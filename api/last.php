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
        // Explore the directory and get last file with datetime
        $folder = getLastFileWithDatetime($repositories["commissions"].$artist);
		
        // Set the first item in the folder as the thumbnail or a default image
        $artists[$artist] = $folder;
    }else{
        // Set a default image if the directory does not exist
        $artists[$artist] = "./assets/img/folder.png";
    }
}

usort($artists, function ($a, $b) use ($folder) {
    return $b['datetime'] <=> $a['datetime']; // oldest first
});

// Output the artists array as a JSON response
echo json_encode($artists);