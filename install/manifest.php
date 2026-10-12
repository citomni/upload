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

return [
	'package' => 'citomni/upload',
	'version' => 1,
	'files' => [],
	'gitignore' => [
		'/public/uploads/u/*',
		'/var/storage/*',
		'!/public/uploads/u/.gitkeep',
		'!/var/storage/.gitkeep',
	],
];
