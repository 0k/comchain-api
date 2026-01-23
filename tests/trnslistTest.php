<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Mocks.php';

class TrnslistTest extends TestCase
{
    public function testMergeOrderAndDedup()
    {
        $session = session([
            'status = 0' => page([
                tx('0xhash1', 1000),
                tx('0xhash1', 950), // duplicate, older
                tx('0xhash2', 900),
            ]),
            'status = 1' => page([]),
        ]);

        $result = get_transactions($session, '0xaddr1', 10, 0);

        $this->assertCount(2, $result);
        $hashes = array_map(function ($r) { return json_decode($r, true)['hash']; }, $result);
        $this->assertSame(['0xhash1', '0xhash2'], $hashes);
    }

    public function testPendingCutoff()
    {
        $now = time();
        $session = session([
            'status = 0' => page([]),
            'status = 1' => page([
                // Only include rows that would pass the real query time >= cutoff
                tx('0xpending1', $now - 1800, 1), // within 1h
            ]),
        ]);

        $result = get_transactions($session, '0xaddr1', 10, 0);

        $this->assertCount(1, $result);
        $tx = json_decode($result[0], true);
        $this->assertSame('0xpending1', $tx['hash']);
    }

    public function testDuplicateHashWithDifferentStatusShouldDedup()
    {
        // Status=1 (pending) arrives first, followed by status=0 (confirmed) of same hash.
        $session = session([
            'status = 1' => page([
                tx('0xdup', 1000, 1),
            ]),
            'status = 0' => page([
                tx('0xdup', 900, 0),
            ]),
        ]);

        $result = get_transactions($session, '0xaddr1', 10, 0);

        // Expected behavior: only one tx per hash.
        $this->assertCount(1, $result, 'Should not emit the same hash twice even if statuses differ');
    }

    public function testPendingReplacedByConfirmedWhenLimitIsOne()
    {
        // Status=1 (pending) arrives first, followed by status=0 (confirmed) of same hash.
        // With limit=1, the code should still find and use the confirmed version.
        $session = session([
            'status = 1' => page([
                tx('0xdup', 1000, 1),
            ]),
            'status = 0' => page([
                tx('0xdup', 900, 0),
            ]),
        ]);

        $result = get_transactions($session, '0xaddr1', 1, 0);

        $this->assertCount(1, $result);
        $tx = json_decode($result[0], true);
        $this->assertSame(0, $tx['status'], 'Pending should be replaced by confirmed version');
    }

    public function testPendingReplacedWhenConfirmedWithinLookupLimit()
    {
        // TXS_PENDING_CLOSURE_PAST_LOOKUP_LIMIT = 500 in tests
        // Pending at 1000, confirmed at 501 (499s diff - within limit)
        $session = session([
            'status = 1' => page([
                tx('0xdup', 1000, 1),
            ]),
            'status = 0' => page([
                tx('0xdup', 501, 0),  // 1000 - 501 = 499 < 500, within limit
            ]),
        ]);

        $result = get_transactions($session, '0xaddr1', 1, 0);

        $this->assertCount(1, $result);
        $tx = json_decode($result[0], true);
        $this->assertSame(0, $tx['status'], 'Pending should be replaced when confirmed is within lookup limit');
    }

    public function testPendingStaysPendingWhenConfirmedBeyondLookupLimit()
    {
        // TXS_PENDING_CLOSURE_PAST_LOOKUP_LIMIT = 500 in tests
        // Pending at 1000, confirmed at 500 (500s diff - at/beyond limit)
        $session = session([
            'status = 1' => page([
                tx('0xdup', 1000, 1),
            ]),
            'status = 0' => page([
                tx('0xdup', 500, 0),  // 1000 - 500 = 500, not < 500, beyond limit
            ]),
        ]);

        $result = get_transactions($session, '0xaddr1', 1, 0);

        $this->assertCount(1, $result);
        $tx = json_decode($result[0], true);
        $this->assertSame(1, $tx['status'], 'Pending should stay pending when confirmed is beyond lookup limit');
    }

    public function testMixedPendingConfirmedWithLookupLimitBoundaries()
    {
        // TXS_PENDING_CLOSURE_PAST_LOOKUP_LIMIT = 500 in tests
        // TXS_PENDING_CUTOFF_AGE = 1000 in tests
        //
        // Request limit=3 to trigger closure mode after collecting 3 transactions.
        // The closure mode will then look for confirmed versions of pending txs.
        //
        // Timeline (time DESC):
        // - 0xreplaced: pending@1000, confirmed@501 (499s diff, within limit) -> expect status=0
        // - 0xkept: pending@950, confirmed@450 (500s diff, beyond limit) -> expect status=1
        // - 0xpending_only: pending@900, no confirmed -> expect status=1
        // - 0xconfirmed_only: confirmed@850, no pending -> not in result (limit=3)
        // - 0xold_confirmed: confirmed@200, no pending -> not in result
        //
        $session = session([
            'status = 1' => page([
                tx('0xreplaced', 1000, 1),
                tx('0xkept', 950, 1),
                tx('0xpending_only', 900, 1),
            ]),
            'status = 0' => page([
                tx('0xconfirmed_only', 850, 0),
                tx('0xreplaced', 501, 0),      // 1000 - 501 = 499 < 500, within limit
                tx('0xkept', 450, 0),          // 950 - 450 = 500, not < 500, beyond limit
                tx('0xold_confirmed', 200, 0),
            ]),
        ]);

        // Request only 3 to trigger closure mode
        $result = get_transactions($session, '0xaddr1', 3, 0);

        $this->assertCount(3, $result);

        // Parse results into a hash -> status map
        $byHash = [];
        foreach ($result as $r) {
            $tx = json_decode($r, true);
            $byHash[$tx['hash']] = $tx['status'];
        }

        // Verify each transaction's expected status
        $this->assertSame(0, $byHash['0xreplaced'], '0xreplaced: pending should be replaced (499s < 500 limit)');
        $this->assertSame(1, $byHash['0xkept'], '0xkept: pending should stay pending (500s >= 500 limit)');
        $this->assertSame(1, $byHash['0xpending_only'], '0xpending_only: pending with no confirmed stays pending');

        // Verify order (by time DESC)
        $hashes = array_map(function ($r) { return json_decode($r, true)['hash']; }, $result);
        $this->assertSame(['0xreplaced', '0xkept', '0xpending_only'], $hashes);
    }

    public function testPaginationOffsetAndLimit()
    {
        // Four transactions, request 2 with offset 1 -> expect hash2, hash3
        $session = session([
            'status = 0' => page([
                tx('0xhash1', 1000),
                tx('0xhash2', 900),
                tx('0xhash3', 800),
                tx('0xhash4', 700),
            ]),
            'status = 1' => page([]),
        ]);

        $result = get_transactions($session, '0xaddr1', 2, 1);

        $hashes = array_map(function ($r) { return json_decode($r, true)['hash']; }, $result);
        $this->assertSame(['0xhash2', '0xhash3'], $hashes);
    }

    public function testPagingAcrossPagesMaintainsOrder()
    {
        // Simulate Cassandra paging: page1 then page2
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

        $result = get_transactions($session, '0xaddr1', 10, 0);
        $hashes = array_map(function ($r) { return json_decode($r, true)['hash']; }, $result);

        $this->assertSame(['0xhash1', '0xhash2', '0xhash3', '0xhash4'], $hashes);
    }

    public function testDirectionAndReceivedAtFormatting()
    {
        // direction=0 should flip add1/add2, and null receivedat should fallback to time
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

        $result = get_transactions($session, '0xaddr1', 1, 0);
        $tx = json_decode($result[0], true);

        $this->assertSame('B', $tx['addr_from']);
        $this->assertSame('A', $tx['addr_to']);
        $this->assertSame(1234, $tx['receivedat'], 'receivedat should default to time when null');
    }

    public function testOnlyPendingTransactionReturned()
    {
        $session = session([
            'status = 0' => page([]),
            'status = 1' => page([
                tx('0xpending', 1000, 1),
            ]),
        ]);

        $result = get_transactions($session, '0xaddr1', 5, 0);
        $this->assertCount(1, $result);
        $tx = json_decode($result[0], true);
        $this->assertSame(1, $tx['status']);
        $this->assertSame('0xpending', $tx['hash']);
    }

    public function testPendingAndConfirmedDifferentHashes()
    {
        $session = session([
            'status = 0' => page([
                tx('0xconfirmed', 900, 0),
            ]),
            'status = 1' => page([
                tx('0xpending', 1000, 1),
            ]),
        ]);

        $result = get_transactions($session, '0xaddr1', 5, 0);
        $this->assertCount(2, $result);
        $hashes = array_map(function ($r) { return json_decode($r, true)['hash']; }, $result);
        $this->assertSame(['0xpending', '0xconfirmed'], $hashes);
    }

}
