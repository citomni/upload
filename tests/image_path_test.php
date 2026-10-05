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
 * The image path against a scripted citomni/image double: routing, the
 * outputs Upload hands to save() (paths Upload chose, unchanged specs and
 * options, no overwrite, existing directories), the order of image job and
 * original, MIME agreement for every ImageFormat and the known aliases,
 * where every result field comes from, upload.file_mode on every placed file,
 * the translation of citomni/image exceptions, and compensation after every
 * failure point (cases C1-C10). The double's signatures are checked
 * against the real Image service.
 */

require __DIR__ . '/bootstrap.php';

use CitOmni\Image\Enum\ImageFormat;
use CitOmni\Image\Exception\ImageCapabilityException;
use CitOmni\Image\Exception\ImageInputException;
use CitOmni\Image\Exception\ImageTargetExistsException;
use CitOmni\Image\Exception\ImageWriteException;
use CitOmni\Image\Service\Image;
use CitOmni\Upload\Enum\UploadRejection;
use CitOmni\Upload\Exception\UploadConfigException;
use CitOmni\Upload\Exception\UploadRejectedException;
use CitOmni\Upload\Exception\UploadStorageException;
use CitOmni\Upload\Service\Upload;
use CitOmni\Upload\Tests\Support\FaultSource;
use CitOmni\Upload\Tests\Support\Fixtures;
use CitOmni\Upload\Tests\Support\RecordingLog;
use CitOmni\Upload\Tests\Support\ScriptedImage;
use CitOmni\Upload\Util\MimeMap;

$dir = tempDir();
$sources = $dir . '/sources';
\mkdir($sources);

/** Write a source file and return its path. */
$source = static function (string $name, string $bytes) use ($sources): string {
	\file_put_contents($sources . '/' . $name, $bytes);

	return $sources . '/' . $name;
};

/**
 * Fresh storage root and an Upload service with a ScriptedImage.
 *
 * @return array{0: Upload, 1: string, 2: ScriptedImage}
 */
$setup = static function (array $upload = [], array $services = []) use ($dir): array {
	$root = $dir . '/root-' . \bin2hex(\random_bytes(4));
	\mkdir($root);
	$image = new ScriptedImage();
	$app = testApp(testCfg(['upload' => ['storages' => ['files' => ['root' => $root, 'web_path' => null]]] + $upload]), $services + ['image' => $image]);

	return [new Upload($app), $root, $image];
};

$jpeg = $source('photo.jpg', Fixtures::jpeg());
$pdf = $source('report.pdf', Fixtures::pdf());
$accept = \array_merge(\array_map(static fn(ImageFormat $format): string => $format->mime(), ImageFormat::cases()), ['application/pdf']);

$avatar = ['storage' => 'files', 'accept' => $accept, 'max_bytes' => 100_000, 'image' => [
	'main' => ['format' => 'webp', 'width' => 1600, 'height' => 1600, 'fit' => 'contain'],
	'variants' => [
		'thumb' => ['format' => ImageFormat::Webp, 'width' => 300, 'height' => 300, 'fit' => 'cover'],
		'small' => ['format' => 'jpeg', 'width' => 100],
	],
	'options' => ['auto_orient' => false],
]];
$archive = ['storage' => 'files', 'directory' => 'archive', 'accept' => $accept, 'max_bytes' => 100_000, 'hash' => 'sha256', 'image' => [
	'main' => null,
	'variants' => ['preview' => ['format' => 'webp', 'width' => 1200]],
]];
$original = ['storage' => 'files', 'accept' => $accept, 'max_bytes' => 100_000, 'image' => ['main' => null]];

// -- The double matches the real Image ------------------------------------------------

$signature = static function (string $class, string $method): array {
	$reflection = new \ReflectionMethod($class, $method);
	$parameters = [];

	foreach ($reflection->getParameters() as $parameter) {
		$parameters[] = [
			$parameter->getName(),
			(string)$parameter->getType(),
			$parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : 'no default',
			$parameter->isVariadic(),
			$parameter->isPassedByReference(),
		];
	}

	return [$parameters, (string)$reflection->getReturnType()];
};

foreach (['inspect', 'save'] as $method) {
	check($signature(Image::class, $method) === $signature(ScriptedImage::class, $method), "ScriptedImage::{$method}() has the signature of Image::{$method}()");
}

// -- Re-encoded main with variants ----------------------------------------------------

[$upload, $root, $image] = $setup();
$result = $upload->storeLocal($jpeg, $avatar, 'u1');

check(\count($image->calls) === 2 && $image->calls[0] === ['inspect', $jpeg] && $image->calls[1][0] === 'save', 'inspect() runs first, then one save()');
[, $saved, $outputs, $options, $directoriesExisted] = $image->calls[1];
check(\preg_match('~^u1/([0-9a-f]{32})\.webp$~D', $result['path'], $match) === 1, 'the main path is {32 hex}.{extension of the main output format}');
$name = $match[1];

check($saved === $jpeg && $options === ['auto_orient' => false] && $directoriesExisted, 'save() gets the source, the options unchanged, and existing target directories');
check(\array_keys($outputs) === ['main', 'thumb', 'small'], 'outputs: main first, then the variants in profile order');
check($outputs['main'] === $avatar['image']['main'] + ['path' => $root . '/u1/' . $name . '.webp'], 'the main spec is passed unchanged, plus the path Upload chose');
check($outputs['thumb'] === $avatar['image']['variants']['thumb'] + ['path' => $root . '/u1/' . $name . '-thumb.webp'], 'a variant spec is passed unchanged, plus the derived variant path');
check($outputs['small']['path'] === $root . '/u1/' . $name . '-small.jpg' && !\array_key_exists('overwrite', $outputs['small']), 'a jpeg variant gets .jpg, and overwrite is never set');
check(
	$result['mime'] === 'image/webp' && $result['source_mime'] === 'image/jpeg' && $result['size'] === \filesize($root . '/' . $result['path'])
	&& [$result['width'], $result['height']] === [1600, 1600] && $result['hash'] === null,
	'a re-encoded main reports the Image result (mime, bytes, width, height) and the inspected source type'
);
check($result['variants'] === [
	'thumb' => ['path' => 'u1/' . $name . '-thumb.webp', 'mime' => 'image/webp', 'size' => \filesize($root . '/u1/' . $name . '-thumb.webp'), 'width' => 300, 'height' => 300],
	'small' => ['path' => 'u1/' . $name . '-small.jpg', 'mime' => 'image/jpeg', 'size' => \filesize($root . '/u1/' . $name . '-small.jpg'), 'width' => 100, 'height' => 100],
], 'variants report path, mime, size, width, and height from the Image result');
check(filesUnder($root) === ['u1/' . $name . '-small.jpg', 'u1/' . $name . '-thumb.webp', 'u1/' . $name . '.webp'], 'storage holds exactly the Image outputs');

// -- Original kept, with a variant ----------------------------------------------------

[$upload, $root, $image] = $setup();
$image->info = ['orientation' => 6, 'display_width' => 1200, 'display_height' => 1600] + $image->info;
$beforeSave = null;
$image->onSave = static function (string $sourcePath, array $outputs) use ($root, &$beforeSave): array {
	$beforeSave = filesUnder($root);

	return ScriptedImage::write($outputs);
};

$moving = $source('moving.jpg', Fixtures::jpeg());
$result = $upload->storeLocal($moving, $archive, '', null, true);
\clearstatcache();

check($beforeSave === [], 'the image job runs before the original is written');
check(
	\preg_match('~^archive/([0-9a-f]{32})\.jpg$~D', $result['path'], $match) === 1 && \file_get_contents($root . '/' . $result['path']) === Fixtures::jpeg() && !\file_exists($moving),
	'the original is stored bit for bit under the extension of its type, and the moved source is removed after the commit'
);
check(
	$result['mime'] === 'image/jpeg' && $result['source_mime'] === 'image/jpeg' && $result['size'] === \strlen(Fixtures::jpeg())
	&& [$result['width'], $result['height']] === [1200, 1600] && $result['hash'] === 'sha256:' . \hash('sha256', Fixtures::jpeg()),
	'a kept original reports its own type and size, the display dimensions from inspect(), and its hash'
);
check(\array_keys($image->calls[1][2]) === ['preview'] && $result['variants']['preview']['path'] === 'archive/' . $match[1] . '-preview.webp', 'only the variants go to save(), next to the original');

[$upload, $root, $image] = $setup();
$result = $upload->storeLocal($jpeg, $original);
check(\count($image->calls) === 1 && $image->calls[0][0] === 'inspect', 'without image outputs there is no image job, only inspect()');
check(\file_get_contents($root . '/' . $result['path']) === Fixtures::jpeg() && $result['variants'] === [] && [$result['width'], $result['height']] === [1600, 1200], 'the original alone is kept, with the dimensions from inspect()');

// -- Routing --------------------------------------------------------------------------

[$upload, $root, $image] = $setup();
$result = $upload->storeLocal($pdf, $avatar);
check($image->calls === [] && \str_ends_with($result['path'], '.pdf') && $result['width'] === null && $result['variants'] === [], 'a PDF in an image profile is a plain file, and citomni/image is not called');
$result = $upload->storeLocal($jpeg, \array_diff_key($avatar, ['image' => true]));
check($image->calls === [] && \str_ends_with($result['path'], '.jpg') && $result['variants'] === [], 'an image in a profile without image block is a plain file');

// -- MIME agreement -------------------------------------------------------------------

$headers = [
	'jpeg' => Fixtures::jpeg(),
	'png' => Fixtures::header(ImageFormat::Png, 4, 2),
	'gif' => Fixtures::header(ImageFormat::Gif, 4, 2),
	'webp' => Fixtures::header(ImageFormat::Webp, 4, 2),
	'avif' => Fixtures::ftyp('avif', 'mif1', 'avif'),
	'bmp' => Fixtures::bmp(),
	'tiff' => Fixtures::header(ImageFormat::Tiff, 4, 2),
	'heic' => Fixtures::ftyp('heic', 'mif1', 'heic'),
];

foreach (ImageFormat::cases() as $format) {
	[$upload, $root, $image] = $setup();
	$image->info = ['format' => $format->value, 'mime' => $format->mime()] + $image->info;
	$result = $upload->storeLocal($source('agree.' . $format->value, $headers[$format->value]), $original);
	check(
		$result['mime'] === $format->mime() && \str_ends_with($result['path'], '.' . MimeMap::extension($format->mime())),
		"{$format->name}: finfo and inspect() agree, and the original keeps the extension of its type"
	);
}

[$upload, $root, $image] = $setup();
$image->info = ['format' => 'heic', 'mime' => 'image/heic'] + $image->info;
$result = $upload->storeLocal($source('alias.heif', Fixtures::heif()), $original);
check($result['mime'] === 'image/heic' && \str_ends_with($result['path'], '.heic'), 'finfo reports image/heif for a mif1 HEIF; the alias canonicalizes to image/heic and agrees with inspect()');

[$upload, $root, $image] = $setup();
$image->info = ['format' => 'png', 'mime' => 'image/png'] + $image->info;
$e = expectThrows(UploadRejectedException::class, static fn() => $upload->storeLocal($jpeg, $avatar), 'finfo and inspect() disagree');
check(
	$e->reason === UploadRejection::TypeNotAllowed && $e->context === ['original_name' => 'photo.jpg', 'size' => \strlen(Fixtures::jpeg()), 'mime' => 'image/jpeg', 'image_mime' => 'image/png'],
	'a disagreement is TypeNotAllowed with both types in the context'
);
check(\count($image->calls) === 1 && \scandir($root) === ['.', '..'], 'after a disagreement nothing is written, and save() is not called');

[$upload, $root, $image] = $setup();
$image->info = ['format' => 'avif', 'mime' => 'image/avif'] + $image->info;
$e = expectThrows(UploadRejectedException::class, static fn() => $upload->storeLocal($source('generic.avif', Fixtures::ftyp('mif1', 'mif1', 'avif')), $original), 'an AVIF with the generic major brand mif1');
check(
	$e->reason === UploadRejection::TypeNotAllowed && $e->context['mime'] === 'image/heic' && $e->context['image_mime'] === 'image/avif',
	'finfo calls an AVIF with major brand mif1 HEIF, and the disagreement is rejected (fail-closed)'
);

// -- upload.file_mode on every placed file --------------------------------------------

if (isWindows()) {
	skip('upload.file_mode on image outputs', 'POSIX permissions do not apply on Windows');
} else {
	foreach ([0644, 0640] as $mode) {
		[$upload, $root] = $setup(['file_mode' => $mode]);
		$produced = $upload->storeLocal($jpeg, $avatar);
		$kept = $upload->storeLocal($jpeg, $archive);
		\clearstatcache();
		$modes = \array_map(static fn(string $file): int => \fileperms($root . '/' . $file) & 0777, filesUnder($root));
		check(\count($modes) === 5 && \array_unique($modes) === [$mode] && \count($produced['variants']) === 2 && \count($kept['variants']) === 1, \sprintf('file_mode %04o is applied to the re-encoded main, the variants, and the kept original', $mode));
	}

	[$upload, $root] = $setup(['file_mode' => null]);
	$produced = $upload->storeLocal($jpeg, $avatar);
	\clearstatcache();
	$modes = \array_map(static fn(string $file): int => \fileperms($root . '/' . $file) & 0777, filesUnder($root));
	check(\count($modes) === 3 && \array_unique($modes) === [0666 & ~\umask()] && $produced['mime'] === 'image/webp', 'with file_mode null, image outputs keep the mode they were written with');

	[$upload, $root, $image] = $setup(['file_mode' => 0644]);
	$dangling = null;
	$image->onSave = static function (string $sourcePath, array $outputs) use (&$dangling): array {
		$results = ScriptedImage::write(\array_diff_key($outputs, ['small' => true]));
		\symlink($outputs['small']['path'] . '.missing', $dangling = $outputs['small']['path']);

		return $results + ['small' => ScriptedImage::result($outputs['small']['path'], ImageFormat::Jpeg, 100, 100, 1)];
	};
	$e = expectThrows(UploadStorageException::class, static fn() => $upload->storeLocal($jpeg, $avatar), 'chmod of an image output fails');
	check(\str_contains($e->getMessage(), 'Failed to chmod') && filesUnder($root) === [] && !\is_link((string)$dangling), 'a failing chmod is a storage error, and everything the call placed is removed');
}

// -- Exceptions and compensation ------------------------------------------------------

// C1: inspect() rejects the content.
[$upload, $root, $image] = $setup();
$thrown = new ImageInputException('Not a recognized image.');
$image->onInspect = static fn(string $path): never => throw $thrown;
$moving = $source('c1.jpg', Fixtures::jpeg());
$e = expectThrows(UploadRejectedException::class, static fn() => $upload->storeLocal($moving, $avatar, move: true), 'C1: inspect() rejects the content');
check(
	$e->reason === UploadRejection::InvalidImage && $e->getPrevious() === $thrown && $e->context === ['original_name' => 'c1.jpg', 'size' => \strlen(Fixtures::jpeg()), 'mime' => 'image/jpeg'],
	'C1: InvalidImage keeps the image exception as previous'
);
check(\count($image->calls) === 1 && \scandir($root) === ['.', '..'] && \file_exists($moving), 'C1: save() is not called, nothing is created, and the moved source stays');

// C3: save() rejects the content.
[$upload, $root, $image] = $setup();
$thrown = new ImageInputException('Image exceeds image.max_pixels.');
$image->onSave = static fn(): never => throw $thrown;
$e = expectThrows(UploadRejectedException::class, static fn() => $upload->storeLocal($jpeg, $archive), 'C3: save() rejects the content');
check($e->reason === UploadRejection::InvalidImage && $e->getPrevious() === $thrown && filesUnder($root) === [], 'C3: InvalidImage, and the original is not written');

// C4: capability and runtime failures propagate unchanged.
foreach ([new ImageCapabilityException('No backend can encode avif.'), new \RuntimeException('Target directory is not writable: x')] as $thrown) {
	[$upload, $root, $image] = $setup();
	$image->onSave = static fn(): never => throw $thrown;
	$e = expectThrows($thrown::class, static fn() => $upload->storeLocal($jpeg, $archive), 'C4: ' . $thrown::class . ' from save()');
	check($e === $thrown && filesUnder($root) === [], 'C4: ' . $thrown::class . ' propagates unchanged, and nothing is written');
}

[$upload, $root, $image] = $setup();
$thrown = new \UnexpectedValueException('Config "image.backends" must be a non-empty list of backend names.');
$image->onInspect = static fn(): never => throw $thrown;
check(expectThrows(\UnexpectedValueException::class, static fn() => $upload->storeLocal($jpeg, $avatar), 'an Image configuration error') === $thrown, 'C4: an Image configuration error propagates unchanged');

// C5: citomni/image rejects a spec or the options.
[$upload, $root, $image] = $setup();
$thrown = new \InvalidArgumentException('Output "thumb": fit "cover" requires both width and height.');
$image->onSave = static fn(): never => throw $thrown;
$e = expectThrows(UploadConfigException::class, static fn() => $upload->storeLocal($jpeg, $avatar), 'C5: citomni/image rejects a spec');
check(
	$e->getPrevious() === $thrown && \str_starts_with($e->getMessage(), 'Inline upload profile:') && \str_contains($e->getMessage(), $thrown->getMessage()) && filesUnder($root) === [],
	'C5: a config error naming the profile, with the Image error as previous'
);

// C6: publishing fails part-way; exactly committed() is removed.
[$upload, $root, $image] = $setup();
$image->onSave = static function (string $sourcePath, array $outputs): never {
	// "small" is written but reported as uncommitted, to show that Upload removes nothing beyond committed().
	ScriptedImage::write(['thumb' => $outputs['thumb'], 'small' => $outputs['small']]);

	throw new ImageWriteException('Failed to publish "main".', ['thumb' => $outputs['thumb']['path']], ['main' => $outputs['main']['path'], 'small' => $outputs['small']['path']]);
};
$e = expectThrows(UploadStorageException::class, static fn() => $upload->storeLocal($jpeg, $avatar), 'C6: publishing fails part-way');
$left = filesUnder($root);
check($e->getPrevious() instanceof ImageWriteException && \count($left) === 1 && \str_ends_with($left[0], '-small.jpg'), 'C6: exactly the committed outputs are removed');

// C7: a target appears during publishing; the occupied target is not Upload's.
[$upload, $root, $image] = $setup();
$foreign = null;
$image->onSave = static function (string $sourcePath, array $outputs) use (&$foreign): never {
	ScriptedImage::write(['thumb' => $outputs['thumb']]);
	\file_put_contents($foreign = $outputs['main']['path'], 'created by another process');

	throw new ImageTargetExistsException('Target exists: main', ['thumb' => $outputs['thumb']['path']], ['main' => $outputs['main']['path'], 'small' => $outputs['small']['path']], 'main');
};
$e = expectThrows(UploadStorageException::class, static fn() => $upload->storeLocal($jpeg, $avatar), 'C7: a target appears during publishing');
check(
	$e->getPrevious() instanceof ImageTargetExistsException && \file_get_contents((string)$foreign) === 'created by another process'
	&& filesUnder($root) === [\substr((string)$foreign, \strlen($root) + 1)],
	'C7: the committed variant is removed, and the occupied target is not touched'
);

[$upload, $root, $image] = $setup();
$image->onSave = static fn(string $sourcePath, array $outputs): never => throw new ImageTargetExistsException('Target exists: main', [], \array_column($outputs, 'path'), 'main');
$e = expectThrows(UploadStorageException::class, static fn() => $upload->storeLocal($jpeg, $avatar), 'C7: a conflict before processing');
check($e->getPrevious() instanceof ImageTargetExistsException && filesUnder($root) === [], 'C7: a conflict before processing removes nothing');

// C8: the original cannot be published after the image job.
[$upload, $root, $image] = $setup();
$target = $root . '/archive';
$planted = null;
$url = FaultSource::create(Fixtures::jpeg(), static function () use ($target, &$planted): void {
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
$e = expectThrows(UploadStorageException::class, static fn() => $upload->storeLocal($url, $archive, move: true), 'C8: the original cannot be published after the image job');
check(
	\str_contains($e->getMessage(), 'already exists') && $planted !== null && \file_get_contents($planted) === 'not written by this call'
	&& filesUnder($root) === ['archive/' . \basename($planted)],
	'C8: the image outputs and the temporary file are removed, and the existing file is not touched'
);
check(FaultSource::exists($url) && \count($image->calls) === 2, 'C8: the moved source is untouched');

// C9: hashing fails after everything was placed.
if (canForceUnreadableFile()) {
	foreach (['a kept original' => $archive, 'a re-encoded main' => ['hash' => 'sha256'] + $avatar] as $what => $profile) {
		[$upload, $root] = $setup(['file_mode' => 0]);
		$e = expectThrows(UploadStorageException::class, static fn() => $upload->storeLocal($jpeg, $profile), "C9: hashing {$what} fails");
		check(\str_contains($e->getMessage(), 'Failed to hash') && filesUnder($root) === [], "C9: everything the call placed is removed ({$what})");
	}
} else {
	skip('C9: a failed hash after the image job is compensated', 'file permissions are not enforced for this user or platform');
}

// C10: compensation cannot remove a committed output; a throwing log masks nothing.
if (canForceReadOnlyDir()) {
	foreach (['recording' => null, 'throwing' => new \RuntimeException('log is down')] as $case => $failure) {
		$log = new RecordingLog();
		$log->failWith = $failure;
		[$upload, $root, $image] = $setup([], ['log' => $log]);
		$locked = null;
		$image->onSave = static function (string $sourcePath, array $outputs) use (&$locked): never {
			ScriptedImage::write(['thumb' => $outputs['thumb']]);
			\chmod($locked = \dirname($outputs['thumb']['path']), 0555);

			throw new ImageWriteException('Failed to publish "main".', ['thumb' => $outputs['thumb']['path']], ['main' => $outputs['main']['path'], 'small' => $outputs['small']['path']]);
		};

		try {
			$e = expectThrows(UploadStorageException::class, static fn() => $upload->storeLocal($jpeg, $avatar), "C10: compensation cannot remove a committed output ({$case} log)");
		} finally {
			if ($locked !== null) {
				\chmod($locked, 0755);
			}
		}

		check($e->getPrevious() instanceof ImageWriteException, "C10: the original exception surfaces unmasked ({$case} log)");
		check(
			\count($log->entries) === 1 && $log->entries[0]['category'] === 'cleanup' && \str_ends_with($log->entries[0]['context']['path'], '-thumb.webp'),
			"C10: the failed removal is logged ({$case} log)"
		);
	}
} else {
	skip('C10: a failed removal of a committed output is logged, and a throwing log masks nothing', 'directories cannot be made read-only for this user or platform');
}

done('image_path_test');
