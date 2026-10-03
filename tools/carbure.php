<?php

/**
 * carbure.php
 *
 * Maintenance of a Carbure instance from the command line. The installation
 * itself is done in the portal (installation wizard of the first start).
 *
 *   php tools/carbure.php add-bank            Configure a bank with woob and follow its accounts
 *   php tools/carbure.php rotate-jwt-secret   New random jwtsecret (every session must log in again)
 *
 * Run it as the user of the web server (e.g. sudo -u www-data, or
 * docker compose exec -it -u www-data carbure php tools/carbure.php add-bank):
 * it is the one running woob during the synchronizations.
 *
 * Options:
 *   --env=prod                 Configuration file: conf/<env>.ini
 *   --no-interaction           Never ask, use the options
 *   --bank-module=bnp          woob module of the bank ("list" shows them)
 *   --bank-backend=bnp         Name of the woob backend (default: the module name)
 *   --bank-accounts=all        Accounts to follow: "all" or numbers ("1,3")
 *   --bank-owner=admin         Carbure user recorded as having added the accounts (default: first
 *                              administrator); the accounts are shared by the household
 *   Interactively, woob asks for the bank credentials itself: they are kept by woob,
 *   never by Carbure. Without a terminal, the backend must already be configured.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once "$root/src/lib/Installer.php";

// Command and --name=value options, in any order
$command = 'help';
$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $argument, $match)) {
        $options[$match[1]] = $match[2] ?? false;
    } else {
        $command = $argument;
    }
}
$interactive = !isset($options['no-interaction']) && stream_isatty(STDIN);
$env = preg_replace('/[^a-z0-9_-]/i', '', $options['env'] ?? 'prod');
$configFile = "$root/conf/$env.ini";

/**
 * Print a step result.
 */
function step($message, $ok = true)
{
    echo ($ok ? "  \033[32m✔\033[0m " : "  \033[31m✘\033[0m ") . $message . "\n";
}

/**
 * Stop with an error.
 */
function fail($message)
{
    step($message, false);
    exit(1);
}

/**
 * Value of an option, asked interactively when missing.
 */
function ask($options, $interactive, $name, $question, $default = '', $secret = false)
{
    if (array_key_exists($name, $options)) {
        return (string)$options[$name];
    }
    if (!$interactive) {
        return $default;
    }
    $suffix = $default !== '' && !$secret ? " [$default]" : '';
    echo "  $question$suffix : ";
    if ($secret && DIRECTORY_SEPARATOR === '/') {
        shell_exec('stty -echo');
    }
    $answer = trim((string)fgets(STDIN));
    if ($secret && DIRECTORY_SEPARATOR === '/') {
        shell_exec('stty echo');
        echo "\n";
    }
    return $answer === '' ? $default : $answer;
}

/**
 * woob command line, from the woob_path setting of the instance.
 * An interactive call through "docker run" needs a terminal (-it).
 */
function woobCommand($woobPath, $arguments, $interactive = false)
{
    $command = trim($woobPath);
    if ($interactive && preg_match('/^docker run /', $command) && !preg_match('/\s-(it|ti)\b/', $command)) {
        $command = preg_replace('/^docker run /', 'docker run -it ', $command);
    }
    return "$command $arguments";
}

/**
 * Run a command attached to the terminal (woob asks its own questions).
 */
function runInteractive($command)
{
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    return is_resource($process) ? proc_close($process) : 1;
}

/**
 * Run a command and return [stdout, stderr, exit code].
 */
function capture($command)
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return ['', 'Cannot run: ' . $command, 1];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [$stdout, $stderr, proc_close($process)];
}

/**
 * Bank accounts of a woob backend, from "woob bank list -f json"
 * (one JSON value per line: an account or an array of accounts).
 */
function woobAccounts($stdout)
{
    $accounts = [];
    foreach (array_filter(array_map('trim', explode("\n", $stdout))) as $line) {
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            continue;
        }
        foreach (isset($decoded['id']) ? [$decoded] : $decoded as $account) {
            if (is_array($account) && !empty($account['id'])) {
                $accounts[] = $account;
            }
        }
    }
    return $accounts;
}

/**
 * Configure a bank backend with woob, then add the chosen accounts to Carbure.
 */
function setupBank(mysqli $db, $woobPath, $options, $interactive)
{
    echo "\nBank accounts\n";

    $module = ask($options, $interactive, 'bank-module', 'woob bank module (e.g. bnp, creditmutuel; "list" shows them, empty: skip)', '');
    while ($interactive && $module === 'list') {
        runInteractive(woobCommand($woobPath, 'config modules CapBank'));
        $module = ask([], $interactive, 'bank-module', 'woob bank module (empty: skip)', '');
    }
    $backend = ask($options, $interactive, 'bank-backend', 'woob backend name', $module);
    if ($backend === '') {
        step("no bank configured (later: php tools/carbure.php add-bank)");
        return;
    }
    if (!preg_match('/^[a-z0-9_-]+$/i', $backend) || ($module !== '' && !preg_match('/^[a-z0-9_]+$/i', $module))) {
        fail("Invalid woob module or backend name");
    }

    // woob asks for the credentials itself and keeps them in its own configuration
    if ($interactive && $module !== '') {
        echo "  woob now asks for your bank credentials (kept by woob, never by Carbure).\n";
        if (runInteractive(woobCommand($woobPath, "config add $module $backend", true)) !== 0) {
            step("woob could not create the backend \"$backend\" (it may already exist)", false);
        }
    }

    [$stdout, $stderr] = capture(woobCommand($woobPath, "bank list -b $backend -f json"));
    $accounts = woobAccounts($stdout);
    if (!$accounts) {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $stderr))));
        step("no account returned by woob for \"$backend\"" . ($lines ? ': ' . end($lines) : ''), false);
        return;
    }

    foreach ($accounts as $i => $account) {
        $balance = isset($account['balance']) ? ' ' . $account['balance'] . ' ' . ($account['currency'] ?? '') : '';
        printf("  %d) %s (%s)%s\n", $i + 1, $account['label'] ?? '?', $account['id'], $balance);
    }
    $choice = ask($options, $interactive, 'bank-accounts', 'Accounts to follow ("all" or numbers like 1,3)', 'all');
    $selected = $choice === 'all' ? array_keys($accounts)
        : array_filter(array_map(fn($n) => (int)trim($n) - 1, explode(',', $choice)), fn($i) => isset($accounts[$i]));

    // User recorded as having added the accounts: a given user, or the first administrator
    $owner = $options['bank-owner'] ?? null;
    $stmt = $owner === null
        ? $db->prepare("SELECT id, username FROM users WHERE is_admin = 1 ORDER BY id LIMIT 1")
        : $db->prepare("SELECT id, username FROM users WHERE username = ?");
    if ($owner !== null) {
        $stmt->bind_param('s', $owner);
    }
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user) {
        fail("Carbure user not found" . ($owner !== null ? ": $owner" : ''));
    }

    foreach ($selected as $i) {
        // woob ids are "<account>@<backend>"
        $number = substr($accounts[$i]['id'], 0, strrpos($accounts[$i]['id'], '@') ?: strlen($accounts[$i]['id']));
        // An account is followed once per household
        $exists = $db->prepare("SELECT id FROM bank_account WHERE account_number = ? AND bank_name = ?");
        $exists->bind_param('ss', $number, $backend);
        $exists->execute();
        if ($exists->get_result()->num_rows > 0) {
            step("account $number@$backend already followed by the household");
            continue;
        }
        $insert = $db->prepare("INSERT INTO bank_account (bank_name, account_number, user_id) VALUES (?, ?, ?)");
        $insert->bind_param('ssi', $backend, $number, $user['id']);
        $insert->execute();
        step("account " . ($accounts[$i]['label'] ?? $number) . " followed (added by {$user['username']})");
    }
}

if (!in_array($command, ['add-bank', 'rotate-jwt-secret'], true) || isset($options['help'])) {
    echo preg_replace('/^ \* ?/m', '', explode('*/', explode('/**', file_get_contents(__FILE__), 2)[1])[0]);
    exit($command === 'help' || isset($options['help']) ? 0 : 1);
}

$config = is_file($configFile) ? parse_ini_file($configFile) : false;
if (!$config) {
    fail("conf/$env.ini not found: install Carbure first, from the portal");
}

// New jwtsecret, nothing else is changed
if ($command === 'rotate-jwt-secret') {
    $ini = Installer::setIni(file_get_contents($configFile), 'jwtsecret', bin2hex(random_bytes(32)));
    if (file_put_contents($configFile, $ini) === false) {
        fail("Cannot write conf/$env.ini");
    }
    step("new jwtsecret in conf/$env.ini: every user (portal and iOS app) must log in again");
    exit(0);
}

// Configure a bank with woob and follow its accounts
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli($config['db_hostname'], $config['db_username'], $config['db_password'],
        $config['db_name'], (int)($config['db_port'] ?? 3306));
    $db->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    fail("Database error: " . $e->getMessage());
}
setupBank($db, $config['woob_path'] ?? 'woob', $options, $interactive);
