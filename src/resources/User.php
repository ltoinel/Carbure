<?php

/**
 * User.php
 *
 * User management
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

final class User {

    /**
     * Supported interface languages
     */
    private const LANGUAGES = ['fr', 'en'];

    /**
     * Public columns of a user (never the password)
     */
    private const COLUMNS = "id, username, firstname, lastname, email, is_admin, language, alert_threshold";

    /**
     * Login.
     *
     * @param string $username The username
     * @param string $password The password
     * @param array|null $device Associative array with 'name' and 'token' (mobile app only)
     * @return array The JWT token data
     * @throws Error If the credentials are invalid
     */
    #[ApiRoute('/user/login', method: 'POST', public: true)]
    public static function login($username, $password, ?array $device = null){

        // check if the $username and the $password are valid in database
        $sql = "SELECT * FROM users where username=?";
        $user = Db::queryOne($sql, "s", $username);

        if (!$user || !self::verifyPassword($user, $password)) {
            throw new Error("Invalid username or password", 401);
        }

        // Update or create the device (the web portal does not send one)
        if (!empty($device['token'])) {
            Device::createOrUpdate($user['id'], $device['name'] ?? '', $device['token']);
        }
             
        $token = Jwt::createJwt($user['id']);
        return $token;

    }

    /**
     * Hash a password.
     *
     * @param string $password The clear password
     * @return string The password hash
     */
    private static function hashPassword($password)
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Check a password against the stored hash.
     * Legacy salted SHA256 hashes are upgraded to password_hash() on success.
     *
     * @param array  $user     The user row from database
     * @param string $password The clear password
     * @return bool True if the password is valid
     */
    private static function verifyPassword($user, $password)
    {
        $hash = $user['password'];

        // Legacy hash: salted SHA256 (64 hex chars)
        if (preg_match('/^[a-f0-9]{64}$/', $hash)) {
            $legacy = hash('sha256', Config::get('password_salt') . $password);
            if (!hash_equals($hash, $legacy)) {
                return false;
            }
            Db::execute("UPDATE users SET password=? WHERE id=?", "si", self::hashPassword($password), $user['id']);
            Logger::info("Password hash upgraded for user " . $user['id']);
            return true;
        }

        if (!password_verify($password, $hash)) {
            return false;
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            Db::execute("UPDATE users SET password=? WHERE id=?", "si", self::hashPassword($password), $user['id']);
        }

        return true;
    }

    /**
     * Check if the authenticated user is an administrator.
     *
     * @return bool True if the user is an administrator
     */
    private static function isAdmin()
    {
        $user = Db::queryOne("SELECT is_admin FROM users WHERE id=?", "i", Jwt::getUserIdFromToken());
        return $user !== null && (bool)$user['is_admin'];
    }

    /**
     * Require the authenticated user to be an administrator.
     *
     * @return void
     * @throws Error If the user is not an administrator
     */
    private static function requireAdmin()
    {
        if (!self::isAdmin()) {
            throw new Error("Forbidden - Administrator only", 403);
        }
    }

    /**
     * Get all users (administrator) or the authenticated user only.
     * @return array List of users (without passwords)
     */
    #[ApiRoute('/user', method: 'GET')]
    public static function get()
    {
        $sql = "SELECT " . self::COLUMNS . " FROM users";
        if (self::isAdmin()) {
            $stmt = Db::execute($sql . " ORDER BY username", "");
        } else {
            $stmt = Db::execute($sql . " WHERE id=?", "i", Jwt::getUserIdFromToken());
        }
        $result = $stmt->get_result();

        $users = array();
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
        }

        return $users;
    }

    /**
     * Get the authenticated user profile.
     * @return array The user (without password)
     */
    #[ApiRoute('/user/me', method: 'GET')]
    public static function me()
    {
        $sql = "SELECT " . self::COLUMNS . " FROM users WHERE id=?";
        $user = Db::queryOne($sql, "i", Jwt::getUserIdFromToken());

        if (!$user) {
            throw new Error("User not found", 404);
        }

        return $user;
    }

    /**
     * Create a new user.
     *
     * @param string      $username  The username
     * @param string      $password  The password (will be hashed)
     * @param string      $email     The email
     * @param string|null $firstname The first name (optional)
     * @param string|null $lastname  The last name (optional)
     * @return array The created user data with id
     * @throws Error If the username or email already exists or the caller is not an administrator
     */
    #[ApiRoute('/user', method: 'POST')]
    public static function create($username, $password, $email, $firstname = null, $lastname = null)
    {
        self::requireAdmin();

        // Check if username already exists
        $sql = "SELECT id FROM users WHERE username=?";
        $stmt = Db::execute($sql, "s", $username);
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            throw new Error("Username already exists");
        }

        // Check if email already exists
        $sql = "SELECT id FROM users WHERE email=?";
        $stmt = Db::execute($sql, "s", $email);
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            throw new Error("Email already exists");
        }

        // Hash the password
        $hashedPassword = self::hashPassword($password);

        // Insert the new user
        $sql = "INSERT INTO users (username, password, email, firstname, lastname) VALUES (?, ?, ?, ?, ?)";
        $stmt = Db::execute($sql, "sssss", $username, $hashedPassword, $email, $firstname, $lastname);

        $userId = $stmt->insert_id;

        return array(
            'id' => $userId,
            'username' => $username,
            'email' => $email,
            'firstname' => $firstname,
            'lastname' => $lastname
        );
    }

    /**
     * Delete a user by id.
     *
     * @param int $id The user id to delete
     * @return bool True if deleted successfully
     * @throws Error If the user is not found or the caller is not an administrator
     */
    #[ApiRoute('/user', method: 'DELETE')]
    public static function delete($id)
    {
        self::requireAdmin();

        if ((int)$id === (int)Jwt::getUserIdFromToken()) {
            throw new Error("You cannot delete your own account", 400);
        }

        // Check if user exists
        $sql = "SELECT id FROM users WHERE id=?";
        $stmt = Db::execute($sql, "i", $id);
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            throw new Error("User not found", 404);
        }

        // The household transactions are attached to their owner
        $owned = Db::queryOne("SELECT COUNT(*) AS count FROM bank_transaction WHERE user=?", "i", $id);
        if ($owned['count'] > 0) {
            throw new Error("User owns bank transactions and cannot be deleted", 409);
        }

        // Delete the user
        $sql = "DELETE FROM users WHERE id=?";
        Db::execute($sql, "i", $id);

        return true;
    }

    /**
     * Update a user.
     *
     * @param int         $id        The user id to update
     * @param string|null $email     The email (optional)
     * @param string|null $firstname The first name (optional)
     * @param string|null $lastname  The last name (optional)
     * @param string|null $password  The new password (optional, will be hashed)
     * @param string|null $language  The interface language (optional, fr|en)
     * @param float|string|null $alertThreshold Push alert threshold for new expenses
     *                                    (optional, "" or 0 disables the alerts)
     * @return array The updated user data
     * @throws Error If the user is not found, email already exists or the caller is not allowed
     */
    #[ApiRoute('/user', method: 'PUT')]
    public static function update($id, $email = null, $firstname = null, $lastname = null, $password = null, $language = null, $alertThreshold = null)
    {
        // A user can only update his own profile, unless administrator
        if ((int)$id !== (int)Jwt::getUserIdFromToken()) {
            self::requireAdmin();
        }

        // Check if user exists
        $sql = "SELECT * FROM users WHERE id=?";
        $stmt = Db::execute($sql, "i", $id);
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            throw new Error("User not found");
        }

        $currentUser = $result->fetch_assoc();

        // Check if email already exists (for another user)
        if ($email && $email !== $currentUser['email']) {
            $sql = "SELECT id FROM users WHERE email=? AND id!=?";
            $stmt = Db::execute($sql, "si", $email, $id);
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                throw new Error("Email already exists");
            }
        }

        // Build update query dynamically based on provided parameters
        $updateFields = array();
        $types = "";
        $params = array();

        if ($email !== null) {
            $updateFields[] = "email=?";
            $types .= "s";
            $params[] = $email;
        }

        if ($firstname !== null) {
            $updateFields[] = "firstname=?";
            $types .= "s";
            $params[] = $firstname;
        }

        if ($lastname !== null) {
            $updateFields[] = "lastname=?";
            $types .= "s";
            $params[] = $lastname;
        }

        if ($language !== null) {
            if (!in_array($language, self::LANGUAGES, true)) {
                throw new Error("Unsupported language: $language", 400);
            }
            $updateFields[] = "language=?";
            $types .= "s";
            $params[] = $language;
        }

        if ($alertThreshold !== null) {
            if ($alertThreshold !== '' && (!is_numeric($alertThreshold) || $alertThreshold < 0)) {
                throw new Error("The alert threshold must be a positive amount", 400);
            }
            $updateFields[] = "alert_threshold=?";
            $types .= "d";
            // "" or 0 disables the alerts
            $params[] = ($alertThreshold === '' || (float)$alertThreshold == 0) ? null : round((float)$alertThreshold, 2);
        }

        if ($password !== null && $password !== '') {
            $hashedPassword = self::hashPassword($password);
            $updateFields[] = "password=?";
            $types .= "s";
            $params[] = $hashedPassword;
        }

        // Execute update if there is something to update
        if (!empty($updateFields)) {
            $types .= "i";
            $params[] = $id;

            $sql = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE id=?";
            Db::execute($sql, $types, ...$params);
        }

        // Get updated user data
        $sql = "SELECT " . self::COLUMNS . " FROM users WHERE id=?";
        $stmt = Db::execute($sql, "i", $id);
        $result = $stmt->get_result();
        $updatedUser = $result->fetch_assoc();

        return $updatedUser;
    }
}
