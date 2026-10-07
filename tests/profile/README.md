# Profile suite

Isolated checks for the profile and configuration contract of `CitOmni\Upload\Service\Upload`: the shipped Registry baseline, every profile rule, the image block, `upload.file_mode` and `upload.dir_mode`, named versus inline profiles, memoization, and layered configuration. No Composer, no database, no environment variables.

```
php tests/profile/run.php
```

Expected: `23 passed, 0 failed`

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a case fails.

## How it works

Profiles are exercised through `storeLocal()` with a text source. Text takes the plain path in every valid profile, so the image service, a double that throws when it is resolved, is never involved. Configuration is the shipped `Registry::CFG_COMMON` plus per-case layers, merged with the kernel's `Arr::mergeAssocLastWins()` as App merges it. Files are written below the suite's run directory, which is removed afterwards.

## Cases

Registry:

- `MAP_COMMON` registers the `upload` service, `CFG_COMMON` is the documented baseline, and the Registry declares nothing else.

Profile rules:

- An inline profile that breaks any of 61 rules (keys, storage, directory, accept, max_bytes, shard, hash, image block, main and variant specs, formats) throws `UploadConfigException` naming the inline profile and writes nothing.
- An invalid `directory` is a configuration error with the path error as previous, in an inline and in a named profile.
- An undefined name, a scalar or list as profile, an invalid variant or main format, and an array as format (a kernel that flattens enum cases) each give a message that names the profile and the problem.

Modes:

- Invalid `upload.file_mode` and `upload.dir_mode` values are rejected with a message naming the key, before content validation; valid values are accepted.

Valid profiles:

- 15 valid shapes are accepted without resolving the image service on the plain path, and an uppercase hash algorithm is normalized to lowercase.

Memoization:

- A named profile is memoized per name, a fresh service reads swapped configuration, a profile that fails validation is not memoized, and inline profiles are normalized on every call.

Canonicalization and merging:

- `accept` entries are canonicalized: `IMAGE/X-MS-BMP` accepts a BMP stored as `image/bmp`.
- A configuration layer replaces a package profile's `accept` list and deep-merges its other keys; the merged profile applies the narrowed list and keeps the package directory and sharding.

Image block:

- An image block without the image service is a configuration error naming the profile and the missing provider, for every file; a profile without image block needs no image service.
- `image.options` must be an array.
