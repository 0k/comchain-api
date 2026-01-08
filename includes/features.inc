<?php

/**
 * Feature negotiation helpers
 *
 * Implements the feature negotiation protocol using X-Client-Features
 * and X-Selected-Features headers.
 *
 * @see feature-negociation.md for protocol specification
 */

/**
 * Parse X-Client-Features header into array of individual features
 *
 * @param string $header The header value (e.g., "txs/0-2 search/1")
 * @return array Array of individual features (e.g., ["txs/0", "txs/1", "txs/2", "search/1"])
 */
function parse_features($header) {
    $features = [];
    foreach (explode(' ', trim($header)) as $spec) {
        if ($spec === '' || strpos($spec, '/') === false) continue;
        list($name, $versions) = explode('/', $spec, 2);
        foreach (explode(',', $versions) as $range) {
            if (strpos($range, '-') !== false) {
                list($start, $end) = explode('-', $range, 2);
                for ($i = (int)$start; $i <= (int)$end; $i++) {
                    $features[] = "$name/$i";
                }
            } else {
                $features[] = "$name/$range";
            }
        }
    }
    return $features;
}

/**
 * Perform feature negotiation
 *
 * @param array $supported_features Features supported by this endpoint
 * @return array|null Array of common features, or null if negotiation fails (406 already sent)
 */
function negotiate_features($supported_features) {
    $client_header = $_SERVER['HTTP_X_CLIENT_FEATURES'] ?? '';

    if ($client_header === '') {
        http_response_code(406);
        echo json_encode([
            'error' => 'Missing required header: X-Client-Features',
            'supported' => $supported_features
        ]);
        return null;
    }

    $client_features = parse_features($client_header);
    $common = array_values(array_intersect($supported_features, $client_features));

    if (empty($common)) {
        http_response_code(406);
        echo json_encode([
            'error' => 'No common feature found',
            'supported' => $supported_features
        ]);
        return null;
    }

    header('X-Selected-Features: ' . implode(' ', $common));
    return $common;
}
