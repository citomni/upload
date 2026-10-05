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

namespace CitOmni\Upload\Enum;

/**
 * Reasons why citomni/upload rejects untrusted input.
 *
 * Case values are stable i18n keys in language/{da,en}/upload.php, passed to
 * Txt::get(). Do not rename them.
 */
enum UploadRejection: string {

	/** UPLOAD_ERR_NO_FILE passed to storeUpload(). */
	case NoFile = 'no_file';

	/** UPLOAD_ERR_PARTIAL. */
	case Partial = 'partial';

	/** UPLOAD_ERR_INI_SIZE/FORM_SIZE, or size above the profile's max_bytes. */
	case TooLarge = 'too_large';

	/** Zero-byte file. */
	case Empty = 'empty';

	/** Canonical MIME not accepted, or finfo/Image MIME disagreement. */
	case TypeNotAllowed = 'type_not_allowed';

	/** Image content rejected by citomni/image (ImageInputException). */
	case InvalidImage = 'invalid_image';


	/**
	 * Return the English fallback message for this reason.
	 *
	 * @return string End-user text, used when no translation is available.
	 */
	public function fallbackMessage(): string {
		return match ($this) {
			self::NoFile => 'No file was uploaded.',
			self::Partial => 'The file was only partially uploaded. Please try again.',
			self::TooLarge => 'The file is too large.',
			self::Empty => 'The file is empty.',
			self::TypeNotAllowed => 'This file type is not allowed.',
			self::InvalidImage => 'The image could not be processed. It may be damaged, too large, animated, or in an unsupported format.',
		};
	}


}
