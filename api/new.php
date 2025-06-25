<?php
// CORS headers must be at the TOP
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=utf-8');

// Include utility functions
require_once "./utilitaires.php";

// Decode the JSON file containing SFW data
$json = json_decode(file_get_contents("sfw.json"), true);
$previousJson = $json;

// If the JSON data is null, initialize it as an empty array
if ($json === null) {
    $json = [];
}

// Get the list of artists from the commissions directory, excluding certain files
$repository = array_diff(scandir(json_decode(file_get_contents("api.json"), true)["commissions"]), [".", "..", "dummy.txt"]);

// Initialize an array to store new artists
$news = [];

// Iterate through the repository to find new artists
foreach ($repository as $artist) {
    if (!array_key_exists($artist, $json)) {
        $news[$artist] = false;
        $json[$artist] = false;
    }
}

// Sort the JSON data naturally and case-insensitively
ksort($json, SORT_NATURAL | SORT_FLAG_CASE);

if ($previousJson !== $json) {
    // If the JSON data has changed, save the updated JSON data back to the file
    file_put_contents("sfw.json", json_encode($json));
}

// Output the new artists as a JSON response
echo json_encode($news);