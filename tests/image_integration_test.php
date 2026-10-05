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
 * The image path against the real citomni/image service.
 *
 * Header-level checks run everywhere, because inspect() needs no codec:
 * crafted JPEG, PNG, GIF, WebP, BMP, and TIFF headers show that finfo and
 * inspect() agree and that a kept original reports the size inspect() sees.
 * With ext-gd, a GD-made JPEG carrying EXIF orientation 6 runs the happy path
 * (a re-encoded main with a variant, and a kept original with a preview), and
 * every format the runtime can encode is produced with citomni/image itself
 * to compare finfo and inspect() on real files. Formats without an encoder
 * in this runtime are skipped one by one.
 */

require __DIR__ . '/bootstrap.php';

use CitOmni\Image\Boot\Registry as ImageRegistry;
use CitOmni\Image\Enum\ImageFormat;
use CitOmni\Image\Service\Image;
use CitOmni\Upload\Enum\UploadRejection;
use CitOmni\Upload\Exception\UploadRejectedException;
use CitOmni\Upload\Service\Upload;
use CitOmni\Upload\Tests\Support\Fixtures;
use CitOmni\Upload\Tests\Support\TestApp;
use CitOmni\Upload\Util\MimeMap;

$dir = tempDir();
$root = $dir . '/root';
\mkdir($root);

$app = testApp(
	testCfg(ImageRegistry::CFG_COMMON, ['upload' => ['storages' => ['files' => ['root' => $root, 'web_path' => null]]]]),
	['image' => static fn(TestApp $app): Image => new Image($app)]
);
$upload = new Upload($app);
$image = $app->image;
$finfo = new \finfo(\FILEINFO_MIME_TYPE);
$accept = \array_map(static fn(ImageFormat $format): string => $format->mime(), ImageFormat::cases());
$keep = ['storage' => 'files', 'accept' => $accept, 'max_bytes' => 5_000_000, 'image' => ['main' => null]];

/** Write a file into the scratch directory and return its path. */
$file = static function (string $name, string $bytes) use ($dir): string {
	\file_put_contents($dir . '/' . $name, $bytes);

	return $dir . '/' . $name;
};

// -- Crafted headers: finfo and inspect() agree ------------------------------------------------

foreach ([ImageFormat::Jpeg, ImageFormat::Png, ImageFormat::Gif, ImageFormat::Webp, ImageFormat::Bmp, ImageFormat::Tiff] as $format) {
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
}

if (!\extension_loaded('gd')) {
	skip('decoding and encoding with citomni/image', 'ext-gd is not loaded');
	done('image_integration_test');
	exit(0);
}

// -- A GD-made JPEG with EXIF orientation 6 ------------------------------------------------------

$capabilities = $image->capabilities();
$canvas = \imagecreatetruecolor(40, 20);
\imagefilledrectangle($canvas, 0, 0, 19, 19, (int)\imagecolorallocate($canvas, 200, 30, 30));
\ob_start();
\imagejpeg($canvas, null, 90);
$plain = (string)\ob_get_clean();

// APP1 EXIF right after SOI: little-endian TIFF header and one IFD entry, Orientation (0x0112) = 6.
$tiff = "II\x2A\x00" . \pack('V', 8) . \pack('v', 1) . \pack('vvVvv', 0x0112, 3, 1, 6, 0) . \pack('V', 0);
$oriented = \substr($plain, 0, 2) . "\xFF\xE1" . \pack('n', 2 + 6 + \strlen($tiff)) . "Exif\x00\x00" . $tiff . \substr($plain, 2);
$jpeg = $file('oriented.jpg', $oriented);
$info = $image->inspect($jpeg);
check($info['orientation'] === 6 && [$info['width'], $info['height'], $info['display_width'], $info['display_height']] === [40, 20, 20, 40], 'the GD-made JPEG carries EXIF orientation 6: stored 40x20, displayed 20x40');

if ($capabilities['decode']['jpeg'] !== null && $capabilities['encode']['webp'] !== null) {
	$avatar = ['storage' => 'files', 'accept' => ['image/jpeg'], 'max_bytes' => 5_000_000, 'image' => [
		'main' => ['format' => 'webp', 'width' => 1600, 'height' => 1600, 'fit' => 'contain'],
		'variants' => ['thumb' => ['format' => 'webp', 'width' => 10, 'height' => 10, 'fit' => 'cover']],
	]];
	$result = $upload->storeLocal($jpeg, $avatar, 'u');
	$main = \getimagesize($root . '/' . $result['path']);
	$thumbPath = $result['variants']['thumb']['path'] ?? '';
	$thumb = \getimagesize($root . '/' . $thumbPath);

	check(
		\preg_match('~^u/[0-9a-f]{32}\.webp$~D', $result['path']) === 1 && $result['mime'] === 'image/webp' && $result['source_mime'] === 'image/jpeg'
		&& $result['size'] === \filesize($root . '/' . $result['path']) && [$result['width'], $result['height']] === [20, 40],
		'a re-encoded main is webp, auto-oriented to 20x40 and not upscaled, with the size from the Image result'
	);
	check(\is_array($main) && $main['mime'] === 'image/webp' && [$main[0], $main[1]] === [20, 40], 'the main file decodes as the reported webp');
	check(
		$thumbPath === \substr($result['path'], 0, -5) . '-thumb.webp' && \is_array($thumb) && [$thumb[0], $thumb[1]] === [10, 10]
		&& $result['variants']['thumb']['size'] === \filesize($root . '/' . $thumbPath),
		'the variant is a 10x10 webp next to the main file'
	);

	if (!isWindows()) {
		\clearstatcache();
		check((\fileperms($root . '/' . $result['path']) & 0777) === 0644 && (\fileperms($root . '/' . $thumbPath) & 0777) === 0644, 'upload.file_mode (0644) is applied to the outputs citomni/image wrote');
	}

	$archive = ['storage' => 'files', 'directory' => 'archive', 'accept' => ['image/jpeg'], 'max_bytes' => 5_000_000, 'hash' => 'sha256', 'image' => [
		'main' => null,
		'variants' => ['preview' => ['format' => 'webp', 'width' => 10]],
	]];
	$result = $upload->storeLocal($jpeg, $archive);
	$preview = \getimagesize($root . '/' . ($result['variants']['preview']['path'] ?? ''));
	check(
		\str_ends_with($result['path'], '.jpg') && \file_get_contents($root . '/' . $result['path']) === $oriented
		&& $result['hash'] === 'sha256:' . \hash('sha256', $oriented) && [$result['width'], $result['height']] === [20, 40],
		'a kept original is bit-identical and hashed, and reports the display dimensions'
	);
	check(\is_array($preview) && [$preview[0], $preview[1]] === [10, 20], 'its preview is rendered from the oriented picture');
} else {
	skip('happy path with citomni/image', 'JPEG decoding or WebP encoding is not available');
}

// -- Real files of every format this runtime can encode ------------------------------------------

$encoded = [];

foreach (ImageFormat::cases() as $format) {
	if ($capabilities['decode']['jpeg'] === null || $capabilities['encode'][$format->value] === null) {
		skip("a real {$format->name} file", 'no backend can encode it in this runtime');
		continue;
	}

	$path = $dir . '/real-' . $format->value . '.' . MimeMap::extension($format->mime());
	$saved = $image->save($jpeg, ['file' => ['path' => $path, 'format' => $format]]);
	$encoded[$format->value] = $path;
	$result = $upload->storeLocal($path, $keep);

	check(
		$saved['file']['mime'] === $format->mime() && MimeMap::canonical((string)$finfo->file($path)) === $format->mime()
		&& $result['mime'] === $format->mime() && [$result['width'], $result['height']] === [20, 40],
		"{$format->name}: finfo and inspect() agree on a file citomni/image encoded"
	);
}

// An AVIF with the generic HEIF major brand: finfo says HEIF, citomni/image says AVIF.
if (isset($encoded['avif']) && \substr((string)\file_get_contents($encoded['avif']), 4, 8) === 'ftypavif') {
	$generic = $file('generic.avif', \substr_replace((string)\file_get_contents($encoded['avif']), 'mif1', 8, 4));
	$e = expectThrows(UploadRejectedException::class, static fn() => $upload->storeLocal($generic, $keep), 'an AVIF with major brand mif1');
	check(
		$e->reason === UploadRejection::TypeNotAllowed && $e->context['mime'] === 'image/heic' && $e->context['image_mime'] === 'image/avif',
		'an AVIF with major brand mif1 is rejected fail-closed: finfo reports HEIF, citomni/image reports AVIF'
	);
} else {
	skip('AVIF with major brand mif1', 'no AVIF file with major brand avif could be produced in this runtime');
}

done('image_integration_test');
