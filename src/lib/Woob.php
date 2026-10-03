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
