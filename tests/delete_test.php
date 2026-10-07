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
 * Deletion contract: strict delete() and deleteWithVariants() versus
 * best-effort cleanup(), cleanupWithVariants(), and cleanupStored(). Path
 * validation before any filesystem call, storage and profile errors, missing
 * files, files that cannot be deleted, ordering (variants before the main
 * file), stopping at the first failure versus attempting every file, cleanup
 * logging, cleanup in a finally block that must not mask the exception in
 * flight, and cleanupStored()'s validation of store results.
 *
 * Ordering and failures run on MemoryStorage, an in-memory storage behind a
 * stream wrapper that records every unlink and refuses chosen files, so they
 * are checked on every platform and also as root. The real filesystem runs end
 * to end: stores through a scripted citomni/image double, then deletion of
 * exactly what they wrote. Refused unlinks on a real filesystem need a
 * non-root POSIX user or Windows.
 */

require __DIR__ . '/bootstrap.php';

use CitOmni\Image\Enum\ImageFormat;
use CitOmni\Upload\Exception\UploadConfigException;
use CitOmni\Upload\Exception\UploadStorageException;
use CitOmni\Upload\Service\Upload;
use CitOmni\Upload\Tests\Support\Fixtures;
use CitOmni\Upload\Tests\Support\MemoryStorage;
use CitOmni\Upload\Tests\Support\RecordingLog;
use CitOmni\Upload\Tests\Support\ScriptedImage;

$dir = tempDir();
$name = \str_repeat('ab', 16);

$avatar = [
	'storage' => 'files',
	'accept' => ['image/jpeg'],
	'max_bytes' => 1_000_000,
	'image' => [
		'main' => ['format' => 'webp'],
		'variants' => [
			'thumb' => ['format' => 'webp'],
			'small' => ['format' => ImageFormat::Jpeg],
			'scan' => ['format' => 'tiff'],
		],
	],
];
$archive = [
	'storage' => 'files',
	'accept' => ['image/jpeg'],
	'max_bytes' => 1_000_000,
	'image' => ['main' => null, 'variants' => ['preview' => ['format' => 'webp']]],
];
$attachment = ['storage' => 'files', 'accept' => ['application/pdf'], 'max_bytes' => 1_000_000];

$main = "u1/{$name}.webp";
$variantFiles = ["u1/{$name}-thumb.webp", "u1/{$name}-small.jpg", "u1/{$name}-scan.tif"];

/**
 * Upload over a fresh in-memory storage named "files".
 *
 * @param array<string, object|null> $services Service doubles; see testApp().
 * @return array{0: Upload, 1: string, 2: ?object, 3: object} Upload, root URL, log, app.
 */
$memory = static function (array $services = [], array $profiles = []): array {
	MemoryStorage::reset();
	$root = MemoryStorage::root('files');
	$services += ['log' => new RecordingLog()];
	$app = testApp(testCfg(['upload' => ['storages' => ['files' => ['root' => $root, 'web_path' => null]], 'profiles' => $profiles]]), $services);

	return [new Upload($app), $root, $services['log'], $app];
};

$urls = static fn(string $root, array $paths): array => \array_map(static fn(string $path): string => "{$root}/{$path}", $paths);

$putAll = static function (string $root, array $paths): void {
	foreach ($paths as $path) {
		MemoryStorage::put("{$root}/{$path}");
	}
};


// -- Paths are validated before the storage is touched -----------------------------

[$upload, $root] = $memory();
MemoryStorage::put("{$root}/a.jpg");

foreach (['', '/a.jpg', 'a/../b.jpg', 'a//b.jpg', '.tmp-x.jpg', 'a\\b.jpg', 'a b.jpg', 'a/'] as $bad) {
	$label = \var_export($bad, true);
	expectThrows(\InvalidArgumentException::class, static fn() => $upload->delete('files', 'a.jpg', $bad), "delete() rejects {$label}");
	expectThrows(\InvalidArgumentException::class, static fn() => $upload->cleanup('files', 'a.jpg', $bad), "cleanup() rejects {$label}");
	expectThrows(\InvalidArgumentException::class, static fn() => $upload->deleteWithVariants($bad, $avatar), "deleteWithVariants() rejects {$label}");
	expectThrows(\InvalidArgumentException::class, static fn() => $upload->cleanupWithVariants($bad, $avatar), "cleanupWithVariants() rejects {$label}");
}

check(MemoryStorage::exists("{$root}/a.jpg") && MemoryStorage::$operations === [], 'every path is validated before the storage is touched: one invalid path deletes nothing');

$e = expectThrows(\InvalidArgumentException::class, static fn() => $upload->delete('files', ''), 'delete() of ""');
check(\str_contains($e->getMessage(), 'must not be empty'), 'an empty path is rejected because it would address the storage root');


// -- Storage and profile errors ----------------------------------------------------

[$upload, $root] = $memory();
expectThrows(UploadConfigException::class, static fn() => $upload->delete('nope', 'a.jpg'), 'delete() on an undefined storage');
expectThrows(UploadConfigException::class, static fn() => $upload->cleanup('nope', 'a.jpg'), 'cleanup() on an undefined storage');
expectThrows(UploadConfigException::class, static fn() => $upload->deleteWithVariants($main, 'undefined'), 'deleteWithVariants() with an undefined profile');
expectThrows(UploadConfigException::class, static fn() => $upload->cleanupWithVariants($main, ['storage' => 'nope'] + $attachment), 'cleanupWithVariants() with a profile on an undefined storage');

$absent = new Upload(testApp(testCfg(['upload' => ['storages' => ['gone' => ['root' => $dir . '/no-such-root', 'web_path' => null]]]])));
$e = expectThrows(UploadConfigException::class, static fn() => $absent->delete('gone', 'a.jpg'), 'delete() on a storage whose root does not exist');
check(\str_contains($e->getMessage(), 'does not exist'), 'the message says the root does not exist');
expectThrows(UploadConfigException::class, static fn() => $absent->cleanup('gone', 'a.jpg'), 'cleanup() on a storage whose root does not exist');
expectThrows(UploadConfigException::class, static fn() => $absent->deleteWithVariants('a.jpg', ['storage' => 'gone'] + $attachment), 'deleteWithVariants() on a storage whose root does not exist');
expectThrows(UploadConfigException::class, static fn() => $absent->cleanupWithVariants('a.jpg', ['storage' => 'gone'] + $attachment), 'cleanupWithVariants() on a storage whose root does not exist');
check(!\file_exists($dir . '/no-such-root'), 'a missing root is not created');

[$upload, $root, , $app] = $memory(['image' => null]);
$putAll($root, [...$variantFiles, $main]);
expectThrows(UploadConfigException::class, static fn() => $upload->deleteWithVariants($main, $avatar), 'deleteWithVariants() with an image profile but no image service');
expectThrows(UploadConfigException::class, static fn() => $upload->cleanupWithVariants($main, $avatar), 'cleanupWithVariants() with an image profile but no image service');
check(MemoryStorage::unlinked() === [], 'a misconfigured image profile deletes nothing');
$upload->delete('files', $main);
check(!MemoryStorage::exists("{$root}/{$main}"), 'delete() by storage needs no image service');


// -- delete(): strict --------------------------------------------------------------

[$upload, $root, $log, $app] = $memory();
$putAll($root, ['a.jpg', 'b/c.pdf', 'd.txt']);
$upload->delete('files', 'a.jpg', 'missing.jpg', 'b/c.pdf', 'a.jpg');
check(MemoryStorage::unlinked() === $urls($root, ['a.jpg', 'missing.jpg', 'b/c.pdf', 'a.jpg']), 'delete() removes the paths in the given order');
check(!MemoryStorage::exists("{$root}/a.jpg") && !MemoryStorage::exists("{$root}/b/c.pdf") && MemoryStorage::exists("{$root}/d.txt"), 'delete() removes exactly the given files; a missing or repeated path counts as deleted');
$upload->delete('files');
check(\count(MemoryStorage::unlinked()) === 4, 'delete() without paths does nothing');
check($log->entries === [] && !\in_array('image', $app->touched, true) && !\in_array('log', $app->touched, true), 'delete() logs nothing and touches neither image nor log');

[$upload, $root] = $memory();
$putAll($root, ['a.jpg', 'b.jpg', 'c.jpg']);
MemoryStorage::$undeletable["{$root}/b.jpg"] = true;
$e = expectThrows(UploadStorageException::class, static fn() => $upload->delete('files', 'a.jpg', 'b.jpg', 'c.jpg'), 'delete() with a file that cannot be deleted');
check(\str_contains($e->getMessage(), 'b.jpg') && \str_contains($e->getMessage(), "'files'") && \str_contains($e->getMessage(), 'Permission denied'), 'the exception names the file, the storage, and the reason');
check(MemoryStorage::unlinked() === $urls($root, ['a.jpg', 'b.jpg']) && !MemoryStorage::exists("{$root}/a.jpg") && MemoryStorage::exists("{$root}/b.jpg") && MemoryStorage::exists("{$root}/c.jpg"), 'delete() stops at the first file it cannot delete: later paths are not attempted');

MemoryStorage::put("{$root}/dir/x.jpg");
$e = expectThrows(UploadStorageException::class, static fn() => $upload->delete('files', 'dir'), 'delete() of a path that names a directory');
check(\str_contains($e->getMessage(), 'Is a directory') && MemoryStorage::exists("{$root}/dir/x.jpg"), 'a directory fails like any file that cannot be deleted, and its contents stay');


[$upload, $root] = $memory();
$upload->delete('files', 'x.jpg');
$upload->delete('files', 'y.jpg');
check(\count(\array_filter(MemoryStorage::$operations, static fn(array $op): bool => $op === ['stat', $root])) === 1, 'the storage root is checked on disk once per service, then memoized');

// -- cleanup(): best effort --------------------------------------------------------

[$upload, $root, $log, $app] = $memory();
$putAll($root, ['a.jpg', 'b.jpg', 'c.jpg']);
MemoryStorage::$undeletable["{$root}/b.jpg"] = true;
$gone = $upload->cleanup('files', 'a.jpg', 'b.jpg', 'missing.jpg', 'c.jpg');
check($gone === false, 'cleanup() reports false when a file remains');
check(MemoryStorage::unlinked() === $urls($root, ['a.jpg', 'b.jpg', 'missing.jpg', 'c.jpg']) && MemoryStorage::exists("{$root}/b.jpg") && !MemoryStorage::exists("{$root}/a.jpg") && !MemoryStorage::exists("{$root}/c.jpg"), 'cleanup() attempts every path despite a failure');
check(\count($log->entries) === 1, 'exactly the failure is logged');
$entry = $log->entries[0];
check($entry['file'] === 'upload.jsonl' && $entry['category'] === 'cleanup' && $entry['message'] === 'Failed to remove a stored file during cleanup.', 'the failure goes to upload.jsonl under cleanup');
check(\array_keys($entry['context']) === ['storage', 'path', 'stored_path', 'error'], 'the log context holds storage, path, stored_path, and error, in that order');
check($entry['context']['storage'] === 'files' && $entry['context']['path'] === "{$root}/b.jpg" && $entry['context']['stored_path'] === 'b.jpg' && \str_contains($entry['context']['error'], 'Permission denied'), 'path is absolute, stored_path is the given relative path, and error is the unlink warning');

check($upload->cleanup('files', 'missing.jpg') === true && $upload->cleanup('files') === true, 'cleanup() of a missing file, or of nothing, is true');
MemoryStorage::put("{$root}/e.jpg");
check($upload->cleanup('files', 'e.jpg', 'e.jpg') === true && !MemoryStorage::exists("{$root}/e.jpg"), 'cleanup() of an existing file, repeated, is true');
check(!\in_array('image', $app->touched, true), 'cleanup() never touches image');

$throwing = new RecordingLog();
$throwing->failWith = new \RuntimeException('log is down');
[$upload, $root] = $memory(['log' => $throwing]);
MemoryStorage::put("{$root}/b.jpg");
MemoryStorage::$undeletable["{$root}/b.jpg"] = true;
check($upload->cleanup('files', 'b.jpg') === false && \count($throwing->entries) === 1, 'a throwing log neither breaks cleanup() nor changes its result');

[$upload, $root, , $app] = $memory(['log' => null]);
MemoryStorage::put("{$root}/b.jpg");
MemoryStorage::$undeletable["{$root}/b.jpg"] = true;
check($upload->cleanup('files', 'b.jpg') === false && !\in_array('log', $app->touched, true), 'without a log service cleanup() still reports false');

[$upload, $root] = $memory(['log' => $throwing]);
MemoryStorage::put("{$root}/b.jpg");
MemoryStorage::$undeletable["{$root}/b.jpg"] = true;
$e = expectThrows(\DomainException::class, static function () use ($upload): void {
	try {
		throw new \DomainException('in flight');
	} finally {
		$upload->cleanup('files', 'b.jpg');
	}
}, 'cleanup() in a finally block while an exception is in flight');
check($e->getMessage() === 'in flight' && $e->getPrevious() === null, 'cleanup() in a finally block does not mask the exception in flight, even with a failing unlink and a throwing log');


// -- *WithVariants(): order, derivation, and failures ------------------------------

[$upload, $root, , $app] = $memory();
$putAll($root, [...$variantFiles, $main]);
$upload->deleteWithVariants($main, $avatar);
check(MemoryStorage::unlinked() === $urls($root, [...$variantFiles, $main]), 'deleteWithVariants() removes the variants in profile order, then the main file');
check(\array_filter($urls($root, [...$variantFiles, $main]), MemoryStorage::exists(...)) === [], 'nothing remains; webp, jpeg, and tiff variants derive .webp, .jpg, and .tif');
check(!\in_array('image', $app->touched, true), 'variant paths are derived without resolving the image service');

[$upload, $root] = $memory([], ['archive' => $archive]);
$putAll($root, ["u2/{$name}.jpg", "u2/{$name}-preview.webp"]);
check($upload->cleanupWithVariants("u2/{$name}.jpg", 'archive') === true, 'cleanupWithVariants() with a named profile and a kept original reports true');
check(MemoryStorage::unlinked() === $urls($root, ["u2/{$name}-preview.webp", "u2/{$name}.jpg"]), 'for a kept original the variant extension follows the variant format, not the main file');

[$upload, $root] = $memory();
$putAll($root, [...$variantFiles, $main]);
MemoryStorage::$undeletable["{$root}/{$variantFiles[1]}"] = true;
$e = expectThrows(UploadStorageException::class, static fn() => $upload->deleteWithVariants($main, $avatar), 'deleteWithVariants() with a variant that cannot be deleted');
check(\str_contains($e->getMessage(), $variantFiles[1]), 'the exception names the variant');
check(MemoryStorage::unlinked() === $urls($root, [$variantFiles[0], $variantFiles[1]]), 'strict deletion stops at the failing variant');
check(MemoryStorage::exists("{$root}/{$main}") && MemoryStorage::exists("{$root}/{$variantFiles[1]}") && MemoryStorage::exists("{$root}/{$variantFiles[2]}"), 'the main file stays, so a retry derives the same paths');
MemoryStorage::$undeletable = [];
$upload->deleteWithVariants($main, $avatar);
check(\array_filter($urls($root, [...$variantFiles, $main]), MemoryStorage::exists(...)) === [], 'a retry completes the deletion');

[$upload, $root, $log] = $memory();
$putAll($root, [...$variantFiles, $main]);
MemoryStorage::$undeletable["{$root}/{$variantFiles[1]}"] = true;
check($upload->cleanupWithVariants($main, $avatar) === false, 'cleanupWithVariants() reports false when a variant remains');
check(MemoryStorage::unlinked() === $urls($root, [...$variantFiles, $main]), 'cleanupWithVariants() attempts every file, the main file included');
check(\array_values(\array_filter($urls($root, [...$variantFiles, $main]), MemoryStorage::exists(...))) === ["{$root}/{$variantFiles[1]}"], 'only the refused variant remains');
check(\count($log->entries) === 1 && $log->entries[0]['context']['stored_path'] === $variantFiles[1] && $log->entries[0]['context']['path'] === "{$root}/{$variantFiles[1]}", 'a refused variant is logged by its own storage-relative path');

[$upload, $root] = $memory();
$putAll($root, ['doc.pdf', 'doc-thumb.webp']);
$upload->deleteWithVariants('doc.pdf', $attachment);
check(MemoryStorage::unlinked() === ["{$root}/doc.pdf"] && MemoryStorage::exists("{$root}/doc-thumb.webp"), 'a profile without image block deletes only the main file');


// -- cleanupStored(): exactly the files a store result lists -----------------------

/**
 * A store result shaped like storeUpload()'s.
 *
 * @param array<string, string> $variants Variant key => storage-relative path.
 */
$storeResult = static fn(string $path, array $variants = [], string $storage = 'files'): array => [
	'storage' => $storage,
	'path' => $path,
	'mime' => 'image/webp',
	'source_mime' => 'image/jpeg',
	'size' => 1,
	'original_name' => 'photo.jpg',
	'width' => 1,
	'height' => 1,
	'hash' => null,
	'variants' => \array_map(static fn(string $variant): array => ['path' => $variant, 'mime' => 'image/webp', 'size' => 1, 'width' => 1, 'height' => 1], $variants),
];

[$upload, $root, $log, $app] = $memory();
check($upload->cleanupStored() === true && MemoryStorage::$operations === [], 'cleanupStored() without results touches nothing and is true');

$photo = $storeResult($main, ['small' => $variantFiles[1], 'thumb' => $variantFiles[0]]);
$putAll($root, [...$variantFiles, $main, 'docs/report.pdf']);
check($upload->cleanupStored($photo, $storeResult('docs/report.pdf'), $storeResult('docs/missing.pdf')) === true, 'cleanupStored() reports true when every listed file is gone; a missing file counts as removed');
check(MemoryStorage::unlinked() === $urls($root, [$variantFiles[1], $variantFiles[0], $main, 'docs/report.pdf', 'docs/missing.pdf']), 'per result, the variants in result order, then the main file; the results in the given order');
check(MemoryStorage::exists("{$root}/{$variantFiles[2]}"), 'a file the result does not list stays, even where the profile would derive it');
check($log->entries === [] && !\in_array('image', $app->touched, true), 'cleanupStored() needs no profile, never resolves image, and logs nothing on success');

[$upload, $root] = $memory(['image' => null]);
MemoryStorage::put("{$root}/{$main}");
check($upload->cleanupStored($storeResult($main)) === true && !MemoryStorage::exists("{$root}/{$main}"), 'cleanupStored() works without a registered image service');

[$upload, $root, $log] = $memory();
$putAll($root, [$variantFiles[0], $main, 'docs/report.pdf']);
MemoryStorage::$undeletable["{$root}/{$variantFiles[0]}"] = true;
check($upload->cleanupStored($storeResult($main, ['thumb' => $variantFiles[0]]), $storeResult('docs/report.pdf')) === false, 'cleanupStored() reports false when a listed file remains');
check(MemoryStorage::unlinked() === $urls($root, [$variantFiles[0], $main, 'docs/report.pdf']), 'cleanupStored() attempts every file of every result despite a failure');
check(\array_values(\array_filter($urls($root, [$variantFiles[0], $main, 'docs/report.pdf']), MemoryStorage::exists(...))) === ["{$root}/{$variantFiles[0]}"], 'only the refused file remains');
check(
	\count($log->entries) === 1 && $log->entries[0]['message'] === 'Failed to remove a stored file during cleanup.'
	&& $log->entries[0]['context']['stored_path'] === $variantFiles[0] && $log->entries[0]['context']['path'] === "{$root}/{$variantFiles[0]}",
	'the failure is logged as by cleanup(), with the file\'s own storage-relative path'
);

[$upload, $root] = $memory();
$valid = $storeResult($main, ['thumb' => $variantFiles[0]]);
$putAll($root, [$variantFiles[0], $main]);
$malformedResults = [
	'no storage' => \array_diff_key($valid, ['storage' => true]),
	'an int storage' => ['storage' => 1] + $valid,
	'no path' => \array_diff_key($valid, ['path' => true]),
	'no variants' => \array_diff_key($valid, ['variants' => true]),
	'null variants' => ['variants' => null] + $valid,
	'a variant without a path' => ['variants' => ['thumb' => ['mime' => 'image/webp']]] + $valid,
	'a variant that is a string' => ['variants' => ['thumb' => $variantFiles[0]]] + $valid,
	'an empty path' => ['path' => ''] + $valid,
	'an invalid path' => ['path' => '../x.webp'] + $valid,
	'an invalid variant path' => ['variants' => ['thumb' => ['path' => 'u1//x.webp']]] + $valid,
];

foreach ($malformedResults as $why => $result) {
	expectThrows(\InvalidArgumentException::class, static fn() => $upload->cleanupStored($valid, $result), "cleanupStored() rejects a result with {$why}");
}

check(MemoryStorage::$operations === [] && MemoryStorage::exists("{$root}/{$main}"), 'every result is validated before the first filesystem call: a malformed result removes nothing, not even the valid one before it');

$e = expectThrows(\InvalidArgumentException::class, static fn() => $upload->cleanupStored([$valid]), 'cleanupStored() of a list instead of results');
check(\str_contains($e->getMessage(), "cleanupStored(...\$batch['stored'])") && \str_contains($e->getMessage(), 'cleanupWithVariants()'), 'the message shows how to pass a batch and where a path read back from a record goes');

MemoryStorage::reset();
$filesRoot = MemoryStorage::root('files');
$otherRoot = MemoryStorage::root('other');
$mixed = new Upload(testApp(testCfg(['upload' => ['storages' => [
	'files' => ['root' => $filesRoot, 'web_path' => null],
	'other' => ['root' => $otherRoot, 'web_path' => null],
	'gone' => ['root' => $dir . '/no-such-root', 'web_path' => null],
]]])));
MemoryStorage::put("{$filesRoot}/a.pdf");
MemoryStorage::put("{$otherRoot}/b.pdf");
expectThrows(UploadConfigException::class, static fn() => $mixed->cleanupStored($storeResult('a.pdf'), $storeResult('c.pdf', [], 'nope')), 'cleanupStored() with a result in an undefined storage');
expectThrows(UploadConfigException::class, static fn() => $mixed->cleanupStored($storeResult('a.pdf'), $storeResult('c.pdf', [], 'gone')), 'cleanupStored() with a result in a storage whose root does not exist');
check(MemoryStorage::unlinked() === [] && MemoryStorage::exists("{$filesRoot}/a.pdf"), 'every storage root is verified before anything is removed');
check($mixed->cleanupStored($storeResult('a.pdf'), $storeResult('b.pdf', [], 'other')) === true && MemoryStorage::unlinked() === ["{$filesRoot}/a.pdf", "{$otherRoot}/b.pdf"], 'each result is removed from its own storage');

[$upload, $root] = $memory(['log' => $throwing]);
MemoryStorage::put("{$root}/{$main}");
MemoryStorage::$undeletable["{$root}/{$main}"] = true;
$e = expectThrows(\DomainException::class, static function () use ($upload, $storeResult, $main): void {
	try {
		throw new \DomainException('in flight');
	} finally {
		$upload->cleanupStored($storeResult($main));
	}
}, 'cleanupStored() in a finally block while an exception is in flight');
check($e->getMessage() === 'in flight' && $e->getPrevious() === null, 'cleanupStored() in a finally block does not mask the exception in flight, even with a failing unlink and a throwing log');


// -- Real filesystem: delete exactly what a store wrote ----------------------------

$realRoot = $dir . '/storage';
\mkdir($realRoot);
$image = new ScriptedImage();
$realLog = new RecordingLog();
$realApp = testApp(testCfg(['upload' => ['storages' => ['files' => ['root' => $realRoot, 'web_path' => null]]]]), ['image' => $image, 'log' => $realLog]);
$real = new Upload($realApp);
$jpeg = $dir . '/photo.jpg';
\file_put_contents($jpeg, Fixtures::header(ImageFormat::Jpeg, 100, 100));
$pdf = $dir . '/doc.pdf';
\file_put_contents($pdf, Fixtures::pdf());
$avatarReal = ['accept' => ['image/jpeg', 'application/pdf']] + $avatar;

$produced = $real->storeLocal($jpeg, $avatarReal, 'u1');
$kept = $real->storeLocal($jpeg, $archive, 'u2');
$plain = $real->storeLocal($pdf, $avatarReal, 'u3');
check(\count(filesUnder($realRoot)) === 7 && \count($produced['variants']) === 3 && \count($kept['variants']) === 1 && $plain['variants'] === [], 'three stores wrote seven files');
$calls = \count($image->calls);
$remaining = [$kept['path'], ...\array_column($kept['variants'], 'path'), $plain['path']];
\sort($remaining);

$real->deleteWithVariants($produced['path'], $avatarReal);
check(filesUnder($realRoot) === $remaining, 'deleteWithVariants() removes exactly what a store with a re-encoded main wrote');
check($real->cleanupWithVariants($kept['path'], $archive) === true && filesUnder($realRoot) === [$plain['path']], 'cleanupWithVariants() removes exactly what a store with a kept original wrote');
$real->deleteWithVariants($plain['path'], $avatarReal);
check(filesUnder($realRoot) === [], 'a plain file in an image profile is deleted although it has no variants');
$real->deleteWithVariants($produced['path'], $avatarReal);
check($real->cleanupWithVariants($kept['path'], $archive) === true, 'deleting again succeeds: missing files count as deleted');
check(\count($image->calls) === $calls && $realLog->entries === [], 'deletion never calls citomni/image and logs nothing on success');
check(\is_dir($realRoot . '/u1'), 'deletion removes files, not directories');

$fresh = [$real->storeLocal($jpeg, $avatarReal, 'u4'), $real->storeLocal($jpeg, $archive, 'u5'), $real->storeLocal($pdf, $avatarReal, 'u6')];
$calls = \count($image->calls);
check(\count(filesUnder($realRoot)) === 7, 'three more stores wrote seven files');
check($real->cleanupStored(...$fresh) === true && filesUnder($realRoot) === [], 'cleanupStored() removes exactly what the stores wrote, from their results alone');
check(\count($image->calls) === $calls && $realLog->entries === [], 'cleanupStored() never calls citomni/image and logs nothing on success');


// -- Real filesystem: refused unlinks (non-root POSIX or Windows) ------------------

if (!canForceUndeletableFile()) {
	skip('a real file the filesystem refuses to delete', 'needs a non-root POSIX user or Windows');
} else {
	$locked = $real->storeLocal($pdf, $attachment, 'locked');
	$file = $realRoot . '/' . $locked['path'];
	$unlock = lockAgainstDelete($file);

	try {
		$e = expectThrows(UploadStorageException::class, static fn() => $real->delete('files', $locked['path']), 'delete() of a real file the filesystem refuses to delete');
		$gone = $real->cleanup('files', $locked['path']);
	} finally {
		$unlock();
	}

	check(\is_file($file) && $gone === false, 'cleanup() reports false and the real file stays');
	check(\count($realLog->entries) === 1 && $realLog->entries[0]['context']['stored_path'] === $locked['path'] && $realLog->entries[0]['context']['path'] === $file, 'the refused removal is logged with both paths');
	$real->delete('files', $locked['path']);
	check(!\is_file($file), 'once the lock is gone, delete() removes the file');
}

if (isWindows() || !canForceUndeletableFile() || !\function_exists('symlink')) {
	skip('a link that survives a refused unlink', 'needs a non-root POSIX user');
} else {
	\mkdir($realRoot . '/links');
	\symlink($realRoot . '/no-target', $realRoot . '/links/dangling.jpg');
	$unlock = lockAgainstDelete($realRoot . '/links/dangling.jpg');

	try {
		expectThrows(UploadStorageException::class, static fn() => $real->delete('files', 'links/dangling.jpg'), 'delete() of a dangling link the filesystem refuses to remove');
		$gone = $real->cleanup('files', 'links/dangling.jpg');
	} finally {
		$unlock();
	}

	check($gone === false && \is_link($realRoot . '/links/dangling.jpg'), 'a link that remains is not reported as gone, although its target does not exist');
}

done('delete_test');
