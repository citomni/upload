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
 * In-memory source files with a read hook, served by the "uploadtest://" stream wrapper.
 *
 * Some filesystem states cannot be arranged before a call: the target name is
 * random and only appears (as a temporary file) once copy() has started, and
 * a directory that must turn read-only halfway through the call cannot be
 * prepared in advance. The hook runs on every read of the source, which
 * happens both during MIME detection and during copy() into the target
 * directory. Hooks decide for themselves when to act, typically once a ".tmp"
 * file exists in the target directory.
 *
 * Behavior:
 * - create() registers a file and returns its URL; exists() tells whether it
 *   is still there (unlink() removes it).
 * - A hook that returns false turns that read into a read error, which makes
 *   copy() fail after it created its target.
 * - Implements what Upload does to a source: url_stat (is_file, is_readable,
 *   filesize), open, read, seek, and fstat (finfo, copy), plus unlink.
 *
 * Notes:
 * - Test-only. Upload has no seam for this: its source handling simply works
 *   through PHP's stream layer.
 * - Files report mode 0644, so is_readable() is true for every user.
 */
final class FaultSource {

	public const string SCHEME = 'uploadtest';

	/** @var resource|null Stream context, set by PHP. */
	public $context;

	/** @var array<string, array{bytes: string, onRead: \Closure|null}> Registered files by URL. */
	private static array $files = [];

	private string $bytes = '';

	private int $position = 0;

	private ?\Closure $onRead = null;


	/**
	 * Register an in-memory source file.
	 *
	 * @param string $bytes File content.
	 * @param \Closure|null $onRead Hook invoked before every read of the file; returning false fails that read.
	 * @return string URL of the file.
	 */
	public static function create(string $bytes, ?\Closure $onRead = null): string {
		if (!\in_array(self::SCHEME, \stream_get_wrappers(), true)) {
			\stream_wrapper_register(self::SCHEME, self::class);
		}

		$url = self::SCHEME . '://source-' . \bin2hex(\random_bytes(4)) . '.bin';
		self::$files[$url] = ['bytes' => $bytes, 'onRead' => $onRead];

		return $url;
	}


	/**
	 * Tell whether a registered file still exists.
	 *
	 * @param string $url URL returned by create().
	 * @return bool True when the file was not unlinked.
	 */
	public static function exists(string $url): bool {
		return isset(self::$files[$url]);
	}


	/**
	 * Open a registered file for reading.
	 *
	 * @param string $path URL.
	 * @param string $mode fopen() mode; only read modes are supported.
	 * @param int $options Stream options.
	 * @param string|null $openedPath Unused.
	 * @return bool True when the file exists and the mode is read-only.
	 */
	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		if (!isset(self::$files[$path]) || !\str_contains($mode, 'r') || \str_contains($mode, '+')) {
			return false;
		}

		$this->bytes = self::$files[$path]['bytes'];
		$this->onRead = self::$files[$path]['onRead'];
		$this->position = 0;

		return true;
	}


	/**
	 * Read from the file, running the hook first.
	 *
	 * @param int $count Maximum number of bytes.
	 * @return string|false Bytes read, or false when the hook reports a read error.
	 */
	public function stream_read(int $count): string|false {
		if ($this->onRead !== null && ($this->onRead)() === false) {
			return false;
		}

		$chunk = \substr($this->bytes, $this->position, $count);
		$this->position += \strlen($chunk);

		return $chunk;
	}


	/**
	 * @return bool True at end of file.
	 */
	public function stream_eof(): bool {
		return $this->position >= \strlen($this->bytes);
	}


	/**
	 * @return int Current position.
	 */
	public function stream_tell(): int {
		return $this->position;
	}


	/**
	 * Move the read position.
	 *
	 * @param int $offset Offset.
	 * @param int $whence SEEK_SET, SEEK_CUR, or SEEK_END.
	 * @return bool True on success.
	 */
	public function stream_seek(int $offset, int $whence = \SEEK_SET): bool {
		$target = match ($whence) {
			\SEEK_SET => $offset,
			\SEEK_CUR => $this->position + $offset,
			\SEEK_END => \strlen($this->bytes) + $offset,
			default => -1,
		};

		if ($target < 0) {
			return false;
		}

		$this->position = $target;

		return true;
	}


	/**
	 * Refuse to expose a file descriptor; PHP then reads through the wrapper.
	 *
	 * @param int $castAs Cast type.
	 * @return false Always.
	 */
	public function stream_cast(int $castAs): false {
		return false;
	}


	/**
	 * @return array<string, int> Stat of the open file.
	 */
	public function stream_stat(): array {
		return self::stat(\strlen($this->bytes));
	}


	/**
	 * @return void
	 */
	public function stream_close(): void {
	}


	/**
	 * Stat a registered file by URL.
	 *
	 * @param string $path URL.
	 * @param int $flags Stat flags.
	 * @return array<string, int>|false Stat, or false when the file does not exist.
	 */
	public function url_stat(string $path, int $flags): array|false {
		return isset(self::$files[$path]) ? self::stat(\strlen(self::$files[$path]['bytes'])) : false;
	}


	/**
	 * Remove a registered file.
	 *
	 * @param string $path URL.
	 * @return bool True when the file existed.
	 */
	public function unlink(string $path): bool {
		if (!isset(self::$files[$path])) {
			return false;
		}

		unset(self::$files[$path]);

		return true;
	}


	/**
	 * Build a stat array for a regular file with mode 0644.
	 *
	 * @param int $size File size.
	 * @return array<string, int> Stat.
	 */
	private static function stat(int $size): array {
		return [
			'dev' => 0, 'ino' => 0, 'mode' => 0100644, 'nlink' => 1, 'uid' => 0, 'gid' => 0, 'rdev' => 0,
			'size' => $size, 'atime' => 0, 'mtime' => 0, 'ctime' => 0, 'blksize' => -1, 'blocks' => -1,
		];
	}


}
