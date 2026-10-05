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
 * Thrown when Upload's own filesystem work fails.
 *
 * Behavior:
 * - Signals failures such as an uncreatable directory, a failed copy, move,
 *   rename, or chmod, an unreadable source size, a failed MIME detection or
 *   hash, a target file that already exists, and upload errors PHP attributes
 *   to the server (UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE,
 *   UPLOAD_ERR_EXTENSION, or an unknown code). It also reports image outputs
 *   that citomni/image could not publish; ImageWriteException or
 *   ImageTargetExistsException is previous.
 * - Files the failing call wrote, including the outputs citomni/image reports
 *   as committed, are removed before this propagates. Removal is best
 *   effort: a file the filesystem refuses to unlink stays behind and
 *   is logged when the log service is registered. Half-written files are
 *   always dot-prefixed ".tmp" files.
 * - delete() and deleteWithVariants() throw it for the first file they cannot
 *   delete; cleanup() and cleanupWithVariants() log such failures instead.
 *
 * Notes:
 * - Never caused by untrusted input. Adapters should let it bubble.
 * - An existing target is never Upload's file and is never touched.
 */
final class UploadStorageException extends UploadException {}
