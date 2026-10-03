<?php

/**
 * Webservice.php
 *
 * Webservices functions for the API.
 * Route resolution is delegated to ApiResolver.
 * Resource classes are loaded by autoload.php.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

final class Webservice {

    /**
     * JSON Content-Type header
     */
    private const CONTENT_TYPE_JSON = "Content-Type: application/json";
    
    /**
     * Server-Sent Events Content-Type header
     */
    private const CONTENT_TYPE_SSE = "Content-Type: text/event-stream";

    /**
     * Return an error message in JSON and log the message.
     *
     * @param int       $code      The HTTP error code
     * @param Exception $exception The exception to report
     * @return void
     */
    public static function error($code,$exception)
    {        
        Logger::error($exception->getMessage(),$exception->getTrace());

        if (!headers_sent()) {
            http_response_code($code);
        }

        // We return the error message + UID for tracking
        echo json_encode(
            array(
                "error" => $exception->getMessage(),
                "code" => $code,
                "uid" => Logger::getUID()
            ), 
            JSON_NUMERIC_CHECK | JSON_PRETTY_PRINT
        );
    }

    /**
     * Get a request header (case-insensitive).
     *
     * @param string $name The header name (e.g. Authorization)
     * @return string|null The header value or null if not found
     */
    public static function getHeader($name)
    {
        // Web servers expose the headers as HTTP_* server variables
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return $_SERVER[$key];
        }
        if (isset($_SERVER['REDIRECT_' . $key])) {
            return $_SERVER['REDIRECT_' . $key];
        }

        // Apache may hide the Authorization header from $_SERVER
        if (function_exists('getallheaders')) {
            $headers = array_change_key_case(getallheaders(), CASE_LOWER);
            return $headers[strtolower($name)] ?? null;
        }

        return null;
    }

    /**
     * Get the payload of the request.
     *
     * @param string|null $rawPayload The raw body (read from php://input when null)
     * @return array The decoded JSON payload
     * @throws Error If the JSON is invalid
     */
    public static function getPayload($rawPayload = null)
    {
        // Get the raw POST data
        $rawPayload ??= file_get_contents('php://input');
        
        if (empty($rawPayload)) {
            return [];
        }

        // Decode JSON to associative array (not object)
        $payload = json_decode($rawPayload, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Error("Invalid JSON payload: " . json_last_error_msg());
        }

        Logger::debug("Payload", $payload);
        
        return $payload ?? [];
    }

    /**
     * Execute the Webservice.
     *
     * @return string The JSON encoded response
     * @throws Error If the route is not found or access is denied
     */
    public static function exec()
    {
        // Get the request path and HTTP method
        $requestPath = strtok($_SERVER["REQUEST_URI"], '?');
        $httpMethod = $_SERVER['REQUEST_METHOD'];
        
        // Remove /api prefix from request path
        $requestPath = preg_replace('#^/api#', '', $requestPath);
        
        $data = self::getPayload();

        // Find the matching route
        $route = ApiResolver::findRoute($requestPath, $httpMethod);

        if (!$route) {
            throw new Error("Route not found: $httpMethod $requestPath", 404);
        }

        // Set appropriate headers based on route type
        self::setResponseHeaders($route['apiRoute']->stream ?? false);

        // Check access control
        self::checkAccess($route['apiRoute']);

        // Call the service
        $response = self::callService($route['class'], $route['method'], $data);

        // A stream has already sent its events: nothing more to output
        return ($route['apiRoute']->stream ?? false) ? '' : $response;
    }

    /**
     * Check access control for the endpoint
     * @param ApiRoute $apiRoute The API route metadata
     */
    private static function checkAccess($apiRoute)
    {
        // Public endpoints don't require authentication
        if ($apiRoute->public) {
            return;
        }

        // Protected endpoints require valid JWT token
        if (!Jwt::checkAuthorization()) {
            throw new Error("Unauthorized - Invalid or missing JWT token", 403);
        }
    }

    /**
     * Set appropriate HTTP response headers
     * @param bool $isStream Whether the response is a Server-Sent Events stream
     */
    private static function setResponseHeaders($isStream)
    {
        if ($isStream) {
            // Server-Sent Events headers
            header(self::CONTENT_TYPE_SSE);
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
            
            // Disable PHP output buffering
            if (ob_get_level()) ob_end_clean();
        } else {
            // Standard JSON response headers
            header(self::CONTENT_TYPE_JSON);
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
        }
    }

    /**
     * Call the service.
     *
     * @param string $resource The resource class name
     * @param string $method   The method name to call
     * @param array  $data     The request data
     * @return string The JSON encoded response
     */
    public static function callService($resource, $method, $data)
    {
        Logger::info("Calling : $resource.$method", $data ? self::maskSensitive($data) : null);

        // Merge GET parameters with payload data
        $data = array_merge($_GET, $data);
        
        // Call the method with or without parameters
        if (empty($data)) {
            $response = call_user_func(array($resource, $method));
        } else {
            Logger::debug("Data", self::maskSensitive($data));
            $response = call_user_func_array(array($resource, $method), $data);
        }

        Logger::debug("Result", $response);

        // Return JSON response with pretty print in debug mode
        $flags = JSON_NUMERIC_CHECK | (Config::get('log_level') === 'debug' ? JSON_PRETTY_PRINT : 0);
        return json_encode($response, $flags);
    }

    /**
     * Mask sensitive values (passwords, tokens) before logging.
     *
     * @param array $data The request data
     * @return array The data with sensitive values masked
     */
    private static function maskSensitive($data)
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::maskSensitive($value);
            } elseif (in_array(strtolower((string)$key), ['password', 'token'], true)) {
                $data[$key] = '***';
            }
        }
        return $data;
    }

    /**
     * Send a progress event via Server-Sent Events
     * @param string $message Progress message
     */
    public static function sendProgress($message)
    {
        echo "data: " . $message . "\n\n";
        
        // Force immediate send
        if (function_exists('flush')) {
            flush();
        }
    }

    /**
     * Send an SSE comment as heartbeat to keep the connection alive.
     * SSE comments (lines starting with :) are ignored by EventSource clients
     * but keep proxies and TCP connections from timing out.
     */
    public static function sendHeartbeat()
    {
        echo ": heartbeat\n\n";
        
        if (function_exists('flush')) {
            flush();
        }
    }

 }