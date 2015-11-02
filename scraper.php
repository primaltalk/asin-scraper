<?php

require_once( "simple_html_dom.php" );

const BASE_URL = "http://www.amazon.co.uk/s/ref=nb_sb_noss?url=search-alias%3Daps&field-keywords=";

// We use the php similar_text method to match text within a certain percentage,
// as given here.
const MINIMUM_MATCH_LEVEL = 90;

const DEBUG_LEVEL = 4; // Print debug messages to console.
const ERROR_LEVEL_DEBUG = 4;
const ERROR_LEVEL_INFO = 3;
const ERROR_LEVEL_WARN = 2;
const ERROR_LEVEL_ERROR = 1;
const ERROR_LEVEL_FATAL = 0;

const ERROR_LEVELS = [ERROR_LEVEL_DEBUG => "DEBUG",
    ERROR_LEVEL_INFO => "INFO",
    ERROR_LEVEL_WARN => "WARNING",
    ERROR_LEVEL_ERROR => "ERROR",
    ERROR_LEVEL_FATAL => "FATAL ERROR"];

class Scraper
{
    // This is the base URL for performing a search for a given product.
    
    private $dataFile = null;
    private $outputFile = null;
    private $errorFile = null;
    private $processedCount = 0;
    private $successCount = 0;
    private $errorCount = 0;
    private $lineCount = 0;
    
    public function __construct($dataFile, $outputFile, $errorFile)
    {
        // Check for and open our input and output files.
        if( !file_exists( $dataFile ) )
            $this->log( ERROR_LEVEL_FATAL, "Unable to open data file " . $dataFile . " - file does not exist." );
        if( ( $this->dataFile = fopen( $dataFile, "r") ) == false )
            $this->log( ERROR_LEVEL_FATAL, "Unable to open data file " . $dataFile );
        if( ( $this->outputFile = fopen( $outputFile, "w" ) ) == false )
            $this->log( ERROR_LEVEL_FATAL, "Unable to open " . $outputFile . " for writing." );
        if( ( $this->errorFile = fopen( $errorFile, "w" ) ) == false )
            $this->log( ERROR_LEVEL_FATAL, "Unable to open " . $errorFile . " for writing." );
        
        // At this point, if anything has gone wrong, the process will have died,
        // so we can continue.
    }
    
    public function scrape()
    {
        $searchTerms;
        $listItems;
        
        // Load up the source data file and start iterating through it.
        while (($data = fgetcsv($this->dataFile, 1000, ",")) !== FALSE)
        {
            // We use a line counter so we can generate errors that refer to a
            // specific line in the input file.
            $this->lineCount ++;
            
            // Ignore the first line as it has headers.
            if( $this->lineCount == 1 ) continue;
            
            // Keep track of the number of lines we've processed.
            $this->processedCount ++;
            
            if( count($data) < 5 ) // Not enough input fields, so reject the line.
            {
                $this->writeToErrorFile( $this->lineCount, (count($data) > 0 ? $data[0] : null), "Input data error - not enough fields." );
                continue;
            }
            
            $SKU = trim( $data[0] );
            $name = trim( $data[1] );
            $description = trim( $data[2] );
            $colour = trim( $data[3] );
            $size = trim( $data[4] );
            
            $this->log( ERROR_LEVEL_DEBUG, "Line " . $this->lineCount . "; Searching for \"" . $name . "\"" );
            
            // Find a match based on the raw name.  If there is a match, we get
            // a link back.  We always get a reference to the list returned,
            // in case we need to look through the list for matching
            // descriptions.
            $listDOM = null;
            $matchFound = false;
            if( ( $link = $this->searchByName( $name, $listDOM ) ) != false )
            {
                $itemDOM = $this->getDOMFromURL( $link );
                $this->scrapeASIN( $itemDOM, $SKU, $name, $description, $colour, $size, $this->lineCount );
            } else
            {
                $this->log( ERROR_LEVEL_DEBUG, "Could not find a good name match.  Trying without SKU / Colour prefix." );
                if( $this->startsWithPartofWord( $name, $SKU ) )
                {
                    // Trim the SKU off the start, which will be the
                    // first word, and search again.
                    $shortName = $this->deleteFirstWord( $name );
                    if( ( $link = $this->searchByName( $shortName, $listDOM ) ) != false )
                    {
                        $itemDOM = $this->getDOMFromURL( $link );
                        $this->scrapeASIN( $itemDOM, $SKU, $name, $description, $colour, $size, $this->lineCount );
                        $matchFound = true;
                    } else
                    {
                        // Searching without the SKU didn't work; let's try the
                        // same thing with the product colour.
                        if( $this->startsWithPartofWord( $shortName, $colour ) )
                        {
                            $shortName = $this->deleteFirstWord( $shortName );
                            if( ( $link = $this->searchByName( $shortName, $listDOM ) ) != false )
                            {
                                $itemDOM = $this->getDOMFromURL( $link );
                                $this->scrapeASIN( $itemDOM, $SKU, $name, $description, $colour, $size, $this->lineCount );
                                $matchFound = true;
                            }
                        }
                    }
                } else
                {
                    $this->log( ERROR_LEVEL_DEBUG, "Name does not start with SKU; falling back to description search." );
                }

                if( !$matchFound )
                {
                    $this->log( ERROR_LEVEL_DEBUG, "Could not find a good enough match.  Trying descriptions." );
                    // Iterate back through our matches, load up the product
                    // page and attempt to match based on description.
                    foreach( $listDOM->find( "li[class='s-result-item']" ) as $resultItem )
                    {
                        $item = $resultItem->find( "a[class='s-access-detail-page']", 0);
                        if( $item != null )
                        {
                            $link = $item->getAttribute("href");
                            $itemDOM = $this->getDOMFromURL( $link );
                            if( $this->matchDescription( $itemDOM, $SKU, $name, $description, $colour, $size, $this->lineCount ) )
                            {
                                $this->scrapeASIN( $itemDOM, $SKU, $name, $description, $colour, $size, $line );
                                $matchFound = true;
                                break;
                            }
                        }
                    }

                    if( !$matchFound )
                        $this->writeToErrorFile( $this->lineCount, $SKU, "Could not find good match." );
                }
            }
            
            // Release all dom elements to defeat the memory leak.
            $this->cleanAllDOM($GLOBALS);
            
        } // while

        // Finish off by closing the files.
        if( $this->dataFile ) fclose( $this->dataFile );
        if( $this->outputFile ) fclose( $this->outputFile );
        if( $this->errorFile ) fclose( $this->errorFile );

        $this->log( ERROR_LEVEL_INFO, "Processing complete." );
        $this->log( ERROR_LEVEL_INFO, $this->processedCount . " lines processed." );
        $this->log( ERROR_LEVEL_INFO, $this->successCount . " ASINs successfully found." );
        $this->log( ERROR_LEVEL_INFO, $this->errorCount . " products not found.  Check error file for details" );
    }
    
    private function searchByName( $name, &$listDOM )
    {
        $this->log( ERROR_LEVEL_DEBUG, "Line " . $this->lineCount . "; Searching for \"" . $name . "\"" );
        
        // Grab the search page from Amazon based on search terms.
        $listDOM = $this->loadSearch( $name );
        
        // First look to see if nothing was found.
        if( !is_null( $listDOM->getElementById("noResultsTitle") ) )
            return false;
        
        // We got some results, so let's iterate through them and get the
        // % match.  The highest match above our minimum is the one we take.
        $listItems = array();
        foreach( $listDOM->find( "li[class='s-result-item']" ) as $resultItem )
        {
            $item = $resultItem->find( "a[class='s-access-detail-page']", 0);
            if( $item != null )
            {
                $title = htmlspecialchars_decode( $item->getAttribute("title") );
                $link = $item->getAttribute("href");
                similar_text( strtoupper( $name ), strtoupper( $title ), $matchLevel );
                $listItems[] = array($matchLevel, $link, $title);
                $this->log(ERROR_LEVEL_DEBUG, $matchLevel . "; \"" . $title . "\"" );
            }
        }
        
        // Now we have an array full of possible matches, find the best
        // match.
        $bestMatch = null;
        foreach( $listItems as $listItem )
        {
            if( is_null( $bestMatch ) )
            {
                $bestMatch = $listItem;
            } else
            {
                if( $listItem[0] > $bestMatch[0] )
                {
                    $bestMatch = $listItem;
                }
            }
        }
        
        // Now if the best match has a score lower than our maximum, we can
        // declare success and return the link for the best match.
        $this->log( ERROR_LEVEL_DEBUG, "Best match: " . $bestMatch[0] . "; " . $bestMatch[2] );
        if( $bestMatch[0] >= MINIMUM_MATCH_LEVEL )
        {
            $this->log( ERROR_LEVEL_DEBUG, "Found a match with " . $bestMatch[0] . " " . $bestMatch[2] );
            return $bestMatch[1];
        }
        
        return false;
    }

    private function matchDescription( $itemDOM, $SKU, $name, $description, $colour, $size, $line )
    {
        // We have a link to a product with an ambiguous name, so let's look
        // for the description.
        $this->log( ERROR_LEVEL_DEBUG, "Looking for description match: \"" . $description . "\"" );
        
        // We look for the specific item description area in the page, otherwise
        // we won't be able to do a fuzzy match as there's too much text to
        // compare.
        $descriptionElement = $itemDOM->find("div[id='productDescription']", 0);
        if( !is_null( $descriptionElement ) )
        {
            $descriptionText = $this->cleanString( $descriptionElement->innertext );
            $this->log( ERROR_LEVEL_DEBUG, "Found a description: \"" . $descriptionText . "\"" );
            return $this->isLike( $description, $descriptionText );
        } else
        {
            // No description, so let's look at product features.
            $descriptionElement = $itemDOM->find("div[id='feature-bullets']", 0);
            if( !is_null( $descriptionElement ) )
            {
                $descriptionText = $this->cleanString( $descriptionElement->innertext );
                $this->log( ERROR_LEVEL_DEBUG, "Found some attributes: \"" . $descriptionText . "\"" );
                return $this->isLike( $description, $descriptionText );
            }
        }
        
        return false;
    }
    
    private function scrapeASIN( $itemDOM, $SKU, $name, $description, $colour, $size, $line )
    {
        $foundASIN = false;

        // We have a link to the product that matches our search, so first load
        // it up.
        $this->log( ERROR_LEVEL_DEBUG, "Loading product page." );
        
        // Now we need to load up the JSON object that has variations.  If it's
        // there, we can look for the colour and size variations given in our
        // source file.  If not, look on the main page to see if there's a match
        // with our source data.  If we can't find a match in either then we
        // haven't found a valid product match and can't record the ASIN.
        foreach( $itemDOM->find("script") as $javascriptElement )
        {
            $javascript = $javascriptElement->innertext;
            if( preg_match( "/twister-js-init-mason-data/", $javascript ) )
            {
                // Now we have the correct javascript, parse it into a DOM object
                // and look for our combinations.  The next part of the code
                // extracts text from within matching curly braces, which is
                // where Amazon hides a JSON object containing the product type
                // combinations we need.
                preg_match_all('/{((?:[^{}]++|(?R))*+)}/', $javascript, $matches);
                
                // We should have several entries as a result, one of which
                // contains the JSON we need, so let's look for it.
                foreach( $matches[1] as $possibleMatch )
                {
                    if( preg_match( "/dataToReturn/", $possibleMatch ) )
                    {
                        // We have what we need, so grab the JSON.
                        preg_match_all('/{((?:[^{}]++|(?R))*+)}/', $possibleMatch, $jsonMatches);
                        $jsonText = $jsonMatches[1];
                        $JSON = json_decode( "{" . $jsonText[0] . "}" );
                        
                        // Now we have the JSON, let's look for the size and
                        // colour dimensions.  If we have those, we can attempt
                        // to map our size and colour to the dimensions in the
                        // data.
                        $size_key = null;
                        $colour_key = null;
                        if( array_key_exists( 'dimensions', $JSON ) )
                        {
                            foreach( $JSON->dimensions as $key => $dimension )
                            {
                                $this->log( ERROR_LEVEL_DEBUG, $dimension );
                                if( stristr( $dimension, 'color' ) ) $colour_key = $key;
                                if( stristr( $dimension, 'size' ) ) $size_key = $key;
                            }
                            if( !is_null( $colour_key ) && !is_null( $size_key ) )
                            {
                                foreach( $JSON->dimensionValuesDisplayData as $ASIN => $dimensions )
                                {
                                    $this->log( ERROR_LEVEL_DEBUG, "Size: " . $dimensions[$size_key] . "; Color: " . $dimensions[$colour_key] );
                                    if( $this->isLike( $colour, $dimensions[$colour_key] )
                                        && $this->isLike( $size, $dimensions[$size_key] ) )
                                    {
                                        $foundASIN = true;
                                        break;
                                    }
                                }
                                if( $foundASIN )
                                {
                                    $this->log( ERROR_LEVEL_DEBUG, "Found ASIN " . $ASIN );
                                    $this->writeToOutputFile( $SKU, $ASIN );
                                } else
                                {
                                    $this->log( ERROR_LEVEL_DEBUG, "Unable to locate correct size " . $size . " and colour " . $colour . "." );
                                }
                            } else
                            {
                                $this->log( ERROR_LEVEL_DEBUG, "Unable to find ASIN for product - unable to locate size and product dimensions." );
                                $this->writeToErrorFile( $line, $SKU, "Match Found but could not locate size and colour dimensions." );
                            }
                        } else
                        {
                            $this->log( ERROR_LEVEL_DEBUG, "Dimension key array not found in JSON where expected." );
                        }
                    }
                }
            }
        }
        
        if( !$foundASIN )
        {
            // No variations, so look in the main body.  The "add to cart"
            // funcationality references the ASIN directly, so we can use that.
            $this->log(ERROR_LEVEL_DEBUG, "ASIN not found in javascript; searching for single product ASIN.");
            $ASINElement = $itemDOM->find("input[id='ASIN']", 0);
            if( is_null( $ASINElement ) )
            {
                $this->log( ERROR_LEVEL_DEBUG, "Unable to find ASIN for product - field not found." );
                $this->writeToErrorFile( $line, $SKU, "Match Found but ASIN Not Present" );
            } else
            {
                // Look for matches on colour and size.  If they're not given
                // or not present on the Amazon page we declare success.
                if( $this->colourMatch( $itemDOM, $colour )
                    && $this->sizeMatch( $itemDOM, $size ) )
                {
                    $ASIN = $ASINElement->getAttribute("value");
                    $this->log( ERROR_LEVEL_DEBUG, "Found ASIN " . $ASIN );
                    $this->writeToOutputFile( $SKU, $ASIN );
                    $foundASIN = true;
                } else
                {
                    $this->log( ERROR_LEVEL_DEBUG, "Unable to find ASIN for product - size or colour mismatch." );
                    $this->writeToErrorFile( $line, $SKU, "Match Found but failed on size or colour mismatch." );
                }
            }
        }
        
        return $foundASIN;
    }
    
    private function colourMatch( $itemDOM, $colour )
    {
        // No need to match if we don't have a colour.
        if( $colour == "" ) return true;
        
        // No need to match if colour isn't given on the page.
        $colourElement = $itemDOM->find("div[id='variation_color_name]", 0);
        if( is_null( $colourElement ) ) return true;
        
        $colourText = $colourElement->find("span[class='selection']");
        if( is_null( $colourText ) ) return true;
        $this->log( ERROR_LEVEL_DEBUG, "Looking for a colour match: " . $colour . " to " . $colourText->innertext );
        if( stristr( $colour, $colourText->innertext ) ) return true;
        
        return false;
    }
    
    private function sizeMatch( $itemDOM, $size )
    {
        // No need to match if we don't have a size.
        if( $size == "" ) return true;
        
        // No need to match if size isn't given on the page.
        $sizeElement = $itemDOM->find("div[id='variation_size_name]", 0);
        if( is_null( $sizeElement ) ) return true;
        
        $sizeText = $sizeElement->find("span[class='selection']")->innertext;
        $this->log( ERROR_LEVEL_DEBUG, "Looking for a size match: " . $size . " to " . $sizeText );
        if( stristr( $size, $sizeText ) ) return true;
        
        return false;
    }
    
    private function loadSearch($searchTerms)
    {
        // Format the search URL with the base URL and search terms modified
        // to fit the format required by Amazon.
        $searchURL = BASE_URL . urlencode(preg_replace("/ /", "+", trim($searchTerms)));
        
        return $this->getDOMFromURL($searchURL);
    }

    private function getDOMFromURL($url)
    {
        $start = time();
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); //Set curl to return the data instead of printing it to the browser.
        curl_setopt($ch, CURLOPT_URL, $url);
        $data = curl_exec($ch);
        curl_close($ch);
        $dom = new simple_html_dom( $data );
        $finish = time();
        $elapsed = $finish - $start;
        $this->log( ERROR_LEVEL_DEBUG, "HTML Query elapsed: " . $elapsed );
        return $dom;
    }
    
    private function cleanAllDOM(&$items,$leave = '')
    {
        foreach($items as $id => $item){
            if($leave && ((!is_array($leave) && $id == $leave) || (is_array($leave) && in_array($id,$leave)))) continue;
            if($id != 'GLOBALS'){
                if(is_object($item) && ((get_class($item) == 'simple_html_dom') || (get_class($item) == 'simple_html_dom_node'))){
                    $items[$id]->clear();
                    unset($items[$id]);
                }else if(is_array($item)){
                    $first = array_shift($item);
                    if(is_object($first) && ((get_class($first) == 'simple_html_dom') || (get_class($first) == 'simple_html_dom_node'))){
                        unset($items[$id]);
                    }
                    unset($first);
                }
            }
        }
    }
    
    private function isLike( $string1, $string2, $minimumMatch = MINIMUM_MATCH_LEVEL )
    {
        similar_text( strtoupper( $string1 ), strtoupper( $string2 ), $matchLevel );
        if( $matchLevel >= $minimumMatch ) return true;
        return false;
    }

    private function log($level, $message)
    {
        if( $level <= DEBUG_LEVEL)
            print( ERROR_LEVELS[$level] . ": " . $message . "\r\n" );
            
        if( $level == ERROR_LEVEL_FATAL ) die();
    }
    
    private function writeToErrorFile( $line, $SKU, $message )
    {
        $this->errorCount ++;
        fputcsv( $this->errorFile, [$line, $SKU, $message] );
    }
    
    private function writeToOutputFile( $SKU, $ASIN )
    {
        $this->successCount ++;
        fputcsv( $this->outputFile, [$SKU, $ASIN] );
    }

    private function cleanString( $string )
    {
        
        $string = strip_tags( html_entity_decode( $string ) );
        $string = trim( $string );
        $string = trim( $string, "\"" );
        $string = trim( $string );
        $string = preg_replace('/\s\s+/', ' ', $string );
        return $string;
    }
    
    private function startsWithPartofWord( $name, $word )
    {
        // The general rule is that the first word of the name may start with
        // part of the SKU, so we extract the first word from the name and look
        // for it in the SKU.  The string "$name" should be cleaned before
        // calling this function.
        
        $firstWord = current( explode( " ", $name ) );
        if( stripos( $word, $firstWord ) == 0 )
            return true;
        return false;
    }
    
    private function deleteFirstWord( $string )
    {
        $elements = explode( " ", $string );
        array_shift( $elements );
        $shortString = trim( implode( " ", $elements ) );
        // Also trim off any starting hyphen and spaces.
        $shortString = trim( $shortString, "-" );
        $shortString = trim( $shortString );
        return $shortString;
    }
    
}

if( $argc < 4 ) die( "Usage: php scraper.php inputFile outputFile errorFile" );
$scraper = new Scraper($argv[1], $argv[2], $argv[3]);
$scraper->scrape();

?>
