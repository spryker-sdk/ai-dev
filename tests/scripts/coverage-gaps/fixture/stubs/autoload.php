<?php

/**
 * Stubs of the core classes the fixture extends or implements. Registered after the Composer autoloader,
 * so the real core classes win when the vendor/ in use has them.
 */
spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
