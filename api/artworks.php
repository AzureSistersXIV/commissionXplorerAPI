<?php
// Handle preflight OPTIONS request for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type");
    header("Access-Control-Max-Age: 86400"); // Cache preflight response for 24 hours
    exit(0);
}

// Set CORS and content type headers for all other responses
header("Access-Control-Allow-Origin: *");
header('Content-Type: application/json; charset=utf-8');

// Include utility functions (must provide getRepositories() and collectFiles())
require_once "./utilitaires.php";

/**
 * Send a JSON error response and stop.
 */
function fail(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

// Get the 'artist' parameter from the request.
// Compare against "" explicitly so an artist named "0" is not treated as missing.
$artist = getFormParameter("artist");
if (!is_string($artist) || $artist === "") {
    fail(400, 'Artist not provided');
}

// Only accept artists that exist in levels.json. This also blocks path traversal
// (e.g. "../something"), which matters because the scan below can delete files.
$known = json_decode(file_get_contents("levels.json"), true) ?? [];
if (!array_key_exists($artist, $known)) {
    fail(404, 'Artist not recognized');
}

file_put_contents('php://stderr', date('[Y-m-d H:i:s] ') . "Debug4: " . print_r($artists, true) . "\n");

// Iterate through each artist and set the thumbnail path
foreach ($artists as $artist => $sfw) {
    $isDir = is_dir($repositories["thumbs"] . $artist);
    if ($isDir) {
        // Explore the directory and sort the contents
        $folder = explorePath($repositories["thumbs"] . $artist);

        // Set the first item in the folder as the thumbnail or a default image
        $artists[$artist] = array_key_exists(0, $folder) ? $folder[0] : "./assets/img/folder.svg";
    } else {
        // Set a default image if the directory does not exist
        $artists[$artist] = "./assets/img/folder.svg";
    }
}

file_put_contents('php://stderr', date('[Y-m-d H:i:s] ') . "Debug1: " . "\n");

// Explore the artist's commissions folder.
// The second argument keeps the existing behaviour of deleting
// desktop.ini / Thumbs.db / @eaDir while scanning.
$path = getRepositories()['commissions'] . $artist;
$files = is_dir($path) ? collectFiles($path) : [];

file_put_contents('php://stderr', date('[Y-m-d H:i:s] ') . "Debug2: " . "\n");

echo json_encode($files);