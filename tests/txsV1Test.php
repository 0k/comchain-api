<?php

use PHPUnit\Framework\TestCase;

// Test constants - lower values for faster tests
define('TXS_MAX_QUERY_LIMIT', 5);
define('TXS_RECENT_MAX_BUFFER_TX_COUNT', 10);
define('TXS_CASSANDRA_QUERY_PAGE_SIZE', 2);
define('TXS_PENDING_CUTOFF_AGE', 3600);
define('TXS_PENDING_CLOSURE_PAST_LOOKUP_LIMIT', 500);

require_once __DIR__ . '/Mocks.php';
require_once __DIR__ . '/../v1/txs.php';

class TxsV1Test extends TestCase
{
    // ========== Entrypoint validation tests ==========

    public function testEntrypointValidAddress()
    {
        $result = txs_entrypoint([
            'addr' => '0x1234567890123456789012345678901234567890',
            'n' => '5',
        ]);
        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('0x1234567890123456789012345678901234567890', $result['addr']);
        $this->assertSame(5, $result['n']);
    }

    public function testEntrypointInvalidAddressTooShort()
    {
        $result = txs_entrypoint([
            'addr' => '0x1234',
            'n' => '5',
        ]);
        $this->assertSame(['error' => 'Invalid address'], $result);
    }

    public function testEntrypointMissingAddress()
    {
        $result = txs_entrypoint(['n' => '5']);
        $this->assertSame(['error' => 'Invalid address'], $result);
    }

    public function testEntrypointMissingN()
    {
        $result = txs_entrypoint([
            'addr' => '0x1234567890123456789012345678901234567890',
        ]);
        $this->assertSame(['error' => 'n is required and must be a non-zero integer'], $result);
    }

    public function testEntrypointZeroN()
    {
        $result = txs_entrypoint([
            'addr' => '0x1234567890123456789012345678901234567890',
            'n' => '0',
        ]);
        $this->assertSame(['error' => 'n is required and must be a non-zero integer'], $result);
    }

    public function testEntrypointNonNumericN()
    {
        $result = txs_entrypoint([
            'addr' => '0x1234567890123456789012345678901234567890',
            'n' => 'abc',
        ]);
        $this->assertSame(['error' => 'n is required and must be a non-zero integer'], $result);
    }

    public function testEntrypointNegativeNWithoutCursor()
    {
        $result = txs_entrypoint([
            'addr' => '0x1234567890123456789012345678901234567890',
            'n' => '-5',
        ]);
        $this->assertSame(['error' => 'n must be positive when no cursor is provided'], $result);
    }

    public function testEntrypointNegativeNWithCursor()
    {
        $result = txs_entrypoint([
            'addr' => '0x1234567890123456789012345678901234567890',
            'n' => '-5',
            'cursor_time' => '1000',
            'cursor_hash' => '0xabc',
        ]);
        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(-5, $result['n']);
        $this->assertSame(1000, $result['cursor_time']);
        $this->assertSame('0xabc', $result['cursor_hash']);
    }

    public function testEntrypointCursorTimeWithoutHash()
    {
        $result = txs_entrypoint([
            'addr' => '0x1234567890123456789012345678901234567890',
            'n' => '5',
            'cursor_time' => '1000',
        ]);
        $this->assertSame(['error' => 'Both cursor_time and cursor_hash must be provided together'], $result);
    }

    public function testEntrypointCursorHashWithoutTime()
    {
        $result = txs_entrypoint([
            'addr' => '0x1234567890123456789012345678901234567890',
            'n' => '5',
            'cursor_hash' => '0xabc',
        ]);
        $this->assertSame(['error' => 'Both cursor_time and cursor_hash must be provided together'], $result);
    }

    public function testEntrypointNonNumericCursorTime()
    {
        $result = txs_entrypoint([
            'addr' => '0x1234567890123456789012345678901234567890',
            'n' => '5',
            'cursor_time' => 'abc',
            'cursor_hash' => '0xabc',
        ]);
        $this->assertSame(['error' => 'cursor_time must be numeric'], $result);
    }

    // ========== Transaction retrieval tests ==========

    public function testBasicNReturnsLastNTransactions()
    {
        $session = session([
            'status = 0' => page([
                tx('0xhash1', 1000),
                tx('0xhash2', 900),
                tx('0xhash3', 800),
                tx('0xhash4', 700),
            ]),
            'status = 1' => page([]),
        ]);

        $result = txs_get($session, '0xaddr1', 3);

        $this->assertCount(3, $result);
        $hashes = array_column($result, 'hash');
        $this->assertSame(['0xhash1', '0xhash2', '0xhash3'], $hashes);
    }

    public function testNLargerThanAvailable()
    {
        $session = session([
            'status = 0' => page([
                tx('0xhash1', 1000),
                tx('0xhash2', 900),
            ]),
            'status = 1' => page([]),
        ]);

        $result = txs_get($session, '0xaddr1', 5);

        $this->assertCount(2, $result);
        $hashes = array_column($result, 'hash');
        $this->assertSame(['0xhash1', '0xhash2'], $hashes);
    }

    public function testCursorWithNegativeNReturnsTransactionsBeforeCursor()
    {
        $session = session([
            'status = 0' => page([
                tx('0xhash1', 1000),
                tx('0xhash2', 900),
                tx('0xhash3', 800),
                tx('0xhash4', 700),
            ]),
            'status = 1' => page([]),
        ]);

        $result = txs_get($session, '0xaddr1', -5, 800, '0xhash3');

        $hashes = array_column($result, 'hash');
        $this->assertSame(['0xhash1', '0xhash2'], $hashes);
    }

    public function testCursorWithPositiveNReturnsTransactionsAfterCursor()
    {
        $session = session([
            'status = 0' => page([
                tx('0xhash1', 1000),
                tx('0xhash2', 900),
                tx('0xhash3', 800),
                tx('0xhash4', 700),
            ]),
            'status = 1' => page([]),
        ]);

        $result = txs_get($session, '0xaddr1', 5, 900, '0xhash2');

        $hashes = array_column($result, 'hash');
        $this->assertSame(['0xhash3', '0xhash4'], $hashes);
    }

    public function testCursorWithPositiveNRespectsCount()
    {
        $session = session([
            'status = 0' => page([
                tx('0xhash1', 1000),
                tx('0xhash2', 900),
                tx('0xhash3', 800),
                tx('0xhash4', 700),
                tx('0xhash5', 600),
            ]),
            'status = 1' => page([]),
        ]);

        $result = txs_get($session, '0xaddr1', 2, 900, '0xhash2');

        $hashes = array_column($result, 'hash');
        $this->assertSame(['0xhash3', '0xhash4'], $hashes);
    }

    public function testCursorWithNegativeNRespectsCount()
    {
        $session = session([
            'status = 0' => page([
                tx('0xhash1', 1000),
                tx('0xhash2', 900),
                tx('0xhash3', 800),
                tx('0xhash4', 700),
            ]),
            'status = 1' => page([]),
        ]);

        $result = txs_get($session, '0xaddr1', -2, 700, '0xhash4');

        $this->assertCount(2, $result);
        $hashes = array_column($result, 'hash');
        $this->assertSame(['0xhash2', '0xhash3'], $hashes);
    }

    public function testCursorAtFirstTransactionWithNegativeNReturnsEmpty()
    {
        $session = session([
            'status = 0' => page([
                tx('0xhash1', 1000),
                tx('0xhash2', 900),
            ]),
            'status = 1' => page([]),
        ]);

        $result = txs_get($session, '0xaddr1', -5, 1000, '0xhash1');

        $this->assertCount(0, $result);
    }

    public function testCursorAtLastTransactionWithPositiveNReturnsEmpty()
    {
        $session = session([
            'status = 0' => page([
                tx('0xhash1', 1000),
                tx('0xhash2', 900),
            ]),
            'status = 1' => page([]),
        ]);

        $result = txs_get($session, '0xaddr1', 5, 900, '0xhash2');

        $this->assertCount(0, $result);
    }

    public function testDeduplicationWithCursor()
    {
        $session = session([
            'status = 0' => page([
                tx('0xhash1', 1000),
                tx('0xhash1', 950), // duplicate, older
                tx('0xhash2', 900),
                tx('0xhash3', 800),
            ]),
            'status = 1' => page([]),
        ]);

        $result = txs_get($session, '0xaddr1', -5, 800, '0xhash3');

        $this->assertCount(2, $result);
        $hashes = array_column($result, 'hash');
        $this->assertSame(['0xhash1', '0xhash2'], $hashes);
    }

    public function testPendingTransactionsIncluded()
    {
        $now = time();
        $session = session([
            'status = 0' => page([
                tx('0xconfirmed', $now - 500, 0),
            ]),
            'status = 1' => page([
                tx('0xpending', $now - 100, 1),
            ]),
        ]);

        $result = txs_get($session, '0xaddr1', 5);

        $this->assertCount(2, $result);
        $hashes = array_column($result, 'hash');
        $this->assertSame(['0xpending', '0xconfirmed'], $hashes);
    }

    public function testPendingReplacedByConfirmed()
    {
        $session = session([
            'status = 1' => page([
                tx('0xdup', 1000, 1),
            ]),
            'status = 0' => page([
                tx('0xdup', 900, 0),
            ]),
        ]);

        $result = txs_get($session, '0xaddr1', 5);

        $this->assertCount(1, $result);
        $this->assertSame(0, $result[0]['status']);
    }

    /**
     * Test realistic scenario: pending (status=1) arrives first with later timestamp,
     * confirmed (status=0) arrives later but has earlier timestamp.
     * When confirmed is within TXS_PENDING_CLOSURE_PAST_LOOKUP_LIMIT, pending should be deduplicated.
     */
    public function testPendingDeduplicatedWhenConfirmedWithinLookupLimit()
    {
        // Request n=2 transactions
        // Pending tx at time 1000, confirmed version at time 700 (300s diff, within 500s limit)
        // Other tx at time 900 to fill the page
        $session = session([
            'status = 1' => page([
                tx('0xdup', 1000, 1),      // pending - will be in page
                tx('0xother', 900, 1),     // another pending
            ]),
            'status = 0' => page([
                tx('0xdup', 700, 0),       // confirmed - 300s earlier, within lookup limit
            ]),
        ]);

        $result = txs_get($session, '0xaddr1', 2);

        // The pending 0xdup should be replaced by its confirmed version
        $hashes = array_column($result, 'hash');
        $this->assertCount(2, $result);
        $this->assertContains('0xdup', $hashes);
        $this->assertContains('0xother', $hashes);

        // Find the 0xdup transaction and verify it's the confirmed version
        $dupTx = array_filter($result, function($tx) { return $tx['hash'] === '0xdup'; });
        $dupTx = array_values($dupTx)[0];
        $this->assertSame(0, $dupTx['status'], 'Pending should be replaced by confirmed when within lookup limit');
    }

    /**
     * Test that pending stays pending when confirmed version is beyond the lookup limit.
     */
    public function testPendingStaysPendingWhenConfirmedBeyondLookupLimit()
    {
        // Pending tx at time 1000, confirmed version at time 400 (600s diff, beyond 500s limit)
        $session = session([
            'status = 1' => page([
                tx('0xdup', 1000, 1),      // pending
                tx('0xother', 900, 1),     // another pending
            ]),
            'status = 0' => page([
                tx('0xdup', 500, 0),       // confirmed - 500s earlier, beyond lookup limit
                tx('0xother', 401, 0),       // confirmed - 499s earlier, in lookup limit
            ]),
        ]);

        $result = txs_get($session, '0xaddr1', 2);

        $hashes = array_column($result, 'hash');
        $this->assertCount(2, $result);
        $this->assertContains('0xdup', $hashes);
        $this->assertContains('0xother', $hashes);

        // Find the 0xdup transaction - should still be pending since confirmed is too far back
        $dupTx = array_filter($result, function($tx) { return $tx['hash'] === '0xdup'; });
        $dupTx = array_values($dupTx)[0];
        $this->assertSame(1, $dupTx['status'], 'Pending should stay pending when confirmed is beyond lookup limit');
        // Find the 0xother transaction - should not be pending since confirmed is not too far back
        $otherTx = array_filter($result, function($tx) { return $tx['hash'] === '0xother'; });
        $otherTx = array_values($otherTx)[0];
        $this->assertSame(0, $otherTx['status'], 'Pending should not be pending when confirmed is before lookup limit');
    }

    /**
     * Test that in "before" direction, pending stays pending when confirmed version is beyond the lookup limit.
     * Same as testPendingStaysPendingWhenConfirmedBeyondLookupLimit but with cursor and negative n.
     */
    public function testPendingStaysPendingWhenConfirmedBeyondLookupLimitInBeforeDirection()
    {
        // Cursor at time 600
        // Pending tx at time 1000, confirmed at time 500 (500s diff, beyond 500s limit)
        // Pending tx at time 900, confirmed at time 401 (499s diff, within 500s limit)
        // Both confirmed versions are older than cursor, so code must look past cursor
        $session = session([
            'status = 1' => page([
                tx('0xdup', 1000, 1),      // pending
                tx('0xother', 900, 1),     // another pending
            ]),
            'status = 0' => page([
                tx('0xcursor', 600, 0),   // cursor transaction
                tx('0xdup', 500, 0),       // confirmed - 500s earlier (1000-500), beyond lookup limit
                tx('0xother', 401, 0),     // confirmed - 499s earlier (900-401), within lookup limit
            ]),
        ]);

        // Request transactions before cursor (negative n)
        $result = txs_get($session, '0xaddr1', -2, 600, '0xcursor');

        $hashes = array_column($result, 'hash');
        $this->assertCount(2, $result);
        $this->assertContains('0xdup', $hashes);
        $this->assertContains('0xother', $hashes);

        // Find the 0xdup transaction - should still be pending since confirmed is beyond lookup limit
        $dupTx = array_filter($result, function($tx) { return $tx['hash'] === '0xdup'; });
        $dupTx = array_values($dupTx)[0];
        $this->assertSame(1, $dupTx['status'], 'Pending should stay pending when confirmed is beyond lookup limit');

        // Find the 0xother transaction - should be confirmed since confirmed is within lookup limit
        $otherTx = array_filter($result, function($tx) { return $tx['hash'] === '0xother'; });
        $otherTx = array_values($otherTx)[0];
        $this->assertSame(0, $otherTx['status'], 'Pending should be replaced by confirmed when within lookup limit');
    }

    public function testCursorWithSameTimeUsesHashAsTiebreaker()
    {
        $session = session([
            'status = 0' => page([
                tx('0xhashB', 1000),
                tx('0xhashA', 1000),
                tx('0xhash2', 900),
            ]),
            'status = 1' => page([]),
        ]);

        $result = txs_get($session, '0xaddr1', 5, 1000, '0xhashA');

        $hashes = array_column($result, 'hash');
        $this->assertSame(['0xhash2'], $hashes);
    }

    public function testDirectionFormatting()
    {
        $session = session([
            'status = 0' => page([
                [
                    'hash' => '0xdir',
                    'time' => 1234,
                    'status' => 0,
                    'direction' => 0,
                    'add1' => 'A',
                    'add2' => 'B',
                    'receivedat' => null,
                ],
            ]),
            'status = 1' => page([]),
        ]);

        $result = txs_get($session, '0xaddr1', 1);
        $tx = $result[0];

        $this->assertSame('B', $tx['addr_from']);
        $this->assertSame('A', $tx['addr_to']);
        $this->assertSame(1234, $tx['receivedat'], 'receivedat should default to time when null');
    }

    public function testPagingAcrossMultiplePages()
    {
        $page2 = page([
            tx('0xhash3', 800),
            tx('0xhash4', 700),
        ]);
        $page1 = page([
            tx('0xhash1', 1000),
            tx('0xhash2', 900),
        ], $page2);

        $session = session([
            'status = 0' => $page1,
            'status = 1' => page([]),
        ]);

        $result = txs_get($session, '0xaddr1', 5);
        $hashes = array_column($result, 'hash');

        $this->assertSame(['0xhash1', '0xhash2', '0xhash3', '0xhash4'], $hashes);
    }

    public function testCursorNavigationWithMultiplePages()
    {
        $page2 = page([
            tx('0xhash3', 800),
            tx('0xhash4', 700),
        ]);
        $page1 = page([
            tx('0xhash1', 1000),
            tx('0xhash2', 900),
        ], $page2);

        $session = session([
            'status = 0' => $page1,
            'status = 1' => page([]),
        ]);

        $result = txs_get($session, '0xaddr1', 5, 900, '0xhash2');
        $hashes = array_column($result, 'hash');

        $this->assertSame(['0xhash3', '0xhash4'], $hashes);
    }

    public function testEmptyResult()
    {
        $session = session([
            'status = 0' => page([]),
            'status = 1' => page([]),
        ]);

        $result = txs_get($session, '0xaddr1', 5);

        $this->assertCount(0, $result);
    }

    public function testCursorOnNonExistentTransactionReturnsError()
    {
        $session = session([
            'status = 0' => page([
                tx('0xhash1', 1000),
                tx('0xhash2', 900),
                tx('0xhash3', 800),
            ]),
            'status = 1' => page([]),
        ]);

        $result = txs_get($session, '0xaddr1', 5, 850, '0xnonexistent');

        $this->assertSame(['error' => 'Cursor not found'], $result);
    }

    public function testCursorExistsFunction()
    {
        $session = session([
            'status = 0' => page([
                tx('0xhash1', 1000),
                tx('0xhash2', 900),
            ]),
            'status = 1' => page([]),
        ]);

        $this->assertTrue(txs_cursor_exists($session, '0xaddr1', 1000, '0xhash1'));
        $this->assertTrue(txs_cursor_exists($session, '0xaddr1', 900, '0xhash2'));
        $this->assertFalse(txs_cursor_exists($session, '0xaddr1', 999, '0xhash1'));
        $this->assertFalse(txs_cursor_exists($session, '0xaddr1', 1000, '0xnonexistent'));
    }

    // ========== Query limit tests ==========

    public function testNExceedsMaxLimitReturnsError()
    {
        $session = session([
            'status = 0' => page([]),
            'status = 1' => page([]),
        ]);

        $result = txs_get($session, '0xaddr1', TXS_MAX_QUERY_LIMIT + 1);
        $this->assertSame(['error' => '|n| exceeds maximum limit of ' . TXS_MAX_QUERY_LIMIT], $result);
    }

    public function testNegativeNExceedsMaxLimitReturnsError()
    {
        $session = session([
            'status = 0' => page([tx('0xhash1', 1000)]),
            'status = 1' => page([]),
        ]);

        $result = txs_get($session, '0xaddr1', -(TXS_MAX_QUERY_LIMIT + 1), 1000, '0xhash1');
        $this->assertSame(['error' => '|n| exceeds maximum limit of ' . TXS_MAX_QUERY_LIMIT], $result);
    }

    public function testNAtMaxLimitIsValid()
    {
        $session = session([
            'status = 0' => page([tx('0xhash1', 1000)]),
            'status = 1' => page([]),
        ]);

        $result = txs_get($session, '0xaddr1', TXS_MAX_QUERY_LIMIT);
        $this->assertArrayNotHasKey('error', $result);
    }

    // ========== Memory/buffer tests ==========

    public function testBeforeDirectionWithManyTransactionsCropsBuffer()
    {
        // Create more transactions than TXS_RECENT_MAX_BUFFER_TX_COUNT (10)
        $txCount = 20;
        $txs = [];
        for ($i = 0; $i < $txCount; $i++) {
            $txs[] = tx('0xhash' . $i, 10000 - $i);
        }

        $session = session([
            'status = 0' => page($txs),
            'status = 1' => page([]),
        ]);

        // Request 3 transactions before the last one (cursor at oldest tx)
        $cursorTime = 10000 - ($txCount - 1);
        $cursorHash = '0xhash' . ($txCount - 1);

        $result = txs_get($session, '0xaddr1', -3, $cursorTime, $cursorHash);

        // Should return 3 transactions closest to cursor
        $this->assertCount(3, $result);

        // Verify correct transactions returned (closest to cursor)
        $hashes = array_column($result, 'hash');
        $this->assertSame('0xhash16', $hashes[0]); // 3 before hash19
        $this->assertSame('0xhash18', $hashes[2]);
    }
}
