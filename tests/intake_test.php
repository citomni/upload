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
 * Intake contract without HTTP: UploadedFiles normalization of the $_FILES
 * shapes PHP produces (single, name[], keyed, deeper nesting selected by
 * $path, full_path, empty slots) and of malformed or shape-confused entries;
 * storeUpload() up to and including its provenance check (developer and
 * configuration errors first, every UPLOAD_ERR_* code, no fallback for files
 * that were not uploaded); storeUploads() in the same scope (the same checks
 * first, also for an empty list, collected rejections, faults that propagate,
 * and #[\NoDiscard]); rejectionMessage() in both forms, with and without the
 * txt service; and the shipped language files.
 *
 * is_uploaded_file() is false outside an HTTP upload, so everything past the
 * provenance check is covered by http_upload_test.php.
 */

require __DIR__ . '/bootstrap.php';

use CitOmni\Upload\Enum\UploadRejection;
use CitOmni\Upload\Exception\UploadConfigException;
use CitOmni\Upload\Exception\UploadRejectedException;
use CitOmni\Upload\Exception\UploadStorageException;
use CitOmni\Upload\Service\Upload;
use CitOmni\Upload\Tests\Support\Fixtures;
use CitOmni\Upload\Tests\Support\RecordingTxt;
use CitOmni\Upload\Util\UploadedFiles;

// -- UploadedFiles::one() ------------------------------------------------------------------

$single = ['name' => 'a.txt', 'full_path' => 'a.txt', 'type' => 'text/plain', 'tmp_name' => '/tmp/phpA', 'error' => \UPLOAD_ERR_OK, 'size' => 6];
$entryA = ['name' => 'a.txt', 'type' => 'text/plain', 'tmp_name' => '/tmp/phpA', 'error' => \UPLOAD_ERR_OK, 'size' => 6];

check(UploadedFiles::one($single) === $entryA, 'one() keeps the five PHP keys in order and drops full_path');
check(UploadedFiles::one(\array_diff_key($single, ['full_path' => true])) === $entryA, 'one() does not need full_path');
check(UploadedFiles::one($single + ['extra' => ['x']]) === $entryA, 'one() ignores extra keys');
check(UploadedFiles::one(['name' => '', 'type' => '', 'tmp_name' => '', 'error' => \UPLOAD_ERR_NO_FILE, 'size' => 0]) === null, 'one() returns null when no file was sent');

foreach ([\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE, \UPLOAD_ERR_PARTIAL, \UPLOAD_ERR_NO_TMP_DIR, \UPLOAD_ERR_CANT_WRITE, \UPLOAD_ERR_EXTENSION, 99] as $error) {
	check(
		UploadedFiles::one(['tmp_name' => '', 'error' => $error, 'size' => 0] + $single) === ['name' => 'a.txt', 'type' => 'text/plain', 'tmp_name' => '', 'error' => $error, 'size' => 0],
		"one() keeps upload error {$error} for storeUpload() to map"
	);
}

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

// -- UploadedFiles::many() -----------------------------------------------------------------

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

check(UploadedFiles::many($multi) === [$entryB, $entryC], 'many() lists name[] slots, skips UPLOAD_ERR_NO_FILE, and keeps other errors');

$keyed = [
	'name' => ['front' => 'f.jpg', 'back' => 'b.jpg'],
	'type' => ['front' => 'image/jpeg', 'back' => 'image/jpeg'],
	'tmp_name' => ['front' => '/tmp/phpF', 'back' => '/tmp/phpK'],
	'error' => ['front' => 0, 'back' => 0],
	'size' => ['front' => 1, 'back' => 2],
];
$listed = UploadedFiles::many($keyed);
check(\array_is_list($listed) && \array_column($listed, 'name') === ['f.jpg', 'b.jpg'], 'many() lists a keyed field in client order and drops the keys');

$nested = $multi;
$nested['name'][0] = ['b.pdf'];
check(UploadedFiles::many($nested) === [$entryC], 'many() skips a slot nested one level deeper');

$gap = $multi;
unset($gap['error'][2]);
check(UploadedFiles::many($gap) === [$entryB], 'many() skips a slot missing from one of the fields');

$typed = $multi;
$typed['size'][0] = '15';
check(UploadedFiles::many($typed) === [$entryC], 'many() skips a slot with a value of the wrong type');

$mixed = $multi;
$mixed['size'] = 15;
check(UploadedFiles::many($mixed) === [], 'many() returns [] when one of the fields is not an array');
check(UploadedFiles::many($single) === [], 'many() returns [] for a single-file field');

foreach (['null' => null, 'a string' => 'files', 'an empty array' => [], 'no slots' => ['name' => []] + $multi] as $why => $empty) {
	check(UploadedFiles::many($empty) === [], "many() returns [] for {$why}");
}

// -- $path selection -------------------------------------------------------------------------

$deep = [
	'name' => ['a' => ['b' => 'c.txt']],
	'full_path' => ['a' => ['b' => 'c.txt']],
	'type' => ['a' => ['b' => 'text/plain']],
	'tmp_name' => ['a' => ['b' => '/tmp/phpC']],
	'error' => ['a' => ['b' => 0]],
	'size' => ['a' => ['b' => 5]],
];
$entryDeep = ['name' => 'c.txt', 'type' => 'text/plain', 'tmp_name' => '/tmp/phpC', 'error' => 0, 'size' => 5];

check(UploadedFiles::one($deep, 'a', 'b') === $entryDeep, 'one(): the full path selects a nested field ("doc[a][b]")');
check(UploadedFiles::one($deep) === null && UploadedFiles::one($deep, 'a') === null, 'one(): without the full path a nested field is malformed');
check(UploadedFiles::one($deep, 'x', 'b') === null && UploadedFiles::one($deep, 'a', 'b', 'c') === null, 'one(): an unknown or too deep path gives null');
check(UploadedFiles::many($deep) === [] && UploadedFiles::many($deep, 'a') === [$entryDeep], 'many(): the path selects the level whose slots are files');

$gallery = [
	'name' => ['photos' => ['p1.jpg', 'p2.jpg']],
	'type' => ['photos' => ['image/jpeg', 'image/jpeg']],
	'tmp_name' => ['photos' => ['/tmp/php1', '/tmp/php2']],
	'error' => ['photos' => [0, 0]],
	'size' => ['photos' => [10, 20]],
];
check(\array_column(UploadedFiles::many($gallery, 'photos'), 'name') === ['p1.jpg', 'p2.jpg'], 'many(): "gallery[photos][]" is selected with the path photos');
check(UploadedFiles::one($keyed, 'back') === ['name' => 'b.jpg', 'type' => 'image/jpeg', 'tmp_name' => '/tmp/phpK', 'error' => 0, 'size' => 2], 'one(): a keyed slot is selected by its key');
check(UploadedFiles::one($multi, 0) === $entryB && UploadedFiles::one($multi, '0') === $entryB, 'one(): a list slot is selected by int or numeric string');
check(UploadedFiles::one($multi, 1) === null && UploadedFiles::one($multi, 7) === null, 'one(): an empty or missing slot gives null');

// -- storeUpload(): upload error codes ------------------------------------------------------

$dir = tempDir();
$root = $dir . '/root';
\mkdir($root);

$cfg = testCfg(['upload' => ['storages' => [
	'files' => ['root' => $root, 'web_path' => null],
	'gone' => ['root' => $dir . '/gone', 'web_path' => null],
]]]);
$app = testApp($cfg);
$upload = new Upload($app);
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

foreach ($rejections as $error => [$reason, $context]) {
	$e = expectThrows(UploadRejectedException::class, static fn() => $upload->storeUpload($entry($error), $profile), "upload error {$error} is a rejection");
	check($e->reason === $reason && $e->context === $context, "upload error {$error} maps to {$reason->name}, with upload_error in the context");
}

$faults = [
	\UPLOAD_ERR_NO_TMP_DIR => 'UPLOAD_ERR_NO_TMP_DIR',
	\UPLOAD_ERR_CANT_WRITE => 'UPLOAD_ERR_CANT_WRITE',
	\UPLOAD_ERR_EXTENSION => 'UPLOAD_ERR_EXTENSION',
	5 => 'unknown code',
	99 => 'unknown code',
	-1 => 'unknown code',
];

foreach ($faults as $error => $label) {
	$e = expectThrows(UploadStorageException::class, static fn() => $upload->storeUpload($entry($error), $profile), "upload error {$error} is a server fault");
	check(\str_contains($e->getMessage(), "upload error {$error} ({$label})"), "the message names upload error {$error} as {$label}");
}

$e = expectThrows(UploadRejectedException::class, static fn() => $upload->storeUpload($entry(\UPLOAD_ERR_PARTIAL, '', "C:\\fakepath\\inv\u{202E}x.pdf"), $profile), 'a rejection for a client name with a bidi control');
check($e->context['original_name'] === 'invx.pdf', 'the original name in the context is sanitized');

// -- storeUpload(): developer and configuration errors come first ---------------------------

expectThrows(UploadConfigException::class, static fn() => $upload->storeUpload($entry(\UPLOAD_ERR_NO_FILE), ['storage' => 'nowhere'] + $profile), 'an invalid profile is reported before the upload error');
expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeUpload($entry(\UPLOAD_ERR_NO_FILE), $profile, '../x'), 'an invalid $subdir is reported before the upload error');
expectThrows(UploadConfigException::class, static fn() => $upload->storeUpload($entry(\UPLOAD_ERR_NO_FILE), ['storage' => 'gone'] + $profile), 'a missing storage root is reported before the upload error');

$badModes = new Upload(testApp(testCfg(['upload' => ['storages' => ['files' => ['root' => $root, 'web_path' => null]], 'file_mode' => '0644']])));
expectThrows(UploadConfigException::class, static fn() => $badModes->storeUpload($entry(\UPLOAD_ERR_NO_FILE), $profile), 'an invalid file_mode is reported before the upload error');

$badFiles = [
	'no keys' => [],
	'no tmp_name' => \array_diff_key($entry(\UPLOAD_ERR_OK), ['tmp_name' => true]),
	'no name' => \array_diff_key($entry(\UPLOAD_ERR_OK), ['name' => true]),
	'a string error' => ['error' => '4'] + $entry(\UPLOAD_ERR_NO_FILE),
	'a null error' => ['error' => null] + $entry(\UPLOAD_ERR_NO_FILE),
];

foreach ($badFiles as $why => $file) {
	expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeUpload($file, $profile), "a \$file with {$why} is a developer error");
}

$raw = ['name' => '', 'full_path' => '', 'type' => '', 'tmp_name' => '', 'error' => \UPLOAD_ERR_NO_FILE, 'size' => 0];
$e = expectThrows(UploadRejectedException::class, static fn() => $upload->storeUpload($raw, $profile), 'a raw single-file $_FILES entry is accepted');
check($e->reason === UploadRejection::NoFile, 'the raw entry reaches the error mapping');

$manipulated = ['name' => ['a.pdf'], 'full_path' => ['a.pdf'], 'type' => ['application/pdf'], 'tmp_name' => ['/tmp/phpX'], 'error' => [0], 'size' => [1]];
$e = expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeUpload($manipulated, $profile), 'a raw entry that the client sent as name[] is a developer error');
check(\str_contains($e->getMessage(), 'UploadedFiles::one()') && UploadedFiles::one($manipulated) === null, 'the message points to UploadedFiles::one(), which returns null for the same entry');

// -- storeUpload(): provenance --------------------------------------------------------------

$local = $dir . '/local.pdf';
\file_put_contents($local, Fixtures::pdf());

$e = expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeUpload($entry(\UPLOAD_ERR_OK, $local, 'local.pdf'), $profile), 'a local file is not an upload: there is no fallback to copy()');
check(\str_contains($e->getMessage(), 'storeLocal()') && \file_get_contents($local) === Fixtures::pdf(), 'the message points to storeLocal(), and the file is untouched');
expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeUpload($entry(\UPLOAD_ERR_OK), $profile), 'UPLOAD_ERR_OK with an empty tmp_name is a developer error');

check(\scandir($root) === ['.', '..'], 'storeUpload() wrote nothing outside an HTTP upload');
check(!\in_array('image', $app->touched, true) && !\in_array('txt', $app->touched, true), 'storeUpload() resolves neither image nor txt');

// -- storeUploads(): the same checks first, rejections collected ----------------------------

check($upload->storeUploads([], $profile) === ['stored' => [], 'rejected' => []], 'storeUploads() of an empty list stores and rejects nothing');
expectThrows(UploadConfigException::class, static fn() => $upload->storeUploads([], 'undefined'), 'an undefined profile is reported for an empty list too');
expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeUploads([], $profile, '../x'), 'an invalid $subdir is reported for an empty list too');
expectThrows(UploadConfigException::class, static fn() => $upload->storeUploads([], ['storage' => 'gone'] + $profile), 'a missing storage root is reported for an empty list too');
expectThrows(UploadConfigException::class, static fn() => $badModes->storeUploads([], $profile), 'an invalid file_mode is reported for an empty list too');

$batch = $upload->storeUploads([$entry(\UPLOAD_ERR_PARTIAL, '', 'a.pdf'), $entry(\UPLOAD_ERR_INI_SIZE, '', 'b.pdf'), $entry(\UPLOAD_ERR_NO_FILE)], $profile);
check(
	$batch['stored'] === [] && \array_map(static fn(UploadRejectedException $e): UploadRejection => $e->reason, $batch['rejected']) === [UploadRejection::Partial, UploadRejection::TooLarge, UploadRejection::NoFile],
	'storeUploads() collects every rejection, in order, instead of stopping at the first'
);
check($batch['rejected'][0]->context === ['original_name' => 'a.pdf', 'upload_error' => \UPLOAD_ERR_PARTIAL], 'a collected rejection is the exception storeUpload() throws, with its context');

$e = expectThrows(UploadStorageException::class, static fn() => $upload->storeUploads([$entry(\UPLOAD_ERR_PARTIAL), $entry(\UPLOAD_ERR_CANT_WRITE), $entry(\UPLOAD_ERR_PARTIAL)], $profile), 'a server fault in a batch is not a rejection');
check(\str_contains($e->getMessage(), 'UPLOAD_ERR_CANT_WRITE'), 'the server fault propagates unchanged and ends the batch');
expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeUploads([$entry(\UPLOAD_ERR_PARTIAL), []], $profile), 'a malformed entry in a batch is a developer error');
expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeUploads([$entry(\UPLOAD_ERR_PARTIAL), null], $profile), 'an entry that is not an array is the same developer error, not a TypeError');
expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeUploads([[]], ['storage' => 'gone'] + $profile), 'every entry is checked before the storage root, in storeUpload()\'s order');
$e = expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeUploads($entry(\UPLOAD_ERR_OK, $local, 'local.pdf'), $profile), 'a single entry instead of a list');
check(\str_contains($e->getMessage(), 'UploadedFiles::many()') && \str_contains($e->getMessage(), 'storeUpload()'), 'the message points to UploadedFiles::many() and, for one entry, to storeUpload()');
expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeUploads([$entry(\UPLOAD_ERR_OK, $local, 'local.pdf')], $profile), 'a local file in a batch is not an upload');
check(\scandir($root) === ['.', '..'] && \file_get_contents($local) === Fixtures::pdf(), 'storeUploads() wrote nothing outside an HTTP upload, and the local file is untouched');

$e = expectThrows(\ErrorException::class, static function () use ($upload, $profile): void {
	$upload->storeUploads([], $profile);
}, 'ignoring the result of storeUploads()');
check(\str_contains($e->getMessage(), 'storeUploads()'), 'storeUploads() is #[\\NoDiscard]: ignoring its result raises the warning');
check(!\in_array('image', $app->touched, true) && !\in_array('txt', $app->touched, true), 'storeUploads() resolves neither image nor txt');

// -- rejectionMessage() ---------------------------------------------------------------------

$app = testApp($cfg, ['txt' => null]);
$upload = new Upload($app);

foreach (UploadRejection::cases() as $reason) {
	check(
		$upload->rejectionMessage($reason) === $reason->fallbackMessage() && $upload->rejectionMessage(new UploadRejectedException($reason)) === $reason->fallbackMessage(),
		"without txt, both forms give the English fallback for {$reason->name}"
	);
}

check(!\in_array('txt', $app->touched, true), 'without a registered txt service, txt is never resolved');

$txt = new RecordingTxt();
$txt->texts = ['too_large' => 'Filen er for stor.'];
$upload = new Upload(testApp($cfg, ['txt' => $txt]));

check($upload->rejectionMessage(UploadRejection::TooLarge) === 'Filen er for stor.', 'the reason form is translated through txt');
check($upload->rejectionMessage(new UploadRejectedException(UploadRejection::TooLarge, ['size' => 11, 'max_bytes' => 10])) === 'Filen er for stor.', 'the exception form is translated through txt');

$expectedCall = ['key' => 'too_large', 'file' => 'upload', 'layer' => 'citomni/upload', 'default' => 'The file is too large.', 'vars' => []];
check($txt->calls === [$expectedCall, $expectedCall], 'txt gets the reason value, the upload file, the citomni/upload layer, the fallback as default, and no vars');
check($upload->rejectionMessage(UploadRejection::Empty) === 'The file is empty.', 'a key that txt cannot resolve gives the fallback');

// -- Language files -------------------------------------------------------------------------

$languages = \dirname(__DIR__) . '/language';
$en = require $languages . '/en/upload.php';
$da = require $languages . '/da/upload.php';
$keys = \array_map(static fn(UploadRejection $reason): string => $reason->value, UploadRejection::cases());

check(\is_array($en) && \array_keys($en) === $keys, 'language/en/upload.php has one key per UploadRejection value, in enum order');
check(\is_array($da) && \array_keys($da) === $keys, 'language/da/upload.php has one key per UploadRejection value, in enum order');

foreach (UploadRejection::cases() as $reason) {
	check($en[$reason->value] === $reason->fallbackMessage(), "the English text for {$reason->value} is the fallback message");
}

check($da === [
	'no_file' => 'Der blev ikke uploadet nogen fil.',
	'partial' => 'Filen blev kun delvist uploadet. Prøv igen.',
	'too_large' => 'Filen er for stor.',
	'empty' => 'Filen er tom.',
	'type_not_allowed' => 'Denne filtype er ikke tilladt.',
	'invalid_image' => 'Billedet kunne ikke behandles. Det kan være beskadiget, for stort, animeret eller i et format, der ikke understøttes.',
], 'the Danish texts are exactly the shipped ones');

foreach ([...\array_values($en), ...\array_values($da)] as $text) {
	check(\is_string($text) && $text !== '' && !\str_contains($text, '%'), 'texts are non-empty and have no placeholders: ' . $text);
}

done('intake_test');
