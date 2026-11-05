<?php

/**
 * Raw transaction decoding
 *
 * A signed legacy Ethereum transaction is the RLP encoding of the list
 * [nonce, gasPrice, gas, to, value, data, v, r, s]. RLP drops leading
 * zero bytes of integers, so r and s are 32 bytes long only most of the
 * time: about 1 signature in 128 has a shorter r or s. Fields must
 * therefore be located by decoding the RLP structure, never by fixed
 * offsets.
 */

const RAWTX_FIELDS = ['nonce', 'gasPrice', 'gas', 'to', 'value', 'data', 'v', 'r', 's'];
const RAWTX_UNSIGNED_FIELD_COUNT = 6;  // nonce .. data
const ABI_SELECTOR_SIZE = 4;
const ABI_WORD_SIZE = 32;
const ADDRESS_SIZE = 20;

/**
 * Read the RLP header of the item starting at byte $pos of $bin.
 *
 * @return array [is_list, payload_start, payload_length]
 * @throws InvalidArgumentException if the item does not fit before $limit
 */
function rlpReadHeader($bin, $pos, $limit) {
    if ($pos >= $limit) {
        throw new InvalidArgumentException("RLP: item expected at byte $pos, found end of data");
    }
    $prefix = ord($bin[$pos]);
    if ($prefix < 0x80) {
        list($is_list, $start, $length) = [false, $pos, 1];
    } else if ($prefix <= 0xb7) {
        list($is_list, $start, $length) = [false, $pos + 1, $prefix - 0x80];
    } else if ($prefix <= 0xbf) {
        $length_size = $prefix - 0xb7;
        list($is_list, $start, $length) =
            [false, $pos + 1 + $length_size, rlpReadLength($bin, $pos + 1, $length_size, $limit)];
    } else if ($prefix <= 0xf7) {
        list($is_list, $start, $length) = [true, $pos + 1, $prefix - 0xc0];
    } else {
        $length_size = $prefix - 0xf7;
        list($is_list, $start, $length) =
            [true, $pos + 1 + $length_size, rlpReadLength($bin, $pos + 1, $length_size, $limit)];
    }
    if ($start + $length > $limit) {
        throw new InvalidArgumentException("RLP: item at byte $pos is truncated");
    }
    return [$is_list, $start, $length];
}

/**
 * Read the big-endian length of $size bytes starting at $pos.
 */
function rlpReadLength($bin, $pos, $size, $limit) {
    if ($pos + $size > $limit) {
        throw new InvalidArgumentException("RLP: length at byte $pos is truncated");
    }
    $length = 0;
    for ($i = 0; $i < $size; $i++) {
        $length = $length * 256 + ord($bin[$pos + $i]);
    }
    return $length;
}

/**
 * Decode a signed legacy raw transaction.
 *
 * @param string $rawtx hexadecimal, with or without the "0x" prefix
 * @return array the fields of RAWTX_FIELDS as lowercase hexadecimal
 *               strings without prefix (as encoded: no leading zero
 *               bytes, empty string for zero), plus 'unsigned': the
 *               hexadecimal RLP encoding of the six unsigned fields
 *               (nonce .. data) without their list header.
 * @throws InvalidArgumentException if $rawtx is not such a transaction
 */
function decodeRawTransaction($rawtx) {
    $hex = strtolower(preg_replace('/^0x/i', '', $rawtx));
    if ($hex === '' || strlen($hex) % 2 !== 0 || !ctype_xdigit($hex)) {
        throw new InvalidArgumentException('Raw transaction is not a non-empty even-length hexadecimal string');
    }
    $bin = hex2bin($hex);
    $size = strlen($bin);

    list($is_list, $start, $length) = rlpReadHeader($bin, 0, $size);
    if (!$is_list) {
        throw new InvalidArgumentException('Raw transaction is not an RLP list');
    }
    if ($start + $length !== $size) {
        throw new InvalidArgumentException('Raw transaction has trailing bytes after its RLP list');
    }

    $fields = [];
    $unsigned_end = null;
    $pos = $start;
    while ($pos < $size) {
        list($item_is_list, $item_start, $item_length) = rlpReadHeader($bin, $pos, $size);
        if ($item_is_list) {
            throw new InvalidArgumentException('Raw transaction field ' . count($fields) . ' is a list');
        }
        $fields[] = bin2hex(substr($bin, $item_start, $item_length));
        $pos = $item_start + $item_length;
        if (count($fields) === RAWTX_UNSIGNED_FIELD_COUNT) {
            $unsigned_end = $pos;
        }
    }
    if (count($fields) !== count(RAWTX_FIELDS)) {
        throw new InvalidArgumentException('Raw transaction has ' . count($fields) . ' fields, expected ' . count(RAWTX_FIELDS));
    }

    $tx = array_combine(RAWTX_FIELDS, $fields);
    if ($tx['to'] !== '' && strlen($tx['to']) !== 2 * ADDRESS_SIZE) {
        throw new InvalidArgumentException('Raw transaction recipient is not a 20-byte address');
    }
    $tx['unsigned'] = bin2hex(substr($bin, $start, $unsigned_end - $start));
    return $tx;
}

/**
 * Decode the contract call carried by a signed raw transaction.
 *
 * @return array|null ['contract' => '0x' + 40 hex, 'selector' => 8 hex,
 *                    'args' => list of 64-hex ABI words], or null when
 *                    the transaction is not a call (no recipient, or data
 *                    that is not a selector followed by whole words).
 * @throws InvalidArgumentException if $rawtx is not a raw transaction
 */
function decodeContractCall($rawtx) {
    $tx = decodeRawTransaction($rawtx);
    $data_size = strlen($tx['data']) / 2;
    if ($tx['to'] === '' || $data_size < ABI_SELECTOR_SIZE
        || ($data_size - ABI_SELECTOR_SIZE) % ABI_WORD_SIZE !== 0) {
        return null;
    }
    $args_hex = substr($tx['data'], 2 * ABI_SELECTOR_SIZE);
    return [
        'contract' => '0x' . $tx['to'],
        'selector' => substr($tx['data'], 0, 2 * ABI_SELECTOR_SIZE),
        'args' => $args_hex === '' ? [] : str_split($args_hex, 2 * ABI_WORD_SIZE),
    ];
}
