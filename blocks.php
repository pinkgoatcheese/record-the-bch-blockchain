<?php

// script requires curl and bcmath

// loading arweave 
include __DIR__ . '/vendor/autoload.php';
use \Arweave\SDK\Arweave;
use \Arweave\SDK\Support\Wallet;

// get local block height
$last_local_block = file_get_contents('blocks.txt');

// get block count from bitcoin.com
get_block_count($last_local_block);

function get_block_count($last_local_block)
{
    $block_count_url = "https://rest.bitcoin.com/v2/blockchain/getBlockCount";
    $curl = curl_init(); 
    curl_setopt_array($curl, array(
    CURLOPT_URL => $block_count_url,            // set the request URL
    CURLOPT_HTTPHEADER => array("Content-Type:application/json"),     // set the headers
    CURLOPT_RETURNTRANSFER => true,         // ask for raw response instead of bool
    ));
    $block_count = curl_exec($curl); // Send the request, save the response
    curl_close($curl); // Close request
    get_missing_blocks($block_count, $last_local_block);
}

// Retrieve missing block information to save if needed
function get_missing_blocks($block_count, $last_local_block)
{
    if ($block_count == $last_local_block) 
    {
        exit();
    } 
    elseif ($block_count > $last_local_block) 
    {
        foreach (range($last_local_block, $block_count) as $block_number) {
            // increment local block number by one
            $last_local_block++;
            // output block number to log file
            print_r($last_local_block);
            // new line
            printf("\n");
            $url = "https://rest.bitcoin.com/v2/block/detailsByHeight/" . $last_local_block . "?verbose=true";

            $curl = curl_init(); 
            curl_setopt_array($curl, array(
            CURLOPT_URL => $url,            // set the request URL
            CURLOPT_HTTPHEADER => array('Content-Type:application/json'),     // set the headers
            CURLOPT_RETURNTRANSFER => true,         // ask for raw response instead of bool
            ));
            $data_response = curl_exec($curl); // Send the request, save the response
            curl_close($curl); // Close request

            $data_response_array = json_decode(trim($data_response), true);
            $block_height = $data_response_array['height'];
            $block_hash = $data_response_array['hash'];
            $previous_block = $data_response_array['previousblockhash'];
            $block_time = $data_response_array['time'];
            //print_r($block_height);

            // Get Block Header to Save
            $header_url = "https://rest.bitcoin.com/v2/blockchain/getBlockHeader/" . $block_hash . "?verbose=false";
            $curl = curl_init(); 
            curl_setopt_array($curl, array(
            CURLOPT_URL => $header_url,            // set the request URL
            CURLOPT_HTTPHEADER => array('Content-Type:application/json'),     // set the headers
            CURLOPT_RETURNTRANSFER => true,         // ask for raw response instead of bool
            ));
            $header_response = curl_exec($curl); // Send the request, save the response
            curl_close($curl); // Close request
            // echo $header_response;

            // convert header response to binary
            $number_input = str_replace('"', '', $header_response);
            $fromBaseInput = '0123456789ABCDEF';
            $toBaseInput = '01';
            $binary_header = convBase($number_input, $fromBaseInput, $toBaseInput);
       
            // validatae data
            if (!is_array($data_response_array) ) 
            {
                exit();
            } 
            else 
            {
                // save block number locally then save
                file_put_contents('blocks.txt', $block_height);
                save_to_arweave($block_height, $block_hash, $previous_block, $block_time, $binary_header);
            }   
        } 
    }
}
// function to convert numbers to any base    
function convBase($numberInput, $fromBaseInput, $toBaseInput)
{
    if ($fromBaseInput==$toBaseInput) return $numberInput;
    $fromBase = str_split($fromBaseInput,1);
    $toBase = str_split($toBaseInput,1);
    $number = str_split($numberInput,1);
    $fromLen=strlen($fromBaseInput);
    $toLen=strlen($toBaseInput);
    $numberLen=strlen($numberInput);
    $retval='';
    if ($toBaseInput == '0123456789')
    {
        $retval=0;
        for ($i = 1;$i <= $numberLen; $i++)
            $retval = bcadd($retval, bcmul(array_search($number[$i-1], $fromBase),bcpow($fromLen,$numberLen-$i)));
        return $retval;
    }
    if ($fromBaseInput != '0123456789')
        $base10=convBase($numberInput, $fromBaseInput, '0123456789');
    else
        $base10 = $numberInput;
    if ($base10<strlen($toBaseInput))
        return $toBase[$base10];
    while($base10 != '0')
    {
        $retval = $toBase[bcmod($base10,$toLen)].$retval;
        $base10 = bcdiv($base10,$toLen,0);
    }
    return $retval;
}
    function save_to_arweave($block_height, $block_hash, $previous_block, $block_time, $binary_header)
    {
        // Creating a Arweave object, this is the primary SDK class,
        // It contains the public methods for creating, sending and getting transactions
        $arweave = new \Arweave\SDK\Arweave('https', 'arweave.net', '443');
    
        // Decode our JWK file to a PHP array named $jwk
        $jwk = json_decode(file_get_contents('jwk.json'), true);
    
        // Create a new wallet using the $jwk array
        $wallet =  new \Arweave\SDK\Support\Wallet($jwk);
    
        // Create a new ARWEAVE transaction to store the verified data
        $transaction = $arweave->createTransaction($wallet, [
            'data' => $binary_header,
            'tags' => [
               // 'App-Name'      =>  'Record The BCH Blockchain',
                'Symbol'        =>  'BCH',
                'Source'        =>  'bitcoin.com',
                'Height'        =>  $block_height,
                'Hash'          =>  $block_hash,
                'Previous'      =>  $previous_block,
                'Block-Data'    =>  'H',
                'Block-Time'    =>  $block_time,
            ]
        ]);
        
        
        // Outputs the transaction id which is stored in the logfile via cron
        printf ('%s', $transaction->getAttribute('id'));
    
        // 1 transaction id per line
        printf("\n");
    
        // Send the transaction to the arweave network
        // $arweave->commit($transaction);
        $arweave->api()->commit($transaction);
    }