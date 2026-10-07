<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

namespace CitOmni\Upload\Service;

use CitOmni\Image\Enum\ImageFormat;
use CitOmni\Image\Exception\ImageInputException;
use CitOmni\Image\Exception\ImageWriteException;
use CitOmni\Kernel\Cfg;
use CitOmni\Kernel\Service\BaseService;
use CitOmni\Upload\Enum\UploadRejection;
use CitOmni\Upload\Exception\UploadConfigException;
use CitOmni\Upload\Exception\UploadRejectedException;
use CitOmni\Upload\Exception\UploadStorageException;
use CitOmni\Upload\Util\MimeMap;
use CitOmni\Upload\Util\OriginalName;
use CitOmni\Upload\Util\StoragePath;

/**
 * Upload: File intake, upload policy, and local storage for CitOmni apps.
 *
 * Validates a file uploaded in the current HTTP request (storeUpload()) or a
 * developer-trusted local file (storeLocal()) against a named or inline
 * profile, and stores it under an opaque, unguessable name in a configured
 * local storage. Image outputs are delegated to citomni/image.
 *
 * Behavior:
 * - Profiles: a named profile is read from upload.profiles.<name>, validated
 *   once, and memoized per name. An inline profile array is validated on every
 *   call. Unknown keys are rejected at profile level and in the image block,
 *   and a profile with an image block requires the image service.
 * - Provenance: storeUpload() maps PHP's upload error code and requires
 *   is_uploaded_file(); storeLocal() requires a readable regular file. Both
 *   continue with the same pipeline.
 * - Batches: storeUploads() runs storeUpload() for several entries with one
 *   profile, collects rejections instead of stopping, and leaves the batch
 *   policy to the caller. It is optional: storeUpload() remains the building
 *   block for a caller with its own batch flow.
 * - Generic validation runs before anything is written. Size is measured by
 *   Upload (empty files and max_bytes), and the MIME type is detected by finfo,
 *   canonicalized, and matched exactly against the profile's accept list.
 *   Client-reported size and type are never used.
 * - Routing: an image profile receiving a citomni/image format takes the image
 *   path; everything else, including a PDF in an image profile, is stored as a
 *   plain file.
 * - Image path: citomni/image inspects the source, and its detected type must
 *   equal finfo's. citomni/image then writes the main output (unless the
 *   original is kept) and the variants to paths Upload chose, without
 *   clobbering. A kept original is written after the image job.
 * - Placement: storage root / profile directory / $subdir / shard segments.
 *   The name is bin2hex(random_bytes(16)) plus an extension derived from the
 *   canonical MIME type or the main output format; variants are named
 *   "{name}-{key}.{ext}". The client's file name never reaches a path.
 * - Writes Upload makes itself move an upload (move_uploaded_file()) or copy a
 *   local file to a dot-prefixed ".tmp" file in the target directory, refuse
 *   an existing target, and rename. upload.file_mode is applied to every file
 *   placed in storage, including citomni/image's outputs.
 * - Compensation: when a call fails before its commit point, the files it
 *   created are removed before the exception propagates (best effort, logged),
 *   including the outputs citomni/image reports as committed. For
 *   storeUploads(), these include the files of every entry stored before the
 *   failure.
 * - rejectionMessage() turns a rejection into end-user text through the txt
 *   service when it is registered, with an English fallback.
 * - Deletion: delete() and deleteWithVariants() delete strictly and stop at the
 *   first file they cannot delete; cleanup(), cleanupWithVariants(), and
 *   cleanupStored() remove best effort, log failures, and report whether
 *   everything is gone. cleanupStored() removes exactly the files listed in
 *   store results. The *WithVariants() methods and cleanupStored() remove the
 *   variants before the main file.
 * - Path methods: variantPath(), absolutePath(), and webPath() derive paths
 *   from configuration alone, without filesystem access, finfo, or
 *   citomni/image.
 *
 * Notes:
 * - No SQL, no Db access, no transport concerns. The caller persists the
 *   returned storage-relative path and maps UploadRejectedException to a
 *   validation response in its adapter.
 * - citomni/image exceptions are translated only where Upload's callers need a
 *   distinct meaning: rejected content, publishing failures, and invalid output
 *   specs or options. ImageCapabilityException and other runtime failures
 *   propagate unchanged.
 * - The log service is optional (hasService('log')) and is used only to report
 *   cleanup failures. The txt service is optional and is used only by
 *   rejectionMessage().
 * - init() is omitted: finfo, profiles, storage roots, and modes are resolved
 *   lazily and memoized for the lifetime of the service.
 *
 * Typical usage:
 *   $file = UploadedFiles::one($_FILES['avatar'] ?? null);
 *   if ($file === null) {
 *       $message = $this->app->upload->rejectionMessage(UploadRejection::NoFile);
 *   } else {
 *       try {
 *           $stored = $this->app->upload->storeUpload($file, 'avatar', $uploadToken . '/profile');
 *       } catch (UploadRejectedException $e) {
 *           $message = $this->app->upload->rejectionMessage($e);
 *       }
 *   }
 */
final class Upload extends BaseService {

	// Keys a profile may contain.
	private const array PROFILE_KEYS = [
		'storage' => true,
		'directory' => true,
		'accept' => true,
		'max_bytes' => true,
		'shard' => true,
		'hash' => true,
		'image' => true,
	];

	// Keys an image block may contain. Spec and option contents belong to citomni/image.
	private const array IMAGE_KEYS = ['main' => true, 'variants' => true, 'options' => true];

	// RFC 6838 restricted names for type and subtype, matched after canonicalization (lowercase).
	private const string MIME_TYPE = '~^[a-z0-9][a-z0-9!#$&^_.+-]{0,126}/[a-z0-9][a-z0-9!#$&^_.+-]{0,126}$~D';

	// Variant keys; the same rule as StoragePath::variant(). "main" is reserved.
	private const string VARIANT_KEY = '~^[a-z0-9_]+$~D';

	/** @var array<string, array<string, mixed>> Normalized named profiles, keyed by profile name. */
	private array $profiles = [];

	/** @var array<string, string> Configured storage roots, keyed by storage name; not checked on disk. */
	private array $configuredRoots = [];

	/** @var array<string, string> Verified storage roots, keyed by storage name. */
	private array $roots = [];

	/** @var array<string, string> Validated web paths, keyed by storage name. */
	private array $webBases = [];

	/** @var array{0: int|null, 1: int}|null Validated [file_mode, dir_mode]. */
	private ?array $modes = null;

	/** MIME detector, created on first use. */
	private ?\finfo $finfo = null;


	// ----------------------------------------------------------------
	// Public API
	// ----------------------------------------------------------------

	/**
	 * Validate and store a file uploaded in the current HTTP request.
	 *
	 * Behavior:
	 * - Resolves the profile, validates $subdir and the shape of $file, and
	 *   checks the storage preconditions (root, file_mode, dir_mode) before any
	 *   user-side outcome, so developer and configuration errors are always
	 *   reported first.
	 * - Maps PHP's upload error code:
	 *   1) UPLOAD_ERR_OK continues
	 *   2) UPLOAD_ERR_INI_SIZE and UPLOAD_ERR_FORM_SIZE reject as TooLarge
	 *   3) UPLOAD_ERR_PARTIAL rejects as Partial
	 *   4) UPLOAD_ERR_NO_FILE rejects as NoFile
	 *   5) UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION, and
	 *      unknown codes are server faults (UploadStorageException)
	 * - Requires is_uploaded_file($file['tmp_name']) without fallback: a path
	 *   that is not an upload of the current request is a developer error.
	 * - Runs the same pipeline as storeLocal(). When the original itself is
	 *   stored (a plain file, or an image whose main is null), it is moved into
	 *   storage with move_uploaded_file(), so the upload is consumed and storing
	 *   the same entry again is a developer error. A re-encoded main leaves the
	 *   upload with PHP, which deletes it when the request ends.
	 *
	 * Notes:
	 * - The client's type and size are ignored: Upload measures the size and
	 *   detects the type itself. The client's name is sanitized metadata only.
	 * - A rejection leaves the upload where PHP put it, so the same entry can be
	 *   stored again within the request, for example with another profile. PHP
	 *   deletes it when the request ends.
	 * - A failure after the move removes the moved data along with the call's
	 *   other files; the client has to upload again.
	 * - A raw single-file $_FILES entry is accepted as well. When the client sent
	 *   another nesting for the field, its values are arrays and this throws
	 *   \InvalidArgumentException, whereas UploadedFiles::one() returns null.
	 *   UploadedFiles is therefore the recommended way to obtain $file.
	 *
	 * Typical usage:
	 *   $file = UploadedFiles::one($_FILES['avatar'] ?? null);
	 *   $stored = $this->app->upload->storeUpload($file, 'avatar', $uploadToken . '/profile');
	 *
	 * @param array<string, mixed> $file Entry from UploadedFiles::one() or UploadedFiles::many(); string name, string tmp_name, and int error are required.
	 * @param string|array<string, mixed> $profile Profile name in upload.profiles, or an inline profile array.
	 * @param string $subdir Storage-relative directory below the profile's directory; "" for none.
	 * @return array{storage: string, path: string, mime: string, source_mime: string, size: int, original_name: string, width: int|null, height: int|null, hash: string|null, variants: array<string, array<string, mixed>>} Stored file; path is relative to the storage root.
	 * @throws UploadRejectedException When PHP reports a client-side upload error, or the file is empty, too large, of a type the profile does not accept, or an image that citomni/image rejects or detects as another type.
	 * @throws UploadConfigException When the profile, its storage, the storage root, file_mode, or dir_mode is invalid, or citomni/image rejects the profile's output specs or options.
	 * @throws UploadStorageException When PHP reports a server-side upload error, moving or writing a file fails, a target already exists, or citomni/image cannot publish all outputs.
	 * @throws \InvalidArgumentException When $subdir or $file is invalid, or the file was not uploaded in the current request.
	 * @throws \CitOmni\Image\Exception\ImageCapabilityException When no backend can decode or encode a required format (propagated unchanged).
	 */
	#[\NoDiscard]
	public function storeUpload(array $file, string|array $profile, string $subdir = ''): array {
		$resolved = $this->resolveProfile($profile);
		StoragePath::validateRelative($subdir);

		$name = $file['name'] ?? null;
		$tmpName = $file['tmp_name'] ?? null;
		$error = $file['error'] ?? null;

		if (!\is_string($name) || !\is_string($tmpName) || !\is_int($error)) {
			throw new \InvalidArgumentException('storeUpload() expects one file entry with a string name, a string tmp_name, and an int error; obtain it with UploadedFiles::one() or UploadedFiles::many().');
		}

		// Storage configuration fails fast, before any user-side upload error is reported.
		$this->storageRoot($resolved['storage']);
		$this->modes();

		$originalName = OriginalName::sanitize($name);
		$this->mapUploadError($error, $originalName);

		if (!\is_uploaded_file($tmpName)) {
			throw new \InvalidArgumentException('Not a file uploaded in the current request: ' . $tmpName . '. Use storeLocal() for local files.');
		}

		return $this->ingest($tmpName, $resolved, $subdir, $originalName, true);
	}


	/**
	 * Validate and store several files uploaded in the current HTTP request with one profile.
	 *
	 * Behavior:
	 * - Resolves the profile, validates $subdir and the shape of every entry, and
	 *   checks the storage preconditions (root, file_mode, dir_mode) first, in
	 *   storeUpload()'s order and also for an empty list. Developer and
	 *   configuration errors therefore surface before anything is stored, even
	 *   when no file was sent.
	 * - Stores the entries in the given order through storeUpload(), with the
	 *   same profile and $subdir for every entry.
	 * - Partial acceptance: a rejected file is collected in "rejected", and the
	 *   next entry is stored. A rejected upload stays with PHP, as after a
	 *   rejected storeUpload().
	 * - Compensation: any other exception (configuration, storage, capability,
	 *   or a developer error such as an entry that was not uploaded in the
	 *   current request) removes the files of the entries stored before it, then
	 *   propagates (best effort, logged). The failing entry's own files are
	 *   removed by storeUpload(), as always.
	 *
	 * Notes:
	 * - Optional: a caller that needs per-file profiles or subdirectories, or its
	 *   own batch flow, loops over storeUpload() instead.
	 * - "stored" and "rejected" are lists in client order; the keys of $files are
	 *   not kept. Entries from UploadedFiles::many() never give NoFile, so every
	 *   rejection's context then carries original_name.
	 * - The batch policy stays with the caller. Partial acceptance persists
	 *   "stored" and reports "rejected"; all-or-nothing removes "stored" with
	 *   cleanupStored() when "rejected" is not empty. A maximum file count is the
	 *   caller's as well: a pre-check before the call, enforced where the results
	 *   are persisted.
	 * - Persisting the results is the caller's commit point. When persistence
	 *   fails, the caller removes "stored" with cleanupStored().
	 * - Ignoring the result means stored files whose paths are never persisted.
	 *
	 * Typical usage:
	 *   $batch = $this->app->upload->storeUploads(UploadedFiles::many($_FILES['photos'] ?? null), 'gallery', $uploadToken);
	 *   foreach ($batch['rejected'] as $e) {
	 *       $messages[] = $e->context['original_name'] . ': ' . $this->app->upload->rejectionMessage($e);
	 *   }
	 *
	 * @param list<array<string, mixed>> $files Entries from UploadedFiles::many(), in client order.
	 * @param string|array<string, mixed> $profile Profile name in upload.profiles, or an inline profile array.
	 * @param string $subdir Storage-relative directory below the profile's directory; "" for none.
	 * @return array{stored: list<array<string, mixed>>, rejected: list<UploadRejectedException>} Results shaped like storeUpload()'s, and the rejections; both in client order.
	 * @throws UploadConfigException When the profile, its storage, the storage root, file_mode, or dir_mode is invalid, or citomni/image rejects the profile's output specs or options.
	 * @throws UploadStorageException When PHP reports a server-side upload error, moving or writing a file fails, a target already exists, or citomni/image cannot publish all outputs.
	 * @throws \InvalidArgumentException When $subdir is invalid, an entry is not an array with a string name, a string tmp_name, and an int error, or a file was not uploaded in the current request.
	 * @throws \CitOmni\Image\Exception\ImageCapabilityException When no backend can decode or encode a required format (propagated unchanged).
	 */
	#[\NoDiscard]
	public function storeUploads(array $files, string|array $profile, string $subdir = ''): array {
		// Developer and configuration errors surface first, in storeUpload()'s order, also when no file was sent.
		$resolved = $this->resolveProfile($profile);
		StoragePath::validateRelative($subdir);

		foreach ($files as $file) {
			if (!\is_array($file) || !\is_string($file['name'] ?? null) || !\is_string($file['tmp_name'] ?? null) || !\is_int($file['error'] ?? null)) {
				throw new \InvalidArgumentException('storeUploads() expects a list of file entries, each with a string name, a string tmp_name, and an int error; obtain it with UploadedFiles::many(). A single entry goes to storeUpload().');
			}
		}

		$root = $this->storageRoot($resolved['storage']);
		$this->modes();

		$stored = [];
		$rejected = [];
		$complete = false;

		try {
			foreach ($files as $file) {
				try {
					$stored[] = $this->storeUpload($file, $profile, $subdir);
				} catch (UploadRejectedException $e) {
					$rejected[] = $e;
				}
			}

			$complete = true;
		} finally {
			if (!$complete) {
				// A failure part-way: the files of earlier entries must not linger.
				$this->discardResults($stored, $resolved['storage'], $root);
			}
		}

		return ['stored' => $stored, 'rejected' => $rejected];
	}


	/**
	 * Validate and store a developer-trusted local file.
	 *
	 * Behavior:
	 * - Resolves the profile, validates $subdir, and requires a readable regular
	 *   file at $sourcePath before any storage IO. Developer and configuration
	 *   errors are therefore reported regardless of the file's content.
	 * - Runs the intake pipeline:
	 *   1) Storage preconditions: the storage root exists; file_mode and dir_mode
	 *      are valid
	 *   2) Generic validation: empty file, max_bytes, canonical finfo MIME type
	 *      against accept
	 *   3) Routing: an image profile receiving a citomni/image format takes the
	 *      image path (inspect, MIME agreement, image job, then the original when
	 *      it is kept); everything else takes the plain path
	 *   4) Writes: copy to a temporary file, refuse an existing target, rename;
	 *      upload.file_mode on every placed file; optional hash of the main file
	 * - The source is always copied, never renamed or linked. With $move, the
	 *   source is removed only after the store succeeded (the commit point),
	 *   with cleanup semantics: a failed removal is logged and the result stands.
	 *   A failure before the commit point leaves the source untouched.
	 *
	 * Notes:
	 * - rename() is not used for $move because a moved source cannot be restored
	 *   safely when a later step fails. link() is not used because a hard link
	 *   would share its inode with a source left behind by a failed unlink, so
	 *   later changes to that source would change the stored file.
	 * - $originalName is metadata only; null uses the file name of $sourcePath.
	 * - Files uploaded in the current request go through storeUpload().
	 * - Ignoring the result means a stored file whose path is never persisted.
	 *
	 * Typical usage:
	 *   $stored = $this->app->upload->storeLocal('/import/invoice.pdf', 'archived_document', (string)$ownerId, move: true);
	 *
	 * @param string $sourcePath Readable regular file to store.
	 * @param string|array<string, mixed> $profile Profile name in upload.profiles, or an inline profile array.
	 * @param string $subdir Storage-relative directory below the profile's directory; "" for none.
	 * @param string|null $originalName Original file name for metadata; null uses the source's file name.
	 * @param bool $move Remove the source after a successful store.
	 * @return array{storage: string, path: string, mime: string, source_mime: string, size: int, original_name: string, width: int|null, height: int|null, hash: string|null, variants: array<string, array<string, mixed>>} Stored file; path is relative to the storage root.
	 * @throws UploadRejectedException When the file is empty, too large, of a type the profile does not accept, or an image that citomni/image rejects or detects as another type.
	 * @throws UploadConfigException When the profile, its storage, the storage root, file_mode, or dir_mode is invalid, or citomni/image rejects the profile's output specs or options.
	 * @throws UploadStorageException When reading the source or writing a file fails, a target already exists, or citomni/image cannot publish all outputs.
	 * @throws \InvalidArgumentException When $subdir is invalid or $sourcePath is not a readable regular file.
	 * @throws \CitOmni\Image\Exception\ImageCapabilityException When no backend can decode or encode a required format (propagated unchanged).
	 */
	#[\NoDiscard]
	public function storeLocal(string $sourcePath, string|array $profile, string $subdir = '', ?string $originalName = null, bool $move = false): array {
		$resolved = $this->resolveProfile($profile);
		StoragePath::validateRelative($subdir);

		\clearstatcache(true, $sourcePath);

		if (!\is_file($sourcePath) || !\is_readable($sourcePath)) {
			throw new \InvalidArgumentException('Source is not a readable regular file: ' . $sourcePath);
		}

		$result = $this->ingest($sourcePath, $resolved, $subdir, OriginalName::sanitize($originalName ?? $sourcePath), false);

		// Past the commit point: consuming the source must not undo the store.
		if ($move) {
			$this->removeFile($sourcePath, 'Failed to remove the source of a moved store.', [
				'storage' => $result['storage'],
				'path' => $sourcePath,
				'stored_path' => $result['path'],
			]);
		}

		return $result;
	}


	/**
	 * Return the end-user message for a rejection.
	 *
	 * Behavior:
	 * - Accepts the rejection exception or its reason. The reason form lets an
	 *   adapter report NoFile when UploadedFiles::one() returns null, without
	 *   creating an exception.
	 * - With the txt service registered: Txt::get() with the reason value as key,
	 *   this package's "upload" language file (layer "citomni/upload"), and the
	 *   English fallback as default.
	 * - Without the txt service: the English fallback.
	 *
	 * Notes:
	 * - The texts have no placeholders. An adapter that wants to show a limit
	 *   composes it from the exception's context.
	 * - Txt configuration errors, such as a missing locale.language, propagate
	 *   unchanged.
	 *
	 * Typical usage:
	 *   $message = $this->app->upload->rejectionMessage($e);
	 *   $message = $this->app->upload->rejectionMessage(UploadRejection::NoFile);
	 *
	 * @param UploadRejectedException|UploadRejection $rejection Rejection, or its reason.
	 * @return string Translated text, or the English fallback.
	 */
	public function rejectionMessage(UploadRejectedException|UploadRejection $rejection): string {
		$reason = $rejection instanceof UploadRejection ? $rejection : $rejection->reason;

		if (!$this->app->hasService('txt')) {
			return $reason->fallbackMessage();
		}

		return $this->app->txt->get($reason->value, 'upload', 'citomni/upload', $reason->fallbackMessage());
	}


	/**
	 * Delete stored files strictly.
	 *
	 * Behavior:
	 * - Validates every path before anything is deleted. A path must be a
	 *   non-empty storage-relative file path; one invalid path deletes nothing.
	 * - Requires a configured storage whose root exists.
	 * - Deletes in the given order. A missing file counts as deleted.
	 * - Stops at the first file that cannot be deleted and throws.
	 *
	 * Notes:
	 * - Use delete() when the deletion is the task itself and must have happened
	 *   before anything else is recorded, for example a retention purge: delete
	 *   the files, then mark the record as purged, so a failure leads to a retry
	 *   instead of a record that lies. Removals after a committed decision use
	 *   cleanup().
	 * - A path that names a directory cannot be unlinked and fails like any
	 *   other file that cannot be deleted.
	 *
	 * Typical usage:
	 *   $this->app->upload->delete('private', $row['storage_path']);
	 *
	 * @param string $storage Storage name in upload.storages.
	 * @param string ...$paths Storage-relative file paths.
	 * @return void
	 * @throws \InvalidArgumentException When a path is empty or invalid.
	 * @throws UploadConfigException When the storage is not configured or its root does not exist.
	 * @throws UploadStorageException When a file cannot be deleted; later paths are not attempted.
	 */
	public function delete(string $storage, string ...$paths): void {
		$paths = self::filePaths($paths);
		$this->deleteFiles($storage, $this->storageRoot($storage), $paths);
	}


	/**
	 * Delete a stored file and every variant its profile defines, strictly.
	 *
	 * Behavior:
	 * - Validates the main path and resolves the profile. A profile with an
	 *   image block requires the image service, as on every use of the profile.
	 * - Derives each variant path from the main path and the variant's format,
	 *   and deletes the variants, in profile order, before the main file.
	 * - A missing file counts as deleted. Stops at the first file that cannot be
	 *   deleted and throws, so the main file stays when a variant fails.
	 *
	 * Notes:
	 * - Deleting the main file last keeps it as the anchor: an interrupted
	 *   deletion never leaves variants without their main file, and a retry
	 *   derives the same paths again.
	 * - Variant paths follow the variant formats in the profile. Changing a
	 *   variant's format changes the derived paths of files already stored.
	 *
	 * Typical usage:
	 *   $this->app->upload->deleteWithVariants($row['avatar_path'], 'avatar');
	 *
	 * @param string $path Storage-relative path of the main file.
	 * @param string|array<string, mixed> $profile Profile name in upload.profiles, or an inline profile array.
	 * @return void
	 * @throws \InvalidArgumentException When the path is empty or invalid.
	 * @throws UploadConfigException When the profile, its storage, or its root is invalid, or an image profile lacks the image service.
	 * @throws UploadStorageException When a file cannot be deleted; the remaining files are not attempted.
	 */
	public function deleteWithVariants(string $path, string|array $profile): void {
		$path = self::filePath($path);
		$resolved = $this->resolveProfile($profile);
		$this->deleteFiles($resolved['storage'], $this->storageRoot($resolved['storage']), self::profileFiles($path, $resolved));
	}


	/**
	 * Remove stored files with cleanup semantics.
	 *
	 * Behavior:
	 * - Validates every path before anything is removed. An invalid path is a
	 *   broken invariant, such as a malformed stored value, and throws.
	 * - Requires a configured storage whose root exists.
	 * - Attempts every path in the given order. A missing file counts as removed.
	 * - A file that cannot be removed is logged with storage, path (absolute),
	 *   stored_path, and error, and makes the result false. Filesystem failures
	 *   never throw, so cleanup() is safe in finally blocks.
	 *
	 * Notes:
	 * - Use cleanup() after a decision was committed: compensating a failed
	 *   persistence, the old file after a replacement, or a deleted attachment.
	 *   A file left behind is a harmless orphan; a record pointing to a deleted
	 *   file would not be harmless.
	 *
	 * Typical usage:
	 *   $this->app->upload->cleanup('private', $oldPath);
	 *
	 * @param string $storage Storage name in upload.storages.
	 * @param string ...$paths Storage-relative file paths.
	 * @return bool True when every file is gone.
	 * @throws \InvalidArgumentException When a path is empty or invalid.
	 * @throws UploadConfigException When the storage is not configured or its root does not exist.
	 */
	public function cleanup(string $storage, string ...$paths): bool {
		$paths = self::filePaths($paths);

		return $this->cleanupFiles($storage, $this->storageRoot($storage), $paths);
	}


	/**
	 * Remove a stored file and every variant its profile defines, with cleanup semantics.
	 *
	 * Behavior:
	 * - Validates the main path and resolves the profile, as deleteWithVariants().
	 * - Attempts every variant, in profile order, and then the main file, even
	 *   when an earlier file cannot be removed.
	 * - Failures are logged per file, with that file's own storage-relative path
	 *   as stored_path, and make the result false. Filesystem failures never throw.
	 *
	 * Typical usage:
	 *   $this->app->upload->cleanupWithVariants($stored['path'], 'avatar');
	 *
	 * @param string $path Storage-relative path of the main file.
	 * @param string|array<string, mixed> $profile Profile name in upload.profiles, or an inline profile array.
	 * @return bool True when the main file and every variant are gone.
	 * @throws \InvalidArgumentException When the path is empty or invalid.
	 * @throws UploadConfigException When the profile, its storage, or its root is invalid, or an image profile lacks the image service.
	 */
	public function cleanupWithVariants(string $path, string|array $profile): bool {
		$path = self::filePath($path);
		$resolved = $this->resolveProfile($profile);

		return $this->cleanupFiles($resolved['storage'], $this->storageRoot($resolved['storage']), self::profileFiles($path, $resolved));
	}


	/**
	 * Remove exactly the files listed in store results, with cleanup semantics.
	 *
	 * Behavior:
	 * - Takes results of storeUpload(), storeLocal(), or the "stored" list of
	 *   storeUploads(). Every result and path is validated before the first
	 *   filesystem call, and every storage root before anything is removed:
	 *   each result needs a string storage, a valid path, and a variants array
	 *   whose entries have a valid path, and each storage an existing root.
	 * - Per result, attempts every variant, in result order, and then the main
	 *   file, even when an earlier file cannot be removed.
	 * - Failures are logged per file, as in cleanup(), and make the result false.
	 *   Filesystem failures never throw, so cleanupStored() is safe in finally
	 *   blocks. No results means nothing to remove, which is true.
	 *
	 * Notes:
	 * - Use it for results that were not committed: compensating a failed
	 *   persistence, or discarding an all-or-nothing batch with rejections. A
	 *   path read back from a record is removed with cleanup() or
	 *   cleanupWithVariants().
	 * - Needs neither the profile nor the image service: the result lists its
	 *   own files, so a later profile change does not change what is removed.
	 *
	 * Typical usage:
	 *   $this->app->upload->cleanupStored(...$batch['stored']);
	 *
	 * @param array<string, mixed> ...$stored Store results.
	 * @return bool True when every listed file is gone.
	 * @throws \InvalidArgumentException When a result lacks a string storage, a string path, or a variants array with string paths, or a path is empty or invalid.
	 * @throws UploadConfigException When a storage is not configured or its root does not exist.
	 */
	public function cleanupStored(array ...$stored): bool {
		$jobs = [];

		foreach ($stored as $result) {
			$storage = $result['storage'] ?? null;
			$path = $result['path'] ?? null;
			$variants = $result['variants'] ?? null;

			if (!\is_string($storage) || !\is_string($path) || !\is_array($variants)) {
				throw new \InvalidArgumentException("cleanupStored() expects store results with a string 'storage', a string 'path', and a 'variants' array. Pass a batch as cleanupStored(...\$batch['stored']); remove a path read back from a record with cleanup() or cleanupWithVariants().");
			}

			$paths = [];

			foreach ($variants as $variant) {
				if (!\is_string($variant['path'] ?? null)) {
					throw new \InvalidArgumentException("cleanupStored() expects every entry in 'variants' to have a string 'path'.");
				}

				$paths[] = $variant['path'];
			}

			$paths[] = $path;
			$jobs[] = [$storage, self::filePaths($paths)];
		}

		// Every root is verified before the first file of any result is removed.
		foreach ($jobs as [$storage]) {
			$this->storageRoot($storage);
		}

		$gone = true;

		foreach ($jobs as [$storage, $paths]) {
			$gone = $this->cleanupFiles($storage, $this->storageRoot($storage), $paths) && $gone;
		}

		return $gone;
	}


	/**
	 * Derive the path of a variant from the main path.
	 *
	 * Behavior:
	 * - Returns "{directory}/{name}-{variant}.{extension}", where name is the main
	 *   file name without its last extension and the extension follows the
	 *   variant's format (jpeg is stored as .jpg, tiff as .tif). A re-encoded
	 *   main and a kept original therefore derive the same variant paths.
	 * - Derives from configuration alone: no filesystem access, no finfo, and no
	 *   citomni/image. A profile with an image block still requires the image
	 *   service to be registered.
	 *
	 * Typical usage:
	 *   $thumb = $this->app->upload->variantPath($row['avatar_path'], 'thumb', 'avatar');
	 *
	 * @param string $path Storage-relative path of the main file.
	 * @param string $variant Variant key defined by the profile.
	 * @param string|array<string, mixed> $profile Profile name in upload.profiles, or an inline profile array.
	 * @return string Storage-relative variant path.
	 * @throws \InvalidArgumentException When the path is empty or invalid, or the profile defines no such variant.
	 * @throws UploadConfigException When the profile is invalid, or an image profile lacks the image service.
	 */
	public function variantPath(string $path, string $variant, string|array $profile): string {
		$path = self::filePath($path);
		$resolved = $this->resolveProfile($profile);
		$entry = $resolved['image']['variants'][$variant] ?? null;

		if ($entry === null) {
			throw new \InvalidArgumentException($resolved['label'] . ' defines no variant ' . self::describe($variant) . '.');
		}

		return self::variantFile($path, $variant, $entry['format']);
	}


	/**
	 * Return the absolute filesystem path of a stored file.
	 *
	 * Behavior:
	 * - Returns the storage root as configured plus the validated relative path.
	 * - No filesystem access: neither the file nor the root is checked, and
	 *   realpath() is not used.
	 *
	 * Typical usage:
	 *   $file = $this->app->upload->absolutePath('private', $row['storage_path']);
	 *
	 * @param string $storage Storage name in upload.storages.
	 * @param string $path Storage-relative file path.
	 * @return string Absolute path.
	 * @throws \InvalidArgumentException When the path is empty or invalid.
	 * @throws UploadConfigException When the storage is not configured or its root is not a non-empty string.
	 */
	public function absolutePath(string $storage, string $path): string {
		$path = self::filePath($path);

		return self::absolute($this->configuredRoot($storage), $path);
	}


	/**
	 * Return the web path of a stored file in a web-served storage.
	 *
	 * Behavior:
	 * - Returns "{web_path}/{path}" without a leading slash and without a base
	 *   URL, which the HTTP layer owns. A web_path of "" serves the storage from
	 *   the web root, so the path is returned alone.
	 * - A storage whose web_path is null is not served directly: its files go
	 *   through an authorizing controller, so asking for a web path is a
	 *   configuration error.
	 * - No filesystem access, no finfo, and no citomni/image.
	 *
	 * Typical usage:
	 *   $src = $baseUrl . '/' . $this->app->upload->webPath('public', $row['avatar_path']);
	 *
	 * @param string $storage Storage name in upload.storages.
	 * @param string $path Storage-relative file path.
	 * @return string Relative web path.
	 * @throws \InvalidArgumentException When the path is empty or invalid.
	 * @throws UploadConfigException When the storage is not configured, has no web_path, or has an invalid one.
	 */
	public function webPath(string $storage, string $path): string {
		$path = self::filePath($path);
		$base = $this->webBase($storage);

		return $base === '' ? $path : $base . '/' . $path;
	}


	// ----------------------------------------------------------------
	// Profiles
	// ----------------------------------------------------------------

	/**
	 * Resolve a profile name or inline profile to a normalized profile.
	 *
	 * Behavior:
	 * - A name is looked up as upload.profiles[$name], normalized from
	 *   Cfg::toArray(), and memoized per name after successful validation.
	 * - An inline array is normalized on every call and never memoized.
	 *
	 * @param string|array<string, mixed> $profile Profile name or inline profile.
	 * @return array<string, mixed> Normalized profile.
	 * @throws UploadConfigException When the profile is unknown or invalid.
	 */
	private function resolveProfile(string|array $profile): array {
		if (\is_array($profile)) {
			return $this->normalizeProfile($profile, 'Inline upload profile');
		}

		if (isset($this->profiles[$profile])) {
			return $this->profiles[$profile];
		}

		$node = $this->app->cfg->upload->profiles[$profile] ?? null;

		if ($node === null) {
			throw new UploadConfigException("Upload profile '{$profile}' is not defined in upload.profiles.");
		}

		return $this->profiles[$profile] = $this->normalizeProfile($node instanceof Cfg ? $node->toArray() : $node, "Upload profile '{$profile}'");
	}


	/**
	 * Validate a raw profile and return its normalized form.
	 *
	 * Behavior:
	 * - Only these keys are allowed: storage, directory, accept,
	 *   max_bytes, shard, hash, image.
	 * - storage: required non-empty string naming an entry in upload.storages.
	 * - directory: string, default "", a valid storage-relative path.
	 * - accept: required; see normalizeAccept().
	 * - max_bytes: required key; an int > 0, or null for no service limit.
	 * - shard: int 0-2, default 0.
	 * - hash: null (default) or an algorithm from hash_algos(), stored lowercase.
	 * - image: null (default) or an image block; see normalizeImage().
	 *
	 * Notes:
	 * - Messages start with $label so the faulty definition can be found.
	 * - An explicit null is only valid where the type allows it (max_bytes,
	 *   hash, image); it never stands in for a default.
	 *
	 * @param mixed $raw Raw profile.
	 * @param string $label Profile label for messages.
	 * @return array{label: string, storage: string, directory: string, accept: array<string, true>|null, max_bytes: int|null, shard: int, hash: string|null, image: array<string, mixed>|null} Normalized profile.
	 * @throws UploadConfigException When the profile breaks a rule; an invalid directory keeps the path error as previous.
	 */
	private function normalizeProfile(mixed $raw, string $label): array {
		if (!\is_array($raw)) {
			throw new UploadConfigException("{$label} must be an array, got " . \get_debug_type($raw) . '.');
		}

		$unknown = \array_diff_key($raw, self::PROFILE_KEYS);

		if ($unknown !== []) {
			throw new UploadConfigException("{$label}: unknown key(s) " . self::keyList($unknown) . '; allowed: ' . \implode(', ', \array_keys(self::PROFILE_KEYS)) . '.');
		}

		$storage = $raw['storage'] ?? null;

		if (!\is_string($storage) || $storage === '') {
			throw new UploadConfigException("{$label}: 'storage' must be a non-empty string.");
		}

		if (($this->app->cfg->upload->storages[$storage] ?? null) === null) {
			throw new UploadConfigException("{$label}: storage '{$storage}' is not defined in upload.storages.");
		}

		$directory = \array_key_exists('directory', $raw) ? $raw['directory'] : '';

		if (!\is_string($directory)) {
			throw new UploadConfigException("{$label}: 'directory' must be a string.");
		}

		// The directory is profile cfg, so a path error is a configuration error naming the profile.
		try {
			StoragePath::validateRelative($directory);
		} catch (\InvalidArgumentException $e) {
			throw new UploadConfigException("{$label}: invalid 'directory'. " . $e->getMessage(), 0, $e);
		}

		$accept = $this->normalizeAccept($raw['accept'] ?? null, $label);

		if (!\array_key_exists('max_bytes', $raw)) {
			throw new UploadConfigException("{$label}: 'max_bytes' is required (an int > 0, or null for no service limit).");
		}

		$maxBytes = $raw['max_bytes'];

		if ($maxBytes !== null && (!\is_int($maxBytes) || $maxBytes < 1)) {
			throw new UploadConfigException("{$label}: 'max_bytes' must be an int > 0, or null for no service limit.");
		}

		$shard = \array_key_exists('shard', $raw) ? $raw['shard'] : 0;

		if (!\is_int($shard) || $shard < 0 || $shard > 2) {
			throw new UploadConfigException("{$label}: 'shard' must be an int from 0 to 2.");
		}

		$hash = $raw['hash'] ?? null;

		if ($hash !== null) {
			if (!\is_string($hash) || !\in_array(\strtolower($hash), \hash_algos(), true)) {
				throw new UploadConfigException("{$label}: 'hash' must be null or an algorithm from hash_algos(), got " . self::describe($hash) . '.');
			}

			$hash = \strtolower($hash);
		}

		return [
			'label' => $label,
			'storage' => $storage,
			'directory' => $directory,
			'accept' => $accept,
			'max_bytes' => $maxBytes,
			'shard' => $shard,
			'hash' => $hash,
			'image' => $this->normalizeImage($raw['image'] ?? null, $label),
		];
	}


	/**
	 * Validate an accept list and return it as a set of canonical MIME types.
	 *
	 * Behavior:
	 * - Requires a non-empty list.
	 * - Exactly ['*'] accepts every type; "*" combined with anything else is
	 *   rejected.
	 * - Every other entry is canonicalized (MimeMap::canonical()) and must then
	 *   be a bare type/subtype of RFC 6838 restricted-name characters. Patterns
	 *   such as "image/*", parameters, and surrounding whitespace are rejected,
	 *   because they would never match a detected type.
	 *
	 * @param mixed $accept Raw accept value.
	 * @param string $label Profile label for messages.
	 * @return array<string, true>|null Set of canonical MIME types, or null for ['*'].
	 * @throws UploadConfigException When the list or an entry is invalid.
	 */
	private function normalizeAccept(mixed $accept, string $label): ?array {
		if (!\is_array($accept) || $accept === [] || !\array_is_list($accept)) {
			throw new UploadConfigException("{$label}: 'accept' must be a non-empty list of MIME types, or exactly ['*'].");
		}

		if (\in_array('*', $accept, true)) {
			if (\count($accept) !== 1) {
				throw new UploadConfigException("{$label}: '*' in 'accept' cannot be combined with other entries.");
			}

			return null;
		}

		$set = [];

		foreach ($accept as $entry) {
			$canonical = \is_string($entry) ? MimeMap::canonical($entry) : '';

			if (\preg_match(self::MIME_TYPE, $canonical) !== 1) {
				throw new UploadConfigException("{$label}: invalid 'accept' entry " . self::describe($entry) . "; expected a bare type/subtype such as 'application/pdf' (RFC 6838), or exactly ['*'].");
			}

			$set[$canonical] = true;
		}

		return $set;
	}


	/**
	 * Validate an image block and return its normalized form.
	 *
	 * Behavior:
	 * - null means "no image outputs": images are stored as plain files.
	 * - Only the keys main, variants, and options are allowed.
	 * - main is a required key: an output spec, or null to keep the original
	 *   bytes (passthrough).
	 * - variants (default []) maps keys matching [a-z0-9_]+ (never "main") to
	 *   output specs.
	 * - options (default []) must be an array; its content is citomni/image's
	 *   to validate.
	 * - The image service must be registered. This is checked when the profile
	 *   is normalized, not when an image arrives, so a missing citomni/image
	 *   provider fails on every use of the profile, including deletion and path
	 *   derivation.
	 *
	 * @param mixed $image Raw image block.
	 * @param string $label Profile label for messages.
	 * @return array{main: array{spec: array<string, mixed>, format: ImageFormat}|null, variants: array<string, array{spec: array<string, mixed>, format: ImageFormat}>, options: array<string, mixed>}|null Normalized block, or null.
	 * @throws UploadConfigException When the block breaks a rule, or the image service is not registered.
	 */
	private function normalizeImage(mixed $image, string $label): ?array {
		if ($image === null) {
			return null;
		}

		if (!\is_array($image)) {
			throw new UploadConfigException("{$label}: 'image' must be an array or null.");
		}

		$unknown = \array_diff_key($image, self::IMAGE_KEYS);

		if ($unknown !== []) {
			throw new UploadConfigException("{$label}: unknown key(s) in 'image': " . self::keyList($unknown) . '; allowed: main, variants, options.');
		}

		if (!\array_key_exists('main', $image)) {
			throw new UploadConfigException("{$label}: 'image' requires a 'main' key (an output spec, or null to keep the original bytes).");
		}

		$main = $image['main'] === null ? null : $this->normalizeSpec($image['main'], $label, 'main');
		$rawVariants = \array_key_exists('variants', $image) ? $image['variants'] : [];

		if (!\is_array($rawVariants)) {
			throw new UploadConfigException("{$label}: 'image.variants' must be an array of output specs keyed by variant name.");
		}

		$variants = [];

		foreach ($rawVariants as $key => $spec) {
			$key = (string)$key;

			if ($key === 'main' || \preg_match(self::VARIANT_KEY, $key) !== 1) {
				throw new UploadConfigException("{$label}: invalid variant key " . self::describe($key) . "; expected [a-z0-9_]+ other than 'main'.");
			}

			$variants[$key] = $this->normalizeSpec($spec, $label, 'variants.' . $key);
		}

		$options = \array_key_exists('options', $image) ? $image['options'] : [];

		if (!\is_array($options)) {
			throw new UploadConfigException("{$label}: 'image.options' must be an array of citomni/image job options.");
		}

		if (!$this->app->hasService('image')) {
			throw new UploadConfigException("{$label}: an image block needs the citomni/image service ('image'), which is not registered. List \\CitOmni\\Image\\Boot\\Registry::class in config/providers.php.");
		}

		return ['main' => $main, 'variants' => $variants, 'options' => $options];
	}


	/**
	 * Validate the Upload-owned parts of one citomni/image output spec.
	 *
	 * Behavior:
	 * - The spec must be an array without "path" and "overwrite": Upload sets the
	 *   path itself and never sets overwrite, so citomni/image's no-clobber
	 *   default applies.
	 * - "format" is required and resolved through ImageFormat: a string with
	 *   ImageFormat::tryFrom(), an ImageFormat case as is. Any other type is
	 *   rejected, including the array a kernel without enum preservation makes
	 *   of an enum case in a cfg file.
	 * - Everything else in the spec is left untouched for citomni/image.
	 *
	 * @param mixed $spec Raw output spec.
	 * @param string $label Profile label for messages.
	 * @param string $output Output name for messages ("main" or "variants.<key>").
	 * @return array{spec: array<string, mixed>, format: ImageFormat} The unchanged spec and its format.
	 * @throws UploadConfigException When the spec breaks a rule.
	 */
	private function normalizeSpec(mixed $spec, string $label, string $output): array {
		if (!\is_array($spec)) {
			throw new UploadConfigException("{$label}: 'image.{$output}' must be an output spec array" . ($output === 'main' ? ' or null' : '') . '.');
		}

		if (\array_key_exists('path', $spec) || \array_key_exists('overwrite', $spec)) {
			throw new UploadConfigException("{$label}: 'image.{$output}' must not contain 'path' or 'overwrite'; Upload sets the path and never overwrites.");
		}

		if (!\array_key_exists('format', $spec)) {
			throw new UploadConfigException("{$label}: 'image.{$output}' requires a 'format'.");
		}

		$format = $spec['format'];

		if ($format instanceof ImageFormat) {
			return ['spec' => $spec, 'format' => $format];
		}

		if (!\is_string($format)) {
			throw new UploadConfigException(
				"{$label}: 'image.{$output}.format' must be a format string or an ImageFormat case, got " . \get_debug_type($format) . '.'
				. (\is_array($format) ? ' An array usually means a kernel that flattens enum cases in cfg; use the string form.' : '')
			);
		}

		$resolved = ImageFormat::tryFrom($format);

		if ($resolved === null) {
			$known = \implode(', ', \array_map(static fn(ImageFormat $case): string => $case->value, ImageFormat::cases()));
			throw new UploadConfigException("{$label}: 'image.{$output}.format' " . self::describe($format) . " is not a known image format (known: {$known}).");
		}

		return ['spec' => $spec, 'format' => $resolved];
	}


	// ----------------------------------------------------------------
	// Storage configuration
	// ----------------------------------------------------------------

	/**
	 * Return the configured root directory of a storage, without filesystem access.
	 *
	 * @param string $storage Storage name.
	 * @return string Root directory exactly as configured.
	 * @throws UploadConfigException When the storage is undefined or its root is not a non-empty string.
	 */
	private function configuredRoot(string $storage): string {
		if (isset($this->configuredRoots[$storage])) {
			return $this->configuredRoots[$storage];
		}

		$node = $this->app->cfg->upload->storages[$storage] ?? null;

		if ($node === null) {
			throw new UploadConfigException("Storage '{$storage}' is not defined in upload.storages.");
		}

		$root = $node['root'] ?? null;

		if (!\is_string($root) || $root === '') {
			throw new UploadConfigException("Storage '{$storage}': upload.storages.{$storage}.root must be a non-empty string.");
		}

		return $this->configuredRoots[$storage] = $root;
	}


	/**
	 * Return the verified root directory of a storage.
	 *
	 * Behavior:
	 * - Takes the configured root (see configuredRoot()).
	 * - Requires the root to exist as a directory. Roots are provisioned by the
	 *   deployment; Upload only creates subdirectories below them.
	 * - Memoizes the root after a successful check, so the check runs once per
	 *   storage per service instance.
	 *
	 * @param string $storage Storage name.
	 * @return string Root directory exactly as configured.
	 * @throws UploadConfigException When the storage is undefined, its root is not a string, or the root does not exist.
	 */
	private function storageRoot(string $storage): string {
		if (isset($this->roots[$storage])) {
			return $this->roots[$storage];
		}

		$root = $this->configuredRoot($storage);

		if (!\is_dir($root)) {
			throw new UploadConfigException("Storage '{$storage}': root directory {$root} does not exist. Storage roots must be provisioned; Upload only creates subdirectories.");
		}

		return $this->roots[$storage] = $root;
	}


	/**
	 * Return the validated plain-file and directory modes.
	 *
	 * @return array{0: int|null, 1: int} [file_mode, or null for no chmod; dir_mode].
	 * @throws UploadConfigException When upload.file_mode or upload.dir_mode is invalid.
	 */
	private function modes(): array {
		if ($this->modes !== null) {
			return $this->modes;
		}

		$fileMode = $this->app->cfg->upload->file_mode;
		$dirMode = $this->app->cfg->upload->dir_mode;

		if ($fileMode !== null && (!\is_int($fileMode) || $fileMode < 0 || $fileMode > 0777)) {
			throw new UploadConfigException('upload.file_mode must be an int from 0 to 0777 (octal), or null for no chmod; got ' . self::describe($fileMode) . '.');
		}

		if (!\is_int($dirMode) || $dirMode < 0 || $dirMode > 0777) {
			throw new UploadConfigException('upload.dir_mode must be an int from 0 to 0777 (octal); got ' . self::describe($dirMode) . '.');
		}

		return $this->modes = [$fileMode, $dirMode];
	}


	/**
	 * Return the validated web path of a storage, without filesystem access.
	 *
	 * Behavior:
	 * - null: the storage is not served directly, so there is no web path.
	 * - "": the storage is served from the web root.
	 * - Anything else must follow the storage path rules (no leading or trailing
	 *   "/", URL-unreserved characters), so web paths need no encoding and never
	 *   start with a slash.
	 * - The validated value is memoized per storage.
	 *
	 * @param string $storage Storage name.
	 * @return string Web path prefix; "" for the web root.
	 * @throws UploadConfigException When the storage is undefined, has no web_path, or has an invalid one.
	 */
	private function webBase(string $storage): string {
		if (isset($this->webBases[$storage])) {
			return $this->webBases[$storage];
		}

		$node = $this->app->cfg->upload->storages[$storage] ?? null;

		if ($node === null) {
			throw new UploadConfigException("Storage '{$storage}' is not defined in upload.storages.");
		}

		$webPath = $node['web_path'] ?? null;

		if ($webPath === null) {
			throw new UploadConfigException("Storage '{$storage}' has no web_path; its files are served through an authorizing controller.");
		}

		if (!\is_string($webPath)) {
			throw new UploadConfigException("Storage '{$storage}': upload.storages.{$storage}.web_path must be a string or null.");
		}

		try {
			StoragePath::validateRelative($webPath);
		} catch (\InvalidArgumentException $e) {
			throw new UploadConfigException("Storage '{$storage}': invalid upload.storages.{$storage}.web_path. " . $e->getMessage(), 0, $e);
		}

		return $this->webBases[$storage] = $webPath;
	}


	// ----------------------------------------------------------------
	// Pipeline
	// ----------------------------------------------------------------

	/**
	 * Map a PHP upload error code to a rejection or a storage error.
	 *
	 * @param int $error UPLOAD_ERR_* code of the file entry.
	 * @param string $originalName Sanitized original name, for the rejection context.
	 * @return void Returns only for UPLOAD_ERR_OK.
	 * @throws UploadRejectedException For INI_SIZE and FORM_SIZE (TooLarge), PARTIAL (Partial), and NO_FILE (NoFile); the context carries upload_error.
	 * @throws UploadStorageException For NO_TMP_DIR, CANT_WRITE, EXTENSION, and unknown codes.
	 */
	private function mapUploadError(int $error, string $originalName): void {
		if ($error === \UPLOAD_ERR_OK) {
			return;
		}

		throw match ($error) {
			\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => new UploadRejectedException(UploadRejection::TooLarge, ['original_name' => $originalName, 'upload_error' => $error]),
			\UPLOAD_ERR_PARTIAL => new UploadRejectedException(UploadRejection::Partial, ['original_name' => $originalName, 'upload_error' => $error]),
			\UPLOAD_ERR_NO_FILE => new UploadRejectedException(UploadRejection::NoFile, ['upload_error' => $error]),
			default => new UploadStorageException(\sprintf(
				'PHP reported upload error %d (%s) for %s. This is a server-side problem.',
				$error,
				match ($error) {
					\UPLOAD_ERR_NO_TMP_DIR => 'UPLOAD_ERR_NO_TMP_DIR',
					\UPLOAD_ERR_CANT_WRITE => 'UPLOAD_ERR_CANT_WRITE',
					\UPLOAD_ERR_EXTENSION => 'UPLOAD_ERR_EXTENSION',
					default => 'unknown code',
				},
				$originalName,
			)),
		};
	}


	/**
	 * Run the intake pipeline for a source that passed the entry checks.
	 *
	 * @param string $source Readable regular file, or the file uploaded in the current request.
	 * @param array<string, mixed> $profile Normalized profile.
	 * @param string $subdir Validated storage-relative subdirectory.
	 * @param string $originalName Sanitized original name.
	 * @param bool $uploaded True when $source was uploaded in the current request; it is then moved instead of copied.
	 * @return array<string, mixed> Upload result: storage, path, mime, source_mime, size, original_name, width, height, hash, variants.
	 * @throws UploadConfigException When a storage precondition fails, or citomni/image rejects the profile's output specs or options.
	 * @throws UploadRejectedException When generic validation or the image path rejects the file.
	 * @throws UploadStorageException When reading or writing fails, or citomni/image cannot publish all outputs.
	 */
	private function ingest(string $source, array $profile, string $subdir, string $originalName, bool $uploaded): array {
		// -- 1. Storage preconditions -------------------------------------

		$root = $this->storageRoot($profile['storage']);
		[$fileMode, $dirMode] = $this->modes();

		// -- 2. Generic validation ----------------------------------------
		// Size and type are measured here; client-reported values are never used.

		$size = @\filesize($source);

		if ($size === false) {
			throw new UploadStorageException('Failed to read the size of ' . $source . '.');
		}

		if ($size === 0) {
			throw new UploadRejectedException(UploadRejection::Empty, ['original_name' => $originalName, 'size' => 0]);
		}

		if ($profile['max_bytes'] !== null && $size > $profile['max_bytes']) {
			throw new UploadRejectedException(UploadRejection::TooLarge, ['original_name' => $originalName, 'size' => $size, 'max_bytes' => $profile['max_bytes']]);
		}

		$mime = MimeMap::canonical($this->detectMime($source));

		if ($profile['accept'] !== null && !isset($profile['accept'][$mime])) {
			throw new UploadRejectedException(UploadRejection::TypeNotAllowed, ['original_name' => $originalName, 'size' => $size, 'mime' => $mime]);
		}

		// -- 3. Route -----------------------------------------------------
		// Future seam: content scanning would go here, after validation and before any write.
		// An image profile hands an image type to citomni/image; everything else, including a
		// PDF in an image profile, is a plain file. inspect() runs before anything is written.

		$context = ['original_name' => $originalName, 'size' => $size, 'mime' => $mime];
		$image = $profile['image'] !== null && MimeMap::imageFormat($mime) !== null ? $profile['image'] : null;
		$info = $image !== null ? $this->inspectImage($source, $mime, $context) : null;
		$produced = $image !== null && $image['main'] !== null;

		// -- 4. Write with compensation -----------------------------------

		$name = \bin2hex(\random_bytes(16));
		$shards = [];

		for ($level = 0; $level < $profile['shard']; ++$level) {
			$shards[] = \substr($name, $level * 2, 2);
		}

		$directory = StoragePath::join($profile['directory'], $subdir, ...$shards);
		$fileName = $name . '.' . MimeMap::extension($produced ? $image['main']['format']->mime() : $mime);
		$path = StoragePath::join($directory, $fileName);
		[$targets, $outputs] = $image !== null ? self::imageOutputs($image, $root, $path) : [[], []];

		$written = [];
		$committed = false;

		try {
			$absoluteDirectory = $this->ensureDirectory($root, $directory, $dirMode);
			$files = [];

			// The image job runs before the original is written, so a rejected image
			// costs no copy of a possibly large original.
			if ($outputs !== []) {
				$files = $this->saveImageOutputs($source, $profile, $outputs, $context, $written);

				foreach ($outputs as $output) {
					self::applyFileMode($output['path'], $fileMode);
				}
			}

			$final = $produced
				? $outputs['main']['path']
				: $this->writeFile($source, $absoluteDirectory, $fileName, $fileMode, $uploaded, $written);
			$hash = null;

			if ($profile['hash'] !== null) {
				\error_clear_last();
				$digest = @\hash_file($profile['hash'], $final);

				if ($digest === false) {
					throw new UploadStorageException('Failed to hash ' . $final . ' with ' . $profile['hash'] . self::lastError() . '.');
				}

				$hash = $profile['hash'] . ':' . $digest;
			}

			$variants = [];

			foreach (\array_keys($image['variants'] ?? []) as $key) {
				$variants[$key] = self::variantEntry($targets[$key], $files[$key]);
			}

			// A plain file and a kept original report Upload's own type and size (and inspect()'s
			// display size for images); a produced main reports citomni/image's result.
			$result = [
				'storage' => $profile['storage'],
				'path' => $path,
				'mime' => $produced ? $files['main']['mime'] : $mime,
				'source_mime' => $mime,
				'size' => $produced ? $files['main']['bytes'] : $size,
				'original_name' => $originalName,
				'width' => $produced ? $files['main']['width'] : ($info['display_width'] ?? null),
				'height' => $produced ? $files['main']['height'] : ($info['display_height'] ?? null),
				'hash' => $hash,
				'variants' => $variants,
			];

			$committed = true;

			return $result;
		} finally {
			if (!$committed) {
				$this->discard($written, $profile['storage']);
			}
		}
	}


	/**
	 * Detect the MIME type of a file with finfo.
	 *
	 * @param string $path File to inspect.
	 * @return string Detected MIME type, not yet canonicalized.
	 * @throws UploadStorageException When finfo cannot read the file.
	 */
	private function detectMime(string $path): string {
		$this->finfo ??= new \finfo(\FILEINFO_MIME_TYPE);

		\error_clear_last();
		$mime = @$this->finfo->file($path);

		if ($mime === false) {
			throw new UploadStorageException('Failed to detect the MIME type of ' . $path . self::lastError() . '.');
		}

		return $mime;
	}


	/**
	 * Ensure that a storage-relative directory exists below a verified root.
	 *
	 * Behavior:
	 * - Returns the root itself for "".
	 * - Creates missing directories with mkdir($path, $dirMode, true); the mode
	 *   is subject to umask.
	 * - Race-safe: a directory created concurrently by another process counts
	 *   as success.
	 *
	 * @param string $root Verified storage root.
	 * @param string $relativeDir Validated storage-relative directory.
	 * @param int $dirMode Mode for created directories.
	 * @return string Absolute directory path.
	 * @throws UploadStorageException When the directory cannot be created.
	 */
	private function ensureDirectory(string $root, string $relativeDir, int $dirMode): string {
		$path = self::absolute($root, $relativeDir);

		if (\is_dir($path)) {
			return $path;
		}

		\error_clear_last();

		if (!@\mkdir($path, $dirMode, true) && !\is_dir($path)) {
			throw new UploadStorageException('Failed to create directory ' . $path . self::lastError() . '.');
		}

		return $path;
	}


	/**
	 * Write a file to its final name through a temporary file in the target directory.
	 *
	 * Behavior:
	 * - Moves an uploaded file with move_uploaded_file(), or copies a local file
	 *   with copy(), to ".{fileName}.{random}.tmp" next to the target, the naming
	 *   citomni/image uses as well, so half-written files are dot-prefixed.
	 * - Registers the temporary path in $written before writing, because a
	 *   failed write can leave a partial file behind; move_uploaded_file() also
	 *   copies when the upload directory is on another filesystem.
	 * - Refuses an existing target. Targets are fresh 128-bit names, so an
	 *   existing one is a fault, not a collision. The existing file is not
	 *   Upload's and is never touched.
	 * - Renames the temporary file to the target, replaces its entry in
	 *   $written, and applies $fileMode when it is not null.
	 *
	 * Notes:
	 * - tempnam() is not used: it creates files with mode 0600, which can make
	 *   public files unreadable for the web server.
	 * - rename() after an existence check instead of link(): no other process
	 *   knows the target name, so a real race cannot occur.
	 * - On POSIX systems both writes leave mode 0666 & ~umask;
	 *   move_uploaded_file() applies it after renaming PHP's 0600 upload file.
	 *
	 * @param string $source File to write.
	 * @param string $directory Existing target directory.
	 * @param string $fileName Target file name.
	 * @param int|null $fileMode Mode for the target; null keeps the mode the write created.
	 * @param bool $uploaded Move $source with move_uploaded_file() instead of copying it.
	 * @param array<int, string> $written Absolute paths created by the current call, updated in place.
	 * @return string Absolute target path.
	 * @throws UploadStorageException When moving, copying, renaming, or chmod fails, or the target exists.
	 */
	private function writeFile(string $source, string $directory, string $fileName, ?int $fileMode, bool $uploaded, array &$written): string {
		$final = self::absolute($directory, $fileName);
		$temp = self::absolute($directory, '.' . $fileName . '.' . \bin2hex(\random_bytes(6)) . '.tmp');

		$written[] = $temp;
		$slot = \array_key_last($written);

		\error_clear_last();

		if ($uploaded ? !@\move_uploaded_file($source, $temp) : !@\copy($source, $temp)) {
			throw new UploadStorageException(($uploaded ? 'Failed to move the uploaded file ' : 'Failed to copy ') . $source . ' to ' . $temp . self::lastError() . '.');
		}

		if (\file_exists($final)) {
			throw new UploadStorageException('Target already exists: ' . $final . '. Upload never overwrites files.');
		}

		\error_clear_last();

		if (!@\rename($temp, $final)) {
			throw new UploadStorageException('Failed to rename ' . $temp . ' to ' . $final . self::lastError() . '.');
		}

		$written[$slot] = $final;
		self::applyFileMode($final, $fileMode);

		return $final;
	}


	// ----------------------------------------------------------------
	// Image path
	// ----------------------------------------------------------------

	/**
	 * Inspect an image source with citomni/image and require MIME agreement with finfo.
	 *
	 * Behavior:
	 * - citomni/image is authoritative for understanding images; Upload only
	 *   compares its detected type with the canonical finfo type.
	 * - A disagreement is treated as ambiguous input and rejected fail-closed.
	 * - Only ImageInputException is translated. Anything else from inspect(),
	 *   including configuration errors raised by the Image service's init(),
	 *   propagates unchanged.
	 *
	 * @param string $source File to inspect.
	 * @param string $mime Canonical finfo type.
	 * @param array<string, mixed> $context Rejection context (original_name, size, mime).
	 * @return array<string, mixed> The inspect() result.
	 * @throws UploadRejectedException InvalidImage when citomni/image rejects the content (its ImageInputException is previous); TypeNotAllowed when the types disagree (the context adds image_mime).
	 */
	private function inspectImage(string $source, string $mime, array $context): array {
		try {
			$info = $this->app->image->inspect($source);
		} catch (ImageInputException $e) {
			throw new UploadRejectedException(UploadRejection::InvalidImage, $context, $e);
		}

		if ($info['mime'] !== $mime) {
			throw new UploadRejectedException(UploadRejection::TypeNotAllowed, $context + ['image_mime' => $info['mime']]);
		}

		return $info;
	}


	/**
	 * Plan the image outputs: paths Upload chose, with the profile's specs.
	 *
	 * @param array<string, mixed> $image Normalized image block.
	 * @param string $root Verified storage root.
	 * @param string $path Storage-relative path of the main file.
	 * @return array{0: array<int|string, string>, 1: array<int|string, array<string, mixed>>} Storage-relative targets and Image::save() outputs, keyed alike: main first (absent when the original is kept), then the variants in profile order.
	 */
	private static function imageOutputs(array $image, string $root, string $path): array {
		$targets = [];
		$outputs = [];

		if ($image['main'] !== null) {
			$targets['main'] = $path;
			$outputs['main'] = $image['main']['spec'] + ['path' => self::absolute($root, $path)];
		}

		foreach ($image['variants'] as $key => $variant) {
			$targets[$key] = self::variantFile($path, $key, $variant['format']);
			$outputs[$key] = $variant['spec'] + ['path' => self::absolute($root, $targets[$key])];
		}

		return [$targets, $outputs];
	}


	/**
	 * Run the image job with citomni/image and translate its failures.
	 *
	 * Behavior:
	 * - Passes the outputs and the profile's options unchanged. Upload never
	 *   sets "overwrite", so citomni/image publishes without clobbering.
	 * - Registers written targets for compensation by output key, with the
	 *   paths Upload chose: every output after success, exactly the keys of
	 *   committed() after ImageWriteException. Upload therefore never removes a
	 *   path it did not name.
	 * - Translation: ImageInputException becomes InvalidImage;
	 *   ImageWriteException and ImageTargetExistsException become
	 *   UploadStorageException; \InvalidArgumentException becomes
	 *   UploadConfigException naming the profile. ImageCapabilityException and
	 *   other runtime failures propagate unchanged.
	 *
	 * @param string $source Source file.
	 * @param array<string, mixed> $profile Normalized profile.
	 * @param array<int|string, array<string, mixed>> $outputs Image::save() outputs.
	 * @param array<string, mixed> $context Rejection context (original_name, size, mime).
	 * @param array<int, string> $written Absolute paths created by the current call, updated in place.
	 * @return array<int|string, array<string, mixed>> Image results, keyed like $outputs.
	 * @throws UploadRejectedException When citomni/image rejects the content.
	 * @throws UploadStorageException When citomni/image cannot publish all outputs.
	 * @throws UploadConfigException When citomni/image rejects an output spec or the options.
	 */
	private function saveImageOutputs(string $source, array $profile, array $outputs, array $context, array &$written): array {
		try {
			$files = $this->app->image->save($source, $outputs, $profile['image']['options']);
		} catch (ImageInputException $e) {
			throw new UploadRejectedException(UploadRejection::InvalidImage, $context, $e);
		} catch (ImageWriteException $e) {
			foreach (\array_keys($e->committed()) as $key) {
				if (isset($outputs[$key])) {
					$written[] = $outputs[$key]['path'];
				}
			}

			throw new UploadStorageException('citomni/image could not publish all image outputs: ' . $e->getMessage(), 0, $e);
		} catch (\InvalidArgumentException $e) {
			throw new UploadConfigException($profile['label'] . ': citomni/image rejected an image output spec or the options. ' . $e->getMessage(), 0, $e);
		}

		foreach ($outputs as $output) {
			$written[] = $output['path'];
		}

		return $files;
	}


	/**
	 * Map an Image result entry to a variant entry of the Upload result.
	 *
	 * @param string $path Storage-relative variant path.
	 * @param array<string, mixed> $file Image result entry.
	 * @return array{path: string, mime: string, size: int, width: int, height: int} Variant entry.
	 */
	private static function variantEntry(string $path, array $file): array {
		return ['path' => $path, 'mime' => $file['mime'], 'size' => $file['bytes'], 'width' => $file['width'], 'height' => $file['height']];
	}


	// ----------------------------------------------------------------
	// Deletion and path derivation
	// ----------------------------------------------------------------

	/**
	 * Validate a storage-relative file path.
	 *
	 * @param string $path Candidate path.
	 * @return string The same path.
	 * @throws \InvalidArgumentException When the path is empty or breaks the storage path rules.
	 */
	private static function filePath(string $path): string {
		if ($path === '') {
			throw new \InvalidArgumentException('A file path must not be empty; "" would address the storage root.');
		}

		return StoragePath::validateRelative($path);
	}


	/**
	 * Validate storage-relative file paths, all before any is used.
	 *
	 * @param array<int|string, string> $paths Candidate paths.
	 * @return list<string> The same paths, in order.
	 * @throws \InvalidArgumentException When a path is empty or invalid.
	 */
	private static function filePaths(array $paths): array {
		$valid = [];

		foreach ($paths as $path) {
			$valid[] = self::filePath($path);
		}

		return $valid;
	}


	/**
	 * List the files a stored main path stands for: its variants, then the main file.
	 *
	 * @param string $path Validated storage-relative main path.
	 * @param array<string, mixed> $profile Normalized profile.
	 * @return list<string> Storage-relative paths in deletion order: variants in profile order, then main.
	 */
	private static function profileFiles(string $path, array $profile): array {
		$files = [];

		foreach ($profile['image']['variants'] ?? [] as $key => $variant) {
			$files[] = self::variantFile($path, $key, $variant['format']);
		}

		$files[] = $path;

		return $files;
	}


	/**
	 * Derive a variant path from the main path and the variant's format.
	 *
	 * The single derivation for both storing and deleting, so the paths a store
	 * writes are exactly the paths deletion and variantPath() derive.
	 *
	 * @param string $mainPath Storage-relative main path.
	 * @param int|string $key Variant key.
	 * @param ImageFormat $format Variant output format.
	 * @return string Storage-relative variant path.
	 */
	private static function variantFile(string $mainPath, int|string $key, ImageFormat $format): string {
		return StoragePath::variant($mainPath, (string)$key, MimeMap::extension($format->mime()));
	}


	/**
	 * Delete files strictly, in order.
	 *
	 * Behavior:
	 * - A missing file counts as deleted.
	 * - The first file that cannot be deleted (it still exists after the failed
	 *   unlink, or a link with its name does) stops the deletion.
	 *
	 * @param string $storage Storage name, for the message.
	 * @param string $root Verified storage root.
	 * @param list<string> $paths Validated storage-relative paths.
	 * @return void
	 * @throws UploadStorageException When a file cannot be deleted; later paths are not attempted.
	 */
	private function deleteFiles(string $storage, string $root, array $paths): void {
		foreach ($paths as $path) {
			$absolute = self::absolute($root, $path);
			\error_clear_last();

			if (@\unlink($absolute)) {
				continue;
			}

			$error = self::lastError();
			\clearstatcache(true, $absolute);

			if (\file_exists($absolute) || \is_link($absolute)) {
				throw new UploadStorageException("Failed to delete {$path} from storage '{$storage}' ({$absolute}){$error}.");
			}
		}
	}


	/**
	 * Remove files with cleanup semantics, attempting all of them.
	 *
	 * @param string $storage Storage name, for the log context.
	 * @param string $root Verified storage root.
	 * @param list<string> $paths Validated storage-relative paths.
	 * @return bool True when every file is gone.
	 */
	private function cleanupFiles(string $storage, string $root, array $paths): bool {
		$gone = true;

		foreach ($paths as $path) {
			$absolute = self::absolute($root, $path);
			$gone = $this->removeFile($absolute, 'Failed to remove a stored file during cleanup.', ['storage' => $storage, 'path' => $absolute, 'stored_path' => $path]) && $gone;
		}

		return $gone;
	}


	// ----------------------------------------------------------------
	// Cleanup (cleanup semantics: never throws)
	// ----------------------------------------------------------------

	/**
	 * Remove the files a call wrote before it failed.
	 *
	 * Every path is attempted, failures are logged, and nothing is thrown, so
	 * the exception in flight is never masked.
	 *
	 * @param array<int, string> $paths Absolute paths created by the call.
	 * @param string $storage Storage the files belong to, for the log context.
	 * @return void
	 */
	private function discard(array $paths, string $storage): void {
		foreach ($paths as $path) {
			$this->removeFile($path, 'Failed to remove a file written by an aborted store.', ['storage' => $storage, 'path' => $path]);
		}
	}


	/**
	 * Remove the files of results stored earlier in an aborted batch.
	 *
	 * Every file is attempted, failures are logged as in discard(), and nothing
	 * is thrown.
	 *
	 * @param list<array<string, mixed>> $results Results of successful stores in the same call.
	 * @param string $storage Storage of the batch's profile, for the log context.
	 * @param string $root Verified root of that storage.
	 * @return void
	 */
	private function discardResults(array $results, string $storage, string $root): void {
		$paths = [];

		foreach ($results as $result) {
			foreach ($result['variants'] as $variant) {
				$paths[] = self::absolute($root, $variant['path']);
			}

			$paths[] = self::absolute($root, $result['path']);
		}

		$this->discard($paths, $storage);
	}


	/**
	 * Remove one file with cleanup semantics.
	 *
	 * Behavior:
	 * - A missing file counts as removed (idempotent).
	 * - A failed unlink is logged with the context plus "error", and reported
	 *   as false.
	 * - Never throws.
	 *
	 * @param string $path File to remove.
	 * @param string $message Log message used on failure.
	 * @param array<string, mixed> $context Log context; "error" is added on failure.
	 * @return bool True when the file is gone.
	 */
	private function removeFile(string $path, string $message, array $context): bool {
		\error_clear_last();

		if (@\unlink($path)) {
			return true;
		}

		$error = \error_get_last()['message'] ?? 'unknown error';
		\clearstatcache(true, $path);

		if (!\file_exists($path) && !\is_link($path)) {
			return true;
		}

		$this->logCleanupFailure($message, $context + ['error' => $error]);

		return false;
	}


	/**
	 * Log a cleanup failure when the log service is registered.
	 *
	 * Cleanup often runs in a finally block while another exception is in
	 * flight, and the log service may be app-replaced with unknown exception
	 * types. The one write call is therefore guarded and a failing log is
	 * swallowed: logging must neither break cleanup nor mask the original
	 * exception. This is the package's only \Throwable catch.
	 *
	 * @param string $message Log message.
	 * @param array<string, mixed> $context Structured context (storage, path, error, ...).
	 * @return void
	 */
	private function logCleanupFailure(string $message, array $context): void {
		if (!$this->app->hasService('log')) {
			return;
		}

		try {
			$this->app->log->write('upload.jsonl', 'cleanup', $message, $context);
		} catch (\Throwable) {
			// Best effort by contract; see the method description.
		}
	}


	// ----------------------------------------------------------------
	// Helpers
	// ----------------------------------------------------------------

	/**
	 * Append a relative path to an absolute directory.
	 *
	 * @param string $directory Absolute directory, with or without a trailing separator.
	 * @param string $relative Relative path or file name; "" returns $directory.
	 * @return string Joined path.
	 */
	private static function absolute(string $directory, string $relative): string {
		if ($relative === '') {
			return $directory;
		}

		$last = \substr($directory, -1);

		return $directory . ($last === '/' || $last === '\\' ? '' : '/') . $relative;
	}


	/**
	 * Apply upload.file_mode to a file placed in storage.
	 *
	 * Applies to everything Upload places, including citomni/image's outputs, so
	 * every file of one upload ends up with the same mode whatever the umask,
	 * and public variants stay readable for the web server.
	 *
	 * @param string $path File in storage.
	 * @param int|null $fileMode Mode, or null to keep the mode the write created.
	 * @return void
	 * @throws UploadStorageException When chmod fails.
	 */
	private static function applyFileMode(string $path, ?int $fileMode): void {
		if ($fileMode === null) {
			return;
		}

		\error_clear_last();

		if (!@\chmod($path, $fileMode)) {
			throw new UploadStorageException('Failed to chmod ' . $path . ' to ' . \sprintf('%04o', $fileMode) . self::lastError() . '.');
		}
	}


	/**
	 * Describe the most recent PHP error for an exception message.
	 *
	 * Callers clear the error state right before the operation they report on.
	 * When a global error handler handles the error itself, PHP records nothing
	 * and the detail is omitted.
	 *
	 * @return string " (detail)" or "".
	 */
	private static function lastError(): string {
		$error = \error_get_last();

		return $error === null ? '' : ' (' . $error['message'] . ')';
	}


	/**
	 * Describe a cfg value for an error message.
	 *
	 * @param mixed $value Value to describe.
	 * @return string JSON literal for strings, the decimal value for ints, the type name otherwise.
	 */
	private static function describe(mixed $value): string {
		if (\is_string($value)) {
			return (string)\json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
		}

		return \is_int($value) ? (string)$value : \get_debug_type($value);
	}


	/**
	 * List array keys for an error message.
	 *
	 * @param array<int|string, mixed> $entries Entries whose keys to list.
	 * @return string Comma-separated, quoted keys.
	 */
	private static function keyList(array $entries): string {
		return \implode(', ', \array_map(static fn(int|string $key): string => self::describe((string)$key), \array_keys($entries)));
	}


}
