<?php
// CORS headers must be at the TOP
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=utf-8');

// Include utility functions
require_once "./utilitaires.php";

// Decode the JSON file containing levels data
$json = json_decode(file_get_contents("levels.json"), true);
$previousJson = $json;

// If the JSON data is null, initialize it as an empty array
if ($json === null) {
    $json = [];
}

if(!file_exists("api.json")) {
    if (!copy("api.json.example", "api.json")) {
        http_response_code(500);
        echo json_encode(["error" => "Failed to create api.json from api.json.example"]);
        exit;
    }
}

// Get the list of artists from the commissions directory, excluding certain files
$repository = array_diff(scandir(json_decode(file_get_contents("api.json"), true)["commissions"]), [".", "..", "dummy.txt"]);

// Initialize an array to store new artists
$news = [];

// Iterate through the repository to find new artists
foreach ($repository as $artist) {
    if (!array_key_exists($artist, $json)) {
        $news[$artist] = -1;
        $json[$artist] = -1;
    }
}

// Sort the JSON data naturally and case-insensitively
ksort($json, SORT_NATURAL | SORT_FLAG_CASE);

if ($previousJson !== $json) {
    // If the JSON data has changed, save the updated JSON data back to the file
    file_put_contents("levels.json", json_encode($json));
}

// Output the new artists as a JSON response
echo json_encode($news);