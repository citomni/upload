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

use CitOmni\Image\Enum\ImageFormat;
use CitOmni\Image\Exception\ImageTargetExistsException;

/**
 * Scriptable double of citomni/image's Image service.
 *
 * Behavior:
 * - inspect() and save() have the real signatures; tests/image-path/run.php
 *   checks them against Image with reflection, so the double cannot drift.
 * - Every call is recorded. save() also records whether every target
 *   directory existed at call time.
 * - inspect() returns $info, or the result of $onInspect.
 * - save() runs $onSave when set; otherwise write() acts like a successful
 *   Image::save(): it refuses a missing target directory (\RuntimeException)
 *   and an existing target (ImageTargetExistsException, nothing committed),
 *   then writes every output and returns results shaped like Image's.
 *
 * Notes:
 * - Files are written with file_put_contents(), so they get 0666 & ~umask,
 *   like the files citomni/image's backends write.
 */
final class ScriptedImage {

	/** @var list<array<int, mixed>> ['inspect', path] or ['save', source, outputs, options, target directories existed]. */
	public array $calls = [];

	/** @var array<string, mixed> Result of inspect() unless $onInspect is set. */
	public array $info = [
		'format' => 'jpeg',
		'mime' => 'image/jpeg',
		'width' => 1600,
		'height' => 1200,
		'orientation' => 1,
		'display_width' => 1600,
		'display_height' => 1200,
		'multi_frame' => false,
		'alpha' => false,
		'icc_profile' => false,
		'color_space' => 'srgb',
		'bytes' => 0,
		'decoder' => 'scripted',
	];

	/** Replaces inspect(): fn(string $path): array, or throws. */
	public ?\Closure $onInspect = null;

	/** Replaces save(): fn(string $sourcePath, array $outputs, array $options): array, or throws. */
	public ?\Closure $onSave = null;


	/**
	 * Record the call and return the scripted metadata.
	 *
	 * @param string $path Path to a readable regular file.
	 * @return array<string, mixed> Metadata.
	 */
	public function inspect(string $path): array {
		$this->calls[] = ['inspect', $path];

		return $this->onInspect !== null ? ($this->onInspect)($path) : $this->info;
	}


	/**
	 * Record the call and run the scripted job.
	 *
	 * @param string $sourcePath Readable source file.
	 * @param array<int|string, array<string, mixed>> $outputs Output specifications.
	 * @param array<string, mixed> $options Job options.
	 * @return array<int|string, array<string, mixed>> Results keyed like $outputs.
	 */
	public function save(string $sourcePath, array $outputs, array $options = []): array {
		$directories = true;

		foreach ($outputs as $output) {
			$directories = $directories && \is_dir(\dirname($output['path']));
		}

		$this->calls[] = ['save', $sourcePath, $outputs, $options, $directories];

		return $this->onSave !== null ? ($this->onSave)($sourcePath, $outputs, $options) : self::write($outputs);
	}


	/**
	 * Write outputs like a successful Image::save().
	 *
	 * @param array<int|string, array<string, mixed>> $outputs Output specifications with paths.
	 * @return array<int|string, array<string, mixed>> Results keyed like $outputs.
	 * @throws \RuntimeException When a target directory does not exist.
	 * @throws ImageTargetExistsException When a target exists; nothing is written then.
	 */
	public static function write(array $outputs): array {
		foreach ($outputs as $key => $output) {
			if (!\is_dir(\dirname($output['path']))) {
				throw new \RuntimeException('Target directory does not exist: ' . \dirname($output['path']));
			}

			if (\file_exists($output['path'])) {
				throw new ImageTargetExistsException('Target exists: ' . $output['path'], [], \array_column($outputs, 'path'), $key);
			}
		}

		$results = [];

		foreach ($outputs as $key => $output) {
			$format = $output['format'] instanceof ImageFormat ? $output['format'] : ImageFormat::from($output['format']);
			$bytes = 'scripted ' . $format->value . ' ' . $key;
			\file_put_contents($output['path'], $bytes);
			$results[$key] = self::result($output['path'], $format, $output['width'] ?? 100, $output['height'] ?? 100, \strlen($bytes));
		}

		return $results;
	}


	/**
	 * Build one result entry shaped like Image::save()'s.
	 *
	 * @param string $path Target path.
	 * @param ImageFormat $format Output format.
	 * @param int $width Output width.
	 * @param int $height Output height.
	 * @param int $bytes Encoded size.
	 * @return array{path: string, format: string, mime: string, width: int, height: int, bytes: int, backend: string} Result entry.
	 */
	public static function result(string $path, ImageFormat $format, int $width, int $height, int $bytes): array {
		return ['path' => $path, 'format' => $format->value, 'mime' => $format->mime(), 'width' => $width, 'height' => $height, 'bytes' => $bytes, 'backend' => 'scripted'];
	}


}
