<?php
//ini_set('display_errors', true);

$f3 = require "./fatfree/lib/base.php";

$f3->config('config.ini');

$mc = new multicurl(100 , 100000);    //(xxx handles , xxx ms timeout)

$logger = new Log('proxy_test.txt');

//set all objects in global $f3
$f3->mset([
    //db' => $db,
    'mc' => $mc,
    'logger' => $logger,
]);

function get_us_proxy(){
    $return = [];

    $page = \Web::instance()->request("http://www.us-proxy.org/");    

    $xml = load_simplexml_page($page['body'] , 'html');    

    foreach($xml->xpath("//table[@id='proxylisttable']//tr") as $row){
        if(!empty($row->td[0])){
            //echo "{$row->td[0]}:{$row->td[1]}\n";
            $return[] = "{$row->td[0]}:{$row->td[1]}";
        }
    }//end - get proxies list

    return $return;
}//end function

function get_rose_proxy(){
    $return = [];
    
    $page =  \Web::instance()->request("http://tools.rosinstrument.com/proxy/plab100.xml");
    
    $xml = load_simplexml_page($page['body'] , 'html');

    foreach($xml->xpath("//item/title") as $proxy){
        $return[] = (string)$proxy;
    }//end - get proxies list    
    
    return $return;    
}//end function

function get_cyber_proxy(){
    $return = [];

    $page =  \Web::instance()->request("http://www.cybersyndrome.net/plr5.html");
    
    $xml = load_simplexml_page($page['body'] , 'html');

    foreach($xml->xpath("//tr/td[2]") as $proxy){
        $return[] = (string)$proxy;
    }//end - get proxies list    
    
    return $return;          
}//end function

/* final results template
function result_callback(&$result , $sequence, $pass = 1){    

    if ( $result['error'] === 0 && $result['http_code'] == 200) {        

    } else {

    }//end - bad page load

    $result = [];   //clear memory
}//end function
*/

/* callback function - final results */
function result_callback(&$result , $sequence, $pass = 1){    

    if ( $result['error'] === 0 && $result['http_code'] == 200 && $xml = load_simplexml_page($result['result'] , 'html') ){

        $proxy = $result['options']['proxy'];

        //$xml = load_simplexml_page($result['result'] , 'html');

        $xml_check = $xml->xpath($result['options']['xpath']);

        if($xml && sizeof($xml_check) > 0){

            echo "\t{$proxy} : {$xml_check[0]}\n";

            file_put_contents("./proxies_good" , "{$proxy}\n" , FILE_APPEND );

            /*
            $fp = @fopen("./proxies_good" , "a");            
            fputcsv($fp , [$proxy]);
            fclose($fp);
            */
        }//end - found a good one        

    } else {

        echo "\tbad load : {$result['options']['proxy']}\n";

    }//end - bad page load

    $result = [];   //clear memory
}//end function

function check_proxies_multi($url = "" , $xpath=""){
    global $f3;
            
    //fetch the newest proxies
    
    $proxies = array_filter( array_unique( array_merge( get_us_proxy() , get_rose_proxy() , get_cyber_proxy() ) ) );

    $count = count($proxies);
    echo "found {$count} proxies.\n";
    sleep(3);
    
    foreach($proxies as $proxy){

        $options = [
            //'data' => '',
            'proxy' => $proxy,  //pass proxy - good ones get re-used for next call
            'xpath' => $xpath,
            'pass' => 1 //starting pass - useful to help track page load count
        ];

        $f3->get('mc')->option( [CURLOPT_PROXY => $proxy] );

        $f3->get('mc')->result_callback($options , 'result_callback');

        $f3->get('mc')->addURL($url , $options);

        //print_r($curl->options);
    }//end proxies loop

    $f3->get('mc')->endWait();

}//end function


@unlink("./proxies_good");  //remove old proxies

/*
    make sure our proxies work with the target domain

    you can check any part of the page with simplexml xpath
    title just happens to be fairly easy
*/

$start = microtime(true);

check_proxies_multi("https://www.brainyquote.com/" , "//title[contains(. , 'Famous Quotes at BrainyQuote')]");

$count = count(file("./proxies_good"));

$now = microtime(true);

echo "{$start} : {$now} : found {$count} proxies in " . ($now - $start) . " seconds\n";