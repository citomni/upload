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

use CitOmni\Image\Enum\ImageFormat;

/**
 * Small byte fixtures for finfo-based content detection.
 *
 * Notes:
 * - The plain fixtures are made for content sniffing: the JPEG is a header
 *   without image data. header() adds the dimensions getimagesize() and
 *   citomni/image's inspect() need. Nothing here is meant to be decoded, and
 *   no GD functions are needed.
 */
final class Fixtures {

	/**
	 * Minimal PDF document (application/pdf).
	 *
	 * @return string Bytes.
	 */
	public static function pdf(): string {
		return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
	}


	/**
	 * JPEG header without image data (image/jpeg).
	 *
	 * @return string Bytes.
	 */
	public static function jpeg(): string {
		return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
	}


	/**
	 * 1x1 24-bit Windows bitmap (image/bmp, or image/x-ms-bmp with older libmagic).
	 *
	 * @return string Bytes.
	 */
	public static function bmp(): string {
		return 'BM' . \pack('V', 58) . \pack('v2', 0, 0) . \pack('V', 54)
			. \pack('V', 40) . \pack('V', 1) . \pack('V', 1) . \pack('v', 1) . \pack('v', 24)
			. \pack('V', 0) . \pack('V', 4) . \pack('V', 2835) . \pack('V', 2835) . \pack('V', 0) . \pack('V', 0)
			. "\x00\x00\xFF\x00";
	}


	/**
	 * HEIF file-type box with the "mif1" brand (libmagic reports image/heif; canonical image/heic).
	 *
	 * @return string Bytes.
	 */
	public static function heif(): string {
		return \pack('N', 24) . 'ftypmif1' . "\x00\x00\x00\x00" . 'mif1heic' . \str_repeat("\x00", 64);
	}


	/**
	 * PHP source code (text/x-php).
	 *
	 * @return string Bytes.
	 */
	public static function php(): string {
		return "<?php\necho 'This must never be stored as an image or executable script.';\n";
	}


	/**
	 * Bytes without a recognizable signature (application/octet-stream).
	 *
	 * @return string Bytes.
	 */
	public static function binary(): string {
		return "\x00\x01\x02\x03\x04\x05\x06\x07\xFE\xFD\xFC\xFB\xFA\xF9\xF8\xF7";
	}

	/**
	 * Header-level image of a given size, readable by getimagesize() and citomni/image's inspect().
	 *
	 * The pixel data is not meaningful: these files exercise detection and
	 * inspection, never decoding. AVIF and HEIC need an encoder; produce them
	 * with citomni/image where the runtime can.
	 *
	 * @param ImageFormat $format Jpeg, Png, Gif, Webp, Bmp, or Tiff.
	 * @param int $width Width in pixels, 1-16383.
	 * @param int $height Height in pixels, 1-16383.
	 * @return string Bytes.
	 * @throws \LogicException For AVIF and HEIC.
	 */
	public static function header(ImageFormat $format, int $width, int $height): string {
		return match ($format) {
			ImageFormat::Jpeg => "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
				. "\xFF\xC0\x00\x11\x08" . \pack('n2', $height, $width) . "\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01"
				. "\xFF\xDA\x00\x0C\x03\x01\x00\x02\x11\x03\x11\x00\x3F\x00" . \str_repeat("\x00", 16) . "\xFF\xD9",
			ImageFormat::Png => "\x89PNG\r\n\x1A\n" . self::pngChunk('IHDR', \pack('N2', $width, $height) . "\x08\x02\x00\x00\x00") . self::pngChunk('IEND', ''),
			ImageFormat::Gif => 'GIF89a' . \pack('v2', $width, $height) . "\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF"
				. "\x2C" . \pack('v4', 0, 0, $width, $height) . "\x00\x02\x02\x44\x01\x00\x3B",
			ImageFormat::Webp => self::webpLossless($width, $height),
			ImageFormat::Bmp => self::bitmap($width, $height),
			ImageFormat::Tiff => self::tiffGray($width, $height),
			ImageFormat::Avif, ImageFormat::Heic => throw new \LogicException($format->name . ' needs an encoder; produce it with citomni/image.'),
		};
	}


	/**
	 * ISOBMFF file-type box, enough for content sniffing of HEIF-family files.
	 *
	 * @param string $major Major brand, four characters.
	 * @param string ...$compatible Compatible brands, four characters each.
	 * @return string The ftyp box followed by padding.
	 */
	public static function ftyp(string $major, string ...$compatible): string {
		$body = $major . "\x00\x00\x00\x00" . \implode('', $compatible);

		return \pack('N', 8 + \strlen($body)) . 'ftyp' . $body . \str_repeat("\x00", 64);
	}


	/**
	 * PNG chunk with its CRC.
	 *
	 * @param string $type Chunk type.
	 * @param string $data Chunk data.
	 * @return string Bytes.
	 */
	private static function pngChunk(string $type, string $data): string {
		return \pack('N', \strlen($data)) . $type . $data . \pack('N', \crc32($type . $data));
	}


	/**
	 * Lossless WebP header (VP8L) without image data.
	 *
	 * @param int $width Width in pixels.
	 * @param int $height Height in pixels.
	 * @return string Bytes.
	 */
	private static function webpLossless(int $width, int $height): string {
		// Signature 0x2F, then 14 bits width - 1 and 14 bits height - 1; alpha and version 0.
		// Zero padding keeps the file long enough for getimagesize(), which reads 30 bytes.
		$chunk = "\x2F" . \pack('V', ($width - 1) | (($height - 1) << 14)) . \str_repeat("\x00", 5);
		$payload = 'WEBP' . 'VP8L' . \pack('V', \strlen($chunk)) . $chunk;

		return 'RIFF' . \pack('V', \strlen($payload)) . $payload;
	}


	/**
	 * 24-bit Windows bitmap with black pixels.
	 *
	 * @param int $width Width in pixels.
	 * @param int $height Height in pixels.
	 * @return string Bytes.
	 */
	private static function bitmap(int $width, int $height): string {
		$pixels = ((($width * 24 + 31) >> 5) * 4) * $height;

		return 'BM' . \pack('V', 54 + $pixels) . \pack('v2', 0, 0) . \pack('V', 54)
			. \pack('V3', 40, $width, $height) . \pack('v2', 1, 24) . \pack('V6', 0, $pixels, 2835, 2835, 0, 0)
			. \str_repeat("\x00", $pixels);
	}


	/**
	 * Little-endian TIFF: one IFD, 8-bit grayscale, uncompressed, one strip.
	 *
	 * @param int $width Width in pixels.
	 * @param int $height Height in pixels.
	 * @return string Bytes.
	 */
	private static function tiffGray(int $width, int $height): string {
		$entries = [[256, 4, $width], [257, 4, $height], [258, 3, 8], [259, 3, 1], [262, 3, 1], [273, 4, 0], [277, 3, 1], [278, 4, $height], [279, 4, $width * $height]];
		$dataOffset = 8 + 2 + \count($entries) * 12 + 4;
		$ifd = \pack('v', \count($entries));

		foreach ($entries as [$tag, $type, $value]) {
			$value = $tag === 273 ? $dataOffset : $value;
			$ifd .= \pack('vvV', $tag, $type, 1) . ($type === 3 ? \pack('v2', $value, 0) : \pack('V', $value));
		}

		return "II\x2A\x00" . \pack('V', 8) . $ifd . \pack('V', 0) . \str_repeat("\x80", $width * $height);
	}



}
