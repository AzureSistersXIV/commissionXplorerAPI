<?php
// Handle preflight OPTIONS request for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *"); // Allow all origins
    header("Access-Control-Allow-Methods: POST, OPTIONS"); // Allow POST and OPTIONS methods
    header("Access-Control-Allow-Headers: Content-Type"); // Allow Content-Type header
    header("Access-Control-Max-Age: 86400"); // Cache preflight response for 24 hours
    exit(0);
}

// Set CORS header for all responses
header("Access-Control-Allow-Origin: *");
// Set content type to JSON
header('Content-Type: application/json; charset=utf-8');

// Include utility functions
require_once "./utilitaires.php";

// Get the 'artist' parameter from the request
$artist = getFormParameter("artist");
// Load the path to commissions from the configuration file
$pathCommission = json_decode(file_get_contents("api.json"), true)['commissions'];

if($artist){
    // If artist parameter is provided, explore the corresponding folder
    $folder = explorePath($pathCommission.$artist, true);
    // Return the folder contents as JSON
    echo json_encode($folder);
}else{
    // If artist parameter is missing, return an error response
    http_response_code(500);
    echo json_encode(['error' => 'Artist not recognized']);
}