# Image path suite

Isolated checks for the image path against a scripted `citomni/image` double: the outputs Upload hands to `save()`, the order of the image job and the original, MIME agreement, where every result field comes from, `upload.file_mode` on every placed file, the translation of `citomni/image` exceptions, and compensation after every failure point. No Composer, no database, no environment variables.

```
php tests/image-path/run.php
```

Expected as a non-root POSIX user: `25 passed, 0 failed`

Expected as root: `23 passed, 0 failed, 2 skipped`

`PASS` lines go to stdout, `FAIL` lines to stderr, and `SKIP` lines name the reason. The last line holds the totals, and the exit code is 1 when a case fails.

## How it works

`tests/Support/ScriptedImage.php` stands in for `CitOmni\Image\Service\Image`. It records every call, returns scripted `inspect()` metadata, and writes outputs like a successful `save()`, or runs a case's own `inspect()` or `save()` script, for example one that throws. The first case compares its `inspect()` and `save()` signatures with the real service through reflection, so the double cannot drift. Every case stores into a fresh storage root below the suite's run directory, which is removed afterwards.

## Cases

The double and the outputs:

- `ScriptedImage` has the signatures of `Image::inspect()` and `Image::save()`.
- A re-encoded main: `inspect()` runs first, then one `save()` with the source, the options unchanged, and existing target directories. The specs reach `save()` unchanged with the paths Upload chose, main first and the variants in profile order, without `overwrite`. The result reports the Image results for main and variants, and storage holds exactly the outputs.
- A kept original: the image job runs before the original is written, the original is stored bit for bit, the moved source is removed after the commit, and the result reports the original's type and size, the display dimensions, the hash, and the variants. Without outputs, only `inspect()` runs.

Routing and MIME agreement:

- A PDF in an image profile and an image in a plain profile never call `citomni/image`.
- finfo and `inspect()` agree for every `ImageFormat`, and a detected `image/heif` canonicalizes to `image/heic`.
- A disagreement is `TypeNotAllowed` with both types and writes nothing; an AVIF with the generic major brand `mif1` is rejected fail-closed.

Modes:

- `file_mode` 0644 and 0640 are applied to the re-encoded main, the variants, and the kept original; with `file_mode` null, outputs keep the mode they were written with; a failing `chmod` is a storage error, and everything placed is removed.

Exceptions and compensation:

- C1: `ImageInputException` from `inspect()` is `InvalidImage`; `save()` is not called, nothing is created, and the moved source stays.
- C3: `ImageInputException` from `save()` is `InvalidImage`, and the original is not written.
- C4: `ImageCapabilityException` and other runtime failures from `save()`, and a configuration error from `inspect()`, propagate unchanged.
- C5: `\InvalidArgumentException` from `save()` is `UploadConfigException` naming the profile, with the Image error as previous.
- C6: a partial publish removes exactly the outputs in `committed()`.
- C7: a target that appears during publishing is not touched while the committed variant is removed, and a conflict before processing removes nothing.
- C8: an original that cannot be published after the image job removes the outputs and the temporary file, and leaves the existing file and the source untouched.
- C9: a failed hash after the image job removes everything the call placed, for a kept original and a re-encoded main.
- C10: a committed output that compensation cannot remove is logged, and a throwing log masks nothing.

## Skipped cases

| Case | Runs when |
|---|---|
| `file_mode` on image outputs, `file_mode` null, and a failing `chmod` (3 cases) | Not on Windows |
| C9: a failed hash after the image job | A file with mode 0000 cannot be read: not as root, not on Windows |
| C10: a committed output that cannot be removed | A directory with mode 0555 refuses new files: not as root, not on Windows |
