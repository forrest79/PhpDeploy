<?php declare(strict_types=1);

namespace Forrest79\DeployPhp;

use Nette\Utils;
use phpseclib3\Crypt;
use phpseclib3\Exception;
use phpseclib3\Net;

class Deploy
{
	/** @var array<string, mixed> */
	protected array $config = [];

	/** @var array<string, mixed> */
	protected array $environment;

	/** @var array<string, Net\SSH2> */
	private array $sshConnections = [];


	/**
	 * @param array<string, mixed> $additionalConfig
	 */
	public function __construct(string $environment, array $additionalConfig = [])
	{
		if (!isset($this->config[$environment])) {
			throw new Exceptions\DeployException(sprintf('Environment \'%s\' not exists in configuration.', $environment));
		}

		$environmentConfig = array_replace_recursive($this->config[$environment], $additionalConfig);
		if ($environmentConfig === NULL) {
			throw new Exceptions\DeployException('Can\'t prepare environment config.');
		}

		$this->environment = $environmentConfig;

		$this->setup();
	}


	protected function setup(): void
	{
	}


	protected function copy(string $source, string $destination): void
	{
		Utils\FileSystem::copy($source, $destination);
	}


	protected function move(string $source, string $destination): void
	{
		Utils\FileSystem::rename($source, $destination);
	}


	protected function delete(string $path): void
	{
		Utils\FileSystem::delete($path);
	}


	protected function makeDir(string $path): void
	{
		Utils\FileSystem::createDir($path, 0755);
	}


	protected function gitCheckout(string $gitRootDirectory, string $checkoutDirectory, string $branch): bool
	{
		$zipFile = $checkoutDirectory . DIRECTORY_SEPARATOR . uniqid() . '-git.zip';

		$currentDirectory = getcwd();
		if ($currentDirectory === FALSE) {
			throw new Exceptions\DeployException('Can\'t determine current directory');
		}

		$gitRootDirectoryPath = realpath($gitRootDirectory);
		if ($gitRootDirectoryPath === FALSE) {
			throw new Exceptions\DeployException(sprintf('GIT root directory \'%s\' doesn\'t exists', $gitRootDirectory));
		}

		chdir($gitRootDirectoryPath);

		$this->makeDir($checkoutDirectory);

		$success = $this->exec(sprintf('git archive -o %s %s && unzip %s -d %s && rm %s', $zipFile, $branch, $zipFile, $checkoutDirectory, $zipFile));

		chdir($currentDirectory);

		return $success;
	}


	/**
	 * @param string|FALSE $stdout
	 */
	protected function exec(string $command, &$stdout = FALSE): bool
	{
		exec($command, $output, $return);
		if ($output && ($stdout !== FALSE)) {
			$stdout = implode(PHP_EOL, $output);
		}
		return $return === 0;
	}


	protected function gzip(string $sourcePath, string $sourceDir, string $targetFile): void
	{
		exec(sprintf('tar -C %s --force-local -zcvf %s %s', $sourcePath, $targetFile, $sourceDir), $output, $return);
		if ($return !== 0) {
			throw new Exceptions\DeployException(sprintf('Can\'t create tar.gz archive \'%s\': %s', $targetFile, implode(PHP_EOL, $output)));
		}
	}


	protected function validatePrivateKey(?string $privateKeyFile = NULL, ?string $passphrase = NULL): bool
	{
		if (($privateKeyFile === NULL) && ($passphrase !== NULL)) {
			throw new Exceptions\DeployException('Can\'t provide passphrase without private key file');
		}

		$credentials = $this->environment['ssh'];

		try {
			$this->createPrivateKey($privateKeyFile ?? $credentials['private_key'], $passphrase ?? $credentials['passphrase'] ?? NULL);
		} catch (Exception\NoKeyLoadedException $e) {
			return FALSE;
		}

		return TRUE;
	}


	protected function ssh(
		string $command,
		?string $validate = NULL,
		?string &$output = NULL,
		?string $host = NULL,
		?int $port = NULL
	): bool
	{
		$output = $this->sshExec($this->sshConnection(Net\SSH2::class, $host, $port), $command . ';echo "[return_code:$?]"');

		preg_match('/\[return_code:(.*?)\]/', $output, $match);
		$output = preg_replace('/\[return_code:(.*?)\]/', '', $output);

		if ($match[1] !== '0') {
			$this->log(sprintf('SSH error output for command "%s": %s', $command, $output));
			return FALSE;
		}

		if ($validate !== NULL) {
			$success = strpos($output ?? '', $validate) !== FALSE;
			if (!$success) {
				$this->log(sprintf('SSH validation error: "%s" doesn\'t contains "%s"', $output, $validate));
			}
			return $success;
		}

		return TRUE;
	}


	protected function sftpPut(
		string $localFile,
		string $remoteDirectory,
		?string $host = NULL,
		?int $port = NULL
	): bool
	{
		$remoteDirectory = rtrim($remoteDirectory, '/');

		$sftp = $this->sshConnection(Net\SFTP::class, $host, $port);
		assert($sftp instanceof Net\SFTP);

		$this->sshExec($sftp, 'mkdir -p ' . $remoteDirectory); // create remote directory if doesn't
		$remoteAbsoluteDirectory = (substr($remoteDirectory, 0, 1) === '/') ? $remoteDirectory : (trim($this->sshExec($sftp, 'pwd')) . '/' . $remoteDirectory);
		$remoteFile = $remoteAbsoluteDirectory . '/' . basename($localFile);

		return $sftp->put($remoteFile, $localFile, Net\SFTP::SOURCE_LOCAL_FILE);
	}


	protected function httpRequest(string $url, ?string $validate = NULL): bool
	{
		$curl = curl_init($url);
		if ($curl === FALSE) {
			throw new Exceptions\DeployException('Can\'t initialize curl');
		}

		curl_setopt($curl, CURLOPT_HEADER, FALSE);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, $validate !== NULL);

		$returned = curl_exec($curl);

		$errorNo = curl_errno($curl);
		curl_close($curl);

		if ($validate !== NULL) {
			if (!is_string($returned)) {
				return FALSE;
			}
			return strpos($returned, $validate) !== FALSE;
		}

		return $errorNo === 0;
	}


	protected function error(?string $message = NULL): void
	{
		throw new Exceptions\DeployException($message ?? '');
	}


	protected function log(string $message, bool $newLine = TRUE): void
	{
		echo $message . ($newLine ? PHP_EOL : '');
	}


	/**
	 * @param class-string $class
	 */
	private function sshConnection(string $class, ?string $host, ?int $port): Net\SSH2
	{
		if ($host === NULL) {
			$host = $this->environment['ssh']['server'];
		}
		if ($port === NULL) {
			$port = $this->environment['ssh']['port'] ?? 22;
		}

		$credentials = $this->environment['ssh'];

		$key = sprintf('%s|%s@%s:%d', $class, $credentials['username'], $host, $port);

		if (!isset($this->sshConnections[$key])) {
			$sshConnection = new $class($host, $port, 0);
			assert($sshConnection instanceof Net\SSH2);

			if (isset($credentials['private_key'])) {
				$privateKey = $this->createPrivateKey($credentials['private_key'], $credentials['passphrase'] ?? NULL);

				if (!$sshConnection->login($credentials['username'], $privateKey)) {
					throw new Exceptions\DeployException(sprintf('SSH can\'t authenticate with private key \'%s\'', $credentials['private_key']));
				}
			} else {
				throw new Exceptions\DeployException('Unsupported authentication type for SSH.');
			}

			$this->sshConnections[$key] = $sshConnection;
		}

		return $this->sshConnections[$key];
	}


	private function sshExec(Net\SSH2 $sshConnection, string $command): string
	{
		return $sshConnection->exec($command);
	}


	private function createPrivateKey(string $privateKeyFile, ?string $passphrase): Crypt\RSA\PrivateKey
	{
		$privateKeyContents = file_get_contents($privateKeyFile);
		if ($privateKeyContents === FALSE) {
			throw new Exceptions\DeployException(sprintf('SSH can\'t load private key \'%s\'', $privateKeyFile));
		}

		// this if is for PHPStan, we can do also `Crypt\RSA::load($privateKeyContents, $passphrase ?? FALSE)` and ignora PHPStan error
		$privateKey = $passphrase === NULL
			? Crypt\RSA::load($privateKeyContents)
			: Crypt\RSA::load($privateKeyContents, $passphrase);
		assert($privateKey instanceof Crypt\RSA\PrivateKey);

		return $privateKey;
	}


	public static function getResponse(): string
	{
		$response = stream_get_line(STDIN, 1024, PHP_EOL);
		if ($response === FALSE) {
			throw new Exceptions\DeployException('Can\'t get response');
		}
		return $response;
	}


	/**
	 * Taken from Symfony/Console.
	 */
	public static function getHiddenResponse(): string
	{
		if (DIRECTORY_SEPARATOR === '\\') {
			exec(__DIR__ . '/../bin/hiddeninput.exe', $output, $resultCode);
			if ($resultCode !== 0) {
				throw new Exceptions\DeployException('Unable to hide the response on Windows');
			}
			return rtrim(implode(PHP_EOL, $output));
		}

		if (self::hasSttyAvailable()) {
			$sttyMode = exec('stty -g');

			exec('stty -echo');
			$value = fgets(STDIN, 4096);
			exec(sprintf('stty %s', $sttyMode));

			if ($value === FALSE) {
				throw new Exceptions\DeployException('Hidden response aborted.');
			}

			$value = trim($value);

			return $value;
		}

		$shell = self::getShell();
		if ($shell !== NULL) {
			$readCmd = ($shell === 'csh') ? 'set mypassword = $<' : 'read -r mypassword';
			$command = sprintf("/usr/bin/env %s -c 'stty -echo; %s; stty echo; echo \$mypassword'", $shell, $readCmd);
			exec($command, $output, $resultCode);
			if ($resultCode !== 0) {
				throw new Exceptions\DeployException('Unable to hide the response on Shell');
			}
			return rtrim(implode(PHP_EOL, $output));
		}

		throw new Exceptions\DeployException('Unable to hide the response');
	}


	private static function getShell(): ?string
	{
		$shell = NULL;

		if (file_exists('/usr/bin/env')) {
			// handle other OSs with bash/zsh/ksh/csh if available to hide the answer
			$test = "/usr/bin/env %s -c 'echo OK' 2> /dev/null";
			foreach (['bash', 'zsh', 'ksh', 'csh'] as $sh) {
				exec(sprintf($test, $sh), $output, $resultCode);
				if ($resultCode !== 0) {
					throw new Exceptions\DeployException('Unable to get shell');
				}
				if (rtrim(implode(PHP_EOL, $output)) === 'OK') {
					$shell = $sh;
					break;
				}
			}
		}

		return $shell;
	}


	private static function hasSttyAvailable(): bool
	{
		exec('stty 2>&1', $output, $exitcode);
		return $exitcode === 0;
	}

}
