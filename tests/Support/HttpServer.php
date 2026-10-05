<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

namespace CitOmni\Upload\Tests\Support;

/**
 * PHP's built-in web server as a child process, for real HTTP uploads in tests.
 *
 * is_uploaded_file() and move_uploaded_file() only accept files uploaded in
 * the current request, which a CLI script cannot produce. This helper runs
 * "php -S" with a router script and sends raw multipart/form-data requests.
 *
 * Behavior:
 * - start() binds the server to 127.0.0.1 on a free port and waits until the
 *   router answers a ping. It returns null when the server cannot run here;
 *   $lastFailure then says why.
 * - post() sends one multipart request and returns the router's JSON report.
 *   A router that answers with anything else fails loudly, with the response
 *   and the server log in the message.
 * - stop() terminates the process and is idempotent. Tests call it in finally;
 *   start() also registers it as a shutdown function as a backstop.
 *
 * Notes:
 * - The free port is found by binding 127.0.0.1:0 and releasing it. When the
 *   server cannot take the port after all, start() retries with a new one.
 * - The command is passed to proc_open() as an array, so no shell sits between
 *   the test and the server, and stop() signals the server itself.
 * - The server inherits the user and umask of the test process.
 */
final class HttpServer {

	/** Why the last start() returned null. */
	public static string $lastFailure = '';

	/** @var resource|null Server process; null once stopped. */
	private $process;


	/**
	 * @param resource $process Server process.
	 * @param int $port Port on 127.0.0.1.
	 * @param string $log File that receives the server's output.
	 */
	private function __construct($process, private readonly int $port, private readonly string $log) {
		$this->process = $process;
	}


	/**
	 * Start the server.
	 *
	 * @param string $router Absolute path of the router script.
	 * @param array<string, string> $ini INI settings, passed with -d.
	 * @param string $workDir Existing directory for the document root and the server log.
	 * @return self|null The running server, or null when it cannot be started here.
	 * @throws \RuntimeException When the server runs but the router does not answer the ping.
	 */
	public static function start(string $router, array $ini, string $workDir): ?self {
		self::$lastFailure = '';

		if (\PHP_BINARY === '' || !\function_exists('proc_open')) {
			self::$lastFailure = 'proc_open() or PHP_BINARY is unavailable';

			return null;
		}

		$log = $workDir . '/server.log';

		for ($attempt = 1; $attempt <= 3; ++$attempt) {
			$port = self::freePort();

			if ($port === null) {
				self::$lastFailure = 'no free port on 127.0.0.1';

				return null;
			}

			$command = [\PHP_BINARY];

			foreach ($ini as $name => $value) {
				\array_push($command, '-d', $name . '=' . $value);
			}

			\array_push($command, '-S', '127.0.0.1:' . $port, '-t', $workDir, $router);
			$process = @\proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);

			if (!\is_resource($process)) {
				self::$lastFailure = 'proc_open() failed';

				return null;
			}

			\fclose($pipes[0]);
			$server = new self($process, $port, $log);

			if ($server->awaitPing()) {
				\register_shutdown_function([$server, 'stop']);

				return $server;
			}

			$server->stop();
			self::$lastFailure = 'the server did not start (attempt ' . $attempt . '): ' . \trim(\substr($server->logTail(), -300));
		}

		return null;
	}


	/**
	 * Send one multipart/form-data POST request and return the router's report.
	 *
	 * @param array<string, string> $fields Form fields, sent before the files.
	 * @param list<array{0: string, 1: string, 2: string}> $files Parts as [field name, file name, bytes].
	 * @param int $cutTail Bytes cut from the end of the body, to send a truncated request.
	 * @return array<string, mixed> Decoded JSON report.
	 * @throws \RuntimeException When the router does not answer with a JSON report.
	 */
	public function post(array $fields, array $files, int $cutTail = 0): array {
		$boundary = 'citomni-upload-' . \bin2hex(\random_bytes(12));
		$body = '';

		foreach ($fields as $name => $value) {
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
		}

		foreach ($files as [$field, $fileName, $bytes]) {
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$field}\"; filename=\"{$fileName}\"\r\n"
				. "Content-Type: application/octet-stream\r\n\r\n{$bytes}\r\n";
		}

		$body .= "--{$boundary}--\r\n";

		if ($cutTail > 0) {
			$body = \substr($body, 0, -$cutTail);
		}

		$response = $this->send(
			"POST / HTTP/1.1\r\nHost: 127.0.0.1:{$this->port}\r\nContent-Type: multipart/form-data; boundary={$boundary}\r\n"
			. 'Content-Length: ' . \strlen($body) . "\r\nConnection: close\r\n\r\n" . $body,
			10.0
		) ?? '';

		$split = \strpos($response, "\r\n\r\n");
		$report = $split === false ? null : \json_decode(\substr($response, $split + 4), true);

		if (\preg_match('~^HTTP/1\.[01] 200 ~', $response) !== 1 || !\is_array($report)) {
			throw new \RuntimeException("The upload router failed.\nResponse:\n" . \substr($response, 0, 3000) . "\nServer log:\n" . $this->logTail());
		}

		return $report;
	}


	/**
	 * Stop the server. Safe to call more than once.
	 *
	 * @return void
	 */
	public function stop(): void {
		if ($this->process === null) {
			return;
		}

		@\proc_terminate($this->process);
		@\proc_close($this->process);
		$this->process = null;
	}


	/**
	 * Wait up to 5 seconds for the router to answer a ping.
	 *
	 * @return bool True when the router answered; false when the server exited or never answered.
	 * @throws \RuntimeException When the server answered with something other than the ping reply.
	 */
	private function awaitPing(): bool {
		$deadline = \hrtime(true) + 5_000_000_000;

		while (\hrtime(true) < $deadline) {
			if (!\proc_get_status($this->process)['running']) {
				return false;
			}

			$response = $this->send("GET /?ping=1 HTTP/1.1\r\nHost: 127.0.0.1:{$this->port}\r\nConnection: close\r\n\r\n", 1.0);

			if ($response !== null && $response !== '') {
				if (\str_contains($response, '{"pong":true}')) {
					return true;
				}

				$this->stop();

				throw new \RuntimeException("The upload router did not answer the ping.\nResponse:\n" . \substr($response, 0, 3000) . "\nServer log:\n" . $this->logTail());
			}

			\usleep(50_000);
		}

		return false;
	}


	/**
	 * Send a raw request and read the whole response.
	 *
	 * @param string $request Raw HTTP request.
	 * @param float $timeout Connect and read timeout in seconds.
	 * @return string|null Raw response, or null when the connection failed.
	 */
	private function send(string $request, float $timeout): ?string {
		$socket = @\stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $error, $timeout);

		if ($socket === false) {
			return null;
		}

		\stream_set_timeout($socket, (int)\ceil($timeout));
		$offset = 0;
		$length = \strlen($request);

		while ($offset < $length) {
			$written = @\fwrite($socket, \substr($request, $offset));

			if ($written === false || $written === 0) {
				\fclose($socket);

				return null;
			}

			$offset += $written;
		}

		$response = \stream_get_contents($socket);
		\fclose($socket);

		return $response === false ? null : $response;
	}


	/**
	 * Find a free port on 127.0.0.1.
	 *
	 * @return int|null Port number, or null when no socket can be bound.
	 */
	private static function freePort(): ?int {
		$socket = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

		if ($socket === false) {
			return null;
		}

		$name = (string)\stream_socket_get_name($socket, false);
		\fclose($socket);
		$port = (int)\substr($name, (int)\strrpos($name, ':') + 1);

		return $port > 0 ? $port : null;
	}


	/**
	 * Return the end of the server log.
	 *
	 * @return string Up to the last 2000 bytes.
	 */
	private function logTail(): string {
		return \is_file($this->log) ? \substr((string)\file_get_contents($this->log), -2000) : '';
	}


}
