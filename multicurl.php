<?php
  /* 
  
  modified by Andrew J Weil (a.k.a rxhector2k5@yahoo/gmail/ or just @rxhector on twitter/steemit)

  this is a mix of multicurl from 
    http://multicurl.nisu.org/ and 
    https://github.com/stefangabos/Zebra_cURL




   * Copyright Manuel Mollar - mm AT nisu.org
   * Distributed under GPL license http://www.gnu.org/copyleft/gpl.html


    constructor (maxdw, optional msschedule)
      - maxdw sets the max number of simultaneous downloads
      - msschedule is used for fine tunning
	sets the miliseconds (def 1) that addURL spends scheduling comunications (see samples.txt)
    public addURL(the url, optional post parameters, optional timeout, optional ordering)
      - schedules the download in background of the url using GET, to use POST set the second parameter.
        returns a sequence id (1,2,...)
	if 'ordering' is 0, it is scheduled in parallel
	if 'ordering' is -1, it will be scheduled when the previous url finished
	if 'ordering' is -2, it will be scheduled when all the urls with the same scheme and host have finished
	if 'ordering' is >0, it will be scheduled after the url that returned this number
	  see examples
    public setOpt(option, value, global)
      - set options only for the next call to addURL (global=false) or for all the following calls (global=true)
    public getResults()
      - returns an array of arrays with the current available results
	  the array index is the request order
	  see examples
    public resultHandler( function (&$results, request order) )
      - to be used instead getResults, will set a function that will be called when every download finished
	  see examples
    public startHandler ( function (array info) )
      - to be called just after a url start downloding
	info has the form: (request order, url, start time, start order, curl handle)
    public wait(miliseconds)
      - schedules communicatios during some seconds, used for fine tunning
	use it periodically if your program does a lot of computation
	without calling addURL. This is necessary as curl_multi is not really concurrent, so if you use
	a low ratio in constructor or do not call addURL periodically, communications are not scheduled,
	you need to call this method (see samples.txt)
    public endWait()
      - waits for terminaton, must be called to ensure that all downloads are finished

  */


if (!function_exists('load_simplexml_page')) {
    /*

        this will 'force' xml to load a web page (pure html)
        sometimes simplexml_import_dom breaks when trying to import html with bad mark up (i.e - old crappy coding / scripts)
        DOMDocument will auto-magically fix shitty html

        then we can simplexml-ize it !!!

        NOTE : 
            when using php file_get_contents or dom document or simplexml
            those functions use the php.ini user_agent

            most web sites will not return a web request to empty user_agent
            or user_agent "PHP"
            i ALWAYS set php.ini user_agent to a valid browser string

            user_agent="Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:47.0) Gecko/20100101 Firefox/47.0"

    */
    //load from url(default) or string
    function load_simplexml_page($page , $type='url'){
        /*
            static DOMDocument::loadHTMLFile() errors on malformed documents
            $dom = new DOMDocument('1.0', 'UTF-8'); //force doctype/utf - get rid of warnings
            prepend @$dom = tell php to ignore any warnings/errors
        */
        $dom = new DOMDocument('1.0', 'UTF-8');
        $type === 'url'
        ? @$dom->loadHTMLFile(trim($page))
        : @$dom->loadHTML($page);
        return simplexml_import_dom($dom);
    }//end function
}//end function check



/*
* for use with fat-free-framework ($f3 or $fw)
* extends prefab = this is meant as a singleton instance
* or - get rid of - extends \prefab to use it on its own
*/

class MultiCurl extends \Prefab {
    private $to=0;
    private $sq=0;
    private $sm=0;
    private $mx=0;
    private $mh;
    private $rc=0;
    private $ms;
    private $cl=[];             //queue for sequencing
    private $rs=[];             //store processed results in array(might ditch this)
    private $hs=[];             //curl handles
    private $rh=false;          //result handler (move this to array) - dont have to move to array - just need to clear it for next run
    private $sh=false;          //start handler (move this to array) - dont have to move to array - just need to clear it for next run

    //curl options for current request
    private $op = [];

    //default curl options (global for all requests)
    private $go = [];

    //@$handles [int] - set number of curls to run 
    //@$ms [int] = millisecond timeout between batch curl calls
    public function __construct($handles = 100 , $ms=1) {
        $this->mx = $handles;   //max handles to run concurrently
        $this->ms = $ms;
        $this->mh = curl_multi_init();

        //set default curl options
        $this->go = [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_AUTOREFERER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CONNECTTIMEOUT      =>  5,
            CURLOPT_TIMEOUT             =>  10,
            CURLOPT_USERAGENT =>  $this->_user_agent(),
        ];        
    }//end constrcutor


    //@$set - $options [array] : pre-processing before the curl call  - set or get the start handler
    //@return[string] - function name of the callback
    public function start_callback(&$options , $set = false){
        if(!$set){

            return $options['start_callback'] ?  : false;

        }
        else{
            $options['start_callback'] = $set;
            $this->sched();
            return $options['start_callback'];
        }
    }//end function


    //@$set - $options[array] : post-processing after the curl call  - set or get the result handler
    //@return[string] - function name of the callback
    public function result_callback(&$options , $set = false){
      if(!$set){

            return $options['result_callback'] ?  : false;

      }
      else{
            $options['result_callback'] = $set;
            $this->sched();
            return $options['result_callback'];
      }
    }//end function


    //pre processing - init variables for curl call
    //call starthandler if defined $options['startHandler']
    private function unCurl($url , &$options , $sequence) {
        //print_r(func_get_args());        
        $curl = curl_init( $url );        

        //set curl options
        //$this->option( [CURLOPT_URL => $url] );        

        if($options['data']){
            $this->option([
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $options['data']
            ]);            
        }

        //options passed in will over-write existing options
        $curl_options = $this->op + $this->go;

        curl_setopt_array($curl , $curl_options);

        curl_multi_add_handle($this->mh , $curl);        
        
        $this->to++;

        $if = [
            'req' => $sequence,
            'url' => $url,
            'options' => $options,
            'handle' => $curl,
            'start' => time(),
            'seq' => $this->to,
        ];

        $this->hs[$curl] = $if;

        //start handler (pre-processing)
        if ($x = &$this->start_callback($options)) {
            $this->rc++;
            $x($if);  //<~~~ short cut : same as function $options['start_callback'](&$if){} or call_user_func_array($x , $if);
            $this->rc--;
        }//end - start handler        

        //clear the start handler for next run
        unset($options['start_callback']);

        //clear options for next request
        $this->op = []; 

        $this->sm++;

        /*
        global $logger;
        echo "after unCurl\n";
        $logger->write( print_r($this , true) );
        */
    }//end function

    private function useq($url , $l) {
        $pu = parse_url($url);
        $pu = "{$pu['scheme']}://{$pu['host']}";
        $lpu = strlen($pu);
        $ok = true;
        foreach($this->hs as $hs){
          if (substr($hs['url'] , 0 , $lpu) == $pu) {
                $ok = false;
                break;
          }
        }
        if ($ok){
          for($i=0; $i < $l; $i++){
            if (substr($this->cl[$i][0] , 0 , $lpu) == $pu) {
                $ok = false;
                break;
            }
          }
        }
        return $ok;
    }//end function

    //post processing (request is finished)
    /*
    $in[array] - returned from curl_multi_info_read
    $in = [
        [msg] => 1
        [result] => 7
        [handle] => Resource id #14 //<~~~ this is the finished curl_multi handle - now we can load results from multi handle array ($this->hs)
      ]
    */    
    private function coge($in) {

        $curl = &$in['handle'];
        // get the handle's ID
        //$id = preg_replace('/Resource id #/', '', $curl);
        $rsi = &$this->hs[$curl];
        $hz = curl_getinfo($curl , CURLINFO_HEADER_SIZE);
        $http_code = curl_getinfo($curl , CURLINFO_HTTP_CODE);
        $r = curl_multi_getcontent($curl);
        $h = substr($r , 0 , $hz); 
        $r = substr($r , $hz);

        //store the result ???
        $this->rs[ $i = $rsi['req'] ]=[
            'result' => $r,
            'error' => $in['result'],
            'header' => $h,
            'http_code' => $http_code ,
            'url' => $rsi['url'],
            'options' => $rsi['options'],
            'start' => $rsi['start'],
            'done' => time(),
            'seq' => $rsi['seq'],          
        ];

        //result handler
        if ($x = &$this->result_callback($this->rs[$i]['options'])) {
            $this->rc++;
            $x($this->rs[$i] , $i);
            $this->rc--;
        }//end - result handler


        //clear callback for next call
        unset($this->rs[$i]['options']['result_callback']); 

        //done with processing - remove curl handle
        unset($this->hs[$curl]);        
        curl_multi_remove_handle($this->mh , $curl);
        
        $this->sm--;

        //check the stack/queue for more
        $l = count($this->cl);
        for($i=0; $i < $l; $i++){
            list($url , $options , $sequence) = $this->cl[$i];
            if ($sequence == -2){
                if (!$ok = $this->useq($url , $i)){
                continue;
                }
                break;
            }
            if (!$sequence or $this->rs[$sequence]){
                break;
            }
            
            if ($i < $l) {
                array_splice($this->cl , $i , 1);
                $this->unCurl($url ,$options , $sequence);
            }
        }//end - loop

        while (curl_multi_exec($this->mh , $ac) == CURLM_CALL_MULTI_PERFORM) ;  //<~~~~ noop
    }//end function

    private function sched(){
        if ($this->rc < 20){          
            while ($in = curl_multi_info_read($this->mh)){
                $this->coge($in);
            }
        }
    }//end function

    //@$option[array] - [CURLOPT_ => val]
    public function option($option , $global = false) {
        foreach($option as $opt => $val){
            $global
                ? $this->go[$opt] = $val
                : $this->op[$opt] = $val;
        }
    }//end function


    /*
      @$options must be an array
        array[
          'data' => optional for POST
          'proxy' => $proxy //you should always pass the proxy so good ones can be re-used for next call (multi-page sequence)
          'anything' => you can pass any other data you need using as many variables as needed
        ]
      @$sequence
          0 = all process in parallel
          -1 =
          -2 = 
    */
    public function addURL($url , $options , $sequence=0) {
      
        $q = $this->sq++;

        if (!$this->cl and !$this->hs){
            $sequence=0;
        }
        else if ($sequence == -1){
            $sequence = $q;
        }

        if ($this->sm < $this->mx) {
            if (!$sequence){ 
                $ok = true;
            }
            else if ($sequence > 0){
                $ok = $this->rs[$sequence];
            }
            else if ($sequence == -2) {
                $ok = $this->useq($url , count($this->cl));
            }
        }

        //process immediately
        if (isset($ok) && $ok){          
            $this->unCurl($url , $options , $sequence);
        }
        else{
            //add to the stack(queue) to process on later run
            $this->cl[] = [
                $url,
                $options,
                $q+1, 
            ];
        }

        $f = microtime(true) + floatval($this->ms)/1000;
        while (microtime(true) < $f) {

            while (curl_multi_exec($this->mh , $ac) == CURLM_CALL_MULTI_PERFORM) ;  //<~~~~ noop

            if (!curl_multi_select($this->mh , 0.001)){
                break;
            }
        }
        
        $this->sched();

        return $this->sq;
    }//end function

    public function getResults() {
        return $this->rs;
    }//end function


    //@$ms[int] : pause in milliseconds - slow down !!!
    public function wait($ms){
        $f = microtime(true)+floatval($ms)/1000;

        while (microtime(true) < $f) {

            while (curl_multi_exec($this->mh, $ac) == CURLM_CALL_MULTI_PERFORM) ;   //<~~~~ noop

            curl_multi_select($this->mh , 0.001);   //notice the .001 second delay
        }

        $this->sched();
    }//end function

    //start the multi-curl (or recover from wait())
    public function endWait() {

        if($this->to){
            $running = null;

            // loop
            do {

                // get status update
                while (($status = curl_multi_exec($this->mh, $running)) == CURLM_CALL_MULTI_PERFORM);

                // if no request has finished yet, keep looping
                if ($status != CURLM_OK) break;

                // if a request was just completed, we'll have to find out which one
                while ($result = curl_multi_info_read($this->mh)) {

                    $this->coge($result); //post processing

                    // waits until curl_multi_exec() returns CURLM_CALL_MULTI_PERFORM or until the timeout, whatever happens first
                    // call usleep() if a select returns -1 - workaround for PHP bug: https://bugs.php.net/bug.php?id=61141
                    if ($running && curl_multi_select($this->mh) === -1) usleep(100);
                }
                // as long as there are threads running or requests waiting in the queue
            } while ($running || !empty($this->hs));

                // close the multi curl handle
            curl_multi_close($this->mh);  
        }                      
    }//end function

    public function getCount(){
        return $this->sq;
    }//end function

    //borrowed this from Zebra_cURL
    private function _user_agent() {
        // browser version: 9 or 10
        $version = rand(9, 10);

        // windows version; here are the meanings:
        // Windows NT 6.2   ->  Windows 8                                       //  can have IE10
        // Windows NT 6.1   ->  Windows 7                                       //  can have IE9 or IE10
        // Windows NT 6.0   ->  Windows Vista                                   //  can have IE9
        $major_version = 6;

        $minor_version =

            // for IE9 Windows can have "0", "1" or "2" as minor version number
            $version == 8 || $version == 9 ? rand(0, 2) :

            // for IE10 Windows will have "2" as major version number
            2;

        // add some extra information
        $extras = rand(0, 3);

        // return the random user agent string
        return 'Mozilla/5.0 (compatible; MSIE ' . $version . '.0; Windows NT ' . $major_version . '.' . $minor_version . ($extras == 1 ? '; WOW64' : ($extras == 2 ? '; Win64; IA64' : ($extras == 3 ? '; Win64; x64' : ''))) . ')';

  }//end function

}//end class

/*

    tipping is allowed
    the old slow way - paypal rxhector2k5@yahoo.com
    the super fast ~3 second way twitter xrptipbot to @rxhector
        or twitter goseedit (trx) to @rxhector

*/