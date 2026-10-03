<?php

class CategoryTest extends DatabaseTestCase
{
    public function testGet()
    {
        $this->assertCount(5, Category::get());
    }

    public function testCreateUpdateDelete()
    {
        $category = Category::create('Vacances', 'DEBIT', 0, 'airplane', 'teal');
        $this->assertGreaterThan(4, $category['id']);

        $child = Category::create('Hôtels', 'DEBIT', $category['id'], 'house', '');
        $this->assertSame($category['id'], $child['parent_category']);

        $updated = Category::update($child['id'], 'Hébergement', 'DEBIT', $category['id'], 'house', '#ff0000');
        $this->assertSame('Hébergement', $updated['name']);

        // A category with sub-categories cannot be deleted
        try {
            Category::delete($category['id']);
            $this->fail('Deleted with a sub-category');
        } catch (Error $e) {
            $this->assertSame(409, $e->getCode());
        }

        $this->assertTrue(Category::delete($child['id']));
        $this->assertTrue(Category::delete($category['id']));
        $this->assertCount(5, Category::get());
    }

    public function testDeleteMovesTransactionsAndRemovesBudgetsAndRules()
    {
        // Énergie (3): transaction u2, a budget and the EDF rule
        $this->assertTrue(Category::delete(3));

        $this->assertEquals(0, Db::queryOne("SELECT category FROM bank_transaction WHERE uuid = 'u2'", "")['category']);
        $this->assertEquals(0, Db::queryOne("SELECT COUNT(*) AS n FROM budget WHERE category = 3", "")['n']);
        $this->assertNull(Category::find('PRLV SEPA EDF '));
    }

    public function testValidation()
    {
        foreach ([
            fn() => Category::create('', 'DEBIT'),
            fn() => Category::create('X', 'OTHER'),
            fn() => Category::create('X', 'DEBIT', 0, 'bad icon!'),
            fn() => Category::create('X', 'DEBIT', 2),              // 2 is already a sub-category
            fn() => Category::update(1, 'Alimentation', 'DEBIT', 4), // 1 has sub-categories
            fn() => Category::update(0, 'X', 'DEBIT'),               // protected
        ] as $i => $call) {
            try {
                $call();
                $this->fail("Case $i accepted");
            } catch (Error $e) {
                $this->assertSame(400, $e->getCode(), "Case $i: " . $e->getMessage());
            }
        }

        $this->expectException(Error::class);
        $this->expectExceptionCode(409);
        Category::create('Salaire', 'CREDIT');
    }

    public function testKeywords()
    {
        $rules = Category::getKeywords();

        $this->assertCount(3, $rules);
        $this->assertEqualsCanonicalizing(['Énergie', 'Salaire', 'Supermarché'], array_column($rules, 'category_name'));
    }

    public function testCreateAndDeleteKeyword()
    {
        $rule = Category::createKeyword('  bio ', 1);
        $this->assertSame('BIO', $rule['keyword']);

        // The label "CB 100% BIO" (category 0) is now categorized
        $this->assertSame(['updated' => 1], Category::applyKeywords());
        $this->assertEquals(1, Db::queryOne("SELECT category FROM bank_transaction WHERE uuid='u4'", "")['category']);

        $this->assertTrue(Category::deleteKeyword($rule['id']));
        $this->assertCount(3, Category::getKeywords());
    }

    public function testCreateDuplicateKeyword()
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(409);
        Category::createKeyword('edf', 1);
    }

    public function testCreateKeywordUnknownCategory()
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        Category::createKeyword('NEW', 99);
    }

    public function testCreateEmptyKeyword()
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        Category::createKeyword('  ', 1);
    }

    public function testDeleteUnknownKeyword()
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        Category::deleteKeyword(99);
    }

    public function testFind()
    {
        $this->assertEquals(3, Category::find('PRLV SEPA EDF '));
        $this->assertNull(Category::find('UNKNOWN'));
    }
}
