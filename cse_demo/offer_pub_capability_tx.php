<?php

//
// The Offer Publisher capability is implemented by two PHP files:
// -- offer_pub_capability_tx 
// -- offer_pub_capability_rx
// 
// File offer_pub_capability_tx gets test product offer data via the Google product search API,
// maps this data to the message schema for /cse/offer/create, uses Avro to serialize and encode this data,
// and sends it to the Fabric (on behalf of the tenant whose credential is sent in the Authorization header) 
// on topic /cse/offer create.
// 
// The Fabric, in turn, publishes this message to all capabilities:
// -- that are subscribed to topic /cse/offer/create
// AND
// -- that have the same tenant as the tenant for which this message was sent
//
// In this demo, only the CSE capability is subscribed to topic /cse/offer/create AND 
// has the same tenant as the Offer Publisher 
//

// include the Avro support package
include_once 'avro.php';

//
// This function gets test product offer data using the Google product search API using the supplied query string
// -- $apikey - API key for Google product search service
// -- $query - product for which to obtains data
function getTestProductData($apikey, $query) 
{
	$apikey = "AIzaSyDxY5s1Ib7wgoDKlqV_NXN1t7vJ2QDH9QQ"; // Bill's Google product search API key
	
    // Build a URL that retrieves product info from the Google product search site
	// model URL - https://www.googleapis.com/shopping/search/v1/public/products/?key=KEY&q=QUERY&country=US
	$url = "https://www.googleapis.com/shopping/search/v1/public/products/?key=".$apikey."&q=".$query."&country=US";
	
	// Initialize a cURL session
	$ch = curl_init();
	
	// Set cURL transfer options
	curl_setopt($ch, CURLOPT_URL, $url); // URL of the target resource. This URL is the host of the Google product search API + a query string
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // TRUE to return the transfer as a string of the return value of curl_exec() instead of outputting it directly.
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // stop cURL from verifying the peer's certificate when the https protocol is used
	
	// Send a GET HTTP request containing the URL constructed above
    // If the request is successful, the returned product information is saved in $json.
	$json = curl_exec($ch);
	
	// Close the cURL session
	curl_close($ch);
	
	// Convert the returned product information (which is in JSON format) into an associative array
	$test_data = json_decode($json, true);
	
	// Return the value for the "items" key in the product info associative array
	return $test_data["items"];
} // end - getTestProductData


//
// This function plugs the information for each product returned by the Google product search API into 
// a ProductDetails structure. Each such ProductDetails structure is placed in an array.
// Finally, the function builds a CSE message, which consists of the array of ProductDetails structures 
// followed by a constant that the identifies the type of product feed - either Full or Incremental
// -- $items - array of product data to process
function mapTestDataToCSEMessage($items)
{
	// Create an associative array to hold each cse_product_detail structure built by the loop that follows 
	$cse_product_details = array();
	
	// For each Google product search item in the array passed in ...
	foreach ($items as $item) {
		// Assign the value of the product key in the Google item to $test_product
		$test_product = $item["product"];
		
		// Add a condition to handle the case in which inventories exist -> determines sale price
		// Get the first item in the inventory
		$inventory_item = array_shift($test_product["inventories"]);
		$price = $inventory_item["price"];
		$currency = $inventory_item["currency"];
		$availability = ucfirst($inventory_item["availability"]);
		
		// For some products, Google sets the availability field to "Unknown"
		// This value causes Avro encoding to fail due to a conflict with the CSE Avro schema
		// As a workaround, set availablity to InStock in this case.
		if ($availability == "Unknown") { $availability = "InStock"; }
		
		// For some products, Google does not initialize the brand field.
		// This causes Avro encoding to fail due to a conflict with the CSE Avro schema
		// As a workaround, if brand is unitialized, set it to the string "Brand unitialized"
		if (!isset($test_product["brand"])) $test_product["brand"] = "Brand unitialized";
		
		// Populate the cse_product_details array with data for the current product
		$cse_product_detail = array(
				"sku" => $test_product["googleId"],
				"title" => $test_product["title"], 
				"description" => $test_product["description"],
				"manufacturer" => $test_product["author"]["name"], 
				"MPN" => "NA", 
				"GTIN" => "NA", 
				"brand" => $test_product["brand"],
				"category" => "NA", 
				"images" => array(),//$product["images"], 
				"link" => $test_product["link"], 
				"condition"=>ucfirst($test_product["condition"]), 
				"salePrice" => array("amount" => $price, "code" =>$currency), 
				"originalPrice" => array("amount" => $price, "code" =>$currency), 
				"availability" => $availability, 
				"taxRate" =>array("country" => "US", "region" => "TX", "rate"=> 8.5, "taxShipping" => false), 
				"shipping" => array("country" => "US", "region" => "TX", "service" => "UPS", "price" => array("amount" => 3.99, "code" =>"USD")), 
				"shippingWeight" => 0.0, 
				"attributes" => array(), 
				"variations" => array(), 
				"offerId" => "NA", 
				"cpc" => array("amount" => 0.0, "code" =>"USD"));
		
		if (count($cse_product_details) > 0)
			break;
		
		// Push the product detail structure for the current product onto the the $cse_product_details array
		array_push($cse_product_details, $cse_product_detail);
	}
	
	// Build the CSE message. 
	// This message is a structure containing an array of ProductDetails structures and 
	// a value that identifies the type of product feed - either Full or Incremental
	$message = array("products" => $cse_product_details, "productFeedType" => "Full");
	
	// Return the CSE message
	return $message;	
} // end - mapTestDataToCSEMessage

//
// Main logic
//

// Get test product offer data, so the demo has data to work with
$apikey = ""; // <-- YOUR GOOGLE PRODUCT SEARCH API KEY HERE"
if ("" == $apikey) die("FATAL ERROR: No Google Product Search API Key.\nGo here to get one: http://code.google.com/apis/shopping/search/v1/getting_started.html");
$test_product_array = getTestProductData($apikey, "adcom");

// Map the returned test product data to the standard CSE message schema, 
// that is, to a message whose structure adheres to the message schema for topic /cse/offer/create
$cse_message = mapTestDataToCSEMessage($test_product_array);
echo print_r($cse_message, true);

// Get the Avro schema for the CSE topic /cse/offer/create from a local file.
// NOTE: In the future, this schema will be hosted on the X.commerce OCL (Open Commerce Language) website. 
// When it is, use this statement to get the schema: $schemaFile = file_get_contents("https://ocl.xcommercecloud.com/cse/offer/create/1.0.0");
$schemaFile = file_get_contents("http://localhost/web/cse_demo/cse.avpr");
if ($schemaFile !== false) {
	echo "Avro schema for CSE topic /cse/offer/create successfully read!\n";	
}

// Parse the schema just retrieved and place the results in an AvroSchema object
$schema = AvroSchema::parse($schemaFile);

// Create an AvroIODataWriter object and assign it the AvroSchema object containing the message schema for topic /cse/offer/create 
// An AvroIODataWriter object handles schema-specific writing of data to the encoder and
// ensures that each datum written is consistent with the writer's schema.
$datum_writer = new AvroIODatumWriter($schema);

// Create an AvroStringIO object - this is an AvroIO wrapper for string I/O
$write_io = new AvroStringIO();

// Create an AvroIOBinaryEncoder object and assign it the AvroStringIO object just created
// An AvroIOBinaryEncoder object encodes and writes Avro data to the contained AvroStringIO object using Avro binary encoding
$encoder = new AvroIOBinaryEncoder($write_io);

// Binary-encode and serialize the supplied CSE message using the schema for topic /cse/offer and the supplied encoder
// The result is stored in the AvroStringIO object $write_io created above
try {
	$datum_writer->write($cse_message, $encoder);
  }
catch (Exception $e) {
	echo "Message does not adhere to the Avro schema for topic /cse/offer/create!";
	echo "Exception object:" . $e;
  } // end - try block
	
//
// Send the CSE message to the Fabric on the topic /cse/offer/create
//
try {
	// Initialize a cURL session
	$ch = curl_init();
	
	// Set the cURL options for this session
	
	// Use the POST method in this request message
    // Pass true to perform a regular HTTP POST. This POST is the normal application/x-www-form-urlencoded kind - the kind most commonly used by HTML forms.
	curl_setopt($ch, CURLOPT_POST, true);  
	
    // Set the URL of the target resource, in this case the X.commerce Fabric
    // This URL is of the form "https://" + hostname:portnum of the Fabric + the topic on which you are publishing
	//	curl_setopt($ch, CURLOPT_URL, "https://api.sandbox.x.com/fabric/cse/offer/create"); // sandbox Fabric + real CSE topic 
	curl_setopt($ch, CURLOPT_URL, "https://api.sandbox.x.com/fabric/experimental/cse/offer/create"); // sandbox Fabric + experimental CSE topic
	
    // Add these HTTP headers to the POST request message
    // - Content-Type: set to avro/binary because the message body is in Avro binary format
    // - Authorization: MUST be set to the tenant bearer token for the merchant added to the CSE capability as a tenant
    // - X-XC-SCHEMA-URI: The Avro schema for topic /cse/offer/create is not yet on the OCL server. As a result,
    //                    the Fabric can't set up this header, as it normally does. As a workaround, the schema 
    //                    for topic /cse/offer/create is in a local file and this header is set to point to this file.
    //                    This lets the CSE capability find the schema it needs to decode the Avro-encoded data it receives.                    
    // - X-XC-SCHEMA-VERSION: Set to version of the schema for topic /cse/offer/create you want the Fabric to reference in X-XC-SCHEMA-URI header
	curl_setopt($ch
			  ,CURLOPT_HTTPHEADER
			  ,array("Content-Type: avro/binary"
			  	 ,"Authorization: Bearer QUkAAXfnTQzCvBsGJPVJt20ELKBKroF6nZWFvgNfonebXgKcKhSZkC6+MWbHyjcikP6z5g==" // <- bearer token of merchant 2 added as tenant of Offer Publisher
			  	 ,"X-XC-SCHEMA-URI: http://localhost/web/cse_demo/cse.avpr"
			     ,"X-XC-SCHEMA-VERSION: 1.0.0")); 
	
	// Pass false to stop cURL from verifying the peer's certificate when the https protocol is used
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

	// Pass true so the message body returned by server is returned as a string by the curl_exec() call that POSTs this request message
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
	
	// Pass true so the headers in the response message are included in the string returned by curl_exec()
	curl_setopt($ch, CURLOPT_HEADER, true);
	
	// Set the number of seconds to allow cURL functions to execute to 10
	curl_setopt($ch, CURLOPT_TIMEOUT, 10);

	// Add the binary-encoded, serialized message for topic /cse/offer/create to the request as the message body
	curl_setopt($ch, CURLOPT_POSTFIELDS, $write_io->string());

	// Send the HTTP POST request to the Fabric and print the headers in the response returned by the Fabric
	$response = curl_exec($ch);
	print $response;
  }
catch (Exception $e) {
	echo "Error POSTing message to the Fabric!";
	echo "Exception object:" . $e;
  } // end - try block
	
// end - offer_pub_capability_tx.php

?>
