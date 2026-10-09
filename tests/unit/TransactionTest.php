<?php

use PHPUnit\Framework\TestCase;

class TransactionTest extends TestCase
{
    public function testCleanLabelCardPayment()
    {
        $this->assertSame('CB AMAZON', Transaction::cleanLabel('facture carte du 010126 amazon carte 4974XXXXXXXX1234'));
    }

    public function testCleanLabelDirectDebit()
    {
        $this->assertSame('PRLV SEPA EDF ', Transaction::cleanLabel('PRLV SEPA EDF MDT/123'));

        // Known limitation: the greedy regex only removes the last part (see TODO.md).
        // Changing it would change the labels, thus the transaction UUIDs.
        $this->assertSame('PRLV SEPA EDF ECH/010126 ', Transaction::cleanLabel('PRLV SEPA EDF ECH/010126 MDT/123'));
    }

    public function testUuidIgnoresCardLabelSuffix()
    {
        $uuid = new ReflectionMethod(Transaction::class, 'generateTransactionUuid');

        // Card payments: only the first 24 chars of the label are significant
        $a = $uuid->invoke(null, 7, '2026-01-01', 'CB SUPERMARCHE DU CENTRE VILLE', -12.5);
        $b = $uuid->invoke(null, 12, '2026-01-01', 'CB SUPERMARCHE DU CENTRE', -12.5);
        $this->assertSame($a, $b);

        // Other transactions: the whole label is significant
        $c = $uuid->invoke(null, 1, '2026-01-01', 'VIR SALAIRE JANVIER', 2000);
        $d = $uuid->invoke(null, 1, '2026-01-01', 'VIR SALAIRE JANVIER BIS', 2000);
        $this->assertNotSame($c, $d);
    }

    public function testIdenticalTransactionsHaveTheirOwnUuid()
    {
        $coffee = ['date' => '2026-01-05', 'amount' => -2.5, 'raw' => 'PRLV CAFE DU COIN', 'type' => 2];
        $other = ['date' => '2026-01-05', 'amount' => -4, 'raw' => 'PRLV BOULANGERIE', 'type' => 2];
        $rows = Transaction::prepareAll(['a' => $coffee, 'b' => $other, 'c' => $coffee, 'd' => $coffee]);

        // The first one keeps the UUID of a single transaction: no UUID of the database changes
        $this->assertSame(['a', 'b', 'c', 'd'], array_keys($rows));
        $this->assertSame(Transaction::prepare($coffee)['uuid'], $rows['a']['uuid']);
        $this->assertSame(Transaction::prepare($other)['uuid'], $rows['b']['uuid']);
        $this->assertSame(md5($rows['a']['uuid'] . '#1'), $rows['c']['uuid']);
        $this->assertSame(md5($rows['a']['uuid'] . '#2'), $rows['d']['uuid']);

        // Received again in the next synchronization: the same UUIDs
        $this->assertSame($rows, Transaction::prepareAll(['a' => $coffee, 'b' => $other, 'c' => $coffee, 'd' => $coffee]));
    }

    public function testCardPaymentsWithTheSameLabelStartKeepTheirUuid()
    {
        // Same first 24 chars: the order of the bank does not change which UUID each one gets
        $north = ['date' => '2026-01-05', 'rdate' => '2026-01-04', 'amount' => -20, 'raw' => 'CB STATION SERVICE AUTOROUTE NORD', 'type' => 7];
        $south = ['date' => '2026-01-05', 'rdate' => '2026-01-04', 'amount' => -20, 'raw' => 'CB STATION SERVICE AUTOROUTE SUD', 'type' => 7];
        $uuids = fn($rows) => array_column($rows, 'uuid', 'label');

        $first = $uuids(Transaction::prepareAll([$north, $south]));
        $this->assertCount(2, array_unique($first));
        $this->assertEquals($first, $uuids(Transaction::prepareAll([$south, $north])));
    }

    public function testSalaryAtEndOfMonthMovesToNextMonth()
    {
        $fix = new ReflectionMethod(Transaction::class, 'fixPrelevementDate');

        $this->assertSame('2026-02-01', $fix->invoke(null, 1, 2000, '2026-01-28'));
        $this->assertSame('2026-01-10', $fix->invoke(null, 1, 2000, '2026-01-10'));
        $this->assertSame('2026-01-28', $fix->invoke(null, 7, -20, '2026-01-28'));
    }
}
