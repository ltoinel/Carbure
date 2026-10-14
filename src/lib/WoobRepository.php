<?php

/**
 * WoobRepository.php
 *
 * Repository of the woob bank modules (Python code that reads the bank credentials):
 * the trust of its keyring and the update of the modules, decided by an administrator.
 *
 * The first "woob config update" asks whether to trust the keyring of the repository,
 * showing its fingerprint (SHA-1); woob then checks the signature of every module with
 * it, and each next keyring with the previous one. Carbure answers yes only when an
 * administrator confirmed exactly the fingerprint woob shows.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

final class WoobRepository {

    /** Repository of the bank modules of woob 3 */
    public const DEFAULT_REPOSITORY = 'https://updates.woob.tech/3/main/';

    /** Fingerprint shown by woob before trusting a repository */
    private const FINGERPRINT = '/Fingerprint of keyring is ([0-9a-f]{40})/i';

    /** Question of woob waiting for an answer ("Are you sure you want to continue? (Y/n): ") */
    private const QUESTION = '/\((?:Y\/n|y\/N)\):\s*$/';

    /** An update that went wrong, in the output of woob */
    private const FAILURE = '/aborted|error|not trusted|invalid signature|unavailable/i';

    /** Seconds of silence from woob before sending an SSE heartbeat */
    private const HEARTBEAT_INTERVAL = 15;

    /**
     * Directory of the keyrings trusted by woob, replaced by the tests (null: the one of
     * the woob home, ~/.local/share/woob/keyrings)
     */
    public static ?string $keyringDir = null;

    /**
     * Repository of the bank modules (woob_repository, else the one of woob 3).
     *
     * @return string The URL (or directory, in the tests), ending with a slash
     */
    public static function repository(): string
    {
        $repository = Config::has('woob_repository') ? (string)Config::get('woob_repository') : self::DEFAULT_REPOSITORY;
        return rtrim($repository, '/') . '/';
    }

    /**
     * State of the repository: trusted or not, and the fingerprint of its keyring (the
     * one trusted, else the one woob would ask to trust).
     *
     * @return array{repository: string, trusted: bool, fingerprint: string|null, error: string|null}
     */
    public static function status(): array
    {
        $trusted = self::trustedKeyring();
        if ($trusted !== null) {
            return ['repository' => self::repository(), 'trusted' => true, 'fingerprint' => sha1_file($trusted) ?: null, 'error' => null];
        }
        [$fingerprint, $error] = self::remoteFingerprint();
        return ['repository' => self::repository(), 'trusted' => false, 'fingerprint' => $fingerprint, 'error' => $error];
    }

    /**
     * Update the repositories and the bank modules of woob ("woob config update"). If woob
     * asks to trust the keyring of the repository, it is trusted only if its fingerprint
     * is exactly the one confirmed by the administrator.
     *
     * @param string|null $fingerprint Fingerprint confirmed by the administrator (null: none)
     * @param callable    $onMessage   Receives each line written by woob
     * @return array{updated: bool, asked: string|null, refused: bool, message: string} updated:
     *         success; asked: fingerprint woob asked to trust (null if not asked); refused:
     *         Carbure answered no to woob; message: last line of woob
     */
    public static function update(?string $fingerprint, callable $onMessage): array
    {
        $process = proc_open(Woob::path() . ' config update', [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new Exception("Failed to start woob");
        }
        $state = ['output' => '', 'pending' => [1 => '', 2 => ''], 'asked' => null, 'answer' => null, 'stdin' => $pipes[0]];
        self::read([1 => $pipes[1], 2 => $pipes[2]], $state, $fingerprint, $onMessage);
        if (is_resource($state['stdin'])) {
            fclose($state['stdin']);
        }
        $code = proc_close($process);

        $lines = array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', $state['output']) ?: [])));
        $updated = $code === 0 && $state['answer'] !== 'n' && !preg_match(self::FAILURE, $state['output']);
        return ['updated' => $updated, 'asked' => $state['asked'], 'refused' => $state['answer'] === 'n', 'message' => (string)end($lines)];
    }

    /**
     * Answer of Carbure to woob asking to trust a keyring: yes only for the fingerprint
     * confirmed by the administrator (an empty answer would be a yes for woob).
     *
     * @param string      $asked     Fingerprint shown by woob
     * @param string|null $confirmed Fingerprint confirmed by the administrator
     * @return string "y" or "n"
     */
    public static function answer(string $asked, ?string $confirmed): string
    {
        return $confirmed !== null && hash_equals(strtolower($asked), strtolower(trim($confirmed))) ? 'y' : 'n';
    }

    /**
     * Whether woob waits for an answer: its last line, not ended, is a yes/no question.
     *
     * @param string $line Last line written by woob, not ended
     * @return bool
     */
    public static function waitsForAnswer(string $line): bool
    {
        return (bool)preg_match(self::QUESTION, $line);
    }

    /**
     * Fingerprint of the keyring woob asks to trust (the last one it showed).
     *
     * @param string $output Output of woob so far
     * @return string|null The fingerprint, null if woob showed none
     */
    public static function askedFingerprint(string $output): ?string
    {
        if (!preg_match_all(self::FINGERPRINT, $output, $m)) {
            return null;
        }
        return strtolower((string)end($m[1]));
    }

    /**
     * Read the outputs of woob until it ends, giving each line to the callback and
     * answering its question about the keyring.
     *
     * @param array<int, resource>  $open        Pipes to read: 1 (stdout), 2 (stderr)
     * @param array<string, mixed>  $state       Output, pending lines, question and answer (updated)
     * @param string|null           $fingerprint Fingerprint confirmed by the administrator
     * @param callable              $onMessage   Receives each line
     * @return void
     */
    private static function read(array $open, array &$state, ?string $fingerprint, callable $onMessage): void
    {
        while (!empty($open)) {
            $read = array_values($open);
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, self::HEARTBEAT_INTERVAL) === 0) {
                Webservice::sendHeartbeat();
                continue;
            }
            foreach ($open as $index => $pipe) {
                if (in_array($pipe, $read, true)) {
                    self::readChunk($index, $open, $state, $onMessage);
                }
            }
            self::answerQuestion($state, $fingerprint, $onMessage);
        }
    }

    /**
     * Read what a pipe has, give its complete lines to the callback, close it at its end.
     *
     * @param int                   $index     Index of the pipe
     * @param array<int, resource>  $open      Pipes still open (updated)
     * @param array<string, mixed>  $state     Output and pending lines (updated)
     * @param callable              $onMessage Receives each line
     * @return void
     */
    private static function readChunk(int $index, array &$open, array &$state, callable $onMessage): void
    {
        $chunk = fread($open[$index], 8192);
        if ($chunk === false || ($chunk === '' && feof($open[$index]))) {
            fclose($open[$index]);
            unset($open[$index]);
            $chunk = "\n";
        }
        $state['output'] .= $chunk;
        $state['pending'][$index] .= $chunk;
        // A progress bar rewrites its line with \r: each version is a line
        $parts = preg_split('/[\r\n]/', $state['pending'][$index]) ?: [];
        $state['pending'][$index] = (string)array_pop($parts);
        foreach ($parts as $line) {
            $line = trim((string)preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $line));
            if ($line !== '') {
                $onMessage(mb_substr($line, 0, 300));
            }
        }
    }

    /**
     * Answer the question of woob, once: yes only to trust the keyring whose fingerprint
     * is the one confirmed by the administrator, no to anything else (woob would wait
     * forever). Its standard input is then closed.
     *
     * @param array<string, mixed> $state       Output, pending lines, question and answer (updated)
     * @param string|null          $fingerprint Fingerprint confirmed by the administrator
     * @param callable             $onMessage   Receives the answer given
     * @return void
     */
    private static function answerQuestion(array &$state, ?string $fingerprint, callable $onMessage): void
    {
        if ($state['answer'] !== null || !is_resource($state['stdin'])
            || !(self::waitsForAnswer($state['pending'][1]) || self::waitsForAnswer($state['pending'][2]))) {
            return;
        }
        $asked = self::askedFingerprint($state['output']);
        $state['asked'] = $asked;
        $state['answer'] = $asked === null ? 'n' : self::answer($asked, $fingerprint);
        $onMessage($state['answer'] === 'y' ? "Repository trusted: $asked" : "Repository not trusted: " . ($asked ?? 'unknown question'));
        Logger::info("woob asked to trust the keyring " . ($asked ?? '?') . " of " . self::repository() . ": "
            . ($state['answer'] === 'y' ? 'trusted' : 'refused'));
        fwrite($state['stdin'], $state['answer'] . "\n");
        fclose($state['stdin']);
    }

    /**
     * Keyring of the repository already trusted by woob.
     *
     * @return string|null Its file, null if the repository is not trusted yet
     */
    private static function trustedKeyring(): ?string
    {
        $dir = self::$keyringDir ?? rtrim((string)Woob::home(), '/') . '/.local/share/woob/keyrings';
        // Named after the URL of the repository by woob ("https___updates_woob_tech_3_main_.gpg")
        $file = $dir . '/' . preg_replace('/[^A-Za-z0-9]/', '_', self::repository()) . '.gpg';
        if (is_file($file)) {
            return $file;
        }
        $any = glob($dir . '/*.gpg') ?: [];
        return $any[0] ?? null;
    }

    /**
     * Fingerprint of the keyring woob would ask to trust (SHA-1 of trusted.gpg).
     *
     * @return array{0: string|null, 1: string|null} The fingerprint, or null and the error
     */
    private static function remoteFingerprint(): array
    {
        $url = self::repository() . 'trusted.gpg';
        $context = stream_context_create(['http' => ['timeout' => 10, 'follow_location' => 0]]);
        $keyring = @file_get_contents($url, false, $context);
        if ($keyring === false || $keyring === '') {
            Logger::warn("Keyring of the woob repository not downloaded: $url");
            return [null, "Keyring of the woob repository not downloaded ($url)"];
        }
        return [sha1($keyring), null];
    }
}
