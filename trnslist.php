<?php

// Generator that yields rows across pages
function paged_rows($page) {
    while (true) {
        foreach ($page as $row) {
            yield $row;
        }
        if ($page->isLastPage()) break;
        $page = $page->nextPage();
    }
}

function get_transactions($session, $addr, $limit, $offset) {
    $needed = $offset + $limit;
    $page_size = 50;
    $pending_cutoff = time() - 3600;

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
    $txs = [];
    $txs_count = 0;

    // Merge all streams by time DESC, deduplicating
    while ($iters) {
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

        // Deduplicate by hash
        $hash = $row['hash'];
        if (isset($seen[$hash]) && $seen[$hash] <= $row['status']) {
            continue;
        }
        $seen[$hash] = $row['status'];

        $txs[] = $row;
        if (++$txs_count >= $needed) break;
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
