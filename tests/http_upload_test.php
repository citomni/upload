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
 * End-to-end checks for storeUpload() and storeUploads() with real HTTP uploads.
 *
 * is_uploaded_file() and move_uploaded_file() only accept files uploaded in
 * the current request. This script therefore starts PHP's built-in web server
 * on 127.0.0.1 and a free port, sends raw multipart/form-data requests, and
 * lets tests/Support/http_router.php call UploadedFiles, storeUpload(),
 * storeUploads(), and cleanupStored() inside each request. The router reports
 * results, exceptions, and the state of the uploaded files as JSON; this
 * script inspects storage directly.
 *
 * The server is stopped in finally, so a failed check never leaves it running.
 * The script skips itself outside the CLI, without proc_open(), or when the
 * server cannot be started.
 */

require __DIR__ . '/bootstrap.php';

use CitOmni\Image\Boot\Registry as ImageRegistry;
use CitOmni\Image\Exception\ImageCapabilityException;
use CitOmni\Image\Service\Image;
use CitOmni\Upload\Exception\UploadRejectedException;
use CitOmni\Upload\Exception\UploadStorageException;
use CitOmni\Upload\Tests\Support\Fixtures;
use CitOmni\Upload\Tests\Support\HttpServer;

$dir = tempDir();
$server = \PHP_SAPI === 'cli' ? HttpServer::start(__DIR__ . '/Support/http_router.php', ['upload_max_filesize' => '1K'], $dir) : null;

if ($server === null) {
	skip('HTTP upload checks', \PHP_SAPI === 'cli' ? 'the built-in web server could not be started: ' . HttpServer::$lastFailure : 'the built-in web server is only started from the CLI');
	done('http_upload_test');
	exit(0);
}

try {
	$pdf = Fixtures::pdf();
	$pdfProfile = ['storage' => 'files', 'accept' => ['application/pdf'], 'max_bytes' => 1000];
	$anyProfile = ['storage' => 'files', 'accept' => ['*'], 'max_bytes' => 100_000];

	/** Create an empty storage root. */
	$newRoot = static function () use ($dir): string {
		$root = $dir . '/root-' . \bin2hex(\random_bytes(4));
		\mkdir($root);

		return $root;
	};

	/**
	 * Send one multipart request: the JSON spec and $fields first, then the files.
	 *
	 * @param list<array{0: string, 1: string, 2: string}> $files [field name, file name, bytes].
	 */
	$request = static fn(array $spec, array $files, array $fields = [], int $cutTail = 0): array
		=> $server->post(['spec' => \json_encode($spec, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)] + $fields, $files, $cutTail);

	/** Whether a value is the normalized entry of an accepted upload. */
	$isEntry = static fn(mixed $entry, string $name, int $size): bool
		=> \is_array($entry) && \array_keys($entry) === ['name', 'type', 'tmp_name', 'error', 'size']
		&& $entry['name'] === $name && $entry['size'] === $size && $entry['error'] === \UPLOAD_ERR_OK && $entry['tmp_name'] !== '';

	// -- Real $_FILES shapes ------------------------------------------------------------------

	$report = $request(['probes' => [
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

	[$one, $manySingle, $multi, $oneMulti, $firstSlot, $emptySlot, $oneKeyed, $front, $manyKeyed, $deep, $deepPartial, $manyDeep, $gallery, $missing] = $report['probes'];

	check($isEntry($one, 'a.txt', 6), 'one(): a single field gives one normalized entry, without full_path');
	check($manySingle === [], 'many(): a single field is not a list');
	check(\count($multi) === 1 && $isEntry($multi[0], 'b.pdf', \strlen($pdf)), 'many(): name[] gives a list, and the empty slot (UPLOAD_ERR_NO_FILE) is skipped');
	check($oneMulti === null, 'one(): a name[] field is refused, so a client cannot turn a single field into arrays');
	check($isEntry($firstSlot, 'b.pdf', \strlen($pdf)) && $emptySlot === null, 'one($entry, 0): a list slot is selected by path, and the empty slot gives null');
	check($oneKeyed === null && $isEntry($front, 'd.txt', 6), 'one(): a keyed field needs its key as path; PHP strips the client directory from name');
	check(($report['files']['keyed']['full_path']['front'] ?? null) === 'dir/sub/d.txt', 'PHP reports the client directory in full_path, which normalization drops');
	check(\count($manyKeyed) === 1 && $isEntry($manyKeyed[0], 'd.txt', 6), 'many(): a keyed field gives a list');
	check($isEntry($deep, 'c.txt', 5) && $deepPartial === null && $manyDeep === [], 'deeper nesting: only the full path selects the entry');
	check(\count($gallery) === 2 && $isEntry($gallery[0], 'p1.txt', 4) && $isEntry($gallery[1], 'p2.txt', 4), 'many($entry, path): a nested list keeps the client order');
	check($missing === null, 'one(): a missing field gives null');

	// -- Storing an upload --------------------------------------------------------------------

	$root = $newRoot();
	$profile = ['directory' => 'docs', 'hash' => 'sha256'] + $pdfProfile;
	$report = $request(['root' => $root, 'calls' => [
		['entry' => 'one', 'field' => 'f', 'profile' => $profile, 'subdir' => 'u1'],
		['entry' => 'one', 'field' => 'f', 'profile' => $profile],
	]], [['f', "Faktura\u{202E}fdp.pdf", $pdf]]);

	[$stored, $again] = $report['calls'];
	$result = $stored['result'] ?? [];

	check(\array_keys($result) === ['storage', 'path', 'mime', 'source_mime', 'size', 'original_name', 'width', 'height', 'hash', 'variants'], 'storeUpload() returns the documented result keys, in order');
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

	if (isWindows()) {
		skip('modes of uploaded files', 'POSIX permissions do not apply on Windows');
	} else {
		\clearstatcache();
		check((\fileperms($root . '/' . $result['path']) & 0777) === 0644, 'the default file_mode 0644 is applied to an upload');

		$root = $newRoot();
		$report = $request(['root' => $root, 'upload' => ['file_mode' => null], 'calls' => [['entry' => 'one', 'field' => 'f', 'profile' => $pdfProfile]]], [['f', 'x.pdf', $pdf]]);
		$path = $report['calls'][0]['result']['path'] ?? '';
		\clearstatcache();
		check($path !== '' && (\fileperms($root . '/' . $path) & 0777) === (0666 & ~\umask()), 'with file_mode null, an upload keeps the 0666 & ~umask that move_uploaded_file() applies');
	}

	// -- Upload errors reported by PHP --------------------------------------------------------

	$root = $newRoot();
	$call = ['entry' => 'one', 'field' => 'f', 'profile' => $anyProfile];

	$report = $request(['root' => $root, 'probes' => [['one', 'f', []]], 'calls' => [$call]], [['f', 'big.bin', \str_repeat('x', 2048)]]);
	check(($report['probes'][0]['error'] ?? null) === \UPLOAD_ERR_INI_SIZE && $report['probes'][0]['tmp_name'] === '', 'PHP reports UPLOAD_ERR_INI_SIZE above upload_max_filesize, and one() keeps it');
	check(($report['calls'][0]['error']['reason'] ?? null) === 'too_large' && $report['calls'][0]['error']['context'] === ['original_name' => 'big.bin', 'upload_error' => \UPLOAD_ERR_INI_SIZE], 'UPLOAD_ERR_INI_SIZE is rejected as TooLarge, with upload_error');

	$report = $request(['root' => $root, 'calls' => [$call]], [['f', 'form.bin', \str_repeat('x', 100)]], ['MAX_FILE_SIZE' => '10']);
	check(($report['calls'][0]['error']['reason'] ?? null) === 'too_large' && $report['calls'][0]['error']['context'] === ['original_name' => 'form.bin', 'upload_error' => \UPLOAD_ERR_FORM_SIZE], 'UPLOAD_ERR_FORM_SIZE (MAX_FILE_SIZE) is rejected as TooLarge');

	$report = $request(['root' => $root, 'calls' => [$call]], [['f', 'part.bin', \str_repeat('y', 300)]], [], 150);
	check(($report['calls'][0]['error']['reason'] ?? null) === 'partial' && $report['calls'][0]['error']['context'] === ['original_name' => 'part.bin', 'upload_error' => \UPLOAD_ERR_PARTIAL], 'a truncated request gives UPLOAD_ERR_PARTIAL, rejected as Partial');

	$report = $request(['root' => $root, 'probes' => [['one', 'f', []]], 'calls' => [['entry' => 'raw'] + $call]], [['f', '', '']]);
	check($report['probes'][0] === null, 'one() gives null when no file was chosen');
	check(($report['calls'][0]['error']['reason'] ?? null) === 'no_file' && $report['calls'][0]['error']['context'] === ['upload_error' => \UPLOAD_ERR_NO_FILE], 'a raw entry with UPLOAD_ERR_NO_FILE is rejected as NoFile');
	check(filesUnder($root) === [], 'upload errors write nothing');

	// -- Generic validation of uploads --------------------------------------------------------

	$root = $newRoot();

	$report = $request(['root' => $root, 'calls' => [$call]], [['f', 'empty.txt', '']]);
	check(($report['calls'][0]['error']['reason'] ?? null) === 'empty' && $report['calls'][0]['error']['context'] === ['original_name' => 'empty.txt', 'size' => 0], 'a zero-byte upload (UPLOAD_ERR_OK) is rejected as Empty');

	$report = $request(['root' => $root, 'calls' => [['profile' => ['max_bytes' => 10] + $anyProfile] + $call]], [['f', 'hundred.bin', \str_repeat('z', 100)]]);
	check(($report['calls'][0]['error']['reason'] ?? null) === 'too_large' && $report['calls'][0]['error']['context'] === ['original_name' => 'hundred.bin', 'size' => 100, 'max_bytes' => 10], 'an upload above max_bytes is rejected as TooLarge');

	$report = $request(['root' => $root, 'calls' => [['profile' => ['accept' => ['image/jpeg']] + $anyProfile] + $call, $call]], [['f', 'evil.jpg', Fixtures::php()]]);
	[$rejected, $retried] = $report['calls'];
	$path = $retried['result']['path'] ?? '';
	check(($rejected['error']['reason'] ?? null) === 'type_not_allowed' && $rejected['error']['context']['mime'] !== 'image/jpeg', 'PHP code named .jpg is rejected by an image accept list');
	check($rejected['tmp_exists_after'] === true, 'a rejection does not consume the upload');
	check($retried['tmp_exists_after'] === false && $path !== '' && \preg_match('~\.(php|phtml|jpg)$~D', $path) === 0, 'the same upload can be stored again within the request, and PHP code is never stored as .php or .jpg');
	check(filesUnder($root) === [$path], 'only the accepted upload is stored');

	// -- Provenance ----------------------------------------------------------------------------

	$root = $newRoot();
	$local = $dir . '/local.pdf';
	\file_put_contents($local, $pdf);
	$report = $request(['root' => $root, 'calls' => [[
		'entry' => ['name' => 'local.pdf', 'type' => 'application/pdf', 'tmp_name' => $local, 'error' => \UPLOAD_ERR_OK, 'size' => \strlen($pdf)],
		'profile' => $pdfProfile,
	]]], [['f', 'other.txt', "x\n"]]);
	check(
		($report['calls'][0]['error']['class'] ?? null) === \InvalidArgumentException::class && \file_get_contents($local) === $pdf && filesUnder($root) === [],
		'a path that is not an upload of the request is refused during an upload request too, and the file is untouched'
	);

	// -- Failures at and after the move ------------------------------------------------------

	if (canForceUnreadableFile()) {
		$root = $newRoot();
		$report = $request(['root' => $root, 'upload' => ['file_mode' => 0], 'calls' => [['entry' => 'one', 'field' => 'f', 'profile' => ['hash' => 'sha256'] + $pdfProfile]]], [['f', 'x.pdf', $pdf]]);
		$error = $report['calls'][0]['error'] ?? [];
		check(($error['class'] ?? null) === UploadStorageException::class && \str_contains($error['message'], 'Failed to hash'), 'a failure after the move (hashing an unreadable file) is a storage error');
		check(filesUnder($root) === [] && $report['calls'][0]['tmp_exists_after'] === false, 'compensation removes the moved upload, and nothing remains');
	} else {
		skip('upload: a failure after the move is compensated', 'file permissions are not enforced for this user or platform');
	}

	if (canForceReadOnlyDir()) {
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
	} else {
		skip('upload: a failed move_uploaded_file() leaves nothing behind', 'directories cannot be made read-only for this user or platform');
	}

	// -- Image uploads ------------------------------------------------------------------------

	$keep = ['storage' => 'files', 'accept' => ['image/jpeg'], 'max_bytes' => 1000, 'image' => ['main' => null, 'variants' => ['preview' => ['format' => 'webp', 'width' => 10]]]];
	$encode = ['storage' => 'files', 'accept' => ['image/jpeg'], 'max_bytes' => 1000, 'image' => ['main' => ['format' => 'webp']]];

	$root = $newRoot();
	$report = $request(['root' => $root, 'image' => 'scripted', 'calls' => [['entry' => 'one', 'field' => 'f', 'profile' => $keep]]], [['f', 'photo.jpg', Fixtures::jpeg()]]);
	$result = $report['calls'][0]['result'] ?? [];
	check(
		\str_ends_with($result['path'] ?? '', '.jpg') && \file_get_contents($root . '/' . $result['path']) === Fixtures::jpeg()
		&& isset($result['variants']['preview']) && $report['calls'][0]['tmp_exists_after'] === false,
		'an image upload whose original is kept is moved into storage after the image job'
	);

	$root = $newRoot();
	$report = $request(['root' => $root, 'image' => 'scripted', 'calls' => [['entry' => 'one', 'field' => 'f', 'profile' => $encode], ['entry' => 'one', 'field' => 'f', 'profile' => $encode]]], [['f', 'photo.jpg', Fixtures::jpeg()]]);
	[$first, $second] = $report['calls'];
	check(
		\str_ends_with($first['result']['path'] ?? '', '.webp') && $first['tmp_exists_after'] === true
		&& isset($second['result']['path']) && $second['result']['path'] !== $first['result']['path'],
		'a re-encoded main leaves the upload with PHP, so the same entry can be stored again within the request'
	);

	$capabilities = \extension_loaded('gd') ? (new Image(testApp(testCfg(ImageRegistry::CFG_COMMON))))->capabilities() : null;

	if ($capabilities !== null && $capabilities['decode']['jpeg'] !== null && $capabilities['encode']['webp'] !== null) {
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
	} else {
		skip('image upload with the real citomni/image', 'ext-gd with JPEG decoding and WebP encoding is not available');
	}

	// -- Batches: storeUploads() --------------------------------------------------------------

	$resultKeys = ['storage', 'path', 'mime', 'source_mime', 'size', 'original_name', 'width', 'height', 'hash', 'variants'];
	$pdfs = [$pdf . "%a\n", $pdf . "%b\n", $pdf . "%c\n"];

	$root = $newRoot();
	$report = $request(['root' => $root, 'batches' => [['field' => 'docs', 'profile' => $pdfProfile, 'subdir' => 'batch']]], [
		['docs[]', 'a.pdf', $pdfs[0]],
		['docs[]', 'evil.pdf', Fixtures::php()],
		['docs[]', '', ''],
		['docs[]', 'b.pdf', $pdfs[1]],
		['docs[]', 'empty.pdf', ''],
		['docs[]', 'big.pdf', \str_repeat('x', 2048)],
		['docs[]', 'c.pdf', $pdfs[2]],
	]);
	$batch = $report['batches'][0];
	$stored = $batch['stored'] ?? [];
	$rejected = $batch['rejected'] ?? [];
	$paths = \array_column($stored, 'path');
	\sort($paths);

	check(($batch['keys'] ?? null) === ['stored', 'rejected'], 'storeUploads() returns stored and rejected, in that order');
	check(\array_column($stored, 'original_name') === ['a.pdf', 'b.pdf', 'c.pdf'], 'partial acceptance: every accepted file is stored, in client order');
	check(
		\array_filter($stored, static fn(array $result): bool => \array_keys($result) !== $resultKeys || \preg_match('~^batch/[0-9a-f]{32}\.pdf$~D', $result['path']) !== 1) === [],
		'every stored entry is a storeUpload() result below the batch $subdir'
	);
	check(\array_map(static fn(array $result): string => (string)\file_get_contents($root . '/' . $result['path']), $stored) === $pdfs && filesUnder($root) === $paths, 'each result points to its own file, and only the accepted files are stored');
	check(\array_column($rejected, 'reason') === ['type_not_allowed', 'empty', 'too_large'], 'every rejection is collected, in client order, instead of stopping the batch');
	check(\array_unique(\array_column($rejected, 'class')) === [UploadRejectedException::class], 'the rejections are the UploadRejectedException instances storeUpload() throws');
	check(
		\array_column(\array_column($rejected, 'context'), 'original_name') === ['evil.pdf', 'empty.pdf', 'big.pdf'] && $rejected[2]['context'] === ['original_name' => 'big.pdf', 'upload_error' => \UPLOAD_ERR_INI_SIZE],
		'each rejection keeps its context, so the adapter can name the file'
	);
	check(($batch['tmp_exists_after'] ?? null) === [false, true, false, true, false, false], 'stored uploads are consumed, rejected uploads stay with PHP, and many() skipped the empty slot');
	check($report['log'] === [] && !\in_array('image', $report['touched'], true) && !\in_array('txt', $report['touched'], true), 'a plain batch resolves neither image nor txt, and logs nothing');

	$root = $newRoot();
	$report = $request(['root' => $root, 'batches' => [['field' => 'docs', 'profile' => $pdfProfile, 'discard' => true]]], [
		['docs[]', 'a.pdf', $pdfs[0]],
		['docs[]', 'evil.pdf', Fixtures::php()],
		['docs[]', 'b.pdf', $pdfs[1]],
	]);
	$batch = $report['batches'][0];
	check(\count($batch['stored'] ?? []) === 2 && \count($batch['rejected'] ?? []) === 1 && ($batch['discarded'] ?? null) === true, 'all-or-nothing on top: cleanupStored() removes the stored files of a batch with a rejection and reports true');
	check(filesUnder($root) === [] && $report['log'] === [], 'nothing of the discarded batch remains, and nothing is logged');

	$root = $newRoot();
	$forged = $dir . '/forged.pdf';
	\file_put_contents($forged, $pdf);
	$report = $request(['root' => $root, 'batches' => [[
		'field' => 'docs',
		'profile' => $pdfProfile,
		'insert' => [[2, ['name' => 'forged.pdf', 'type' => 'application/pdf', 'tmp_name' => $forged, 'error' => \UPLOAD_ERR_OK, 'size' => \strlen($pdf)]]],
	]]], [['docs[]', 'a.pdf', $pdfs[0]], ['docs[]', 'b.pdf', $pdfs[1]], ['docs[]', 'c.pdf', $pdfs[2]]]);
	$batch = $report['batches'][0];
	check(($batch['error']['class'] ?? null) === \InvalidArgumentException::class && !isset($batch['stored']), 'a fault part-way through a batch propagates: here an entry that was not uploaded in the request');
	check(filesUnder($root) === [] && ($batch['tmp_exists_after'] ?? null) === [false, false, true, true], 'the files of the entries stored before the fault are removed, and later entries are never attempted');
	check($report['log'] === [] && \file_get_contents($forged) === $pdf, 'the compensation succeeds silently, and the file of the forged entry is untouched');

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

	$root = $newRoot();
	$photo = Fixtures::jpeg();
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

	if (isWindows() || !canForceUndeletableFile()) {
		skip('batch compensation with files the filesystem refuses to remove', 'needs a non-root POSIX user');
	} else {
		$root = $newRoot();

		try {
			$report = $request(['root' => $root, 'image' => 'scripted', 'fail_save' => 2, 'lock_on_fail' => true, 'batches' => [['field' => 'photos', 'profile' => $keep, 'subdir' => 'gallery']]], [
				['photos[]', 'one.jpg', $photo],
				['photos[]', 'two.jpg', $photo],
			]);
		} finally {
			\chmod($root . '/gallery', 0755);
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
	}
} finally {
	$server->stop();
}

done('http_upload_test');
