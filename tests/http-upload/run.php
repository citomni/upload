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

namespace CitOmni\Upload\Tests\HttpUpload;

use CitOmni\Image\Boot\Registry as ImageRegistry;
use CitOmni\Image\Enum\ImageFormat;
use CitOmni\Image\Exception\ImageCapabilityException;
use CitOmni\Image\Service\Image;
use CitOmni\Upload\Exception\UploadRejectedException;
use CitOmni\Upload\Exception\UploadStorageException;
use CitOmni\Upload\Tests\Support\Fixtures;
use CitOmni\Upload\Tests\Support\HttpServer;

/*
 * Isolated suite for checkUpload(), storeUpload(), and storeUploads() with real
 * HTTP uploads, end to end.
 *
 * is_uploaded_file() and move_uploaded_file() only accept files uploaded in
 * the current request. The suite therefore starts PHP's built-in web server on
 * 127.0.0.1 and a free port, sends raw multipart/form-data requests, and lets
 * server.php call UploadedFiles, checkUpload(), storeUpload(), storeUploads(),
 * and cleanupStored() inside each request. The router reports results,
 * exceptions, and the state of the uploaded files as JSON; the cases inspect
 * storage directly.
 *
 * Usage:
 *   php tests/http-upload/run.php
 *
 * Notes:
 * - The server runs with the same PHP binary and php.ini as the suite, plus
 *   upload_max_filesize=1K, and is stopped in finally.
 * - A server that cannot be started fails every case that sends a request; it
 *   is not a reason to skip.
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
$server = null;
$serverFailure = '';

try {
	$server = HttpServer::start(__DIR__ . '/server.php', ['upload_max_filesize' => '1K'], $dir);
	$serverFailure = HttpServer::$lastFailure;
} catch (\RuntimeException $e) {
	$serverFailure = $e->getMessage();
}

/**
 * Send one multipart request: the JSON spec and $fields first, then the files.
 *
 * @param list<array{0: string, 1: string, 2: string}> $files [field name, file name, bytes].
 * @return array<string, mixed> The router's report.
 * @throws \RuntimeException When the server is not running or the router fails.
 */
$request = static function (array $spec, array $files, array $fields = [], int $cutTail = 0) use ($server, $serverFailure): array {
	if ($server === null) {
		throw new \RuntimeException('The built-in web server could not be started: ' . $serverFailure);
	}

	return $server->post(['spec' => \json_encode($spec, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)] + $fields, $files, $cutTail);
};

/** Create an empty storage root. */
$newRoot = static function () use ($dir): string {
	$root = $dir . '/root-' . \bin2hex(\random_bytes(4));
	\mkdir($root);

	return $root;
};

/** Create a local file outside storage: it exists, but it was not uploaded in the request. */
$localFile = static function (string $bytes) use ($dir): string {
	$path = $dir . '/local-' . \bin2hex(\random_bytes(4)) . '.pdf';
	\file_put_contents($path, $bytes);

	return $path;
};

/** Whether a value is the normalized entry of an accepted upload. */
$isEntry = static fn(mixed $entry, string $name, int $size): bool
	=> \is_array($entry) && \array_keys($entry) === ['name', 'type', 'tmp_name', 'error', 'size']
	&& $entry['name'] === $name && $entry['size'] === $size && $entry['error'] === \UPLOAD_ERR_OK && $entry['tmp_name'] !== '';

$pdf = Fixtures::pdf();
$photo = Fixtures::jpeg();
$pdfProfile = ['storage' => 'files', 'accept' => ['application/pdf'], 'max_bytes' => 1000];
$anyProfile = ['storage' => 'files', 'accept' => ['*'], 'max_bytes' => 100_000];
$anyCall = ['entry' => 'one', 'field' => 'f', 'profile' => $anyProfile];
$keep = ['storage' => 'files', 'accept' => ['image/jpeg'], 'max_bytes' => 1000, 'image' => ['main' => null, 'variants' => ['preview' => ['format' => 'webp', 'width' => 10]]]];
$encode = ['storage' => 'files', 'accept' => ['image/jpeg'], 'max_bytes' => 1000, 'image' => ['main' => ['format' => 'webp']]];
$resultKeys = ['storage', 'path', 'mime', 'source_mime', 'size', 'original_name', 'width', 'height', 'hash', 'variants'];
$pdfs = [$pdf . "%a\n", $pdf . "%b\n", $pdf . "%c\n"];

/** The report of one request that probes the $_FILES shapes PHP builds; sent once and shared by the shape cases. */
$shapes = static function () use ($request, $pdf): array {
	static $report = null;

	return $report ??= $request(['probes' => [
		['one', 'single', []],
		['many', 'single', []],
		['many', 'multi', []],
		['one', 'multi', []],
		['one', 'multi', [0]],
		['one', 'multi', [1]],
		['one', 'keyed', []],
		['one', 'keyed', ['front']],
		['many', 'keyed', []],
		['one', 'deep', ['a', 'b']],
		['one', 'deep', ['a']],
		['many', 'deep', []],
		['many', 'gallery', ['photos']],
		['one', 'missing', []],
	]], [
		['single', 'a.txt', "hello\n"],
		['multi[]', 'b.pdf', $pdf],
		['multi[]', '', ''],
		['keyed[front]', 'dir/sub/d.txt', "front\n"],
		['deep[a][b]', 'c.txt', "deep\n"],
		['gallery[photos][]', 'p1.txt', "one\n"],
		['gallery[photos][]', 'p2.txt', "two\n"],
	]);
};

/**
 * One batch with partial acceptance below the $subdir "batch"; sent once and shared by the batch cases.
 *
 * @return array{0: array<string, mixed>, 1: string} The router's report and the storage root.
 */
$partialBatch = static function () use ($request, $newRoot, $pdfProfile, $pdfs): array {
	static $sent = null;

	if ($sent === null) {
		$root = $newRoot();
		$sent = [$request(['root' => $root, 'batches' => [['field' => 'docs', 'profile' => $pdfProfile, 'subdir' => 'batch']]], [
			['docs[]', 'a.pdf', $pdfs[0]],
			['docs[]', 'evil.pdf', Fixtures::php()],
			['docs[]', '', ''],
			['docs[]', 'b.pdf', $pdfs[1]],
			['docs[]', 'empty.pdf', ''],
			['docs[]', 'big.pdf', \str_repeat('x', 2048)],
			['docs[]', 'c.pdf', $pdfs[2]],
		]), $root];
	}

	return $sent;
};

try {

	// -- 1. Real $_FILES shapes ---------------------------------------------------------

	test('one() gives a single field as one normalized entry without full_path, and many() refuses it', static function () use ($shapes, $isEntry): void {
		[$one, $manySingle] = $shapes()['probes'];
		check($isEntry($one, 'a.txt', 6), 'one(): a single field gives one normalized entry, without full_path');
		check($manySingle === [], 'many(): a single field is not a list');
	});

	test('many() lists name[] without the empty slot, and one() refuses name[] but selects a slot by path', static function () use ($shapes, $isEntry, $pdf): void {
		[, , $multi, $oneMulti, $firstSlot, $emptySlot] = $shapes()['probes'];
		check(\count($multi) === 1 && $isEntry($multi[0], 'b.pdf', \strlen($pdf)), 'many(): name[] gives a list, and the empty slot (UPLOAD_ERR_NO_FILE) is skipped');
		check($oneMulti === null, 'one(): a name[] field is refused, so a client cannot turn a single field into arrays');
		check($isEntry($firstSlot, 'b.pdf', \strlen($pdf)) && $emptySlot === null, 'one($entry, 0): a list slot is selected by path, and the empty slot gives null');
	});

	test('a keyed field needs its key as path; PHP strips the client directory from name and reports it in full_path, which normalization drops', static function () use ($shapes, $isEntry): void {
		$report = $shapes();
		[, , , , , , $oneKeyed, $front, $manyKeyed] = $report['probes'];
		check($oneKeyed === null && $isEntry($front, 'd.txt', 6), 'one(): a keyed field needs its key as path; PHP strips the client directory from name');
		check(($report['files']['keyed']['full_path']['front'] ?? null) === 'dir/sub/d.txt', 'PHP reports the client directory in full_path, which normalization drops');
		check(\count($manyKeyed) === 1 && $isEntry($manyKeyed[0], 'd.txt', 6), 'many(): a keyed field gives a list');
	});

	test('deeper nesting needs the full path, and many() keeps the client order of a nested list', static function () use ($shapes, $isEntry): void {
		[$deep, $deepPartial, $manyDeep, $gallery] = \array_slice($shapes()['probes'], 9, 4);
		check($isEntry($deep, 'c.txt', 5) && $deepPartial === null && $manyDeep === [], 'deeper nesting: only the full path selects the entry');
		check(\count($gallery) === 2 && $isEntry($gallery[0], 'p1.txt', 4) && $isEntry($gallery[1], 'p2.txt', 4), 'many($entry, path): a nested list keeps the client order');
	});

	test('one() gives null for a missing field', static function () use ($shapes): void {
		check($shapes()['probes'][13] === null, 'one(): a missing field gives null');
	});


	// -- 2. Storing an upload -----------------------------------------------------------

	test('storeUpload() moves a plain upload into storage with the documented result, and storing the same entry again is a developer error', static function () use ($request, $newRoot, $pdf, $pdfProfile, $resultKeys): void {
		$root = $newRoot();
		$profile = ['directory' => 'docs', 'hash' => 'sha256'] + $pdfProfile;
		$report = $request(['root' => $root, 'calls' => [
			['entry' => 'one', 'field' => 'f', 'profile' => $profile, 'subdir' => 'u1'],
			['entry' => 'one', 'field' => 'f', 'profile' => $profile],
		]], [['f', "Faktura\u{202E}fdp.pdf", $pdf]]);

		[$stored, $again] = $report['calls'];
		$result = $stored['result'] ?? [];

		check(\array_keys($result) === $resultKeys, 'storeUpload() returns the documented result keys, in order');
		check(
			$result['storage'] === 'files' && \preg_match('~^docs/u1/[0-9a-f]{32}\.pdf$~D', $result['path']) === 1
			&& $result['mime'] === 'application/pdf' && $result['source_mime'] === 'application/pdf' && $result['size'] === \strlen($pdf)
			&& $result['width'] === null && $result['height'] === null && $result['hash'] === 'sha256:' . \hash('sha256', $pdf) && $result['variants'] === [],
			'a plain upload reports its own type, size, and hash, without dimensions or variants'
		);
		check($result['original_name'] === 'Fakturafdp.pdf', 'the client name is sanitized metadata (the bidi override is removed)');
		check(\file_get_contents($root . '/' . $result['path']) === $pdf && filesUnder($root) === [$result['path']], 'the stored file holds the uploaded bytes, and no temporary file remains');
		check($stored['tmp_exists_after'] === false, 'the upload is consumed: move_uploaded_file() moved it into storage');
		check(($again['error']['class'] ?? null) === \InvalidArgumentException::class, 'storing the same entry again is a developer error');
		check(!\in_array('image', $report['touched'], true) && !\in_array('txt', $report['touched'], true) && $report['log'] === [], 'neither image nor txt is resolved, and nothing is logged');
	});

	test('the default file_mode 0644 is applied to an upload', static function () use ($request, $newRoot, $pdf, $pdfProfile): void {
		requires(!isWindows(), 'POSIX permissions do not apply on Windows');

		$root = $newRoot();
		$report = $request(['root' => $root, 'calls' => [['entry' => 'one', 'field' => 'f', 'profile' => $pdfProfile]]], [['f', 'x.pdf', $pdf]]);
		$path = $report['calls'][0]['result']['path'] ?? '';
		\clearstatcache();
		check($path !== '' && (\fileperms($root . '/' . $path) & 0777) === 0644, 'the default file_mode 0644 is applied to an upload');
	});

	test('with file_mode null, an upload keeps the 0666 & ~umask that move_uploaded_file() applies', static function () use ($request, $newRoot, $pdf, $pdfProfile): void {
		requires(!isWindows(), 'POSIX permissions do not apply on Windows');

		$root = $newRoot();
		$report = $request(['root' => $root, 'upload' => ['file_mode' => null], 'calls' => [['entry' => 'one', 'field' => 'f', 'profile' => $pdfProfile]]], [['f', 'x.pdf', $pdf]]);
		$path = $report['calls'][0]['result']['path'] ?? '';
		\clearstatcache();
		check($path !== '' && (\fileperms($root . '/' . $path) & 0777) === (0666 & ~\umask()), 'with file_mode null, an upload keeps the 0666 & ~umask that move_uploaded_file() applies');
	});


	// -- 3. Upload errors reported by PHP -----------------------------------------------

	test('PHP reports UPLOAD_ERR_INI_SIZE above upload_max_filesize: one() keeps it, and storeUpload() rejects it as TooLarge', static function () use ($request, $newRoot, $anyCall): void {
		$root = $newRoot();
		$report = $request(['root' => $root, 'probes' => [['one', 'f', []]], 'calls' => [$anyCall]], [['f', 'big.bin', \str_repeat('x', 2048)]]);
		check(($report['probes'][0]['error'] ?? null) === \UPLOAD_ERR_INI_SIZE && $report['probes'][0]['tmp_name'] === '', 'PHP reports UPLOAD_ERR_INI_SIZE above upload_max_filesize, and one() keeps it');
		check(($report['calls'][0]['error']['reason'] ?? null) === 'too_large' && $report['calls'][0]['error']['context'] === ['original_name' => 'big.bin', 'upload_error' => \UPLOAD_ERR_INI_SIZE], 'UPLOAD_ERR_INI_SIZE is rejected as TooLarge, with upload_error');
		check(filesUnder($root) === [], 'the upload error writes nothing');
	});

	test('UPLOAD_ERR_FORM_SIZE (MAX_FILE_SIZE) is rejected as TooLarge', static function () use ($request, $newRoot, $anyCall): void {
		$root = $newRoot();
		$report = $request(['root' => $root, 'calls' => [$anyCall]], [['f', 'form.bin', \str_repeat('x', 100)]], ['MAX_FILE_SIZE' => '10']);
		check(($report['calls'][0]['error']['reason'] ?? null) === 'too_large' && $report['calls'][0]['error']['context'] === ['original_name' => 'form.bin', 'upload_error' => \UPLOAD_ERR_FORM_SIZE], 'UPLOAD_ERR_FORM_SIZE (MAX_FILE_SIZE) is rejected as TooLarge');
		check(filesUnder($root) === [], 'the upload error writes nothing');
	});

	test('a truncated request gives UPLOAD_ERR_PARTIAL, rejected as Partial', static function () use ($request, $newRoot, $anyCall): void {
		$root = $newRoot();
		$report = $request(['root' => $root, 'calls' => [$anyCall]], [['f', 'part.bin', \str_repeat('y', 300)]], [], 150);
		check(($report['calls'][0]['error']['reason'] ?? null) === 'partial' && $report['calls'][0]['error']['context'] === ['original_name' => 'part.bin', 'upload_error' => \UPLOAD_ERR_PARTIAL], 'a truncated request gives UPLOAD_ERR_PARTIAL, rejected as Partial');
		check(filesUnder($root) === [], 'the upload error writes nothing');
	});

	test('no chosen file gives null from one() and NoFile for the raw entry', static function () use ($request, $newRoot, $anyCall): void {
		$root = $newRoot();
		$report = $request(['root' => $root, 'probes' => [['one', 'f', []]], 'calls' => [['entry' => 'raw'] + $anyCall]], [['f', '', '']]);
		check($report['probes'][0] === null, 'one() gives null when no file was chosen');
		check(($report['calls'][0]['error']['reason'] ?? null) === 'no_file' && $report['calls'][0]['error']['context'] === ['upload_error' => \UPLOAD_ERR_NO_FILE], 'a raw entry with UPLOAD_ERR_NO_FILE is rejected as NoFile');
		check(filesUnder($root) === [], 'the upload error writes nothing');
	});


	// -- 4. Generic validation and provenance -------------------------------------------

	test('a zero-byte upload (UPLOAD_ERR_OK) is rejected as Empty', static function () use ($request, $newRoot, $anyCall): void {
		$root = $newRoot();
		$report = $request(['root' => $root, 'calls' => [$anyCall]], [['f', 'empty.txt', '']]);
		check(($report['calls'][0]['error']['reason'] ?? null) === 'empty' && $report['calls'][0]['error']['context'] === ['original_name' => 'empty.txt', 'size' => 0], 'a zero-byte upload (UPLOAD_ERR_OK) is rejected as Empty');
		check(filesUnder($root) === [], 'the rejection writes nothing');
	});

	test('an upload above max_bytes is rejected as TooLarge', static function () use ($request, $newRoot, $anyCall, $anyProfile): void {
		$root = $newRoot();
		$report = $request(['root' => $root, 'calls' => [['profile' => ['max_bytes' => 10] + $anyProfile] + $anyCall]], [['f', 'hundred.bin', \str_repeat('z', 100)]]);
		check(($report['calls'][0]['error']['reason'] ?? null) === 'too_large' && $report['calls'][0]['error']['context'] === ['original_name' => 'hundred.bin', 'size' => 100, 'max_bytes' => 10], 'an upload above max_bytes is rejected as TooLarge');
		check(filesUnder($root) === [], 'the rejection writes nothing');
	});

	test('a rejected upload stays with PHP and can be stored again within the request, and PHP code is never stored as .php or .jpg', static function () use ($request, $newRoot, $anyCall, $anyProfile): void {
		$root = $newRoot();
		$report = $request(['root' => $root, 'calls' => [['profile' => ['accept' => ['image/jpeg']] + $anyProfile] + $anyCall, $anyCall]], [['f', 'evil.jpg', Fixtures::php()]]);
		[$rejected, $retried] = $report['calls'];
		$path = $retried['result']['path'] ?? '';
		check(($rejected['error']['reason'] ?? null) === 'type_not_allowed' && $rejected['error']['context']['mime'] !== 'image/jpeg', 'PHP code named .jpg is rejected by an image accept list');
		check($rejected['tmp_exists_after'] === true, 'a rejection does not consume the upload');
		check($retried['tmp_exists_after'] === false && $path !== '' && \preg_match('~\.(php|phtml|jpg)$~D', $path) === 0, 'the same upload can be stored again within the request, and PHP code is never stored as .php or .jpg');
		check(filesUnder($root) === [$path], 'only the accepted upload is stored');
	});

	test('a path that is not an upload of the request is refused during an upload request too, and the file is untouched', static function () use ($request, $newRoot, $localFile, $pdf, $pdfProfile): void {
		$root = $newRoot();
		$local = $localFile($pdf);
		$report = $request(['root' => $root, 'calls' => [[
			'entry' => ['name' => 'local.pdf', 'type' => 'application/pdf', 'tmp_name' => $local, 'error' => \UPLOAD_ERR_OK, 'size' => \strlen($pdf)],
			'profile' => $pdfProfile,
		]]], [['f', 'other.txt', "x\n"]]);
		check(
			($report['calls'][0]['error']['class'] ?? null) === \InvalidArgumentException::class && \file_get_contents($local) === $pdf && filesUnder($root) === [],
			'a path that is not an upload of the request is refused during an upload request too, and the file is untouched'
		);
	});


	// -- 5. Failures at and after the move ----------------------------------------------

	test('a failure after the move (hashing an unreadable file) is a storage error, and compensation removes the moved upload', static function () use ($request, $newRoot, $pdf, $pdfProfile): void {
		requires(canForceUnreadableFile(), 'file permissions are not enforced for this user or platform');

		$root = $newRoot();
		$report = $request(['root' => $root, 'upload' => ['file_mode' => 0], 'calls' => [['entry' => 'one', 'field' => 'f', 'profile' => ['hash' => 'sha256'] + $pdfProfile]]], [['f', 'x.pdf', $pdf]]);
		$error = $report['calls'][0]['error'] ?? [];
		check(($error['class'] ?? null) === UploadStorageException::class && \str_contains($error['message'], 'Failed to hash'), 'a failure after the move (hashing an unreadable file) is a storage error');
		check(filesUnder($root) === [] && $report['calls'][0]['tmp_exists_after'] === false, 'compensation removes the moved upload, and nothing remains');
	});

	test('a failed move_uploaded_file() is a storage error, writes nothing, and leaves the upload with PHP', static function () use ($request, $newRoot, $pdf, $pdfProfile): void {
		requires(canForceReadOnlyDir(), 'directories cannot be made read-only for this user or platform');

		$root = $newRoot();
		\mkdir($root . '/locked');
		\chmod($root . '/locked', 0555);

		try {
			$report = $request(['root' => $root, 'calls' => [['entry' => 'one', 'field' => 'f', 'profile' => ['directory' => 'locked'] + $pdfProfile]]], [['f', 'x.pdf', $pdf]]);
		} finally {
			\chmod($root . '/locked', 0755);
		}

		$error = $report['calls'][0]['error'] ?? [];
		check(($error['class'] ?? null) === UploadStorageException::class && \str_contains($error['message'], 'Failed to move the uploaded file'), 'a failed move_uploaded_file() is a storage error');
		check(filesUnder($root) === [] && $report['calls'][0]['tmp_exists_after'] === true, 'nothing is written, and the upload stays with PHP until the request ends');
	});


	// -- 6. Image uploads ---------------------------------------------------------------

	test('an image upload whose original is kept is moved into storage after the image job', static function () use ($request, $newRoot, $photo, $keep): void {
		$root = $newRoot();
		$report = $request(['root' => $root, 'image' => 'scripted', 'calls' => [['entry' => 'one', 'field' => 'f', 'profile' => $keep]]], [['f', 'photo.jpg', $photo]]);
		$result = $report['calls'][0]['result'] ?? [];
		check(
			\str_ends_with($result['path'] ?? '', '.jpg') && \file_get_contents($root . '/' . $result['path']) === $photo
			&& isset($result['variants']['preview']) && $report['calls'][0]['tmp_exists_after'] === false,
			'an image upload whose original is kept is moved into storage after the image job'
		);
	});

	test('a re-encoded main leaves the upload with PHP, so the same entry can be stored again within the request', static function () use ($request, $newRoot, $photo, $encode): void {
		$root = $newRoot();
		$report = $request(['root' => $root, 'image' => 'scripted', 'calls' => [['entry' => 'one', 'field' => 'f', 'profile' => $encode], ['entry' => 'one', 'field' => 'f', 'profile' => $encode]]], [['f', 'photo.jpg', $photo]]);
		[$first, $second] = $report['calls'];
		check(
			\str_ends_with($first['result']['path'] ?? '', '.webp') && $first['tmp_exists_after'] === true
			&& isset($second['result']['path']) && $second['result']['path'] !== $first['result']['path'],
			'a re-encoded main leaves the upload with PHP, so the same entry can be stored again within the request'
		);
	});

	test('with the real citomni/image, an uploaded JPEG is kept bit for bit and its preview is rendered from the upload', static function () use ($request, $newRoot, $keep): void {
		requires(\extension_loaded('gd'), 'ext-gd is not loaded');
		$capabilities = (new Image(testApp(testCfg(ImageRegistry::CFG_COMMON))))->capabilities();
		requires($capabilities['decode']['jpeg'] !== null && $capabilities['encode']['webp'] !== null, 'citomni/image cannot decode JPEG or encode WebP in this runtime');

		$canvas = \imagecreatetruecolor(16, 8);
		\ob_start();
		\imagejpeg($canvas);
		$bytes = (string)\ob_get_clean();

		$root = $newRoot();
		$report = $request(['root' => $root, 'image' => 'real', 'calls' => [['entry' => 'one', 'field' => 'f', 'profile' => $keep]]], [['f', 'real.jpg', $bytes]]);
		$result = $report['calls'][0]['result'] ?? [];
		$preview = isset($result['variants']['preview']['path']) ? \getimagesize($root . '/' . $result['variants']['preview']['path']) : false;
		check(
			\file_get_contents($root . '/' . ($result['path'] ?? '')) === $bytes && [$result['width'], $result['height']] === [16, 8]
			&& \is_array($preview) && [$preview[0], $preview[1]] === [10, 5] && $report['calls'][0]['tmp_exists_after'] === false,
			'with the real citomni/image, an uploaded JPEG is kept bit for bit and its preview is rendered from the upload'
		);
	});


	// -- 7. Checking an upload without storing it: checkUpload() ------------------------

	test('checkUpload() reports the detected type, the measured size, and the sanitized name, and leaves the upload with PHP byte for byte', static function () use ($request, $newRoot, $pdf, $pdfProfile): void {
		$root = $newRoot();
		$report = $request(['root' => $root, 'checks' => [['entry' => 'one', 'field' => 'f', 'profile' => $pdfProfile]]], [['f', "Faktura\u{202E}fdp.pdf", $pdf]]);
		$checked = $report['checks'][0];
		check(
			($checked['result'] ?? null) === ['mime' => 'application/pdf', 'size' => \strlen($pdf), 'original_name' => 'Fakturafdp.pdf', 'width' => null, 'height' => null],
			'checkUpload() returns the detected type instead of the client type (application/octet-stream), the measured size, and the sanitized name'
		);
		check($checked['tmp_exists_after'] === true && $checked['tmp_sha256_after'] === \hash('sha256', $pdf), 'checkUpload() leaves the upload with PHP, byte for byte');
		check(filesUnder($root) === [] && $report['log'] === [] && !\in_array('image', $report['touched'], true) && !\in_array('txt', $report['touched'], true), 'checkUpload() writes nothing, logs nothing, and resolves neither image nor txt on the plain path');
	});

	test('a checked upload can still be stored within the request', static function () use ($request, $newRoot, $pdf, $pdfProfile): void {
		$root = $newRoot();
		$report = $request(['root' => $root, 'checks' => [['entry' => 'one', 'field' => 'f', 'profile' => $pdfProfile]], 'calls' => [['entry' => 'one', 'field' => 'f', 'profile' => $pdfProfile]]], [['f', 'x.pdf', $pdf]]);
		$path = $report['calls'][0]['result']['path'] ?? '';
		check(isset($report['checks'][0]['result']) && $path !== '' && filesUnder($root) === [$path] && \file_get_contents($root . '/' . $path) === $pdf, 'a checked upload can still be stored within the request');
	});

	test('checkUpload() needs neither a provisioned storage root nor a valid file_mode, and creates nothing', static function () use ($request, $dir, $pdf, $pdfProfile): void {
		$missing = $dir . '/not-provisioned';
		$report = $request(['root' => $missing, 'upload' => ['file_mode' => '0644'], 'checks' => [['entry' => 'one', 'field' => 'f', 'profile' => $pdfProfile]]], [['f', 'x.pdf', $pdf]]);
		check(($report['checks'][0]['result']['mime'] ?? null) === 'application/pdf' && !\file_exists($missing), 'checkUpload() needs neither a provisioned storage root nor a valid file_mode, and creates nothing');
	});

	test('checkUpload() rejects as storeUpload() does, with the same contexts, and a rejected check leaves the upload with PHP', static function () use ($request, $newRoot, $pdf, $pdfProfile): void {
		$root = $newRoot();
		$report = $request(['root' => $root, 'checks' => [
			['entry' => 'one', 'field' => 'empty', 'profile' => $pdfProfile],
			['entry' => 'one', 'field' => 'big', 'profile' => ['max_bytes' => 10] + $pdfProfile],
			['entry' => 'one', 'field' => 'evil', 'profile' => $pdfProfile],
			['entry' => 'one', 'field' => 'huge', 'profile' => $pdfProfile],
			['entry' => 'raw', 'field' => 'none', 'profile' => $pdfProfile],
		]], [['empty', 'empty.pdf', ''], ['big', 'big.pdf', $pdf], ['evil', 'evil.pdf', Fixtures::php()], ['huge', 'huge.pdf', \str_repeat('x', 2048)], ['none', '', '']]);
		$errors = \array_column($report['checks'], 'error');
		check(\array_column($errors, 'reason') === ['empty', 'too_large', 'type_not_allowed', 'too_large', 'no_file'], 'checkUpload() rejects as storeUpload() does: Empty, TooLarge (max_bytes), TypeNotAllowed, TooLarge (UPLOAD_ERR_INI_SIZE), and NoFile');
		check(
			$errors[1]['context'] === ['original_name' => 'big.pdf', 'size' => \strlen($pdf), 'max_bytes' => 10] && $errors[2]['context']['mime'] !== 'application/pdf'
			&& $errors[3]['context'] === ['original_name' => 'huge.pdf', 'upload_error' => \UPLOAD_ERR_INI_SIZE],
			'the rejections carry the same contexts as storeUpload()'
		);
		check(\array_column($report['checks'], 'tmp_exists_after') === [true, true, true, null, null] && filesUnder($root) === [], 'a rejected check leaves the upload with PHP and writes nothing');
	});

	test('checkUpload() refuses a path that is not an upload of the request, and the file is untouched', static function () use ($request, $newRoot, $localFile, $pdf, $pdfProfile): void {
		$root = $newRoot();
		$local = $localFile($pdf);
		$report = $request(['root' => $root, 'checks' => [[
			'entry' => ['name' => 'local.pdf', 'type' => 'application/pdf', 'tmp_name' => $local, 'error' => \UPLOAD_ERR_OK, 'size' => \strlen($pdf)],
			'profile' => $pdfProfile,
		]]], [['f', 'other.txt', "x\n"]]);
		check(($report['checks'][0]['error']['class'] ?? null) === \InvalidArgumentException::class && \file_get_contents($local) === $pdf, 'checkUpload() refuses a path that is not an upload of the request, and the file is untouched');
	});

	test('checkUpload() on the image path inspects the upload and reports the display size from inspection, without an image job', static function () use ($request, $newRoot, $photo): void {
		$root = $newRoot();
		$images = ['storage' => 'files', 'accept' => ['image/jpeg', 'image/png'], 'max_bytes' => 1000, 'image' => ['main' => null, 'variants' => ['preview' => ['format' => 'webp', 'width' => 10]]]];
		$report = $request(['root' => $root, 'image' => 'scripted', 'checks' => [['entry' => 'one', 'field' => 'photo', 'profile' => $images]]], [['photo', 'photo.jpg', $photo]]);
		$checked = $report['checks'][0];
		check(
			($checked['result'] ?? null) === ['mime' => 'image/jpeg', 'size' => \strlen($photo), 'original_name' => 'photo.jpg', 'width' => 1600, 'height' => 1200]
			&& $checked['tmp_exists_after'] === true && filesUnder($root) === [],
			'an image profile inspects the upload and reports the display size from inspection, without an image job'
		);
	});

	test('checkUpload() on the image path requires finfo and citomni/image to agree, as on the store path', static function () use ($request, $newRoot): void {
		$root = $newRoot();
		$images = ['storage' => 'files', 'accept' => ['image/jpeg', 'image/png'], 'max_bytes' => 1000, 'image' => ['main' => null, 'variants' => ['preview' => ['format' => 'webp', 'width' => 10]]]];
		$report = $request(['root' => $root, 'image' => 'scripted', 'checks' => [['entry' => 'one', 'field' => 'mislabeled', 'profile' => $images]]], [['mislabeled', 'graphic.png', Fixtures::header(ImageFormat::Png, 16, 8)]]);
		$mislabeled = $report['checks'][0];
		check(
			($mislabeled['error']['reason'] ?? null) === 'type_not_allowed' && ($mislabeled['error']['context']['image_mime'] ?? null) === 'image/jpeg',
			'on the image path, finfo and citomni/image must agree, as on the store path'
		);
	});


	// -- 8. Batches: storeUploads() -----------------------------------------------------

	test('storeUploads() stores every accepted file in client order, each a storeUpload() result below the batch $subdir', static function () use ($partialBatch, $pdfs, $resultKeys): void {
		[$report, $root] = $partialBatch();
		$batch = $report['batches'][0];
		$stored = $batch['stored'] ?? [];
		$paths = \array_column($stored, 'path');
		\sort($paths);

		check(($batch['keys'] ?? null) === ['stored', 'rejected'], 'storeUploads() returns stored and rejected, in that order');
		check(\array_column($stored, 'original_name') === ['a.pdf', 'b.pdf', 'c.pdf'], 'partial acceptance: every accepted file is stored, in client order');
		check(
			\array_filter($stored, static fn(array $result): bool => \array_keys($result) !== $resultKeys || \preg_match('~^batch/[0-9a-f]{32}\.pdf$~D', $result['path']) !== 1) === [],
			'every stored entry is a storeUpload() result below the batch $subdir'
		);
		check(\array_map(static fn(array $result): string => (string)\file_get_contents($root . '/' . $result['path']), $stored) === $pdfs && filesUnder($root) === $paths, 'each result points to its own file, and only the accepted files are stored');
	});

	test('storeUploads() collects every rejection in client order, with its context, instead of stopping the batch', static function () use ($partialBatch): void {
		$rejected = $partialBatch()[0]['batches'][0]['rejected'] ?? [];
		check(\array_column($rejected, 'reason') === ['type_not_allowed', 'empty', 'too_large'], 'every rejection is collected, in client order, instead of stopping the batch');
		check(\array_unique(\array_column($rejected, 'class')) === [UploadRejectedException::class], 'the rejections are the UploadRejectedException instances storeUpload() throws');
		check(
			\array_column(\array_column($rejected, 'context'), 'original_name') === ['evil.pdf', 'empty.pdf', 'big.pdf'] && $rejected[2]['context'] === ['original_name' => 'big.pdf', 'upload_error' => \UPLOAD_ERR_INI_SIZE],
			'each rejection keeps its context, so the adapter can name the file'
		);
	});

	test('a batch consumes the stored uploads and leaves the rejected ones with PHP, without image, txt, or log', static function () use ($partialBatch): void {
		$report = $partialBatch()[0];
		check(($report['batches'][0]['tmp_exists_after'] ?? null) === [false, true, false, true, false, false], 'stored uploads are consumed, rejected uploads stay with PHP, and many() skipped the empty slot');
		check($report['log'] === [] && !\in_array('image', $report['touched'], true) && !\in_array('txt', $report['touched'], true), 'a plain batch resolves neither image nor txt, and logs nothing');
	});

	test('all-or-nothing on top: cleanupStored() removes the stored files of a batch with a rejection and reports true', static function () use ($request, $newRoot, $pdfProfile, $pdfs): void {
		$root = $newRoot();
		$report = $request(['root' => $root, 'batches' => [['field' => 'docs', 'profile' => $pdfProfile, 'discard' => true]]], [
			['docs[]', 'a.pdf', $pdfs[0]],
			['docs[]', 'evil.pdf', Fixtures::php()],
			['docs[]', 'b.pdf', $pdfs[1]],
		]);
		$batch = $report['batches'][0];
		check(\count($batch['stored'] ?? []) === 2 && \count($batch['rejected'] ?? []) === 1 && ($batch['discarded'] ?? null) === true, 'all-or-nothing on top: cleanupStored() removes the stored files of a batch with a rejection and reports true');
		check(filesUnder($root) === [] && $report['log'] === [], 'nothing of the discarded batch remains, and nothing is logged');
	});

	test('a fault part-way through a batch propagates, the files stored before it are removed, and later entries are never attempted', static function () use ($request, $newRoot, $localFile, $pdf, $pdfProfile, $pdfs): void {
		$root = $newRoot();
		$forged = $localFile($pdf);
		$report = $request(['root' => $root, 'batches' => [[
			'field' => 'docs',
			'profile' => $pdfProfile,
			'insert' => [[2, ['name' => 'forged.pdf', 'type' => 'application/pdf', 'tmp_name' => $forged, 'error' => \UPLOAD_ERR_OK, 'size' => \strlen($pdf)]]],
		]]], [['docs[]', 'a.pdf', $pdfs[0]], ['docs[]', 'b.pdf', $pdfs[1]], ['docs[]', 'c.pdf', $pdfs[2]]]);
		$batch = $report['batches'][0];
		check(($batch['error']['class'] ?? null) === \InvalidArgumentException::class && !isset($batch['stored']), 'a fault part-way through a batch propagates: here an entry that was not uploaded in the request');
		check(filesUnder($root) === [] && ($batch['tmp_exists_after'] ?? null) === [false, false, true, true], 'the files of the entries stored before the fault are removed, and later entries are never attempted');
		check($report['log'] === [] && \file_get_contents($forged) === $pdf, 'the compensation succeeds silently, and the file of the forged entry is untouched');
	});

	test('a malformed entry is reported before anything is stored, so every upload stays with PHP', static function () use ($request, $newRoot, $pdfProfile, $pdfs): void {
		$root = $newRoot();
		$report = $request(['root' => $root, 'batches' => [['field' => 'docs', 'profile' => $pdfProfile, 'insert' => [[2, ['name' => 'no-tmp-name.pdf', 'error' => \UPLOAD_ERR_OK]]]]]], [
			['docs[]', 'a.pdf', $pdfs[0]],
			['docs[]', 'b.pdf', $pdfs[1]],
			['docs[]', 'c.pdf', $pdfs[2]],
		]);
		$batch = $report['batches'][0];
		check(
			($batch['error']['class'] ?? null) === \InvalidArgumentException::class && filesUnder($root) === [] && ($batch['tmp_exists_after'] ?? null) === [true, true, null, true],
			'a malformed entry is reported before anything is stored: every upload stays with PHP'
		);
	});

	test('an image batch stores each kept original with its preview, and cleanupStored() removes a discarded image result', static function () use ($request, $newRoot, $photo, $keep): void {
		$root = $newRoot();
		$report = $request(['root' => $root, 'image' => 'scripted', 'batches' => [
			['field' => 'keep', 'profile' => $keep, 'subdir' => 'gallery'],
			['field' => 'drop', 'profile' => $keep, 'subdir' => 'gallery', 'discard' => true],
		]], [['keep[]', 'one.jpg', $photo], ['keep[]', 'two.jpg', $photo], ['drop[]', 'three.jpg', $photo]]);
		[$kept, $dropped] = $report['batches'];
		$files = [];

		foreach ($kept['stored'] ?? [] as $result) {
			\array_push($files, $result['path'], $result['variants']['preview']['path'] ?? '');
		}

		\sort($files);
		check(\count($kept['stored'] ?? []) === 2 && filesUnder($root) === $files, 'an image batch stores each kept original with its preview, and every result lists both');
		check(\count($dropped['stored'] ?? []) === 1 && ($dropped['discarded'] ?? null) === true, 'cleanupStored() removes a discarded image result, its preview and its original');
	});

	test('an ImageCapabilityException part-way through an image batch propagates unchanged, and the files of the photo before it are removed', static function () use ($request, $newRoot, $photo, $keep): void {
		$root = $newRoot();
		$report = $request(['root' => $root, 'image' => 'scripted', 'fail_save' => 2, 'batches' => [['field' => 'photos', 'profile' => $keep, 'subdir' => 'gallery']]], [
			['photos[]', 'one.jpg', $photo],
			['photos[]', 'two.jpg', $photo],
			['photos[]', 'three.jpg', $photo],
		]);
		$batch = $report['batches'][0];
		check(($batch['error']['class'] ?? null) === ImageCapabilityException::class, 'an ImageCapabilityException part-way through an image batch propagates unchanged');
		check(filesUnder($root) === [] && ($batch['tmp_exists_after'] ?? null) === [false, true, true], 'the original and preview of the first photo are removed; the failing and later photos stay with PHP');
		check($report['log'] === [], 'the compensation of an image batch logs nothing when every file goes');
	});

	test('a removal the filesystem refuses during batch compensation is logged and does not mask the exception in flight', static function () use ($request, $newRoot, $photo, $keep): void {
		requires(!isWindows() && canForceUndeletableFile(), 'needs a non-root POSIX user');

		$root = $newRoot();

		try {
			$report = $request(['root' => $root, 'image' => 'scripted', 'fail_save' => 2, 'lock_on_fail' => true, 'batches' => [['field' => 'photos', 'profile' => $keep, 'subdir' => 'gallery']]], [
				['photos[]', 'one.jpg', $photo],
				['photos[]', 'two.jpg', $photo],
			]);
		} finally {
			if (\is_dir($root . '/gallery')) {
				\chmod($root . '/gallery', 0755);
			}
		}

		$batch = $report['batches'][0];
		$log = $report['log'];
		check(($batch['error']['class'] ?? null) === ImageCapabilityException::class && \count(filesUnder($root)) === 2, 'a refused removal during batch compensation does not mask the exception in flight, and the two files stay');
		check(
			\count($log) === 2 && \array_unique(\array_column($log, 'message')) === ['Failed to remove a file written by an aborted store.']
			&& \array_unique(\array_column($log, 'category')) === ['cleanup'] && \array_keys($log[0]['context']) === ['storage', 'path', 'error']
			&& $log[0]['context']['storage'] === 'files' && \str_starts_with($log[0]['context']['path'], $root . '/gallery/'),
			'every file the batch compensation could not remove is logged as a file of an aborted store'
		);
	});

} finally {
	$server?->stop();
}

done();
