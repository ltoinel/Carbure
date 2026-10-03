<?php

require_once 'autoload.php';

try {

    // if the path doesn't contain a '.' we consider it as a request to the webservice
    if (strpos($_SERVER["REQUEST_URI"], ".") === false) {

        // Log the request
        Logger::info("Request : " . $_SERVER["REQUEST_URI"]);

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




