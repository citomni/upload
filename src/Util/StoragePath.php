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

namespace CitOmni\Upload\Util;

/**
 * StoragePath: Lexical validation and composition of storage-relative paths.
 *
 * A storage-relative path is used both as a filesystem path below a storage
 * root and as a web path below the storage's web_path. The allowed characters
 * make the two identical without any encoding.
 *
 * Behavior:
 * - The separator is "/". A backslash is rejected.
 * - No leading "/", no empty segments (so no trailing or repeated "/"), and no
 *   segment starting with "." (so no ".", "..", or dotfiles).
 * - Segments match [A-Za-z0-9._~-]+, the URL-unreserved characters.
 * - "" is valid and means "no segments".
 * - Violations throw \InvalidArgumentException. A path is never repaired by
 *   mutation, and realpath() is never used.
 *
 * Notes:
 * - Pure functions: no App, no IO, no state.
 * - Dot-prefixed names are reserved for temporary files (".{name}.{random}.tmp"),
 *   which therefore can never be addressed through a valid path.
 *
 * Typical usage:
 *   $directory = StoragePath::join($profileDirectory, $subdir, $shard);
 *   $thumb = StoragePath::variant('docs/9f86d081.jpg', 'thumb', 'webp'); // 'docs/9f86d081-thumb.webp'
 */
final class StoragePath {

	// One or more "/"-separated segments of [A-Za-z0-9._~-]; a segment never starts with ".".
	private const string RELATIVE = '#^[A-Za-z0-9_~-][A-Za-z0-9._~-]*(?:/[A-Za-z0-9_~-][A-Za-z0-9._~-]*)*$#D';

	private const string VARIANT = '#^[a-z0-9_]+$#D';

	private const string EXTENSION = '#^[a-z0-9]+$#D';


	/**
	 * Validate a storage-relative path and return it unchanged.
	 *
	 * Typical usage:
	 *   StoragePath::validateRelative('users/42/profile'); // 'users/42/profile'
	 *   StoragePath::validateRelative('../etc');           // \InvalidArgumentException
	 *
	 * @param string $path Candidate path; "" means no segments.
	 * @return string The same path, never modified.
	 * @throws \InvalidArgumentException When the path breaks a rule.
	 */
	public static function validateRelative(string $path): string {
		if ($path === '' || \preg_match(self::RELATIVE, $path) === 1) {
			return $path;
		}

		throw new \InvalidArgumentException(
			'Invalid storage-relative path ' . self::quote($path) . ': expected "/"-separated segments of [A-Za-z0-9._~-] '
			. 'that do not start with ".", without a leading, trailing, or repeated "/".'
		);
	}


	/**
	 * Join path parts with "/" and validate the result.
	 *
	 * Behavior:
	 * - Empty parts are skipped, so optional parts can be passed as "".
	 * - Only the joined result is validated. A part with a trailing "/"
	 *   therefore fails as a repeated "/".
	 *
	 * Typical usage:
	 *   StoragePath::join('docs', '', '9f'); // 'docs/9f'
	 *
	 * @param string ...$parts Path parts.
	 * @return string Joined path; "" when every part is empty.
	 * @throws \InvalidArgumentException When the joined path breaks a rule.
	 */
	public static function join(string ...$parts): string {
		$segments = [];

		foreach ($parts as $part) {
			if ($part !== '') {
				$segments[] = $part;
			}
		}

		return self::validateRelative(\implode('/', $segments));
	}


	/**
	 * Derive a variant path from a main path.
	 *
	 * The variant lives in the main file's directory as
	 * "{name}-{variant}.{extension}", where name is the main file name without
	 * its last extension. The result depends only on the arguments.
	 *
	 * Typical usage:
	 *   StoragePath::variant('a/b.c.jpg', 'thumb', 'webp'); // 'a/b.c-thumb.webp'
	 *
	 * @param string $mainPath Non-empty storage-relative path of the main file.
	 * @param string $variant Variant key matching [a-z0-9_]+.
	 * @param string $extension Extension without the dot, matching [a-z0-9]+.
	 * @return string Storage-relative variant path.
	 * @throws \InvalidArgumentException When an argument is invalid.
	 */
	public static function variant(string $mainPath, string $variant, string $extension): string {
		if ($mainPath === '') {
			throw new \InvalidArgumentException('Main path must not be empty.');
		}

		self::validateRelative($mainPath);

		if (\preg_match(self::VARIANT, $variant) !== 1) {
			throw new \InvalidArgumentException('Invalid variant key ' . self::quote($variant) . ': expected [a-z0-9_]+.');
		}

		if (\preg_match(self::EXTENSION, $extension) !== 1) {
			throw new \InvalidArgumentException('Invalid extension ' . self::quote($extension) . ': expected [a-z0-9]+ without a dot.');
		}

		$slash = \strrpos($mainPath, '/');
		$directory = $slash === false ? '' : \substr($mainPath, 0, $slash + 1);
		$fileName = $slash === false ? $mainPath : \substr($mainPath, $slash + 1);

		// A valid segment never starts with ".", so the name before the last dot is never empty.
		$dot = \strrpos($fileName, '.');
		$name = $dot === false ? $fileName : \substr($fileName, 0, $dot);

		return $directory . $name . '-' . $variant . '.' . $extension;
	}


	/**
	 * Quote a value for an exception message, escaping control characters.
	 *
	 * @param string $value Raw value, possibly invalid UTF-8.
	 * @return string JSON string literal.
	 */
	private static function quote(string $value): string {
		return (string)\json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
	}


}
