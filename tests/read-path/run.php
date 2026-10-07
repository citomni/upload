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

namespace CitOmni\Upload\Tests\ReadPath;

use CitOmni\Image\Enum\ImageFormat;
use CitOmni\Upload\Exception\UploadConfigException;
use CitOmni\Upload\Service\Upload;
use CitOmni\Upload\Tests\Support\Fixtures;
use CitOmni\Upload\Tests\Support\MemoryStorage;
use CitOmni\Upload\Tests\Support\ScriptedImage;

/*
 * Isolated suite for the path methods: variantPath(), absolutePath(), and
 * webPath(). Derivation (variant extensions follow the variant format, and the
 * derived paths are the ones a store wrote for a re-encoded main and for a kept
 * original), validation, storage and profile errors, the web_path rules, and
 * the guarantee that the path methods make no filesystem call, create no finfo,
 * and never touch citomni/image.
 *
 * The no-IO proof uses a storage whose root is a MemoryStorage URL, which
 * records every wrapper call, and a root that does not exist. A stat, an
 * existence check, or realpath() would be recorded, throw, or change the
 * returned string. A control case shows that the recorder does see IO.
 *
 * Usage:
 *   php tests/read-path/run.php
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
$name = \str_repeat('cd', 16);

$avatar = [
	'storage' => 'files',
	'accept' => ['image/jpeg'],
	'max_bytes' => 1_000_000,
	'image' => [
		'main' => ['format' => 'webp'],
		'variants' => [
			'thumb' => ['format' => 'webp'],
			'small' => ['format' => ImageFormat::Jpeg],
			2 => ['format' => 'png'],
		],
	],
];
$archive = [
	'storage' => 'files',
	'accept' => ['image/jpeg'],
	'max_bytes' => 1_000_000,
	'image' => ['main' => null, 'variants' => ['preview' => ['format' => 'webp'], 'scan' => ['format' => 'tiff']]],
];
$attachment = ['storage' => 'files', 'accept' => ['application/pdf'], 'max_bytes' => 1_000_000];

$root = $dir . '/storage';
\mkdir($root);
$cfg = testCfg(['upload' => ['storages' => ['files' => ['root' => $root, 'web_path' => 'media']]]]);


// -- 1. variantPath() -------------------------------------------------------------------

test('variantPath() derives the paths a store wrote, for a re-encoded main and for a kept original', static function () use ($dir, $cfg, $root, $avatar, $archive): void {
	$upload = new Upload(testApp($cfg, ['image' => new ScriptedImage()]));
	$jpeg = $dir . '/photo.jpg';
	\file_put_contents($jpeg, Fixtures::header(ImageFormat::Jpeg, 100, 100));

	$produced = $upload->storeLocal($jpeg, $avatar, 'users/42');
	$kept = $upload->storeLocal($jpeg, $archive, 'users/43');
	check(\count($produced['variants']) === 3 && \count($kept['variants']) === 2, 'the stores wrote three and two variants');

	foreach ([[$produced, $avatar, 'a re-encoded main'], [$kept, $archive, 'a kept original']] as [$stored, $profile, $kind]) {
		foreach ($stored['variants'] as $key => $variant) {
			check($upload->variantPath($stored['path'], (string)$key, $profile) === $variant['path'] && \is_file($root . '/' . $variant['path']), "variantPath() derives the stored path of variant {$key} for {$kind}");
		}
	}
});

test('variantPath() takes the extension from the variant format: jpeg as .jpg, tiff as .tif, whatever the main file', static function () use ($cfg, $name, $avatar, $archive): void {
	$upload = new Upload(testApp($cfg, ['image' => new ScriptedImage()]));
	check($upload->variantPath("a/{$name}.webp", 'small', $avatar) === "a/{$name}-small.jpg", 'a jpeg variant is stored as .jpg');
	check($upload->variantPath("a/{$name}.jpg", 'scan', $archive) === "a/{$name}-scan.tif", 'a tiff variant is stored as .tif');
	check($upload->variantPath("a/{$name}.webp", 'thumb', $avatar) === $upload->variantPath("a/{$name}.jpg", 'thumb', $avatar), 'the main file extension does not matter');
	check($upload->variantPath("{$name}.webp", '2', $avatar) === "{$name}-2.png", 'a main file at the storage root and a digit-only variant key');
	check($upload->variantPath('legacy.v2.jpg', 'thumb', $avatar) === 'legacy.v2-thumb.webp', 'only the last extension of the main file name is replaced');
});

test('variantPath() rejects a variant the profile does not define, main included', static function () use ($cfg, $name, $avatar): void {
	$upload = new Upload(testApp($cfg, ['image' => new ScriptedImage()]));

	foreach (['nope', 'main', 'THUMB', ''] as $variant) {
		$e = expectThrows(\InvalidArgumentException::class, static fn() => $upload->variantPath("a/{$name}.webp", $variant, $avatar), 'variantPath() with variant ' . \var_export($variant, true));
		check(\str_contains($e->getMessage(), 'defines no variant'), 'the message says the profile defines no such variant: ' . \var_export($variant, true));
	}
});

test('variantPath() rejects a profile without image block, invalid paths, an undefined profile, and a missing image service', static function () use ($cfg, $name, $avatar, $attachment): void {
	$upload = new Upload(testApp($cfg, ['image' => new ScriptedImage()]));
	expectThrows(\InvalidArgumentException::class, static fn() => $upload->variantPath("a/{$name}.pdf", 'thumb', $attachment), 'a profile without image block defines no variants');
	expectThrows(\InvalidArgumentException::class, static fn() => $upload->variantPath('', 'thumb', $avatar), 'variantPath() of an empty path');
	expectThrows(\InvalidArgumentException::class, static fn() => $upload->variantPath('../x.webp', 'thumb', $avatar), 'variantPath() of a path that leaves the storage');
	expectThrows(UploadConfigException::class, static fn() => $upload->variantPath("a/{$name}.webp", 'thumb', 'undefined'), 'variantPath() with an undefined profile');

	$noImage = new Upload(testApp($cfg, ['image' => null]));
	expectThrows(UploadConfigException::class, static fn() => $noImage->variantPath("a/{$name}.webp", 'thumb', $avatar), 'an image profile needs the image service, also for path derivation');
});


// -- 2. absolutePath() ------------------------------------------------------------------

$roots = testCfg(['upload' => ['storages' => [
	'files' => ['root' => $root, 'web_path' => null],
	'slash' => ['root' => $root . '/', 'web_path' => null],
	'absent' => ['root' => $dir . '/does/not/exist', 'web_path' => null],
	'empty' => ['root' => '', 'web_path' => null],
	'number' => ['root' => 5, 'web_path' => null],
]]]);

test('absolutePath() joins the configured root and the path, without checking or creating the root', static function () use ($roots, $root, $dir): void {
	$paths = new Upload(testApp($roots));
	check($paths->absolutePath('files', 'users/42/x.webp') === $root . '/users/42/x.webp', 'absolutePath() joins the root and the relative path');
	check($paths->absolutePath('slash', 'users/42/x.webp') === $root . '/users/42/x.webp', 'a trailing separator on the root is not doubled');
	check($paths->absolutePath('absent', 'x.webp') === $dir . '/does/not/exist/x.webp', 'absolutePath() does not check that the root exists');
	check(!\file_exists($dir . '/does'), 'and it creates nothing');
});

test('absolutePath() rejects an undefined storage, an empty or non-string root, and invalid paths', static function () use ($roots): void {
	$paths = new Upload(testApp($roots));
	expectThrows(UploadConfigException::class, static fn() => $paths->absolutePath('nope', 'x.webp'), 'absolutePath() on an undefined storage');
	expectThrows(UploadConfigException::class, static fn() => $paths->absolutePath('empty', 'x.webp'), 'absolutePath() on a storage whose root is ""');
	expectThrows(UploadConfigException::class, static fn() => $paths->absolutePath('number', 'x.webp'), 'absolutePath() on a storage whose root is not a string');
	expectThrows(\InvalidArgumentException::class, static fn() => $paths->absolutePath('files', ''), 'absolutePath() of an empty path');
	expectThrows(\InvalidArgumentException::class, static fn() => $paths->absolutePath('files', 'a/../../etc/passwd'), 'absolutePath() of a path that leaves the storage');
});


// -- 3. webPath() -----------------------------------------------------------------------

$web = static fn(): Upload => new Upload(testApp(testCfg(['upload' => ['storages' => [
	'docroot' => ['root' => $root, 'web_path' => ''],
	'cdn' => ['root' => $root, 'web_path' => 'cdn/media'],
	'nokey' => ['root' => $root],
	'leading' => ['root' => $root, 'web_path' => '/uploads'],
	'trailing' => ['root' => $root, 'web_path' => 'uploads/'],
	'double' => ['root' => $root, 'web_path' => 'a//b'],
	'parent' => ['root' => $root, 'web_path' => '../up'],
	'space' => ['root' => $root, 'web_path' => 'up loads'],
	'number' => ['root' => $root, 'web_path' => 5],
]]])));

test('webPath() serves the public baseline storage under uploads/u, and the private one has no web path', static function (): void {
	$baseline = new Upload(testApp(testCfg()));
	check($baseline->webPath('public', 'users/42/x.webp') === 'uploads/u/users/42/x.webp', 'the public baseline storage serves files under uploads/u, without a leading slash');
	$e = expectThrows(UploadConfigException::class, static fn() => $baseline->webPath('private', 'x.pdf'), 'webPath() on the private baseline storage');
	check(\str_contains($e->getMessage(), 'no web_path'), 'the message says the storage has no web_path');
});

test('webPath() serves a web_path of "" from the web root and prefixes a nested one; a missing key means no web path', static function () use ($web): void {
	$upload = $web();
	check($upload->webPath('docroot', 'users/42/x.webp') === 'users/42/x.webp', 'a web_path of "" serves the storage from the web root');
	check($upload->webPath('cdn', 'x.webp') === 'cdn/media/x.webp' && $upload->webPath('cdn', 'y.webp') === 'cdn/media/y.webp', 'a nested web_path is prefixed, also on repeated calls');
	expectThrows(UploadConfigException::class, static fn() => $upload->webPath('nokey', 'x.webp'), 'a storage without a web_path key has no web path');
});

test('webPath() rejects an invalid web_path with a message naming the storage', static function () use ($web): void {
	$upload = $web();

	foreach (['leading', 'trailing', 'double', 'parent', 'space', 'number'] as $storage) {
		$e = expectThrows(UploadConfigException::class, static fn() => $upload->webPath($storage, 'x.webp'), "webPath() with the invalid web_path of storage '{$storage}'");
		check(\str_contains($e->getMessage(), "'{$storage}'") && \str_contains($e->getMessage(), 'web_path'), "the message names storage '{$storage}' and web_path");
	}
});

test('webPath() rejects an undefined storage and invalid paths', static function () use ($web): void {
	$upload = $web();
	expectThrows(UploadConfigException::class, static fn() => $upload->webPath('nope', 'x.webp'), 'webPath() on an undefined storage');
	expectThrows(\InvalidArgumentException::class, static fn() => $upload->webPath('cdn', ''), 'webPath() of an empty path');
	expectThrows(\InvalidArgumentException::class, static fn() => $upload->webPath('cdn', '/x.webp'), 'webPath() of a path with a leading slash');
});


// -- 4. The path methods make no IO and touch no service --------------------------------

/**
 * Upload over a recording MemoryStorage root ("files") and a root that does not exist ("absent").
 *
 * @return array{0: Upload, 1: string, 2: \CitOmni\Upload\Tests\Support\TestApp} Upload, recording root URL, app.
 */
$recorded = static function () use ($dir): array {
	MemoryStorage::reset();
	$memoryRoot = MemoryStorage::root('readpath');
	$app = testApp(testCfg(['upload' => [
		'storages' => [
			'files' => ['root' => $memoryRoot, 'web_path' => 'media'],
			'absent' => ['root' => $dir . '/also/missing', 'web_path' => 'absent'],
		],
		'profiles' => ['avatar' => ['storage' => 'files', 'accept' => ['image/jpeg'], 'max_bytes' => 1000, 'image' => ['main' => ['format' => 'webp'], 'variants' => ['thumb' => ['format' => 'webp'], 'small' => ['format' => 'jpeg']]]]],
	]]));

	return [new Upload($app), $memoryRoot, $app];
};

test('the path methods make no filesystem call, create nothing, resolve no service, and create no finfo', static function () use ($recorded, $dir, $name, $avatar): void {
	[$upload, $memoryRoot, $app] = $recorded();
	$main = "u/{$name}.webp";

	$results = [
		$upload->variantPath($main, 'thumb', 'avatar'),
		$upload->variantPath($main, 'small', $avatar),
		$upload->absolutePath('files', $main),
		$upload->absolutePath('absent', $main),
		$upload->webPath('files', $main),
		$upload->webPath('absent', $main),
	];
	check($results === [
		"u/{$name}-thumb.webp",
		"u/{$name}-small.jpg",
		"{$memoryRoot}/{$main}",
		"{$dir}/also/missing/{$main}",
		"media/{$main}",
		"absent/{$main}",
	], 'the path methods return the derived strings for a recording root and for a root that does not exist');

	foreach ([
		static fn() => $upload->variantPath('', 'thumb', 'avatar'),
		static fn() => $upload->variantPath($main, 'nope', 'avatar'),
		static fn() => $upload->absolutePath('files', '../x'),
		static fn() => $upload->webPath('files', 'a//b'),
	] as $i => $call) {
		expectThrows(\InvalidArgumentException::class, $call, "failing path call {$i}");
	}

	check(MemoryStorage::$operations === [], 'the path methods make no filesystem call on the storage: no stat, no open, no unlink');
	check(!\file_exists($dir . '/also'), 'the root that does not exist is not created');
	check($app->touched === [], 'the path methods resolve no service: not image, not txt, not log');
	check((new \ReflectionProperty(Upload::class, 'finfo'))->getValue($upload) === null, 'the path methods create no finfo');
});

test('control: the same storages record the filesystem calls of a deletion', static function () use ($recorded, $name): void {
	[$upload, $memoryRoot] = $recorded();
	$main = "u/{$name}.webp";

	expectThrows(UploadConfigException::class, static fn() => $upload->delete('absent', $main), 'delete() checks the root that the path methods leave alone');
	$upload->delete('files', $main);
	check(MemoryStorage::$operations !== [] && MemoryStorage::unlinked() === ["{$memoryRoot}/{$main}"], 'control: the recorder sees the filesystem calls of a deletion');
});

done();
