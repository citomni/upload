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

namespace CitOmni\Upload\Tests\Intake;

use CitOmni\Upload\Enum\UploadRejection;
use CitOmni\Upload\Exception\UploadConfigException;
use CitOmni\Upload\Exception\UploadRejectedException;
use CitOmni\Upload\Exception\UploadStorageException;
use CitOmni\Upload\Service\Upload;
use CitOmni\Upload\Tests\Support\Fixtures;
use CitOmni\Upload\Tests\Support\RecordingTxt;
use CitOmni\Upload\Util\UploadedFiles;

/*
 * Isolated suite for the intake contract without HTTP: UploadedFiles
 * normalization of the $_FILES shapes PHP produces (single, name[], keyed,
 * deeper nesting selected by $path, full_path, empty slots) and of malformed or
 * shape-confused entries; storeUpload() up to and including its provenance
 * check (developer and configuration errors first, every UPLOAD_ERR_* code, no
 * fallback for files that were not uploaded); checkUpload() in the same scope,
 * without the storage preconditions it never needs; storeUploads() in the same
 * scope (the same checks first, also for an empty list, collected rejections,
 * faults that propagate, and #[\NoDiscard]); rejectionMessage() in both forms,
 * with and without the txt service; and the shipped language files.
 *
 * is_uploaded_file() is false outside an HTTP upload, so everything past the
 * provenance check is covered by tests/http-upload/.
 *
 * Usage:
 *   php tests/intake/run.php
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

// $_FILES shapes as PHP builds them.
$single = ['name' => 'a.txt', 'full_path' => 'a.txt', 'type' => 'text/plain', 'tmp_name' => '/tmp/phpA', 'error' => \UPLOAD_ERR_OK, 'size' => 6];
$entryA = ['name' => 'a.txt', 'type' => 'text/plain', 'tmp_name' => '/tmp/phpA', 'error' => \UPLOAD_ERR_OK, 'size' => 6];

$multi = [
	'name' => ['b.pdf', '', 'c.txt'],
	'full_path' => ['b.pdf', '', 'c.txt'],
	'type' => ['application/pdf', '', 'text/plain'],
	'tmp_name' => ['/tmp/phpB', '', ''],
	'error' => [\UPLOAD_ERR_OK, \UPLOAD_ERR_NO_FILE, \UPLOAD_ERR_PARTIAL],
	'size' => [15, 0, 0],
];
$entryB = ['name' => 'b.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/phpB', 'error' => \UPLOAD_ERR_OK, 'size' => 15];
$entryC = ['name' => 'c.txt', 'type' => 'text/plain', 'tmp_name' => '', 'error' => \UPLOAD_ERR_PARTIAL, 'size' => 0];

$keyed = [
	'name' => ['front' => 'f.jpg', 'back' => 'b.jpg'],
	'type' => ['front' => 'image/jpeg', 'back' => 'image/jpeg'],
	'tmp_name' => ['front' => '/tmp/phpF', 'back' => '/tmp/phpK'],
	'error' => ['front' => 0, 'back' => 0],
	'size' => ['front' => 1, 'back' => 2],
];

$deep = [
	'name' => ['a' => ['b' => 'c.txt']],
	'full_path' => ['a' => ['b' => 'c.txt']],
	'type' => ['a' => ['b' => 'text/plain']],
	'tmp_name' => ['a' => ['b' => '/tmp/phpC']],
	'error' => ['a' => ['b' => 0]],
	'size' => ['a' => ['b' => 5]],
];
$entryDeep = ['name' => 'c.txt', 'type' => 'text/plain', 'tmp_name' => '/tmp/phpC', 'error' => 0, 'size' => 5];

$gallery = [
	'name' => ['photos' => ['p1.jpg', 'p2.jpg']],
	'type' => ['photos' => ['image/jpeg', 'image/jpeg']],
	'tmp_name' => ['photos' => ['/tmp/php1', '/tmp/php2']],
	'error' => ['photos' => [0, 0]],
	'size' => ['photos' => [10, 20]],
];

$dir = tempDir();

/**
 * Create a fresh storage root and an Upload service with the storages "files" (that root) and "gone" (a missing root).
 *
 * @return array{0: Upload, 1: string, 2: \CitOmni\Upload\Tests\Support\TestApp}
 */
$setup = static function (array $upload = [], array $services = []) use ($dir): array {
	$root = $dir . '/root-' . \bin2hex(\random_bytes(4));
	\mkdir($root);
	$app = testApp(testCfg(['upload' => ['storages' => [
		'files' => ['root' => $root, 'web_path' => null],
		'gone' => ['root' => $dir . '/gone', 'web_path' => null],
	]] + $upload]), $services);

	return [new Upload($app), $root, $app];
};

$profile = ['storage' => 'files', 'accept' => ['application/pdf'], 'max_bytes' => 1000];

/** An entry as PHP reports it; tmp_name is "" for every upload error. */
$entry = static fn(int $error, string $tmpName = '', string $name = 'report.pdf'): array
	=> ['name' => $name, 'type' => 'application/pdf', 'tmp_name' => $tmpName, 'error' => $error, 'size' => 0];

$rejections = [
	\UPLOAD_ERR_INI_SIZE => [UploadRejection::TooLarge, ['original_name' => 'report.pdf', 'upload_error' => \UPLOAD_ERR_INI_SIZE]],
	\UPLOAD_ERR_FORM_SIZE => [UploadRejection::TooLarge, ['original_name' => 'report.pdf', 'upload_error' => \UPLOAD_ERR_FORM_SIZE]],
	\UPLOAD_ERR_PARTIAL => [UploadRejection::Partial, ['original_name' => 'report.pdf', 'upload_error' => \UPLOAD_ERR_PARTIAL]],
	\UPLOAD_ERR_NO_FILE => [UploadRejection::NoFile, ['upload_error' => \UPLOAD_ERR_NO_FILE]],
];

$faults = [
	\UPLOAD_ERR_NO_TMP_DIR => 'UPLOAD_ERR_NO_TMP_DIR',
	\UPLOAD_ERR_CANT_WRITE => 'UPLOAD_ERR_CANT_WRITE',
	\UPLOAD_ERR_EXTENSION => 'UPLOAD_ERR_EXTENSION',
	5 => 'unknown code',
	99 => 'unknown code',
	-1 => 'unknown code',
];

$badFiles = [
	'no keys' => [],
	'no tmp_name' => \array_diff_key($entry(\UPLOAD_ERR_OK), ['tmp_name' => true]),
	'no name' => \array_diff_key($entry(\UPLOAD_ERR_OK), ['name' => true]),
	'a string error' => ['error' => '4'] + $entry(\UPLOAD_ERR_NO_FILE),
	'a null error' => ['error' => null] + $entry(\UPLOAD_ERR_NO_FILE),
];

// A raw single-file field that the client sent as name[].
$manipulated = ['name' => ['a.pdf'], 'full_path' => ['a.pdf'], 'type' => ['application/pdf'], 'tmp_name' => ['/tmp/phpX'], 'error' => [0], 'size' => [1]];

// A local file: it exists, but it was not uploaded in the current request.
$local = $dir . '/local.pdf';
\file_put_contents($local, Fixtures::pdf());


// -- 1. UploadedFiles::one() ------------------------------------------------------------

test('UploadedFiles::one() keeps the five PHP keys in order, without full_path and extra keys', static function () use ($single, $entryA): void {
	check(UploadedFiles::one($single) === $entryA, 'one() keeps the five PHP keys in order and drops full_path');
	check(UploadedFiles::one(\array_diff_key($single, ['full_path' => true])) === $entryA, 'one() does not need full_path');
	check(UploadedFiles::one($single + ['extra' => ['x']]) === $entryA, 'one() ignores extra keys');
});

test('UploadedFiles::one() returns null when no file was sent and keeps every other error code', static function () use ($single): void {
	check(UploadedFiles::one(['name' => '', 'type' => '', 'tmp_name' => '', 'error' => \UPLOAD_ERR_NO_FILE, 'size' => 0]) === null, 'one() returns null when no file was sent');

	foreach ([\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE, \UPLOAD_ERR_PARTIAL, \UPLOAD_ERR_NO_TMP_DIR, \UPLOAD_ERR_CANT_WRITE, \UPLOAD_ERR_EXTENSION, 99] as $error) {
		check(
			UploadedFiles::one(['tmp_name' => '', 'error' => $error, 'size' => 0] + $single) === ['name' => 'a.txt', 'type' => 'text/plain', 'tmp_name' => '', 'error' => $error, 'size' => 0],
			"one() keeps upload error {$error} for storeUpload() to map"
		);
	}
});

test('UploadedFiles::one() returns null for a malformed or shape-confused entry', static function () use ($single): void {
	$malformed = [
		'null' => null,
		'a string' => 'avatar',
		'an empty array' => [],
		'a missing name' => \array_diff_key($single, ['name' => true]),
		'a missing type' => \array_diff_key($single, ['type' => true]),
		'a missing tmp_name' => \array_diff_key($single, ['tmp_name' => true]),
		'a missing error' => \array_diff_key($single, ['error' => true]),
		'a missing size' => \array_diff_key($single, ['size' => true]),
		'a field sent as name[] (arrays instead of values)' => ['name' => ['a.txt'], 'type' => ['text/plain'], 'tmp_name' => ['/tmp/phpA'], 'error' => [0], 'size' => [6]],
		'an array name only' => ['name' => ['a.txt']] + $single,
		'a null tmp_name' => ['tmp_name' => null] + $single,
		'a string error' => ['error' => '0'] + $single,
		'a float size' => ['size' => 6.0] + $single,
		'an int type' => ['type' => 1] + $single,
	];

	foreach ($malformed as $why => $entry) {
		check(UploadedFiles::one($entry) === null, "one() returns null for {$why}");
	}
});


// -- 2. UploadedFiles::many() -----------------------------------------------------------

test('UploadedFiles::many() lists the slots of name[] and of a keyed field in client order, without keys', static function () use ($multi, $entryB, $entryC, $keyed): void {
	check(UploadedFiles::many($multi) === [$entryB, $entryC], 'many() lists name[] slots, skips UPLOAD_ERR_NO_FILE, and keeps other errors');

	$listed = UploadedFiles::many($keyed);
	check(\array_is_list($listed) && \array_column($listed, 'name') === ['f.jpg', 'b.jpg'], 'many() lists a keyed field in client order and drops the keys');
});

test('UploadedFiles::many() skips a malformed slot', static function () use ($multi, $entryB, $entryC): void {
	$nested = $multi;
	$nested['name'][0] = ['b.pdf'];
	check(UploadedFiles::many($nested) === [$entryC], 'many() skips a slot nested one level deeper');

	$gap = $multi;
	unset($gap['error'][2]);
	check(UploadedFiles::many($gap) === [$entryB], 'many() skips a slot missing from one of the fields');

	$typed = $multi;
	$typed['size'][0] = '15';
	check(UploadedFiles::many($typed) === [$entryC], 'many() skips a slot with a value of the wrong type');
});

test('UploadedFiles::many() returns [] for anything that is not a list of file slots', static function () use ($multi, $single): void {
	$mixed = $multi;
	$mixed['size'] = 15;
	check(UploadedFiles::many($mixed) === [], 'many() returns [] when one of the fields is not an array');
	check(UploadedFiles::many($single) === [], 'many() returns [] for a single-file field');

	foreach (['null' => null, 'a string' => 'files', 'an empty array' => [], 'no slots' => ['name' => []] + $multi] as $why => $empty) {
		check(UploadedFiles::many($empty) === [], "many() returns [] for {$why}");
	}
});


// -- 3. $path selection -----------------------------------------------------------------

test('one() and many() select a nested field only by its full $path', static function () use ($deep, $entryDeep): void {
	check(UploadedFiles::one($deep, 'a', 'b') === $entryDeep, 'one(): the full path selects a nested field ("doc[a][b]")');
	check(UploadedFiles::one($deep) === null && UploadedFiles::one($deep, 'a') === null, 'one(): without the full path a nested field is malformed');
	check(UploadedFiles::one($deep, 'x', 'b') === null && UploadedFiles::one($deep, 'a', 'b', 'c') === null, 'one(): an unknown or too deep path gives null');
	check(UploadedFiles::many($deep) === [] && UploadedFiles::many($deep, 'a') === [$entryDeep], 'many(): the path selects the level whose slots are files');
});

test('a $path selects a nested list, a keyed slot, or a list slot', static function () use ($gallery, $keyed, $multi, $entryB): void {
	check(\array_column(UploadedFiles::many($gallery, 'photos'), 'name') === ['p1.jpg', 'p2.jpg'], 'many(): "gallery[photos][]" is selected with the path photos');
	check(UploadedFiles::one($keyed, 'back') === ['name' => 'b.jpg', 'type' => 'image/jpeg', 'tmp_name' => '/tmp/phpK', 'error' => 0, 'size' => 2], 'one(): a keyed slot is selected by its key');
	check(UploadedFiles::one($multi, 0) === $entryB && UploadedFiles::one($multi, '0') === $entryB, 'one(): a list slot is selected by int or numeric string');
	check(UploadedFiles::one($multi, 1) === null && UploadedFiles::one($multi, 7) === null, 'one(): an empty or missing slot gives null');
});


// -- 4. storeUpload() up to provenance --------------------------------------------------

test('storeUpload() rejects client-side upload errors with upload_error in the context', static function () use ($setup, $profile, $entry, $rejections): void {
	[$upload] = $setup();

	foreach ($rejections as $error => [$reason, $context]) {
		$e = expectThrows(UploadRejectedException::class, static fn() => $upload->storeUpload($entry($error), $profile), "upload error {$error} is a rejection");
		check($e->reason === $reason && $e->context === $context, "upload error {$error} maps to {$reason->name}, with upload_error in the context");
	}
});

test('storeUpload() reports server-side and unknown upload errors as storage faults naming the code', static function () use ($setup, $profile, $entry, $faults): void {
	[$upload] = $setup();

	foreach ($faults as $error => $label) {
		$e = expectThrows(UploadStorageException::class, static fn() => $upload->storeUpload($entry($error), $profile), "upload error {$error} is a server fault");
		check(\str_contains($e->getMessage(), "upload error {$error} ({$label})"), "the message names upload error {$error} as {$label}");
	}
});

test('the original name in a rejection context is sanitized', static function () use ($setup, $profile, $entry): void {
	[$upload] = $setup();
	$e = expectThrows(UploadRejectedException::class, static fn() => $upload->storeUpload($entry(\UPLOAD_ERR_PARTIAL, '', "C:\\fakepath\\inv\u{202E}x.pdf"), $profile), 'a rejection for a client name with a bidi control');
	check($e->context['original_name'] === 'invx.pdf', 'the original name in the context is sanitized');
});

test('storeUpload() reports developer and configuration errors before the upload error', static function () use ($setup, $profile, $entry): void {
	[$upload] = $setup();
	expectThrows(UploadConfigException::class, static fn() => $upload->storeUpload($entry(\UPLOAD_ERR_NO_FILE), ['storage' => 'nowhere'] + $profile), 'an invalid profile is reported before the upload error');
	expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeUpload($entry(\UPLOAD_ERR_NO_FILE), $profile, '../x'), 'an invalid $subdir is reported before the upload error');
	expectThrows(UploadConfigException::class, static fn() => $upload->storeUpload($entry(\UPLOAD_ERR_NO_FILE), ['storage' => 'gone'] + $profile), 'a missing storage root is reported before the upload error');

	[$badModes] = $setup(['file_mode' => '0644']);
	expectThrows(UploadConfigException::class, static fn() => $badModes->storeUpload($entry(\UPLOAD_ERR_NO_FILE), $profile), 'an invalid file_mode is reported before the upload error');
});

test('storeUpload() rejects a malformed $file as a developer error', static function () use ($setup, $profile, $badFiles): void {
	[$upload] = $setup();

	foreach ($badFiles as $why => $file) {
		expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeUpload($file, $profile), "a \$file with {$why} is a developer error");
	}
});

test('storeUpload() accepts a raw single-file $_FILES entry and refuses one the client sent as name[]', static function () use ($setup, $profile, $manipulated): void {
	[$upload] = $setup();

	$raw = ['name' => '', 'full_path' => '', 'type' => '', 'tmp_name' => '', 'error' => \UPLOAD_ERR_NO_FILE, 'size' => 0];
	$e = expectThrows(UploadRejectedException::class, static fn() => $upload->storeUpload($raw, $profile), 'a raw single-file $_FILES entry is accepted');
	check($e->reason === UploadRejection::NoFile, 'the raw entry reaches the error mapping');

	$e = expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeUpload($manipulated, $profile), 'a raw entry that the client sent as name[] is a developer error');
	check(\str_contains($e->getMessage(), 'UploadedFiles::one()') && UploadedFiles::one($manipulated) === null, 'the message points to UploadedFiles::one(), which returns null for the same entry');
});

test('storeUpload() refuses a file that was not uploaded in the request, without fallback, and writes nothing', static function () use ($setup, $profile, $entry, $local): void {
	[$upload, $root, $app] = $setup();

	$e = expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeUpload($entry(\UPLOAD_ERR_OK, $local, 'local.pdf'), $profile), 'a local file is not an upload: there is no fallback to copy()');
	check(\str_contains($e->getMessage(), 'storeLocal()') && \file_get_contents($local) === Fixtures::pdf(), 'the message points to storeLocal(), and the file is untouched');
	expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeUpload($entry(\UPLOAD_ERR_OK), $profile), 'UPLOAD_ERR_OK with an empty tmp_name is a developer error');

	check(\scandir($root) === ['.', '..'], 'storeUpload() wrote nothing outside an HTTP upload');
	check(!\in_array('image', $app->touched, true) && !\in_array('txt', $app->touched, true), 'storeUpload() resolves neither image nor txt');
});


// -- 5. checkUpload(): the intake of storeUpload(), without storage ---------------------

test('checkUpload() reports profile errors before the shape of $file and the upload error', static function () use ($setup, $profile, $entry): void {
	[$upload] = $setup();
	expectThrows(UploadConfigException::class, static fn() => $upload->checkUpload($entry(\UPLOAD_ERR_NO_FILE), ['storage' => 'nowhere'] + $profile), 'checkUpload(): an invalid profile is reported before the upload error');
	expectThrows(UploadConfigException::class, static fn() => $upload->checkUpload([], 'undefined'), 'checkUpload(): an undefined profile is reported before the shape of $file');
});

test('checkUpload() rejects a malformed $file as a developer error naming checkUpload()', static function () use ($setup, $profile, $badFiles, $manipulated): void {
	[$upload] = $setup();

	foreach ($badFiles as $why => $file) {
		$e = expectThrows(\InvalidArgumentException::class, static fn() => $upload->checkUpload($file, $profile), "checkUpload(): a \$file with {$why} is a developer error");
		check(\str_contains($e->getMessage(), 'checkUpload() expects one file entry'), "checkUpload(): the message for a \$file with {$why} names checkUpload()");
	}

	$e = expectThrows(\InvalidArgumentException::class, static fn() => $upload->checkUpload($manipulated, $profile), 'checkUpload(): a raw entry that the client sent as name[] is a developer error');
	check(\str_contains($e->getMessage(), 'UploadedFiles::one()'), 'checkUpload(): the message points to UploadedFiles::one()');
});

test('checkUpload() maps every upload error code as storeUpload() does', static function () use ($setup, $profile, $entry, $rejections, $faults): void {
	[$upload] = $setup();

	foreach ($rejections as $error => [$reason, $context]) {
		$e = expectThrows(UploadRejectedException::class, static fn() => $upload->checkUpload($entry($error), $profile), "checkUpload(): upload error {$error} is a rejection");
		check($e->reason === $reason && $e->context === $context, "checkUpload(): upload error {$error} maps to {$reason->name}, as in storeUpload()");
	}

	foreach ($faults as $error => $label) {
		$e = expectThrows(UploadStorageException::class, static fn() => $upload->checkUpload($entry($error), $profile), "checkUpload(): upload error {$error} is a server fault");
		check(\str_contains($e->getMessage(), "upload error {$error} ({$label})"), "checkUpload(): the message names upload error {$error} as {$label}");
	}
});

test('checkUpload() checks neither the storage root nor the modes, because it places nothing in storage', static function () use ($setup, $profile, $entry): void {
	[$upload] = $setup();
	$e = expectThrows(UploadRejectedException::class, static fn() => $upload->checkUpload($entry(\UPLOAD_ERR_PARTIAL), ['storage' => 'gone'] + $profile), 'checkUpload() with an unprovisioned storage root');
	check($e->reason === UploadRejection::Partial, 'checkUpload() checks no storage root, because it places nothing in storage');

	[$badModes] = $setup(['file_mode' => '0644']);
	$e = expectThrows(UploadRejectedException::class, static fn() => $badModes->checkUpload($entry(\UPLOAD_ERR_PARTIAL), $profile), 'checkUpload() with an invalid file_mode');
	check($e->reason === UploadRejection::Partial, 'checkUpload() checks no modes, because it places nothing in storage');
});

test('checkUpload() refuses a file that was not uploaded in the request, without fallback, and writes nothing', static function () use ($setup, $profile, $entry, $local): void {
	[$upload, $root, $app] = $setup();

	$e = expectThrows(\InvalidArgumentException::class, static fn() => $upload->checkUpload($entry(\UPLOAD_ERR_OK, $local, 'local.pdf'), $profile), 'checkUpload(): a local file is not an upload');
	check(\str_contains($e->getMessage(), 'Not a file uploaded in the current request') && \file_get_contents($local) === Fixtures::pdf(), 'checkUpload(): there is no fallback for local files, and the file is untouched');

	check(\scandir($root) === ['.', '..'], 'checkUpload() wrote nothing');
	check(!\in_array('image', $app->touched, true) && !\in_array('txt', $app->touched, true), 'checkUpload() resolves neither image nor txt');
});


// -- 6. storeUploads(): the same checks first, rejections collected ---------------------

test('storeUploads() of an empty list stores nothing, but still reports developer and configuration errors', static function () use ($setup, $profile): void {
	[$upload] = $setup();
	check($upload->storeUploads([], $profile) === ['stored' => [], 'rejected' => []], 'storeUploads() of an empty list stores and rejects nothing');
	expectThrows(UploadConfigException::class, static fn() => $upload->storeUploads([], 'undefined'), 'an undefined profile is reported for an empty list too');
	expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeUploads([], $profile, '../x'), 'an invalid $subdir is reported for an empty list too');
	expectThrows(UploadConfigException::class, static fn() => $upload->storeUploads([], ['storage' => 'gone'] + $profile), 'a missing storage root is reported for an empty list too');

	[$badModes] = $setup(['file_mode' => '0644']);
	expectThrows(UploadConfigException::class, static fn() => $badModes->storeUploads([], $profile), 'an invalid file_mode is reported for an empty list too');
});

test('storeUploads() collects every rejection in order instead of stopping at the first', static function () use ($setup, $profile, $entry): void {
	[$upload] = $setup();
	$batch = $upload->storeUploads([$entry(\UPLOAD_ERR_PARTIAL, '', 'a.pdf'), $entry(\UPLOAD_ERR_INI_SIZE, '', 'b.pdf'), $entry(\UPLOAD_ERR_NO_FILE)], $profile);
	check(
		$batch['stored'] === [] && \array_map(static fn(UploadRejectedException $e): UploadRejection => $e->reason, $batch['rejected']) === [UploadRejection::Partial, UploadRejection::TooLarge, UploadRejection::NoFile],
		'storeUploads() collects every rejection, in order, instead of stopping at the first'
	);
	check($batch['rejected'][0]->context === ['original_name' => 'a.pdf', 'upload_error' => \UPLOAD_ERR_PARTIAL], 'a collected rejection is the exception storeUpload() throws, with its context');
});

test('a server fault in a batch is not a rejection: it propagates unchanged and ends the batch', static function () use ($setup, $profile, $entry): void {
	[$upload] = $setup();
	$e = expectThrows(UploadStorageException::class, static fn() => $upload->storeUploads([$entry(\UPLOAD_ERR_PARTIAL), $entry(\UPLOAD_ERR_CANT_WRITE), $entry(\UPLOAD_ERR_PARTIAL)], $profile), 'a server fault in a batch is not a rejection');
	check(\str_contains($e->getMessage(), 'UPLOAD_ERR_CANT_WRITE'), 'the server fault propagates unchanged and ends the batch');
});

test('storeUploads() checks every entry before the storage root, and a single entry instead of a list is a developer error', static function () use ($setup, $profile, $entry, $local): void {
	[$upload] = $setup();
	expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeUploads([$entry(\UPLOAD_ERR_PARTIAL), []], $profile), 'a malformed entry in a batch is a developer error');
	expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeUploads([$entry(\UPLOAD_ERR_PARTIAL), null], $profile), 'an entry that is not an array is the same developer error, not a TypeError');
	expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeUploads([[]], ['storage' => 'gone'] + $profile), 'every entry is checked before the storage root, in storeUpload()\'s order');
	$e = expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeUploads($entry(\UPLOAD_ERR_OK, $local, 'local.pdf'), $profile), 'a single entry instead of a list');
	check(\str_contains($e->getMessage(), 'UploadedFiles::many()') && \str_contains($e->getMessage(), 'storeUpload()'), 'the message points to UploadedFiles::many() and, for one entry, to storeUpload()');
});

test('storeUploads() refuses a file that was not uploaded in the request and writes nothing', static function () use ($setup, $profile, $entry, $local): void {
	[$upload, $root] = $setup();
	expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeUploads([$entry(\UPLOAD_ERR_OK, $local, 'local.pdf')], $profile), 'a local file in a batch is not an upload');
	check(\scandir($root) === ['.', '..'] && \file_get_contents($local) === Fixtures::pdf(), 'storeUploads() wrote nothing outside an HTTP upload, and the local file is untouched');
});

test('storeUploads() is #[\NoDiscard] and resolves neither image nor txt', static function () use ($setup, $profile, $entry): void {
	[$upload, , $app] = $setup();

	$e = expectThrows(\ErrorException::class, static function () use ($upload, $profile): void {
		$upload->storeUploads([], $profile);
	}, 'ignoring the result of storeUploads()');
	check(\str_contains($e->getMessage(), 'storeUploads()'), 'storeUploads() is #[\\NoDiscard]: ignoring its result raises the warning');

	$batch = $upload->storeUploads([$entry(\UPLOAD_ERR_PARTIAL)], $profile);
	check(\count($batch['rejected']) === 1, 'a batch with a rejection ran');
	check(!\in_array('image', $app->touched, true) && !\in_array('txt', $app->touched, true), 'storeUploads() resolves neither image nor txt');
});


// -- 7. rejectionMessage() --------------------------------------------------------------

test('rejectionMessage() gives the English fallback in both forms without the txt service', static function () use ($setup): void {
	[$upload, , $app] = $setup([], ['txt' => null]);

	foreach (UploadRejection::cases() as $reason) {
		check(
			$upload->rejectionMessage($reason) === $reason->fallbackMessage() && $upload->rejectionMessage(new UploadRejectedException($reason)) === $reason->fallbackMessage(),
			"without txt, both forms give the English fallback for {$reason->name}"
		);
	}

	check(!\in_array('txt', $app->touched, true), 'without a registered txt service, txt is never resolved');
});

test('rejectionMessage() translates both forms through txt with the citomni/upload layer and the fallback as default', static function () use ($setup): void {
	$txt = new RecordingTxt();
	$txt->texts = ['too_large' => 'Filen er for stor.'];
	[$upload] = $setup([], ['txt' => $txt]);

	check($upload->rejectionMessage(UploadRejection::TooLarge) === 'Filen er for stor.', 'the reason form is translated through txt');
	check($upload->rejectionMessage(new UploadRejectedException(UploadRejection::TooLarge, ['size' => 11, 'max_bytes' => 10])) === 'Filen er for stor.', 'the exception form is translated through txt');

	$expectedCall = ['key' => 'too_large', 'file' => 'upload', 'layer' => 'citomni/upload', 'default' => 'The file is too large.', 'vars' => []];
	check($txt->calls === [$expectedCall, $expectedCall], 'txt gets the reason value, the upload file, the citomni/upload layer, the fallback as default, and no vars');
	check($upload->rejectionMessage(UploadRejection::Empty) === 'The file is empty.', 'a key that txt cannot resolve gives the fallback');
});


// -- 8. Language files ------------------------------------------------------------------

$languages = \dirname(__DIR__, 2) . '/language';
$keys = \array_map(static fn(UploadRejection $reason): string => $reason->value, UploadRejection::cases());

test('language/en/upload.php has one key per UploadRejection value, in enum order, with the fallback texts', static function () use ($languages, $keys): void {
	$en = require $languages . '/en/upload.php';
	check(\is_array($en) && \array_keys($en) === $keys, 'language/en/upload.php has one key per UploadRejection value, in enum order');

	foreach (UploadRejection::cases() as $reason) {
		check($en[$reason->value] === $reason->fallbackMessage(), "the English text for {$reason->value} is the fallback message");
	}
});

test('language/da/upload.php has one key per UploadRejection value, in enum order, with the shipped texts', static function () use ($languages, $keys): void {
	$da = require $languages . '/da/upload.php';
	check(\is_array($da) && \array_keys($da) === $keys, 'language/da/upload.php has one key per UploadRejection value, in enum order');
	check($da === [
		'no_file' => 'Der blev ikke uploadet nogen fil.',
		'partial' => 'Filen blev kun delvist uploadet. Prøv igen.',
		'too_large' => 'Filen er for stor.',
		'empty' => 'Filen er tom.',
		'type_not_allowed' => 'Denne filtype er ikke tilladt.',
		'invalid_image' => 'Billedet kunne ikke behandles. Det kan være beskadiget, for stort, animeret eller i et format, der ikke understøttes.',
	], 'the Danish texts are exactly the shipped ones');
});

test('the language texts are non-empty and have no placeholders', static function () use ($languages): void {
	$en = require $languages . '/en/upload.php';
	$da = require $languages . '/da/upload.php';

	foreach ([...\array_values($en), ...\array_values($da)] as $text) {
		check(\is_string($text) && $text !== '' && !\str_contains($text, '%'), 'texts are non-empty and have no placeholders: ' . $text);
	}
});

done();
