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
 * Base class for the exceptions citomni/upload throws about its own work.
 *
 * Behavior:
 * - Groups three failure classes with different handling:
 *   1) UploadRejectedException: untrusted input was rejected
 *   2) UploadConfigException: upload cfg or a profile is invalid
 *   3) UploadStorageException: Upload's own filesystem work failed
 *
 * Notes:
 * - Catch the concrete subclasses. Only UploadRejectedException is meant for
 *   adapters; catching UploadException would hide configuration and server
 *   faults behind a validation message.
 * - Developer misuse of Upload's API raises \InvalidArgumentException and is
 *   not part of this hierarchy: an invalid $subdir, $file, or file path, a
 *   missing local source, a file that was not uploaded in the current
 *   request, or a variant the profile does not define.
 */
abstract class UploadException extends \RuntimeException {}
