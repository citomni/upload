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

/*
 * Shared bootstrap for citomni/upload regression scripts.
 *
 * Run a script directly, e.g. `php tests/storage_test.php`, or all of them
 * with `composer test`. Scripts exit non-zero on the first failed check and
 * print a "SKIP:" line for every case the platform cannot force.
 *
 * Provides:
 * - A minimal BaseService double, defined before the Kernel's BaseService can
 *   be autoloaded, because the real one requires a full App, and an App needs
 *   citomni/http or citomni/cli.
 * - One PSR-4 autoloader: Upload's src/ and tests/, plus citomni/kernel and
 *   citomni/image from sibling packages (the development layout
 *   citomni/{kernel,image,upload}; vendor/citomni/* has the same shape).
 *   No Composer installation and no vendor/autoload.php is needed.
 * - An error handler that turns every unsuppressed PHP warning or notice
 *   into an exception. @-suppressed diagnostics are left to PHP, so
 *   error_get_last() works as in citomni/http.
 * - check(), expectThrows(), skip(), tempDir(), filesUnder(), testCfg(),
 *   testApp(), platform probes, and done() helpers.
 */

namespace CitOmni\Kernel\Service {

	if (!\class_exists(BaseService::class, false)) {

		abstract class BaseService {

			protected object $app;

			/** @var array<string,mixed> */
			protected array $options;

			/**
			 * Create a minimal service test double without a full App.
			 *
			 * @param object $app Test application.
			 * @param array<string,mixed> $options Service options.
			 */
			public function __construct(object $app, array $options = []) {
				$this->app = $app;
				$this->options = $options;
				$this->init();
			}

			protected function init(): void {
			}
		}
	}
}

namespace {

	use CitOmni\Kernel\Arr;
	use CitOmni\Kernel\Cfg;
	use CitOmni\Upload\Boot\Registry;
	use CitOmni\Upload\Tests\Support\RecordingLog;
	use CitOmni\Upload\Tests\Support\TestApp;


	/** Report a fatal setup problem on STDERR (CLI) or the response (web runner). */
	function fail(string $message): never {
		\defined('STDERR') ? \fwrite(\STDERR, $message . "\n") : print($message . "\n");
		exit(1);
	}

	if (\PHP_VERSION_ID < 80500) {
		fail('PHP 8.5 or newer is required.');
	}

	foreach (['fileinfo', 'mbstring'] as $uploadTestExtension) {
		if (!\extension_loaded($uploadTestExtension)) {
			fail("ext-{$uploadTestExtension} is required.");
		}
	}

	// citomni/kernel and citomni/image are loaded from sibling packages.
	$uploadTestSiblings = [
		'CitOmni\\Kernel\\' => \dirname(__DIR__, 2) . '/kernel/src',
		'CitOmni\\Image\\' => \dirname(__DIR__, 2) . '/image/src',
	];

	foreach ($uploadTestSiblings as $uploadTestDirectory) {
		if (!\is_dir($uploadTestDirectory)) {
			fail(
				"Sibling packages not found. The tests load citomni/kernel and citomni/image from the layout citomni/{kernel,image,upload}.\n"
				. "Looked for:\n  " . \implode("\n  ", $uploadTestSiblings)
			);
		}
	}

	\spl_autoload_register(static function (string $class) use ($uploadTestSiblings): void {
		$map = [
			'CitOmni\\Upload\\Tests\\' => __DIR__,
			'CitOmni\\Upload\\' => \dirname(__DIR__) . '/src',
		] + $uploadTestSiblings;

		foreach ($map as $prefix => $directory) {
			if (\str_starts_with($class, $prefix)) {
				$file = $directory . '/' . \str_replace('\\', '/', \substr($class, \strlen($prefix))) . '.php';

				if (\is_file($file)) {
					require $file;
				}

				return;
			}
		}
	});

	unset($uploadTestExtension, $uploadTestSiblings, $uploadTestDirectory);

	// Fail on any unsuppressed diagnostic, including deprecations and #[\NoDiscard] warnings.
	\set_error_handler(static function (int $errno, string $message, string $file, int $line): bool {
		if ((\error_reporting() & $errno) === 0) {
			return false;
		}

		throw new \ErrorException($message, 0, $errno, $file, $line);
	});

	// Registry::CFG_COMMON derives the baseline roots from CITOMNI_APP_PATH. It points
	// at a directory that does not exist, so the baseline storages are unprovisioned.
	if (!\defined('CITOMNI_APP_PATH')) {
		\define('CITOMNI_APP_PATH', \sys_get_temp_dir() . '/citomni-upload-test-app-' . \bin2hex(\random_bytes(6)));
	}

	$checks = 0;
	$skips = 0;


	/** Check a condition independently of zend.assertions. */
	function check(bool $condition, string $message): void {
		global $checks;
		++$checks;

		if (!$condition) {
			throw new \RuntimeException('FAILED: ' . $message);
		}
	}


	/**
	 * Check that a callback throws a given exception class.
	 *
	 * @param class-string<\Throwable> $class Expected exception class.
	 * @return \Throwable The caught exception.
	 */
	function expectThrows(string $class, callable $callback, string $message): \Throwable {
		try {
			$callback();
		} catch (\Throwable $e) {
			check($e instanceof $class, $message . ' (expected ' . $class . ', got ' . $e::class . ': ' . $e->getMessage() . ')');
			return $e;
		}

		check(false, $message . ' (expected ' . $class . ', nothing thrown)');
		throw new \LogicException('unreachable');
	}


	/** Report a case the current platform or user cannot force. */
	function skip(string $case, string $reason): void {
		global $skips;
		++$skips;

		echo 'SKIP: ' . $case . ' (' . $reason . ")\n";
	}


	/**
	 * Create an empty temporary directory, removed at shutdown.
	 *
	 * Removal first restores write permission everywhere, because tests make
	 * files and directories read-only on purpose.
	 */
	function tempDir(): string {
		$dir = \sys_get_temp_dir() . '/citomni-upload-test-' . \bin2hex(\random_bytes(6));

		if (!\mkdir($dir, 0777, true)) {
			fail('Unable to create temp dir: ' . $dir);
		}

		\register_shutdown_function(static function () use ($dir): void {
			@\chmod($dir, 0777);

			$entries = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ($entries as $entry) {
				@\chmod($entry->getPathname(), $entry->isDir() ? 0777 : 0666);
			}

			$entries = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ($entries as $entry) {
				$entry->isDir() ? @\rmdir($entry->getPathname()) : @\unlink($entry->getPathname());
			}

			@\rmdir($dir);
		});

		return $dir;
	}


	/**
	 * List all files below a directory, dotfiles included.
	 *
	 * @return list<string> Paths relative to $dir with "/" separators, sorted.
	 */
	function filesUnder(string $dir): array {
		$files = [];

		if (!\is_dir($dir)) {
			return $files;
		}

		$entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

		foreach ($entries as $entry) {
			if ($entry->isFile()) {
				$files[] = \str_replace('\\', '/', \substr($entry->getPathname(), \strlen($dir) + 1));
			}
		}

		\sort($files);

		return $files;
	}


	/**
	 * Build a Cfg the way App merges it: the shipped baseline, then each layer, last wins.
	 *
	 * Arr::normalizeConfig() is deliberately not applied, so enum cases reach
	 * Upload unchanged regardless of the sibling kernel's version.
	 *
	 * @param array<string, mixed> ...$layers Overlays merged in order onto Registry::CFG_COMMON.
	 */
	function testCfg(array ...$layers): Cfg {
		$data = Registry::CFG_COMMON;

		foreach ($layers as $layer) {
			$data = Arr::mergeAssocLastWins($data, $layer);
		}

		return new Cfg($data);
	}


	/**
	 * Build a test app with a real Cfg and replaceable service doubles.
	 *
	 * Defaults: "log" is a RecordingLog, and "image" and "txt" are factories
	 * that throw on resolution, which proves that a code path never touches them.
	 * Pass a service as null to leave it unregistered.
	 *
	 * @param array<string, object|null> $services Service id => instance, \Closure factory, or null.
	 */
	function testApp(?Cfg $cfg = null, array $services = []): TestApp {
		$services += [
			'log' => new RecordingLog(),
			'image' => static function (): never {
				throw new \LogicException('The image service must not be touched.');
			},
			'txt' => static function (): never {
				throw new \LogicException('The txt service must not be touched.');
			},
		];

		return new TestApp($cfg ?? testCfg(), \array_filter($services, static fn(?object $service): bool => $service !== null));
	}


	/** Whether the tests run on Windows. */
	function isWindows(): bool {
		return \PHP_OS_FAMILY === 'Windows';
	}


	/**
	 * Make a file undeletable where the platform allows it.
	 *
	 * POSIX: the parent directory becomes read-only. Windows: the file becomes
	 * read-only. Root ignores both on POSIX; probe with canForceUndeletableFile().
	 *
	 * @return \Closure Callback that restores the previous permissions.
	 */
	function lockAgainstDelete(string $file): \Closure {
		if (isWindows()) {
			\chmod($file, 0444);

			return static function () use ($file): void {
				\chmod($file, 0666);
			};
		}

		$directory = \dirname($file);
		\chmod($directory, 0555);

		return static function () use ($directory): void {
			\chmod($directory, 0755);
		};
	}


	/** Whether lockAgainstDelete() really prevents unlink() for this user and platform. */
	function canForceUndeletableFile(): bool {
		static $result = null;

		if ($result === null) {
			$directory = tempDir() . '/probe';
			\mkdir($directory);
			\file_put_contents($directory . '/file', 'x');
			$restore = lockAgainstDelete($directory . '/file');
			$result = !@\unlink($directory . '/file');
			$restore();
		}

		return $result;
	}


	/** Whether a directory with mode 0555 really refuses new entries for this user and platform. */
	function canForceReadOnlyDir(): bool {
		static $result = null;

		if ($result === null) {
			$directory = tempDir() . '/probe';
			\mkdir($directory);
			\chmod($directory, 0555);
			$result = @\file_put_contents($directory . '/file', 'x') === false;
			\chmod($directory, 0755);
		}

		return $result;
	}


	/** Whether a file with mode 0000 really cannot be read for this user and platform. */
	function canForceUnreadableFile(): bool {
		static $result = null;

		if ($result === null) {
			$file = tempDir() . '/probe';
			\file_put_contents($file, 'x');
			\chmod($file, 0000);
			\clearstatcache();
			$result = @\file_get_contents($file) === false;
			\chmod($file, 0644);
		}

		return $result;
	}


	/** Print the summary line for a script. */
	function done(string $name): void {
		global $checks, $skips;
		echo $name . ': ' . $checks . ' checks passed' . ($skips > 0 ? ', ' . $skips . ' skipped' : '') . ".\n";
	}
}
