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
 * Regression checks for the pure helpers: StoragePath (lexical rules, join,
 * variant derivation), OriginalName (client file name sanitization), and
 * MimeMap (aliases, curated and format-derived extensions, image-format
 * routing), plus the UploadRejection vocabulary and the exception hierarchy.
 */

require __DIR__ . '/bootstrap.php';

use CitOmni\Image\Enum\ImageFormat;
use CitOmni\Upload\Enum\UploadRejection;
use CitOmni\Upload\Exception\UploadConfigException;
use CitOmni\Upload\Exception\UploadException;
use CitOmni\Upload\Exception\UploadRejectedException;
use CitOmni\Upload\Exception\UploadStorageException;
use CitOmni\Upload\Util\MimeMap;
use CitOmni\Upload\Util\OriginalName;
use CitOmni\Upload\Util\StoragePath;

// -- StoragePath::validateRelative() --------------------------------------------------

$valid = ['', 'a', 'a/b', 'users/42/profile', 'A-Z_0.9~x', 'a.b/c.d', 'x~y/z-1', '9f/86/9f86d081884c7d65.webp', 'a..b', '-', '~', '_'];

foreach ($valid as $path) {
	check(StoragePath::validateRelative($path) === $path, "validateRelative() returns '{$path}' unchanged");
}

$invalid = [
	'/a' => 'a leading slash',
	'/' => 'a lone slash',
	'a/' => 'a trailing slash',
	'a//b' => 'a repeated slash',
	'//' => 'only slashes',
	'a\\b' => 'a backslash',
	'.' => 'a "." segment',
	'..' => 'a ".." segment',
	'a/./b' => 'an inner "." segment',
	'a/../b' => 'an inner ".." segment',
	'../a' => 'a leading ".." segment',
	'.hidden' => 'a dotfile',
	'a/.git' => 'a dot directory',
	'a b' => 'a space',
	'æøå' => 'non-ASCII characters',
	'a%2Fb' => 'a percent sign',
	'a:b' => 'a colon',
	'a?b' => 'a question mark',
	'a*b' => 'an asterisk',
	"a\0b" => 'a NUL byte',
	"a\nb" => 'an inner newline',
	"a\n" => 'a trailing newline (the pattern is anchored with D)',
];

foreach ($invalid as $path => $why) {
	expectThrows(\InvalidArgumentException::class, static fn() => StoragePath::validateRelative((string)$path), "validateRelative() rejects {$why}");
}

$e = expectThrows(\InvalidArgumentException::class, static fn() => StoragePath::validateRelative("a\nb"), 'validateRelative() message');
check(\str_contains($e->getMessage(), '"a\\nb"'), 'the message quotes the path with control characters escaped');

// -- StoragePath::join() --------------------------------------------------------------

check(StoragePath::join() === '', 'join() without parts is ""');
check(StoragePath::join('', '', '') === '', 'join() of empty parts is ""');
check(StoragePath::join('a', '', 'b') === 'a/b', 'join() skips empty parts');
check(StoragePath::join('a/b', 'c', 'd.txt') === 'a/b/c/d.txt', 'join() joins with "/"');
check(StoragePath::join('', 'token/profile', '9f') === 'token/profile/9f', 'join() with an empty first part');

foreach ([['a/', 'b'], ['/a'], ['a', '..'], ['a', 'b\\c'], ['a', '.tmp'], ['a', 'b c']] as $parts) {
	expectThrows(\InvalidArgumentException::class, static fn() => StoragePath::join(...$parts), 'join() validates the joined result: ' . \json_encode($parts));
}

// -- StoragePath::variant() -----------------------------------------------------------

check(StoragePath::variant('users/42/9f86d081.jpg', 'thumb', 'webp') === 'users/42/9f86d081-thumb.webp', 'variant(): {dir}/{name}-{variant}.{extension}');
check(StoragePath::variant('9f86d081.jpg', 'thumb', 'webp') === '9f86d081-thumb.webp', 'variant(): main file at the storage root');
check(StoragePath::variant('a/b.c.d', 'v_1', 'png') === 'a/b.c-v_1.png', 'variant(): only the last extension is replaced');
check(StoragePath::variant('a/noext', 'thumb', 'webp') === 'a/noext-thumb.webp', 'variant(): main file without extension');
check(StoragePath::variant('a/b.', 'x', 'jpg') === 'a/b-x.jpg', 'variant(): main file ending with a dot');
check(StoragePath::variant('a/b.jpg', 'main', 'jpg') === 'a/b-main.jpg', 'variant(): the util does not reserve "main" (profiles do)');

foreach (['Thumb', 'th-umb', 'a.b', '', 'thumb ', 'æ', "thumb\n"] as $variant) {
	expectThrows(\InvalidArgumentException::class, static fn() => StoragePath::variant('a/b.jpg', $variant, 'webp'), 'variant() rejects variant key ' . \json_encode($variant));
}

foreach (['WEBP', 'we.bp', '', 'we-bp', '.webp', "webp\n"] as $extension) {
	expectThrows(\InvalidArgumentException::class, static fn() => StoragePath::variant('a/b.jpg', 'thumb', $extension), 'variant() rejects extension ' . \json_encode($extension));
}

foreach (['', '/a.jpg', 'a/../b.jpg', 'a//b.jpg', '.a.jpg', 'a\\b.jpg'] as $mainPath) {
	expectThrows(\InvalidArgumentException::class, static fn() => StoragePath::variant($mainPath, 'thumb', 'webp'), 'variant() rejects main path ' . \json_encode($mainPath));
}

// -- OriginalName::sanitize() ---------------------------------------------------------

check(OriginalName::sanitize('C:\\fakepath\\x.jpg') === 'x.jpg', 'sanitize(): browser fakepath is reduced to the file name');
check(OriginalName::sanitize('/home/user/report.pdf') === 'report.pdf', 'sanitize(): POSIX path is reduced to the file name');
check(OriginalName::sanitize('a/b\\c.txt') === 'c.txt', 'sanitize(): mixed separators');
check(OriginalName::sanitize('Profilbillede æøå.JPG') === 'Profilbillede æøå.JPG', 'sanitize(): multibyte names and inner spaces are kept');
check(OriginalName::sanitize('a  b.pdf') === 'a  b.pdf', 'sanitize(): inner whitespace is kept');

foreach ([null, '', '   ', "\t\n", 'dir/', 'C:\\dir\\', "\u{202E}", "\x00\x7F"] as $empty) {
	check(OriginalName::sanitize($empty) === 'file', 'sanitize(): empty result becomes "file" for ' . \json_encode($empty, \JSON_INVALID_UTF8_SUBSTITUTE));
}

check(OriginalName::sanitize("a\x00b\x01c\x1Fd\x7Fe.txt") === 'abcde.txt', 'sanitize(): C0 controls and DEL are removed');
check(OriginalName::sanitize("a\u{0080}b\u{0085}c\u{009F}d.txt") === 'abcd.txt', 'sanitize(): C1 controls are removed');
check(OriginalName::sanitize("invoice\u{202E}fdp.exe") === 'invoicefdp.exe', 'sanitize(): right-to-left override is removed');
check(
	OriginalName::sanitize("a\u{202A}b\u{202B}c\u{202C}d\u{202D}e\u{2066}f\u{2067}g\u{2068}h\u{2069}i.txt") === 'abcdefghi.txt',
	'sanitize(): bidi embeddings, overrides, and isolates are removed'
);

$scrubbed = OriginalName::sanitize("ab\xFF\xFEcd.txt");
check(
	\mb_check_encoding($scrubbed, 'UTF-8') && \str_starts_with($scrubbed, 'ab') && \str_ends_with($scrubbed, 'cd.txt') && !\str_contains($scrubbed, "\xFF"),
	'sanitize(): invalid UTF-8 is scrubbed and the valid parts survive'
);

check(OriginalName::sanitize("  report.pdf \t") === 'report.pdf', 'sanitize(): ASCII whitespace is trimmed');
check(OriginalName::sanitize("\u{00A0}report.pdf\u{3000}") === 'report.pdf', 'sanitize(): Unicode whitespace is trimmed');

$long = OriginalName::sanitize(\str_repeat('é', 300));
check(\mb_strlen($long, 'UTF-8') === 255 && \mb_check_encoding($long, 'UTF-8'), 'sanitize(): long names are cut to 255 characters without splitting one');
check(OriginalName::sanitize(\str_repeat('a', 255)) === \str_repeat('a', 255), 'sanitize(): 255 characters are kept');

// -- MimeMap::canonical() -------------------------------------------------------------

check(MimeMap::canonical('IMAGE/JPEG') === 'image/jpeg', 'canonical() lowercases');
check(MimeMap::canonical('image/bmp') === 'image/bmp', 'canonical() keeps canonical types');
check(MimeMap::canonical('Application/X-Foo') === 'application/x-foo', 'canonical() lowercases unknown types and keeps them otherwise');

$aliases = [
	'image/x-ms-bmp' => 'image/bmp',
	'image/x-bmp' => 'image/bmp',
	'image/heif' => 'image/heic',
	'application/x-rar-compressed' => 'application/vnd.rar',
	'application/x-rar' => 'application/vnd.rar',
	'Image/X-MS-BMP' => 'image/bmp',
	'IMAGE/HEIF' => 'image/heic',
];

foreach ($aliases as $alias => $canonical) {
	check(MimeMap::canonical($alias) === $canonical, "canonical() maps {$alias} to {$canonical}");
}

// -- MimeMap::extension() -------------------------------------------------------------

$extensions = [
	'image/svg+xml' => 'svg',
	'application/pdf' => 'pdf',
	'text/plain' => 'txt',
	'text/csv' => 'csv',
	'application/xml' => 'xml',
	'text/xml' => 'xml',
	'application/json' => 'json',
	'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
	'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
	'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
	'application/vnd.oasis.opendocument.text' => 'odt',
	'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
	'application/vnd.oasis.opendocument.presentation' => 'odp',
	'application/zip' => 'zip',
	'application/vnd.rar' => 'rar',
	'application/epub+zip' => 'epub',
	'message/rfc822' => 'eml',
];

foreach ($extensions as $mime => $extension) {
	check(MimeMap::extension($mime) === $extension, "extension({$mime}) is {$extension}");
}

// Expected extension per ImageFormat case: the format value, except jpeg and tiff.
$formatExtensions = [
	'jpeg' => 'jpg',
	'png' => 'png',
	'gif' => 'gif',
	'webp' => 'webp',
	'avif' => 'avif',
	'bmp' => 'bmp',
	'tiff' => 'tif',
	'heic' => 'heic',
];

// A case citomni/image adds later must follow the same rule: its value is the extension.
foreach (ImageFormat::cases() as $format) {
	$expected = $formatExtensions[$format->value] ?? $format->value;
	check(
		MimeMap::extension($format->mime()) === $expected && \preg_match('~^[a-z0-9]+$~D', $expected) === 1,
		"extension({$format->mime()}) is {$expected} ({$format->name})"
	);
}

$formatValues = \array_map(static fn(ImageFormat $format): string => $format->value, ImageFormat::cases());
check(\array_diff(\array_keys($formatExtensions), $formatValues) === [], 'every expected format extension belongs to an ImageFormat case');

$dangerous = [
	'text/html', 'application/xhtml+xml', 'application/javascript', 'text/javascript', 'application/x-php', 'text/x-php',
	'application/x-msdownload', 'application/x-executable', 'application/x-sharedlib', 'application/x-sh',
	'application/octet-stream', 'application/x-empty', 'IMAGE/JPEG', 'image/x-ms-bmp', '',
];

foreach ($dangerous as $mime) {
	check(MimeMap::extension($mime) === 'bin', "extension({$mime}) is bin");
}

// -- MimeMap::imageFormat() -----------------------------------------------------------

foreach (ImageFormat::cases() as $format) {
	check(MimeMap::imageFormat($format->mime()) === $format, "imageFormat() routes {$format->mime()} to {$format->name}");
	check(MimeMap::canonical($format->mime()) === $format->mime(), "the MIME type of {$format->name} is canonical");
}

foreach (['image/svg+xml', 'application/pdf', 'text/plain', 'image/x-ms-bmp', 'image/heif', ''] as $mime) {
	check(MimeMap::imageFormat($mime) === null, "imageFormat({$mime}) is null (not an image format, or not canonical)");
}

// -- UploadRejection ------------------------------------------------------------------

$rejections = [
	'NoFile' => ['no_file', 'No file was uploaded.'],
	'Partial' => ['partial', 'The file was only partially uploaded. Please try again.'],
	'TooLarge' => ['too_large', 'The file is too large.'],
	'Empty' => ['empty', 'The file is empty.'],
	'TypeNotAllowed' => ['type_not_allowed', 'This file type is not allowed.'],
	'InvalidImage' => ['invalid_image', 'The image could not be processed. It may be damaged, too large, animated, or in an unsupported format.'],
];

check(\array_map(static fn(UploadRejection $case): string => $case->name, UploadRejection::cases()) === \array_keys($rejections), 'UploadRejection has exactly the specified cases, in order');

foreach (UploadRejection::cases() as $case) {
	check($case->value === $rejections[$case->name][0], "UploadRejection::{$case->name} has the i18n key {$rejections[$case->name][0]}");
	check($case->fallbackMessage() === $rejections[$case->name][1], "UploadRejection::{$case->name} fallback message");
}

// -- Exceptions -----------------------------------------------------------------------

$previous = new \RuntimeException('cause');
$rejected = new UploadRejectedException(UploadRejection::TooLarge, ['size' => 11, 'max_bytes' => 10], $previous);
check($rejected->reason === UploadRejection::TooLarge && $rejected->context === ['size' => 11, 'max_bytes' => 10], 'UploadRejectedException exposes reason and context');
check($rejected->getMessage() === 'Upload rejected: too_large' && $rejected->getCode() === 0 && $rejected->getPrevious() === $previous, 'UploadRejectedException message, code, and previous');
check((new UploadRejectedException(UploadRejection::Empty))->context === [], 'UploadRejectedException context defaults to []');

check((new \ReflectionClass(UploadException::class))->isAbstract() && \is_subclass_of(UploadException::class, \RuntimeException::class), 'UploadException is abstract and extends RuntimeException');

foreach ([UploadRejectedException::class, UploadConfigException::class, UploadStorageException::class] as $class) {
	$reflection = new \ReflectionClass($class);
	check($reflection->isFinal() && $reflection->isSubclassOf(UploadException::class), "{$class} is final and extends UploadException");
}

done('util_test');
