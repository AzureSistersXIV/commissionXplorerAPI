<?php
// CORS headers must be at the TOP
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=utf-8');

// Include utility functions
require_once "./utilitaires.php";

// Load artists data from JSON file
$artists = json_decode(file_get_contents("sfw.json"), true);

if($artists === null){
    $artists = [];
}

// Load repository paths from JSON file
$repositories = json_decode(file_get_contents("api.json"), true);

// Filter artists based on the 'isNsfw' query parameter
$artists = array_filter($artists, function($artist): bool{
    return $artist === (!getQueryParameter("isNsfw") || getQueryParameter("isNsfw") !== "true");
});

// Iterate through each artist and set the thumbnail path
foreach($artists as $artist => $sfw){
    $isDir = is_dir($repositories["thumbs"].$artist);
    if($isDir){
        // Explore the directory and sort the contents
        $folder = explorePath($repositories["thumbs"].$artist);
        sort($folder);
        // Set the first item in the folder as the thumbnail or a default image
        $artists[$artist] = array_key_exists(0, $folder) ? $folder[0] : "./assets/img/folder.png";
    }else{
        // Set a default image if the directory does not exist
        $artists[$artist] = "./assets/img/folder.png";
    }
}

// Sort artists array naturally and case-insensitively
ksort($artists, SORT_NATURAL | SORT_FLAG_CASE);

// Output the artists array as a JSON response
echo json_encode($artists);