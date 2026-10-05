<?php

/**
 * Import of the statement files downloaded from the banks
 */
class ImportTest extends DatabaseTestCase
{
    /**
     * A CSV statement, base64 encoded as the portal sends it
     * @return string
     */
    private function statement()
    {
        $date = fn($days) => date('d/m/Y', strtotime($this->day($days)));
        $csv = "Date;Libellé;Montant\n"
            // u2 of the data set (PRLV SEPA EDF, -50 on day 2) with another label, one day later
            . $date(3) . ";PRLV SEPA EDF MDT/987;-50,00\n"
            // New, categorized by the SUPERMARCHE rule
            . $date(5) . ";CB SUPERMARCHE BIS;-12,34\n"
            // New and old: the rules apply whatever the date
            . "10/05/2019;VIR SALAIRE MAI;2 000,00\n"
            // Twice in the file: the second one would be merged with the first
            . $date(5) . ";CB SUPERMARCHE BIS;-12,34\n";
        return base64_encode($csv);
    }

    public function testPreview()
    {
        $this->loginAs(self::USER);
        $preview = Transaction::previewImport($this->statement(), 'releve.csv');

        $this->assertSame('csv', $preview['format']);
        $this->assertSame(['new' => 2, 'known' => 1, 'duplicate' => 1], $preview['counts']);
        $this->assertSame('2019-05-10', $preview['from']);
        $this->assertSame(['duplicate', 'new', 'new', 'known'], array_column($preview['rows'], 'status'));
        $this->assertSame('PRLV SEPA EDF ', $preview['rows'][0]['match']['label']);
        $this->assertSame('CB SUPERMARCHE BIS', $preview['rows'][1]['label']);
        $this->assertSame(7, $preview['rows'][1]['type']);
        $this->assertArrayNotHasKey('uuid', $preview['rows'][1]);
    }

    public function testImportTheNewTransactions()
    {
        $this->loginAs(self::USER);
        $result = Transaction::import($this->statement(), 'releve.csv');

        $this->assertSame(2, $result['imported']);
        $this->assertSame(2, $result['categorized']);
        $row = Db::queryOne("SELECT category, user, type FROM bank_transaction WHERE label = 'CB SUPERMARCHE BIS'", "");
        $this->assertEquals(2, $row['category']);
        $this->assertEquals(self::USER, $row['user']);
        $this->assertEquals(7, $row['type']);
        $this->assertEquals(4, Db::queryOne("SELECT category FROM bank_transaction WHERE label = 'VIR SALAIRE MAI'", "")['category']);
        // The probable duplicate is left out
        $this->assertNull(Db::queryOne("SELECT id FROM bank_transaction WHERE label LIKE 'PRLV SEPA EDF MDT%'", ""));

        // A second import finds them
        $again = Transaction::previewImport($this->statement(), 'releve.csv');
        $this->assertSame(['new' => 0, 'known' => 3, 'duplicate' => 1], $again['counts']);
        $this->assertSame(0, Transaction::import($this->statement(), 'releve.csv')['imported']);
    }

    public function testImportASelectedDuplicate()
    {
        $this->loginAs(self::USER);
        // Only the probable duplicate (index 0); the known row 3 is never imported
        $result = Transaction::import($this->statement(), 'releve.csv', [0, 3]);

        $this->assertSame(1, $result['imported']);
        $this->assertEquals(2, Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction WHERE amount = -50", "")['n']);
        $this->assertNull(Db::queryOne("SELECT id FROM bank_transaction WHERE label = 'CB SUPERMARCHE BIS'", ""));
    }

    public function testInvalidFiles()
    {
        $this->loginAs(self::USER);
        try {
            Transaction::previewImport('not base64 !', 'x.csv');
            $this->fail('Not base64');
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
        }
        $this->expectExceptionCode(422);
        Transaction::previewImport(base64_encode("nothing;to;read\n"), 'x.csv');
    }

    public function testTheFileIsNotLogged()
    {
        $this->assertSame(['file' => '***', 'filename' => 'x.csv'], Webservice::maskSensitive(['file' => 'abc', 'filename' => 'x.csv']));
    }
}
