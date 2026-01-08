<?php

/**
 * v1/txs.php - Cursor-based pagination API for transactions
 *
 * GET parameters:
 *   - addr: (required) wallet address (42 chars)
 *   - n: (required) signed integer
 *        - Without cursor: must be positive, returns last n transactions
 *        - With cursor, negative: get |n| transactions BEFORE cursor (more recent)
 *        - With cursor, positive: get n transactions AFTER cursor (older)
 *   - cursor_time: (optional) timestamp of cursor transaction
 *   - cursor_hash: (optional) hash of cursor transaction
 */

require_once __DIR__ . '/../includes/cassandra.inc';

/**
 * Validate and parse GET parameters for the txs endpoint
 *
 * @param array $params The GET parameters
 * @return array Either ['error' => string] or ['addr' => string, 'n' => int, 'cursor_time' => int|null, 'cursor_hash' => string|null]
 */
function txs_entrypoint($params) {
    // Validate address
    if (strlen($params['addr'] ?? '') != 42) {
        return ['error' => 'Invalid address'];
    }
    $addr = strtolower(preg_replace("/[^a-zA-Z0-9]+/", "", $params['addr']));

    // Validate n parameter
    if (!isset($params['n']) || !is_numeric($params['n']) || (int)$params['n'] == 0) {
        return ['error' => 'n is required and must be a non-zero integer'];
    }
    $n = (int)$params['n'];

    // Parse optional cursor parameters
    $cursor_time = null;
    $cursor_hash = null;

    $has_time = isset($params['cursor_time']);
    $has_hash = isset($params['cursor_hash']);

    if ($has_time && $has_hash) {
        if (!is_numeric($params['cursor_time'])) {
            return ['error' => 'cursor_time must be numeric'];
        }
        $cursor_time = (int)$params['cursor_time'];
        $cursor_hash = $params['cursor_hash'];
    } elseif ($has_time || $has_hash) {
        return ['error' => 'Both cursor_time and cursor_hash must be provided together'];
    } else {
        // No cursor - n must be positive
        if ($n < 0) {
            return ['error' => 'n must be positive when no cursor is provided'];
        }
    }

    return [
        'addr' => $addr,
        'n' => $n,
        'cursor_time' => $cursor_time,
        'cursor_hash' => $cursor_hash,
    ];
}

/**
 * Check if a cursor (time, hash) exists in the database
 *
 * @param object $session Cassandra session
 * @param string $addr Wallet address
 * @param int $time Transaction timestamp
 * @param string $hash Transaction hash
 * @return bool True if cursor exists
 */
function txs_cursor_exists($session, $addr, $time, $hash) {
    // Try status = 0 first (most likely), then status = 1
    foreach ([0, 1] as $status) {
        $result = $session->execute(
            new Cassandra\SimpleStatement(
                "SELECT hash FROM testtransactions WHERE add1 = ? AND time = ? AND status = $status AND hash = ?"
            ),
            ['arguments' => [$addr, $time, $hash]]
        );
        foreach ($result as $row) {
            return true;
        }
    }
    return false;
}

/**
 * Get transactions with cursor-based pagination
 *
 * @param object $session Cassandra session
 * @param string $addr Wallet address
 * @param int $n Signed integer: count (magnitude) and direction (sign when cursor provided)
 * @param int|null $cursor_time Timestamp of cursor transaction (optional)
 * @param string|null $cursor_hash Hash of cursor transaction (optional)
 * @return array Array of transaction rows, or ['error' => string] on failure
 */
function txs_get($session, $addr, $n, $cursor_time = null, $cursor_hash = null) {
    if (abs($n) > TXS_MAX_QUERY_LIMIT) {
        return ['error' => '|n| exceeds maximum limit of ' . TXS_MAX_QUERY_LIMIT];
    }

    $pending_cutoff = time() - TXS_PENDING_CUTOFF_AGE;

    // Determine direction and count
    $count = abs($n);
    $has_cursor = $cursor_time !== null && $cursor_hash !== null;
    $is_direction_after = $n >= 0;

    if ($has_cursor) {
        // Validate cursor exists
        if (!txs_cursor_exists($session, $addr, $cursor_time, $cursor_hash)) {
            return ['error' => 'Cursor not found'];
        }
    }

    $iters = [
        paged_rows($session->execute(
            new Cassandra\SimpleStatement("SELECT * FROM testtransactions WHERE add1 = ? AND status = 0 ORDER BY time DESC"),
            ['arguments' => [$addr], 'page_size' => TXS_CASSANDRA_QUERY_PAGE_SIZE]
        )),
        paged_rows($session->execute(
            new Cassandra\SimpleStatement("SELECT * FROM testtransactions WHERE add1 = ? AND status = 1 AND time>=". $pending_cutoff ." ORDER BY time DESC"),
            ['arguments' => [$addr], 'page_size' => TXS_CASSANDRA_QUERY_PAGE_SIZE]
        )),
    ];

    // Remove exhausted iterators
    $iters = array_filter($iters, function ($it) {
        return $it->valid();
    });

    $seen = [];      // all hash seen yet (required for deduplication)
    $seen_idx = [];  // keep index of pending txs to close
    $txs = [];
    $txs_count = 0;
    $cursor_rank = $cursor_time !== null ? [$cursor_time, $cursor_hash] : null;
    $enough_but_remaining_seen = false;

    // For 'before' direction: collect all txs more recent than cursor, then take last N
    // For 'after' direction: skip until past cursor (or start if no cursor), then collect N

    // Merge all streams by time DESC, deduplicating
    while ($iters) {
        // For 'after': stop when we have enough
        if ($is_direction_after && $txs_count >= $count) {
            if (empty($seen_idx)) {
                break;
            }
            $enough_but_remaining_seen = true;
        }

        // Find iterator with highest time (most recent), hash as tiebreaker
        $best_key = null;
        $best_rank = null;
        foreach ($iters as $key => $iter) {
            $row = $iter->current();
            $rank = [$row['time']->value(), $row['hash']];
            if ($best_rank === null || $rank > $best_rank) {
                $best_key = $key;
                $best_rank = $rank;
            }
        }

        // Get row and advance iterator
        $best_iter = $iters[$best_key];
        $row = $best_iter->current();
        $best_iter->next();
        if (!$best_iter->valid()) {
            unset($iters[$best_key]);
        }

        if ($enough_but_remaining_seen) {
            // Allow to look for more transactions in the past to
            // close possible pending transactions
            $last_time = $row['time']->value();
            $found = false;
            foreach($seen_idx as $h => $sidx) {
                if ($txs[$sidx]['time']->value() - $last_time < TXS_PENDING_CLOSURE_PAST_LOOKUP_LIMIT) {
                    $found = true;
                    break;
                }
                unset($seen_idx[$h]);   // too old
            }
            if (!$found) break;
            $enough_but_remaining_seen = false;
        }

        $current_rank = [$row['time']->value(), $row['hash']];

        // Handle cursor-based filtering
        if ($has_cursor) {
            if ($is_direction_after) {
                // We want txs OLDER than cursor
                // Skip until we pass the cursor
                if ($current_rank >= $cursor_rank) {
                    continue;
                }
            } else {
                // We want txs MORE RECENT than cursor
                // When we reach the cursor, continue looking for pending closures
                if ($current_rank <= $cursor_rank) {
                    if (empty($seen_idx)) {
                        break;
                    }
                    $enough_but_remaining_seen = true;
                }
            }
        }

        // Deduplicate by hash
        $hash = $row['hash'];
        if (isset($seen[$hash])) {
            // Status 0 is final.
            if ($seen[$hash] == 0) {
                continue;
            }
            // Replace previously stored row with the lower-status one
            if (isset($seen_idx[$hash])) {
                $txs[$seen_idx[$hash]] = $row;
            }

            if ($enough_but_remaining_seen)
                continue;

            $seen[$hash] = $row['status'];
            // Once we keep a status 0 version, we no longer need an index tracked.
            if ($row['status'] == 0) {
                unset($seen_idx[$hash]);
            }
            continue;
        }

        if ($enough_but_remaining_seen)
            continue;

        $seen[$hash] = $row['status'];
        // Only track index when status > 0; status 0 is final and won't be replaced.
        if ($row['status'] > 0) {
            $seen_idx[$hash] = $txs_count;
        }

        $txs[] = $row;
        $txs_count++;

        // For 'before' direction: crop buffer when it exceeds max to avoid memory issues
        if ($has_cursor && !$is_direction_after && $txs_count > TXS_RECENT_MAX_BUFFER_TX_COUNT) {
            $txs = array_slice($txs, -$count, null, true);  // preserve indexes
            $txs_count = count($txs);
        }
    }

    // Apply count limit
    if ($has_cursor && !$is_direction_after) {
        // For 'before': take the last N (closest to cursor)
        $txs = array_slice($txs, -$count);
    } else {
        // For 'after' and no-cursor: take first N
        $txs = array_slice($txs, 0, $count);
    }

    // Format output
    $output = [];
    foreach ($txs as $row) {
        $tx = [];
        $tx['hash'] = $row['hash'];
        $tx['status'] = $row['status'];
        $tx['time'] = $row['time']->value();
        $tx['receivedat'] = !is_null($row['receivedat'])
            ? $row['receivedat']->value()
            : $tx['time'];

        if ($row['direction'] == 1) {
            $tx['addr_from'] = $row['add1'];
            $tx['addr_to'] = $row['add2'];
        } else {
            $tx['addr_from'] = $row['add2'];
            $tx['addr_to'] = $row['add1'];
        }

        $tx['direction'] = $row['direction'];
        $tx['add1'] = $row['add1'];
        $tx['add2'] = $row['add2'];

        $output[] = $tx;
    }

    return $output;
}

// @codeCoverageIgnoreStart
// Main entry point - only runs when executed directly
if (realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    /**
     * Maximum number of transactions that can be requested in a single query.
     */
    define('TXS_MAX_QUERY_LIMIT', 100);

    /**
     * Maximum buffer size for "before" direction queries.
     * Must be significantly higher than TXS_MAX_QUERY_LIMIT to avoid
     */
    define('TXS_RECENT_MAX_BUFFER_TX_COUNT', 500);

    /**
     * Page size for Cassandra queries.
     */
    define('TXS_CASSANDRA_QUERY_PAGE_SIZE', 50);

    /**
     * Maximum age in seconds for pending transactions to be included.
     */
    define('TXS_PENDING_CUTOFF_AGE', 3600);

    /**
     * How far back in time (seconds) to look for confirmed versions of pending transactions.
     */
    define('TXS_PENDING_CLOSURE_PAST_LOOKUP_LIMIT', 24 * 3600);

    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json');

    $result = txs_entrypoint($_GET);

    if (isset($result['error'])) {
        http_response_code(400);
        echo json_encode($result);
        exit;
    }

    // Connect to Cassandra
    $cluster = Cassandra::cluster('127.0.0.1')
        ->withCredentials("transactions_ro", "Public_transactions")
        ->build();
    $session = $cluster->connect('comchain');

    $txs = txs_get(
        $session,
        $result['addr'],
        $result['n'],
        $result['cursor_time'],
        $result['cursor_hash']
    );

    if (isset($txs['error'])) {
        http_response_code(400);
        echo json_encode($txs);
        exit;
    }

    echo json_encode($txs);
}
// @codeCoverageIgnoreEnd
?>
