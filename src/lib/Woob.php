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
     */
    public static function getBankData($type, $bankId)
    {
        $command = self::buildCommand($type, $bankId);
        Logger::debug("Calling woob : $command");

        $stdout = self::executeWithHeartbeat($command);

        return self::parseOutput($type, $stdout);
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
     * @param string $command The shell command to execute
     * @return string The stdout output of the command
     * @throws Exception If the process fails to start or exits with a non-zero code
     */
    private static function executeWithHeartbeat($command)
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
                Webservice::sendHeartbeat();
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
            throw new Exception("Error while calling woob (exit code: $return_var)");
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
