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

use CitOmni\Kernel\Cfg;

/**
 * Minimal App double for Upload tests.
 *
 * Behavior:
 * - cfg is a real kernel Cfg, so array access such as
 *   $app->cfg->upload->profiles[$name] behaves as in App. The property is
 *   public and can be swapped to observe memoization.
 * - Services resolve lazily through __get(). A \Closure entry is a factory,
 *   invoked with the app on first resolution like App's lazy services, so a
 *   real service such as citomni/image's Image can be built. A factory that
 *   throws makes every resolution of that id throw.
 * - touched lists every resolved service id in order, so tests can prove that
 *   a service was never resolved.
 * - hasService() mirrors App: a registration check that constructs nothing.
 *
 * Notes:
 * - An unknown id throws \RuntimeException, like App::__get().
 */
final class TestApp {

	/** Real kernel configuration wrapper. */
	public Cfg $cfg;

	/** @var list<string> Resolved service ids, in order. */
	public array $touched = [];

	/** @var array<string, object> Service instances or factories (\Closure). */
	private array $services;


	/**
	 * Create the app double.
	 *
	 * @param Cfg $cfg Configuration.
	 * @param array<string, object> $services Service id => instance, or \Closure factory.
	 */
	public function __construct(Cfg $cfg, array $services) {
		$this->cfg = $cfg;
		$this->services = $services;
	}


	/**
	 * Check whether a service id is registered.
	 *
	 * @param string $id Service id.
	 * @return bool True when registered.
	 */
	public function hasService(string $id): bool {
		return isset($this->services[$id]);
	}


	/**
	 * Resolve a service, recording the access.
	 *
	 * @param string $id Service id.
	 * @return object Service instance.
	 * @throws \RuntimeException When the id is not registered.
	 */
	public function __get(string $id): object {
		$this->touched[] = $id;

		if (!isset($this->services[$id])) {
			throw new \RuntimeException("Unknown app component: app->{$id}");
		}

		if ($this->services[$id] instanceof \Closure) {
			$this->services[$id] = ($this->services[$id])($this);
		}

		return $this->services[$id];
	}


}
