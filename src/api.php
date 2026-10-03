<?php

// Not installed yet: only the installation wizard of the portal answers (/api/setup)
$env = preg_replace('/[^a-z0-9_-]/i', '', getenv('APP_ENV') ?: 'prod');
if (!is_file(dirname(__DIR__) . "/data/conf/$env.ini") && !is_file(dirname(__DIR__) . "/conf/$env.ini")) {
    foreach (['Migrator', 'Installer', 'Setup'] as $class) {
        require_once __DIR__ . "/lib/$class.php";
    }
    Setup::serve(dirname(__DIR__), $env);
    return;
}

require_once 'autoload.php';

try {

    // if the path doesn't contain a '.' we consider it as a request to the webservice
    if (strpos($_SERVER["REQUEST_URI"], ".") === false) {

        // Log the request
        // Tokens given in the URL (sync, MCP) are not written in the logs
        Logger::info("Request : " . preg_replace('/([?&]token=)[^&]*/i', '$1***', $_SERVER["REQUEST_URI"]));

        // We execute the webservice
        echo Webservice::exec();
        return;
    }

} catch (Exception $e) {
    // Exception occurred, we return a 500 error
    Webservice::error(500, $e);

} catch (Error $e) {
    // Error occurred, we return the error code if it is an HTTP client error, 400 otherwise
    $code = $e->getCode();
    Webservice::error(($code >= 400 && $code < 500) ? $code : 400, $e);
}




