<?php

/**
 * install.php
 *
 * Carbure installer: creates the MySQL/MariaDB schema, the first administrator
 * and the configuration file of the instance (conf/<env>.ini) with random secrets.
 *
 * Interactive:      php tools/install.php
 * Non interactive:  php tools/install.php --no-interaction --db-password=... --admin-password=...
 *
 * Options (all optional, asked interactively when missing):
 *   --env=prod                 Configuration file to create: conf/<env>.ini
 *   --db-host=localhost        --db-port=3306      --db-name=carbure
 *   --db-user=carbure          --db-password=
 *   --db-root-user=            --db-root-password=  Account used to create the database and
 *                                                   the user (skip if they already exist)
 *   --db-user-host=%           Host the database user may connect from (% = any host;
 *                              localhost is added when the database is local)
 *   --admin-user=admin         --admin-password=    --admin-email=
 *   --language=fr              Language of the first user (fr|en)
 *   --woob-path=woob           Command used to run woob
 *   --force                    Overwrite an existing configuration file
 *   --no-interaction           Never ask, use the options and defaults
 *
 * Bank accounts (asked at the end of an interactive installation):
 *   --bank-module=bnp          woob module of the bank ("list" shows them)
 *   --bank-backend=bnp         Name of the woob backend (default: the module name)
 *   --bank-accounts=all        Accounts to follow: "all" or numbers ("1,3")
 *   --bank-owner=admin         Carbure user owning the accounts (default: first administrator)
 *   Interactively, woob asks for the bank credentials itself: they are kept by woob,
 *   never by Carbure. Without a terminal, the backend must already be configured.
 *
 * Maintenance of an existing instance:
 *   --add-bank                 Configure a bank with woob and add its accounts to Carbure
 *   --rotate-jwt-secret        Replace only the jwtsecret of conf/<env>.ini with a new random
 *                              value (every session, portal and iOS app, must log in again)
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

const MIN_PHP = '8.2.0';
const EXTENSIONS = ['mysqli', 'curl', 'openssl', 'json'];

$root = dirname(__DIR__);
$options = getopt('', [
    'env:', 'db-host:', 'db-port:', 'db-name:', 'db-user:', 'db-password:',
    'db-root-user:', 'db-root-password:', 'db-user-host:', 'admin-user:', 'admin-password:', 'admin-email:',
    'language:', 'woob-path:', 'force', 'no-interaction', 'rotate-jwt-secret', 'help',
    'add-bank', 'bank-module:', 'bank-backend:', 'bank-accounts:', 'bank-owner:',
]);

if (isset($options['help'])) {
    echo preg_replace('/^ \* ?/m', '', explode('*/', explode('/**', file_get_contents(__FILE__), 2)[1])[0]);
    exit(0);
}

$interactive = !isset($options['no-interaction']) && stream_isatty(STDIN);

// Maintenance: new jwtsecret for an existing instance, nothing else is changed
if (isset($options['rotate-jwt-secret'])) {
    $env = preg_replace('/[^a-z0-9_-]/i', '', $options['env'] ?? 'prod');
    $configFile = "$root/conf/$env.ini";
    if (!is_file($configFile)) {
        fail("conf/$env.ini not found");
    }
    $ini = setIni(file_get_contents($configFile), 'jwtsecret', bin2hex(random_bytes(32)));
    if (file_put_contents($configFile, $ini) === false) {
        fail("Cannot write conf/$env.ini");
    }
    step("new jwtsecret in conf/$env.ini: every user (portal and iOS app) must log in again");
    exit(0);
}

// Maintenance: configure a bank with woob and add its accounts to an existing instance
if (isset($options['add-bank'])) {
    $env = preg_replace('/[^a-z0-9_-]/i', '', $options['env'] ?? 'prod');
    $config = @parse_ini_file("$root/conf/$env.ini");
    if (!$config) {
        fail("conf/$env.ini not found: run the installation first");
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $db = new mysqli($config['db_hostname'], $config['db_username'], $config['db_password'],
            $config['db_name'], (int)($config['db_port'] ?? 3306));
        $db->set_charset('utf8mb4');
    } catch (mysqli_sql_exception $e) {
        fail("Database error: " . $e->getMessage());
    }
    setupBank($db, $config['woob_path'] ?? 'woob', $options, $interactive);
    exit(0);
}

/**
 * Print a step result.
 */
function step($message, $ok = true)
{
    echo ($ok ? "  \033[32m✔\033[0m " : "  \033[31m✘\033[0m ") . $message . "\n";
}

/**
 * Stop the installation with an error.
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
        step("no bank configured (later: php tools/install.php --add-bank)");
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

    // Owner of the accounts: a given user, or the first administrator
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
        $exists = $db->prepare("SELECT id FROM bank_account WHERE account_number = ? AND bank_name = ? AND user_id = ?");
        $exists->bind_param('ssi', $number, $backend, $user['id']);
        $exists->execute();
        if ($exists->get_result()->num_rows > 0) {
            step("account $number@$backend already followed by {$user['username']}");
            continue;
        }
        $insert = $db->prepare("INSERT INTO bank_account (bank_name, account_number, user_id) VALUES (?, ?, ?)");
        $insert->bind_param('ssi', $backend, $number, $user['id']);
        $insert->execute();
        step("account " . ($accounts[$i]['label'] ?? $number) . " followed by {$user['username']}");
    }
}

/**
 * Run a SQL script (several statements).
 */
function runScript(mysqli $db, $sql)
{
    $db->multi_query($sql);
    do {
        if ($result = $db->store_result()) {
            $result->free();
        }
    } while ($db->more_results() && $db->next_result());
}

/**
 * Set "key=value" in an INI content (the key line must exist, commented or not).
 */
function setIni($ini, $key, $value)
{
    $quoted = preg_match('/^[A-Za-z0-9_.\/:@-]*$/', $value) ? $value : '"' . str_replace('"', '', $value) . '"';
    $count = 0;
    $ini = preg_replace('/^;?\s*' . preg_quote($key, '/') . '\s*=.*$/m', $key . '=' . $quoted, $ini, 1, $count);
    if ($count === 0) {
        $ini .= "\n$key=$quoted\n";
    }
    return $ini;
}

echo "\n\033[1mCarbure - installation\033[0m\n\n";

// 1. Prerequisites
echo "Prerequisites\n";
if (version_compare(PHP_VERSION, MIN_PHP, '<')) {
    fail("PHP " . MIN_PHP . " or newer is required (current: " . PHP_VERSION . ")");
}
step("PHP " . PHP_VERSION);
foreach (EXTENSIONS as $extension) {
    extension_loaded($extension) ? step("extension $extension") : fail("PHP extension missing: $extension");
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// 2. Configuration file
$env = preg_replace('/[^a-z0-9_-]/i', '', $options['env'] ?? 'prod');
$configFile = "$root/conf/$env.ini";
if (file_exists($configFile) && !isset($options['force'])) {
    fail("conf/$env.ini already exists (use --force to overwrite it, or --env=<name>)");
}

// 3. Database
echo "\nDatabase\n";
$dbHost = ask($options, $interactive, 'db-host', 'MySQL/MariaDB host', 'localhost');
$dbPort = (int)ask($options, $interactive, 'db-port', 'Port', '3306');
$dbName = ask($options, $interactive, 'db-name', 'Database name', 'carbure');
$dbUser = ask($options, $interactive, 'db-user', 'Database user', 'carbure');
$dbPassword = ask($options, $interactive, 'db-password', 'Password of this user (empty: generated)', '', true);
$rootUser = ask($options, $interactive, 'db-root-user', 'Admin account to create the database and the user (empty: they already exist)', '');
$rootPassword = $rootUser !== '' ? ask($options, $interactive, 'db-root-password', "Password of $rootUser", '', true) : '';

if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName) || !preg_match('/^[A-Za-z0-9_.-]+$/', $dbUser)) {
    fail("Invalid database or user name");
}
if ($dbPassword === '') {
    $dbPassword = bin2hex(random_bytes(16));
}

try {
    if ($rootUser !== '') {
        $admin = new mysqli($dbHost, $rootUser, $rootPassword, '', $dbPort);
        $admin->query("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        step("database $dbName");
        // A server in a container sees TCP clients with their network address, not
        // localhost: '%' by default, plus localhost (socket) for a local server
        $hosts = [$options['db-user-host'] ?? '%'];
        if (in_array($dbHost, ['localhost', '127.0.0.1'], true)) {
            $hosts[] = 'localhost';
        }
        $password = $admin->real_escape_string($dbPassword);
        foreach (array_unique($hosts) as $host) {
            $host = $admin->real_escape_string($host);
            $admin->query("CREATE USER IF NOT EXISTS '$dbUser'@'$host' IDENTIFIED BY '$password'");
            $admin->query("ALTER USER '$dbUser'@'$host' IDENTIFIED BY '$password'");
            $admin->query("GRANT ALL PRIVILEGES ON `$dbName`.* TO '$dbUser'@'$host'");
            step("user $dbUser@$host");
        }
        $admin->query("FLUSH PRIVILEGES");
        $admin->close();
    }

    $db = new mysqli($dbHost, $dbUser, $dbPassword, $dbName, $dbPort);
    $db->set_charset('utf8mb4');
    step("connection as $dbUser");
} catch (mysqli_sql_exception $e) {
    fail("Database error: " . $e->getMessage());
}

// 4. Schema and reference data
$tables = $db->query("SHOW TABLES LIKE 'users'")->num_rows;
require_once "$root/src/lib/Migrator.php";
$migrator = new Migrator($db, "$root/sql/migrations");
if ($tables > 0) {
    // Existing database: bring it up to date
    try {
        $applied = $migrator->migrate();
        step("schema already present, " . ($applied ? count($applied) . " migration(s) applied" : "up to date")
            . " (version " . ($migrator->version() ?? 'none') . ")");
    } catch (RuntimeException $e) {
        fail($e->getMessage());
    }
} else {
    try {
        runScript($db, file_get_contents("$root/sql/carbure.sql"));
        // sql/carbure.sql is up to date: every migration is already in it
        $migrator->baseline();
        step("schema imported (sql/carbure.sql), version " . $migrator->version());
    } catch (mysqli_sql_exception $e) {
        fail("Schema import failed: " . $e->getMessage());
    }
}

// Category 0 is the default category of the transactions
$db->query("SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_AUTO_VALUE_ON_ZERO')");
$db->query("SET FOREIGN_KEY_CHECKS=0");
$db->query("INSERT IGNORE INTO bank_transaction_category (id, name, parent_category, type, icon, color)
            VALUES (0, 'Non catégorisé', 0, 'HORS-BUDGET', 'help', '#9e9e9e')");
$db->query("SET FOREIGN_KEY_CHECKS=1");
step("default category");

// 5. First administrator
echo "\nAdministrator\n";
if ($db->query("SELECT id FROM users WHERE is_admin = 1 LIMIT 1")->num_rows > 0) {
    step("an administrator already exists: kept");
} else {
    $adminUser = ask($options, $interactive, 'admin-user', 'Login', 'admin');
    $adminPassword = ask($options, $interactive, 'admin-password', 'Password (8 characters minimum)', '', true);
    $adminEmail = ask($options, $interactive, 'admin-email', 'Email (optional)', '');
    $language = ask($options, $interactive, 'language', 'Language (fr|en)', 'fr');
    if (strlen($adminPassword) < 8) {
        fail("The administrator password must contain at least 8 characters");
    }
    $stmt = $db->prepare("INSERT INTO users (username, password, email, is_admin, language) VALUES (?, ?, ?, 1, ?)");
    $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
    $email = $adminEmail === '' ? null : $adminEmail;
    $language = in_array($language, ['fr', 'en'], true) ? $language : 'fr';
    $stmt->bind_param('ssss', $adminUser, $hash, $email, $language);
    $stmt->execute();
    step("administrator $adminUser");
}

// 6. Configuration file with random secrets
echo "\nConfiguration\n";
$woobPath = ask($options, $interactive, 'woob-path', 'Command to run woob', 'woob');
$syncToken = bin2hex(random_bytes(24));
$ini = file_get_contents("$root/conf/prod.sample.ini");
foreach ([
    'log_level' => 'warning',
    'development' => 'false',
    'db_hostname' => $dbHost,
    'db_port' => (string)$dbPort,
    'db_username' => $dbUser,
    'db_password' => $dbPassword,
    'db_name' => $dbName,
    'jwtsecret' => bin2hex(random_bytes(32)),
    'password_salt' => bin2hex(random_bytes(16)),
    'sync_token' => $syncToken,
    'woob_path' => $woobPath,
] as $key => $value) {
    $ini = setIni($ini, $key, $value);
}
if (file_put_contents($configFile, $ini) === false) {
    fail("Cannot write conf/$env.ini");
}
@chmod($configFile, 0640);
step("conf/$env.ini (random jwtsecret, password_salt and sync_token)");

// 7. Bank accounts: woob backend and accounts to synchronize
$configureBank = isset($options['bank-backend']) || isset($options['bank-module'])
    || ($interactive && strtolower(ask($options, $interactive, 'configure-bank', "\nConfigure a bank account now? (y/n)", 'y')) === 'y');
if ($configureBank) {
    setupBank($db, $woobPath, $options, $interactive);
}

echo "\n\033[1mDone.\033[0m Next steps:\n";
echo "  - Web server: route /api/* to src/api.php and serve portal/ (see the documentation)\n";
echo "  - Daily synchronization (cron): curl -s -H 'X-Sync-Token: $syncToken' https://<your-host>/api/bank/sync\n";
echo "  - More banks: php tools/install.php --add-bank (as the user running the web server)\n";
echo "  - Push notifications: [apns] in conf/$env.ini\n";
echo "  - Documentation: https://ltoinel.github.io/Carbure/\n\n";
