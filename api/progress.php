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
        // Explore the commissions folder for the artist
        $commFolder = array_filter(explorePath($repositories["commissions"].$artist), function($file){
            $extension = pathinfo($file, PATHINFO_EXTENSION);
            return in_array($extension, ["jpg", "jpeg", "png", "gif"]);
        });

        if(count($commFolder)=== 0){
            $progress = 0;
            break;
        }
        
        // Create thumbnails folder if it doesn't exist
        if(!is_dir($repositories["thumbs"].$artist)){
            mkdir($repositories["thumbs"].$artist, 0755, true);
        } 
        // Explore the thumbnails folder for the artist
        $thumbsFolder = explorePath($repositories["thumbs"].$artist);
        
        // Calculate progress as the ratio of thumbnails to commissions
        $progress = count($thumbsFolder) / count($commFolder);
        break;
    default:
        // Throw an exception for unknown actions
        throw new Exception("Unknown action");
}

// Return progress as a JSON response
echo json_encode($progress);