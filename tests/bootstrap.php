<?php

/*
 * Test bootstrap. Prefers the package's own vendor/ (standalone runs),
 * falling back to the host application's autoloader when the package lives
 * inside a monorepo (packages/zuko/laravel-bit-masks).
 */

$autoloaders = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../../vendor/autoload.php',
];

foreach ($autoloaders as $autoloader) {
    if (file_exists($autoloader)) {
        require $autoloader;
        break;
    }
}
