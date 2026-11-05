<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../ecrecover_helper.php';

class EcrecoverTest extends TestCase
{
    private static function fixture($name)
    {
        $fixtures = require __DIR__ . '/fixtures/rawtx.php';
        return $fixtures[$name];
    }

    public function testRecoversSenderOfRawTransactions()
    {
        $cases = [
            '32-byte r and s' => 'transfer_nonce127',
            '31-byte r' => 'transfer_nonce125',
            '31-byte r and s' => 'transfer_nonce126',
            '31-byte s, 3 arguments' => 'onbehalf',
        ];
        foreach ($cases as $label => $name) {
            $expected = self::fixture($name);

            $sender = TransactionEcRecover($expected['raw'])[0];

            $this->assertSame($expected['from'], strtolower($sender), $label);
        }
    }
}
