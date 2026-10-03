<?php

/**
 * Woob.php
 *
 * Woob integration utility class
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

final class Woob {

    /**
     * Seconds of silence from woob before sending an SSE heartbeat
     */
    private const HEARTBEAT_INTERVAL = 15;

    /**
     * Call Woob to get the list of transactions from a bank.
     * Uses proc_open to send SSE heartbeats while waiting for woob to finish,
     * preventing proxy/client timeouts on long-running commands.
     *
     * @param string $type   The type of transactions to get (coming or history)
     * @param string $bankId The bank account identifier
     * @return array|null The transaction data or null if no data
     * @throws Exception If woob fails, or reports an error without returning data
     */
    public static function getBankData($type, $bankId)
    {
        $command = self::buildCommand($type, $bankId);
        Logger::debug("Calling woob : $command");

        $stderr = '';
        $stdout = self::executeWithHeartbeat($command, $stderr);
        $data = self::parseOutput($type, $stdout);

        // woob may exit with 0 when a backend cannot be loaded or logged in:
        // no data + an error on stderr is a failure, not an empty account
        if ($data === null && preg_match('/error|exception|traceback|unable|no module/i', $stderr)) {
            Logger::error("woob returned no data", ["stderr" => $stderr]);
            throw new Exception("woob returned no data" . self::lastLine($stderr));
        }

        return $data;
    }

    /**
     * List the bank accounts of every configured woob backend.
     *
     * @return array The accounts: id ("<account>@<backend>"), label, balance, currency...
     * @throws Exception If woob fails or returns nothing while reporting an error
     */
    public static function listAccounts()
    {
        $woob_path = Config::get('woob_path');
        $woob_logging = Config::get('woob_logging');
        $command = "$woob_path bank list -f json --logging $woob_logging";
        Logger::debug("Calling woob : $command");

        // Plain JSON request: no Server-Sent Events heartbeat in the output
        $stderr = '';
        $stdout = self::executeWithHeartbeat($command, $stderr, false);
        $accounts = self::parseOutput('accounts', $stdout);

        if ($accounts === null && preg_match('/error|exception|traceback|unable|no module/i', $stderr)) {
            Logger::error("woob returned no account", ["stderr" => $stderr]);
            throw new Exception("woob returned no account" . self::lastLine($stderr));
        }

        return array_values(array_filter($accounts ?? [], fn($a) => is_array($a) && !empty($a['id'])));
    }

    /**
     * List the bank backends configured in woob (only their name and module:
     * the configuration, which contains the bank login, is never returned).
     *
     * @return array The backends: [{name, module}]
     */
    public static function listBackends()
    {
        $woob_path = Config::get('woob_path');
        $stderr = '';
        $stdout = self::executeWithHeartbeat("$woob_path config list CapBank -f json", $stderr, false);

        $backends = [];
        foreach (self::parseOutput('backends', $stdout) ?? [] as $row) {
            if (is_array($row) && !empty($row['Name'])) {
                $backends[] = ['name' => $row['Name'], 'module' => $row['Module'] ?? $row['Name']];
            }
        }
        usort($backends, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $backends;
    }

    /**
     * List the bank modules supported by woob (cached for a day: the list only
     * changes with woob updates).
     *
     * @return array The modules: [{module, description}]
     */
    public static function listBankModules()
    {
        $cacheKey = 'carbure_woob_bank_modules';
        if (function_exists('apcu_fetch') && ini_get('apc.enabled')) {
            $cached = apcu_fetch($cacheKey, $found);
            if ($found) {
                return $cached;
            }
        }

        $woob_path = Config::get('woob_path');
        $stderr = '';
        $stdout = self::executeWithHeartbeat("$woob_path config modules CapBank -f json", $stderr, false);

        $modules = [];
        foreach (self::parseOutput('modules', $stdout) ?? [] as $row) {
            if (is_array($row) && !empty($row['Name'])) {
                $modules[] = ['module' => $row['Name'], 'description' => $row['Description'] ?? ''];
            }
        }
        usort($modules, fn($a, $b) => strcasecmp($a['description'] ?: $a['module'], $b['description'] ?: $b['module']));

        if ($modules && function_exists('apcu_store') && ini_get('apc.enabled')) {
            apcu_store($cacheKey, $modules, 86400);
        }

        return $modules;
    }

    /**
     * Settings asked by a woob bank module (login, password, website...), to
     * build the form of the portal. Nothing secret is returned.
     *
     * @param string $module The woob module (e.g. bnp)
     * @return array The fields: [{key, label, description, default, required, masked, choices}]
     * @throws Exception If woob does not know the module
     */
    public static function moduleFields($module)
    {
        $info = self::moduleInfo($module);
        // woob only describes the settings of an installed module: install it first
        if (!isset($info['config'])) {
            self::installModule($module);
            $info = self::moduleInfo($module);
        }

        $fields = [];
        foreach (($info['config'] ?? []) as $key => $field) {
            $choices = [];
            foreach ((array)($field['choices'] ?? []) as $value => $label) {
                // woob gives either {value: label} or a list of values
                $choices[] = is_int($value) && !is_array($label)
                    ? ['value' => (string)$label, 'label' => (string)$label]
                    : ['value' => (string)$value, 'label' => (string)$label];
            }
            // Booleans are offered as y/n choices: false must stay "n", not the first choice
            $default = $field['default'] ?? '';
            if (is_bool($default)) {
                $default = $default ? 'y' : 'n';
            }
            $fields[] = [
                'key' => (string)$key,
                'label' => (string)($field['label'] ?? $key),
                'description' => (string)($field['description'] ?? ''),
                'default' => is_scalar($default) ? (string)$default : '',
                'required' => !empty($field['required']),
                'masked' => !empty($field['masked']),
                'regexp' => is_string($field['regexp'] ?? null) ? $field['regexp'] : null,
                'choices' => $choices,
            ];
        }

        return ['module' => $info['name'], 'description' => (string)($info['description'] ?? ''), 'fields' => $fields];
    }

    /**
     * Description of a woob module (with its settings once installed).
     *
     * @param string $module The woob module
     * @return array The description given by "woob config info"
     * @throws Exception If woob does not know the module
     */
    private static function moduleInfo($module)
    {
        $woob_path = Config::get('woob_path');
        $stderr = '';
        $stdout = self::executeWithHeartbeat("$woob_path config info " . escapeshellarg($module) . " -f json", $stderr, false);
        foreach (self::parseOutput('module', $stdout) ?? [] as $row) {
            if (is_array($row) && isset($row['name'])) {
                return $row;
            }
        }
        throw new Exception("woob does not know the module $module" . self::lastLine($stderr ?: $stdout));
    }

    /**
     * Install a woob module: "config add" installs it, then stops at the first
     * setting it asks for (no input). A backend created anyway (module without
     * settings) is removed.
     *
     * @param string $module The woob module
     * @return void
     */
    private static function installModule($module)
    {
        $woob_path = Config::get('woob_path');
        $probe = 'carbure_install_probe';
        $process = proc_open("$woob_path config add " . escapeshellarg($module) . ' ' . escapeshellarg($probe) . ' < /dev/null',
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return;
        }
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        if (stripos($stdout, 'successfully added') !== false) {
            shell_exec("$woob_path config remove " . escapeshellarg($probe) . ' < /dev/null 2>&1');
        }
    }

    /**
     * Configure a bank backend in woob with the settings typed in the portal
     * (woob keeps them; Carbure does not store them).
     *
     * @param string $module  The woob module
     * @param string $backend The backend name
     * @param array  $params  The settings: key => value
     * @return void
     * @throws Exception If woob refuses the backend
     */
    public static function addBackend($module, $backend, $params)
    {
        // woob reads at most 2 arguments: the module, then the backend name and the
        // key=value settings together, quoted (it splits them on spaces)
        $settings = [$backend];
        foreach ($params as $key => $value) {
            $settings[] = "$key=$value";
        }
        $woob_path = Config::get('woob_path');
        $stderr = '';
        // The settings are not logged: they contain the bank credentials
        $process = proc_open("$woob_path config add " . escapeshellarg($module) . ' ' . escapeshellarg('"' . implode(' ', $settings) . '"') . " < /dev/null",
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new Exception("Unable to run woob");
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        // Success is announced by woob; anything else (missing setting asked, module
        // not loaded, name taken...) is a failure
        if ($code !== 0 || stripos($stdout, 'successfully added') === false) {
            Logger::error("woob could not add the backend $backend ($module)");
            throw new Exception("woob could not add the bank" . self::lastLine($stderr ?: $stdout));
        }
    }

    /**
     * Remove a backend from woob (and the credentials woob kept for it).
     *
     * @param string $backend The backend name
     * @return void
     */
    public static function removeBackend($backend)
    {
        $woob_path = Config::get('woob_path');
        shell_exec("$woob_path config remove " . escapeshellarg($backend) . ' < /dev/null 2>&1');
    }

    /**
     * Last non empty line of an output, prefixed with ": " (empty string if none).
     *
     * @param string $output The command output
     * @return string The suffix to add to an error message
     */
    private static function lastLine($output)
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));
        return $lines ? ': ' . mb_substr(end($lines), 0, 200) : '';
    }

    /**
     * Build the woob CLI command string.
     *
     * @param string $type  The type of transactions to get (coming or history)
     * @param string $bankId The bank account identifier
     * @return string The full command to execute
     */
    private static function buildCommand($type, $bankId)
    {
        $woob_path = Config::get('woob_path');
        $woob_transactions = Config::get('woob_transactions');
        $woob_logging = Config::get('woob_logging');
        $woob_auto_update = Config::get('woob_auto_update');
        $woob_debug = Config::get('woob_debug');

        $auto_update_flag = $woob_auto_update ? " --auto-update" : "";
        $debug_flag = $woob_debug ? " --debug" : "";

        $bankId = escapeshellarg($bankId);

        return "$woob_path bank $type $bankId --count $woob_transactions$auto_update_flag$debug_flag --logging $woob_logging -f json -s date,rdate,amount,raw,type,category,card";
    }

    /**
     * Execute a shell command with SSE heartbeats sent during execution.
     *
     * @param string $command   The shell command to execute
     * @param string $stderr    Receives the error output of the command
     * @param bool   $heartbeat Send SSE heartbeats while waiting (stream routes only)
     * @return string The stdout output of the command
     * @throws Exception If the process fails to start or exits with a non-zero code
     */
    private static function executeWithHeartbeat($command, &$stderr = '', $heartbeat = true)
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new Exception("Failed to start woob process");
        }

        fclose($pipes[0]);

        $output = [1 => '', 2 => ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];

        // Read stdout and stderr until both are closed, sending a heartbeat
        // each time woob stays silent for HEARTBEAT_INTERVAL seconds
        while (!empty($open)) {
            $read = array_values($open);
            $write = null;
            $except = null;

            if (stream_select($read, $write, $except, self::HEARTBEAT_INTERVAL) === 0) {
                if ($heartbeat) {
                    Webservice::sendHeartbeat();
                }
                continue;
            }

            foreach ($read as $pipe) {
                $index = array_search($pipe, $open, true);
                $chunk = fread($pipe, 8192);
                if ($chunk === '' || $chunk === false) {
                    fclose($pipe);
                    unset($open[$index]);
                } else {
                    $output[$index] .= $chunk;
                }
            }
        }

        $stdout = $output[1];
        $stderr = $output[2];
        $return_var = proc_close($process);

        
        if (Config::get('woob_debug')) {
            Logger::debug("Woob stdout", ["stdout" => $stdout]);
            Logger::debug("Woob stderr", ["stderr" => $stderr]);
        }

        if ($return_var != 0) {
            Logger::error("Error while calling woob (exit code: $return_var)", ["stdout" => $stdout, "stderr" => $stderr]);

            // The last line of the error output usually gives the cause (e.g. a Python exception)
            throw new Exception("Error while calling woob (exit code: $return_var)" . self::lastLine($stderr));
        }

        return $stdout;
    }

    /**
     * Parse the JSON output returned by woob.
     *
     * @param string $type   The transaction type (for logging)
     * @param string $stdout The raw stdout from the woob process
     * @return array|null The parsed transaction data or null if empty
     */
    private static function parseOutput($type, $stdout)
    {
        $lines = array_filter(explode("\n", trim($stdout)));

        if (count($lines) === 0) {
            Logger::warn("No data received from the bank");
            return null;
        }

        $data = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if ($decoded === null) {
                continue;
            }
            if (isset($decoded[0]) && is_array($decoded[0])) {
                $data = array_merge($data, $decoded);
            } else {
                $data[] = $decoded;
            }
        }

        Logger::debug("Synchronized $type: " . count($data) . " transactions");
        return $data;
    }
}
