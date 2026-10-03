<?php

/**
 * Config.php
 *
 * A simple config loader.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

final class Config {

    // Static settings
    private static $SETTINGS = [];

    /**
     * Get the config file path based on the environment.
     *
     * @param string $path The base path of the project
     * @param string $env  The environment (prod, dev, test)
     * @return string The config file path
     * @throws Exception If the config file is not found
     */
    private static function getConfigFile($path,$env){

        // load the settings from the ini file
        $CONFIG_FILE = $path . "/conf/". $env.".ini";
        if (!file_exists($CONFIG_FILE)) {
            throw new Exception("Config file not found: $CONFIG_FILE");
        }
        return $CONFIG_FILE;
    }

    /**
     * Load the configuration file.
     *
     * @param string $env The environment (prod, dev, test)
     * @return void
     * @throws Exception If the config file is not found
     */ 
    public static function load($env="prod"){
        
        // Install directory is the root of the project
        $INSTALL_DIR = dirname(dirname(__DIR__));

        // Load the settings
        $configFile = self::getConfigFile($INSTALL_DIR,$env);
        self::$SETTINGS =  parse_ini_file($configFile);

        // Debug mode
        if (self::get('log_level') === 'debug') {
            ini_set('display_errors', '1');
            ini_set('display_startup_errors', '1');
            error_reporting(E_ALL);
        }

        self::set('install_dir', $INSTALL_DIR);
    }

    /**
     * Get a setting from the configuration.
     *
     * @param string $key The setting key
     * @return mixed The setting value
     * @throws Exception If the setting is not found
     */
    public static function get($key){
       if (array_key_exists($key, self::$SETTINGS)) {
           return self::$SETTINGS[$key];
       } else {
           throw new Exception("Setting not found: $key");
       }
    }

    /**
     * Check if a setting exists and is not empty.
     *
     * @param string $key The setting key
     * @return bool True if the setting is defined
     */
    public static function has($key){
        return !empty(self::$SETTINGS[$key]);
    }

    /**
     * Set a setting in the configuration.
     *
     * @param string $key   The setting key
     * @param mixed  $value The setting value
     * @return void
     */
    public static function set($key,$value){
        self::$SETTINGS[$key] = $value;
    }
    
    /**
     * Convert relative path to absolute path using install directory as base
     * @param string $path Path to convert (can be relative or absolute)
     * @return string Absolute path
     */
    public static function getAbsolutePath($path)
    {
        // If already absolute (starts with / or drive letter on Windows), return as is
        if (substr($path, 0, 1) === '/' || preg_match('/^[a-zA-Z]:\\\\/', $path)) {
            return $path;
        }
        
        // Convert relative path to absolute using install directory
        return self::get('install_dir') . '/' . ltrim($path, '/');
    }
}