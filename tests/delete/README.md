# Delete suite

Isolated checks for the deletion contract: strict `delete()` and `deleteWithVariants()` versus best-effort `cleanup()`, `cleanupWithVariants()`, and `cleanupStored()`. No Composer, no database, no environment variables.

```
php tests/delete/run.php
```

Expected as a non-root POSIX user: `31 passed, 0 failed`

Expected as root: `29 passed, 0 failed, 2 skipped`

`PASS` lines go to stdout, `FAIL` lines to stderr, and `SKIP` lines name the reason. The last line holds the totals, and the exit code is 1 when a case fails.

## How it works

Ordering and failures run on `tests/Support/MemoryStorage.php`, an in-memory storage behind the `uploadmem://` stream wrapper. It records every filesystem call Upload makes on the storage and refuses to unlink chosen files, so these cases run on every platform and also as root. The real filesystem runs end to end: stores through a scripted `citomni/image` double, then deletion of exactly what they wrote. Refused unlinks on a real filesystem need a non-root POSIX user or Windows.

## Cases

Paths, storages, and profiles:

- Every path is validated before the storage is touched, so one invalid path deletes nothing; `''` is rejected because it would address the storage root.
- An undefined storage or profile, and a storage root that does not exist, are configuration errors for every method; a missing root is not created.
- An image profile without the image service deletes nothing, while `delete()` by storage needs no image service.

`delete()`:

- Removes exactly the given files in the given order; a missing or repeated path counts as deleted, and no path does nothing. Nothing is logged.
- Stops at the first file it cannot delete and names the file, the storage, and the reason; a path that names a directory fails the same way.
- The storage root is checked on disk once per service.

`cleanup()`:

- Attempts every path, reports false when a file remains, and logs exactly the failure to `upload.jsonl` under `cleanup`, with `storage`, `path`, `stored_path`, and `error`.
- A missing file, no paths, and an existing file twice are true; the image service is never touched.
- A throwing log and a missing log service change nothing, and `cleanup()` in a `finally` block does not mask the exception in flight.

`*WithVariants()`:

- The variants go in profile order, then the main file, with extensions from the variant formats, without resolving the image service.
- Strict deletion stops at a failing variant and keeps the main file, so a retry completes it; cleanup attempts every file and logs a refused variant by its own path.
- A profile without image block deletes only the main file.

`cleanupStored()`:

- Without results it touches nothing and is true. It removes exactly the listed files, per result the variants in result order and then the main file, without a profile and without the image service.
- It attempts every file of every result, and logs a refused file by its own path.
- Every result is validated before the first filesystem call, every storage root before anything is removed, and each result is removed from its own storage. A list instead of results gets a message on how to pass a batch.
- In a `finally` block it does not mask the exception in flight.

Real filesystem:

- `deleteWithVariants()` and `cleanupWithVariants()` remove exactly what a store wrote, deleting again succeeds, and directories stay.
- `cleanupStored()` removes exactly what the stores wrote, from their results alone.
- A file the filesystem refuses to delete: `delete()` throws, `cleanup()` reports false and logs, and `delete()` succeeds once unlocked.
- A link that survives a refused unlink is not reported as gone, although its target does not exist.

## Skipped cases

| Case | Runs when |
|---|---|
| A real file the filesystem refuses to delete | A file can be made undeletable: not as root |
| A link that survives a refused unlink | A non-root POSIX user |
