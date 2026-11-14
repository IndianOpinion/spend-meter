<?php

/**
 * PSR-4 autoloader for use without Composer.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'IndianOpinion\\SpendMeter\\';
    if (0 !== strncmp($class, $prefix, strlen($prefix))) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_readable($file)) {
        require $file;
    }
});
