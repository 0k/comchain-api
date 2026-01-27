<?php
header('Access-Control-Allow-Origin: *');
include './checkAdmin.php';
include './Webhook.php';
require_once 'libs/jsonRPCClient.php';

$gethRPC = new jsonRPCClient('http://127.0.0.1:8545');
if(isset($_REQUEST['balance'])){
    header('Content-Type: application/json');
    echo getEthBalance($_REQUEST['balance'],$gethRPC);
} else if(isset($_REQUEST['rawtx'])){
    header('Content-Type: application/json');
    echo sendRawTransaction($_REQUEST['rawtx'],$gethRPC);
} else if(isset($_REQUEST['txdata'])){
    header('Content-Type: application/json');
    echo getTransactionData($_REQUEST['txdata'],$gethRPC);
} else if(isset($_REQUEST['estimatedGas'])){
    header('Content-Type: application/json');
    echo getEstimatedGas($_REQUEST['estimatedGas'],$gethRPC);
} else if(isset($_REQUEST['ethCall'])){
    header('Content-Type: application/json');
    echo getEthCall($_REQUEST['ethCall'],$gethRPC);
}else if(isset($_REQUEST['ethCallAt']) && isset($_REQUEST['blockNb'])){
    header('Content-Type: application/json');
    echo getEthCallAt($_REQUEST['ethCallAt'],$_REQUEST['blockNb'],$gethRPC);
} else if(isset($_REQUEST['hash'])){
    header('Content-Type: application/json');
    echo getTransaction($_REQUEST['hash'],$gethRPC);
} else if(isset($_REQUEST['batch'])){
    header('Content-Type: application/json');
    echo getBatchEthCallAt($_REQUEST['batch'],$gethRPC);
} else {
    header('Content-Type: application/json');
    echo blockNumber($gethRPC);
}
function blockNumber($gethRPC){
    $data = getDefaultResponse();
    try {
        $ret = getRPCResponse($gethRPC->eth_blockNumber());
        $data['data'] = $ret;
        $block=hexdec($ret);
    }
    catch (exception $e) {
        $data['error'] = true;
        $data['msg'] = $e->getMessage();
        error_log($e->getMessage(),0);
    }
    return $block;
}


function getEstimatedGas($txobj, $gethRPC){
    $data = getDefaultResponse();
    try {
        $data['data'] = getRPCResponse($gethRPC->eth_estimateGas($txobj));
    }
    catch (exception $e) {
        $data['error'] = true;
        $data['msg'] = $e->getMessage();
        error_log($e->getMessage(),0);
    }
    return json_encode($data);
}


function getTransaction($hash, $gethRPC){
    $data = getDefaultResponse();
    try {
        $ret = getRPCResponse($gethRPC->eth_getTransactionByHash($hash),
        "pending");
        $cluster  = Cassandra::cluster('127.0.0.1') ->withCredentials("transactions_ro", "Public_transactions")
                ->build();
        $keyspace  = 'comchain';
        $session  = $cluster->connect($keyspace);
        $query = "SELECT * FROM testtransactions WHERE hash = ?"; 
        $options = array('arguments' => array($hash));

	    foreach ($session->execute(new Cassandra\SimpleStatement($query), $options) as $row) {
	        if ($row['direction']==1){
	            if ( $row['status']==0 || !isset($arr)) {
	                $arr = $row;
	                $arr['addr_from'] = $arr['add1'];
                    $arr['addr_to'] = $arr['add2'];
                    $arr['time'] = $arr['time']->value();
                }
	        }
	        
	    }
	    
	    
        $arr['transaction'] = $ret;
        $ret = json_encode($arr);
        $data = $ret;
    }
    catch (exception $e) {
        $data['error'] = true;
        $data['msg'] = $e->getMessage();
        error_log($e->getMessage(),0);
    }
    return json_encode($data);
}




function getEthCall($txobj, $gethRPC){
    $data = getDefaultResponse();
    try {
        $data['data'] = getRPCResponse($gethRPC->eth_call($txobj,
        "pending"));
    }
    catch (exception $e) {
        $data['error'] = true;
        $data['msg'] = $e->getMessage();
        error_log($e->getMessage(),0);
    }
    return json_encode($data);
}


function getEthCallAt($txobj, $blockNb, $gethRPC){
    $data = getDefaultResponse();
    try {
        $data['data'] = getRPCResponse($gethRPC->eth_call($txobj,
        $blockNb));
    }
    catch (exception $e) {
        $data['error'] = true;
        $data['msg'] = $e->getMessage();
        error_log($e->getMessage(),0);
    }
    return json_encode($data);
}


function getTransactionData($addr, $gethRPC){
    $data = getDefaultResponse();
    try {
        $addr = formatAddress($addr);
        $balance = getRPCResponse($gethRPC->eth_getBalance($addr,
            "pending"));
        $nonce = getRPCResponse($gethRPC->eth_getTransactionCount($addr,
            "pending"));
        $gasprice = getRPCResponse($gethRPC->eth_gasPrice());
        $balance=bchexdec($balance);
        $tarr['address'] = $addr;
        $tarr['balance'] = $balance;
        $tarr['nonce'] = $nonce;
        $tarr['gasprice'] = $gasprice;
        $data['data'] = $tarr;
    }
    catch (exception $e) {
        $data['error'] = true;
        $data['msg'] = $e->getMessage();
        error_log($e->getMessage(),0);
    }
    return json_encode($data);
}
function getEthBalance($addr, $gethRPC)
{
    $data = getDefaultResponse();
    try {
        $addr = formatAddress($addr);
        $balancehex = getRPCResponse($gethRPC->eth_getBalance($addr,
            "pending"));
        $balance=bchexdec($balancehex);
        $tarr['address'] = $addr;
        $tarr['balance'] = $balance;
        $tarr['balancehex'] = $balancehex;
        $data['data'] = $tarr;
    }
    catch (exception $e) {
        $data['error'] = true;
        $data['msg'] = $e->getMessage();
        error_log($e->getMessage(),0);
    }
    return json_encode($data);
}
function getRPCResponse($result){
    if(isset($result['result'])){
        return $result['result'];
    } else {
        throw new Exception($result['error']['message']);
    }
}

// Lookup into the DB for a valid shop from the REQUEST parameters

function validateShop($shop_id, $server_name){
   $cluster  = Cassandra::cluster('127.0.0.1') ->withCredentials("transactions_ro", "Public_transactions")->build();
   $keyspace  = 'comchain';
   $session  = $cluster->connect($keyspace);
   $query = "SELECT webhook_url, server_name FROM sellers WHERE store_id = ? "; 
   $options = array('arguments' => array($shop_id)); 
   foreach ($session->execute(new Cassandra\SimpleStatement($query), $options) as $row) {
      if ($row['server_name']==$server_name){
	    return $row['webhook_url'];
      }
   }
   
   return "";
}


function validateShopData() {
    $shop_id=$_REQUEST['shopId'];
    $shop_ref=$_REQUEST['txId'];
    $server_name=$_REQUEST['serverName'];
    if (isset($shop_id) && isset($server_name) && isset($shop_ref)) {
        return validateShop( $shop_id, $server_name);
    } else {
        return "";
    }
}

function storeAdditionalData($is_valid_shop, $transaction_ash, $web_hook_status) {
    $shop_id=$_REQUEST['shopId'];
    $shop_ref=$_REQUEST['txId'];
    $delegate=$_REQUEST['delegate'];
    $memo_from=$_REQUEST['memo_from'];
    $memo_to=$_REQUEST['memo_to'];
    $parent_hash=$_REQUEST['parent_hash'];
    
    $do_insert=false;
    $fields =array();
    $val =array();
   
    $fields['wh_status'] = $web_hook_status;
    $val[]='?';
    
    if ($is_valid_shop) {
        $fields['store_id'] = $shop_id;
        $fields['store_ref'] = $shop_ref; 
        $val[]='?';  
        $val[]='?';      
    }
    
    if (isset($delegate)) {
        $fields['delegate'] = $delegate;
        $val[]='?';    
    }
    
    if (isset($parent_hash)) {
        $fields['linked_hash'] = $parent_hash;
        $val[]='?';    
    }
    
    if (isset($memo_from) && $memo_from!="") {
        $fields['message_from'] = $memo_from;
        $val[]='?';    
    }
    
    if (isset($memo_to) && $memo_to!="") {
        $fields['message_to'] = $memo_to;
        $val[]='?';    
    }
    
    $fields['hash'] = $transaction_ash;
    $val[]='?';    
    // build the query
    $query = "INSERT INTO webshop_transactions (".join(', ',array_keys($fields));
    $query = $query.',receivedAt) VALUES ('.join(', ',$val).",".time().")";
    
    $cluster  = Cassandra::cluster('127.0.0.1') ->withCredentials("webhook_rw", "Private_access_transactions")->build();
    $keyspace  = 'comchain';
    $session  = $cluster->connect($keyspace);
    $session->execute(new Cassandra\SimpleStatement($query), array('arguments' => $fields));
    
}

function storeTransaction($is_valid_shop, $transaction_ash, $web_hook_status, $amount, $from, $to, $trans_type, $currency) {
    $shop_id=$_REQUEST['shopId'];
    $shop_ref=$_REQUEST['txId'];
    $delegate=$_REQUEST['delegate'];
    $parent_hash=$_REQUEST['parent_hash'];
    $memo_from=$_REQUEST['memo_from'];
    $memo_to=$_REQUEST['memo_to'];
    
    $do_insert=false;
    $fields =array();
    $val =array();
    
    $fields['add1'] = $from;
    $val[]='?'; 
    $fields['add2'] = $to;
    $val[]='?'; 
    $fields['direction'] = 1;
    $val[]='?'; 
    
    $fields['status'] = 1;
    $val[]='?'; 
    
    $fields['hash'] = $transaction_ash;
    $val[]='?'; 
    
   
    $fields['sent'] = $amount;
    $val[]='?';
    $fields['recieved'] = $amount;
    $val[]='?';
    $fields['tax'] = 0;
    $val[]='?';
    $fields['type'] = $trans_type;
    $val[]='?'; 
    
    $fields['wh_status'] = $web_hook_status;
    $val[]='?';

    if ($is_valid_shop) {
        $fields['store_id'] = $shop_id;
        $fields['store_ref'] = $shop_ref; 
        $val[]='?'; 
        $val[]='?';    
    }
    
    if (isset($delegate)) {
        $fields['delegate'] = $delegate;
        $val[]='?';   
    }
    
    if (isset($parent_hash)) {
        $fields['linked_hash'] = $parent_hash;
        $val[]='?';    
    }
    
    if (isset($memo_from) && $memo_from!="") {
        $fields['message_from'] = $memo_from;
        $val[]='?';   
    }
    
    if (isset($memo_to) && $memo_to!="") {
        $fields['message_to'] = $memo_to;
        $val[]='?';   
    }
    
    if (isset($currency) && $currency!="") {
        $fields['currency'] = $currency;
        $val[]='?';   
    }  
    
    
    /* incompatibiliy between the number of bytes=> set in the text
    $fields['time'] = ''.time();
    $val[]='?';
    */

    $now = time();
    // build the query
    $query = "INSERT INTO testtransactions (".join(', ',array_keys($fields));
    $query = $query.',time,receivedAt) VALUES ('.join(', ',$val).','.$now.','.$now.')';
    
    $keyspace  = 'comchain';
    // for pledge only the other direction is inserted
    if ($trans_type!='Pledge') {
        $cluster  = Cassandra::cluster('127.0.0.1') ->withCredentials("transactions_rw", "Private_access_transactions")->build();
        $session1  = $cluster->connect($keyspace);
        $session1->execute(new Cassandra\SimpleStatement($query), array('arguments' => $fields));
    }
 
    $cluster2  = Cassandra::cluster('127.0.0.1') ->withCredentials("transactions_rw", "Private_access_transactions")->build();
    $session2  = $cluster2->connect($keyspace);
    $fields['add1'] = $to;
    $fields['add2'] = $from;
    $fields['direction'] = 2; 
    $session2->execute(new Cassandra\SimpleStatement($query), array('arguments' => $fields));
    
    
}

function getContract1($contract) { 
    $contract = '0x'.$contract;
    // legacy cases: Lemanopolis, Monnaie-Leman
    if (strtolower($contract)==strtolower("0xE616BE14B489c33c8CC3D5974A5DeAad6E4A33c8")){
        $contract="0x85291865Ac4b11b086EAf901E6116eba014244cE";
    } else if (strtolower($contract)==strtolower("0xF765D6608c9B4640BB0af860771793c41C4b8eF8")){
        $contract="0xB86C066396A6f21F17301E9acfec2a0Fc5c76116";
    }  
    
    return $contract;  

}


function in_my_array($needle, $array){
   
    foreach ($array as $elem) {
        if ($needle==$elem){
            return true;
        }
    }
    return false; 
}

function sendRawTransaction($rawtx,$gethRPC){
    $data = getDefaultResponse();
                            //   Direct      From    On Behalf   Accept Request
    $transfert_NA_functions = ['a5f7c148','58258353','1b6b1ee5','132019f4'];
    $transfert_CM_functions = ['60ca9c4c','2ef9ade2','74c421fe','1415707c'];
    $pledge_function = '6c343eef';
    $transfert_functs =  array_merge($transfert_NA_functions,$transfert_CM_functions);
    
    $lock_error = 'Account_Locked_Error';
    $amount_error = 'Incompatible_Amount';
    $restricted_transaction_type_error = 'Incompatible_Account_Types_Error';
    $currency = '';
   
    try {
    
        // length input 2 vs 3 x64
        $is_f2 = false;
        $is_f3 = false;
        if (strlen($rawtx)>316 && substr($rawtx,-274,4) == 'b844'){
            $is_f2 = true;
        } else if (strlen($rawtx)>380  && substr($rawtx,-338,4) == 'b864'){
            $is_f3 = true;
        }
        
        
        $need_pending = false;
        $amount = 0;
        $trans_type = '';
        $from_add = '';
        $to_add = '';
        $dbg ='';
        if ($is_f3){
            $tr_info = substr($rawtx,-380,246);
            //get the type of transfert
            $funct_address = strtolower(substr($tr_info,46,8));
            // get the sender
            $sender = TransactionEcRecover($rawtx)[0];
            // get the contract for the balances
            $contract = getContract1(substr($tr_info,0,40));
            $contract2 = '0x'.substr($tr_info,0,40);
            $currency = getCurrency($contract);
            if ($funct_address==$transfert_NA_functions[2] || $funct_address==$transfert_CM_functions[2]) {
                // Transfert On Behalf 
            
                // get the account debited
                $from_add = '0x'.substr($tr_info,78,40);
                // get the dest.
                $to_add = '0x'.substr($tr_info,142,40);
                // get the amount
                $amount = hexdec(substr($tr_info,-64));
                
                if (!checkPreventTransactionRule($from_add, $to_add, $contract)) {
                     $data["data"] = "TransactionBetweenForbidenAccountTypes";
                     throw new Exception($restricted_transaction_type_error);
                }
                
                // get the infos 
                $status = getAccountStatus(array($from_add, $to_add), $contract);  
                $from_status = $status[$from_add];
                $to_status = $status[$to_add];
                
                 
                $from_Nt_bal = getNTBalance($from_add, $contract);
                $from_Cm_bal = getCMBalance($from_add, $contract);
                $from_Cm_lim_m = getCMLimitM($from_add, $contract);
                
                $to_Cm_bal = getCMBalance($to_add, $contract);
                $to_Cm_lim_p = getCMLimitP($to_add, $contract);
                
                if ($from_status==1 && $to_status==1){
                    // Check delegation exists and is above amount
                    $delegation_amount = getNumberInMap($from_add, $sender, $contract2, '0x046d3307');  // Address order OK
                    
                    if ($amount>$delegation_amount) {
                        $data["data"] = "InsufficientDelegatedAmount";
                        throw new Exception($amount_error);
                    }
                    
                    // Check transaction is possible
                    if ($funct_address==$transfert_NA_functions[2]) {  // Nanti
                        if ($amount>$from_Nt_bal) {
                            $data["data"] = "InsufficientNantBalance";
                            throw new Exception($amount_error);
                        }
                        $trans_type = 'Transfer';
                    } else if ($funct_address==$transfert_CM_functions[2]) { // Mutual Credit
                        // dest can accept amount
                        if ($to_Cm_bal + $amount >= $to_Cm_lim_p) {
                            $data["data"] = "ReceiverWouldHitCMLimitMax";
                            throw new Exception($amount_error);
                        }
                        // sender has credit 
                        if ($from_Cm_bal - $amount <= $from_Cm_lim_m) {
                            $data["data"] = "SenderWouldHitCMLimitMin";
                            throw new Exception($amount_error);
                        }
                        $trans_type = 'TransferCredit';
                    } else {
                        $data["data"] = "UnexpectedTransferAddress";
                        throw new Exception($amount_error);
                    }

                    $need_pending = true;
                } else {  
                    throw new Exception($lock_error);
                }
            }
        } else if ($is_f2){
            $tr_info = substr($rawtx,-316,182);
            //get the type of transfert
            $funct_address = strtolower(substr($tr_info,46,8));
            // get the sender
            $sender = TransactionEcRecover($rawtx)[0];
            // get the contract for the balances
            $contract = getContract1(substr($tr_info,0,40));
            $contract2 = '0x'.substr($tr_info,0,40);
            $currency = getCurrency($contract);
            // get the dest
            $dest = '0x'.substr($tr_info,78,40);
            // get the amount
            $amount = hexdec(substr($tr_info,-64));
            
            if (in_my_array($funct_address, $transfert_functs)) {

                
                $status = getAccountStatus(array($sender, $dest), $contract);  
                $from_status = $status[$sender];
                $to_status = $status[$dest];
                
                if ($from_status==1 && $to_status==1) {
                    if ($funct_address==$transfert_NA_functions[0] || 
                        $funct_address==$transfert_CM_functions[0] || 
                        $funct_address==$transfert_NA_functions[3] || 
                        $funct_address==$transfert_CM_functions[3]) {
                        // Direct Transfert and Accept reqest
                        
                      

                        $from_add = $sender;
                        $to_add = $dest;  




                        if (!checkPreventTransactionRule($from_add, $to_add, $contract)) {
                             $data["data"] = "TransactionBetweenForbidenAccountTypes";
                             throw new Exception($restricted_transaction_type_error);
                        }                     


                        // get the infos 
                        
                        $from_Nt_bal = getNTBalance($sender, $contract);
                        $from_Cm_bal = getCMBalance($sender, $contract);
                        $from_Cm_lim_m = getCMLimitM($sender, $contract);
                        
                        $to_Cm_bal = getCMBalance($dest, $contract);
                        $to_Cm_lim_p = getCMLimitP($dest, $contract);
                        
                        // Check request exists and amount is not bigger than expected 
                        if ($funct_address==$transfert_NA_functions[3] || $funct_address==$transfert_CM_functions[3]){
                            $request_amount = getNumberInMap( $sender, $dest, $contract2, '0x3537d3fa');   //ok address order

                            if ($amount > $request_amount) {
                                $data["data"] = "AmountBiggerThanRequested";
                                throw new Exception($amount_error);
                            }
                        }
                        
                        // Check transaction is possible
                        if ($funct_address==$transfert_NA_functions[0] || $funct_address==$transfert_NA_functions[3]) {  // Nanti
                            if ($amount > $from_Nt_bal) {
                                $data["data"] = "InsufficientNantBalance";
                                throw new Exception($amount_error);
                            }
                            $trans_type = 'Transfer';
                        } else if ($funct_address==$transfert_CM_functions[0] || $funct_address==$transfert_CM_functions[3]) { // Mutual Credit
                            // dest can accept amount
                            if ($to_Cm_bal + $amount > $to_Cm_lim_p) {
                                $data["data"] = "ReceiverWouldHitCMLimitMax";
                                throw new Exception($amount_error);
                            }
                            // sender has credit 
                            if ($from_Cm_bal - $amount < $from_Cm_lim_m) {
                                $data["data"] = "SenderWouldHitCMLimitMin";
                                throw new Exception($amount_error);
                            }
                            $trans_type = 'TransferCredit';
                        } else {
                            $data["data"] = "UnexpectedTransferAddress";
                            throw new Exception($amount_error);
                        }
                        $need_pending = true;
                    } else if ($funct_address==$transfert_NA_functions[1] || $funct_address==$transfert_CM_functions[1]) {
                        // Transfert from 
                        $requestor = $sender;
                        $requested = $dest;
                        
                        $from_add = $dest;
                        $to_add = $sender; 
                        
                        if (!checkPreventTransactionRule($from_add, $to_add, $contract)) {
                             $data["data"] = "TransactionBetweenForbidenAccountTypes";
                             throw new Exception($restricted_transaction_type_error);
                        } 


                        $from_Nt_bal = getNTBalance($requested, $contract);
                        $from_Cm_bal = getCMBalance($requested, $contract);
                        $from_Cm_lim_m = getCMLimitM($requested, $contract);
                        
                        $to_Cm_bal = getCMBalance($requestor, $contract);
                        $to_Cm_lim_p = getCMLimitP($requestor, $contract);
                        
                        $check_passed = true;
                        // check autorisation exists and is above amount
                        $allowance_amount = getNumberInMap($requested, $requestor, $contract2, '0xdd62ed3e');  //address order ok
                        $check_passed = $allowance_amount>=$amount;
                        
                        // Check transaction is possible
                        if ($funct_address==$transfert_NA_functions[1]) {  // Nanti
                            $check_passed &= $from_Nt_bal>=$amount;
                            $trans_type = 'Transfer';
                        } else if ($funct_address==$transfert_CM_functions[1]) { // Mutual Credit
                            // dest can accept amount
                            $check_passed &= $to_Cm_bal + $amount <= $to_Cm_lim_p;
                            // sender has credit 
                            $check_passed &=$from_Cm_bal-$amount >= $from_Cm_lim_m;
                            $trans_type = 'TransferCredit';
                        } else {
                            $check_passed = false;
                        }
                        $need_pending = $check_passed;
                    } 
                } else {
                    throw new Exception($lock_error);
                }
            
            } else if ($pledge_function == $funct_address) {
                // We have a pledge:
                $trans_type = 'Pledge';
                $from_add = 'Admin';
                $to_add = $dest;  
                 
                $acctype = getAccType($sender, $contract);
                $status = getAccountStatus(array($sender), $contract);
                $curr_stat= $status[$sender];
                
                $need_pending = ($acctype==2 || $acctype==3) && $curr_stat==1;
            }
                 
        }
    
          
        $data['data'] = getRPCResponse($gethRPC->eth_sendRawTransaction($rawtx));
        
        $shop_url = validateShopData();
        $wh_status = 0;
        if (strlen($shop_url)>0 && $amount > 0) {
            $wh_status = 1;
        }
        
        if ($need_pending && strlen($shop_url)>0 && $amount > 0) {
            $message = createWebhookMessage($data['data'], $_REQUEST['serverName'], 
                                                $_REQUEST['shopId'], $_REQUEST['txId'], 
                                                $trans_type, $from_add, $to_add, $amount); 
                                                
            $res = sendWebhook($shop_url, $message);
            if ($res) {
                $wh_status = 3;
            } else {
                $wh_status = 2;
            }
        }
        // Storing the memos, shop and/or delegate
        storeAdditionalData(strlen($shop_url)>0, $data['data'], $wh_status); 
        
        if ($need_pending) {
            // adding pending transaction
            storeTransaction(strlen($shop_url)>0, $data['data'], $wh_status, $amount, $from_add, $to_add, $trans_type, $currency); 
        }
       
    } catch (exception $e) {
        $data['error'] = true;
        $data['msg'] = $e->getMessage();
        error_log($e->getMessage(),0);
    }
    
    /* $data['dbg']= $dbg; */
    return json_encode($data);
    
}


function formatAddress($addr){
    if (substr($addr, 0, 2) == "0x")
        return $addr;
    else
        return "0x".$addr;
}
function getDefaultResponse(){
    $data['error'] = false;
    $data['msg'] = "";
    $data['data'] = "";
    return $data;
}
function bchexdec($hex)
{
    $dec = 0;
    $len = strlen($hex);
    for ($i = 1; $i <= $len; $i++) {
        $dec = bcadd($dec, bcmul(strval(hexdec($hex[$i - 1])), bcpow('16', strval($len - $i))));
    }
    return $dec;
}

function getBatchEthCallAt($batch, $gethRPC)
{
    $requests = [];
    foreach ($batch as $item) {
        $tx = [
            'to'   => $item['ethCall']['to'],
            'data' => $item['ethCall']['data'],
        ];
        $requests[] = [
            'method' => 'eth_call',
            'params' => [$tx, $item['blockNb']],
        ];
    }

    try {
        $responses = $gethRPC->batch($requests);   // one HTTP round‑trip
        // strip to the raw RPC results only
        $out = array_map(
            function($r) {
                if (isset($r['error'])) {
                    if (isset($r['error']['message'])) {
                        return ['error' => ['msg' => $r['error']['message']]];
                    }
                    return ['error' => ['msg' => "unknown error"], 'data' => $r['error']];
                }
                if ($r['result'] == "0x") {
                    return ['error' => ['msg' => "Invalid Geth Call"], 'data' => "InvalidCall"];
                }
                return ['data' => $r['result']];
            },
            $responses
        );
        return json_encode(['error' => false, 'data' => $out]);
    } catch (Exception $e) {
        error_log($e->getMessage(),0);
        return json_encode(['error' => true, 'msg' => $e->getMessage()]);
    }
}

?>
