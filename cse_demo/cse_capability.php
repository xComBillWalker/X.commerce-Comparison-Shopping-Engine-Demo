<?php

//
// The CSE capability:
// 1) Receives HTTP POST messages on topic /cse/offer/create published (via the Fabric) by the Offer Publisher, 
// gets the message body from each such message, uses Avro to decode the message body, and inserts the result 
// in a MongoDB database
// 2) Sends an HTTP POST message on topic /cse/offer/created to the Fabric. This messages contains the data originally 
// received on topic /cse/offer/create, as required by the CSE contract
//
// The Fabric, in turn, publishes this message to all capabilities:
// -- that are subscribed to topic /cse/offer/created
// AND
// -- that have the same tenant as the tenant for which this message was sent
//
// In this demo, only the Offer Publisher capability is subscribed to topic /cse/offer/created AND 
// has the same tenant as the CSE capability
//

// Include the Avro support package
include_once 'avro.php';

// Open the log file
$fp = fopen('cse.log', 'at');
fwrite($fp, "============================================" . "\n\n");
fwrite($fp, "INFO: Message posted by the Fabric on topic /cse/offer/create\n\n");

// Get all HTTP headers out of the received message
$headers = getallheaders();
fwrite($fp, "INFO: HTTP headers retrieved from received message: " . print_r($headers, true) . "\n\n");

// Get the posted message body
// NOTE: The message body is currently in Avro binary form
$posted_data = file_get_contents("php://input");

// Get the URI of the Avro message schema file for the topic /cse/offer/create from the X-XC-SCHEMA-URI header.
// In the future, this schema file will be hosted on the X.commerce OCL (Open Commerce Language) website
// For this demo, however, this URI points to a local file.
$schema_uri = $headers['X-XC-SCHEMA-URI'];
fwrite($fp, "INFO: URI of Avro message schema retrieved from received message: " . $schema_uri . "\n\n");

// Get the contents of the schema file identified by the URI retrieved above
$schemaFile = file_get_contents($schema_uri);

// Parse the schema for topic /cse/offer/create just retrieved and place the results in an AvroSchema object
$schema = AvroSchema::parse($schemaFile);

// Use Avro to decode and deserialize the binary-encoded message body.
// The result is the plain text version of the message body
// The message sender used Avro to binary-encode the text version of the message body before sending the message.

// Create an AvroIODatumReader object and assign it the AvroSchema object containing the message schema for topic /cse/offer/create
// An AvroIODatumReader object handles schema-specific reading of data from the decoder and
// ensures that each datum read is consistent with the reader's schema.
$datum_reader = new AvroIODatumReader($schema);

// Create an AvroStringIO object and assign it the Avro-encoded posted data, which is Avro-encoded
$read_io = new AvroStringIO($posted_data);

// Create an AvroIOBinaryDecoder object and assign it the AvroStringIO object containing the posted, encoded data.
// An AvroIOBinaryDecoder object reads Avro data from the contained AvroStringIO object and decodes this data.
$decoder = new AvroIOBinaryDecoder($read_io);

// Decode and deserialize the posted data using the message schema for topic /cse/offer/create and the supplied decoder
// The data is retrieved from the AvroStringIO object $read_io created above
// Upon return, $message contains the plain text version of the X.commerce message sent by the publisher
$message = $datum_reader->read($decoder);
fwrite($fp, "INFO: Decoded message: " . print_r($message, true) . "\n\n");

// Connect to the MongoDB server running on your machine
// NOTE: you must start the MongoDB server prior to running this web application
$conn = new Mongo('localhost');

// Access the cse_data database
// If this database does not exist, Mongo creates it
$db = $conn->cse_data;

// Access the google collection
// If this collection does not exist, Mongo creates it
$collection = $db->google;

// Insert a new document into the google collection
$item = $message["products"];
$collection->insert($item);

// Write to log file
fwrite($fp, "INFO: Item inserted in database: " . print_r($item, true) . "\n\n");
fwrite($fp, "INFO: Inserted document with ID: " . $item['_id'] . "\n\n");
fwrite($fp, "============================================" . "\n\n");

// Disconnect from the MongoDB server
$conn->close();


//
// Publish an "ack" message on topic /cse/offer/created 
// As the message body, include the data originally recevied since that's what the CSE contract requires
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
	curl_setopt($ch, CURLOPT_URL, "https://localhost:8080/cse/offer/created");
	//	curl_setopt($ch, CURLOPT_URL, "https://api.sandbox.x.com/fabric/experimental/cse/offer/create"); // for sandbox Fabric

	// Add these HTTP headers to the POST request message
	// - Content-Type: set to avro/binary because the message body is in Avro binary format
	// - Authorization: MUST be set to the tenant bearer token for the merchant added to the CSE capability as a tenant
	// - X-XC-MESSAGE-GUID-CONTINUATION - GUID identifying the message being acknowledged
	// - X-XC-SCHEMA-URI: The Avro schema for topic /cse/offer/create is not yet on the OCL server. As a result,
	//                    the Fabric can't set up this header, as it normally does. As a workaround, the schema
	//                    for topic /cse/offer/create is in a local file and this header is set to point to this file.
	//                    This lets the CSE capability find the schema it needs to decode the Avro-encoded data it receives.
	// - X-XC-SCHEMA-VERSION: Set to version of the schema for topic /cse/offer/create you want the Fabric to reference in X-XC-SCHEMA-URI header
	$msg_guid = $headers['X-XC-MESSAGE-GUID'];
	curl_setopt($ch
			,CURLOPT_HTTPHEADER
			,array("Content-Type: avro/binary"
				,"Authorization: Bearer QUkAAaM+u42KAGU0d8kb819B9LUtB7G5IWLz//45TKM9au9xlWaen5ZHH1yn5OqlPk+HRQ==" // <-- bearer token of merchant 2 added as tenant of cse_capability
				,"X-XC-MESSAGE-GUID-CONTINUATION: $msg_guid" // in "ack" message, send the GUID of the message being "acked"
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

	// Add the binary-encoded, serialized data originally sent as the message body
	curl_setopt($ch, CURLOPT_POSTFIELDS, $posted_data);

	// POST the HTTP request message to the Fabric and print the response returned by the Fabric
	$response = curl_exec($ch);
//	print $response;
	fwrite($fp, "INFO: Message /cse/offer/created sent to Fabric" . "\n\n");
	fwrite($fp, "INFO: Response from Fabric: " . $response . "\n\n");
	
  } // end - try block
catch (Exception $e) {
	echo "Error POSTing message to the Fabric!";
	echo "Exception object:" . $e;
  } // end - catch block

// Close the log file
fclose($fp);  
  
// end - cse_capability.php

?>
