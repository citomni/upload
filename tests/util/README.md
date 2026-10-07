# Util suite

Isolated checks for the pure helpers `StoragePath`, `OriginalName`, and `MimeMap`, the `UploadRejection` vocabulary, and the exception hierarchy. No Composer, no database, no environment variables, and no files written.

```
php tests/util/run.php
```

Expected: `21 passed, 0 failed`

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a case fails.

## Cases

`StoragePath`:

- `validateRelative()` returns valid paths unchanged, `''` included, and rejects every path outside the rules: leading, trailing, or repeated `/`, backslashes, `.` and `..` segments, dotfiles, characters outside `[A-Za-z0-9._~-]`, NUL, and a trailing newline. The message quotes the path with control characters escaped.
- `join()` skips empty parts, joins with `/`, and validates the result.
- `variant()` derives `{dir}/{name}-{variant}.{extension}`, replaces only the last extension, and rejects invalid variant keys, extensions, and main paths. The util does not reserve `main`; profiles do.

`OriginalName::sanitize()`:

- Keeps only the file name for Windows, POSIX, and mixed separators, with multibyte characters and inner whitespace.
- Gives `file` when nothing is left.
- Removes C0 and C1 controls, DEL, and the bidi controls; scrubs invalid UTF-8; trims ASCII and Unicode whitespace; and cuts a name to 255 characters without splitting one.

`MimeMap`:

- `canonical()` lowercases and maps the known aliases (`image/x-ms-bmp`, `image/x-bmp`, `image/heif`, `application/x-rar-compressed`, `application/x-rar`).
- `extension()` follows the curated map for types that are not image formats, derives image extensions from every `ImageFormat` case (`jpeg` as `jpg`, `tiff` as `tif`), and gives `bin` for dangerous, unknown, and non-canonical types.
- `imageFormat()` routes the MIME type of every `ImageFormat` case and nothing else.

Rejections and exceptions:

- `UploadRejection` has exactly the specified cases, i18n keys, and English fallback messages.
- `UploadRejectedException` exposes `reason` and `context`, with the log message `Upload rejected: {reason}`, code 0, and the previous exception.
- `UploadException` is abstract and extends `\RuntimeException`; the three Upload exceptions are final subclasses of it.
