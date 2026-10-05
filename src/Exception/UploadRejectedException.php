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

namespace CitOmni\Upload\Exception;

use CitOmni\Upload\Enum\UploadRejection;

/**
 * Thrown when untrusted upload input is rejected.
 *
 * Behavior:
 * - reason identifies the rejection. Its value is a stable i18n key; the
 *   enum's fallbackMessage() provides the English text.
 * - context carries diagnostic details, depending on the reason:
 *   original_name, size, max_bytes, mime, image_mime, and upload_error (the
 *   UPLOAD_ERR_* code when PHP reported the problem).
 * - The exception message ("Upload rejected: {reason}") is for logs, not for
 *   end users.
 * - No file written by the rejected call remains in storage.
 *
 * Notes:
 * - The only Upload exception that adapters are expected to catch and map to
 *   a validation response. Every other Upload exception is a server-side fault.
 * - A rejection caused by citomni/image keeps the original image exception as
 *   previous, so the detail survives in logs.
 */
final class UploadRejectedException extends UploadException {

	/**
	 * Create a rejection.
	 *
	 * @param UploadRejection $reason Why the input was rejected.
	 * @param array<string, mixed> $context Diagnostic details (original_name, size, max_bytes, mime, ...).
	 * @param \Throwable|null $previous Underlying failure, if any.
	 */
	public function __construct(
		public readonly UploadRejection $reason,
		public readonly array $context = [],
		?\Throwable $previous = null,
	) {
		parent::__construct('Upload rejected: ' . $reason->value, 0, $previous);
	}


}
