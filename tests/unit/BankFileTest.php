<?php

use PHPUnit\Framework\TestCase;

class BankFileTest extends TestCase
{
    /**
     * Parse a file of tests/fixtures/import
     * @param string $name The file name
     * @return array
     */
    private function parse($name)
    {
        return BankFile::parse(file_get_contents(__DIR__ . '/../fixtures/import/' . $name), $name);
    }

    public function testOfx()
    {
        $file = $this->parse('releve.ofx');
        $this->assertSame('ofx', $file['format']);
        $this->assertSame('00012345678', $file['account']);
        $this->assertCount(4, $file['transactions']);

        // The memo completes the cut name; DTUSER gives the date of the operation
        $this->assertSame(['date' => '2026-01-05', 'rdate' => '2026-01-04', 'amount' => -42.5,
                           'raw' => 'FACTURE CARTE DU 040126 BOULANGERIE', 'type' => 7], $file['transactions'][0]);
        // Comma as decimal separator, memo appended
        $this->assertSame(-65.3, $file['transactions'][1]['amount']);
        $this->assertSame('PRLV SEPA EDF ECH/100126', $file['transactions'][1]['raw']);
        $this->assertSame(2, $file['transactions'][1]['type']);
        // Entities decoded
        $this->assertSame('RETRAIT DAB PARIS & CIE', $file['transactions'][3]['raw']);
        $this->assertSame(6, $file['transactions'][3]['type']);
    }

    public function testQif()
    {
        $file = $this->parse('releve.qif');
        $this->assertSame('qif', $file['format']);
        $this->assertSame(['date' => '2026-01-31', 'rdate' => '2026-01-31', 'amount' => -12.9, 'raw' => 'CB MONOPRIX', 'type' => 7], $file['transactions'][0]);
        // Space between thousands, memo appended
        $this->assertSame(1500.0, $file['transactions'][1]['amount']);
        $this->assertSame('VIR CAF ALLOCATIONS', $file['transactions'][1]['raw']);
    }

    public function testCamt()
    {
        $file = $this->parse('releve.xml');
        $this->assertSame('camt', $file['format']);
        $this->assertSame('FR7630004000010001234567890', $file['account']);
        $this->assertSame(['date' => '2026-01-12', 'rdate' => '2026-01-11', 'amount' => -23.4, 'raw' => 'CB PHARMACIE CENTRALE', 'type' => 7], $file['transactions'][0]);
        // Credit, label from the remittance information
        $this->assertSame(150.0, $file['transactions'][1]['amount']);
        $this->assertSame('REMBOURSEMENT MUTUELLE', $file['transactions'][1]['raw']);
    }

    public function testCsvWithDebitAndCreditColumnsInWindows1252()
    {
        // Lines about the account before the header, accents in Windows-1252
        $file = $this->parse('credit-agricole.csv');
        $this->assertSame('csv', $file['format']);
        $this->assertSame(['date' => '2026-01-03', 'rdate' => '2026-01-03', 'amount' => -19.99, 'raw' => 'PRLV SEPA FREE MOBILE', 'type' => 2], $file['transactions'][0]);
        $this->assertSame(1200.0, $file['transactions'][1]['amount']);
    }

    public function testCsvWithAmountColumn()
    {
        $file = $this->parse('boursorama.csv');
        $this->assertSame([['date' => '2026-01-07', 'rdate' => '2026-01-07', 'amount' => -45.0, 'raw' => 'CARTE 06/01/26 SNCF', 'type' => 7]], $file['transactions']);
    }

    public function testCsvWithMonthFirstDates()
    {
        $csv = "Date,Description,Amount\n01/31/2026,COFFEE SHOP,-3.50\n02/01/2026,REFUND,10.00\n";
        $file = BankFile::parse($csv, 'export.csv');
        $this->assertSame('2026-01-31', $file['transactions'][0]['date']);
        $this->assertSame('2026-02-01', $file['transactions'][1]['date']);
        $this->assertSame(0, $file['transactions'][0]['type']);
    }

    public function testCsvWithoutKnownColumns()
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(422);
        BankFile::parse("a;b;c\n1;2;3\n", 'export.csv');
    }

    public function testEmptyAndTooLargeFiles()
    {
        try {
            BankFile::parse("  \n", 'x.csv');
            $this->fail('An empty file is refused');
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
        }
        $this->expectExceptionCode(413);
        BankFile::parse(str_repeat('x', BankFile::MAX_SIZE + 1), 'x.csv');
    }

    public function testInvalidCamt()
    {
        $this->expectExceptionCode(422);
        BankFile::parse('<Document><BkToCstmrStmt><Ntry>', 'x.xml');
    }

    public function testAmounts()
    {
        $this->assertSame(-1234.56, BankFile::amount('-1 234,56 €'));
        $this->assertSame(1234.56, BankFile::amount('1.234,56'));
        $this->assertSame(1234.56, BankFile::amount('1,234.56'));
        $this->assertSame(-12.5, BankFile::amount('12,50-'));
        $this->assertSame(-7.0, BankFile::amount('(7.00)'));
        $this->assertSame(3.0, BankFile::amount('+3'));
        $this->assertNull(BankFile::amount(''));
        $this->assertNull(BankFile::amount('abc'));
    }

    public function testDates()
    {
        $this->assertSame('2026-01-31', BankFile::date('20260131120000[+1:CET]'));
        $this->assertSame('2026-01-31', BankFile::date('2026-01-31T10:00:00'));
        $this->assertSame('2026-01-31', BankFile::date('31/01/2026'));
        $this->assertSame('2026-01-31', BankFile::date('31.01.26'));
        $this->assertSame('2026-01-31', BankFile::date("1/31'26", false));
        $this->assertNull(BankFile::date('31/02/2026'));
        $this->assertNull(BankFile::date('hier'));
    }

    public function testTypeFromLabelThenHint()
    {
        $this->assertSame(4, BankFile::type('REMISE CHEQUE 123'));
        $this->assertSame(3, BankFile::type('CHEQUE 4567'));
        $this->assertSame(1, BankFile::type('vir sepa loyer'));
        $this->assertSame(9, BankFile::type('FRAIS TENUE DE COMPTE'));
        $this->assertSame(2, BankFile::type('ECHEANCE PRET', 2));
        $this->assertSame(BankFile::TYPE_UNKNOWN, BankFile::type('AMAZON'));
    }

    public function testDetect()
    {
        $this->assertSame('ofx', BankFile::detect("<OFX>\n<BANKMSGSRSV1>"));
        $this->assertSame('qif', BankFile::detect("!Type:Bank\nD01/01/2026\nT-1\n^"));
        $this->assertSame('camt', BankFile::detect('<Document><BkToCstmrStmt>'));
        $this->assertSame('csv', BankFile::detect("Date;Libellé;Montant"));
        $this->assertSame('ofx', BankFile::detect('???', 'export.QFX'));
    }
}
