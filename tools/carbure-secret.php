#!/usr/bin/env php
<?php

/**
 * carbure-secret.php
 *
 * Prints a bank credential encrypted by Carbure (#532). woob runs this command itself: a
 * setting written between backquotes in its file of the banks is a shell command whose
 * output is the value. The key comes from the environment of the woob process
 * (CARBURE_SECRET_KEY, or a secret file), never from the data volume: the clear value
 * only goes through the pipe to woob.
 *
 * Usage: carbure-secret.php v1:<base64>
 */

use Carbure\Lib\Secret;

require __DIR__ . '/../src/lib/Secret.php';

try {
    $value = Secret::decrypt((string)($argv[1] ?? ''));
} catch (Throwable $e) {
    fwrite(STDERR, "carbure-secret: " . $e->getMessage() . "\n");
    exit(2);
}
if ($value === null) {
    // No key, another key, or not a value encrypted by Carbure: never a partial output
    fwrite(STDERR, "carbure-secret: this value cannot be decrypted (check " . Secret::KEY . ")\n");
    exit(1);
}
echo $value, "\n";
