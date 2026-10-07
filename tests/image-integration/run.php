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

namespace CitOmni\Upload\Tests\ImageIntegration;

use CitOmni\Image\Boot\Registry as ImageRegistry;
use CitOmni\Image\Enum\ImageFormat;
use CitOmni\Image\Service\Image;
use CitOmni\Upload\Enum\UploadRejection;
use CitOmni\Upload\Exception\UploadRejectedException;
use CitOmni\Upload\Service\Upload;
use CitOmni\Upload\Tests\Support\Fixtures;
use CitOmni\Upload\Tests\Support\TestApp;
use CitOmni\Upload\Util\MimeMap;

/*
 * Isolated suite for the image path against the real citomni/image service.
 *
 * Header-level cases run everywhere, because inspect() needs no codec:
 * crafted JPEG, PNG, GIF, WebP, BMP, and TIFF headers show that finfo and
 * inspect() agree and that a kept original reports the size inspect() sees.
 * With ext-gd, a GD-made JPEG carrying EXIF orientation 6 runs the happy path
 * (a re-encoded main with a variant, and a kept original with a preview), and
 * every format the runtime can encode is produced with citomni/image itself
 * to compare finfo and inspect() on real files. Cases that need ext-gd or a
 * codec this runtime lacks are skipped one by one.
 *
 * Usage:
 *   php tests/image-integration/run.php
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

// Fail fast on every diagnostic, including deprecations and #[\NoDiscard] warnings. Like the
// production ErrorHandler, leave diagnostics silenced with @ to PHP, so error_get_last() works.
\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	if ((\error_reporting() & $errno) === 0) {
		return false;
	}

	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require \dirname(__DIR__) . '/bootstrap.php';

$dir = tempDir();

/**
 * Create a fresh storage root and an Upload service with the real citomni/image service.
 *
 * @return array{0: Upload, 1: string, 2: Image}
 */
$setup = static function () use ($dir): array {
	$root = $dir . '/root-' . \bin2hex(\random_bytes(4));
	\mkdir($root);
	$app = testApp(
		testCfg(ImageRegistry::CFG_COMMON, ['upload' => ['storages' => ['files' => ['root' => $root, 'web_path' => null]]]]),
		['image' => static fn(TestApp $app): Image => new Image($app)]
	);

	return [new Upload($app), $root, $app->image];
};

/** Write a file into the scratch directory and return its path. */
$file = static function (string $name, string $bytes) use ($dir): string {
	\file_put_contents($dir . '/' . $name, $bytes);

	return $dir . '/' . $name;
};

/** Skip the current case unless ext-gd is loaded and citomni/image can decode JPEG and encode $format here. */
$requiresCodecs = static function (Image $image, ImageFormat $format): void {
	requires(\extension_loaded('gd'), 'ext-gd is not loaded');
	$capabilities = $image->capabilities();
	requires($capabilities['decode']['jpeg'] !== null && $capabilities['encode'][$format->value] !== null, "citomni/image cannot decode JPEG or encode {$format->name} in this runtime");
};

/**
 * Bytes of a GD-made 40x20 JPEG with EXIF orientation 6: stored 40x20, displayed 20x40. Needs ext-gd.
 *
 * The left half is red, so an orientation that is not applied would show.
 */
$orientedJpeg = static function (): string {
	$canvas = \imagecreatetruecolor(40, 20);
	\imagefilledrectangle($canvas, 0, 0, 19, 19, (int)\imagecolorallocate($canvas, 200, 30, 30));
	\ob_start();
	\imagejpeg($canvas, null, 90);
	$plain = (string)\ob_get_clean();

	// APP1 EXIF right after SOI: little-endian TIFF header and one IFD entry, Orientation (0x0112) = 6.
	$tiff = "II\x2A\x00" . \pack('V', 8) . \pack('v', 1) . \pack('vvVvv', 0x0112, 3, 1, 6, 0) . \pack('V', 0);

	return \substr($plain, 0, 2) . "\xFF\xE1" . \pack('n', 2 + 6 + \strlen($tiff)) . "Exif\x00\x00" . $tiff . \substr($plain, 2);
};

$finfo = new \finfo(\FILEINFO_MIME_TYPE);
$accept = \array_map(static fn(ImageFormat $format): string => $format->mime(), ImageFormat::cases());
$keep = ['storage' => 'files', 'accept' => $accept, 'max_bytes' => 5_000_000, 'image' => ['main' => null]];
$avatar = ['storage' => 'files', 'accept' => ['image/jpeg'], 'max_bytes' => 5_000_000, 'image' => [
	'main' => ['format' => 'webp', 'width' => 1600, 'height' => 1600, 'fit' => 'contain'],
	'variants' => ['thumb' => ['format' => 'webp', 'width' => 10, 'height' => 10, 'fit' => 'cover']],
]];
$archive = ['storage' => 'files', 'directory' => 'archive', 'accept' => ['image/jpeg'], 'max_bytes' => 5_000_000, 'hash' => 'sha256', 'image' => [
	'main' => null,
	'variants' => ['preview' => ['format' => 'webp', 'width' => 10]],
]];


// -- 1. Crafted headers: finfo and inspect() agree --------------------------------------

foreach ([ImageFormat::Jpeg, ImageFormat::Png, ImageFormat::Gif, ImageFormat::Webp, ImageFormat::Bmp, ImageFormat::Tiff] as $format) {
	test("{$format->name}: finfo and inspect() agree on a crafted header, and the original is kept with the inspected dimensions", static function () use ($format, $setup, $file, $finfo, $keep): void {
		[$upload, $root, $image] = $setup();
		$bytes = Fixtures::header($format, 40, 20);
		$path = $file('header.' . $format->value, $bytes);
		$info = $image->inspect($path);

		check(
			MimeMap::canonical((string)$finfo->file($path)) === $format->mime() && $info['mime'] === $format->mime() && [$info['width'], $info['height']] === [40, 20],
			"{$format->name}: finfo and inspect() agree on a crafted header"
		);

		$result = $upload->storeLocal($path, $keep);
		check(
			$result['mime'] === $format->mime() && [$result['width'], $result['height']] === [40, 20]
			&& \str_ends_with($result['path'], '.' . MimeMap::extension($format->mime())) && \file_get_contents($root . '/' . $result['path']) === $bytes,
			"{$format->name}: the original is kept with the dimensions inspect() reports"
		);
	});
}


// -- 2. A GD-made JPEG with EXIF orientation 6 ------------------------------------------

test('the GD-made JPEG carries EXIF orientation 6: stored 40x20, displayed 20x40', static function () use ($setup, $file, $orientedJpeg): void {
	requires(\extension_loaded('gd'), 'ext-gd is not loaded');

	[, , $image] = $setup();
	$info = $image->inspect($file('oriented.jpg', $orientedJpeg()));
	check($info['orientation'] === 6 && [$info['width'], $info['height'], $info['display_width'], $info['display_height']] === [40, 20, 20, 40], 'the GD-made JPEG carries EXIF orientation 6: stored 40x20, displayed 20x40');
});

test('a re-encoded main is a webp, auto-oriented to 20x40 and not upscaled, with the size from the Image result', static function () use ($setup, $file, $orientedJpeg, $requiresCodecs, $avatar): void {
	[$upload, $root, $image] = $setup();
	$requiresCodecs($image, ImageFormat::Webp);

	$result = $upload->storeLocal($file('oriented.jpg', $orientedJpeg()), $avatar, 'u');
	$main = \getimagesize($root . '/' . $result['path']);

	check(
		\preg_match('~^u/[0-9a-f]{32}\.webp$~D', $result['path']) === 1 && $result['mime'] === 'image/webp' && $result['source_mime'] === 'image/jpeg'
		&& $result['size'] === \filesize($root . '/' . $result['path']) && [$result['width'], $result['height']] === [20, 40],
		'a re-encoded main is webp, auto-oriented to 20x40 and not upscaled, with the size from the Image result'
	);
	check(\is_array($main) && $main['mime'] === 'image/webp' && [$main[0], $main[1]] === [20, 40], 'the main file decodes as the reported webp');
});

test('the variant of a re-encoded main is a 10x10 webp next to the main file', static function () use ($setup, $file, $orientedJpeg, $requiresCodecs, $avatar): void {
	[$upload, $root, $image] = $setup();
	$requiresCodecs($image, ImageFormat::Webp);

	$result = $upload->storeLocal($file('oriented.jpg', $orientedJpeg()), $avatar, 'u');
	$thumbPath = $result['variants']['thumb']['path'] ?? '';
	$thumb = \getimagesize($root . '/' . $thumbPath);

	check(
		$thumbPath === \substr($result['path'], 0, -5) . '-thumb.webp' && \is_array($thumb) && [$thumb[0], $thumb[1]] === [10, 10]
		&& $result['variants']['thumb']['size'] === \filesize($root . '/' . $thumbPath),
		'the variant is a 10x10 webp next to the main file'
	);
});

test('upload.file_mode (0644) is applied to the outputs citomni/image wrote', static function () use ($setup, $file, $orientedJpeg, $requiresCodecs, $avatar): void {
	requires(!isWindows(), 'POSIX permissions do not apply on Windows');
	[$upload, $root, $image] = $setup();
	$requiresCodecs($image, ImageFormat::Webp);

	$result = $upload->storeLocal($file('oriented.jpg', $orientedJpeg()), $avatar, 'u');
	\clearstatcache();
	check((\fileperms($root . '/' . $result['path']) & 0777) === 0644 && (\fileperms($root . '/' . $result['variants']['thumb']['path']) & 0777) === 0644, 'upload.file_mode (0644) is applied to the outputs citomni/image wrote');
});

test('a kept original is bit-identical and hashed, reports the display dimensions, and its preview is rendered from the oriented picture', static function () use ($setup, $file, $orientedJpeg, $requiresCodecs, $archive): void {
	[$upload, $root, $image] = $setup();
	$requiresCodecs($image, ImageFormat::Webp);

	$oriented = $orientedJpeg();
	$result = $upload->storeLocal($file('oriented.jpg', $oriented), $archive);
	$preview = \getimagesize($root . '/' . ($result['variants']['preview']['path'] ?? ''));
	check(
		\str_ends_with($result['path'], '.jpg') && \file_get_contents($root . '/' . $result['path']) === $oriented
		&& $result['hash'] === 'sha256:' . \hash('sha256', $oriented) && [$result['width'], $result['height']] === [20, 40],
		'a kept original is bit-identical and hashed, and reports the display dimensions'
	);
	check(\is_array($preview) && [$preview[0], $preview[1]] === [10, 20], 'its preview is rendered from the oriented picture');
});


// -- 3. Real files of every format this runtime can encode ------------------------------

foreach (ImageFormat::cases() as $format) {
	test("a real {$format->name} file: finfo and inspect() agree on a file citomni/image encoded", static function () use ($format, $setup, $dir, $file, $orientedJpeg, $requiresCodecs, $finfo, $keep): void {
		[$upload, , $image] = $setup();
		$requiresCodecs($image, $format);

		$path = $dir . '/real-' . $format->value . '.' . MimeMap::extension($format->mime());
		$saved = $image->save($file('oriented.jpg', $orientedJpeg()), ['file' => ['path' => $path, 'format' => $format]]);
		$result = $upload->storeLocal($path, $keep);

		check(
			$saved['file']['mime'] === $format->mime() && MimeMap::canonical((string)$finfo->file($path)) === $format->mime()
			&& $result['mime'] === $format->mime() && [$result['width'], $result['height']] === [20, 40],
			"{$format->name}: finfo and inspect() agree on a file citomni/image encoded"
		);
	});
}

test('an AVIF with major brand mif1 is rejected fail-closed: finfo reports HEIF, citomni/image reports AVIF', static function () use ($setup, $dir, $file, $orientedJpeg, $requiresCodecs, $keep): void {
	[$upload, , $image] = $setup();
	$requiresCodecs($image, ImageFormat::Avif);

	$path = $dir . '/brand.avif';
	$image->save($file('oriented.jpg', $orientedJpeg()), ['file' => ['path' => $path, 'format' => ImageFormat::Avif]]);
	$avif = (string)\file_get_contents($path);
	requires(\substr($avif, 4, 8) === 'ftypavif', 'no AVIF file with major brand avif could be produced in this runtime');

	$generic = $file('generic.avif', \substr_replace($avif, 'mif1', 8, 4));
	$e = expectThrows(UploadRejectedException::class, static fn() => $upload->storeLocal($generic, $keep), 'an AVIF with major brand mif1');
	check(
		$e->reason === UploadRejection::TypeNotAllowed && $e->context['mime'] === 'image/heic' && $e->context['image_mime'] === 'image/avif',
		'an AVIF with major brand mif1 is rejected fail-closed: finfo reports HEIF, citomni/image reports AVIF'
	);
});

done();
