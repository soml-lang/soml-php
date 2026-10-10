<?php

declare(strict_types=1);

require_once __DIR__ . '/process.php';

// Lint repository PHP files and the extensionless CLI with the same PHP executable as Composer. Vendor dependencies are checked by their own projects.
$root = dirname(__DIR__);
$files = [$root . '/bin/soml'];

foreach (['src', 'tests', 'bench', 'tools'] as $directory) {
	$entries = new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS), static fn (SplFileInfo $entry): bool => !$entry->isDir() || $entry->getFilename() !== 'vendor');

	foreach (new RecursiveIteratorIterator($entries) as $entry) {
		if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
			$files[] = $entry->getPathname();
		}
	}
}

sort($files, SORT_STRING);

try {
	exit(runProcess([PHP_BINARY, '-l', ...$files], timeoutSeconds: 120));
} catch (RuntimeException $exception) {
	fwrite(STDERR, $exception->getMessage() . "\n");
	exit(1);
}
