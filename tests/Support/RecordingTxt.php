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
 * Txt double with the signature of citomni/infrastructure's Txt::get().
 *
 * Behavior:
 * - Records every call as ['key', 'file', 'layer', 'default', 'vars'].
 * - Returns texts[$key] when it is set, otherwise $default, as Txt does for a
 *   missing key.
 */
final class RecordingTxt {

	/** @var list<array{key: string, file: string, layer: string, default: string, vars: array<string, mixed>}> */
	public array $calls = [];

	/** @var array<string, string> Texts by key; other keys resolve to the default. */
	public array $texts = [];


	/**
	 * Resolve a text.
	 *
	 * @param string $key Translation key.
	 * @param string $file Language file name without extension.
	 * @param string $layer "app" or "vendor/package".
	 * @param string $default Fallback text.
	 * @param array<string, mixed> $vars Placeholder values.
	 * @return string The configured text, or $default.
	 */
	public function get(string $key, string $file, string $layer = 'app', string $default = '', array $vars = []): string {
		$this->calls[] = ['key' => $key, 'file' => $file, 'layer' => $layer, 'default' => $default, 'vars' => $vars];

		return $this->texts[$key] ?? $default;
	}


}
