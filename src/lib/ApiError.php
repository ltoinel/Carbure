<?php

/**
 * ApiError.php
 *
 * An error of the API that the clients can translate: next to its English message
 * (logs, mobile apps, MCP), a stable code and the values of the message.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

use Error;
use Throwable;

/**
 * Error of a request with a stable code: the JSON response adds "error_code" (and
 * "params" when the message carries values) next to the unchanged "error" message, and
 * the portal shows the translation of apiErrors.<error_code> (portal/i18n/<locale>.json).
 * Still an Error: caught and answered like the others (HTTP status = getCode()).
 */
final class ApiError extends Error
{
    /**
     * @param string                          $message   The English message (unchanged for the existing clients)
     * @param int                             $status    The HTTP status (4xx)
     * @param string                          $errorCode The stable code (snake_case), key of the translations
     * @param array<string, string|int|float> $params    The values placed in the message, by name ({name} of the translations)
     */
    public function __construct(string $message, int $status, private readonly string $errorCode, private readonly array $params = [])
    {
        parent::__construct($message, $status);
    }

    /**
     * The stable code of the error.
     *
     * @return string The code (snake_case)
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * The values placed in the message.
     *
     * @return array<string, string|int|float> The values, by name
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * The fields describing an error in a response: its code and values for an ApiError,
     * nothing for another error (its message only, as before).
     *
     * @param Throwable $error The error
     * @return array{error_code?: string, params?: array<string, string|int|float>} The fields to add to the response
     */
    public static function fields(Throwable $error): array
    {
        if (!$error instanceof self) {
            return [];
        }
        return ['error_code' => $error->errorCode] + ($error->params ? ['params' => $error->params] : []);
    }
}
