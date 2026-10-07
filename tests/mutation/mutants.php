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

/*
 * Mutant definitions for tests/mutation/run.php.
 *
 * Each mutant breaks one behavior the test suite is expected to catch. The
 * runner applies the edits of a mutant to a copy of the package, in order, and
 * every search string must occur exactly once at the point it is applied, so
 * a definition that no longer matches the code fails the run.
 *
 * Keys:
 * - id: stable identifier, "{area}-{nn}".
 * - label: the behavior the mutant breaks.
 * - file: package-relative file to mutate.
 * - edits: [search, replace] pairs, applied in order.
 * - tests: test scripts, without "_test.php", run in order until one fails.
 * - unprivileged: optional; true when only checks that need a refused unlink
 *   kill the mutant. Root never gets a refused unlink, so the runner skips
 *   such a mutant unless it runs as a non-root POSIX user.
 */

return [

	// -- Profiles and configuration ---------------------------------------------------

	[
		'id' => 'profile-01',
		'label' => 'unknown profile keys allowed',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\$unknown = \\array_diff_key(\$raw, self::PROFILE_KEYS);",
				"\$unknown = [];",
			],
		],
		'tests' => ['util', 'profile', 'storage'],
	],

	[
		'id' => 'profile-02',
		'label' => 'unknown image keys allowed',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\$unknown = \\array_diff_key(\$image, self::IMAGE_KEYS);",
				"\$unknown = [];",
			],
		],
		'tests' => ['util', 'profile', 'storage'],
	],

	[
		'id' => 'profile-03',
		'label' => 'directory error not translated',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\ttry {\n\t\t\tStoragePath::validateRelative(\$directory);\n\t\t} catch (\\InvalidArgumentException \$e) {\n\t\t\tthrow new UploadConfigException(\"{\$label}: invalid 'directory'. \" . \$e->getMessage(), 0, \$e);\n\t\t}\n",
				"\t\tStoragePath::validateRelative(\$directory);\n",
			],
		],
		'tests' => ['util', 'profile', 'storage'],
	],

	[
		'id' => 'profile-04',
		'label' => 'named profiles not memoized',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\tif (isset(\$this->profiles[\$profile])) {\n\t\t\treturn \$this->profiles[\$profile];\n\t\t}\n",
				"",
			],
		],
		'tests' => ['util', 'profile', 'storage'],
	],

	[
		'id' => 'profile-05',
		'label' => 'RFC 6838 check off',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"if (\\preg_match(self::MIME_TYPE, \$canonical) !== 1) {",
				"if (\$canonical === '') {",
			],
		],
		'tests' => ['util', 'profile', 'storage'],
	],

	[
		'id' => 'profile-06',
		'label' => 'variant key main allowed',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"if (\$key === 'main' || \\preg_match(self::VARIANT_KEY, \$key) !== 1) {",
				"if (\\preg_match(self::VARIANT_KEY, \$key) !== 1) {",
			],
		],
		'tests' => ['util', 'profile', 'storage'],
	],

	[
		'id' => 'profile-07',
		'label' => 'path/overwrite allowed in spec',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"if (\\array_key_exists('path', \$spec) || \\array_key_exists('overwrite', \$spec)) {",
				"if (false) {",
			],
		],
		'tests' => ['util', 'profile', 'storage'],
	],

	[
		'id' => 'profile-08',
		'label' => 'enum format rejected',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\tif (\$format instanceof ImageFormat) {\n\t\t\treturn ['spec' => \$spec, 'format' => \$format];\n\t\t}\n",
				"",
			],
		],
		'tests' => ['util', 'profile', 'storage'],
	],

	[
		'id' => 'profile-09',
		'label' => 'hash not lowercased',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\t\$hash = \\strtolower(\$hash);\n",
				"",
			],
		],
		'tests' => ['util', 'profile', 'storage'],
	],

	// -- Storing: validation, plain writes, compensation ------------------------------

	[
		'id' => 'store-01',
		'label' => 'temp registered after copy',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\$written[] = \$temp;\n\t\t\$slot = \\array_key_last(\$written);\n\n\t\t\\error_clear_last();\n\n\t\tif (\$uploaded ? !@\\move_uploaded_file(\$source, \$temp) : !@\\copy(\$source, \$temp)) {\n\t\t\tthrow new UploadStorageException((\$uploaded ? 'Failed to move the uploaded file ' : 'Failed to copy ') . \$source . ' to ' . \$temp . self::lastError() . '.');\n\t\t}\n",
				"\t\t\\error_clear_last();\n\n\t\tif (\$uploaded ? !@\\move_uploaded_file(\$source, \$temp) : !@\\copy(\$source, \$temp)) {\n\t\t\tthrow new UploadStorageException((\$uploaded ? 'Failed to move the uploaded file ' : 'Failed to copy ') . \$source . ' to ' . \$temp . self::lastError() . '.');\n\t\t}\n\n\t\t\$written[] = \$temp;\n\t\t\$slot = \\array_key_last(\$written);\n",
			],
		],
		'tests' => ['util', 'profile', 'storage'],
	],

	[
		'id' => 'store-02',
		'label' => 'final not tracked after rename',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\$written[\$slot] = \$final;\n",
				"",
			],
		],
		'tests' => ['util', 'profile', 'storage'],
		'unprivileged' => true,
	],

	[
		'id' => 'store-03',
		'label' => 'log without hasService check',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\tif (!\$this->app->hasService('log')) {\n\t\t\treturn;\n\t\t}\n",
				"",
			],
		],
		'tests' => ['util', 'profile', 'storage'],
		'unprivileged' => true,
	],

	[
		'id' => 'store-04',
		'label' => 'detected type not canonicalized',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\$mime = MimeMap::canonical(\$this->detectMime(\$source));",
				"\$mime = \$this->detectMime(\$source);",
			],
		],
		'tests' => ['util', 'profile', 'storage'],
	],

	[
		'id' => 'store-05',
		'label' => 'accept not enforced',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"if (\$profile['accept'] !== null && !isset(\$profile['accept'][\$mime])) {",
				"if (false) {",
			],
		],
		'tests' => ['util', 'profile', 'storage'],
	],

	[
		'id' => 'store-06',
		'label' => 'max_bytes off by one',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\$size > \$profile['max_bytes']",
				"\$size >= \$profile['max_bytes']",
			],
		],
		'tests' => ['util', 'profile', 'storage'],
	],

	[
		'id' => 'store-07',
		'label' => 'empty files accepted',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"if (\$size === 0) {",
				"if (false) {",
			],
		],
		'tests' => ['util', 'profile', 'storage'],
	],

	[
		'id' => 'store-08',
		'label' => 'wrong shard offset',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\$shards[] = \\substr(\$name, \$level * 2, 2);",
				"\$shards[] = \\substr(\$name, 0, 2);",
			],
		],
		'tests' => ['util', 'profile', 'storage'],
	],

	[
		'id' => 'store-09',
		'label' => 'chmod skipped for plain writes',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\$written[\$slot] = \$final;\n\t\tself::applyFileMode(\$final, \$fileMode);\n",
				"\t\t\$written[\$slot] = \$final;\n",
			],
		],
		'tests' => ['util', 'profile', 'storage'],
	],

	[
		'id' => 'store-10',
		'label' => 'dir_mode ignored',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"@\\mkdir(\$path, \$dirMode, true)",
				"@\\mkdir(\$path, 0777, true)",
			],
		],
		'tests' => ['util', 'profile', 'storage'],
	],

	[
		'id' => 'store-11',
		'label' => 'subdir not validated',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\tStoragePath::validateRelative(\$subdir);\n\n\t\t\\clearstatcache(true, \$sourcePath);",
				"\t\t\\clearstatcache(true, \$sourcePath);",
			],
		],
		'tests' => ['util', 'profile', 'storage'],
	],

	[
		'id' => 'store-12',
		'label' => 'storage roots not memoized',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\tif (isset(\$this->roots[\$storage])) {\n\t\t\treturn \$this->roots[\$storage];\n\t\t}\n",
				"",
			],
		],
		'tests' => ['delete'],
	],

	[
		'id' => 'store-13',
		'label' => 'modes checked after validation',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\$root = \$this->storageRoot(\$profile['storage']);\n\t\t[\$fileMode, \$dirMode] = \$this->modes();\n",
				"\t\t\$root = \$this->storageRoot(\$profile['storage']);\n\t\t\$modesLater = true;\n",
			],
			[
				"\t\t// -- 3. Route -----------------------------------------------------\n",
				"\t\t// -- 3. Route -----------------------------------------------------\n\t\t[\$fileMode, \$dirMode] = \$this->modes();\n",
			],
		],
		'tests' => ['util', 'profile', 'storage'],
	],

	// -- Upload intake and rejection messages -----------------------------------------

	[
		'id' => 'intake-01',
		'label' => 'INI_SIZE mapped as Partial',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\\UPLOAD_ERR_INI_SIZE, \\UPLOAD_ERR_FORM_SIZE => new UploadRejectedException(UploadRejection::TooLarge",
				"\\UPLOAD_ERR_FORM_SIZE => new UploadRejectedException(UploadRejection::TooLarge",
			],
		],
		'tests' => ['intake', 'http_upload', 'storage'],
	],

	[
		'id' => 'intake-02',
		'label' => 'upload_error missing from context',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"['original_name' => \$originalName, 'upload_error' => \$error]),\n\t\t\t\\UPLOAD_ERR_PARTIAL",
				"['original_name' => \$originalName]),\n\t\t\t\\UPLOAD_ERR_PARTIAL",
			],
		],
		'tests' => ['intake', 'http_upload', 'storage'],
	],

	[
		'id' => 'intake-03',
		'label' => 'no provenance check',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\tif (!\\is_uploaded_file(\$tmpName)) {",
				"\t\tif (false) {",
			],
		],
		'tests' => ['intake', 'http_upload', 'storage'],
	],

	[
		'id' => 'intake-04',
		'label' => 'uploads copied instead of moved',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"return \$this->ingest(\$tmpName, \$resolved, \$subdir, \$originalName, true);",
				"return \$this->ingest(\$tmpName, \$resolved, \$subdir, \$originalName, false);",
			],
		],
		'tests' => ['intake', 'http_upload', 'storage'],
	],

	[
		'id' => 'intake-05',
		'label' => 'storage checked after error mapping',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t// Storage configuration fails fast, before any user-side upload error is reported.\n\t\t\$this->storageRoot(\$resolved['storage']);\n\t\t\$this->modes();\n",
				"\t\t// Storage configuration fails fast, before any user-side upload error is reported.\n",
			],
		],
		'tests' => ['intake', 'http_upload', 'storage'],
	],

	[
		'id' => 'intake-06',
		'label' => 'file shape not validated',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"if (!\\is_string(\$name) || !\\is_string(\$tmpName) || !\\is_int(\$error)) {",
				"if (\$name === null) {",
			],
		],
		'tests' => ['intake', 'http_upload', 'storage'],
	],

	[
		'id' => 'intake-07',
		'label' => 'rejectionMessage ignores txt',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\tif (!\$this->app->hasService('txt')) {\n\t\t\treturn \$reason->fallbackMessage();\n\t\t}\n",
				"\t\treturn \$reason->fallbackMessage();\n",
			],
		],
		'tests' => ['intake', 'http_upload', 'storage'],
	],

	[
		'id' => 'intake-08',
		'label' => 'rejectionMessage wrong layer',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"'upload', 'citomni/upload', \$reason->fallbackMessage()",
				"'upload', 'app', \$reason->fallbackMessage()",
			],
		],
		'tests' => ['intake', 'http_upload', 'storage'],
	],

	[
		'id' => 'intake-09',
		'label' => 'NO_FILE slots kept',
		'file' => 'src/Util/UploadedFiles.php',
		'edits' => [
			[
				"return \$file['error'] === \\UPLOAD_ERR_NO_FILE ? null : \$file;",
				"return \$file;",
			],
		],
		'tests' => ['intake', 'http_upload', 'storage'],
	],

	[
		'id' => 'intake-10',
		'label' => 'leaf types unchecked',
		'file' => 'src/Util/UploadedFiles.php',
		'edits' => [
			[
				"if (\$key === 'error' || \$key === 'size' ? !\\is_int(\$value) : !\\is_string(\$value)) {",
				"if (false) {",
			],
		],
		'tests' => ['intake', 'http_upload', 'storage'],
	],

	[
		'id' => 'intake-11',
		'label' => 'many() wraps single entries',
		'file' => 'src/Util/UploadedFiles.php',
		'edits' => [
			[
				"\t\t\tif (!\\is_array(\$fields[\$key])) {\n\t\t\t\treturn \$files;\n\t\t\t}\n",
				"\t\t\tif (!\\is_array(\$fields[\$key])) {\n\t\t\t\t\$one = self::leaf(\$fields, null);\n\t\t\t\treturn \$one === null ? [] : [\$one];\n\t\t\t}\n",
			],
		],
		'tests' => ['intake', 'http_upload', 'storage'],
	],

	[
		'id' => 'intake-12',
		'label' => 'path ignored',
		'file' => 'src/Util/UploadedFiles.php',
		'edits' => [
			[
				"\t\t\tforeach (\$path as \$step) {",
				"\t\t\tforeach ([] as \$step) {",
			],
		],
		'tests' => ['intake', 'http_upload', 'storage'],
	],

	// -- Batches: storeUploads() and cleanupStored() ----------------------------------

	[
		'id' => 'batch-01',
		'label' => 'no compensation for earlier entries',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\t\t// A failure part-way: the files of earlier entries must not linger.\n\t\t\t\t\$this->discardResults(\$stored, \$resolved['storage'], \$root);\n",
				"\t\t\t\t// A failure part-way: the files of earlier entries must not linger.\n",
			],
		],
		'tests' => ['http_upload'],
	],

	[
		'id' => 'batch-02',
		'label' => 'every exception collected as a rejection',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\t\t} catch (UploadRejectedException \$e) {\n\t\t\t\t\t\$rejected[] = \$e;",
				"\t\t\t\t} catch (\\Throwable \$e) {\n\t\t\t\t\t\$rejected[] = \$e;",
			],
		],
		'tests' => ['intake', 'http_upload'],
	],

	[
		'id' => 'batch-03',
		'label' => 'batch stops at the first rejection',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\t\t\t\$rejected[] = \$e;\n",
				"\t\t\t\t\t\$rejected[] = \$e;\n\t\t\t\t\tbreak;\n",
			],
		],
		'tests' => ['intake', 'http_upload'],
	],

	[
		'id' => 'batch-04',
		'label' => 'empty batch returns before the upfront checks',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\$resolved = \$this->resolveProfile(\$profile);\n\t\tStoragePath::validateRelative(\$subdir);\n\n\t\tforeach (\$files as \$file) {",
				"\t\tif (\$files === []) {\n\t\t\treturn ['stored' => [], 'rejected' => []];\n\t\t}\n\n\t\t\$resolved = \$this->resolveProfile(\$profile);\n\t\tStoragePath::validateRelative(\$subdir);\n\n\t\tforeach (\$files as \$file) {",
			],
		],
		'tests' => ['intake'],
	],

	[
		'id' => 'batch-05',
		'label' => 'empty batch skips the storage preconditions',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\$root = \$this->storageRoot(\$resolved['storage']);\n\t\t\$this->modes();\n",
				"\t\t\$root = \$files === [] ? '' : \$this->storageRoot(\$resolved['storage']);\n\n\t\tif (\$files !== []) {\n\t\t\t\$this->modes();\n\t\t}\n",
			],
		],
		'tests' => ['intake'],
	],

	[
		'id' => 'batch-15',
		'label' => 'entries checked only when they are reached',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\tif (!\\is_array(\$file) || !\\is_string(\$file['name'] ?? null) || !\\is_string(\$file['tmp_name'] ?? null) || !\\is_int(\$file['error'] ?? null)) {",
				"\t\t\tif (false) {",
			],
		],
		'tests' => ['intake', 'http_upload'],
	],

	[
		'id' => 'batch-06',
		'label' => 'storeUploads() result may be ignored',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t#[\\NoDiscard]\n\tpublic function storeUploads(",
				"\tpublic function storeUploads(",
			],
		],
		'tests' => ['intake'],
	],

	[
		'id' => 'batch-07',
		'label' => 'batch compensation ignores variants',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\tforeach (\$result['variants'] as \$variant) {\n\t\t\t\t\$paths[] = self::absolute(\$root, \$variant['path']);\n\t\t\t}\n\n",
				"",
			],
		],
		'tests' => ['http_upload'],
	],

	[
		'id' => 'batch-16',
		'label' => 'batch compensation does not log refused removals',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\$this->discard(\$paths, \$storage);\n",
				"\t\tforeach (\$paths as \$path) {\n\t\t\t@\\unlink(\$path);\n\t\t}\n",
			],
		],
		'tests' => ['http_upload'],
		'unprivileged' => true,
	],

	[
		'id' => 'batch-08',
		'label' => 'cleanupStored() short-circuits after a failure',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\$gone = \$this->cleanupFiles(\$storage, \$this->storageRoot(\$storage), \$paths) && \$gone;",
				"\$gone = \$gone && \$this->cleanupFiles(\$storage, \$this->storageRoot(\$storage), \$paths);",
			],
		],
		'tests' => ['delete'],
	],

	[
		'id' => 'batch-09',
		'label' => 'cleanupStored() ignores variants',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\t\t\$paths[] = \$variant['path'];\n",
				"",
			],
		],
		'tests' => ['delete'],
	],

	[
		'id' => 'batch-10',
		'label' => 'cleanupStored() removes the main file first',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\t\$paths[] = \$path;\n\t\t\t\$jobs[] = [\$storage, self::filePaths(\$paths)];",
				"\t\t\t\\array_unshift(\$paths, \$path);\n\t\t\t\$jobs[] = [\$storage, self::filePaths(\$paths)];",
			],
		],
		'tests' => ['delete'],
	],

	[
		'id' => 'batch-11',
		'label' => 'cleanupStored() accepts a result without variants',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\$variants = \$result['variants'] ?? null;",
				"\$variants = \$result['variants'] ?? [];",
			],
			[
				" || !\\is_string(\$path) || !\\is_array(\$variants)) {",
				" || !\\is_string(\$path)) {",
			],
		],
		'tests' => ['delete'],
	],

	[
		'id' => 'batch-12',
		'label' => 'cleanupStored() verifies roots while removing',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t// Every root is verified before the first file of any result is removed.\n\t\tforeach (\$jobs as [\$storage]) {\n\t\t\t\$this->storageRoot(\$storage);\n\t\t}\n\n",
				"",
			],
		],
		'tests' => ['delete'],
	],

	[
		'id' => 'batch-13',
		'label' => 'cleanupStored() touches a root before validating every result',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\t\$jobs[] = [\$storage, self::filePaths(\$paths)];",
				"\t\t\t\$this->storageRoot(\$storage);\n\t\t\t\$jobs[] = [\$storage, self::filePaths(\$paths)];",
			],
		],
		'tests' => ['delete'],
	],

	[
		'id' => 'batch-14',
		'label' => 'cleanupStored() skips the root check',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\t\$this->storageRoot(\$storage);\n\t\t}",
				"\t\t\t\$this->configuredRoot(\$storage);\n\t\t}",
			],
			[
				"\$gone = \$this->cleanupFiles(\$storage, \$this->storageRoot(\$storage), \$paths) && \$gone;",
				"\$gone = \$this->cleanupFiles(\$storage, \$this->configuredRoot(\$storage), \$paths) && \$gone;",
			],
		],
		'tests' => ['delete'],
	],

	// -- Image path -------------------------------------------------------------------

	[
		'id' => 'image-01',
		'label' => 'no MIME agreement check',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\tif (\$info['mime'] !== \$mime) {",
				"\t\tif (false) {",
			],
		],
		'tests' => ['image_path', 'profile', 'image_integration'],
	],

	[
		'id' => 'image-02',
		'label' => 'all outputs removed on ImageWriteException',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\tforeach (\\array_keys(\$e->committed()) as \$key) {",
				"\t\t\tforeach (\\array_keys(\$outputs) as \$key) {",
			],
		],
		'tests' => ['image_path', 'profile', 'image_integration'],
	],

	[
		'id' => 'image-03',
		'label' => 'committed outputs not removed',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\t\t\t\$written[] = \$outputs[\$key]['path'];\n",
				"\t\t\t\t\t// skipped\n",
			],
		],
		'tests' => ['image_path', 'profile', 'image_integration'],
	],

	[
		'id' => 'image-04',
		'label' => 'save() ImageInputException untranslated',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\t\$files = \$this->app->image->save(\$source, \$outputs, \$profile['image']['options']);\n\t\t} catch (ImageInputException \$e) {\n\t\t\tthrow new UploadRejectedException(UploadRejection::InvalidImage, \$context, \$e);\n",
				"\t\t\t\$files = \$this->app->image->save(\$source, \$outputs, \$profile['image']['options']);\n\t\t} catch (\\DomainException \$e) {\n\t\t\tthrow new UploadRejectedException(UploadRejection::InvalidImage, \$context, \$e);\n",
			],
		],
		'tests' => ['image_path', 'profile', 'image_integration'],
	],

	[
		'id' => 'image-05',
		'label' => 'InvalidArgumentException untranslated',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t} catch (\\InvalidArgumentException \$e) {\n\t\t\tthrow new UploadConfigException(\$profile['label']",
				"\t\t} catch (\\DomainException \$e) {\n\t\t\tthrow new UploadConfigException(\$profile['label']",
			],
		],
		'tests' => ['image_path', 'profile', 'image_integration'],
	],

	[
		'id' => 'image-06',
		'label' => 'RuntimeException translated',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t} catch (ImageWriteException \$e) {",
				"\t\t} catch (\\RuntimeException \$e) {\n\t\t\tthrow new UploadStorageException('x', 0, \$e);\n\t\t} catch (ImageWriteException \$e) {",
			],
		],
		'tests' => ['image_path', 'profile', 'image_integration'],
	],

	[
		'id' => 'image-07',
		'label' => 'original written before the image job',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\t\$files = [];\n\n\t\t\t// The image job runs before",
				"\t\t\t\$files = [];\n\t\t\tif (!\$produced) { \$this->writeFile(\$source, \$absoluteDirectory, '.early', \$fileMode, false, \$written); }\n\n\t\t\t// The image job runs before",
			],
		],
		'tests' => ['image_path', 'profile', 'image_integration'],
	],

	[
		'id' => 'image-08',
		'label' => 'file_mode skipped for image outputs',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\t\t\tself::applyFileMode(\$output['path'], \$fileMode);\n",
				"",
			],
		],
		'tests' => ['image_path', 'profile', 'image_integration'],
	],

	[
		'id' => 'image-09',
		'label' => 'no hasService(\'image\') check',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\tif (!\$this->app->hasService('image')) {",
				"\t\tif (false) {",
			],
		],
		'tests' => ['image_path', 'profile', 'image_integration'],
	],

	[
		'id' => 'image-10',
		'label' => 'options type unchecked',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\tif (!\\is_array(\$options)) {",
				"\t\tif (false) {",
			],
		],
		'tests' => ['image_path', 'profile', 'image_integration'],
	],

	[
		'id' => 'image-11',
		'label' => 'kept original reports stored width',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"(\$info['display_width'] ?? null)",
				"(\$info['width'] ?? null)",
			],
		],
		'tests' => ['image_path', 'profile', 'image_integration'],
	],

	[
		'id' => 'image-12',
		'label' => 'produced main reports source size',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"'size' => \$produced ? \$files['main']['bytes'] : \$size,",
				"'size' => \$size,",
			],
		],
		'tests' => ['image_path', 'profile', 'image_integration'],
	],

	[
		'id' => 'image-13',
		'label' => 'overwrite set to false',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\$outputs['main'] = \$image['main']['spec'] + ['path' => self::absolute(\$root, \$path)];",
				"\$outputs['main'] = \$image['main']['spec'] + ['path' => self::absolute(\$root, \$path), 'overwrite' => false];",
			],
		],
		'tests' => ['image_path', 'profile', 'image_integration'],
	],

	[
		'id' => 'image-14',
		'label' => 'options not passed',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\$this->app->image->save(\$source, \$outputs, \$profile['image']['options']);",
				"\$this->app->image->save(\$source, \$outputs, []);",
			],
		],
		'tests' => ['image_path', 'profile', 'image_integration'],
	],

	[
		'id' => 'image-15',
		'label' => 'variant extension from source type',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"MimeMap::extension(\$format->mime())",
				"MimeMap::extension('image/jpeg')",
			],
		],
		'tests' => ['image_path', 'delete', 'read_path'],
	],

	// -- Deletion and cleanup ---------------------------------------------------------

	[
		'id' => 'delete-01',
		'label' => 'strict delete never throws',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\t\tthrow new UploadStorageException(\"Failed to delete {\$path} from storage '{\$storage}' ({\$absolute}){\$error}.\");",
				"\t\t\t\tcontinue;",
			],
		],
		'tests' => ['delete'],
	],

	[
		'id' => 'delete-02',
		'label' => 'strict delete stops after the first success',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\tif (@\\unlink(\$absolute)) {\n\t\t\t\tcontinue;",
				"\t\t\tif (@\\unlink(\$absolute)) {\n\t\t\t\treturn;",
			],
		],
		'tests' => ['delete'],
	],

	[
		'id' => 'delete-03',
		'label' => 'cleanup short-circuits after a failure',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\$gone = \$this->removeFile(\$absolute, 'Failed to remove a stored file during cleanup.', ['storage' => \$storage, 'path' => \$absolute, 'stored_path' => \$path]) && \$gone;",
				"\$gone = \$gone && \$this->removeFile(\$absolute, 'Failed to remove a stored file during cleanup.', ['storage' => \$storage, 'path' => \$absolute, 'stored_path' => \$path]);",
			],
		],
		'tests' => ['delete'],
	],

	[
		'id' => 'delete-04',
		'label' => 'main deleted before variants',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\$files[] = \$path;\n\n\t\treturn \$files;",
				"\t\treturn [\$path, ...\$files];",
			],
		],
		'tests' => ['delete'],
	],

	[
		'id' => 'delete-05',
		'label' => 'empty path accepted',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\tif (\$path === '') {\n\t\t\tthrow new \\InvalidArgumentException('A file path",
				"\t\tif (false) {\n\t\t\tthrow new \\InvalidArgumentException('A file path",
			],
		],
		'tests' => ['delete', 'read_path'],
	],

	[
		'id' => 'delete-06',
		'label' => 'delete() touches the root before validating',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\$paths = self::filePaths(\$paths);\n\t\t\$this->deleteFiles(\$storage, \$this->storageRoot(\$storage), \$paths);",
				"\t\t\$root = \$this->storageRoot(\$storage);\n\t\t\$this->deleteFiles(\$storage, \$root, self::filePaths(\$paths));",
			],
		],
		'tests' => ['delete'],
	],

	[
		'id' => 'delete-07',
		'label' => 'cleanup() touches the root before validating',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\$paths = self::filePaths(\$paths);\n\n\t\treturn \$this->cleanupFiles(\$storage, \$this->storageRoot(\$storage), \$paths);",
				"\t\t\$root = \$this->storageRoot(\$storage);\n\n\t\treturn \$this->cleanupFiles(\$storage, \$root, self::filePaths(\$paths));",
			],
		],
		'tests' => ['delete'],
	],

	[
		'id' => 'delete-08',
		'label' => 'deleteWithVariants() skips the root check',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\$this->deleteFiles(\$resolved['storage'], \$this->storageRoot(\$resolved['storage']), self::profileFiles(\$path, \$resolved));",
				"\$this->deleteFiles(\$resolved['storage'], \$this->configuredRoot(\$resolved['storage']), self::profileFiles(\$path, \$resolved));",
			],
		],
		'tests' => ['delete'],
	],

	[
		'id' => 'delete-09',
		'label' => 'cleanup log without stored_path',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"['storage' => \$storage, 'path' => \$absolute, 'stored_path' => \$path]",
				"['storage' => \$storage, 'path' => \$absolute]",
			],
		],
		'tests' => ['delete'],
	],

	[
		'id' => 'delete-10',
		'label' => 'cleanup reports a surviving link as gone',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"if (!\\file_exists(\$path) && !\\is_link(\$path)) {",
				"if (!\\file_exists(\$path)) {",
			],
		],
		'tests' => ['delete'],
		'unprivileged' => true,
	],

	[
		'id' => 'delete-11',
		'label' => 'strict delete accepts a surviving link',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"if (\\file_exists(\$absolute) || \\is_link(\$absolute)) {",
				"if (\\file_exists(\$absolute)) {",
			],
		],
		'tests' => ['delete'],
		'unprivileged' => true,
	],

	// -- Path methods -----------------------------------------------------------------

	[
		'id' => 'path-01',
		'label' => 'absolutePath() checks the root on disk',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"return self::absolute(\$this->configuredRoot(\$storage), \$path);",
				"return self::absolute(\$this->storageRoot(\$storage), \$path);",
			],
		],
		'tests' => ['read_path'],
	],

	[
		'id' => 'path-02',
		'label' => 'web_path not validated',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\ttry {\n\t\t\tStoragePath::validateRelative(\$webPath);",
				"\t\ttry {\n\t\t\tStoragePath::validateRelative('x');",
			],
		],
		'tests' => ['read_path'],
	],

	[
		'id' => 'path-03',
		'label' => 'web_path null not rejected as such',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\tif (\$webPath === null) {",
				"\t\tif (false) {",
			],
		],
		'tests' => ['read_path'],
	],

	[
		'id' => 'path-04',
		'label' => 'webPath() with a leading slash',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"return \$base === '' ? \$path : \$base . '/' . \$path;",
				"return \$base === '' ? \$path : '/' . \$base . '/' . \$path;",
			],
		],
		'tests' => ['read_path'],
	],

	[
		'id' => 'path-05',
		'label' => 'unknown variant not rejected',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\tif (\$entry === null) {",
				"\t\tif (false) {",
			],
		],
		'tests' => ['read_path'],
	],

	[
		'id' => 'path-06',
		'label' => 'variant extension from the format value',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"return StoragePath::variant(\$mainPath, (string)\$key, MimeMap::extension(\$format->mime()));",
				"return StoragePath::variant(\$mainPath, (string)\$key, \$format->value);",
			],
		],
		'tests' => ['delete', 'read_path'],
	],

	[
		'id' => 'path-07',
		'label' => 'store derives variants on its own',
		'file' => 'src/Service/Upload.php',
		'edits' => [
			[
				"\t\t\t\$targets[\$key] = self::variantFile(\$path, \$key, \$variant['format']);",
				"\t\t\t\$targets[\$key] = StoragePath::variant(\$path, (string)\$key, \$variant['format']->value);",
			],
		],
		'tests' => ['delete', 'read_path'],
	],

	[
		'id' => 'path-08',
		'label' => 'image extensions not derived from ImageFormat',
		'file' => 'src/Util/MimeMap.php',
		'edits' => [
			[
				"\t\tif (\$format !== null) {\n\t\t\treturn self::FORMAT_EXTENSIONS[\$format->value] ?? \$format->value;",
				"\t\tif (false) {\n\t\t\treturn self::FORMAT_EXTENSIONS[\$format->value] ?? \$format->value;",
			],
		],
		'tests' => ['util', 'read_path'],
	],

];
