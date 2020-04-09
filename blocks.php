<?php

// script requires php7.2, curl,  bcmath

// loading arweave 
include __DIR__ . '/vendor/autoload.php';
use \Arweave\SDK\Arweave;
use \Arweave\SDK\Support\Wallet;

// get local block height
$last_local_block = trim(file_get_contents('blocks.txt'));

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
        // Print message to logfile if no new blocks to retrieve
        printf("\n");
        echo("BCH Block Height is equal to last local block");
        exit();
    }
    elseif ($block_count > $last_local_block)
    {
        foreach (range($last_local_block, $block_count) as $block_number) {
            // increment local block number by one
            $last_local_block++;
            if ($last_local_block == $block_count)
            {
                // New line and notify of job completion in log
                printf("\n"); 
                echo("All missing blocks saved to arweave");
                exit();
            }
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
 
            // Get Block Header to Save
            $header_url = "https://rest.bitcoin.com/v2/blockchain/getBlockHeader/" . $block_hash . "?verbose=false";
            // echo($header_url);
            $curl = curl_init();
            curl_setopt_array($curl, array(
            CURLOPT_URL => $header_url,            // set the request URL
            // CURLOPT_HTTPHEADER => array('Content-Type:application/json'),     // set the headers
            CURLOPT_RETURNTRANSFER => true,         // ask for raw response instead of bool
            ));
            $header_response = curl_exec($curl); // Send the request, save the response
            curl_close($curl); // Close request
            // convert header response to binary
            $hex_input = str_replace('"', '', $header_response);
            gettype($header_response);
            $header_data_array = hex2ByteArray($hex_input);
            $binary_header = pack('C*', ...$header_data_array);
            //validate data
            if (!is_array($data_response_array))
            {
                // New line and output error to logfile
                printf("\n");
                echo('Data Response is not an array');
                exit();
            }
            elseif (!isset($block_height))
            {
               // New line and output error to logfile
               printf("\n");
               echo('Empty Variable for block_height exiting');
               exit();
            } else {
                // save block number locally then save
                file_put_contents('blocks.txt', $block_height);
                save_to_arweave($block_height, $block_hash, $previous_block, $block_time, $binary_header);
            }
        }
    }
}

function hex2ByteArray($hex_input)
{
    $string = hex2bin($hex_input);
    return unpack('C*', $string);
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
    $arweave->api()->commit($transaction);
    // Output the saved block height to logfile
    echo($block_height);
}
