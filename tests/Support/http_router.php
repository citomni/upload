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
 * UploadedFiles probes and storeUpload() calls and answers with a JSON report:
 * - files: the raw $_FILES array
 * - probes: one result per ['one' | 'many', field, path] probe
 * - calls: per storeUpload() call, the result or the exception (class, message,
 *   reason, context), and whether the uploaded file still exists afterwards
 * - log: cleanup log entries; touched: service ids that Upload resolved
 *
 * A call's entry is 'one' (UploadedFiles::one() of the field), 'raw' (the raw
 * $_FILES entry), or an explicit array. spec.image picks the image service:
 * 'scripted' (ScriptedImage), 'real' (citomni/image), or the default double
 * that throws. Test-only: the server is bound to 127.0.0.1 and lives as long
 * as one test script.
 */

require __DIR__ . '/../bootstrap.php';

use CitOmni\Image\Boot\Registry as ImageRegistry;
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
	$report = ['files' => $_FILES, 'probes' => [], 'calls' => [], 'log' => [], 'touched' => []];

	foreach ($spec['probes'] ?? [] as [$operation, $field, $path]) {
		$report['probes'][] = $operation === 'one'
			? UploadedFiles::one($_FILES[$field] ?? null, ...$path)
			: UploadedFiles::many($_FILES[$field] ?? null, ...$path);
	}

	if (isset($spec['calls'])) {
		$log = new RecordingLog();
		$services = ['log' => $log] + match ($spec['image'] ?? null) {
			'scripted' => ['image' => new ScriptedImage()],
			'real' => ['image' => static fn(TestApp $app): Image => new Image($app)],
			default => [],
		};
		$app = testApp(testCfg(ImageRegistry::CFG_COMMON, ['upload' => ['storages' => ['files' => ['root' => $spec['root'], 'web_path' => null]]] + ($spec['upload'] ?? [])]), $services);
		$upload = new Upload($app);

		foreach ($spec['calls'] as $call) {
			$entry = match (true) {
				\is_array($call['entry']) => $call['entry'],
				$call['entry'] === 'raw' => $_FILES[$call['field']] ?? null,
				default => UploadedFiles::one($_FILES[$call['field']] ?? null, ...($call['path'] ?? [])),
			};

			$outcome = [];

			try {
				$outcome['result'] = $upload->storeUpload($entry, $call['profile'], $call['subdir'] ?? '');
			} catch (\Throwable $e) {
				$outcome['error'] = [
					'class' => $e::class,
					'message' => $e->getMessage(),
					'reason' => $e instanceof UploadRejectedException ? $e->reason->value : null,
					'context' => $e instanceof UploadRejectedException ? $e->context : null,
				];
			}

			$tmpName = \is_array($entry) ? ($entry['tmp_name'] ?? null) : null;
			$outcome['tmp_exists_after'] = \is_string($tmpName) && $tmpName !== '' ? \file_exists($tmpName) : null;
			$report['calls'][] = $outcome;
		}

		$report['log'] = $log->entries;
		$report['touched'] = $app->touched;
	}

	echo \json_encode($report, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);
} catch (\Throwable $e) {
	\http_response_code(500);
	echo \json_encode(['router_error' => $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine()], \JSON_INVALID_UTF8_SUBSTITUTE);
}
