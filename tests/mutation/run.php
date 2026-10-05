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

/*
 * Mutation runner for citomni/upload. Not part of `composer test`.
 *
 * Applies each mutant defined in mutants.php to a disposable copy of the
 * package and runs the test scripts that are expected to kill it. A mutant is
 * killed when one of its scripts exits non-zero.
 *
 * Usage:
 *   php tests/mutation/run.php            Run every mutant.
 *   php tests/mutation/run.php <filter>   Run the mutants whose id or label contains <filter>.
 *   php tests/mutation/run.php --list     Validate the definitions and list them.
 *
 * Behavior:
 * - Validates every definition before anything runs, also when a filter is
 *   given. Edits apply in order, each search string must occur exactly once in
 *   the file at that point, and the mutated file must still parse. A
 *   definition that no longer matches the code fails the run.
 * - Copies the package (without .git and vendor) and the src/ trees of the
 *   sibling packages citomni/kernel and citomni/image into a temporary
 *   directory, in the layout tests/bootstrap.php expects. The working tree is
 *   never modified, so an interrupted run leaves nothing to restore.
 * - Runs every script the selected mutants use once without a mutation first.
 *   A failing baseline stops the run, because every mutant would look killed.
 * - Runs the scripts with the runner's PHP binary and php.ini. Run it where
 *   the suite normally runs (with ext-gd): fewer checks run elsewhere, and a
 *   mutant that only those checks kill then survives.
 * - A mutant marked unprivileged is only killed by checks that need a refused
 *   unlink. Root never gets one, so such a mutant is skipped, and reported as
 *   skipped, unless the runner runs as a non-root POSIX user.
 *
 * Exit codes:
 * - 0: every mutant that ran was killed.
 * - 1: at least one mutant survived.
 * - 2: invalid definitions, a missing sibling package, or a failing baseline;
 *   no mutant ran.
 *
 * Notes:
 * - There is no timeout. A script that hangs stops the run; interrupt it, the
 *   working tree is untouched. The temporary copy is removed after a run and
 *   kept after a failing baseline for inspection.
 */

const MUTATION_REQUIRED_KEYS = ['id', 'label', 'file', 'edits', 'tests'];
const MUTATION_KEYS = [...MUTATION_REQUIRED_KEYS, 'unprivileged'];


/**
 * Print a message on STDERR and stop.
 *
 * @param string $message Message.
 * @param int $code Exit code.
 * @return never
 */
function mutationFail(string $message, int $code = 2): never {
	\fwrite(\STDERR, $message . "\n");
	exit($code);
}


/**
 * Validate the definitions and compute each mutated file.
 *
 * Behavior:
 * - Checks keys and types, unique ids, existing files and test scripts.
 * - Applies the edits in order; each search string must occur exactly once in
 *   the content at that point, and the result must differ from the original.
 *
 * @param string $package Package root.
 * @param mixed $definitions Value returned by mutants.php.
 * @return array{0: list<array<string, mixed>>, 1: list<string>} Prepared mutants, and problems.
 */
function mutationPrepare(string $package, mixed $definitions): array {
	if (!\is_array($definitions) || !\array_is_list($definitions)) {
		return [[], ['mutants.php must return a list of definitions.']];
	}

	$mutants = [];
	$problems = [];
	$files = [];

	foreach ($definitions as $index => $definition) {
		$name = \is_array($definition) && \is_string($definition['id'] ?? null) && $definition['id'] !== '' ? $definition['id'] : 'definition #' . ($index + 1);

		if (!\is_array($definition)) {
			$problems[] = "{$name}: a definition must be an array.";
			continue;
		}

		$errors = [];

		foreach (\array_diff(\array_keys($definition), MUTATION_KEYS) as $key) {
			$errors[] = "{$name}: unknown key '{$key}'.";
		}

		foreach (\array_diff(MUTATION_REQUIRED_KEYS, \array_keys($definition)) as $key) {
			$errors[] = "{$name}: missing key '{$key}'.";
		}

		if ($errors !== []) {
			\array_push($problems, ...$errors);
			continue;
		}

		['id' => $id, 'label' => $label, 'file' => $file, 'edits' => $edits, 'tests' => $tests] = $definition;
		$unprivileged = $definition['unprivileged'] ?? false;

		if (!\is_string($id) || $id === '') {
			$errors[] = "{$name}: id must be a non-empty string.";
		} elseif (isset($mutants[$id])) {
			$errors[] = "{$name}: the id is used twice.";
		}

		if (!\is_string($label) || $label === '') {
			$errors[] = "{$name}: label must be a non-empty string.";
		}

		if (!\is_string($file) || $file === '' || !\is_file($package . '/' . $file)) {
			$errors[] = "{$name}: file must name a file in the package.";
		}

		$validEdit = static fn(mixed $edit): bool => \is_array($edit) && \array_is_list($edit) && \count($edit) === 2 && \is_string($edit[0]) && \is_string($edit[1]) && $edit[0] !== '' && $edit[0] !== $edit[1];

		if (!\is_array($edits) || $edits === [] || !\array_is_list($edits) || \array_filter($edits, $validEdit) !== $edits) {
			$errors[] = "{$name}: edits must be a non-empty list of [search, replace] pairs with a non-empty search that differs from its replacement.";
		}

		if (!\is_array($tests) || $tests === [] || !\array_is_list($tests)) {
			$errors[] = "{$name}: tests must be a non-empty list of script names.";
		} else {
			foreach ($tests as $test) {
				if (!\is_string($test) || !\is_file("{$package}/tests/{$test}_test.php")) {
					$errors[] = "{$name}: there is no test script tests/" . (\is_string($test) ? $test : '?') . '_test.php.';
				}
			}
		}

		if (!\is_bool($unprivileged)) {
			$errors[] = "{$name}: unprivileged must be a bool.";
		}

		if ($errors !== []) {
			\array_push($problems, ...$errors);
			continue;
		}

		$pristine = $files[$file] ??= (string)\file_get_contents($package . '/' . $file);
		$content = $pristine;

		foreach ($edits as $number => [$search, $replace]) {
			$count = \substr_count($content, $search);

			if ($count !== 1) {
				$problems[] = "{$id}: edit " . ($number + 1) . " matches {$count} times in {$file}; it must match exactly once.";
				continue 2;
			}

			$content = \str_replace($search, $replace, $content);
		}

		if ($content === $pristine) {
			$problems[] = "{$id}: the edits leave {$file} unchanged.";
			continue;
		}

		$mutants[$id] = [
			'id' => $id,
			'label' => $label,
			'file' => $file,
			'tests' => $tests,
			'unprivileged' => $unprivileged,
			'content' => $content,
			'pristine' => $pristine,
		];
	}

	return [\array_values($mutants), $problems];
}


/**
 * Check that every mutated file still parses.
 *
 * @param list<array<string, mixed>> $mutants Prepared mutants.
 * @return list<string> Problems.
 */
function mutationLint(array $mutants): array {
	$problems = [];
	$file = \sys_get_temp_dir() . '/citomni-upload-mutation-lint-' . \bin2hex(\random_bytes(6)) . '.php';

	try {
		foreach ($mutants as $mutant) {
			\file_put_contents($file, $mutant['content']);
			[$code, $output] = mutationPhp(['-n', '-l', $file], \sys_get_temp_dir(), false);

			if ($code !== 0) {
				$problems[] = "{$mutant['id']}: the mutated {$mutant['file']} does not parse: " . mutationReason($output);
			}
		}
	} finally {
		@\unlink($file);
	}

	return $problems;
}


/**
 * Run the PHP binary and return its exit code and combined output.
 *
 * Output goes to a temporary file rather than a pipe, so a process the script
 * starts (the PHP development server, for example) cannot hold the runner.
 *
 * @param list<string> $arguments Arguments after the binary (and ini options).
 * @param string $cwd Working directory.
 * @param bool $withIni Pass the runner's php.ini (-c), or -n when it has none.
 * @return array{0: int, 1: string} Exit code and output.
 */
function mutationPhp(array $arguments, string $cwd, bool $withIni = true): array {
	$command = [\PHP_BINARY];

	if ($withIni) {
		$ini = \php_ini_loaded_file();
		\array_push($command, ...($ini === false ? ['-n'] : ['-c', $ini]));
	}

	\array_push($command, ...$arguments);
	$log = \tempnam(\sys_get_temp_dir(), 'citomni-mutation-');

	if ($log === false) {
		mutationFail('Cannot create a temporary file in ' . \sys_get_temp_dir() . '.');
	}

	try {
		$process = \proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['redirect', 1]], $pipes, $cwd);

		if (!\is_resource($process)) {
			mutationFail('Cannot start ' . \PHP_BINARY . '.');
		}

		\fclose($pipes[0]);
		$code = \proc_close($process);

		return [$code, (string)\file_get_contents($log)];
	} finally {
		@\unlink($log);
	}
}


/**
 * Pick the line that explains a failure: the failed check or the fatal error.
 *
 * @param string $output Script output.
 * @return string One line, at most 150 characters.
 */
function mutationReason(string $output): string {
	$lines = \array_values(\array_filter(\array_map(\trim(...), \preg_split('/\R/', $output) ?: []), static fn(string $line): bool => $line !== ''));

	foreach ($lines as $line) {
		if (\str_contains($line, 'FAILED') || \str_contains($line, 'Fatal error') || \str_contains($line, 'Parse error')) {
			return \mb_strimwidth($line, 0, 150, '...');
		}
	}

	return $lines === [] ? '(no output)' : \mb_strimwidth(\end($lines), 0, 150, '...');
}


/**
 * Copy a directory tree, leaving out the given top-level entries.
 *
 * @param string $from Source directory.
 * @param string $to Target directory; created when missing.
 * @param list<string> $skip Top-level entry names to leave out.
 * @return void
 */
function mutationCopy(string $from, string $to, array $skip = []): void {
	if (!\is_dir($to) && !\mkdir($to, 0777, true)) {
		mutationFail("Cannot create {$to}.");
	}

	$tree = new \RecursiveCallbackFilterIterator(
		new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
		static fn(\SplFileInfo $item, string $key, \RecursiveDirectoryIterator $iterator): bool => $iterator->getSubPath() !== '' || !\in_array($item->getFilename(), $skip, true),
	);

	foreach (new \RecursiveIteratorIterator($tree, \RecursiveIteratorIterator::SELF_FIRST) as $item) {
		$target = $to . '/' . \substr($item->getPathname(), \strlen($from) + 1);

		if ($item->isDir()) {
			if (!\is_dir($target) && !\mkdir($target, 0777, true)) {
				mutationFail("Cannot create {$target}.");
			}
		} elseif (!\copy($item->getPathname(), $target)) {
			mutationFail("Cannot copy {$item->getPathname()} to {$target}.");
		}
	}
}


/**
 * Remove a directory tree.
 *
 * @param string $directory Directory.
 * @return void
 */
function mutationRemove(string $directory): void {
	$items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

	foreach ($items as $item) {
		$item->isDir() && !$item->isLink() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
	}

	@\rmdir($directory);
}


/**
 * Copy the package and its sibling packages into a temporary directory.
 *
 * @param string $package Package root.
 * @return string Temporary directory; the package copy is its "upload" subdirectory.
 */
function mutationSandbox(string $package): string {
	$parent = \dirname($package);

	foreach (['kernel', 'image'] as $sibling) {
		if (!\is_dir("{$parent}/{$sibling}/src")) {
			mutationFail("tests/bootstrap.php loads citomni/{$sibling} from {$parent}/{$sibling}/src, which does not exist. Run the mutants in the layout citomni/{kernel,image,upload} or vendor/citomni/{kernel,image,upload}.");
		}
	}

	$sandbox = \sys_get_temp_dir() . '/citomni-upload-mutation-' . \bin2hex(\random_bytes(6));
	mutationCopy($package, "{$sandbox}/upload", ['.git', 'vendor']);

	foreach (['kernel', 'image'] as $sibling) {
		mutationCopy("{$parent}/{$sibling}/src", "{$sandbox}/{$sibling}/src");
	}

	return $sandbox;
}


// -- Arguments and definitions --------------------------------------------------------

if (\PHP_SAPI !== 'cli') {
	mutationFail('Run the mutation runner from the command line.');
}

$package = \dirname(__DIR__, 2);
$arguments = \array_slice($argv, 1);
$listOnly = \in_array('--list', $arguments, true);
$filters = \array_values(\array_diff($arguments, ['--list']));

if (\count($filters) > 1 || ($listOnly && $filters !== [])) {
	mutationFail('Usage: php tests/mutation/run.php [<filter> | --list]');
}

$filter = $filters[0] ?? null;
[$mutants, $problems] = mutationPrepare($package, require __DIR__ . '/mutants.php');

if ($problems === []) {
	$problems = mutationLint($mutants);
}

if ($problems !== []) {
	mutationFail("Invalid mutant definitions; nothing ran:\n  - " . \implode("\n  - ", $problems));
}

if ($listOnly) {
	foreach ($mutants as $mutant) {
		\printf("%-11s %s [%s]%s\n", $mutant['id'], $mutant['label'], \implode(', ', $mutant['tests']), $mutant['unprivileged'] ? ' (unprivileged)' : '');
	}

	echo \count($mutants) . " mutants; every definition matches the code.\n";
	exit(0);
}

$selected = $filter === null ? $mutants : \array_values(\array_filter($mutants, static fn(array $mutant): bool => \stripos($mutant['id'], $filter) !== false || \stripos($mutant['label'], $filter) !== false));

if ($selected === []) {
	mutationFail("No mutant matches '{$filter}'.");
}

$unprivileged = \PHP_OS_FAMILY !== 'Windows' && \function_exists('posix_geteuid') && \posix_geteuid() !== 0;
$runnable = \array_values(\array_filter($selected, static fn(array $mutant): bool => $unprivileged || !$mutant['unprivileged']));


// -- Copy and baseline ----------------------------------------------------------------

$started = \microtime(true);
$sandbox = mutationSandbox($package);
$root = $sandbox . '/upload';
$ini = \php_ini_loaded_file();

\printf(
	"Mutation run: %d of %d mutants, PHP %s, %s, %s.\n",
	\count($selected),
	\count($mutants),
	\PHP_VERSION,
	$ini === false ? 'no php.ini' : $ini,
	$unprivileged ? 'unprivileged POSIX user' : 'privileged or non-POSIX user, so unprivileged mutants are skipped',
);

$scripts = \array_values(\array_unique(\array_merge(...\array_map(static fn(array $mutant): array => $mutant['tests'], $runnable ?: [['tests' => []]]))));

foreach ($scripts as $script) {
	[$code, $output] = mutationPhp(["tests/{$script}_test.php"], $root);

	if ($code !== 0) {
		mutationFail("Baseline failed: tests/{$script}_test.php fails without a mutation: " . mutationReason($output) . "\nThe copy is kept at {$sandbox}.");
	}
}

echo 'Baseline: ' . \count($scripts) . " test scripts pass without a mutation.\n\n";


// -- Mutants --------------------------------------------------------------------------

$counts = ['killed' => 0, 'survived' => 0, 'skipped' => 0];

foreach ($selected as $mutant) {
	if ($mutant['unprivileged'] && !$unprivileged) {
		$counts['skipped']++;
		\printf("%-11s %-9s %s\n%12s needs a non-root POSIX user\n", $mutant['id'], 'SKIPPED', $mutant['label'], '');
		continue;
	}

	$target = $root . '/' . $mutant['file'];
	$verdict = null;
	\file_put_contents($target, $mutant['content']);

	try {
		foreach ($mutant['tests'] as $script) {
			[$code, $output] = mutationPhp(["tests/{$script}_test.php"], $root);

			if ($code !== 0) {
				$verdict = "{$script}_test: " . mutationReason($output);
				break;
			}
		}
	} finally {
		\file_put_contents($target, $mutant['pristine']);
	}

	$counts[$verdict === null ? 'survived' : 'killed']++;
	\printf("%-11s %-9s %s\n%12s %s\n", $mutant['id'], $verdict === null ? 'SURVIVED' : 'KILLED', $mutant['label'], '', $verdict ?? 'no failure in ' . \implode(', ', $mutant['tests']));
}

mutationRemove($sandbox);
\printf("\n%d killed, %d survived, %d skipped, in %ds.\n", $counts['killed'], $counts['survived'], $counts['skipped'], (int)\round(\microtime(true) - $started));
exit($counts['survived'] === 0 ? 0 : 1);
