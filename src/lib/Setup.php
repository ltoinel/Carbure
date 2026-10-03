<?php

/**
 * Setup.php
 *
 * Installation wizard of the portal: as long as the configuration file
 * (conf/<env>.ini) does not exist, the API only answers /api/setup:
 *
 *   GET  /api/setup           State of the installation and default values
 *   POST /api/setup/database  Test the database and tell what the installation will do
 *   POST /api/setup/install   Install or migrate the schema, create the first
 *                             administrator and write the configuration
 *
 * From the local network (a NAS at home), anyone reaching the portal can install
 * it, like most self-hosted applications. From Internet, or when conf/setup.code
 * exists, the POST requests require the setup code of this file (created on the
 * first request, and printed in the logs of the Docker container).
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

final class Setup {

    private string $root;
    private string $configFile;
    private string $codeFile;
    private string $clientAddress;

    /**
     * @param string $root       Root of the project
     * @param string $configFile Configuration file to create
     * @param string $codeFile   File of the setup code
     * @param string $clientAddress IP address of the client
     */
    public function __construct($root, $configFile, $codeFile, $clientAddress = '127.0.0.1')
    {
        $this->root = $root;
        $this->configFile = $configFile;
        $this->codeFile = $codeFile;
        $this->clientAddress = $clientAddress;
    }

    /**
     * Answer the current HTTP request (the configuration does not exist).
     *
     * @param string $root Root of the project
     * @param string $env  Name of the configuration (prod)
     * @return void
     */
    public static function serve($root, $env)
    {
        $setup = new self($root, "$root/conf/$env.ini", "$root/conf/setup.code", $_SERVER['REMOTE_ADDR'] ?? '');
        $path = preg_replace('#^/api#', '', strtok($_SERVER['REQUEST_URI'] ?? '/', '?'));
        $data = json_decode(file_get_contents('php://input') ?: '[]', true);

        [$status, $body] = $setup->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $path, is_array($data) ? $data : []);

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Handle a request of the wizard.
     *
     * @param string $method HTTP method
     * @param string $path   Path without /api
     * @param array  $data   JSON body
     * @return array [HTTP status, response]
     */
    public function handle($method, $path, $data)
    {
        try {
            if ($path === '/setup' && $method === 'GET') {
                return [200, $this->status()];
            }
            // Healthy while waiting for the installation (Docker HEALTHCHECK)
            if ($path === '/health' && $method === 'GET') {
                return [200, ['status' => 'setup']];
            }
            if ($path === '/setup/database' && $method === 'POST') {
                $this->checkCode($data);
                return [200, $this->database($data)];
            }
            if ($path === '/setup/install' && $method === 'POST') {
                $this->checkCode($data);
                return [200, $this->install($data)];
            }
            return [503, ['error' => 'Carbure is not installed yet: open the portal to install it', 'code' => 503, 'setup' => true]];
        } catch (SetupError $e) {
            return [$e->getCode(), ['error' => $e->getMessage(), 'code' => $e->getCode()]];
        } catch (mysqli_sql_exception $e) {
            return [400, ['error' => 'Database: ' . $e->getMessage(), 'code' => 400]];
        } catch (InvalidArgumentException | RuntimeException $e) {
            return [400, ['error' => $e->getMessage(), 'code' => 400]];
        }
    }

    /**
     * State of the wizard and default values (environment of the Docker container).
     *
     * @return array setup, codeFile, defaults
     */
    private function status()
    {
        $codeRequired = $this->codeRequired();
        if ($codeRequired) {
            $this->code();
        }
        return [
            'setup' => true,
            'codeRequired' => $codeRequired,
            // Docker: the database password is given by the environment
            'dbPasswordFromEnvironment' => getenv('DB_PASSWORD') !== false && getenv('DB_PASSWORD') !== '',
            'codeFile' => 'conf/setup.code',
            'defaults' => [
                'db_host' => getenv('DB_HOST') ?: 'localhost',
                'db_port' => (int)(getenv('DB_PORT') ?: 3306),
                'db_name' => getenv('DB_NAME') ?: 'carbure',
                'db_user' => getenv('DB_USER') ?: 'carbure',
                'admin_user' => 'admin',
                'language' => in_array(getenv('LANGUAGE'), ['fr', 'en'], true) ? getenv('LANGUAGE') : 'fr',
            ],
        ];
    }

    /**
     * Connect to the database and tell what the installation will do.
     *
     * @param array $data db_host, db_port, db_name, db_user, db_password
     * @return array state (none|current|outdated), version, pending, hasAdmin
     */
    private function database($data)
    {
        $db = $this->connect($data);
        return Installer::state($db, $this->root);
    }

    /**
     * Install: schema (created, or migrated after confirmation), first
     * administrator if none, configuration file; the setup code is then deleted.
     *
     * @param array $data Database fields, admin_user, admin_password, admin_email,
     *                    language, backup_confirmed (to apply migrations)
     * @return array done, version, username
     * @throws SetupError If a migration is not confirmed or the configuration cannot be written
     */
    private function install($data)
    {
        $db = $this->connect($data);
        $state = Installer::state($db, $this->root);

        if ($state['state'] === 'outdated' && empty($data['backup_confirmed'])) {
            throw new SetupError("Confirm that the database is saved before applying the migrations", 409);
        }
        $needsAdmin = !$state['hasAdmin'];
        if ($needsAdmin && strlen((string)($data['admin_password'] ?? '')) < 8) {
            throw new SetupError("The administrator password must contain at least 8 characters", 400);
        }
        if (!is_writable(dirname($this->configFile)) && !is_writable($this->configFile)) {
            throw new SetupError("The web server cannot write in conf/: give it the write permission", 500);
        }

        if ($state['state'] === 'none') {
            Installer::installSchema($db, $this->root);
        } elseif ($state['state'] === 'outdated') {
            Installer::migrate($db, $this->root);
        } else {
            Installer::defaultCategory($db);
        }

        $username = null;
        if ($needsAdmin) {
            $username = trim((string)($data['admin_user'] ?? 'admin'));
            Installer::createAdmin($db, $username, $data['admin_password'], $data['admin_email'] ?? null, $data['language'] ?? 'fr');
        }

        [$ini] = Installer::config($this->root, [
            'db_hostname' => $data['db_host'],
            'db_port' => (string)(int)($data['db_port'] ?? 3306),
            'db_username' => $data['db_user'],
            'db_password' => $this->password($data),
            'db_name' => $data['db_name'],
            'woob_path' => getenv('CARBURE_WOOB_PATH') ?: 'woob',
        ]);
        if (@file_put_contents($this->configFile, $ini) === false) {
            throw new SetupError("Cannot write the configuration file: check the permissions of conf/", 500);
        }
        @chmod($this->configFile, 0640);
        @unlink($this->codeFile);

        return ['done' => true, 'version' => (new Migrator($db, "$this->root/sql/migrations"))->version(), 'username' => $username];
    }

    /**
     * Connect with the database fields of the request.
     *
     * @param array $data db_host, db_port, db_name, db_user, db_password
     * @return mysqli The connection
     */
    private function connect($data)
    {
        foreach (['db_host', 'db_name', 'db_user'] as $field) {
            if (empty($data[$field]) || !is_string($data[$field])) {
                throw new SetupError("Missing field: $field", 400);
            }
        }
        return Installer::connect($data['db_host'], (int)($data['db_port'] ?? 3306), $data['db_name'], $data['db_user'], $this->password($data));
    }

    /**
     * Password of the database: the one typed, or the one of the environment
     * (Docker) for the database of the environment only.
     *
     * @param array $data Database fields
     * @return string The password
     */
    private function password($data)
    {
        $typed = (string)($data['db_password'] ?? '');
        $environment = getenv('DB_PASSWORD');
        if ($typed === '' && $environment !== false
            && $data['db_host'] === (getenv('DB_HOST') ?: 'localhost')
            && $data['db_user'] === (getenv('DB_USER') ?: 'carbure')
            && $data['db_name'] === (getenv('DB_NAME') ?: 'carbure')) {
            return $environment;
        }
        return $typed;
    }

    /**
     * The setup code, created on first use.
     *
     * @return string The code
     * @throws SetupError If the code cannot be written
     */
    public function code()
    {
        if (is_file($this->codeFile)) {
            return trim(file_get_contents($this->codeFile));
        }
        // No ambiguous characters (0/O, 1/I/L): it is typed by hand
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 12; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        if (@file_put_contents($this->codeFile, $code . "\n") === false) {
            throw new SetupError("Cannot write conf/setup.code: the web server must be able to write in conf/", 500);
        }
        @chmod($this->codeFile, 0600);
        // Shown in the logs of the server (docker logs)
        error_log("Carbure: installation code $code (also in conf/setup.code)");
        return $code;
    }

    /**
     * The setup code is required from Internet, or when the file exists (set by
     * the administrator of the server, e.g. the Docker container).
     *
     * @return bool True if required
     */
    private function codeRequired()
    {
        if (is_file($this->codeFile)) {
            return true;
        }
        $local = filter_var($this->clientAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            && filter_var($this->clientAddress, FILTER_VALIDATE_IP) !== false;
        return !$local;
    }

    /**
     * Check the setup code of a request (slowed down when wrong).
     *
     * @param array $data The request with code
     * @return void
     * @throws SetupError If the code is wrong
     */
    private function checkCode($data)
    {
        if (!$this->codeRequired()) {
            return;
        }
        $given = strtoupper(preg_replace('/\s+/', '', (string)($data['code'] ?? '')));
        if ($given === '' || !hash_equals($this->code(), $given)) {
            usleep(500000);
            throw new SetupError("Wrong setup code: see conf/setup.code on the server, or the logs of the Docker container", 403);
        }
    }
}

/**
 * Error of the wizard, with its HTTP status as code.
 */
final class SetupError extends RuntimeException {
}
