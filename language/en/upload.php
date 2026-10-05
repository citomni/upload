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

// English rejection messages for citomni/upload, read by Txt with the layer
// "citomni/upload" and the file "upload". Keys are UploadRejection values. The
// texts must equal UploadRejection::fallbackMessage(), which is their source.
return [
	'no_file'          => 'No file was uploaded.',
	'partial'          => 'The file was only partially uploaded. Please try again.',
	'too_large'        => 'The file is too large.',
	'empty'            => 'The file is empty.',
	'type_not_allowed' => 'This file type is not allowed.',
	'invalid_image'    => 'The image could not be processed. It may be damaged, too large, animated, or in an unsupported format.',
];
