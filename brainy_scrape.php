<?php
//ini_set('display_errors', true);

$f3 = require "./fatfree/lib/base.php";

$f3->config('config.ini');

/*

    i tried using $f3 \db\jig for json - but it wasnt quite working the way i wanted
    so i just switch to fairly fast csv

*/
/* using super simple flat file db w/json format */
//$db = new \DB\Jig ( "./quotes" , $format = \DB\Jig::FORMAT_JSON );

$mc = new multicurl(100 , 1000000);    //(xxx handles , xxx ms timeout)

$logger = new Log('brainy_test.txt');

$proxies = file("./proxies_good" , FILE_IGNORE_NEW_LINES);

//set all objects in global $f3
$f3->mset([
    //'db' => $db,
    'mc' => $mc,
    'logger' => $logger,
    'proxies' => $proxies,
    'author_count' => 0,    //global counter
    'quote_count' => 0      //global counter
]);

//data storage
//create some quick easy jig(json) tables
class Author extends \DB\Jig\Mapper {
    public function __construct() {
        parent::__construct( \Base::instance()->get('db'), 'authors.json' );
    }
}
//$user = new User();
class Quote extends \DB\Jig\Mapper {
    public function __construct() {
        parent::__construct( \Base::instance()->get('db'), 'quotes.json' );
    }
}


/* final results */
function quotes_callback(&$result , $sequence){    
    global $f3;

    if ( $result['error'] === 0 && $result['http_code'] == 200 && $xml = load_simplexml_page($result['result'] , 'html') ) {        
        $url = $result['url'];        

        echo "quotes : {$url} : {$sequence}\n";

        //$xml = load_simplexml_page($result['result'] , 'html');
        foreach($xml->xpath("//div[@id='quotesList']//div[@class='m-brick grid-item boxy bqQt']") as $div){        
            
            //create smaller xml chunk to prevent extra traversing/doubles
            $simple = new SimpleXMLElement($div->asXML());
            
            $author_quote = $simple->xpath("(//a[contains(@class , 'b-qt')])[1]");
                
            $tags = [];            
            //$author = $simple->xpath("//a[contains(@class , 'bq-aut')][1]");
            foreach($simple->xpath("//div[@class='kw-box']//a[contains(@href , 'topics')]") as $tag){
                $tags[] = (string)$tag;
            }
            //print_r($tags);
            //die('test');

            $fields = [
                'quote' => (string)$author_quote[0] ,
                'tags' => $tags
            ];

            //format data
            $data = print_r($fields , true) . "\n";

            //or change data format on the fly
            //$data = json_encode($fields);

            $f3->set('quote_count' , $f3->get('quote_count')+1);   //inc global counter
            
            echo "found {$f3->get('author_count')} authors - {$f3->get('quote_count')} quotes\n";

            $author_csv = $f3->get('quotes_dir') . "/{$result['options']['author']}.csv";
            file_put_contents($author_csv , $data , FILE_APPEND );
        }//end - get quotes loop
        
        
        //check for pagination
        $pagination = $xml->xpath("//ul[contains(@class , 'pagination bqNPgn pagination-sm')]//a[contains( . , 'Next')]");
        if(sizeof($pagination) > 0){
            echo "\tquotes paging : {$pagination[0]['href']}\n";

            $url = "http://www.brainyquote.com{$pagination[0]['href']}";

            $f3->get('mc')->option( [ CURLOPT_PROXY => $result['optiona']['proxy'] ]);  //re-use same proxy
            $f3->get('mc')->result_callback($result['options'] , 'quotes_callback');
            $f3->get('mc')->addURL($url , $result['options']);              
        }//end - pagination                

    } else {
        $url = $result['url'];

        echo "\tquotes bad load : {$url} : {$result['options']['proxy']} : {$result['options']['pass']}\n";
        
        $result['options']['pass']++;        

        $proxies = $f3->get('proxies');
        $proxy = $proxies[rand(1 , sizeof($proxies)-1)];    //get new proxy
        $result['options']['proxy'] = $proxy;

        $f3->get('mc')->option( [CURLOPT_PROXY => $proxy] );
        $f3->get('mc')->result_callback($result['options'] , 'quotes_callback');
        $f3->get('mc')->addURL($url , $result['options']);        
    }//end - bad page load

    $result = [];   //clear memory
}//end function

//get the authors name and links to quotes
function authors_callback(&$result , $sequence){    
    global $f3; 

    //$xml = load_simplexml_page($result['result'] , 'html');
    if ( $result['error'] === 0 && $result['http_code'] == 200 /*&& $xml = load_simplexml_page($result['result'] , 'html')*/ ) {

        echo "authors : {$result['url']} : {$sequence}\n";

        $xml = load_simplexml_page($result['result'] , 'html');
        
        foreach($xml->xpath("//div[@class='bq_s']//table//a[contains(@href , 'authors')]") as $link){

            $author_name = array_pop( explode("/" , (string)$link['href']));
            
            $f3->set('author_count' , $f3->get('author_count')+1);   //inc global counter

            echo "\n\t{$author_name} - {$f3->get('author_count')}\n";

            //now for the tricky part
            //get the authors quotes - (using different callback)
            $result['options']['author'] = $author_name;

            $url = "http://www.brainyquote.com{$link['href']}";    //set new url for next call (get the authors quotes)
            
            $f3->get('mc')->option( [CURLOPT_PROXY => $result['options']['proxy'] ]);    //re-use good proxy
            $f3->get('mc')->result_callback($result['options'] , 'quotes_callback');    //switch to callback for next page
            $f3->get('mc')->addURL($url , $result['options']);             
        }//end - get authors

        
        //check for pagination
        $pagination = $xml->xpath("//ul[contains(@class , 'pagination bqNPgn pagination-sm')]//a[contains( . , 'Next')]");
        if(sizeof($pagination) > 0){
            echo "\tauthors paging : {$pagination[0]['href']}\n";
            
            $url = "http://www.brainyquote.com{$pagination[0]['href']}";

            $f3->get('mc')->option( [ CURLOPT_PROXY => $result['options']['proxy'] ]);    //re-use good proxy
            $f3->get('mc')->result_callback($result['options'] , 'authors_callback');
            $f3->get('mc')->addURL($url , $result['options']);
            
        }//end - pagination

    } else {
        $url = $result['url'];

        echo "\tauthors bad load : {$url} : {$result['options']['proxy']} : {$result['options']['pass']}\n";
        //print_r($result);
        
        $result['options']['pass']++;
        
        $proxies = $f3->get('proxies');
        $proxy = $proxies[rand(1 , sizeof($proxies)-1)];    //get new proxy
        $result['options']['proxy'] = $proxy;

        $f3->get('mc')->option( [CURLOPT_PROXY => $proxy] );
        $f3->get('mc')->result_callback($result['options'] , 'authors_callback');
        $f3->get('mc')->addURL($url , $result['options']);
        
    }//end - bad page load

    $result = [];   //clear memory
}//end function

$start = microtime(true);

//the entry point loads with simplexml (curl) - must have php.ini openssl and set php.ini user_agent
$xml = load_simplexml_page("http://www.brainyquote.com/");

//(this is just for testing to get started) - to get the entire site remove the [1] from end of xpath
//$links = $xml->xpath("(//div[@class='letter-navbar qs-blk']//a[contains(@href , 'authors')])");

$links = $xml->xpath("(//div[@class='letter-navbar qs-blk']//a[contains(@href , 'authors')])[1]"); 

foreach($links as $link){
    echo "{$link['href']}\n";
    $url = "http://www.brainyquote.com{$link['href']}";

    $proxy = $proxies[rand(1 , sizeof($proxies)-1)];

    $options = [
        'proxy' => $proxy,  //pass proxy - good ones get re-used for next call
        'pass' => 1 //starting pass - used to help track page load count
    ];    

    $f3->get('mc')->option( [CURLOPT_PROXY => $proxy] );
    $f3->get('mc')->result_callback($options , 'authors_callback');
    $f3->get('mc')->addURL($url , $options);
}
$f3->get('mc')->endWait();

$now = microtime(true);

echo "{$start} : {$now} : found {$f3->get('author_count')} authors - {$f3->get('quote_count')} quotes in " . ($now - $start) . " seconds\n";

//1552080200.9947 : 1552080686.2755 : found 2000 authors - 25336 quotes in 485.2807559967 seconds (52pages/sec downloaded)