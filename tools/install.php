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
 *   --admin-user=admin         --admin-password=    --admin-email=
 *   --language=fr              Language of the first user (fr|en)
 *   --woob-path=woob           Command used to run woob
 *   --force                    Overwrite an existing configuration file
 *   --no-interaction           Never ask, use the options and defaults
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

const MIN_PHP = '8.2.0';
const EXTENSIONS = ['mysqli', 'curl', 'openssl', 'json'];

$root = dirname(__DIR__);
$options = getopt('', [
    'env:', 'db-host:', 'db-port:', 'db-name:', 'db-user:', 'db-password:',
    'db-root-user:', 'db-root-password:', 'admin-user:', 'admin-password:', 'admin-email:',
    'language:', 'woob-path:', 'force', 'no-interaction', 'help',
]);

if (isset($options['help'])) {
    echo preg_replace('/^ \* ?/m', '', explode('*/', explode('/**', file_get_contents(__FILE__), 2)[1])[0]);
    exit(0);
}

$interactive = !isset($options['no-interaction']) && stream_isatty(STDIN);

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
        $host = in_array($dbHost, ['localhost', '127.0.0.1'], true) ? 'localhost' : '%';
        $password = $admin->real_escape_string($dbPassword);
        $admin->query("CREATE USER IF NOT EXISTS '$dbUser'@'$host' IDENTIFIED BY '$password'");
        $admin->query("ALTER USER '$dbUser'@'$host' IDENTIFIED BY '$password'");
        $admin->query("GRANT ALL PRIVILEGES ON `$dbName`.* TO '$dbUser'@'$host'");
        $admin->query("FLUSH PRIVILEGES");
        step("user $dbUser@$host");
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
if ($tables > 0) {
    step("schema already present: kept as is (apply sql/migrations/*.sql if needed)");
} else {
    try {
        runScript($db, file_get_contents("$root/sql/carbure.sql"));
        step("schema imported (sql/carbure.sql)");
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

echo "\n\033[1mDone.\033[0m Next steps:\n";
echo "  - Web server: route /api/* to src/api.php and serve portal/ (see the documentation)\n";
echo "  - Daily synchronization (cron): curl -s -H 'X-Sync-Token: $syncToken' https://<your-host>/api/bank/sync\n";
echo "  - Bank accounts: add them to the bank_account table, push notifications: [apns] in conf/$env.ini\n";
echo "  - Documentation: https://ltoinel.github.io/Carbure/\n\n";
