# Amazon ASIN Scraper

The ASIN scraper takes an input file containing product names to search for on Amazon(.co.uk) and spits out ASINs.  It's particularly userful as it will also take a description and, where it can't find a good enough match on product name, will look through the first page of search results to find a matching description.

## Dependencies
PHP version 5.6 or above.
The [simple html dom] library.

## Usage

php scraper.php <inputfile> <outputfile> <errorfile>

## <inputfile>

The input file is a simple CSV file with the following columns:

Column | Description
--- | ---
SKU | Particularly useful if you're attempting to get ASINs from Amazon for your existing product database, populate this with the SKUs of your products so you can easily import the data back into your tables.
Name | the name of the product.  This is the key used to search for the product on Amazon.
Description | the description of the product.  Where the script can’t find a good match on product name it will look for a match on product description.
Main Colour | the colour of the product.  If the product on Amazon has colour and size combinations, the tool will attempt to match this field against the combinations on the Amazon product page.
Size | the size of the product.  If the product on Amazon has colour and size combinations, the tool will attempt to match this field against the combinations on the Amazon product page.

Please note that if colour and size combinations are not given on the Amazon product page for a product matching the Name field, the script will ignore the colour and size data in the <inputfile>.

The script assumes that the <inputfile> has a header row containing the names of these columns and will accordingly not process the first row in the file.  The script also assumes that the columns are in the order given above.

## <outputfile>

The script writes a line to this file for each product found.  Each line will contain the SKU of the product as taken from the input file and the ASIN of the product, separated by a comma.  Products not found on Amazon are not written to this file, but are writting to <errorfile> instead.

## <errorfile>

The script writes the SKUs of each product not found to this file, plus any other errors encountered during processing.  Each line in the file will also contain the number of the line in the <inputfile> that generated the error.  The amount of information written to this file can be tuned by changing the DEBUG_LEVEL constant in the script.

## Version
1.0

## Installation
To install, make sure you've got PHP installed with curl support.  Download the [simple html dom] library and install it in the same directory as this script.

##License
GPL Version 2.0

[//]: # (These are reference links used in the body of this note and get stripped out when the markdown processor does it's job. There is no need to format nicely because it shouldn't be seen. Thanks SO - http://stackoverflow.com/questions/4823468/store-comments-in-markdown-syntax)

   [simple html dom] <http://simplehtmldom.sourceforge.net>
