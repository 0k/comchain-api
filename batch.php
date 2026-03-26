<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
require_once 'libs/jsonRPCClient.php';

$gethRPC = new jsonRPCClient('http://127.0.0.1:8545');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['error' => true, 'msg' => 'Expected JSON array']);
    exit;
}

$REJECTED = ['rawtx', 'txdata', 'hash'];

$requests = [];
$mapping = [];  // tracks which input index maps to which RPC call(s)

foreach ($input as $idx => $item) {
    // Check for rejected operations
    foreach ($REJECTED as $key) {
        if (isset($item[$key])) {
            $mapping[$idx] = ['rejected' => "Operation '$key' is not supported in batch"];
            continue 2;
        }
    }

    if (isset($item['balance'])) {
        $addr = formatAddress($item['balance']);
        $blockNb = isset($item['blockNb']) ? $item['blockNb'] : 'pending';
        $mapping[$idx] = ['rpc_index' => count($requests), 'type' => 'balance', 'addr' => $addr];
        $requests[] = [
            'method' => 'eth_getBalance',
            'params' => [$addr, $blockNb],
        ];
    } else if (isset($item['ethCallAt']) && isset($item['blockNb'])) {
        $tx = [
            'to'   => $item['ethCallAt']['to'],
            'data' => $item['ethCallAt']['data'],
        ];
        $mapping[$idx] = ['rpc_index' => count($requests), 'type' => 'ethCall'];
        $requests[] = [
            'method' => 'eth_call',
            'params' => [$tx, $item['blockNb']],
        ];
    } else if (isset($item['ethCall'])) {
        $tx = [
            'to'   => $item['ethCall']['to'],
            'data' => $item['ethCall']['data'],
        ];
        $mapping[$idx] = ['rpc_index' => count($requests), 'type' => 'ethCall'];
        $requests[] = [
            'method' => 'eth_call',
            'params' => [$tx, 'pending'],
        ];
    } else if (isset($item['estimatedGas'])) {
        $mapping[$idx] = ['rpc_index' => count($requests), 'type' => 'estimatedGas'];
        $requests[] = [
            'method' => 'eth_estimateGas',
            'params' => [$item['estimatedGas']],
        ];
    } else if (isset($item['blockByNumber'])) {
        $mapping[$idx] = ['rpc_index' => count($requests), 'type' => 'block'];
        $requests[] = [
            'method' => 'eth_getBlockByNumber',
            'params' => [$item['blockByNumber'], false],
        ];
    } else if (isset($item['blockNumber'])) {
        $mapping[$idx] = ['rpc_index' => count($requests), 'type' => 'blockNumber'];
        $requests[] = [
            'method' => 'eth_blockNumber',
            'params' => [],
        ];
    } else {
        $mapping[$idx] = ['rejected' => 'Unknown operation'];
    }
}

// Send single batched RPC call to geth
try {
    $responses = count($requests) > 0 ? $gethRPC->batch($requests) : [];
} catch (Exception $e) {
    echo json_encode(['error' => true, 'msg' => $e->getMessage()]);
    exit;
}

// Map RPC responses back to input items
$out = [];
for ($idx = 0; $idx < count($input); $idx++) {
    $map = $mapping[$idx];

    if (isset($map['rejected'])) {
        $out[] = ['error' => ['msg' => $map['rejected']]];
        continue;
    }

    $r = $responses[$map['rpc_index']];

    if (isset($r['error'])) {
        $msg = isset($r['error']['message']) ? $r['error']['message'] : 'unknown error';
        $out[] = ['error' => ['msg' => $msg]];
        continue;
    }

    if ($map['type'] === 'balance') {
        $balancehex = $r['result'];
        $out[] = ['data' => [
            'address'    => $map['addr'],
            'balance'    => bchexdec($balancehex),
            'balancehex' => $balancehex,
        ]];
    } else {
        $out[] = ['data' => $r['result']];
    }
}

echo json_encode(['error' => false, 'data' => $out]);


// -- Helpers (duplicated from api.php to avoid including its routing code) --

function formatAddress($addr) {
    if (substr($addr, 0, 2) == "0x")
        return $addr;
    else
        return "0x" . $addr;
}

function bchexdec($hex) {
    if (substr($hex, 0, 2) == "0x") {
        $hex = substr($hex, 2);
    }
    $dec = 0;
    $len = strlen($hex);
    for ($i = 1; $i <= $len; $i++) {
        $dec = bcadd($dec, bcmul(strval(hexdec($hex[$i - 1])), bcpow('16', strval($len - $i))));
    }
    return $dec;
}

?>
