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
 * Shared bootstrap for the suites (tests/<suite>/run.php) and the built-in web
 * server router (tests/http-upload/server.php). Not a suite of its own;
 * tests/run.php only collects run.php and database.php.
 *
 * A suite installs its CLI guard and its error handler, then requires this file
 * instead of a Composer bootstrap. Every suite runs in its own process.
 *
 * Provides:
 * - A minimal BaseService double, defined before the kernel's BaseService can
 *   be autoloaded, because the real one requires a full App, and an App needs
 *   citomni/http or citomni/cli.
 * - One PSR-4 autoloader: Upload's src/ and tests/, plus citomni/kernel and
 *   citomni/image from sibling packages (the development layout
 *   citomni/{kernel,image,upload}; vendor/citomni/* has the same shape).
 * - CITOMNI_APP_PATH, pointing at a directory that never exists, so the
 *   baseline storages of Registry::CFG_COMMON are unprovisioned.
 * - The case runner: test() runs one named case and reports PASS, FAIL, or
 *   SKIP; done() prints the totals and ends the suite with its exit code.
 * - Assertions and helpers: check(), expectThrows(), requires(), tempDir(),
 *   filesUnder(), testCfg(), testApp(), and platform probes.
 *
 * Notes:
 * - Files a suite writes live below one run directory,
 *   citomni_upload_<suite>_test_<random> under sys_get_temp_dir(), created on
 *   first use and removed at shutdown. Nothing else is ever removed.
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


	/** A failed check: the case fails with the check's message. */
	final class CheckFailed extends \RuntimeException {
	}


	/** A case the platform cannot run: it is reported as SKIP with the reason. */
	final class CaseSkipped extends \RuntimeException {
	}


	/** Report a fatal setup problem on STDERR (CLI) or the response (web server router) and stop. */
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
				"Sibling packages not found. The suites load citomni/kernel and citomni/image from the layout citomni/{kernel,image,upload}.\n"
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

	// The run directory of this suite; the suite is the directory of the script PHP started.
	\define('UPLOAD_TEST_RUN_DIR', \sys_get_temp_dir() . '/citomni_upload_' . \basename(\dirname(\get_included_files()[0])) . '_test_' . \bin2hex(\random_bytes(6)));

	// Registry::CFG_COMMON derives the baseline roots from CITOMNI_APP_PATH. It points
	// at a directory that is never created, so the baseline storages are unprovisioned.
	if (!\defined('CITOMNI_APP_PATH')) {
		\define('CITOMNI_APP_PATH', \UPLOAD_TEST_RUN_DIR . '/app');
	}

	$passed = 0;
	$failed = 0;
	$skipped = 0;


	// ----------------------------------------------------------------
	// Case runner
	// ----------------------------------------------------------------

	/**
	 * Run one case and report it.
	 *
	 * Behavior:
	 * - PASS <name> on stdout when the case returns.
	 * - SKIP <name>: <reason> on stdout when the case calls requires() with a
	 *   condition that does not hold.
	 * - FAIL <name> - <reason> on stderr when a check fails or anything else is
	 *   thrown. The suite goes on with the next case either way.
	 *
	 * @param string $name Case name, shown in the report.
	 * @param \Closure $case The case; it sets up everything it needs itself.
	 * @return void
	 */
	function test(string $name, \Closure $case): void {
		global $passed, $failed, $skipped;

		try {
			$case();
		} catch (CaseSkipped $e) {
			++$skipped;
			\fwrite(\STDOUT, "SKIP {$name}: {$e->getMessage()}\n");

			return;
		} catch (CheckFailed $e) {
			++$failed;
			\fwrite(\STDERR, "FAIL {$name} - " . oneLine($e->getMessage()) . "\n");

			return;
		} catch (\Throwable $e) {
			++$failed;
			\fwrite(\STDERR, "FAIL {$name} - " . oneLine($e::class . ': ' . $e->getMessage() . ' at ' . \basename($e->getFile()) . ':' . $e->getLine()) . "\n");

			return;
		}

		++$passed;
		\fwrite(\STDOUT, "PASS {$name}\n");
	}


	/** Print the totals line and end the suite: exit code 0 when no case failed, 1 otherwise. */
	function done(): never {
		global $passed, $failed, $skipped;

		\fwrite(\STDOUT, "{$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . "\n");
		exit($failed === 0 ? 0 : 1);
	}


	/** Collapse line breaks, so a FAIL line stays on one line. */
	function oneLine(string $text): string {
		return \trim((string)\preg_replace('/\s*\R\s*/', ' | ', $text));
	}


	// ----------------------------------------------------------------
	// Assertions
	// ----------------------------------------------------------------

	/** Fail the current case with $message unless $condition holds. */
	function check(bool $condition, string $message): void {
		if (!$condition) {
			throw new CheckFailed($message);
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

		throw new CheckFailed($message . ' (expected ' . $class . ', nothing thrown)');
	}


	/**
	 * Skip the current case unless the platform can run it.
	 *
	 * Call it before the case's first check, so a skipped case has checked nothing.
	 */
	function requires(bool $condition, string $reason): void {
		if (!$condition) {
			throw new CaseSkipped($reason);
		}
	}


	// ----------------------------------------------------------------
	// Files and configuration
	// ----------------------------------------------------------------

	/**
	 * Create an empty directory below the run directory.
	 *
	 * The run directory is created on first use and removed at shutdown. Removal
	 * first restores write permission everywhere, because cases make files and
	 * directories read-only on purpose.
	 */
	function tempDir(): string {
		static $count = 0;

		if ($count === 0) {
			if (!\mkdir(\UPLOAD_TEST_RUN_DIR, 0777, true)) {
				fail('Unable to create the run directory ' . \UPLOAD_TEST_RUN_DIR);
			}

			\register_shutdown_function(static function (): void {
				removeRunDir(\UPLOAD_TEST_RUN_DIR);
			});
		}

		$dir = \UPLOAD_TEST_RUN_DIR . '/' . ++$count;

		if (!\mkdir($dir)) {
			fail('Unable to create temp dir: ' . $dir);
		}

		return $dir;
	}


	/** Remove the run directory, including everything below it. */
	function removeRunDir(string $dir): void {
		if (!\is_dir($dir)) {
			return;
		}

		@\chmod($dir, 0777);

		$entries = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($entries as $entry) {
			if (!$entry->isLink()) {
				@\chmod($entry->getPathname(), $entry->isDir() ? 0777 : 0666);
			}
		}

		$entries = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($entries as $entry) {
			$entry->isDir() && !$entry->isLink() ? @\rmdir($entry->getPathname()) : @\unlink($entry->getPathname());
		}

		@\rmdir($dir);
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


	// ----------------------------------------------------------------
	// Platform probes
	// ----------------------------------------------------------------

	/** Whether the suites run on Windows. */
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
}
