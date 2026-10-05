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

use CitOmni\Image\Enum\ImageFormat;

/**
 * MimeMap: Canonical MIME types, file extensions, and image-format routing.
 *
 * Behavior:
 * - canonical() lowercases a MIME type and maps known aliases to one canonical
 *   form, so finfo results and profile accept lists compare exactly.
 * - extension() maps a canonical MIME type to the extension Upload stores it
 *   with:
 *   1) A citomni/image format is stored with its ImageFormat value ("png",
 *      "webp", ...), except "jpeg" -> "jpg" and "tiff" -> "tif". The set is
 *      derived from ImageFormat::cases(), so a format citomni/image adds
 *      takes the same path without a change here.
 *   2) Any other type follows a curated map; everything else, including HTML,
 *      JavaScript, PHP, and executables, is stored as "bin".
 * - imageFormat() tells whether a canonical MIME type is a citomni/image
 *   format. The lookup is built from ImageFormat::cases() and
 *   ImageFormat::mime(); it is not a format vocabulary of its own.
 *
 * Notes:
 * - Pure functions: no App, no IO. The image-format lookup is built once per
 *   process and memoized in a static property.
 * - Extensions are Upload's filename policy ("jpeg" -> "jpg", "tiff" -> "tif").
 * - SVG is not an ImageFormat, so it is never routed to citomni/image.
 * - Aliases are added test-driven when a legitimate variant is met.
 *
 * Typical usage:
 *   $mime = MimeMap::canonical('image/x-ms-bmp'); // 'image/bmp'
 *   $ext = MimeMap::extension($mime);            // 'bmp'
 *   $isImage = MimeMap::imageFormat($mime) !== null;
 */
final class MimeMap {

	// Alias => canonical MIME type.
	private const array ALIASES = [
		'image/x-ms-bmp' => 'image/bmp',
		'image/x-bmp' => 'image/bmp',
		'image/heif' => 'image/heic',
		'application/x-rar-compressed' => 'application/vnd.rar',
		'application/x-rar' => 'application/vnd.rar',
	];

	// ImageFormat value => extension, where the two differ. Every other
	// citomni/image format is stored with its value as the extension.
	private const array FORMAT_EXTENSIONS = [
		'jpeg' => 'jpg',
		'tiff' => 'tif',
	];

	// Canonical MIME type => extension for types that are not citomni/image
	// formats. Anything else is stored as "bin".
	private const array EXTENSIONS = [
		'image/svg+xml' => 'svg',
		'application/pdf' => 'pdf',
		'text/plain' => 'txt',
		'text/csv' => 'csv',
		'application/xml' => 'xml',
		'text/xml' => 'xml',
		'application/json' => 'json',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
		'application/vnd.oasis.opendocument.text' => 'odt',
		'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
		'application/vnd.oasis.opendocument.presentation' => 'odp',
		'application/zip' => 'zip',
		'application/vnd.rar' => 'rar',
		'application/epub+zip' => 'epub',
		'message/rfc822' => 'eml',
	];

	/** @var array<string, ImageFormat>|null Canonical MIME type => image format, built on first use. */
	private static ?array $formats = null;


	/**
	 * Return the canonical form of a MIME type.
	 *
	 * @param string $mime MIME type, as detected or configured.
	 * @return string Lowercased type, mapped through the alias list.
	 */
	public static function canonical(string $mime): string {
		$mime = \strtolower($mime);

		return self::ALIASES[$mime] ?? $mime;
	}


	/**
	 * Return the extension Upload stores a canonical MIME type with.
	 *
	 * Behavior:
	 * - A citomni/image format: its ImageFormat value, except "jpeg" -> "jpg"
	 *   and "tiff" -> "tif".
	 * - Any other type: the curated map, or "bin" outside it.
	 *
	 * @param string $canonicalMime Canonical (lowercase, de-aliased) MIME type.
	 * @return string Extension without the dot; "bin" for types that are neither an image format nor in the curated map.
	 */
	public static function extension(string $canonicalMime): string {
		$format = self::imageFormat($canonicalMime);

		if ($format !== null) {
			return self::FORMAT_EXTENSIONS[$format->value] ?? $format->value;
		}

		return self::EXTENSIONS[$canonicalMime] ?? 'bin';
	}


	/**
	 * Return the citomni/image format of a canonical MIME type.
	 *
	 * @param string $canonicalMime Canonical (lowercase, de-aliased) MIME type.
	 * @return ImageFormat|null The format, or null when the type is not a citomni/image format.
	 */
	public static function imageFormat(string $canonicalMime): ?ImageFormat {
		if (self::$formats === null) {
			$formats = [];

			foreach (ImageFormat::cases() as $format) {
				$formats[$format->mime()] = $format;
			}

			self::$formats = $formats;
		}

		return self::$formats[$canonicalMime] ?? null;
	}


}
