<?php
/**
 * PolyPay PHP SDK autoloader
 *
 * For non-Composer projects. Usage:
 *   require_once '/path/to/php-sdk/autoload.php';
 *
 * @package PolyPay
 */

spl_autoload_register(function ($class) {
    // Only handle the PolyPay namespace.
    $prefix = 'PolyPay\\';
    $prefixLen = strlen($prefix);

    if (strncmp($prefix, $class, $prefixLen) !== 0) {
        return;
    }

    // Map the namespace to the src/ directory.
    $relativeClass = substr($class, $prefixLen);
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
