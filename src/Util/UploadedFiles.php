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
 * UploadedFiles: Normalize $_FILES entries into flat, strictly typed file entries.
 *
 * PHP builds $_FILES from multipart field names that the client chooses, and it
 * transposes nested names: a field "doc[a][b]" appears as
 * $_FILES['doc']['name']['a']['b'], $_FILES['doc']['tmp_name']['a']['b'], and so
 * on. The shape of an entry is therefore untrusted input.
 *
 * Behavior:
 * - A normalized entry has exactly the keys name, type, tmp_name, error, and
 *   size, in that order, with string, string, string, int, and int values.
 *   full_path (the client's directory path) is ignored.
 * - one() returns the entry of a single-file field, or null when no file was
 *   sent (UPLOAD_ERR_NO_FILE) or the entry is malformed.
 * - many() returns a list for a multi-file field ("name[]", or a keyed field
 *   such as "name[front]"). Slots without a file and malformed slots are
 *   skipped, and keys are dropped. A single-file field gives [].
 * - $path selects a nested field in all five arrays first:
 *   one($_FILES['doc'] ?? null, 'a', 'b') for "doc[a][b]",
 *   many($_FILES['gallery'] ?? null, 'photos') for "gallery[photos][]", and
 *   one($_FILES['files'] ?? null, 0) for the first slot of "files[]".
 * - Malformed input never throws: missing keys, values of the wrong type
 *   (including the arrays a different nesting produces), and paths that do
 *   not exist give null or are skipped.
 * - Upload errors other than UPLOAD_ERR_NO_FILE are kept, so storeUpload()
 *   can map them.
 *
 * Notes:
 * - Pure functions: no App, no IO, no superglobals.
 * - name and type come from the client and size is PHP's count. Upload
 *   sanitizes the name, measures the size, and detects the type itself.
 *
 * Typical usage:
 *   $file = UploadedFiles::one($_FILES['avatar'] ?? null);
 *   $files = UploadedFiles::many($_FILES['attachments'] ?? null);
 */
final class UploadedFiles {

	// PHP's per-file keys, in PHP's order. full_path is ignored.
	private const array KEYS = ['name', 'type', 'tmp_name', 'error', 'size'];


	/**
	 * Normalize a single-file entry.
	 *
	 * @param mixed $entry Raw $_FILES entry, e.g. $_FILES['avatar'] ?? null.
	 * @param int|string ...$path Keys of a nested field, e.g. 'a', 'b' for "doc[a][b]".
	 * @return array{name: string, type: string, tmp_name: string, error: int, size: int}|null The entry, or null when no file was sent or the entry is malformed.
	 */
	public static function one(mixed $entry, int|string ...$path): ?array {
		$fields = self::select($entry, $path);

		return $fields === null ? null : self::leaf($fields, null);
	}


	/**
	 * Normalize a multi-file entry to a list.
	 *
	 * @param mixed $entry Raw $_FILES entry, e.g. $_FILES['attachments'] ?? null.
	 * @param int|string ...$path Keys of a nested field, e.g. 'photos' for "gallery[photos][]".
	 * @return list<array{name: string, type: string, tmp_name: string, error: int, size: int}> Entries in client order; empty and malformed slots are skipped.
	 */
	public static function many(mixed $entry, int|string ...$path): array {
		$fields = self::select($entry, $path);
		$files = [];

		if ($fields === null) {
			return $files;
		}

		foreach (self::KEYS as $key) {
			if (!\is_array($fields[$key])) {
				return $files;
			}
		}

		foreach (\array_keys($fields['name']) as $slot) {
			$file = self::leaf($fields, $slot);

			if ($file !== null) {
				$files[] = $file;
			}
		}

		return $files;
	}


	/**
	 * Follow a path through each of the five fields.
	 *
	 * @param mixed $entry Raw $_FILES entry.
	 * @param array<int, int|string> $path Keys to follow.
	 * @return array<string, mixed>|null The five selected values, or null when the entry or a step is missing.
	 */
	private static function select(mixed $entry, array $path): ?array {
		if (!\is_array($entry)) {
			return null;
		}

		$fields = [];

		foreach (self::KEYS as $key) {
			if (!\array_key_exists($key, $entry)) {
				return null;
			}

			$value = $entry[$key];

			foreach ($path as $step) {
				if (!\is_array($value) || !\array_key_exists($step, $value)) {
					return null;
				}

				$value = $value[$step];
			}

			$fields[$key] = $value;
		}

		return $fields;
	}


	/**
	 * Build one entry from the five selected values.
	 *
	 * @param array<string, mixed> $fields Selected values per key.
	 * @param int|string|null $slot Slot within each value, or null for the values themselves.
	 * @return array{name: string, type: string, tmp_name: string, error: int, size: int}|null The entry, or null when it is malformed or has no file.
	 */
	private static function leaf(array $fields, int|string|null $slot): ?array {
		$file = [];

		foreach (self::KEYS as $key) {
			if ($slot === null) {
				$value = $fields[$key];
			} elseif (\is_array($fields[$key]) && \array_key_exists($slot, $fields[$key])) {
				$value = $fields[$key][$slot];
			} else {
				return null;
			}

			if ($key === 'error' || $key === 'size' ? !\is_int($value) : !\is_string($value)) {
				return null;
			}

			$file[$key] = $value;
		}

		return $file['error'] === \UPLOAD_ERR_NO_FILE ? null : $file;
	}


}
