# HTTP upload suite

End-to-end checks for `checkUpload()`, `storeUpload()`, and `storeUploads()` with real HTTP uploads through PHP's built-in web server. No Composer, no database, no environment variables.

```
php tests/http-upload/run.php
```

Expected as a non-root POSIX user with ext-gd: `37 passed, 0 failed`

Expected as root with ext-gd: `34 passed, 0 failed, 3 skipped`

`PASS` lines go to stdout, `FAIL` lines to stderr, and `SKIP` lines name the reason. The last line holds the totals, and the exit code is 1 when a case fails.

## How it works

`is_uploaded_file()` and `move_uploaded_file()` only accept files uploaded in the current request. The suite therefore starts PHP's built-in web server on 127.0.0.1 and a free port, with `server.php` as router, the same PHP binary and php.ini as the suite, and `upload_max_filesize=1K`. Each case sends raw multipart/form-data requests; the router calls `UploadedFiles`, `checkUpload()`, `storeUpload()`, `storeUploads()`, and `cleanupStored()` inside the request and reports results, exceptions, and the state of the uploaded files as JSON. The cases inspect storage directly. The server is stopped in `finally`.

A server that cannot be started is not a reason to skip: every case that sends a request fails with the reason.

| File | Role |
|---|---|
| `run.php` | Suite. Starts the server, sends the requests, and checks the reports and storage. |
| `server.php` | Router, not a suite; `tests/run.php` only collects `run.php` and `database.php`. |
| `../Support/HttpServer.php` | Starts and stops the server and sends the requests. |

## Cases

`$_FILES` shapes, from one request:

- `one()` normalizes a single field without `full_path`, which `many()` refuses; `many()` lists `name[]` without the empty slot, which `one()` refuses unless a slot is selected by path.
- A keyed field needs its key as path; PHP strips the client directory from `name` and reports it in `full_path`, which normalization drops. Deeper nesting needs the full path, a nested list keeps the client order, and a missing field gives null.

Storing an upload:

- A plain upload is moved into storage with the documented result: detected type, measured size, hash, sanitized name, and no temporary file. The upload is consumed, so storing the entry again is a developer error.
- The default `file_mode` 0644 is applied, and with `file_mode` null an upload keeps the `0666 & ~umask` of `move_uploaded_file()`.

Upload errors and validation:

- `UPLOAD_ERR_INI_SIZE` and `UPLOAD_ERR_FORM_SIZE` are `TooLarge`, a truncated request is `Partial`, no chosen file is `NoFile`, a zero-byte upload is `Empty`, and an upload above `max_bytes` is `TooLarge`; none of them writes anything.
- A rejected upload stays with PHP and can be stored again within the request, and PHP code is never stored as `.php` or `.jpg`.
- A path that is not an upload of the request is refused during an upload request too.
- A failure after the move is compensated, and a failed `move_uploaded_file()` writes nothing and leaves the upload with PHP.

Image uploads:

- A kept original is moved into storage after the image job; a re-encoded main leaves the upload with PHP, so the entry can be stored again.
- With the real `citomni/image`, an uploaded JPEG is kept bit for bit, and its preview is rendered from it.

`checkUpload()`:

- It reports the detected type, the measured size, and the sanitized name, leaves the upload with PHP byte for byte, and writes nothing; the checked upload can still be stored.
- It needs neither a provisioned root nor a valid `file_mode`, rejects as `storeUpload()` does with the same contexts, and refuses a path that is not an upload of the request.
- On the image path it reports the display size from inspection without an image job, and finfo and `citomni/image` must agree.

`storeUploads()`:

- Partial acceptance, from one request: every accepted file is stored in client order as a `storeUpload()` result, every rejection is collected in client order with its context, stored uploads are consumed, and rejected ones stay with PHP.
- All-or-nothing on top: `cleanupStored()` discards a batch with a rejection.
- A fault part-way removes the files stored before it and attempts no later entry; a malformed entry is reported before anything is stored.
- An image batch stores each kept original with its preview, and `cleanupStored()` removes a discarded image result; an `ImageCapabilityException` part-way propagates unchanged, and the files of the photo before it are removed.
- A removal the filesystem refuses during batch compensation is logged and does not mask the exception in flight.

## Skipped cases

| Case | Runs when |
|---|---|
| The default `file_mode` and `file_mode` null (2 cases) | Not on Windows |
| A failure after the move is compensated | A file with mode 0000 cannot be read: not as root, not on Windows |
| A failed `move_uploaded_file()` | A directory with mode 0555 refuses new files: not as root, not on Windows |
| With the real `citomni/image` | ext-gd is loaded, and `citomni/image` can decode JPEG and encode WebP |
| A refused removal during batch compensation | A non-root POSIX user |
