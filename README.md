# CitOmni Upload

Validated file intake and local storage for CitOmni applications and packages, with image outputs delegated to `citomni/image`.

`citomni/upload` stores files uploaded in the current HTTP request, and local files, under opaque names in configured public and private storages. A named profile decides what is accepted, where it is stored, and which image outputs are produced.

Upload knows **when** an accepted file needs image processing, **which** outputs a profile wants, and **where** they go. It never knows **how** pixels are processed: that is `citomni/image`'s job.

---

## What this package is

`citomni/upload` is one transport-agnostic intake engine for HTTP and CLI. `storeUpload()` and `storeLocal()` handle one file:

1. It checks the profile, the arguments, and the storage configuration.
2. It validates the file: provenance, size, and the content type detected with `finfo`.
3. It stores the file under a fresh random name. For an image profile, `citomni/image` produces the image outputs at paths Upload chose.
4. It returns a plain array with the storage-relative path and metadata.

The application persists that array. Upload knows nothing about databases, users, or entities. For a multi-file field, `storeUploads()` runs the same steps for every file and returns the results together with the rejections.

---

## What this package owns

- Normalizing `$_FILES` entries, mapping PHP's upload error codes, and requiring `is_uploaded_file()`.
- The size limit per use case (`max_bytes`).
- Content-type detection with `finfo` and the `accept` policy.
- Storage roots, relative paths, directories, sharding, file names, and extensions.
- Writing plain files, including an image original kept byte for byte.
- The mode of every file placed in storage, `citomni/image`'s outputs included.
- Which image outputs a profile wants, and their roles.
- Removing what a failed store call wrote, including the outputs `citomni/image` committed and, for a batch, the files stored before the failure.
- The mechanics of a batch: one profile for every file, rejections collected instead of stopping, and removal of exactly the files a store result lists.
- Deleting stored files and deriving their paths.

---

## What this package does not own

- **Image processing.** Decoding, EXIF and HEIF orientation, resizing, cropping, fit geometry, alpha and flattening, encoding and quality settings, pixel and memory guards, codec and backend detection, image defaults (`image.*`), and the write safety of image outputs all belong to `citomni/image`. When a generic image capability is missing, `citomni/image` is extended; Upload does not reimplement it.
- **Persistence and domain rules.** Database access, media libraries, retention, limits such as a maximum number of files per entity, the batch policy (all-or-nothing or partial acceptance), and the decision to remove an old file belong to the application.
- **The public upload area.** CitOmni's application scaffold (`citomni/http`) provides the directory and its server configuration; Upload states its [requirements](#web-server-configuration-for-public-storage).
- **URLs.** `webPath()` returns a relative path; the base URL belongs to the HTTP layer.
- **Users, tenants, and access control.**
- **Cloud and object storage.**

---

## Relationship to other CitOmni packages

```text
citomni/kernel
      ↑
citomni/image
      ↑
citomni/upload
      ↑
application
```

- `citomni/image` is a hard dependency, so its requirements apply to Upload as well.
- Upload does not depend on `citomni/http`, `citomni/cli`, or `citomni/infrastructure`, and runs in HTTP and CLI mode alike.
- The `log` and `txt` services of `citomni/infrastructure` are used when they are registered: `log` records cleanup failures, and `txt` translates rejection messages. Without them, Upload relies on return values and English texts.
- Controllers and commands call Upload. Operations and Repositories persist its results.

---

## Requirements

- PHP **8.5+**
- `citomni/kernel` **^1.0.2.2** (the first release that merges `MAP_COMMON` and `CFG_COMMON`)
- `citomni/image` **^1.0**, and with it `ext-gd`, `ext-zlib`, and optionally `ext-imagick` (see its [requirements](https://github.com/citomni/image#requirements))
- PHP `ext-fileinfo` (content-type detection)
- PHP `ext-mbstring` (file name sanitizing)
- Optional: `citomni/infrastructure` for the `log` and `txt` services

---

## Installation

```bash
composer require citomni/upload
```

Register both providers in the application's `config/providers.php`:

```php
<?php
declare(strict_types=1);

return [
	\CitOmni\Image\Boot\Registry::class,
	\CitOmni\Upload\Boot\Registry::class,
];
```

A Composer dependency is not a service registration. Without the Image provider, neither `$this->app->image` nor the `image.*` configuration exists, and every use of a profile with an `image` block throws `UploadConfigException`, including calls that store a non-image file, delete files, or derive variant paths. There is no fallback.

The package registers the `upload` service (`$this->app->upload`) in HTTP and CLI mode, ships the configuration baseline under `upload` and the language files for rejection messages, and contributes no routes or commands.

---

## Quick start

Everything in the examples is illustrative. The profile names, controllers, Operations, Repositories, and tables do not exist in CitOmni, and the package ships no profiles.

Define a profile, typically in the application's `config/citomni_cfg.php`, which HTTP and CLI share:

```php
// config/citomni_cfg.php (illustrative)
return [
	'upload' => [
		'profiles' => [
			'avatar' => [
				'storage'   => 'public',
				'accept'    => ['image/jpeg', 'image/png', 'image/webp'],
				'max_bytes' => 15_000_000,
				'image'     => [
					// citomni/image output specs; quality and similar settings fall back to the image.* defaults.
					'main' => [
						'format' => 'webp',
						'width'  => 1600,
						'height' => 1600,
						'fit'    => 'contain',
					],
					'variants' => [
						'thumb' => [
							'format' => 'webp',
							'width'  => 300,
							'height' => 300,
							'fit'    => 'cover',
						],
					],
				],
			],
		],
	],
];
```

`avatar` has no `directory`. The caller passes the user's opaque upload token and a subdirectory as `$subdir`, so no internal ID appears in a public path:

```php
use CitOmni\Upload\Enum\UploadRejection;
use CitOmni\Upload\Exception\UploadRejectedException;
use CitOmni\Upload\Util\UploadedFiles;

class AvatarController extends BaseController {

	public function update(): void {
		// App-specific: the user and the user's opaque upload token.
		$userId = $this->resolveUserId();
		$uploadToken = $this->resolveUploadToken($userId);

		$file = UploadedFiles::one($_FILES['avatar'] ?? null);
		if ($file === null) {
			$message = $this->app->upload->rejectionMessage(UploadRejection::NoFile);
			// Render the form with $message (app-specific).
			return;
		}

		try {
			$stored = $this->app->upload->storeUpload($file, 'avatar', $uploadToken . '/profile');
		} catch (UploadRejectedException $e) {
			$message = $this->app->upload->rejectionMessage($e);
			// Render the form with $message (app-specific).
			return;
		}

		(new ReplaceAvatar($this->app))->execute($userId, $stored);
		// Redirect (app-specific).
	}
}
```

The adapter catches only `UploadRejectedException`; see [Errors and exceptions](#errors-and-exceptions). The returned array is described under [Result format](#result-format), and `ReplaceAvatar` under [Usage patterns](#usage-patterns).

---

## Configuration

The provider contributes this baseline:

```php
'upload' => [
	'storages' => [
		'public' => [
			'root'     => \CITOMNI_APP_PATH . '/public/uploads/u',
			'web_path' => 'uploads/u',
		],
		'private' => [
			'root'     => \CITOMNI_APP_PATH . '/var/storage',
			'web_path' => null,
		],
	],
	'file_mode' => 0644,
	'dir_mode'  => 0755,
	'profiles'  => [],
],
```

Applications override it through the normal CitOmni configuration merge.

### Storages

A storage is a `root` and an optional `web_path`. `storages` is an open map: applications override the baseline roots and add storages of their own, for example on a separate mount for large archives.

- `root` is the absolute path of an existing directory. The deployment provisions the roots; Upload creates validated subdirectories below a root, never the root itself. Store, delete, and cleanup calls throw `UploadConfigException` while the root does not exist; a successful check is memoized per service instance.
- `web_path` is `null` when the files are served only through a controller that authorizes each request, `''` when the storage root is the web root, or otherwise a relative path that follows the [path rules](#path-rules). A missing key counts as `null`. Any other value throws `UploadConfigException` naming the storage. Only `webPath()` reads and validates `web_path`.
- The baseline derives its roots from `CITOMNI_APP_PATH` because `CITOMNI_PUBLIC_PATH` is not defined in CLI. Applications with another layout override `root`.

### File and directory modes

- `file_mode` is an int from `0` to `0777`, or `null`. Upload applies it with `chmod()` to every file it places in storage, `citomni/image`'s outputs included, so all files of one upload end up with the same mode whatever the umask, and public variants stay readable for the web server. `null` keeps the mode the write created (`0666 & ~umask`).
- `dir_mode` is an int from `0` to `0777`, passed to `mkdir()` for new directories and subject to the umask.

Both are validated and memoized on the first store call. An invalid value throws `UploadConfigException`.

### Profiles

A profile is a named upload policy under `upload.profiles.<name>`. Every method that takes `string|array $profile` also accepts an inline profile array. Packages that use Upload ship their own profiles, named `<package>_<purpose>`.

| Key | Type | Default | Meaning |
|---|---|---|---|
| `storage` | string | required | Name of an entry in `upload.storages`. |
| `directory` | string | `''` | Base directory below the storage root. The call's `$subdir` is appended. |
| `accept` | list of strings | required | Accepted MIME types, or exactly `['*']`. |
| `max_bytes` | int or null | required key | Size limit in bytes, at least 1, or `null` for no limit on Upload's side. |
| `shard` | int | `0` | Number of shard directory levels, 0 to 2. |
| `hash` | string or null | `null` | An algorithm from `hash_algos()`, matched case-insensitively. The result then carries a hash of the main file. |
| `image` | array or null | `null` | Image outputs; see [The image block](#the-image-block). `null` stores images as plain files. |

- Unknown keys throw `UploadConfigException`. A typo such as `images` would otherwise store unprocessed originals, possibly with EXIF and GPS data, in a public storage.
- An explicit `null` is valid only where the type allows it (`max_bytes`, `hash`, `image`). It never stands in for a default.
- `accept` entries are lowercased and de-aliased like detected types (see [MIME types and extensions](#mime-types-and-extensions)) and must then be a bare `type/subtype` of RFC 6838 characters. Patterns such as `image/*`, parameters, and surrounding whitespace are rejected because they would never match a detected type. `'*'` cannot be combined with other entries.
- `max_bytes` is Upload's policy. It is not compared with PHP's `upload_max_filesize` or `post_max_size`, which act before Upload sees a file.
- Configuration layers merge associative arrays deeply but replace lists. An application that overrides a package profile's `accept` replaces the list instead of extending it.

### The image block

```php
'image' => [
	'main'     => [/* output spec */],
	'variants' => [
		'thumb' => [/* output spec */],
	],
	'options'  => [/* job options */],
],
```

The block decides which outputs exist and which role each has. The specs and options themselves are `citomni/image` vocabulary and are passed on unchanged: see its [output specifications](https://github.com/citomni/image#output-specifications) and [job options](https://github.com/citomni/image#job-options).

| Key | Rule |
|---|---|
| `main` | Required key. An output spec means that `citomni/image` produces the main file, re-encoded and without metadata. `null` means that Upload stores the original bytes untouched (passthrough). |
| `variants` | Map of output specs, default `[]`. Keys match `[a-z0-9_]+` and must not be `main`. `citomni/image` always produces variants from the source. |
| `options` | `citomni/image` job options for the whole job, default `[]`, which means the `image.*` defaults. Must be an array. |

- A spec must not contain `path` or `overwrite`. Upload sets `path` itself and never sets `overwrite`, so `citomni/image`'s no-clobber default applies.
- `format` is required. It is the only spec field Upload reads, because it decides the file's extension and the variant paths before `citomni/image` runs. It is a format string or a `CitOmni\Image\Enum\ImageFormat` case. Any other type is rejected, including the array that a kernel which flattens enum cases in configuration makes of an enum case; the string form always works.
- `citomni/image` validates everything else in a spec, and all options, in the first image job that uses the profile. An invalid transform, such as `fit: cover` without both dimensions, therefore surfaces at that job as `UploadConfigException` naming the profile, with `citomni/image`'s `\InvalidArgumentException` as `previous`.

### Policy ownership

A spec states only what is specific to the job. When `quality`, `compression`, or `background` is omitted, `citomni/image` applies its `image.*` defaults. Upload configuration holds no global image defaults:

```text
image.webp.quality = 80                               citomni/image owns the default
upload.profiles.avatar.image.main.quality = 90        allowed: a deliberate job-specific deviation
upload.image_quality, upload.defaults.webp.quality    not allowed: a competing global policy
```

### Validation

A profile is validated when it is resolved: a named profile on first use, after which it is memoized per name, and an inline profile on every call. A profile that fails validation is not memoized. Every violation throws `UploadConfigException` with a message that starts with the profile's name (`Upload profile 'avatar'`) or with `Inline upload profile`. That includes an unknown profile, an invalid `directory` (with the path error as `previous`), and an `image` block without the image service.

### Profile shapes

| Shape | Example | Behavior |
|---|---|---|
| Image with generated outputs | `avatar` ([Quick start](#quick-start)) | `citomni/image` produces the main file and a variant; metadata is stripped. |
| Plain files | `attachment` | No `image` block. Images are stored untouched, and `citomni/image` is never called. |
| Untouched original with a generated preview | `archived_document` | `main: null` keeps the original, `citomni/image` produces the preview, and the original is hashed. |

```php
// config/citomni_cfg.php (illustrative)
return [
	'upload' => [
		'profiles' => [
			'attachment' => [
				'storage'   => 'private',
				'directory' => 'attachments',
				'accept'    => ['application/pdf', 'image/jpeg', 'image/png'],
				'max_bytes' => 20_000_000,
				'shard'     => 1,
			],
			'archived_document' => [
				'storage'   => 'private',
				'directory' => 'archive',
				'accept'    => ['application/pdf', 'application/xml', 'text/xml', 'image/jpeg', 'image/png'],
				'max_bytes' => 25_000_000,
				'shard'     => 2,
				'hash'      => 'sha256',
				'image'     => [
					// Keep the original bytes untouched.
					'main'     => null,
					'variants' => [
						'preview' => ['format' => 'webp', 'width' => 1200],
					],
				],
			],
		],
	],
];
```

---

## Public API

```php
// CitOmni\Upload\Service\Upload, service id "upload"
final class Upload extends BaseService {

	#[\NoDiscard]
	public function storeUpload(array $file, string|array $profile, string $subdir = ''): array {}

	#[\NoDiscard]
	public function storeUploads(array $files, string|array $profile, string $subdir = ''): array {}

	#[\NoDiscard]
	public function storeLocal(string $sourcePath, string|array $profile, string $subdir = '', ?string $originalName = null, bool $move = false): array {}

	public function rejectionMessage(UploadRejectedException|UploadRejection $rejection): string {}

	public function delete(string $storage, string ...$paths): void {}

	public function deleteWithVariants(string $path, string|array $profile): void {}

	public function cleanup(string $storage, string ...$paths): bool {}

	public function cleanupWithVariants(string $path, string|array $profile): bool {}

	public function cleanupStored(array ...$stored): bool {}

	public function variantPath(string $path, string $variant, string|array $profile): string {}

	public function absolutePath(string $storage, string $path): string {}

	public function webPath(string $storage, string $path): string {}
}
```

Pure helpers in `CitOmni\Upload\Util`, without App and without IO:

```text
UploadedFiles::one(mixed $entry, int|string ...$path): ?array
UploadedFiles::many(mixed $entry, int|string ...$path): array
StoragePath::validateRelative(string $path): string
StoragePath::join(string ...$parts): string
StoragePath::variant(string $mainPath, string $variant, string $extension): string
OriginalName::sanitize(?string $name): string
MimeMap::canonical(string $mime): string
MimeMap::extension(string $canonicalMime): string
MimeMap::imageFormat(string $canonicalMime): ?ImageFormat
```

- `storeUpload()`, `storeUploads()`, and `storeLocal()` carry `#[\NoDiscard]`, because a stored file whose path is never persisted is an orphan. `delete*()` and `cleanup*()` do not; callers deliberately ignore cleanup results.
- The service does no work at construction. Named profiles, storage roots, web paths, modes, and the `finfo` instance are resolved lazily and memoized per service instance. `$this->app->image` is resolved only on the image path, because its initialization reads and validates `image.*`.
- Deletion and the path methods are described under [Deleting files and deriving paths](#deleting-files-and-deriving-paths).

### `storeUpload()`

Stores a file uploaded in the current HTTP request.

- `$file` is an entry from `UploadedFiles::one()` or `UploadedFiles::many()`, or a raw single-file `$_FILES` entry. Only `name` (string), `tmp_name` (string), and `error` (int) are checked; any other shape throws `\InvalidArgumentException`. If the client sent another nesting for the field, the raw values are arrays and the call throws where `UploadedFiles::one()` would return `null`, which makes `UploadedFiles` the recommended source.
- Developer and configuration errors are reported first, whatever the file contains: the profile, `$subdir`, the shape of `$file`, the storage root, and the modes. The PHP error code is mapped next (see [PHP upload error codes](#php-upload-error-codes)), and provenance is checked last, because PHP sets `tmp_name` to `''` when an upload fails.
- `is_uploaded_file()` is required without fallback. A file that was not uploaded in the current request throws `\InvalidArgumentException`; local files go through `storeLocal()`.
- The client's `type` and `size` are never used. Its `name` becomes sanitized metadata (`original_name`) and never part of a path.
- When Upload stores the original itself (a plain file, or an image whose `main` is `null`), it moves the upload with `move_uploaded_file()`, and storing the same entry again throws `\InvalidArgumentException`. A re-encoded main leaves the upload with PHP, which deletes it when the request ends.
- A rejection leaves the upload where PHP put it, so the same entry can be stored again within the request, for example with another profile. A failure after the move removes the moved data along with the call's other files; the client has to upload again.

### `storeUploads()`

Stores the files of a multi-file field with one profile. It is optional: it calls `storeUpload()` for every entry and adds the batch mechanics that nearly every multi-file form needs, while the batch policy stays with the application.

```php
$batch = $this->app->upload->storeUploads(UploadedFiles::many($_FILES['attachments'] ?? null), 'attachment', (string)$ownerId);
// ['stored' => list of results, 'rejected' => list of UploadRejectedException]
```

- `$files` is a list of entries, normally from `UploadedFiles::many()`, which skips slots without a file. Every entry is stored with the same profile and `$subdir`, in the given order.
- The profile, `$subdir`, the shape of every entry, the storage root, and the modes are checked first, in `storeUpload()`'s order and also for an empty list. A developer or configuration error therefore surfaces before anything is stored, even when no file was sent. An entry that is not an array with a string `name`, a string `tmp_name`, and an int `error` throws `\InvalidArgumentException`.
- Partial acceptance: a rejected file goes to `rejected`, and the next entry is stored. `stored` holds results in the [result format](#result-format) and `rejected` the exceptions, both as lists in client order; the keys of `$files` are not kept. Entries from `UploadedFiles::many()` never give `NoFile`, so every rejection's `context` then carries `original_name`, and the adapter can name the file. A rejected upload stays with PHP, as after a rejected `storeUpload()`.
- Any other exception aborts the batch: the files of the entries stored before it are removed (best effort, logged), and the exception propagates. The failing entry's own files are removed by `storeUpload()`, and later entries are not attempted.
- Persisting `stored` is the caller's commit point. All-or-nothing is a policy on top: remove `stored` with `cleanupStored()` when `rejected` is not empty. See [Many files per entity](#many-files-per-entity-with-a-maximum).
- An adapter that needs a profile or `$subdir` per file, or a flow of its own, such as stopping at the first rejection, loops over `storeUpload()` instead.

### `UploadedFiles`

`UploadedFiles` turns a `$_FILES` entry into flat entries with exactly the keys `name`, `type`, `tmp_name`, `error`, and `size`. `error` and `size` are ints, and `type` is the client's claim, which Upload never uses. The class reads no superglobals; the caller passes the entry, as in `UploadedFiles::one($_FILES['avatar'] ?? null)`.

| Form field | Call | Result |
|---|---|---|
| `avatar` | `one($entry)` | The entry. `many($entry)` returns `[]`. |
| `files[]` | `many($entry)` | A list in client order, without slots that hold no file. `one($entry)` returns `null`, and `one($entry, 0)` the first slot. |
| `files[front]` | `many($entry)` or `one($entry, 'front')` | A list without the keys, or that one entry. |
| `doc[a][b]`, `gallery[photos][]` | `one($entry, 'a', 'b')`, `many($entry, 'photos')` | The variadic `$path` selects the nested field. |

- Malformed input, such as a missing key, a value of the wrong type, or a path that does not exist, returns `null` or is skipped; it never throws. Extra keys are ignored, and the client-controlled `full_path` is dropped.
- `UPLOAD_ERR_NO_FILE` makes `one()` return `null` and is skipped by `many()`. Other error codes are kept, so `storeUpload()` can map them.
- Two limits act before Upload sees anything: a request above `post_max_size` arrives with an empty `$_FILES`, and files beyond `max_file_uploads` are dropped silently.

### `storeLocal()`

Stores a developer-trusted local file, for example in an import command. Content validation is the same as for `storeUpload()`.

- `$sourcePath` must be a readable regular file; otherwise `\InvalidArgumentException`.
- The source is always copied, never renamed or linked. A rename cannot be undone safely when a later step fails, and a hard link would share its inode with a source left behind, so later changes to that source would change the stored file.
- With `$move = true`, the source is removed only after the store succeeded, with cleanup semantics: a failed removal does not fail the call and is logged when `log` is registered. A failure before that point leaves the source untouched.
- `$originalName` is metadata only; `null` uses the source's file name.

```php
// CLI command (illustrative): the same Operation an HTTP adapter would use, with another intake.
$stored = $this->app->upload->storeLocal($path, 'archived_document', (string)$ownerId, move: true);
(new ArchiveDocument($this->app))->execute($ownerId, $stored);
```

`archived_document` stores in a private storage, where an internal ID in the path is acceptable.

### `rejectionMessage()`

Returns end-user text for a rejection. It takes the exception or its reason. The reason form serves an adapter whose `UploadedFiles::one()` returned `null`: `rejectionMessage(UploadRejection::NoFile)`.

- With the `txt` service registered, it returns `$this->app->txt->get($reason->value, 'upload', 'citomni/upload', $reason->fallbackMessage())`. Without it, it returns the English fallback.
- The texts have no placeholders. An adapter that wants to show a limit composes it from the exception's `context`, for example `max_bytes`.
- The package ships `language/en/upload.php` and `language/da/upload.php` with one key per `UploadRejection` value; the English file equals the fallback texts. Txt reads them from the application's `vendor/citomni/upload/language/{locale.language}/upload.php`, so `locale.language` must be a bare language code such as `'da'`. A value such as `'da_DK'` has no fallback to `'da'`: the result is the English text, and Txt logs the missing file.
- Applications cannot override the package's texts through Txt. An application that wants its own texts maps `$e->reason` in its adapter.
- Txt configuration errors, such as a missing `locale.language`, propagate unchanged. No other Upload method resolves `txt`.

---

## Result format

`storeUpload()` and `storeLocal()` return the same shape, with the keys in this order, and `storeUploads()` returns a list of them under `stored`. The example is an avatar stored with the `avatar` profile below the user's opaque upload token:

```php
[
	'storage'       => 'public',
	'path'          => 'k3f9x2q7/profile/9f86d081884c7d659a2feaa0c55ad015.webp',
	'mime'          => 'image/webp',
	'source_mime'   => 'image/jpeg',
	'size'          => 184321,
	'original_name' => 'IMG_2041.JPG',
	'width'         => 1600,
	'height'        => 1200,
	'hash'          => null,
	'variants'      => [
		'thumb' => [
			'path'   => 'k3f9x2q7/profile/9f86d081884c7d659a2feaa0c55ad015-thumb.webp',
			'mime'   => 'image/webp',
			'size'   => 12044,
			'width'  => 300,
			'height' => 300,
		],
	],
]
```

`path` is relative to the root of the storage that `storage` names. Persist it; `absolutePath()` and `webPath()` derive the rest.

| Field | Plain file | Kept original (`main: null`) | Produced main |
|---|---|---|---|
| `path` | Upload | Upload | Upload: the path given to `citomni/image` |
| `mime` | Detected type | Detected type | `citomni/image` result `mime` |
| `source_mime` | Detected type | Detected type | Detected type |
| `size` | Measured by Upload | Measured by Upload; the bytes are unchanged | `citomni/image` result `bytes` |
| `width`, `height` | `null` | `inspect()` display width and height | `citomni/image` result `width` and `height` |
| `hash` | Of the stored file | Of the stored original | Of the re-encoded main |
| `variants` | `[]` | Per variant: Upload's `path`, and `mime`, `size` (`bytes`), `width`, and `height` from `citomni/image` | As for a kept original |

- The detected type is the canonical `finfo` type. On the image path, `citomni/image` has detected the same type; see [Type agreement](#type-agreement).
- A kept original reports display dimensions, because browsers apply EXIF orientation when they show the untouched file.
- A file that is not an image in an image profile, such as a PDF in `archived_document`, follows the plain column.
- `hash` is `'{algorithm}:{hex}'`, such as `'sha256:9f86…'`, or `null` when the profile sets no `hash`.

---

## Errors and exceptions

```text
\RuntimeException
└── UploadException                   (abstract)
    ├── UploadRejectedException       Untrusted input was rejected. The only one adapters catch.
    ├── UploadConfigException         Invalid upload configuration or profile, unknown storage,
    │                                 missing root, a null or invalid web_path for webPath(), the
    │                                 image service not registered, or output specs or options
    │                                 that citomni/image rejects.
    └── UploadStorageException        Upload's own filesystem failure (strict deletion included),
                                      a server-side PHP upload error, or image outputs that
                                      citomni/image could not publish.

\InvalidArgumentException             Developer misuse: an invalid or empty path, an invalid
                                      $subdir, an unknown variant, a malformed $file or store
                                      result, a file not uploaded in the current request, or a
                                      local source that is not a readable regular file.
```

The Upload exceptions live in `CitOmni\Upload\Exception`.

- Adapters catch `UploadRejectedException` and nothing else. Catching `UploadException` would hide configuration and server faults behind a validation message. `storeUploads()` returns its rejections instead of throwing them; everything else propagates as from `storeUpload()`.
- Every other exception, including `\InvalidArgumentException` and `citomni/image`'s `ImageCapabilityException`, is a fault for the error handler.
- `UploadConfigException` messages name the profile or the configuration key.
- `UploadRejectedException` carries `public readonly UploadRejection $reason` and `public readonly array $context`. Its message (`Upload rejected: {reason}`) is meant for logs. A rejected call leaves no file in storage.

### Rejections

The reasons are cases of `CitOmni\Upload\Enum\UploadRejection`:

| Reason | Value | Raised when | `context` |
|---|---|---|---|
| `NoFile` | `no_file` | `UPLOAD_ERR_NO_FILE` reaches `storeUpload()` | `upload_error` |
| `Partial` | `partial` | `UPLOAD_ERR_PARTIAL` | `original_name`, `upload_error` |
| `TooLarge` | `too_large` | `UPLOAD_ERR_INI_SIZE` or `UPLOAD_ERR_FORM_SIZE` | `original_name`, `upload_error` |
| `TooLarge` | `too_large` | The size exceeds `max_bytes` | `original_name`, `size`, `max_bytes` |
| `Empty` | `empty` | The file has 0 bytes | `original_name`, `size` (`0`) |
| `TypeNotAllowed` | `type_not_allowed` | The detected type is not accepted, or `finfo` and `citomni/image` disagree | `original_name`, `size`, `mime`; on disagreement also `image_mime` |
| `InvalidImage` | `invalid_image` | `citomni/image` rejects the content (`ImageInputException`) | `original_name`, `size`, `mime`; the image exception is `previous` |

- `upload_error` holds the `UPLOAD_ERR_*` code, so `INI_SIZE` and `FORM_SIZE` can be told apart.
- The values are stable i18n keys and are never renamed. `fallbackMessage()` returns the English text.
- `InvalidImage` covers several causes (corrupt, truncated, or unrecognized content, too many pixels, multiple frames, mismatching geometry or ICC profile), because `citomni/image` reports no machine-readable reason, and Upload does not parse exception messages.

### PHP upload error codes

| Code | Result |
|---|---|
| `UPLOAD_ERR_OK` | Continue |
| `UPLOAD_ERR_INI_SIZE`, `UPLOAD_ERR_FORM_SIZE` | Rejected as `TooLarge` |
| `UPLOAD_ERR_PARTIAL` | Rejected as `Partial` |
| `UPLOAD_ERR_NO_FILE` | Rejected as `NoFile` |
| `UPLOAD_ERR_NO_TMP_DIR`, `UPLOAD_ERR_CANT_WRITE`, `UPLOAD_ERR_EXTENSION`, unknown codes | `UploadStorageException` naming the code: a server-side problem |

### `citomni/image` exceptions

Only the calls to `inspect()` and `save()` are wrapped, only these types are translated, and there is no catch-all around them:

| From `citomni/image` | In Upload |
|---|---|
| `ImageInputException` | `UploadRejectedException` with the reason `InvalidImage`. No target was touched. |
| `ImageWriteException`, including `ImageTargetExistsException` | `UploadStorageException`. The outputs `committed()` lists are removed first. An occupied target is never Upload's and is never touched. |
| `\InvalidArgumentException` from `save()` | `UploadConfigException` naming the profile. Upload passes only the profile's specs and options and paths it built itself, so the cause is the profile. |
| `ImageCapabilityException` and anything else | Propagates unchanged. |

The original exception is `previous` in every translation. `ImageCapabilityException` means that the runtime cannot do what the profile asks, such as decoding or encoding a format or converting colors; see the [deployment checklist](#deployment-checklist).

---

## Image outputs

Upload calls `citomni/image` only when the profile has an `image` block **and** the detected type is a `citomni/image` format. Plain profiles, other types in an image profile (a PDF, or an SVG, which is not an image format), deletion, cleanup, and the path methods never touch it.

1. **Inspect.** `citomni/image` inspects the source. The type it reports must equal the detected type.
2. **Name and prepare.** Upload names the main file and the variants and creates the target directory, because `citomni/image` requires existing directories.
3. **Image job.** When the profile has outputs, one `save()` call gets the main spec (unless `main` is `null`) and every variant, each with the path Upload chose and without `overwrite`, plus the profile's `options`. `file_mode` is then applied to every output.
4. **Original.** With `main: null`, Upload writes the original bytes once the job has succeeded.
5. **Result.** The main file is hashed when the profile sets `hash`, and the result is assembled.

### Type agreement

`finfo` is Upload's detector for every file, and `citomni/image` is authoritative for understanding images. Upload compares the two and implements no image detection of its own:

- `accept` is matched against the canonical `finfo` type for every file.
- On the image path, the type `inspect()` reports must equal it. A disagreement is treated as ambiguous input and rejected fail-closed as `TypeNotAllowed`, with both types in the context. Causes include polyglot files, but also another libmagic version, an unknown alias, or variations in container brands.
- A short alias list covers known legitimate variants (see [MIME types and extensions](#mime-types-and-extensions)); `image/heif` counts as `image/heic`, because `citomni/image` reports HEIF as HEIC. Profiles must still accept what `finfo` actually reports on the production server.

### Order and atomicity

- The image job runs before Upload writes a kept original. Most failures are rejected input found while decoding, and with this order a rejection costs no copy of a possibly large original. The source stays untouched during the job, also when it is an HTTP upload or the source of `storeLocal(..., move: true)`.
- With outputs, storage is all-or-nothing: when `citomni/image` cannot produce a variant, for example because the source exceeds `image.max_pixels`, the original is not stored either.

### Compensation

When a store call fails, every file it created is removed before the exception propagates: Upload's own files, temporary files included, and `citomni/image`'s outputs, which means all of them after a successful `save()` and exactly those in `committed()` after a failed publish. For `storeUploads()`, that includes every file of the entries the batch stored before the failure. Removal is keyed by output name and uses the paths Upload chose, so Upload never removes a path it did not name. It is best effort: a file the filesystem refuses to unlink stays and is logged when `log` is registered. `citomni/image` removes its own temporary files; whatever a crash leaves behind is removed by the sweep job in the [deployment checklist](#deployment-checklist).

Upload decodes nothing, reads no pixel or EXIF data, adds no temporary-file layer around `save()`, and runs no capability probes. `capabilities()` is a deployment and diagnostics tool, not part of the pipeline.

---

## Storage and naming

### Public and private storages

- A storage with a `web_path`, such as the baseline `public`, lies below the web root and is served without authorization.
- A storage without one, such as the baseline `private`, is never served directly. A controller authorizes each request and sends the file, for example with `Response::download()`; see [Usage patterns](#usage-patterns).
- Upload knows no users, tenants, or tokens. CitOmni's policy for public uploads places each user's public files below an opaque per-user token instead of an internal ID; the caller passes the token as part of `$subdir`, as in the [Quick start](#quick-start). Internal IDs and personal data never belong in the paths of a public storage. In a private storage, internal IDs are fine, because its files are never served directly.

### Path rules

`StoragePath::validateRelative()` validates every storage-relative path lexically: `directory`, `$subdir`, `web_path`, and every path given to the deletion and path methods.

- `/` is the separator. A backslash is rejected.
- No leading or trailing `/`, no empty segments, and no segment that starts with `.`, so no `.`, `..`, or dotfiles.
- Segments match `[A-Za-z0-9._~-]+`, the URL-unreserved characters, so the same path is a filesystem path and a web path without any encoding.
- `''` is valid as a directory and invalid as a file path, where it would address the storage root.
- A violation throws `\InvalidArgumentException`, or, for configuration (`directory`, `web_path`), `UploadConfigException` with the path error as `previous`. A path is never repaired.
- `realpath()` is deliberately not used, so symlinks inside a storage are not resolved. A storage must not contain links to elsewhere.

### Placement and names

```text
{root}/{directory}/{subdir}/{shards}/{name}.{ext}          main file
{root}/{directory}/{subdir}/{shards}/{name}-{key}.{ext}    variant
```

Empty parts are skipped.

- `name` is `bin2hex(random_bytes(16))`. Its 128 bits make public files unguessable. The client's file name never reaches a path.
- `shard: n` adds `n` directories of two hex characters from the start of the name, for example `9f/86/9f86….webp`.
- The main file's extension follows the detected type, or the main `format` for a produced main. A variant's extension follows its `format`.
- One function derives variant paths from the main path, the variant key, and the variant's `format`, and storing, deletion, and `variantPath()` all use it. Changing a variant's `format` therefore makes the derived paths of existing files wrong: it is a migration. Applications that want to be robust against it persist variant paths in their own columns.

### Original file name

The original name is metadata only (display, `Content-Disposition`). `OriginalName::sanitize()`:

1. normalizes `\` to `/` and keeps the part after the last `/` (`basename()` is locale-dependent for multibyte input);
2. scrubs invalid UTF-8 with `mb_scrub()`;
3. removes C0 and C1 control characters, DEL, and the bidi controls U+202A–U+202E and U+2066–U+2069;
4. trims whitespace, Unicode whitespace included, with `mb_trim()`;
5. limits the name to 255 characters;
6. returns `file` when nothing is left.

`storeUpload()` sanitizes the client's `name`, and `storeLocal()` sanitizes `$originalName` or the source path. Escaping the name for output is the template's job.

### MIME types and extensions

The extension always follows the canonical type, never the client's file name.

A `citomni/image` format is stored with its `ImageFormat` value as the extension, except `jpeg` → `jpg` and `tiff` → `tif`. The set is derived from `ImageFormat::cases()`, so a format that `citomni/image` adds is covered without a change in Upload. With the current formats:

```text
image/jpeg → jpg        image/png → png         image/gif → gif         image/webp → webp
image/avif → avif       image/bmp → bmp         image/tiff → tif        image/heic → heic
```

Other types follow a curated map; anything else, including HTML, JavaScript, PHP, and executables, is stored as `bin`:

```text
image/svg+xml → svg     application/pdf → pdf   text/plain → txt        text/csv → csv
application/xml → xml   text/xml → xml          application/json → json
application/zip → zip   application/vnd.rar → rar   application/epub+zip → epub   message/rfc822 → eml
application/vnd.openxmlformats-officedocument.wordprocessingml.document → docx
application/vnd.openxmlformats-officedocument.spreadsheetml.sheet → xlsx
application/vnd.openxmlformats-officedocument.presentationml.presentation → pptx
application/vnd.oasis.opendocument.text → odt
application/vnd.oasis.opendocument.spreadsheet → ods
application/vnd.oasis.opendocument.presentation → odp
anything else → bin
```

- `MimeMap::canonical()` lowercases a type and maps known aliases: `image/x-ms-bmp` and `image/x-bmp` to `image/bmp`, `image/heif` to `image/heic`, and `application/x-rar-compressed` and `application/x-rar` to `application/vnd.rar`.
- `jpeg` → `jpg` and `tiff` → `tif` are Upload's file name policy; `citomni/image` deliberately has none.
- SVG is not a `citomni/image` format, so it always takes the plain path. SVG and XML can carry script: avoid accepting them in a public storage. The CSP sandbox under [Web server configuration](#web-server-configuration-for-public-storage) neutralizes script in such files when they are opened directly.

### Writes and directories

- A file Upload places itself, a plain file or a kept original, is first written to a temporary file next to the target, named `.{name}.{ext}.{random}.tmp` like `citomni/image`'s temporary files: with `move_uploaded_file()` for an upload, with `copy()` for a local file. An existing target is refused with `UploadStorageException`; otherwise the file is renamed into place and `file_mode` is applied.
- An existence check before `rename()` is enough, because targets are fresh 128-bit names that no other process knows. `citomni/image` needs an atomic create-if-absent because its callers can choose colliding paths.
- `tempnam()` is not used: it creates files with mode `0600`, which can make public files unreadable for the web server.
- Directories are created on demand below the root, race-safely, with `mkdir()` and `dir_mode`. They are created before `citomni/image` runs, because it requires existing directories.
- One rule covers the whole storage: dot-prefixed `.tmp` files are unfinished and may be removed once they are old. The API can never address them, because a valid path segment never starts with `.`.

---

## Deleting files and deriving paths

All eight methods validate every path against the [path rules](#path-rules) before any filesystem call, and `''` is invalid as a file path. One invalid path means that nothing is deleted. Invalid paths throw `\InvalidArgumentException`.

### `delete*()` and `cleanup*()`

| | `delete()`, `deleteWithVariants()` | `cleanup()`, `cleanupWithVariants()`, `cleanupStored()` |
|---|---|---|
| Use when | Deleting is the task itself and must have happened before anything else is recorded | Removing files after a decision that is already committed |
| Missing file | Counts as deleted | Counts as removed |
| `unlink()` fails | `UploadStorageException` | Logged; the result becomes `false`; nothing is thrown |
| The path names a directory | `UploadStorageException` | `false` and a log entry |
| A link survives a refused unlink | Not gone, even when its target is missing | Not gone |
| Returns | `void`: the files are gone, or it throws | `bool`: `true` when every file is gone |
| Invalid or empty path | `\InvalidArgumentException` before any IO | `\InvalidArgumentException` before any IO, also for a malformed store result |
| Unknown storage or missing root | `UploadConfigException` | `UploadConfigException`; `cleanupStored()` checks every result's storage before it removes anything |
| No paths | Checks the root, then does nothing | Checks the root, then returns `true`; `cleanupStored()` without results returns `true` |
| Order | As given; `*WithVariants()`: the variants in profile order, then the main file | The same; `cleanupStored()`: per result, its variants in result order, then its main file |
| A file fails | Stops and throws | Attempts every path |
| Log context | – | `storage`, `path` (absolute), `stored_path` (the file's own relative path), `error` |

Rule of thumb: when removing the file comes after a database commit, it is cleanup. That covers compensating a failed persistence, the old file after a replacement, and the file of an attachment the user deleted. When removing the file is the action itself and must have happened before the database is marked, it is delete. A retention purge, for example, deletes strictly and then marks the record as purged, so a failure leads to a retry instead of a record that lies.

- `*WithVariants()` derives the variant paths from the main path and the profile's variant formats and removes the variants before the main file. The main file stays the anchor: an interrupted deletion never leaves variants without their main file, and a retry derives the same paths. A profile without an `image` block covers the main file only. For a non-image file stored with an image profile, the derived variant paths do not exist and count as removed.
- `cleanupStored(...$stored)` removes exactly the files that store results list, each from its result's own storage: per result every `variants` path, then `path`. It needs neither the profile nor the image service, so a profile change since the store does not matter. It is meant for results that were never committed: compensating a failed persistence, or discarding an all-or-nothing batch with `cleanupStored(...$batch['stored'])`. A path read back from a record goes through `cleanup()` or `cleanupWithVariants()`. A result without a string `storage`, a string `path`, or a `variants` array with string paths throws `\InvalidArgumentException` before any filesystem call.
- `cleanup*()` never throws on filesystem failures, so it is safe in `finally` blocks with paths from a store result. It still throws on invalid paths, which signal a broken invariant such as a malformed stored value, on an unknown storage or a missing root, and, for an image profile, when the image service is missing.
- Deletion removes files, never directories; empty shard directories remain.
- With the `log` service registered, cleanup failures are written with `$this->app->log->write('upload.jsonl', 'cleanup', $message, $context)`. The same goes for a store call's own compensation (context `storage` and `path`) and for a `$move` source that could not be removed (`path` is the source, and `stored_path` is the stored file). `error` comes from `error_get_last()`, best effort. A failing log is swallowed, because cleanup often runs while another exception is in flight.

### Path methods

- `variantPath($path, $variant, $profile)` derives the path a store uses for that variant, with the extension of the variant's format (`jpeg` as `.jpg`, `tiff` as `.tif`). A re-encoded main and a kept original derive the same variant paths. An unknown variant, `main`, and a profile without an `image` block throw `\InvalidArgumentException`.
- `absolutePath($storage, $path)` returns the configured root, `/`, and the path. A root with a trailing separator is handled.
- `webPath($storage, $path)` returns `{web_path}/{path}` without a leading slash and without a base URL, or the path alone when `web_path` is `''`. A storage whose `web_path` is `null` throws `UploadConfigException`, because its files are served through an authorizing controller, and so does an invalid `web_path`.

The path methods read configuration only: no filesystem call (no stat, no `realpath()`), no `finfo`, and no `citomni/image`. They therefore say nothing about whether a file exists.

```php
$thumb = $this->app->upload->variantPath($row['avatar_path'], 'thumb', 'avatar');
// $baseUrl comes from the HTTP layer (app-specific).
$src = $baseUrl . '/' . $this->app->upload->webPath('public', $thumb);
```

---

## Usage patterns

The patterns are illustrative, like the Quick start. They share one order: store the new file, persist, then remove the old file. A crash can leave an orphaned file or a dot-prefixed `.tmp` file; that is harmless and is removed by an application job. A database reference to a deleted file must never arise.

### One file per entity

The [Quick start](#quick-start) controller hands the stored avatar to an Operation that swaps the path and discards the previous files:

```php
final class ReplaceAvatar extends BaseOperation {

	/**
	 * Point a user's profile at a newly stored avatar and discard the previous one.
	 *
	 * Behavior:
	 * - Swaps the path in one transaction and receives the previous path.
	 * - On persistence failure, the newly stored files are removed (best effort) and the error bubbles.
	 * - After a successful swap, the previous files are removed (best effort, logged by Upload on failure).
	 *
	 * @param  int                  $userId  Target user.
	 * @param  array<string,mixed>  $stored  Result from Upload::storeUpload() or Upload::storeLocal().
	 * @return array{path:string}  The stored main path.
	 */
	public function execute(int $userId, array $stored): array {
		$repository = new UserProfileRepository($this->app);

		$oldPath = null;
		$swapped = false;
		try {
			$oldPath = $repository->swapAvatarPath($userId, $stored['path']);
			$swapped = true;
		} finally {
			if (!$swapped) {
				$this->app->upload->cleanupStored($stored);
			}
		}

		if ($oldPath !== null) {
			$this->app->upload->cleanupWithVariants($oldPath, 'avatar');
		}

		return ['path' => $stored['path']];
	}
}
```

```php
final class UserProfileRepository extends BaseRepository {

	public function swapAvatarPath(int $userId, string $newPath): ?string {
		return $this->app->db->transaction(function () use ($userId, $newPath): ?string {
			$old = $this->app->db->fetchValue(
				'SELECT avatar_path FROM user_profiles WHERE user_id = ? FOR UPDATE',
				[$userId]
			);
			$this->app->db->execute(
				'UPDATE user_profiles SET avatar_path = ? WHERE user_id = ?',
				[$newPath, $userId]
			);
			return $old === null ? null : (string)$old;
		});
	}
}
```

`cleanupStored()` and `cleanupWithVariants()` are best effort, so neither the compensation nor the removal after the commit needs a `try`/`catch`. The compensation removes exactly the files of the fresh result; the previous files are derived from the persisted path and the profile. A removal that fails after the commit is logged by Upload, and the action succeeds. The thumbnail path follows from `variantPath($path, 'thumb', 'avatar')`; applications that prefer a thumbnail column persist `$stored['variants']['thumb']['path']`.

### Many files per entity, with a maximum

- **Pre-check (UX).** The adapter may refuse a batch when existing plus incoming files exceed the limit, before any file is processed.
- **Authoritative check.** The Repository locks the parent row, counts, and inserts in one transaction. It returns `null` when the limit would be exceeded.
- **Batch policy.** All-or-nothing or partial acceptance is the application's choice. `storeUploads()` stores every acceptable file and reports the rest; the policy decides what happens with what was stored.

An all-or-nothing adapter:

```php
// Controller action body (illustrative), with the imports of the Quick start controller.
$batch = $this->app->upload->storeUploads(UploadedFiles::many($_FILES['attachments'] ?? null), 'attachment', (string)$ownerId);

if ($batch['rejected'] !== []) {
	// All or nothing: the files that were accepted must not linger.
	$this->app->upload->cleanupStored(...$batch['stored']);

	$messages = [];
	foreach ($batch['rejected'] as $e) {
		$messages[] = $e->context['original_name'] . ': ' . $this->app->upload->rejectionMessage($e);
	}
	// Render the form with $messages (app-specific).
	return;
}

$result = (new AddAttachments($this->app))->execute($ownerId, $batch['stored']);
```

For partial acceptance, the adapter skips the early return: it passes `$batch['stored']` on and shows the messages for `$batch['rejected']` with the outcome.

```php
final class AddAttachments extends BaseOperation {

	private const int MAX_FILES = 20;

	/**
	 * Persist stored files for an owner entity within the per-owner limit.
	 *
	 * Behavior:
	 * - Persists all files in one transaction, or none when the limit would be exceeded.
	 * - When nothing is persisted, the stored files are removed (best effort).
	 *
	 * @param  int                        $ownerId      Owning entity.
	 * @param  list<array<string,mixed>>  $storedFiles  Store results, e.g. Upload::storeUploads()['stored'].
	 * @return array{ok:bool, reason:?string, ids:list<int>}  Outcome and the new ids.
	 */
	public function execute(int $ownerId, array $storedFiles): array {
		$repository = new AttachmentRepository($this->app);

		$ids = null;
		try {
			$ids = $repository->insertWithinLimit($ownerId, $storedFiles, self::MAX_FILES);
		} finally {
			if ($ids === null) {
				// Limit exceeded or persistence failed: the stored files must not linger.
				$this->app->upload->cleanupStored(...$storedFiles);
			}
		}

		return $ids === null
			? ['ok' => false, 'reason' => 'limit_exceeded', 'ids' => []]
			: ['ok' => true, 'reason' => null, 'ids' => $ids];
	}
}
```

`storeUploads()` is a convenience, not a requirement. An adapter with a flow of its own, such as a profile per file or stopping at the first rejection, loops over `storeUpload()` and compensates with `cleanupStored()`:

```php
// Stops at the first rejection, so nothing after it is processed.
$stored = [];
$complete = false;

try {
	foreach (UploadedFiles::many($_FILES['attachments'] ?? null) as $file) {
		$stored[] = $this->app->upload->storeUpload($file, 'attachment', (string)$ownerId);
	}
	$complete = true;
} catch (UploadRejectedException $e) {
	$message = $this->app->upload->rejectionMessage($e);
	// Render the form with $message (app-specific).
	return;
} finally {
	if (!$complete) {
		// A rejection or a fault part-way: the files stored so far must not linger.
		$this->app->upload->cleanupStored(...$stored);
	}
}
```

### Private download and purge

```php
// Controller (illustrative): authorization for $documentId is already enforced.
$row = (new ArchivedDocumentRepository($this->app))->findById($documentId);
$this->app->response->download(
	$this->app->upload->absolutePath('private', $row['storage_path']),
	$row['original_name']
);
```

A retention purge belongs in the application's Operations. It deletes strictly before it marks the record:

```php
// Operation (illustrative): a failed deletion throws, so the record is never marked falsely.
$this->app->upload->deleteWithVariants($row['storage_path'], 'archived_document');
$repository->markPurged($row['id']);
```

---

## Security

Upload's own guarantees:

- The client's type and size are never used.
- The content type is detected with `finfo`, canonicalized, and matched exactly against a mandatory `accept` list.
- On the image path, `citomni/image`'s detection must agree with `finfo`.
- Extensions follow the type or the output format, and dangerous types become `bin`.
- Names carry 128 bits of randomness, and the client's file name never appears in a path.
- HTTP provenance requires `is_uploaded_file()`, without fallback.
- Relative paths are validated lexically and rejected on violation, never repaired.
- Nothing is written until validation and, on the image path, inspection have passed. Half-written files are dot-prefixed `.tmp` files.
- No existing file is overwritten: Upload checks its own targets before renaming, and `citomni/image` publishes without clobbering.
- The original file name is sanitized and used as metadata only.
- Compensation removes only files the call created itself, including the outputs `citomni/image` reports as committed.

Inherited from `citomni/image` and not retested here: metadata stripping (EXIF, XMP, GPS) on re-encoding, the pixel budget, truncation checks, multi-frame rejection, geometry verification, and no-clobber publishing.

---

## Deployment checklist

- [ ] `config/providers.php` lists both `\CitOmni\Image\Boot\Registry` and `\CitOmni\Upload\Boot\Registry`.
- [ ] The storage roots exist and are writable for the PHP process; with the baseline, `public/uploads/u/` and `var/storage/`.
- [ ] The storage filesystem supports hard links. `citomni/image` needs them for no-clobber publishing; without them, image outputs fail with `ImageWriteException`, which Upload reports as `UploadStorageException`.
- [ ] The web server is configured for the public storage; see [Web server configuration](#web-server-configuration-for-public-storage).
- [ ] For every image profile, `$this->app->image->capabilities()` reports a decoder for each accepted image type and an encoder for each output format. Otherwise such uploads end in `ImageCapabilityException`, a server error.
- [ ] **Color management.** If the application accepts ICC-profiled images in color spaces other than sRGB (Display P3, Adobe RGB, CMYK), the deployment either has verified color management (`capabilities()['color_management'] !== null`, in practice Imagick with LittleCMS) or deliberately sets `image.color` to `'ignore'`. Otherwise such sources end in `ImageCapabilityException`. Both belong to the `citomni/image` setup; see its [color management](https://github.com/citomni/image#color-management).
- [ ] **Pixel budget.** `image.max_pixels` (default 25 MP, about 100 MB of raw pixel data alone) is a deployment decision based on realistic source sizes and PHP's `memory_limit`. Sources above it, for example from 48, 50, 100, or 200 MP camera modes, are rejected as `InvalidImage`. Do not raise the limit merely to accept everything.
- [ ] **Mounts.** When a storage mount is down, the mount point remains as an empty directory. The root check then passes, and files land on the underlying filesystem. Monitor mounts outside Upload.
- [ ] **Symlinks.** Storages contain no links to elsewhere.
- [ ] **Sweep job.** Dot-prefixed `.tmp` files that a crashed process left behind, from Upload or `citomni/image`, cannot be addressed through the API. An application job removes them by age.
- [ ] **Language.** `locale.language` is a bare language code such as `'da'`, so the package's translations are found.

---

## Web server configuration for public storage

Upload does not own the public upload area or its server configuration; CitOmni's application scaffold provides the directory and its `.htaccess`. Upload relies on the area to:

- execute no scripts and list no directories,
- deny access to dotfiles, which covers the `.tmp` files of Upload and `citomni/image`,
- send `X-Content-Type-Options: nosniff`,
- preferably send a CSP sandbox, so that script in SVG, XML, or HTML opened directly is neutralized.

Dangerous types are stored as `bin`, so Upload never writes a file with an executable extension; the first rule is defense in depth.

Apache, as an example to adapt:

```apache
# Example for public/uploads/.htaccess; adapt it to your server. Directives in
# .htaccess require a matching AllowOverride.

Options -Indexes -ExecCGI

# No PHP execution under mod_php. With PHP-FPM, exclude this directory from the
# PHP handler in the virtual host instead.
<IfModule mod_php.c>
	php_flag engine off
</IfModule>

# Deny dotfiles, including half-written .tmp files.
<FilesMatch "^\.">
	Require all denied
</FilesMatch>

<IfModule mod_headers.c>
	Header always set X-Content-Type-Options "nosniff"
	Header always set Content-Security-Policy "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox"
</IfModule>
```

nginx, as an example to adapt:

```nginx
# Example for the directory that holds the public storages (baseline web_path
# uploads/u); adapt it to your server. ^~ keeps regex locations such as \.php$
# from matching below it, and nothing here is passed to PHP.
location ^~ /uploads/ {
	autoindex off;

	# Deny dotfiles, including half-written .tmp files.
	location ~ /\. {
		deny all;
	}

	# add_header here replaces every add_header inherited from the server
	# level; repeat the server-wide headers you need.
	add_header X-Content-Type-Options "nosniff" always;
	add_header Content-Security-Policy "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox" always;
}
```

---

## Current limitations

Known behavior:

- An AVIF file with the major brand `mif1` is rejected as `TypeNotAllowed`: `finfo` reports `image/heif` (canonical `image/heic`), while `citomni/image` reports `image/avif`. Telling them apart would mean image detection in Upload.
- HEIC and TIFF can only be decoded with Imagick, so producing outputs from them requires an Imagick build with the codec. Without it, such uploads end in `ImageCapabilityException`.
- Changing a variant's `format` changes the derived paths of files already stored; that is a migration.
- A re-encoded main does not consume the upload. The temporary file stays with PHP until the request ends, and the same entry can be stored again.
- With `main: null` and no variants, only `inspect()` runs and nothing is decoded, so `image.max_pixels` is not enforced.
- Upload removes files, never directories. A call that fails or is rejected during the image job can leave behind the empty directories it created for its targets.

Not provided:

- Database integration, a media library, or a shared files table.
- Cloud or object storage, and storage drivers.
- Chunked or resumable uploads, and virus scanning.
- Storing content that is not a file, and regenerating variants.
- Per-request transforms such as a user-chosen crop, and semantic file names.
- A per-profile pixel limit stricter than `image.max_pixels`.
- Removal of stale temporary files; see the sweep job in the [deployment checklist](#deployment-checklist).

---

## Testing

The tests cover Upload's own logic and its boundary with `citomni/image`. Image correctness (orientation, geometry, alpha, compression, metadata, codecs, backends, color, multi-frame, output verification) is tested in `citomni/image` only.

The tests are plain PHP regression scripts, one per area, and each stops with a non-zero exit code at the first failed check. There is no PHPUnit and no Composer installation: `tests/bootstrap.php` loads Upload's `src/` and `tests/` and the sibling packages `../kernel/src` and `../image/src`, so the tests run in this development layout and fail with the searched paths when a sibling is missing:

```text
citomni/
├── kernel/
├── image/
└── upload/
```

An installation under `vendor/citomni/` has the same shape, but `tests/` is excluded from the Composer dist archive, so the tests need a source checkout.

Run every script with:

```bash
composer test
```

Run one script with `php tests/storage_test.php`.

| Script | Covers |
|---|---|
| `util_test.php` | `StoragePath`, `OriginalName`, `MimeMap`, `UploadRejection`, and the exception hierarchy |
| `profile_test.php` | Every profile rule, the Registry baseline, memoization, inline profiles, and merge semantics |
| `intake_test.php` | `UploadedFiles`, error-code mapping, check order, provenance, `storeUploads()` without HTTP, `rejectionMessage()`, and the language files |
| `storage_test.php` | Generic validation, plain writes, the result, routing, `$move`, and compensation |
| `image_path_test.php` | The image path against a scripted `citomni/image` double: outputs, order, result fields, exception translation, and compensation |
| `image_integration_test.php` | The real `citomni/image`: `finfo` against `inspect()` on crafted headers, and the happy path with GD |
| `delete_test.php` | `delete*()` and `cleanup*()`, `cleanupStored()` included, on an in-memory storage and on the real filesystem |
| `read_path_test.php` | `variantPath()`, `absolutePath()`, `webPath()`, and proof that they do no IO |
| `http_upload_test.php` | End to end through PHP's built-in web server: `$_FILES` shapes, `storeUpload()` with real uploads and error codes, image uploads, and `storeUploads()` batches with partial acceptance and compensation |

The scripts require PHP 8.5, `ext-fileinfo`, and `ext-mbstring`, and the image checks require `ext-gd`. Every unsuppressed diagnostic fails a run, including deprecations and `#[\NoDiscard]` warnings. Failures are forced through the filesystem or through stream wrappers in `tests/Support/`; the production code has no test seams.

Checks the platform cannot support print a `SKIP:` line instead of failing:

- Failures that only file permissions can force, such as a refused unlink or a read-only directory, are decided by behavior probes, not by user ID. They skip as root and on Windows; deletion semantics are still covered on every platform through an in-memory storage.
- Image checks skip without `ext-gd`, and format by format without an encoder for the format.
- `http_upload_test.php` skips outside the CLI, without `proc_open()`, or when PHP's built-in web server cannot start on `127.0.0.1`.
- The `image/heif` alias check skips when the local libmagic reports another type for its fixture.

### Mutation testing

`tests/mutation/` holds a mutation runner and 84 mutants in the areas `profile`, `store`, `intake`, `batch`, `image`, `delete`, and `path`. It is not part of `composer test`.

```bash
php tests/mutation/run.php            # All mutants.
php tests/mutation/run.php <filter>   # Mutants whose id or label contains <filter>.
php tests/mutation/run.php --list     # Validate the definitions and list them.
```

- Every definition is validated before anything runs, also with a filter: each search string must occur exactly once, and the mutated file must parse.
- The runner works in a temporary copy of the package with the sibling `kernel` and `image` sources and never modifies the working tree. Each affected script first runs without a mutation; a failing baseline stops the run and keeps the copy for inspection.
- Exit code `0` means that every mutant that ran was killed, `1` that at least one survived, and `2` invalid definitions, a missing sibling package, or a failing baseline.
- Run it where the suite normally runs, with `ext-gd`, as a non-root POSIX user: five mutants are killed only by a refused unlink and are skipped as root. There is no timeout.

---

## Coding & Documentation Conventions

All CitOmni projects follow the shared conventions documented here:

[CitOmni Coding & Documentation Conventions](https://github.com/citomni/docs/blob/main/contribute/CONVENTIONS.md)

---

## License

**CitOmni Upload** is open-source under the **MIT License**. See [LICENSE](LICENSE) and [NOTICE](NOTICE).

---

## Trademarks

"CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**.

You may make factual references to "CitOmni", but do not modify the marks, create confusingly similar logos, or imply sponsorship, endorsement, or affiliation without prior written permission.

Do not register or use "citomni" or confusingly similar terms in company names, domains, social handles, or top-level vendor/package names.

For details, see [TRADEMARKS.md](TRADEMARKS.md).

---

## Author

Developed by Lars Grove Mortensen (c) 2012-present.

---

CitOmni - low overhead, high performance, ready for anything.
