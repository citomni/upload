# Read path suite

Isolated checks for the path methods `variantPath()`, `absolutePath()`, and `webPath()`: derivation, validation, storage and profile errors, the `web_path` rules, and the guarantee that the path methods make no filesystem call, create no finfo, and touch no service. No Composer, no database, no environment variables.

```
php tests/read-path/run.php
```

Expected: `12 passed, 0 failed`

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a case fails.

## How it works

The no-IO case uses a storage whose root is a `MemoryStorage` URL, which records every filesystem call, and a root that does not exist. A stat, an existence check, or `realpath()` would be recorded, throw, or change the returned string. A control case shows that the recorder does see the calls of a deletion.

## Cases

- `variantPath()` derives the paths a store wrote, for a re-encoded main and for a kept original. The extension follows the variant format (`jpeg` as `.jpg`, `tiff` as `.tif`) whatever the main file, and only the last extension of the main file is replaced.
- `variantPath()` rejects a variant the profile does not define (`main` included), a profile without image block, invalid paths, an undefined profile, and an image profile without the image service.
- `absolutePath()` joins the configured root and the path without doubling a trailing separator, without checking or creating the root; it rejects an undefined storage, an empty or non-string root, and invalid paths.
- `webPath()` serves the public baseline storage under `uploads/u` and has no web path for the private one. A `web_path` of `''` serves from the web root, a nested one is prefixed, and a missing key means no web path. An invalid `web_path` is rejected with a message naming the storage, and so are an undefined storage and invalid paths.
- The path methods make no filesystem call, create nothing, resolve no service, and create no finfo; the control case records the calls of a deletion.
