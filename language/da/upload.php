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

// Danish rejection messages for citomni/upload, read by Txt with the layer
// "citomni/upload" and the file "upload". Keys are UploadRejection values.
return [
	'no_file'          => 'Der blev ikke uploadet nogen fil.',
	'partial'          => 'Filen blev kun delvist uploadet. Prøv igen.',
	'too_large'        => 'Filen er for stor.',
	'empty'            => 'Filen er tom.',
	'type_not_allowed' => 'Denne filtype er ikke tilladt.',
	'invalid_image'    => 'Billedet kunne ikke behandles. Det kan være beskadiget, for stort, animeret eller i et format, der ikke understøttes.',
];
