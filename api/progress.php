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

// Load repository paths from configuration file
$repositories = json_decode(file_get_contents("api.json"), true);

// Get action and artist parameters from the request
$action = getFormParameter("action");
$artist = getFormParameter("artist");
$progress = 0.0;

// Handle different actions
switch($action){
    case "thumbnails":
        $progress = getPercentage($artist);
        break;
    default:
        // Throw an exception for unknown actions
        throw new Exception("Unknown action");
}

// Return progress as a JSON response
echo json_encode($progress);