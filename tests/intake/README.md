# Intake suite

Isolated checks for the intake contract without HTTP: `UploadedFiles`, `storeUpload()`, `checkUpload()`, and `storeUploads()` up to and including the provenance check, `rejectionMessage()`, and the shipped language files. No Composer, no database, no environment variables.

```
php tests/intake/run.php
```

Expected: `31 passed, 0 failed`

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a case fails.

## How it works

`is_uploaded_file()` is false outside an HTTP upload, so every store and check here ends at the provenance check or before it. Everything past it is covered by `tests/http-upload/`. The txt service is a `RecordingTxt` double; the image service is a double that throws when it is resolved, which proves that intake never touches it.

## Cases

`UploadedFiles`:

- `one()` keeps the five PHP keys in order without `full_path` and extra keys, returns null for `UPLOAD_ERR_NO_FILE` and for malformed or shape-confused entries, and keeps every other error code for `storeUpload()` to map.
- `many()` lists the slots of `name[]` and of a keyed field in client order without keys, skips malformed slots, and returns `[]` for anything that is not a list of file slots.
- A `$path` selects a nested field only in full, a nested list, a keyed slot, or a list slot.

`storeUpload()`:

- Client-side upload errors are rejections with `upload_error` in the context; server-side and unknown codes are `UploadStorageException` naming the code. The original name in a context is sanitized.
- Developer and configuration errors (profile, `$subdir`, missing root, invalid `file_mode`) are reported before the upload error, and a malformed `$file` is a developer error.
- A raw single-file `$_FILES` entry is accepted; one the client sent as `name[]` is refused with a pointer to `UploadedFiles::one()`.
- A file that was not uploaded in the request is refused without fallback, and nothing is written.

`checkUpload()`:

- Profile errors come before the shape of `$file` and the upload error, and a malformed `$file` is a developer error naming `checkUpload()`.
- Every upload error code is mapped as in `storeUpload()`.
- Neither the storage root nor the modes are checked, because nothing is placed in storage.
- A file that was not uploaded in the request is refused without fallback, and nothing is written.

`storeUploads()`:

- An empty list stores nothing but still reports developer and configuration errors.
- Rejections are collected in order instead of stopping the batch; a server fault propagates unchanged and ends it.
- Every entry is checked before the storage root, a single entry instead of a list is a developer error, and a file that was not uploaded in the request is refused.
- The result is `#[\NoDiscard]`, and neither image nor txt is resolved.

Messages and language files:

- `rejectionMessage()` gives the English fallback in both forms without the txt service, and translates both forms through txt with the layer `citomni/upload`, the file `upload`, and the fallback as default.
- `language/en/upload.php` and `language/da/upload.php` have one key per `UploadRejection` value in enum order; the English texts equal the fallbacks, the Danish texts are the shipped ones, and no text is empty or has placeholders.
