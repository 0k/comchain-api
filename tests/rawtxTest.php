<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/rawtx.inc';

class RawtxTest extends TestCase
{
    private static function fixture($name)
    {
        $fixtures = require __DIR__ . '/fixtures/rawtx.php';
        return $fixtures[$name];
    }

    private static function hexNumber($hex)
    {
        return ltrim(strtolower(preg_replace('/^0x/', '', $hex)), '0');
    }

    public function testDecodesRealTransactions()
    {
        $cases = [
            '32-byte r and s' => 'transfer_nonce127',
            '31-byte r' => 'transfer_nonce125',
            '31-byte r and s' => 'transfer_nonce126',
            '31-byte s, 3 arguments' => 'onbehalf',
        ];
        foreach ($cases as $label => $name) {
            $expected = self::fixture($name);

            $tx = decodeRawTransaction($expected['raw']);

            $this->assertSame(self::hexNumber($expected['nonce']), self::hexNumber($tx['nonce']), $label);
            $this->assertSame(substr($expected['to'], 2), $tx['to'], $label);
            $this->assertSame(substr($expected['input'], 2), $tx['data'], $label);
            $this->assertSame(self::hexNumber($expected['v']), self::hexNumber($tx['v']), $label);
            $this->assertSame(self::hexNumber($expected['r']), self::hexNumber($tx['r']), $label);
            $this->assertSame(self::hexNumber($expected['s']), self::hexNumber($tx['s']), $label);
        }
    }

    public function testDecodesTwoArgumentCall()
    {
        $call = decodeContractCall(self::fixture('transfer_nonce125')['raw']);

        $this->assertSame('0x2297f43487d4269c5a41de128a89d9067770c7dc', $call['contract']);
        $this->assertSame('a5f7c148', $call['selector']);
        $this->assertCount(2, $call['args']);
        $this->assertSame('0x52e5db165cb319321d49af005021ce4900e66c7e', '0x' . substr($call['args'][0], 24, 40));
        $this->assertSame(12908, hexdec($call['args'][1]));
    }

    public function testDecodesThreeArgumentCall()
    {
        $expected = self::fixture('onbehalf');
        $words = str_split(substr($expected['input'], 10), 64);

        $call = decodeContractCall($expected['raw']);

        $this->assertSame('1b6b1ee5', $call['selector']);
        $this->assertSame($words, $call['args']);
    }

    public function testReturnsNullForNonCallData()
    {
        $this->assertNull(decodeContractCall(self::fixture('empty_data')['raw']));
    }

    /**
     * The values api.php used to read at fixed offsets from the end of the
     * raw transaction must be unchanged whenever those offsets were right,
     * i.e. for a 2-argument call signed with 32-byte r and s.
     */
    public function testMatchesLegacyOffsetsOnNormalSignature()
    {
        $raw = self::fixture('transfer_nonce127')['raw'];
        $this->assertSame('b844', substr($raw, -274, 4), 'fixture must be one the legacy parser handled');
        $legacy = substr($raw, -316, 182);

        $call = decodeContractCall($raw);

        $this->assertSame('0x' . substr($legacy, 0, 40), $call['contract']);
        $this->assertSame(substr($legacy, 46, 8), $call['selector']);
        $this->assertSame(substr($legacy, 78, 40), substr($call['args'][0], 24, 40));
        $this->assertSame(hexdec(substr($legacy, -64)), hexdec($call['args'][1]));
    }

    public function testRejectsMalformedRawTransactions()
    {
        $raw = self::fixture('transfer_nonce127')['raw'];
        $cases = [
            'truncated' => substr($raw, 0, -2),
            'trailing bytes' => $raw . '00',
            'odd hex length' => $raw . '0',
            'not hexadecimal' => '0x' . str_repeat('zz', 20),
            'empty' => '0x',
            'not a list' => '0x8180',
            'list of 8 items' => '0xc8' . str_repeat('80', 8),
        ];
        foreach ($cases as $label => $malformed) {
            try {
                decodeRawTransaction($malformed);
                $this->fail("$label: accepted");
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage(), $label);
            }
        }
    }
}
