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
 * best-effort cleanup() and cleanupWithVariants(). Path validation before any
 * filesystem call, storage and profile errors, missing files, files that
 * cannot be deleted, ordering (variants before the main file), stopping at the
 * first failure versus attempting every file, cleanup logging, and cleanup in
 * a finally block that must not mask the exception in flight.
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
