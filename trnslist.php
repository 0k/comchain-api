<?php

require_once __DIR__ . '/includes/cassandra.inc';

function get_transactions($session, $addr, $limit, $offset) {
    $needed = $offset + $limit;
    $page_size = TXS_CASSANDRA_QUERY_PAGE_SIZE;
    $pending_cutoff = time() - TXS_PENDING_CUTOFF_AGE;

    $iters = [
        paged_rows($session->execute(
            new Cassandra\SimpleStatement("SELECT * FROM testtransactions WHERE add1 = ? AND status = 0 ORDER BY time DESC"),
            ['arguments' => [$addr], 'page_size' => $page_size]
        )),
        paged_rows($session->execute(
            new Cassandra\SimpleStatement("SELECT * FROM testtransactions WHERE add1 = ? AND status = 1 AND time>=". $pending_cutoff ." ORDER BY time DESC"),
            ['arguments' => [$addr], 'page_size' => $page_size]
        )),
    ];

    // Remove exhausted iterators
    $iters = array_filter($iters, function ($it) {
        return $it->valid();
    });

    $seen = [];
    $seen_idx = [];
    $txs = [];
    $txs_count = 0;
    $enough_but_remaining_seen = false;

    // Merge all streams by time DESC, deduplicating
    while ($iters) {
        // Check if we have enough but still have pending transactions to close
        if ($txs_count >= $needed) {
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
            foreach ($seen_idx as $h => $sidx) {
                if ($txs[$sidx]['time']->value() - $last_time < TXS_PENDING_CLOSURE_PAST_LOOKUP_LIMIT) {
                    $found = true;
                    break;
                }
                unset($seen_idx[$h]);   // too old
            }
            if (!$found) break;
            $enough_but_remaining_seen = false;
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
                // Once we keep a status 0 version, we no longer need an index tracked.
                if ($row['status'] == 0) {
                    unset($seen_idx[$hash]);
                }
            }

            if ($txs_count >= $needed)
                continue;

            $seen[$hash] = $row['status'];
            continue;
        }

        if ($txs_count >= $needed)
            continue;

        $seen[$hash] = $row['status'];
        // Only track index when status > 0; status 0 is final and won't be replaced.
        if ($row['status'] > 0) {
            $seen_idx[$hash] = $txs_count;
        }

        $txs[] = $row;
        $txs_count++;
    }

    // Apply pagination
    $txs = array_slice($txs, $offset);

    // Format output
    $output = [];
    foreach ($txs as $row) {
        if ($row['direction'] == 1) {
            $row['addr_from'] = $row['add1'];
            $row['addr_to'] = $row['add2'];
        } else {
            $row['addr_from'] = $row['add2'];
            $row['addr_to'] = $row['add1'];
        }

        $row['time'] = $row['time']->value();
        // for old transaction without receivedat
        $row['receivedat'] = !is_null($row['receivedat'])
            ? $row['receivedat']->value()
            : $row['time'];

        $output[] = json_encode($row);
    }

    return $output;
}

// @codeCoverageIgnoreStart
// Main entry point - only runs when executed directly
if (realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
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

    // Validate and parse input
    if (strlen($_GET['addr'] ?? '') != 42) {
        echo "Bye!";
        exit;
    }
    $addr = strtolower(preg_replace("/[^a-zA-Z0-9]+/", "", $_GET['addr']));
    $limit = is_numeric($_GET['count'] ?? '') ? (int)$_GET['count'] : 5;
    $offset = is_numeric($_GET['offset'] ?? '') ? (int)$_GET['offset'] : 0;

    // Connect to Cassandra
    $cluster = Cassandra::cluster('127.0.0.1')
        ->withCredentials("transactions_ro", "Public_transactions")
        ->build();
    $session = $cluster->connect('comchain');

    echo json_encode(get_transactions($session, $addr, $limit, $offset));
}
// @codeCoverageIgnoreEnd
?>
