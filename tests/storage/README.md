# Storage suite

Isolated checks for the storage contract of `storeLocal()` on the plain path: generic validation before any write, source checks, placement, storage roots, the profile directory versus `$subdir`, file and directory modes, hashing, the result, routing, `$move`, and compensation. No Composer, no database, no environment variables.

```
php tests/storage/run.php
```

Expected as a non-root POSIX user: `34 passed, 0 failed`

Expected as root: `30 passed, 0 failed, 4 skipped`

`PASS` lines go to stdout, `FAIL` lines to stderr, and `SKIP` lines name the reason. The last line holds the totals, and the exit code is 1 when a case fails.

## How it works

Every case stores into a fresh storage root below the suite's run directory, which is removed afterwards. Failures that cannot be arranged in advance are forced through the filesystem where the platform enforces permissions, and through the test-only `FaultSource` stream wrapper where the target name or a change during the call is needed: a target that appears while `copy()` runs, a read error halfway through `copy()`, or a directory that turns read-only during it. Upload itself has no test seams.

## Cases

Generic validation:

- An empty file (`Empty`), a file above `max_bytes` (`TooLarge`), a type outside `accept` (`TypeNotAllowed`), and PHP code named `.jpg` are rejected with their contexts, and a rejection creates nothing in storage, not even a directory.
- A file of exactly `max_bytes` is accepted, and `max_bytes` null sets no limit.
- `['*']` accepts any type; an unknown type is stored as `.bin`, and PHP code gets the extension of its detected type, never `.php` or `.jpg`.
- A BMP is stored as `image/bmp` whichever alias finfo reports, and a detected `image/heif` is canonicalized to `image/heic` before `accept` is matched.

Source and placement:

- A missing, directory, or unreadable source is a developer error.
- Directory, `$subdir`, and 0 to 2 shard levels make up the path of a 32-hex name; every store writes a byte copy under a new name and leaves no temporary file; a file without directory lands in the root; a root with a trailing slash works.

Storage roots and paths:

- An unprovisioned baseline root is a configuration error naming the storage, checked before content, and never created; so is a custom storage with a missing, non-string, or absent root.
- The verified root is memoized per storage, and a fresh service checks again.
- An invalid profile directory is a configuration error with the path error as previous, while the same path as `$subdir` is a developer error, checked before the source.

Modes, hash, result, and routing:

- `file_mode` is applied to the stored file and `dir_mode` to every created directory; `file_mode` null keeps the mode `copy()` created.
- `hash` is `{algorithm}:{hex}` of the stored bytes, or null.
- The result of a plain file has the documented keys in order; an explicit original name is sanitized metadata that never reaches the path.
- A PDF in an image profile and a JPEG in a plain profile take the plain path without resolving the image service.

`$move`:

- A rejection or a developer error leaves the source untouched; a successful store removes it, and without `$move` it stays.
- A source that cannot be removed keeps the result standing and is logged once, with a recording log, a throwing log, and no log service.
- A failure after the copy (hashing an unreadable file) is compensated and leaves the source untouched.

Compensation:

- An existing target is refused and never touched, a partial temporary file from a failed `copy()` is removed, and in both cases the source stays.
- When the target directory turns read-only during `copy()`, the rename failure surfaces unmasked, the temporary file that cannot be removed stays behind dot-prefixed, and the failed removal is logged, with a recording log, a throwing log, and no log service.

## Skipped cases

| Case | Runs when |
|---|---|
| An unreadable source is a developer error | A file with mode 0000 cannot be read: not as root, not on Windows |
| `file_mode` and `dir_mode` are applied (2 cases) | Not on Windows |
| A source that cannot be removed keeps the result standing | A file can be made undeletable: not as root |
| A failure after the copy is compensated | A file with mode 0000 cannot be read: not as root, not on Windows |
| A directory that turns read-only during `copy()` | A directory with mode 0555 refuses new files: not as root, not on Windows |
| A detected `image/heif` is canonicalized | finfo reports `image/heif` for the HEIF fixture, as PHP's bundled libmagic does |

The permission conditions are decided by probing the behavior, not by the user ID.
