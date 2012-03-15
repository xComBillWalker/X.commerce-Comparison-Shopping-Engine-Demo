<?php

// The Offer Publisher capability is implemented by two PHP files:
// -- offer_pub_capability_tx 
// -- offer_pub_capability_rx
// 
// File offer_pub_capability_rx receives HTTP POST messages 
// on topic /cse/offer/created published (via the Fabric) by the CSE capability
//

//include_once 'avro.php';

// Open the log file
$fp = fopen('offer_pub.log', 'at');
fwrite($fp, "============================================" . "\n\n");
fwrite($fp, "INFO: Message posted by the Fabric on topic /cse/offer/created\n\n");

// Get all HTTP headers out of the received message
$headers = getallheaders();
fwrite($fp, "INFO: HTTP headers retrieved from received message: " . print_r($headers, true) . "\n\n");

// Get the URI of the Avro message schema file for the topic /cse/offer/create from the X-XC-SCHEMA-URI header.
// In the future, this schema file will be hosted on the X.commerce OCL (Open Commerce Language) website
// For this demo, however, this URI points to a local file.
$schema_uri = $headers['X-XC-SCHEMA-URI'];
//fwrite($fp, "INFO: URI of Avro message schema retrieved from received message: " . $schema_uri . "\n\n");

// Get the posted message body
// NOTE: The message body is currently in Avro binary form
$posted_data = file_get_contents("php://input");

//
// TO DO - Use Avro to decode the posted data. Then process this data according to your business logic 
//

// Close the log file
fclose($fp);

// end - offer_pub_capability_rx.php

?>
