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

namespace CitOmni\Upload\Tests\Profile;

use CitOmni\Image\Enum\ImageFormat;
use CitOmni\Kernel\Arr;
use CitOmni\Upload\Boot\Registry;
use CitOmni\Upload\Enum\UploadRejection;
use CitOmni\Upload\Exception\UploadConfigException;
use CitOmni\Upload\Exception\UploadRejectedException;
use CitOmni\Upload\Service\Upload;
use CitOmni\Upload\Tests\Support\Fixtures;

/*
 * Isolated suite for the profile and configuration contract: the shipped
 * Registry baseline, every profile rule (UploadConfigException naming the
 * profile), the image-block structure Upload owns, upload.file_mode and
 * upload.dir_mode, named versus inline lookup, memoization, canonicalization
 * of accept, and the merge semantics of layered configuration (lists are
 * replaced).
 *
 * Profiles are exercised through storeLocal(). A text source keeps every
 * valid profile on the plain path, so the image service, which throws when
 * resolved, is never involved.
 *
 * Usage:
 *   php tests/profile/run.php
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
$root = $dir . '/storage';
\mkdir($root);

$text = $dir . '/note.txt';
\file_put_contents($text, "Hello, upload.\n");
$pdf = $dir . '/report.pdf';
\file_put_contents($pdf, Fixtures::pdf());
$bmp = $dir . '/pixel.bmp';
\file_put_contents($bmp, Fixtures::bmp());
$empty = $dir . '/empty.txt';
\file_put_contents($empty, '');

$storages = ['upload' => ['storages' => ['docs' => ['root' => $root, 'web_path' => null]]]];
$base = ['storage' => 'docs', 'accept' => ['text/plain'], 'max_bytes' => 1000];

/** Create an Upload service over the docs storage, with extra cfg layers. */
$service = static fn(array ...$layers): Upload => new Upload(testApp(testCfg($storages, ...$layers)));

/** A profile with one variant spec, for the variant rules. */
$variant = static fn(mixed $spec, string $key = 'thumb'): array => $base + ['image' => ['main' => null, 'variants' => [$key => $spec]]];

// Named profiles with invalid formats, keyed by profile name.
$formats = [
	'fmt_unknown' => 'jpg',
	'fmt_upper' => 'WEBP',
	'fmt_int' => 123,
	'fmt_null' => null,
	'fmt_flattened' => ['name' => 'Webp', 'value' => 'webp'],
];

$named = [
	'scalar' => 'not-a-profile',
	'list' => ['text/plain', 'docs'],
	'disabled' => null,
	'main_format' => $base + ['image' => ['main' => ['format' => 'png8']]],
	'bad_dir' => $base + ['directory' => '../escape'],
];

foreach ($formats as $name => $format) {
	$named[$name] = $variant(['format' => $format, 'width' => 300]);
}

/** Create an Upload service whose upload.profiles holds the invalid named profiles. */
$namedService = static fn(): Upload => $service(['upload' => ['profiles' => $named]]);


// -- 1. Registry baseline ---------------------------------------------------------------

test('Registry registers the upload service and ships the documented baseline, and nothing else', static function (): void {
	check(Registry::MAP_COMMON === ['upload' => Upload::class], 'MAP_COMMON registers the upload service');
	check(
		Registry::CFG_COMMON === [
			'upload' => [
				'storages' => [
					'public' => ['root' => \CITOMNI_APP_PATH . '/public/uploads/u', 'web_path' => 'uploads/u'],
					'private' => ['root' => \CITOMNI_APP_PATH . '/var/storage', 'web_path' => null],
				],
				'file_mode' => 0644,
				'dir_mode' => 0755,
				'profiles' => [],
			],
		],
		'CFG_COMMON ships the documented baseline'
	);
	check(\array_keys((new \ReflectionClass(Registry::class))->getConstants()) === ['MAP_COMMON', 'CFG_COMMON'], 'Registry declares only MAP_COMMON and CFG_COMMON');
});


// -- 2. Profile rules (inline profiles) -------------------------------------------------

test('an inline profile that breaks a rule is rejected, names the inline profile, and writes nothing', static function () use ($dir, $text, $base, $variant): void {
	$invalid = [
		'an unknown profile key' => $base + ['images' => ['main' => null]],
		'a misspelled profile key' => $base + ['shards' => 1],
		'a missing storage' => \array_diff_key($base, ['storage' => true]),
		'a non-string storage' => ['storage' => 1] + $base,
		'an empty storage' => ['storage' => ''] + $base,
		'an unknown storage' => ['storage' => 'nowhere'] + $base,
		'a non-string directory' => $base + ['directory' => 5],
		'a null directory' => $base + ['directory' => null],
		'a missing accept' => \array_diff_key($base, ['accept' => true]),
		'a null accept' => ['accept' => null] + $base,
		'an empty accept' => ['accept' => []] + $base,
		'accept as a string' => ['accept' => 'text/plain'] + $base,
		'accept as a map' => ['accept' => ['txt' => 'text/plain']] + $base,
		'a non-string accept entry' => ['accept' => ['text/plain', 5]] + $base,
		'an empty accept entry' => ['accept' => ['']] + $base,
		"'*' combined with a type" => ['accept' => ['*', 'text/plain']] + $base,
		"'*' twice" => ['accept' => ['*', '*']] + $base,
		'an image/* pattern' => ['accept' => ['image/*']] + $base,
		'a bare extension in accept' => ['accept' => ['pdf']] + $base,
		'a MIME type with a parameter' => ['accept' => ['text/plain; charset=utf-8']] + $base,
		'a MIME type with whitespace' => ['accept' => [' application/pdf']] + $base,
		'a MIME type without subtype' => ['accept' => ['text/']] + $base,
		'a missing max_bytes' => \array_diff_key($base, ['max_bytes' => true]),
		'a string max_bytes' => ['max_bytes' => '1000'] + $base,
		'a float max_bytes' => ['max_bytes' => 1000.0] + $base,
		'a bool max_bytes' => ['max_bytes' => true] + $base,
		'a zero max_bytes' => ['max_bytes' => 0] + $base,
		'a negative max_bytes' => ['max_bytes' => -1] + $base,
		'shard -1' => $base + ['shard' => -1],
		'shard 3' => $base + ['shard' => 3],
		'a string shard' => $base + ['shard' => '1'],
		'a null shard' => $base + ['shard' => null],
		'an unknown hash algorithm' => $base + ['hash' => 'sha265'],
		'an empty hash' => $base + ['hash' => ''],
		'a non-string hash' => $base + ['hash' => 256],
		'a scalar image block' => $base + ['image' => 'webp'],
		'an image block without main' => $base + ['image' => ['variants' => []]],
		'an unknown image block key' => $base + ['image' => ['main' => null, 'variant' => []]],
		'a scalar main' => $base + ['image' => ['main' => 'webp']],
		'an empty main spec' => $base + ['image' => ['main' => []]],
		'a main spec without format' => $base + ['image' => ['main' => ['width' => 100]]],
		'a main spec with path' => $base + ['image' => ['main' => ['format' => 'webp', 'path' => '/tmp/x.webp']]],
		'a main spec with a null path' => $base + ['image' => ['main' => ['format' => 'webp', 'path' => null]]],
		'a main spec with overwrite' => $base + ['image' => ['main' => ['format' => 'webp', 'overwrite' => false]]],
		'a main spec with an unknown format' => $base + ['image' => ['main' => ['format' => 'jpg']]],
		'scalar variants' => $base + ['image' => ['main' => null, 'variants' => 'thumb']],
		'null variants' => $base + ['image' => ['main' => null, 'variants' => null]],
		"the variant key 'main'" => $variant(['format' => 'webp'], 'main'),
		'an uppercase variant key' => $variant(['format' => 'webp'], 'Thumb'),
		'a hyphenated variant key' => $variant(['format' => 'webp'], 'th-umb'),
		'an empty variant key' => $variant(['format' => 'webp'], ''),
		'a scalar variant spec' => $variant('webp'),
		'a variant spec without format' => $variant(['width' => 300]),
		'a variant spec with path' => $variant(['format' => 'webp', 'path' => 'x.webp']),
		'a variant spec with overwrite' => $variant(['format' => 'webp', 'overwrite' => true]),
		'an unknown format string' => $variant(['format' => 'jpg']),
		'an uppercase format string' => $variant(['format' => 'WEBP']),
		'an integer format' => $variant(['format' => 123]),
		'a null format' => $variant(['format' => null]),
		'a flattened enum format' => $variant(['format' => ['name' => 'Webp', 'value' => 'webp']]),
		'a case of another enum as format' => $variant(['format' => UploadRejection::Empty]),
	];

	$root = $dir . '/rules';
	\mkdir($root);
	$upload = new Upload(testApp(testCfg(['upload' => ['storages' => ['docs' => ['root' => $root, 'web_path' => null]]]])));

	foreach ($invalid as $why => $profile) {
		$e = expectThrows(UploadConfigException::class, static fn() => $upload->storeLocal($text, $profile), "an inline profile with {$why} is rejected");
		check(\str_starts_with($e->getMessage(), 'Inline upload profile'), "the message for {$why} names the inline profile");
	}

	check(filesUnder($root) === [], 'invalid profiles write nothing');
});

test('an invalid directory in an inline profile is a configuration error with the path error as previous', static function () use ($dir, $text, $base): void {
	$root = $dir . '/directories';
	\mkdir($root);
	$upload = new Upload(testApp(testCfg(['upload' => ['storages' => ['docs' => ['root' => $root, 'web_path' => null]]]])));

	foreach (['../escape', '/abs', 'a//b', 'a/', '.hidden', 'a\\b', 'a b'] as $directory) {
		$e = expectThrows(UploadConfigException::class, static fn() => $upload->storeLocal($text, $base + ['directory' => $directory]), 'invalid directory ' . \json_encode($directory) . ' is a config error');
		check($e->getPrevious() instanceof \InvalidArgumentException && \str_starts_with($e->getMessage(), "Inline upload profile: invalid 'directory'"), 'the directory error names the profile and keeps the path error as previous');
	}

	check(filesUnder($root) === [], 'invalid directories write nothing');
});


// -- 3. Named profiles ------------------------------------------------------------------

test('an undefined profile name is rejected with a message naming it', static function () use ($text, $namedService): void {
	$upload = $namedService();

	foreach (['nope', '', 'disabled'] as $name) {
		$e = expectThrows(UploadConfigException::class, static fn() => $upload->storeLocal($text, $name), "profile '{$name}' is undefined");
		check(\str_contains($e->getMessage(), "Upload profile '{$name}' is not defined"), "the message names the undefined profile '{$name}'");
	}
});

test('a named profile that is not a profile array is rejected', static function () use ($text, $namedService): void {
	$upload = $namedService();

	$e = expectThrows(UploadConfigException::class, static fn() => $upload->storeLocal($text, 'scalar'), 'a scalar named profile is rejected');
	check(\str_contains($e->getMessage(), "Upload profile 'scalar' must be an array"), 'the message names the scalar profile');

	$e = expectThrows(UploadConfigException::class, static fn() => $upload->storeLocal($text, 'list'), 'a list as named profile is rejected');
	check(\str_contains($e->getMessage(), "Upload profile 'list': unknown key(s)"), 'a list profile fails on its keys');
});

test('an invalid variant format in a named profile names the profile and the output', static function () use ($text, $formats, $namedService): void {
	$upload = $namedService();

	foreach ($formats as $name => $format) {
		$e = expectThrows(UploadConfigException::class, static fn() => $upload->storeLocal($text, $name), "format of profile {$name} is rejected");
		check(
			\str_contains($e->getMessage(), "Upload profile '{$name}'") && \str_contains($e->getMessage(), 'image.variants.thumb.format'),
			"the format error names profile {$name} and the output"
		);
	}
});

test('an array as format explains that the kernel likely flattens enum cases', static function () use ($text, $namedService): void {
	$upload = $namedService();
	$e = expectThrows(UploadConfigException::class, static fn() => $upload->storeLocal($text, 'fmt_flattened'), 'flattened enum format');
	check(\str_contains($e->getMessage(), 'got array') && \str_contains($e->getMessage(), 'flattens enum cases'), 'the flattened-enum message explains the likely cause');
});

test('an unknown main format lists the known formats', static function () use ($text, $namedService): void {
	$upload = $namedService();
	$e = expectThrows(UploadConfigException::class, static fn() => $upload->storeLocal($text, 'main_format'), 'unknown main format');
	check(\str_contains($e->getMessage(), "Upload profile 'main_format': 'image.main.format' \"png8\"") && \str_contains($e->getMessage(), 'known: jpeg, png, gif, webp, avif, bmp, tiff, heic'), 'the message lists the known formats');
});

test('an invalid directory in a named profile is a configuration error naming the profile', static function () use ($text, $namedService): void {
	$upload = $namedService();
	$e = expectThrows(UploadConfigException::class, static fn() => $upload->storeLocal($text, 'bad_dir'), 'invalid directory in a named profile');
	check(
		$e->getPrevious() instanceof \InvalidArgumentException && \str_starts_with($e->getMessage(), "Upload profile 'bad_dir': invalid 'directory'"),
		'an invalid directory in a named profile is a config error naming the profile, with the path error as previous'
	);
});


// -- 4. upload.file_mode and upload.dir_mode --------------------------------------------

test('an invalid upload.file_mode is rejected with a message naming it', static function () use ($text, $base, $service): void {
	foreach (['0644', 01000, -1, 420.0, true] as $mode) {
		$upload = $service(['upload' => ['file_mode' => $mode]]);
		$e = expectThrows(UploadConfigException::class, static fn() => $upload->storeLocal($text, $base), 'file_mode ' . \var_export($mode, true) . ' is rejected');
		check(\str_starts_with($e->getMessage(), 'upload.file_mode'), 'the message names upload.file_mode');
	}
});

test('an invalid upload.dir_mode is rejected with a message naming it', static function () use ($text, $base, $service): void {
	foreach ([null, '0755', 01000, -1, 493.0] as $mode) {
		$upload = $service(['upload' => ['dir_mode' => $mode]]);
		$e = expectThrows(UploadConfigException::class, static fn() => $upload->storeLocal($text, $base), 'dir_mode ' . \var_export($mode, true) . ' is rejected');
		check(\str_starts_with($e->getMessage(), 'upload.dir_mode'), 'the message names upload.dir_mode');
	}
});

test('the modes are checked before content validation', static function () use ($empty, $base, $service): void {
	$upload = $service(['upload' => ['file_mode' => '0644']]);
	expectThrows(UploadConfigException::class, static fn() => $upload->storeLocal($empty, $base), 'modes are checked before content validation (an empty file still reports the mode)');
});

test('valid file_mode and dir_mode values are accepted', static function () use ($text, $base, $service): void {
	foreach ([null, 0, 0600, 0777] as $mode) {
		check($service(['upload' => ['file_mode' => $mode]])->storeLocal($text, $base)['size'] === 15, 'file_mode ' . \var_export($mode, true) . ' is accepted');
	}

	foreach ([0, 0777] as $mode) {
		check($service(['upload' => ['dir_mode' => $mode]])->storeLocal($text, $base)['size'] === 15, 'dir_mode ' . \var_export($mode, true) . ' is accepted');
	}
});


// -- 5. Valid profiles ------------------------------------------------------------------

test('valid profiles are accepted and never resolve the image service on the plain path', static function () use ($text, $base, $variant, $storages): void {
	$valid = [
		'a string format' => $base + ['image' => ['main' => ['format' => 'webp', 'width' => 1600, 'fit' => 'contain'], 'variants' => ['thumb' => ['format' => 'jpeg']]]],
		'ImageFormat cases' => $base + ['image' => ['main' => ['format' => ImageFormat::Webp], 'variants' => ['thumb' => ['format' => ImageFormat::Avif]]]],
		'a passthrough main' => $base + ['image' => ['main' => null, 'variants' => ['preview' => ['format' => 'webp', 'width' => 1200]]]],
		'no variants key' => $base + ['image' => ['main' => ['format' => 'png']]],
		'empty variants' => $base + ['image' => ['main' => null, 'variants' => []]],
		'options left to citomni/image' => $base + ['image' => ['main' => null, 'options' => ['auto_orient' => 'not-checked-here', 'anything' => ['nested' => true]]]],
		'spec contents left to citomni/image' => $variant(['format' => 'webp', 'fit' => 'not-a-fit', 'width' => -5, 'unknown' => 1]),
		'digit and underscore variant keys' => $base + ['image' => ['main' => null, 'variants' => ['w_300' => ['format' => 'webp'], '2x' => ['format' => 'webp']]]],
		'a directory' => $base + ['directory' => 'docs/2026'],
		'shard 2' => $base + ['shard' => 2],
		'max_bytes null' => ['max_bytes' => null] + $base,
		"accept ['*']" => ['accept' => ['*']] + $base,
		'an uppercase hash algorithm' => $base + ['hash' => 'SHA256'],
		'an alias in accept' => ['accept' => ['text/plain', 'image/x-ms-bmp']] + $base,
		'explicit null image and hash' => $base + ['image' => null, 'hash' => null],
	];

	$app = testApp(testCfg($storages));
	$upload = new Upload($app);

	foreach ($valid as $why => $profile) {
		$result = $upload->storeLocal($text, $profile);
		check($result['mime'] === 'text/plain' && $result['variants'] === [], "an inline profile with {$why} is accepted (text takes the plain path)");
	}

	check(!\in_array('image', $app->touched, true), 'valid image profiles never resolve the image service on the plain path');
});

test('an uppercase hash algorithm is normalized to lowercase', static function () use ($text, $base, $service): void {
	check($service()->storeLocal($text, $base + ['hash' => 'SHA256'])['hash'] === 'sha256:' . \hash_file('sha256', $text), 'the hash algorithm is normalized to lowercase');
});


// -- 6. Memoization ---------------------------------------------------------------------

test('a named profile is memoized per name, and a fresh service reads the swapped cfg', static function () use ($text, $base, $storages): void {
	$app = testApp(testCfg($storages, ['upload' => ['profiles' => ['memo' => $base]]]));
	$upload = new Upload($app);
	check($upload->storeLocal($text, 'memo')['mime'] === 'text/plain', 'the named profile accepts text');

	$app->cfg = testCfg($storages, ['upload' => ['profiles' => ['memo' => ['accept' => ['application/pdf']] + $base]]]);
	check($upload->storeLocal($text, 'memo')['mime'] === 'text/plain', 'a named profile is memoized per name: the swapped cfg is not read again');

	$e = expectThrows(UploadRejectedException::class, static fn() => (new Upload($app))->storeLocal($text, 'memo'), 'a fresh service reads the swapped cfg');
	check($e->reason === UploadRejection::TypeNotAllowed, 'the fresh service applies the swapped profile');
});

test('a named profile that fails validation is not memoized', static function () use ($text, $base, $storages): void {
	$app = testApp(testCfg($storages, ['upload' => ['profiles' => ['later' => ['accept' => []] + $base]]]));
	$upload = new Upload($app);
	expectThrows(UploadConfigException::class, static fn() => $upload->storeLocal($text, 'later'), 'the invalid named profile is rejected');
	$app->cfg = testCfg($storages, ['upload' => ['profiles' => ['later' => $base]]]);
	check($upload->storeLocal($text, 'later')['mime'] === 'text/plain', 'a failed normalization is not memoized');
});

test('inline profiles are normalized on every call', static function () use ($text, $base, $service): void {
	$upload = $service();
	check($upload->storeLocal($text, $base)['mime'] === 'text/plain', 'the inline profile accepts text');
	$e = expectThrows(UploadRejectedException::class, static fn() => $upload->storeLocal($text, ['accept' => ['application/pdf']] + $base), 'a different inline profile on the same service');
	check($e->reason === UploadRejection::TypeNotAllowed, 'inline profiles are normalized per call');
});


// -- 7. Canonicalization and merge semantics --------------------------------------------

test('accept entries are canonicalized: IMAGE/X-MS-BMP accepts a BMP stored as image/bmp', static function () use ($bmp, $base, $service): void {
	$result = $service()->storeLocal($bmp, ['accept' => ['IMAGE/X-MS-BMP']] + $base);
	check($result['mime'] === 'image/bmp' && \str_ends_with($result['path'], '.bmp'), 'accept entries are canonicalized: IMAGE/X-MS-BMP accepts a BMP stored as image/bmp');
});

/** A package profile and an app layer that narrows its accept list. */
$package = ['upload' => ['profiles' => ['pkg_doc' => [
	'storage' => 'docs',
	'directory' => 'pkg',
	'accept' => ['application/pdf', 'text/plain'],
	'max_bytes' => 5000,
	'shard' => 1,
]]]];
$appLayer = ['upload' => ['profiles' => ['pkg_doc' => ['accept' => ['application/pdf']]]]];

test('layered configuration replaces the accept list and deep-merges the other profile keys', static function () use ($package, $appLayer): void {
	$withPackage = Arr::mergeAssocLastWins(Registry::CFG_COMMON, $package);
	check(\array_keys($withPackage['upload']['profiles']) === ['pkg_doc'], "the baseline 'profiles' => [] is replaced by the first associative overlay");

	$merged = Arr::mergeAssocLastWins($withPackage, $appLayer)['upload']['profiles']['pkg_doc'];
	check($merged['accept'] === ['application/pdf'], 'an app layer replaces the accept list instead of extending it');
	check($merged['storage'] === 'docs' && $merged['directory'] === 'pkg' && $merged['max_bytes'] === 5000 && $merged['shard'] === 1, 'the other profile keys are deep-merged');
});

test('a merged package profile applies the narrowed accept list and keeps the package directory and sharding', static function () use ($text, $pdf, $package, $appLayer, $service): void {
	$upload = $service($package, $appLayer);
	$e = expectThrows(UploadRejectedException::class, static fn() => $upload->storeLocal($text, 'pkg_doc'), 'text is rejected once the app layer narrowed accept');
	check($e->reason === UploadRejection::TypeNotAllowed, 'the narrowed list applies');

	$result = $upload->storeLocal($pdf, 'pkg_doc');
	check(\preg_match('~^pkg/([0-9a-f]{2})/\1[0-9a-f]{30}\.pdf$~D', $result['path']) === 1, 'the merged profile keeps the package directory and sharding');
});


// -- 8. Image blocks: the image service and an options array ----------------------------

test('an image block without the image service is a configuration error naming the profile, whatever the file', static function () use ($text, $base, $storages): void {
	$imageProfile = $base + ['image' => ['main' => null]];
	$withoutImage = static fn(array ...$layers): Upload => new Upload(testApp(testCfg($storages, ...$layers), ['image' => null]));

	$e = expectThrows(UploadConfigException::class, static fn() => $withoutImage()->storeLocal($text, $imageProfile), 'an image block without the image service is a config error, whatever the file');
	check(\str_starts_with($e->getMessage(), 'Inline upload profile:') && \str_contains($e->getMessage(), 'citomni/image') && \str_contains($e->getMessage(), 'providers.php'), 'the message names the profile and the missing citomni/image provider');

	$e = expectThrows(UploadConfigException::class, static fn() => $withoutImage(['upload' => ['profiles' => ['pics' => $imageProfile]]])->storeLocal($text, 'pics'), 'a named image profile without the image service');
	check(\str_starts_with($e->getMessage(), "Upload profile 'pics':"), 'the message names the named profile');
	check($withoutImage()->storeLocal($text, $base)['mime'] === 'text/plain', 'a profile without image block needs no image service');
});

test('image options must be an array', static function () use ($text, $base, $service): void {
	foreach (['null' => null, 'a string' => 'auto_orient', 'an int' => 1, 'an object' => new \stdClass()] as $why => $options) {
		$e = expectThrows(UploadConfigException::class, static fn() => $service()->storeLocal($text, $base + ['image' => ['main' => null, 'options' => $options]]), "image options as {$why} are rejected");
		check(\str_contains($e->getMessage(), "'image.options' must be an array"), "the message for options as {$why} names image.options");
	}

	check($service()->storeLocal($text, $base + ['image' => ['main' => null, 'options' => []]])['mime'] === 'text/plain', 'empty image options are valid');
});

done();
