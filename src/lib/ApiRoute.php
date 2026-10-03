<?php

/**
 * ApiRoute.php
 *
 * Attribute to define API routes
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

#[Attribute(Attribute::TARGET_METHOD)]
class ApiRoute {
    public string $path;
    public string $method;
    public bool $public;
    public bool $stream;
    public bool $raw = false;

    /**
     * Declare an API route on a public static method.
     *
     * @param string $path   The route path, without the /api prefix (e.g. /user/login)
     * @param string $method The HTTP method (GET, POST, PUT, DELETE)
     * @param bool   $public True if the route does not require a JWT
     * @param bool   $stream True if the route answers with Server-Sent Events
     * @param bool   $raw    True if the method returns the response body itself
     *                       (already encoded JSON, or '' for an empty body)
     */
    public function __construct(
        string $path, 
        string $method = 'GET', 
        bool $public = false,
        bool $stream = false,
        bool $raw = false
    ) {
        $this->path = $path;
        $this->method = $method;
        $this->public = $public;
        $this->stream = $stream;
        $this->raw = $raw;
    }
}