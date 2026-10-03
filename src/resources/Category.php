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
