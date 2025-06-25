<?php
// CORS headers must be at the TOP
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=utf-8');

// Include utility functions
require_once "./utilitaires.php";

// Get the 'artwork' parameter from the form submission
$artwork = getFormParameter("artwork");

// Decode the JSON file to get the path for thumbnails
$pathCommission = json_decode(file_get_contents("api.json"), true)['thumbs'];

// Initialize an empty array to store the JSON response
$json = [];

if(!$artwork){
    // If 'artwork' is not provided, get the 'artworks' parameter
    $artworks = getFormParameter("artworks");

    // Loop through each artwork and get its full path
    foreach($artworks as $artwork){
        $json[] = getFullPaths($artwork);
    }
}else{
    // If 'artwork' is provided, get its full path
    $json = getFullPaths($artwork);
}

// Encode the array to JSON format and output it
echo json_encode($json);