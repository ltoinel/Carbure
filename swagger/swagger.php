<?php

/**
 * generate-swagger.php
 *
 * Automatic Swagger/OpenAPI generator by reflection
 * Scans all Resource files and generates OpenAPI 3.0 specification
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

require_once __DIR__ . '/../src/autoload.php';

class SwaggerGenerator {

    private $spec;
    private $resourcesPath;

    public function __construct($resourcesPath) {
        $this->resourcesPath = $resourcesPath;
        $this->spec = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Carbure API',
                'description' => 'Backend API',
                'version' => '1.0.0',
            ],
            'servers' => [
                [
                    'url' => '/api',
                    'description' => 'API Server'
                ]
            ],
            'paths' => [],
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT'
                    ]
                ]
            ]
        ];
    }

    /**
     * Scan all resources files and generate OpenAPI spec
     */
    public function generate() {
        $files = glob($this->resourcesPath . '/*.php');

        foreach ($files as $file) {
            $this->processResourceFile($file);
        }

        return $this->spec;
    }

    /**
     * Process a single resource file
     */
    private function processResourceFile($file) {
        require_once $file;

        $resourceName = basename($file, '.php');
        $class = new ReflectionClass($resourceName);

        // Get all public static methods
        $methods = $class->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC);

        foreach ($methods as $method) {
            $this->processMethod($resourceName, $method);
        }
    }

    /**
     * Process a method and add it to the OpenAPI spec
     */
    private function processMethod($resourceName, ReflectionMethod $method) {
        $methodName = $method->getName();
        
        // Skip private/protected methods
        if (!$method->isPublic() || !$method->isStatic()) {
            return;
        }

        // Check for ApiRoute attribute
        $attributes = $method->getAttributes(ApiRoute::class);
        if (empty($attributes)) {
            return; // Skip methods without ApiRoute annotation
        }

        $apiRoute = $attributes[0]->newInstance();
        
        // Get HTTP method and path from ApiRoute annotation
        // Add /api prefix to all routes
        $httpMethod = strtolower($apiRoute->method);
        $path = '/api' . $apiRoute->path;

        // Get parameters
        $parameters = $this->extractParameters($method, $httpMethod);

        // Get documentation from docblock
        $docComment = $method->getDocComment();
        $description = $this->extractDescription($docComment);

        // Build the operation
        $operation = [
            'summary' => $this->generateSummary($methodName, $resourceName),
            'description' => $description ?: "Endpoint for $resourceName::$methodName",
            'tags' => [$resourceName],
            'responses' => [
                '200' => [
                    'description' => 'Successful response',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object'
                            ]
                        ]
                    ]
                ],
                '400' => [
                    'description' => 'Bad request'
                ],
                '401' => [
                    'description' => 'Unauthorized'
                ],
                '500' => [
                    'description' => 'Server error'
                ]
            ]
        ];

        // Add parameters or requestBody based on HTTP method
        if ($httpMethod === 'get' || $httpMethod === 'delete') {
            if (!empty($parameters)) {
                $operation['parameters'] = $parameters;
            }
        } else {
            // POST, PUT, PATCH: use requestBody
            if (!empty($parameters)) {
                $operation['requestBody'] = [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => $parameters,
                                'required' => array_keys(array_filter($parameters, function($p) {
                                    return $p['required'] ?? false;
                                }))
                            ]
                        ]
                    ]
                ];
            }
        }

        // Add security based on ApiRoute->public property (not public = requires auth)
        if (!$apiRoute->public) {
            $operation['security'] = [
                ['BearerAuth' => []]
            ];
        }

        // Initialize path if not exists
        if (!isset($this->spec['paths'][$path])) {
            $this->spec['paths'][$path] = [];
        }

        $this->spec['paths'][$path][$httpMethod] = $operation;
    }



    /**
     * Extract parameters from method signature
     */
    private function extractParameters(ReflectionMethod $method, $httpMethod) {
        $parameters = [];
        
        foreach ($method->getParameters() as $param) {
            $paramName = $param->getName();
            
            // Try to detect type
            $type = 'string';
            if ($param->hasType()) {
                $paramType = $param->getType();
                if ($paramType instanceof ReflectionNamedType) {
                    $typeName = $paramType->getName();
                    if ($typeName === 'int') {
                        $type = 'integer';
                    } elseif ($typeName === 'float' || $typeName === 'double') {
                        $type = 'number';
                    } elseif ($typeName === 'bool') {
                        $type = 'boolean';
                    } elseif ($typeName === 'array') {
                        $type = 'object';
                    }
                }
            }

            // Check if parameter has default value
            $required = !$param->isOptional();

            if ($httpMethod === 'get' || $httpMethod === 'delete') {
                // GET/DELETE: parameters in query string
                $parameters[] = [
                    'name' => $paramName,
                    'in' => 'query',
                    'required' => $required,
                    'schema' => [
                        'type' => $type
                    ],
                    'description' => ucfirst($paramName) . ' parameter'
                ];
            } else {
                // POST/PUT/PATCH: parameters in request body
                $parameters[$paramName] = [
                    'type' => $type,
                    'description' => ucfirst($paramName) . ' parameter',
                    'required' => $required
                ];
            }
        }

        return $parameters;
    }

    /**
     * Extract description from docblock
     */
    private function extractDescription($docComment) {
        if (!$docComment) {
            return '';
        }

        // Extract lines between /** and */
        $lines = explode("\n", $docComment);
        $description = [];

        foreach ($lines as $line) {
            $line = trim($line);
            // Remove /** */ and *
            $line = preg_replace('/^\/?\*+\/?\s*/', '', $line);
            
            // Stop at @param or other tags
            if (strpos($line, '@') === 0) {
                break;
            }

            if (!empty($line)) {
                $description[] = $line;
            }
        }

        return implode(' ', $description);
    }

    /**
     * Generate a human-readable summary
     */
    private function generateSummary($methodName, $resourceName) {
        // Convert camelCase to readable text
        $summary = preg_replace('/([a-z])([A-Z])/', '$1 $2', $methodName);
        $summary = ucfirst($summary);
        
        return "$summary $resourceName";
    }

    /**
     * Output the OpenAPI spec as JSON
     */
    public function output() {
        header('Content-Type: application/json');
        echo json_encode($this->spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Get the spec array
     */
    public function getSpec() {
        return $this->spec;
    }
}

// Generate the swagger specification
$generator = new SwaggerGenerator(__DIR__ . '/../src/resources');
$generator->generate();
$generator->output();
