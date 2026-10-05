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

/**
 * Thrown when upload configuration or a profile is invalid.
 *
 * Behavior:
 * - Signals a problem the developer or operator must fix in cfg:
 *   1) An unknown profile or storage, or a profile that breaks the profile
 *      rules (including an invalid "directory"; the path error is previous)
 *   2) A storage root that does not exist or is not configured as a string
 *   3) An invalid upload.file_mode or upload.dir_mode
 *   4) An image block while the image service (citomni/image) is not
 *      registered
 *   5) Output specs or job options that citomni/image rejects; its
 *      \InvalidArgumentException is previous
 *   6) webPath() for a storage whose web_path is null or invalid
 *
 * Notes:
 * - Messages name the profile ("Upload profile 'avatar'" or "Inline upload
 *   profile") or the cfg key, so the faulty definition can be found.
 * - Never caused by untrusted input. Adapters should let it bubble.
 */
final class UploadConfigException extends UploadException {}
