<?php
require_once './CryptoCurrencyPHP/PointMathGMP.class.php';
require_once './CryptoCurrencyPHP/SECp256k1.class.php';
require_once './CryptoCurrencyPHP/Signature.class.php';
require_once './vendor/autoload.php';
require_once './Keccak.php';
use kornrunner\Keccak;

require_once './Buffer.php';
require_once __DIR__ . '/includes/rawtx.inc';

function encodeLength(int $l, int $offset)
    {
        if ($l < 56) {
            return Buffer::int($l + $offset)->getHex();
        }
        if ($l < 256 ** 8) {
            /** @var string $bl */
            $bl = Buffer::int($l)->getBinary();
            return (Buffer::int(strlen($bl) + $offset + 55)->getHex()) . (Buffer::int($l)->getHex());
        }
        throw new \Exception('Failed to encode length');
    }


function TransactionEcRecover($rawTx) {
    $tx = decodeRawTransaction($rawTx);
    // RLP drops leading zero bytes: restore r and s to 32 bytes, v to 1.
    $signed = '0x'.str_pad($tx['r'], 64, '0', STR_PAD_LEFT)
                  .str_pad($tx['s'], 64, '0', STR_PAD_LEFT)
                  .str_pad($tx['v'], 2, '0', STR_PAD_LEFT);

    // the signed hash covers the RLP list of the unsigned fields
    $data = encodeLength(strlen($tx['unsigned'])/2, 192).$tx['unsigned'];
    $hash = keccak256WithPrefix(hex2bin($data));
    return ecRecoverPublic($hash, $signed);
}


function personal_ecRecover($msg, $signed) {
    return personal_ecRecoverPublic($msg, $signed)[0];
}

function ecRecover($hex, $signed) {
    return ecRecoverPublic($hex, $signed)[0];
}

function personal_ecRecoverPublic($msg, $signed) {
    $personal_prefix_msg = "\x19Ethereum Signed Message:\n". strlen($msg). $msg;
    $hex = keccak256WithPrefix($personal_prefix_msg);
    return ecRecoverPublic($hex, $signed);
}

function keyToAddress($publicKeyString) {
    return '0x'. substr(keccak256WithPrefix(hex2bin($publicKeyString)), -40);
}
    
function ecRecoverPublic($hex, $signed) {
    $rHex   = substr($signed, 2, 64);
    $sHex   = substr($signed, 66, 64);
    $vValue = hexdec(substr($signed, 130, 2));
    $messageHex       = substr($hex, 2);
    $messageByteArray = unpack('C*', hex2bin($messageHex));
    $messageGmp       = gmp_init("0x" . $messageHex);
    $r = $rHex;		//hex string without 0x
    $s = $sHex; 	//hex string without 0x
    $v = $vValue; 	//27 or 28

    //with hex2bin it gives the same byte array as the javascript
    $rByteArray = unpack('C*', hex2bin($r));
    $sByteArray = unpack('C*', hex2bin($s));
    $rGmp = gmp_init("0x" . $r);
    $sGmp = gmp_init("0x" . $s);

    $recovery = $v - 27;
    if ($recovery !== 0 && $recovery !== 1) {
        throw new Exception('Invalid signature v value');
    }

    $publicKey = Signature::recoverPublicKey($rGmp, $sGmp, $messageGmp, $recovery);
    $publicKeyString = $publicKey["x"] . $publicKey["y"];

    return array(keyToAddress($publicKeyString),$publicKeyString);
}

function strToHex($string)
{
    $hex = unpack('H*', $string);
    return '0x' . array_shift($hex);
}

function keccak256WithPrefix($str) {
    return '0x'. Keccak::hash($str, 256);
}

?>
