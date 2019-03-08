<?php
    /* testing to see if file_get_contents / DOMDocument::loadHTMLFile / simplexml uses php.ini user_agent */

    // put this in yout webserver somewhere so you can request ex. http://localhost/test_user_agent.php

    //the call from cmd line 
    //php /path/to/test_user_agent.php 

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
    

    
    if(isset($_SERVER['REQUEST_METHOD'])){
        //this is a webpage hit 
        $return = print_r($_SERVER , true) ;
        echo $return;   //return something to browser
    }
    else
    {
        //no server request - this is running as cmd line script
        
        //returns default php user_agent "PHP" (if set)
        //$text = file_get_contents("http://localhost/test_dom.php");
        //echo $text;

        //returns default php user_agent "PHP" (if set)
        $xml = load_simplexml_page("http://localhost/test_user_agent.php");
        echo $xml->asXML();

    }  
