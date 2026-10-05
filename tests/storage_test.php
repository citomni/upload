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
 * Storage contract of storeLocal() on the plain path: generic validation
 * before any write, source checks, placement (directory, $subdir, sharding,
 * 32-hex names), storage roots, profile directory versus $subdir errors,
 * file and directory modes, hashing, the result shape of a plain file, routing
 * without touching the image service, $move semantics around the commit
 * point, and compensation.
 *
 * Failures that cannot be arranged in advance are forced through the
 * filesystem where the platform enforces permissions (otherwise SKIP), and
 * through the test-only FaultSource stream wrapper where the target name or
 * a mid-call state change is needed. Upload itself has no test seams.
 */

require __DIR__ . '/bootstrap.php';

use CitOmni\Image\Enum\ImageFormat;
use CitOmni\Upload\Enum\UploadRejection;
use CitOmni\Upload\Exception\UploadConfigException;
use CitOmni\Upload\Exception\UploadRejectedException;
use CitOmni\Upload\Exception\UploadStorageException;
use CitOmni\Upload\Service\Upload;
use CitOmni\Upload\Tests\Support\FaultSource;
use CitOmni\Upload\Tests\Support\Fixtures;
use CitOmni\Upload\Tests\Support\RecordingLog;
use CitOmni\Upload\Util\MimeMap;

$dir = tempDir();
$sources = $dir . '/sources';
\mkdir($sources);

/** Write a source file and return its path. */
$source = static function (string $name, string $bytes) use ($sources): string {
	$path = $sources . '/' . $name;
	\file_put_contents($path, $bytes);

	return $path;
};

/**
 * Create a fresh storage root and an Upload service whose "files" storage points at it.
 *
 * @return array{0: Upload, 1: string, 2: \CitOmni\Upload\Tests\Support\TestApp}
 */
$setup = static function (array $upload = [], array $services = []) use ($dir): array {
	$root = $dir . '/root-' . \bin2hex(\random_bytes(4));
	\mkdir($root);
	$app = testApp(testCfg(['upload' => ['storages' => ['files' => ['root' => $root, 'web_path' => null]]] + $upload]), $services);

	return [new Upload($app), $root, $app];
};

$text = $source('note.txt', "Hello, upload.\n");
$pdf = $source('report.pdf', Fixtures::pdf());
$jpeg = $source('photo.jpg', Fixtures::jpeg());
$bmp = $source('pixel.bmp', Fixtures::bmp());
$heif = $source('photo.heif', Fixtures::heif());
$binary = $source('blob.dat', Fixtures::binary());
$evil = $source('evil.jpg', Fixtures::php());
$empty = $source('empty.txt', '');

$textProfile = ['storage' => 'files', 'accept' => ['text/plain'], 'max_bytes' => 1000];
$pdfProfile = ['storage' => 'files', 'accept' => ['application/pdf'], 'max_bytes' => 100_000];

// -- Generic validation ---------------------------------------------------------------

[$upload, $root] = $setup();
$rejecting = ['directory' => 'never/created', 'shard' => 2] + $textProfile;

$e = expectThrows(UploadRejectedException::class, static fn() => $upload->storeLocal($empty, $rejecting), 'a zero-byte file is rejected');
check($e->reason === UploadRejection::Empty && $e->context === ['original_name' => 'empty.txt', 'size' => 0], 'Empty carries original_name and size');

$eleven = $source('eleven.txt', \str_repeat('a', 11));
$e = expectThrows(UploadRejectedException::class, static fn() => $upload->storeLocal($eleven, ['max_bytes' => 10] + $rejecting), 'a file above max_bytes is rejected');
check($e->reason === UploadRejection::TooLarge && $e->context === ['original_name' => 'eleven.txt', 'size' => 11, 'max_bytes' => 10], 'TooLarge carries original_name, size, and max_bytes');

$e = expectThrows(UploadRejectedException::class, static fn() => $upload->storeLocal($text, ['accept' => ['application/pdf']] + $rejecting), 'a type outside accept is rejected');
check($e->reason === UploadRejection::TypeNotAllowed && $e->context === ['original_name' => 'note.txt', 'size' => 15, 'mime' => 'text/plain'], 'TypeNotAllowed carries original_name, size, and the detected type');

$e = expectThrows(UploadRejectedException::class, static fn() => $upload->storeLocal($evil, ['accept' => ['image/jpeg']] + $rejecting), 'PHP code named .jpg is rejected by an image accept list');
check($e->reason === UploadRejection::TypeNotAllowed && $e->context['mime'] !== 'image/jpeg', 'the type comes from the content, never from the name');

check(\scandir($root) === ['.', '..'], 'rejections write nothing and create no directories');

$ten = $source('ten.txt', \str_repeat('a', 10));
check($upload->storeLocal($ten, ['max_bytes' => 10] + $textProfile)['size'] === 10, 'a file of exactly max_bytes is accepted');

$big = $source('big.txt', \str_repeat("lorem ipsum\n", 100_000));
check($upload->storeLocal($big, ['max_bytes' => null] + $textProfile)['size'] === 1_200_000, 'max_bytes null sets no service limit');

$result = $upload->storeLocal($binary, ['accept' => ['*']] + $textProfile);
check($result['mime'] === 'application/octet-stream' && \str_ends_with($result['path'], '.bin'), "['*'] accepts any type, and an unknown type is stored as .bin");

$result = $upload->storeLocal($evil, ['accept' => ['*']] + $textProfile);
check(
	$result['mime'] !== 'image/jpeg' && \str_ends_with($result['path'], '.' . MimeMap::extension($result['mime'])) && \preg_match('~\.(php|phtml|jpg)$~D', $result['path']) === 0,
	"PHP code accepted by ['*'] gets the extension of its detected type, never .php or .jpg"
);

foreach (['image/bmp', 'image/x-ms-bmp'] as $accepted) {
	$result = $upload->storeLocal($bmp, ['accept' => [$accepted]] + $textProfile);
	check(
		$result['mime'] === 'image/bmp' && $result['source_mime'] === 'image/bmp' && \str_ends_with($result['path'], '.bmp'),
		"accept [{$accepted}] takes a BMP, stored as image/bmp whichever alias finfo reports"
	);
}

$detected = (new \finfo(\FILEINFO_MIME_TYPE))->file($heif);

if ($detected === 'image/heif') {
	$result = $upload->storeLocal($heif, ['accept' => ['image/heic']] + $textProfile);
	check(
		$result['mime'] === 'image/heic' && $result['source_mime'] === 'image/heic' && \str_ends_with($result['path'], '.heic'),
		'finfo reports image/heif; Upload canonicalizes the detected type to image/heic before matching accept'
	);
} else {
	skip('detected alias image/heif is canonicalized', 'this libmagic reports ' . \var_export($detected, true) . ' for the HEIF fixture');
}

// -- Source ---------------------------------------------------------------------------

expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeLocal($sources . '/missing.txt', $textProfile), 'a missing source is a developer error');
expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeLocal($sources, $textProfile), 'a directory as source is a developer error');

if (canForceUnreadableFile()) {
	$locked = $source('locked.txt', 'secret');
	\chmod($locked, 0000);
	expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeLocal($locked, $textProfile), 'an unreadable source is a developer error');
	\chmod($locked, 0644);
} else {
	skip('unreadable source is a developer error', 'file permissions are not enforced for this user or platform');
}

// -- Placement ------------------------------------------------------------------------

[$upload, $root] = $setup();
$docs = ['directory' => 'docs'] + $pdfProfile;

$r0 = $upload->storeLocal($pdf, $docs, 'sub/inner');
$r1 = $upload->storeLocal($pdf, ['shard' => 1] + $docs);
$r2 = $upload->storeLocal($pdf, ['shard' => 2] + $docs, 'token');

check(\preg_match('~^docs/sub/inner/[0-9a-f]{32}\.pdf$~D', $r0['path']) === 1, 'shard 0: directory/subdir/{32 hex}.pdf');
check(\preg_match('~^docs/([0-9a-f]{2})/\1[0-9a-f]{30}\.pdf$~D', $r1['path']) === 1, 'shard 1: one directory of the first 2 hex characters');
check(\preg_match('~^docs/token/([0-9a-f]{2})/([0-9a-f]{2})/\1\2[0-9a-f]{28}\.pdf$~D', $r2['path']) === 1, 'shard 2: two 2-hex directories after the subdir');

foreach ([$r0, $r1, $r2] as $result) {
	check(\file_get_contents($root . '/' . $result['path']) === Fixtures::pdf(), 'the stored file is a byte copy of the source: ' . $result['path']);
}

check(\count(\array_unique([$r0['path'], $r1['path'], $r2['path']])) === 3, 'every store gets a new name');
check(\is_dir($root . '/docs/sub/inner'), 'missing directories are created');
check(\preg_grep('~(^|/)\.~', filesUnder($root)) === [], 'no dot-prefixed temporary file remains');

$result = $upload->storeLocal($pdf, $pdfProfile);
check(\preg_match('~^[0-9a-f]{32}\.pdf$~D', $result['path']) === 1 && \is_file($root . '/' . $result['path']), 'without directory, subdir, and sharding the file lands in the storage root');

$slashRoot = $dir . '/slash-root';
\mkdir($slashRoot);
$result = (new Upload(testApp(testCfg(['upload' => ['storages' => ['files' => ['root' => $slashRoot . '/', 'web_path' => null]]]]))))->storeLocal($pdf, ['directory' => 'x'] + $pdfProfile);
check(\is_file($slashRoot . '/' . $result['path']), 'a root configured with a trailing slash works');

// -- Storage roots --------------------------------------------------------------------

$upload = new Upload(testApp(testCfg()));
$e = expectThrows(UploadConfigException::class, static fn() => $upload->storeLocal($text, ['storage' => 'public'] + $textProfile), 'the unprovisioned baseline root of "public" is a config error');
check(\str_contains($e->getMessage(), "Storage 'public'") && !\file_exists(\CITOMNI_APP_PATH), 'the message names the storage, and the root is not created');
expectThrows(UploadConfigException::class, static fn() => $upload->storeLocal($empty, ['storage' => 'private'] + $textProfile), 'the root is checked before content validation');

$upload = new Upload(testApp(testCfg(['upload' => ['storages' => [
	'gone' => ['root' => $dir . '/gone', 'web_path' => null],
	'numeric' => ['root' => 42, 'web_path' => null],
	'rootless' => ['web_path' => 'x'],
]]])));

expectThrows(UploadConfigException::class, static fn() => $upload->storeLocal($text, ['storage' => 'gone'] + $textProfile), 'a custom storage with a missing root is a config error');
check(!\file_exists($dir . '/gone'), 'the missing custom root is not created');
expectThrows(UploadConfigException::class, static fn() => $upload->storeLocal($text, ['storage' => 'numeric'] + $textProfile), 'a non-string root is a config error');
expectThrows(UploadConfigException::class, static fn() => $upload->storeLocal($text, ['storage' => 'rootless'] + $textProfile), 'a storage without root is a config error');

[$upload, $root, $app] = $setup();
check(\is_file($root . '/' . $upload->storeLocal($text, $textProfile)['path']), 'the first store verifies the root');
$app->cfg = testCfg(['upload' => ['storages' => ['files' => ['root' => $dir . '/nowhere', 'web_path' => null]]]]);
check(\is_file($root . '/' . $upload->storeLocal($text, $textProfile)['path']), 'the verified root is memoized per storage: the same service keeps using it');
expectThrows(UploadConfigException::class, static fn() => (new Upload($app))->storeLocal($text, $textProfile), 'a fresh service checks the swapped root');

// -- Profile directory versus $subdir -------------------------------------------------

[$upload, $root] = $setup();

foreach (['../escape', '/abs', 'a//b', 'a/', '.hidden', 'a\\b', 'a b'] as $path) {
	$label = \json_encode($path);
	$e = expectThrows(UploadConfigException::class, static fn() => $upload->storeLocal($text, ['directory' => $path] + $textProfile), "an invalid profile directory {$label} is a config error");
	check($e->getPrevious() instanceof \InvalidArgumentException, "the path error for directory {$label} is kept as previous");
	expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeLocal($text, $textProfile, $path), "an invalid \$subdir {$label} is a developer error");
}

$e = expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeLocal($sources . '/missing.txt', $textProfile, '../x'), 'an invalid $subdir with a missing source');
check(\str_contains($e->getMessage(), 'storage-relative path'), 'the $subdir is checked before the source');
check(\scandir($root) === ['.', '..'], 'invalid paths write nothing');

// -- File and directory modes ---------------------------------------------------------

if (isWindows()) {
	skip('file_mode and dir_mode are applied', 'POSIX permissions do not apply on Windows');
} else {
	[$upload, $root] = $setup(['file_mode' => 0640, 'dir_mode' => 0750]);
	$result = $upload->storeLocal($text, ['directory' => 'modes/deep'] + $textProfile);
	\clearstatcache();
	check((\fileperms($root . '/' . $result['path']) & 0777) === 0640, 'file_mode is applied to the stored file');
	check(
		(\fileperms($root . '/modes') & 0777) === (0750 & ~\umask()) && (\fileperms($root . '/modes/deep') & 0777) === (0750 & ~\umask()),
		'dir_mode is applied to every created directory, subject to umask'
	);

	[$upload, $root] = $setup(['file_mode' => null]);
	$result = $upload->storeLocal($text, $textProfile);
	\clearstatcache();
	check((\fileperms($root . '/' . $result['path']) & 0777) === (0666 & ~\umask()), 'file_mode null leaves the mode copy() created');
}

// -- Hash -----------------------------------------------------------------------------

[$upload, $root] = $setup();
$result = $upload->storeLocal($pdf, ['hash' => 'sha256'] + $pdfProfile);
check(
	$result['hash'] === 'sha256:' . \hash_file('sha256', $root . '/' . $result['path']) && $result['hash'] === 'sha256:' . \hash('sha256', Fixtures::pdf()) && \preg_match('~^sha256:[0-9a-f]{64}$~D', $result['hash']) === 1,
	'hash is "sha256:<hex>" of the stored bytes'
);
check($upload->storeLocal($pdf, ['hash' => 'md5'] + $pdfProfile)['hash'] === 'md5:' . \md5(Fixtures::pdf()), 'hash uses the configured algorithm');
check($upload->storeLocal($pdf, $pdfProfile)['hash'] === null, 'hash is null when the profile does not enable it');

// -- Result ---------------------------------------------------------------------------

$result = $upload->storeLocal($pdf, $pdfProfile, 'res');
check(\array_keys($result) === ['storage', 'path', 'mime', 'source_mime', 'size', 'original_name', 'width', 'height', 'hash', 'variants'], 'the result has the documented keys, in order');
check(
	$result['storage'] === 'files' && \str_starts_with($result['path'], 'res/') && $result['mime'] === 'application/pdf' && $result['source_mime'] === 'application/pdf'
	&& $result['size'] === \strlen(Fixtures::pdf()) && $result['original_name'] === 'report.pdf'
	&& $result['width'] === null && $result['height'] === null && $result['hash'] === null && $result['variants'] === [],
	'a plain file reports the finfo type, its own size, no dimensions, and no variants; original_name defaults to the source file name'
);

$result = $upload->storeLocal($pdf, $pdfProfile, '', 'C:\\fakepath\\Faktura 2026.pdf');
check($result['original_name'] === 'Faktura 2026.pdf' && !\str_contains($result['path'], 'Faktura'), 'an explicit original name is sanitized metadata and never reaches the path');

// -- Routing --------------------------------------------------------------------------

[$upload, $root, $app] = $setup();
$imageProfile = ['storage' => 'files', 'accept' => ['application/pdf', 'image/jpeg', 'image/png'], 'max_bytes' => 100_000, 'image' => [
	'main' => ['format' => 'webp', 'width' => 1600, 'fit' => 'contain'],
	'variants' => ['thumb' => ['format' => ImageFormat::Webp, 'width' => 300, 'height' => 300, 'fit' => 'cover']],
]];

$result = $upload->storeLocal($pdf, $imageProfile);
check(
	$result['mime'] === 'application/pdf' && \str_ends_with($result['path'], '.pdf') && $result['width'] === null && $result['height'] === null && $result['variants'] === [],
	'a PDF in an image profile follows the plain path'
);

$result = $upload->storeLocal($jpeg, ['accept' => ['image/jpeg']] + $pdfProfile);
check($result['mime'] === 'image/jpeg' && \str_ends_with($result['path'], '.jpg') && $result['variants'] === [], 'a JPEG in a profile without an image block follows the plain path');
check(!\in_array('image', $app->touched, true), 'the image service is never resolved on the plain path');

// -- $move ----------------------------------------------------------------------------

[$upload, $root] = $setup();

$moving = $source('move-large.txt', \str_repeat('x', 50));
$e = expectThrows(UploadRejectedException::class, static fn() => $upload->storeLocal($moving, ['max_bytes' => 10] + $textProfile, move: true), 'move: a file above max_bytes is rejected');
check($e->reason === UploadRejection::TooLarge && \file_get_contents($moving) === \str_repeat('x', 50), 'a TooLarge rejection leaves the moved source untouched');

$moving = $source('move-type.txt', 'plain text');
$e = expectThrows(UploadRejectedException::class, static fn() => $upload->storeLocal($moving, ['accept' => ['application/pdf']] + $textProfile, move: true), 'move: a type outside accept is rejected');
check($e->reason === UploadRejection::TypeNotAllowed && \file_get_contents($moving) === 'plain text', 'a TypeNotAllowed rejection leaves the moved source untouched');

$moving = $source('move-ok.pdf', Fixtures::pdf());
$result = $upload->storeLocal($moving, $pdfProfile, move: true);
\clearstatcache();
check(!\file_exists($moving) && \file_get_contents($root . '/' . $result['path']) === Fixtures::pdf(), 'a successful moved store keeps the bytes and removes the source');

$kept = $source('keep.pdf', Fixtures::pdf());
check(\is_file($root . '/' . $upload->storeLocal($kept, $pdfProfile)['path']) && \file_exists($kept), 'without move the source stays');

expectThrows(\InvalidArgumentException::class, static fn() => $upload->storeLocal($kept, $pdfProfile, '../x', null, true), 'move: an invalid $subdir is a developer error');
check(\file_exists($kept), 'a developer error leaves the moved source untouched');

if (canForceUndeletableFile()) {
	foreach (['recording' => null, 'throwing' => new \RuntimeException('log is down'), 'unregistered' => false] as $case => $failure) {
		$log = new RecordingLog();
		$log->failWith = $failure instanceof \Throwable ? $failure : null;
		[$upload, $root, $app] = $setup([], ['log' => $failure === false ? null : $log]);

		$lockedDir = $sources . '/locked-' . $case;
		\mkdir($lockedDir);
		$moving = $lockedDir . '/source.pdf';
		\file_put_contents($moving, Fixtures::pdf());
		$restore = lockAgainstDelete($moving);

		try {
			$result = $upload->storeLocal($moving, $pdfProfile, move: true);
		} finally {
			$restore();
		}

		check(\file_exists($moving) && \is_file($root . '/' . $result['path']), "a failed source removal keeps the source, and the result stands ({$case} log)");

		if ($failure === false) {
			check(!\in_array('log', $app->touched, true), 'without a registered log service, the log is never resolved');
		} else {
			$entry = $log->entries[0] ?? null;
			check(\count($log->entries) === 1 && $entry['file'] === 'upload.jsonl' && $entry['category'] === 'cleanup', "the failed source removal is logged once to upload.jsonl, category cleanup ({$case} log)");
			check(
				$entry['context']['path'] === $moving && $entry['context']['stored_path'] === $result['path'] && $entry['context']['storage'] === 'files' && \is_string($entry['context']['error']),
				"the log context names the source, the stored path, the storage, and the error ({$case} log)"
			);
		}
	}
} else {
	skip('move: a failed source removal is logged and the result stands', 'files cannot be made undeletable for this user or platform');
}

if (canForceUnreadableFile()) {
	[$upload, $root] = $setup(['file_mode' => 0]);
	$moving = $source('after-copy.pdf', Fixtures::pdf());
	$e = expectThrows(UploadStorageException::class, static fn() => $upload->storeLocal($moving, ['hash' => 'sha256', 'directory' => 'hashing'] + $pdfProfile, move: true), 'a failure after the copy (hashing an unreadable file) is a storage error');
	check(\str_contains($e->getMessage(), 'Failed to hash'), 'the failure happened after the file was published');
	check(filesUnder($root) === [], 'compensation removed the stored file, and no temporary file remains');
	check(\file_get_contents($moving) === Fixtures::pdf(), 'a failure past the copy leaves the moved source untouched');
} else {
	skip('move: a failure after the copy (hash) leaves the source and storage clean', 'file permissions are not enforced for this user or platform');
}

// -- Compensation (FaultSource) -------------------------------------------------------

// An existing target cannot be prepared in advance: its name only appears once copy() runs.
[$upload, $root] = $setup();
$target = $root . '/clash';
$planted = null;

$url = FaultSource::create(Fixtures::pdf(), static function () use ($target, &$planted): void {
	if ($planted !== null) {
		return;
	}

	foreach (\glob($target . '/.*.tmp') ?: [] as $temporary) {
		if (\preg_match('~^\.(.+)\.[0-9a-f]{12}\.tmp$~D', \basename($temporary), $match) === 1) {
			$planted = $target . '/' . $match[1];
			\file_put_contents($planted, 'not written by this call');

			return;
		}
	}
});

$e = expectThrows(UploadStorageException::class, static fn() => $upload->storeLocal($url, ['directory' => 'clash'] + $pdfProfile, move: true), 'an existing target is refused');
check($planted !== null && \str_contains($e->getMessage(), 'already exists'), 'the target appeared while copy() ran');
check(\file_get_contents($planted) === 'not written by this call', 'the existing file is not touched');
check(filesUnder($root) === ['clash/' . \basename($planted)], 'the temporary file is removed, and nothing else remains');
check(FaultSource::exists($url), 'the moved source is untouched');

// A read error halfway through copy() leaves a partial temporary file that compensation must remove.
[$upload, $root] = $setup();
$copyReads = 0;

$url = FaultSource::create(\str_repeat("%PDF-1.4\n", 4096), static function () use ($root, &$copyReads): ?bool {
	if ((\glob($root . '/partial/.*.tmp') ?: []) === []) {
		return null;
	}

	return ++$copyReads > 1 ? false : null;
});

$e = expectThrows(UploadStorageException::class, static fn() => $upload->storeLocal($url, ['directory' => 'partial'] + $pdfProfile, move: true), 'a read error during copy() is a storage error');
check($copyReads > 1 && \str_contains($e->getMessage(), 'Failed to copy'), 'copy() failed after writing part of the temporary file');
check(filesUnder($root) === [], 'the partial temporary file is removed');
check(FaultSource::exists($url), 'the moved source is untouched after a failed copy');

// A directory that turns read-only during copy() makes both rename() and the compensating unlink() fail.
if (canForceReadOnlyDir()) {
	foreach (['recording' => null, 'throwing' => new \RuntimeException('log is down'), 'unregistered' => false] as $case => $failure) {
		$log = new RecordingLog();
		$log->failWith = $failure instanceof \Throwable ? $failure : null;
		[$upload, $root, $app] = $setup([], ['log' => $failure === false ? null : $log]);

		$target = $root . '/locked';
		\mkdir($target);
		$locked = false;

		$url = FaultSource::create(Fixtures::pdf(), static function () use ($target, &$locked): void {
			if (!$locked && (\glob($target . '/.*.tmp') ?: []) !== []) {
				\chmod($target, 0555);
				$locked = true;
			}
		});

		try {
			$e = expectThrows(UploadStorageException::class, static fn() => $upload->storeLocal($url, ['directory' => 'locked'] + $pdfProfile, move: true), "publishing into a directory that turned read-only fails ({$case} log)");
		} finally {
			\chmod($target, 0755);
		}

		check($locked && \str_contains($e->getMessage(), 'Failed to rename'), "the original exception surfaces unmasked ({$case} log)");

		$temporaries = \glob($target . '/.*.tmp') ?: [];
		check(\count($temporaries) === 1, "the temporary file that could not be removed stays behind, dot-prefixed ({$case} log)");
		check(FaultSource::exists($url), "the moved source is untouched ({$case} log)");

		if ($failure === false) {
			check(!\in_array('log', $app->touched, true), 'without a registered log service, the log is never resolved');
		} else {
			$entry = $log->entries[0] ?? null;
			check(
				\count($log->entries) === 1 && $entry['file'] === 'upload.jsonl' && $entry['category'] === 'cleanup'
				&& $entry['context']['path'] === $temporaries[0] && $entry['context']['storage'] === 'files' && \is_string($entry['context']['error']),
				"the failed compensation is logged with storage, path, and error ({$case} log)"
			);
		}
	}
} else {
	skip('compensation: a failed unlink is logged, and a throwing log masks nothing', 'directories cannot be made read-only for this user or platform');
}

done('storage_test');
