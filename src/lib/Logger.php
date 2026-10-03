<?php

/**
 * Logger.php
 *
 * A Simple PHP Logger.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

final class Logger {

    // Static Unique ID for the execution of the script
    private static $UID;
    
    // Log buffer for batched writes
    private static $buffer = [];
    
    // Maximum buffer size before auto-flush
    private static $bufferSize = 50;
    
    // Cache for log file path
    private static $logFilePath = null;
    
    // Shutdown handler registered flag
    private static $shutdownRegistered = false;

    // Log level priorities
    private const LEVELS = [
        'DEBUG'   => 0,
        'INFO'    => 1,
        'WARNING' => 2,
        'ERROR'   => 3,
    ];

    // Cached minimum log level
    private static $minLevel = null;

    /**
     * Get the unique ID of the log.
     *
     * @return string The unique ID
     */
    public static function getUID(){
        if (self::$UID === null){
            self::$UID = uniqid();
        }
        return self::$UID;
    }

    /**
     * Get the minimum log level from configuration.
     *
     * @return int The minimum log level priority
     */
    private static function getMinLevel()
    {
        if (self::$minLevel === null) {
            $configured = strtoupper(Config::get('log_level') ?? 'INFO');
            self::$minLevel = self::LEVELS[$configured] ?? self::LEVELS['INFO'];
        }
        return self::$minLevel;
    }

    /**
     * Log a debug message in the log file.
     *
     * @param string     $msg  The message to log
     * @param mixed|null $data The data to log
     * @return void
     */
    public static function debug($msg, $data=null){
        self::log($msg, $data, "DEBUG");
    }

    /**
     * Log an info message in the log file.
     *
     * @param string     $msg  The message to log
     * @param mixed|null $data The data to log
     * @return void
     */
    public static function info($msg, $data=null){
        self::log($msg, $data, "INFO");
    }

    /**
     * Log a warning in the log file.
     *
     * @param string     $msg  The message to log
     * @param mixed|null $data The data to log
     * @return void
     */
    public static function warn($msg, $data=null){
        self::log($msg, $data, "WARNING");
    }

    /**
     * Log an error in the log file.
     *
     * @param string     $msg  The message to log
     * @param mixed|null $data The data to log
     * @return void
     */
    public static function error($msg, $data=null){
        self::log($msg, $data, "ERROR");
    }

    /**
     * Log a message in the log file.
     *
     * @param string     $msg   The message to log
     * @param mixed|null $data  The data to log
     * @param string     $level The log level
     * @return void
     */
    private static function log($msg, $data, $level){

        // Skip if below minimum log level
        if (self::LEVELS[$level] < self::getMinLevel()) {
            return;
        }

        // Build log entry
        $timestamp = date("y:m:d H:i:s");
        $caller = self::getCaller();
        
        // Add the data to the message if any
        if ($data !== null){
            $msg = $msg . " : " . print_r($data, true);
        }

        // Format log line
        $logLine = $timestamp . " : " . $level . " : " . self::getUID() . " : " . $caller . " : " . $msg . "\n";
        
        // Add to buffer
        self::$buffer[] = $logLine;
        
        // Register shutdown function on first log
        if (!self::$shutdownRegistered) {
            register_shutdown_function([self::class, 'flush']);
            self::$shutdownRegistered = true;
        }
        
        // Auto-flush if buffer is full
        if (count(self::$buffer) >= self::$bufferSize) {
            self::flush();
        }
    }

    /**
     * Get the caller file and function from the backtrace.
     *
     * @return string The caller info formatted as "File.php : Class::method"
     */
    private static function getCaller()
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        $caller = $trace[3] ?? $trace[2] ?? [];
        $file = isset($caller['file']) ? basename($caller['file']) : '?';
        $function = $caller['function'] ?? '?';
        $class = isset($caller['class']) ? $caller['class'] . '::' : '';

        return $file . "->" . $class . $function;
    }
    
    /**
     * Flush buffered logs to file.
     *
     * @return void
     */
    public static function flush(){
        if (empty(self::$buffer)) {
            return;
        }
        
        // Write all buffered logs at once
        $logFile = self::getLogFilePath();
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $content = implode('', self::$buffer);
        file_put_contents($logFile, $content, FILE_APPEND | LOCK_EX);
        
        // Clear buffer
        self::$buffer = [];
    }

    /**
     * Get the log file path, building and caching it on first call.
     *
     * @return string The log file path
     */
    private static function getLogFilePath()
    {
        if (self::$logFilePath === null) {
            $postfix = date("Ymd");
            if (isset($GLOBALS['SCOPE'])) {
                $postfix = $GLOBALS['SCOPE'] . "_" . $postfix;
            }
            $log_dir = Config::get('data_dir') . "/logs";
            self::$logFilePath = $log_dir . "/carbure_$postfix.log";
        }

        return self::$logFilePath;
    }
    
    /**
     * Set buffer size for performance tuning
     * @param int $size Number of log entries before auto-flush
     */
    public static function setBufferSize($size){
        self::$bufferSize = max(1, (int)$size);
    }
}
