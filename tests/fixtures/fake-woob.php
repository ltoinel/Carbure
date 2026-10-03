<?php

/**
 * fake-woob.php
 *
 * Test double of the woob CLI used by the tests (woob_path = "php tests/fixtures/fake-woob.php").
 * Usage: fake-woob.php bank <coming|history> <account@bank> [options...]
 *        fake-woob.php bank list -b <backend> -f json   (two accounts, "none": no account)
 *        fake-woob.php config add <module> <backend>     (always succeeds)
 *
 * - account "fail@bank"  : writes on stderr and exits with code 1
 * - account "empty@bank" : no output
 * - account "broken@bank": no output, error on stderr, exit code 0 (module not loaded)
 * - account "slow@bank"  : waits 2 seconds before answering
 * - other accounts       : one JSON array of transactions per line, like woob -f json
 */

$type = $argv[2] ?? '';
$account = $argv[3] ?? '';

if (($argv[1] ?? '') === 'config' && $type === 'list') {
    // One JSON object per line, with the (secret) configuration that must not leak
    echo json_encode(['Name' => 'bnp', 'Module' => 'bnp', 'Configuration' => 'login=12345678, password=*****']) . "\n";
    echo json_encode(['Name' => 'cic_pro', 'Module' => 'cic', 'Configuration' => 'login=jdoe, password=*****']) . "\n";
    exit(0);
}

if (($argv[1] ?? '') === 'config') {
    exit(0);
}

if (($argv[1] ?? '') === 'bank' && $type === 'list') {
    $index = array_search('-b', $argv);
    $backend = $index === false ? 'bnp' : $argv[$index + 1];
    if ($backend === 'none') {
        fwrite(STDERR, "Error(none): Unable to load module \"none\"\n");
        exit(0);
    }
    echo json_encode([
        ['id' => "00012345678@$backend", 'label' => 'Compte chèques', 'balance' => '1234.56', 'currency' => 'EUR'],
        ['id' => "00087654321@$backend", 'label' => 'Livret A', 'balance' => '5000.00', 'currency' => 'EUR'],
    ]) . "\n";
    exit(0);
}

if ($account === 'fail@bank') {
    fwrite(STDERR, "AttributeError: 'NoneType' object has no attribute 'iter_accounts'\n");
    exit(1);
}

if ($account === 'empty@bank') {
    exit(0);
}

if ($account === 'broken@bank') {
    fwrite(STDERR, "Error(bnp): Unable to load module \"bnp\": No module named 'curl_cffi'\n");
    exit(0);
}

if ($account === 'slow@bank') {
    sleep(2);
}

$month = date('Y-m');

if ($type === 'coming') {
    echo json_encode([
        ['date' => "$month-05", 'rdate' => "$month-04", 'amount' => -42.5, 'raw' => 'FACTURE CARTE DU 040126 BOULANGERIE CARTE 4974', 'type' => 12, 'card' => '4974XXXXXXXX1234'],
        ['date' => "$month-05", 'rdate' => "$month-05", 'amount' => -10, 'raw' => 'VIR NOT A CARD', 'type' => 1],
    ]) . "\n";
} else {
    echo json_encode([
        ['date' => "$month-02", 'rdate' => "$month-02", 'amount' => -60.1, 'raw' => 'PRLV SEPA EDF MDT/123', 'type' => 2],
        ['date' => "$month-03", 'rdate' => "$month-02", 'amount' => -25, 'raw' => 'FACTURE CARTE DU 020126 SUPERMARCHE CARTE 4974', 'type' => 7, 'card' => '4974XXXXXXXX1234'],
    ]) . "\n";
    echo "not json, ignored\n";
    echo json_encode(['date' => "$month-28", 'rdate' => "$month-28", 'amount' => 2500, 'raw' => 'VIR SALAIRE', 'type' => 1]) . "\n";
}
