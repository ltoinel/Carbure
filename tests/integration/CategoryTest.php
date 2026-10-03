<?php

class CategoryTest extends DatabaseTestCase
{
    public function testGet()
    {
        $this->assertCount(5, Category::get());
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
