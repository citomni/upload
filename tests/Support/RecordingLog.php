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

namespace CitOmni\Upload\Tests\Support;

/**
 * Log double with the signature of citomni/infrastructure's Log::write().
 *
 * Behavior:
 * - Records every write as ['file', 'category', 'message', 'context'].
 * - When failWith is set, throws it after recording, to prove that a failing
 *   log neither breaks cleanup nor masks an exception in flight.
 */
final class RecordingLog {

	/** @var list<array{file: string|null, category: string, message: string|array<mixed>|object, context: array<string, mixed>}> */
	public array $entries = [];

	/** Exception thrown by write() after recording, or null. */
	public ?\Throwable $failWith = null;


	/**
	 * Record a log entry.
	 *
	 * @param string|null $file Target log file name.
	 * @param string $category Category.
	 * @param string|array<mixed>|object $message Message payload.
	 * @param array<string, mixed> $context Structured context.
	 * @return void
	 * @throws \Throwable The configured failWith exception.
	 */
	public function write(?string $file, string $category, string|array|object $message, array $context = []): void {
		$this->entries[] = ['file' => $file, 'category' => $category, 'message' => $message, 'context' => $context];

		if ($this->failWith !== null) {
			throw $this->failWith;
		}
	}


}
