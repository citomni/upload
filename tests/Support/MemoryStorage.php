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
 * In-memory storage behind the "uploadmem://" stream wrapper.
 *
 * A storage whose root is a MemoryStorage URL keeps its files in memory, so a
 * test sees every filesystem call Upload makes on the storage and can make
 * chosen files undeletable, on every platform and also as root.
 *
 * Behavior:
 * - root() registers the wrapper on first use and an empty root directory, and
 *   returns the root URL. put() adds a file; its parent directories exist
 *   implicitly.
 * - Every wrapper call is recorded in $operations as [operation, path].
 * - url_stat() reports roots and implied directories as directories, files as
 *   regular files, and anything else as missing.
 * - unlink() removes a file. For a missing path, a directory, or a path listed
 *   in $undeletable it raises a warning and fails, as a refused unlink does.
 * - Opening, creating, renaming, and listing always fail; deletion and the
 *   path methods need none of them.
 *
 * Notes:
 * - reset() forgets roots, files, operations, and undeletable paths. The
 *   wrapper stays registered.
 */
final class MemoryStorage {

	public const string SCHEME = 'uploadmem';

	/** @var resource|null Stream context, set by PHP. */
	public $context;

	/** @var list<array{0: string, 1: string}> Recorded wrapper calls as [operation, path]. */
	public static array $operations = [];

	/** @var array<string, true> URLs whose unlink() fails with "Permission denied". */
	public static array $undeletable = [];

	/** @var array<string, true> Root directory URLs. */
	private static array $roots = [];

	/** @var array<string, string> File contents by URL. */
	private static array $files = [];


	/**
	 * Register an empty root directory and return its URL.
	 *
	 * @param string $name Root name.
	 * @return string Root URL, "uploadmem://{name}".
	 */
	public static function root(string $name): string {
		if (!\in_array(self::SCHEME, \stream_get_wrappers(), true)) {
			\stream_wrapper_register(self::SCHEME, self::class);
		}

		$url = self::SCHEME . '://' . $name;
		self::$roots[$url] = true;

		return $url;
	}


	/**
	 * Add a file.
	 *
	 * @param string $url File URL below a root.
	 * @param string $bytes Content.
	 * @return void
	 */
	public static function put(string $url, string $bytes = 'x'): void {
		self::$files[$url] = $bytes;
	}


	/**
	 * Tell whether a file exists, without recording an operation.
	 *
	 * @param string $url File URL.
	 * @return bool True when the file exists.
	 */
	public static function exists(string $url): bool {
		return isset(self::$files[$url]);
	}


	/**
	 * Return the URLs passed to unlink(), in call order.
	 *
	 * @return list<string> URLs.
	 */
	public static function unlinked(): array {
		$urls = [];

		foreach (self::$operations as [$operation, $url]) {
			if ($operation === 'unlink') {
				$urls[] = $url;
			}
		}

		return $urls;
	}


	/**
	 * Forget roots, files, operations, and undeletable paths.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$operations = [];
		self::$undeletable = [];
		self::$roots = [];
		self::$files = [];
	}


	public function url_stat(string $path, int $flags): array|false {
		self::$operations[] = ['stat', $path];

		if (isset(self::$files[$path])) {
			return self::stat(0100644, \strlen(self::$files[$path]));
		}

		return self::isDirectory($path) ? self::stat(040755, 0) : false;
	}


	public function unlink(string $path): bool {
		self::$operations[] = ['unlink', $path];

		$reason = match (true) {
			!isset(self::$files[$path]) => self::isDirectory($path) ? 'Is a directory' : 'No such file or directory',
			isset(self::$undeletable[$path]) => 'Permission denied',
			default => null,
		};

		if ($reason !== null) {
			\trigger_error("unlink({$path}): {$reason}", \E_USER_WARNING);

			return false;
		}

		unset(self::$files[$path]);

		return true;
	}


	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		self::$operations[] = ['open', $path];

		return false;
	}


	public function mkdir(string $path, int $mode, int $options): bool {
		self::$operations[] = ['mkdir', $path];

		return false;
	}


	public function rename(string $from, string $to): bool {
		self::$operations[] = ['rename', $from];

		return false;
	}


	public function rmdir(string $path, int $options): bool {
		self::$operations[] = ['rmdir', $path];

		return false;
	}


	public function dir_opendir(string $path, int $options): bool {
		self::$operations[] = ['opendir', $path];

		return false;
	}


	/**
	 * Tell whether a URL is a root or the parent of a stored file.
	 *
	 * @param string $path URL.
	 * @return bool True for a directory.
	 */
	private static function isDirectory(string $path): bool {
		$path = \rtrim($path, '/');

		if (isset(self::$roots[$path])) {
			return true;
		}

		foreach (self::$files as $url => $bytes) {
			if (\str_starts_with($url, $path . '/')) {
				return true;
			}
		}

		return false;
	}


	/**
	 * Build a stat array.
	 *
	 * @param int $mode File type and permission bits.
	 * @param int $size Size in bytes.
	 * @return array<int|string, int> Numeric and named stat fields.
	 */
	private static function stat(int $mode, int $size): array {
		$named = ['dev' => 0, 'ino' => 0, 'mode' => $mode, 'nlink' => 1, 'uid' => 0, 'gid' => 0, 'rdev' => 0, 'size' => $size, 'atime' => 0, 'mtime' => 0, 'ctime' => 0, 'blksize' => -1, 'blocks' => -1];

		return \array_values($named) + $named;
	}
}
