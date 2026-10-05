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

namespace CitOmni\Upload\Util;

/**
 * OriginalName: Sanitize a client-supplied file name for use as metadata.
 *
 * The original name is metadata only (display, Content-Disposition). It never
 * becomes part of a storage path.
 *
 * Behavior:
 * - "\" is normalized to "/", and only the part after the last "/" is kept,
 *   so "C:\fakepath\x.jpg" becomes "x.jpg". basename() is not used because it
 *   is locale-dependent for multibyte input.
 * - Invalid UTF-8 is scrubbed with mb_scrub(). Each invalid sequence becomes
 *   the mbstring substitute character ("?" by default).
 * - C0 and C1 control characters, DEL, and the bidi controls U+202A-U+202E
 *   and U+2066-U+2069 are removed.
 * - Whitespace, including Unicode whitespace, is trimmed at both ends
 *   (mb_trim()).
 * - The result is limited to 255 characters (code points, mb_substr()).
 * - An empty result becomes "file".
 *
 * Notes:
 * - Pure function: no App, no IO, no state.
 * - Output escaping is the template's responsibility.
 *
 * Typical usage:
 *   $name = OriginalName::sanitize('C:\fakepath\Faktura 2026.pdf'); // 'Faktura 2026.pdf'
 */
final class OriginalName {

	private const int MAX_LENGTH = 255;

	// C0 controls, DEL, C1 controls, and bidi embeddings/overrides/isolates.
	private const string CONTROLS = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';


	/**
	 * Sanitize a client file name.
	 *
	 * @param string|null $name Client-supplied name or path; null when unknown.
	 * @return string Non-empty name of at most 255 characters.
	 * @throws \RuntimeException When PCRE fails on scrubbed input (not expected in practice).
	 */
	public static function sanitize(?string $name): string {
		$name = \str_replace('\\', '/', $name ?? '');
		$slash = \strrpos($name, '/');

		if ($slash !== false) {
			$name = \substr($name, $slash + 1);
		}

		$name = \mb_scrub($name, 'UTF-8');
		$name = \preg_replace(self::CONTROLS, '', $name) ?? throw new \RuntimeException('Failed to strip control characters: ' . \preg_last_error_msg());
		$name = \mb_trim($name, null, 'UTF-8');
		$name = \mb_substr($name, 0, self::MAX_LENGTH, 'UTF-8');

		return $name === '' ? 'file' : $name;
	}


}
