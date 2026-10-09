<?php

use ltoinel\lib\Config;

/**
 * autoload.php
 *
 * Carbure autoload file
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

spl_autoload_register(
    function ($className) {
        $file = str_replace(["ltoinel\\", "\\"], ["/", "/"], $className) . ".php";
        require_once __DIR__ . $file;      
    }
);

Config::load(getenv('APP_ENV') ?: 'prod');
