<?php

/**
 * Category.php
 * *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

final class Category {

    /**
     * Get all the categories available.
     *
     * @return array The list of categories
     */
    #[ApiRoute('/category', method: 'GET')]
    public static function get()
    {
        // Get the category
        $sql = "SELECT * FROM bank_transaction_category";
        $result = Db::query($sql);

        $categories = array();

        // Collect the rows
        if ($result->num_rows > 0) {

            while ($row = $result->fetch_assoc()) {
                $categories[] = $row;
            }
        }

        return $categories;
    }

    /**
     * Types of category
     */
    private const TYPES = ['DEBIT', 'CREDIT', 'HORS-BUDGET'];

    /**
     * Validate the fields of a category and return them normalized.
     *
     * @param int|null $id     The category being modified (null for a new one)
     * @param string   $name   The name
     * @param int      $parent The parent category (0 for a top-level category)
     * @param string   $type   DEBIT, CREDIT or HORS-BUDGET
     * @param string   $icon   The icon (SF Symbols name used by the iOS app)
     * @param string   $color  The color (SwiftUI name or hex)
     * @return array [name, parent, type, icon, color]
     * @throws Error If a field is invalid
     */
    private static function validate($id, $name, $parent, $type, $icon, $color)
    {
        $name = trim((string)$name);
        $parent = (int)$parent;
        if ($name === '' || mb_strlen($name) > 50) {
            throw new Error("The name must contain 1 to 50 characters", 400);
        }
        if (!in_array($type, self::TYPES, true)) {
            throw new Error("Invalid category type", 400);
        }
        if (!preg_match('/^[a-z0-9._]{0,50}$/', (string)$icon) || !preg_match('/^(#[0-9a-fA-F]{3,8}|[a-zA-Z]{0,30})$/', (string)$color)) {
            throw new Error("Invalid icon or color", 400);
        }

        $duplicate = Db::queryOne("SELECT id FROM bank_transaction_category WHERE name = ? AND id <> ?", "si", $name, $id ?? -1);
        if ($duplicate) {
            throw new Error("A category with this name already exists", 409);
        }

        // One level of sub-categories: the parent must be a top-level category
        if ($parent !== 0) {
            $row = Db::queryOne("SELECT parent_category FROM bank_transaction_category WHERE id = ?", "i", $parent);
            if (!$row || (int)$row['parent_category'] !== 0 || $parent === (int)$id) {
                throw new Error("The parent must be a top-level category", 400);
            }
            if ($id !== null && Db::queryOne("SELECT id FROM bank_transaction_category WHERE parent_category = ? AND id <> ? LIMIT 1", "ii", $id, $id)) {
                throw new Error("A category with sub-categories cannot become a sub-category", 400);
            }
        }

        return [$name, $parent, $type, (string)$icon, (string)$color];
    }

    /**
     * Create a category.
     *
     * @param string $name            The name
     * @param string $type            DEBIT, CREDIT or HORS-BUDGET
     * @param int    $parent_category The parent category (0 for a top-level category)
     * @param string $icon            The icon (SF Symbols name)
     * @param string $color           The color (SwiftUI name or hex)
     * @return array The category
     * @throws Error If invalid or the name already exists
     */
    #[ApiRoute('/category', method: 'POST')]
    public static function create($name, $type, $parent_category = 0, $icon = '', $color = '')
    {
        User::requireAdmin();

        [$name, $parent, $type, $icon, $color] = self::validate(null, $name, $parent_category, $type, $icon, $color);

        $stmt = Db::execute("INSERT INTO bank_transaction_category (name, parent_category, type, icon, color) VALUES (?, ?, ?, ?, ?)",
            "sisss", $name, $parent, $type, $icon, $color);

        return ['id' => $stmt->insert_id, 'name' => $name, 'parent_category' => $parent, 'type' => $type, 'icon' => $icon, 'color' => $color];
    }

    /**
     * Modify a category.
     *
     * @param int    $id              The category
     * @param string $name            The name
     * @param string $type            DEBIT, CREDIT or HORS-BUDGET
     * @param int    $parent_category The parent category (0 for a top-level category)
     * @param string $icon            The icon (SF Symbols name)
     * @param string $color           The color (SwiftUI name or hex)
     * @return array The category
     * @throws Error If not found, protected, invalid or the name already exists
     */
    #[ApiRoute('/category', method: 'PUT')]
    public static function update($id, $name, $type, $parent_category = 0, $icon = '', $color = '')
    {
        User::requireAdmin();

        $id = (int)$id;
        self::existing($id);
        [$name, $parent, $type, $icon, $color] = self::validate($id, $name, $parent_category, $type, $icon, $color);

        Db::execute("UPDATE bank_transaction_category SET name = ?, parent_category = ?, type = ?, icon = ?, color = ? WHERE id = ?",
            "sisssi", $name, $parent, $type, $icon, $color, $id);

        return ['id' => $id, 'name' => $name, 'parent_category' => $parent, 'type' => $type, 'icon' => $icon, 'color' => $color];
    }

    /**
     * Delete a category: its transactions become uncategorized, its budgets and
     * categorization rules are deleted.
     *
     * @param int $id The category
     * @return bool True if deleted
     * @throws Error If not found, protected or with sub-categories
     */
    #[ApiRoute('/category', method: 'DELETE')]
    public static function delete($id)
    {
        User::requireAdmin();

        $id = (int)$id;
        self::existing($id);
        if (Db::queryOne("SELECT id FROM bank_transaction_category WHERE parent_category = ? AND id <> ? LIMIT 1", "ii", $id, $id)) {
            throw new Error("Delete or move its sub-categories first", 409);
        }

        Db::execute("UPDATE bank_transaction SET category = 0 WHERE category = ?", "i", $id);
        Db::execute("DELETE FROM budget WHERE category = ?", "i", $id);
        Db::execute("DELETE FROM bank_transaction_category WHERE id = ?", "i", $id);

        return true;
    }

    /**
     * Check that a category exists and can be modified.
     *
     * @param int $id The category
     * @return void
     * @throws Error If not found or protected
     */
    private static function existing($id)
    {
        if ($id === 0) {
            throw new Error("The default category cannot be modified", 400);
        }
        if (!Db::queryOne("SELECT id FROM bank_transaction_category WHERE id = ?", "i", $id)) {
            throw new Error("Category not found", 404);
        }
    }

    /**
     * Get the automatic categorization rules (keywords found in the labels).
     *
     * @return array The rules with their category name, sorted by category
     */
    #[ApiRoute('/category/keyword', method: 'GET')]
    public static function getKeywords()
    {
        $sql = "SELECT k.id, k.keyword, k.category, c.name AS category_name
                FROM bank_transaction_category_keyword k
                JOIN bank_transaction_category c ON c.id = k.category
                ORDER BY c.name, k.keyword";
        return Db::execute($sql, "")->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Add an automatic categorization rule.
     *
     * @param string $keyword  The text to find in the transaction labels (stored in upper case)
     * @param int    $category The category to set
     * @return array The created rule
     * @throws Error If the keyword is empty or already exists, or the category does not exist
     */
    #[ApiRoute('/category/keyword', method: 'POST')]
    public static function createKeyword($keyword, $category)
    {
        User::requireAdmin();

        // Labels are stored in upper case
        $keyword = strtoupper(trim((string)$keyword));
        if ($keyword === '' || strlen($keyword) > 60) {
            throw new Error("The keyword must contain 1 to 60 characters", 400);
        }

        if (!Db::queryOne("SELECT id FROM bank_transaction_category WHERE id=?", "i", $category)) {
            throw new Error("Category not found", 404);
        }

        if (Db::queryOne("SELECT id FROM bank_transaction_category_keyword WHERE keyword=?", "s", $keyword)) {
            throw new Error("This keyword already exists", 409);
        }

        $stmt = Db::execute("INSERT INTO bank_transaction_category_keyword (keyword, category) VALUES (?, ?)", "si", $keyword, $category);

        return ['id' => $stmt->insert_id, 'keyword' => $keyword, 'category' => (int)$category];
    }

    /**
     * Delete an automatic categorization rule.
     *
     * @param int $id The rule id
     * @return bool True if deleted
     * @throws Error If the rule does not exist
     */
    #[ApiRoute('/category/keyword', method: 'DELETE')]
    public static function deleteKeyword($id)
    {
        User::requireAdmin();

        $stmt = Db::execute("DELETE FROM bank_transaction_category_keyword WHERE id=?", "i", $id);

        if ($stmt->affected_rows === 0) {
            throw new Error("Rule not found", 404);
        }

        return true;
    }

    /**
     * Apply the rules to all the transactions without category.
     *
     * @return array The number of transactions categorized
     */
    #[ApiRoute('/category/keyword/apply', method: 'POST')]
    public static function applyKeywords()
    {
        User::requireAdmin();

        return ['updated' => Transaction::updateMissingCategories(true)];
    }

    /**
     * Get the category of a transaction based on its label.
     *
     * @param string     $label    The label of the transaction
     * @param array|null $keywords The keywords already loaded (loaded when null)
     * @return int|null The category id or null if not found
     */
    public static function find($label, $keywords = null)
    {
        $keywords ??= self::loadKeywords();

        // The first keyword found in the label gives the category
        foreach ($keywords as $row) {
            if (strpos($label, $row["keyword"]) !== false) {
                return $row["category"];
            }
        }

        return null;
    }

    /**
     * Load all the categorization keywords, in rule creation order.
     *
     * @return array The rows (keyword, category)
     */
    public static function loadKeywords()
    {
        $sql = "SELECT keyword, category FROM bank_transaction_category_keyword ORDER BY id";
        return Db::execute($sql, "")->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
