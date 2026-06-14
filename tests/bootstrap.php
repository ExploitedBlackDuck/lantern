<?php

declare(strict_types=1);

// PSR-4 autoloader for OCA\Lantern in CI before composer's autoloader exists.
spl_autoload_register(static function (string $class): void {
	$prefix = 'OCA\\Lantern\\';
	if (!str_starts_with($class, $prefix)) {
		return;
	}
	$rel = str_replace('\\', '/', substr($class, \strlen($prefix)));
	foreach ([\dirname(__DIR__) . '/lib/', __DIR__ . '/'] as $base) {
		$file = $base . $rel . '.php';
		if (is_file($file)) {
			require $file;
			return;
		}
	}
});
