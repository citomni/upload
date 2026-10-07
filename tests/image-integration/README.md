# Image integration suite

Isolated checks for the image path against the real `citomni/image` service: finfo against `inspect()` on crafted headers and on files `citomni/image` encoded, and the happy path with GD. Image correctness itself (orientation, geometry, codecs, metadata) is tested in `citomni/image`. No Composer, no database, no environment variables.

```
php tests/image-integration/run.php
```

Expected with ext-gd (JPEG, PNG, WebP, AVIF, and BMP support) and without ext-imagick: `17 passed, 0 failed, 3 skipped`

`PASS` lines go to stdout, `FAIL` lines to stderr, and `SKIP` lines name the reason. The last line holds the totals, and the exit code is 1 when a case fails.

## How it works

The six header cases need no codec, because `inspect()` reads headers only. The other cases build a 40x20 JPEG with GD, add EXIF orientation 6, and store it through the real service, or let `citomni/image` encode it into every format the runtime can encode. Each case skips on its own when ext-gd or a codec is missing. Every case stores into a fresh storage root below the suite's run directory, which is removed afterwards.

## Cases

- JPEG, PNG, GIF, WebP, BMP, and TIFF headers (6 cases): finfo and `inspect()` agree, and the original is kept with the dimensions `inspect()` reports.
- The GD-made JPEG carries EXIF orientation 6: stored 40x20, displayed 20x40.
- A re-encoded main is a WebP auto-oriented to 20x40, not upscaled, that decodes as reported and has the size of the Image result; its variant is a 10x10 WebP next to it; and `upload.file_mode` 0644 is applied to both.
- A kept original is bit-identical and hashed, reports the display dimensions, and its preview is rendered from the oriented picture.
- A real file of every `ImageFormat` (8 cases): finfo and `inspect()` agree on a file `citomni/image` encoded.
- An AVIF with major brand `mif1` is rejected fail-closed: finfo reports HEIF, `citomni/image` reports AVIF.

## Skipped cases

| Case | Runs when |
|---|---|
| Every case after the six header cases | ext-gd is loaded |
| The re-encoded main, its variant, the `file_mode` case, and the kept original | `citomni/image` can decode JPEG and encode WebP |
| The `file_mode` case | Not on Windows |
| A real file of a format | `citomni/image` can decode JPEG and encode that format. With GD alone, GIF, TIFF, and HEIC have no encoder, which gives the 3 skips above |
| An AVIF with major brand `mif1` | `citomni/image` can encode AVIF, and the encoded file has the major brand `avif` |
