<?php
echo 'PHP version: ' . phpversion() . PHP_EOL;
try {
    require_once __DIR__ . '/../vendor/autoload.php';
    echo 'autoload OK' . PHP_EOL;
} catch (Throwable $e) {
    echo 'EXCEPTION (' . get_class($e) . '): ' . $e->getMessage() . PHP_EOL;
}
echo 'Script continued past autoload.' . PHP_EOL;
