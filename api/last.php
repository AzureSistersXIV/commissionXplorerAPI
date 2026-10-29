<?php

// CORS headers must be at the TOP
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=utf-8');

// Include utility functions
require_once "./utilitaires.php";

// Load artists data from JSON file
$artists = json_decode(file_get_contents("levels.json"), true);

if ($artists === null) {
    $artists = [];
}

// Get the 'isNsfw' query parameter and parse it to determine the maximum level of NSFW content to include
$query = parseMaxLevel(getQueryParameter("isNsfw"));

// Filter the artists array to include only those with a level less than or equal to the parsed query parameter
$artists = array_filter($artists, function ($artist) use ($query) : bool {
    return $artist <= $query; // Keep artists with a level less than or equal to the query parameter
});

// Load repository paths from JSON file
$repositories = json_decode(file_get_contents("api.json"), true);

// Iterate through each artist and set the thumbnail path
foreach ($artists as $artist => $sfw) {
    $isDir = is_dir($repositories["thumbs"] . $artist);
    if ($isDir) {
        // Explore the directory and get last file with datetime
        $folder = getLastFileWithDatetime($repositories["commissions"] . $artist);

        // Set the first item in the folder as the thumbnail
        $artists[$artist] = $folder;
    } else {
        // Set a default image if the directory does not exist,
        // keeping the SAME shape as the branch above (array with file + datetime)
        $artists[$artist] = [
            'file'     => './assets/img/folder.png',
            'datetime' => '1970-01-01 00:00:00',
        ];
    }
}

// Use uasort() to preserve the artist-name keys while sorting
uasort($artists, function ($a, $b) {
    return $b['datetime'] <=> $a['datetime']; // newest first
});

// Output the artists array as a JSON response
echo json_encode($artists);