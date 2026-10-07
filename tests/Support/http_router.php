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
 * Request router for tests/http_upload_test.php, run by PHP's built-in web server.
 *
 * "GET /?ping=1" answers {"pong":true}. A POST carries a JSON "spec" field and
 * file parts. Inside the request, where is_uploaded_file() and
 * move_uploaded_file() accept the uploaded files, the router runs the requested
 * UploadedFiles probes, checkUpload() checks, storeUpload() calls, and
 * storeUploads() batches, in that order, and answers with a JSON report:
 * - files: the raw $_FILES array
 * - probes: one result per ['one' | 'many', field, path] probe
 * - checks: per checkUpload() call, the result or the exception (class,
 *   message, reason, context), whether the uploaded file still exists
 *   afterwards, and the SHA-256 of its bytes then
 * - calls: per storeUpload() call, the result or the exception (class, message,
 *   reason, context), and whether the uploaded file still exists afterwards
 * - batches: per storeUploads() call, the result keys, "stored", "rejected"
 *   (class, reason, context), the result of cleanupStored() when the batch asks
 *   for "discard", or the exception, and per entry whether its uploaded file
 *   still exists afterwards (null for an entry without a string tmp_name)
 * - log: cleanup log entries; touched: service ids that Upload resolved
 *
 * The entry of a check or a call is 'one' (UploadedFiles::one() of the field),
 * 'raw' (the raw $_FILES entry), or an explicit array. A batch's entries are
 * UploadedFiles::many() of its field and path, with the explicit entries of
 * "insert" spliced in at the given positions. spec.image picks the image
 * service: 'scripted' (ScriptedImage), 'real' (citomni/image), or the default
 * double that throws. With spec.fail_save = n, the scripted save() throws
 * ImageCapabilityException on its n-th call, before it writes anything; with
 * spec.lock_on_fail, it first makes its target directory read-only (0555), so
 * the batch's compensation cannot remove what the batch stored there.
 * Test-only: the server is bound to 127.0.0.1 and lives as long as one test
 * script.
 */

require __DIR__ . '/../bootstrap.php';

use CitOmni\Image\Boot\Registry as ImageRegistry;
use CitOmni\Image\Exception\ImageCapabilityException;
use CitOmni\Image\Service\Image;
use CitOmni\Upload\Exception\UploadRejectedException;
use CitOmni\Upload\Service\Upload;
use CitOmni\Upload\Tests\Support\RecordingLog;
use CitOmni\Upload\Tests\Support\ScriptedImage;
use CitOmni\Upload\Tests\Support\TestApp;
use CitOmni\Upload\Util\UploadedFiles;

\header('Content-Type: application/json');

try {
	if (isset($_GET['ping'])) {
		echo '{"pong":true}';

		return;
	}

	$spec = \json_decode((string)($_POST['spec'] ?? ''), true, 64, \JSON_THROW_ON_ERROR);
	$report = ['files' => $_FILES, 'probes' => [], 'checks' => [], 'calls' => [], 'batches' => [], 'log' => [], 'touched' => []];

	foreach ($spec['probes'] ?? [] as [$operation, $field, $path]) {
		$report['probes'][] = $operation === 'one'
			? UploadedFiles::one($_FILES[$field] ?? null, ...$path)
			: UploadedFiles::many($_FILES[$field] ?? null, ...$path);
	}

	/** The entry a check or a call names: 'one', 'raw', or an explicit array. */
	$entryOf = static fn(array $item): mixed => match (true) {
		\is_array($item['entry']) => $item['entry'],
		$item['entry'] === 'raw' => $_FILES[$item['field']] ?? null,
		default => UploadedFiles::one($_FILES[$item['field']] ?? null, ...($item['path'] ?? [])),
	};

	/** Describe an exception for the report. */
	$errorOf = static fn(\Throwable $e): array => [
		'class' => $e::class,
		'message' => $e->getMessage(),
		'reason' => $e instanceof UploadRejectedException ? $e->reason->value : null,
		'context' => $e instanceof UploadRejectedException ? $e->context : null,
	];

	if (isset($spec['checks']) || isset($spec['calls']) || isset($spec['batches'])) {
		$log = new RecordingLog();
		$scripted = new ScriptedImage();

		if (isset($spec['fail_save'])) {
			$failAt = $spec['fail_save'];
			$lock = (bool)($spec['lock_on_fail'] ?? false);
			$saves = 0;
			$scripted->onSave = static function (string $source, array $outputs) use ($failAt, $lock, &$saves): array {
				if (++$saves === $failAt) {
					if ($lock) {
						\chmod(\dirname(\reset($outputs)['path']), 0555);
					}

					throw new ImageCapabilityException('Scripted: no encoder for this job.');
				}

				return ScriptedImage::write($outputs);
			};
		}

		$services = ['log' => $log] + match ($spec['image'] ?? null) {
			'scripted' => ['image' => $scripted],
			'real' => ['image' => static fn(TestApp $app): Image => new Image($app)],
			default => [],
		};
		$app = testApp(testCfg(ImageRegistry::CFG_COMMON, ['upload' => ['storages' => ['files' => ['root' => $spec['root'], 'web_path' => null]]] + ($spec['upload'] ?? [])]), $services);
		$upload = new Upload($app);

		foreach ($spec['checks'] ?? [] as $item) {
			$entry = $entryOf($item);
			$outcome = [];

			try {
				$outcome['result'] = $upload->checkUpload($entry, $item['profile']);
			} catch (\Throwable $e) {
				$outcome['error'] = $errorOf($e);
			}

			$tmpName = \is_array($entry) ? ($entry['tmp_name'] ?? null) : null;
			$exists = \is_string($tmpName) && $tmpName !== '' ? \file_exists($tmpName) : null;
			$outcome['tmp_exists_after'] = $exists;
			$outcome['tmp_sha256_after'] = $exists === true ? \hash_file('sha256', $tmpName) : null;
			$report['checks'][] = $outcome;
		}

		foreach ($spec['calls'] ?? [] as $call) {
			$entry = $entryOf($call);
			$outcome = [];

			try {
				$outcome['result'] = $upload->storeUpload($entry, $call['profile'], $call['subdir'] ?? '');
			} catch (\Throwable $e) {
				$outcome['error'] = $errorOf($e);
			}

			$tmpName = \is_array($entry) ? ($entry['tmp_name'] ?? null) : null;
			$outcome['tmp_exists_after'] = \is_string($tmpName) && $tmpName !== '' ? \file_exists($tmpName) : null;
			$report['calls'][] = $outcome;
		}

		foreach ($spec['batches'] ?? [] as $batch) {
			$entries = UploadedFiles::many($_FILES[$batch['field']] ?? null, ...($batch['path'] ?? []));

			foreach ($batch['insert'] ?? [] as [$position, $entry]) {
				\array_splice($entries, $position, 0, [$entry]);
			}

			$outcome = [];

			try {
				$result = $upload->storeUploads($entries, $batch['profile'], $batch['subdir'] ?? '');
				$outcome['keys'] = \array_keys($result);
				$outcome['stored'] = $result['stored'];
				$outcome['rejected'] = \array_map(static fn(UploadRejectedException $e): array => ['class' => $e::class, 'reason' => $e->reason->value, 'context' => $e->context], $result['rejected']);

				if ($batch['discard'] ?? false) {
					$outcome['discarded'] = $upload->cleanupStored(...$result['stored']);
				}
			} catch (\Throwable $e) {
				$outcome['error'] = ['class' => $e::class, 'message' => $e->getMessage()];
			}

			$outcome['tmp_exists_after'] = \array_map(static fn(mixed $entry): ?bool => \is_string($entry['tmp_name'] ?? null) ? $entry['tmp_name'] !== '' && \file_exists($entry['tmp_name']) : null, $entries);
			$report['batches'][] = $outcome;
		}

		$report['log'] = $log->entries;
		$report['touched'] = $app->touched;
	}

	echo \json_encode($report, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);
} catch (\Throwable $e) {
	\http_response_code(500);
	echo \json_encode(['router_error' => $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine()], \JSON_INVALID_UTF8_SUBSTITUTE);
}
