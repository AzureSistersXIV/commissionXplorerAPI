<?php
// Handle preflight OPTIONS request for CORS[cite: 2]
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type");
    header("Access-Control-Max-Age: 86400"); // Cache preflight response for 24 hours[cite: 2]
    exit(0);
}

// Set CORS and content type headers for all other responses[cite: 2]
header("Access-Control-Allow-Origin: *");
header('Content-Type: application/json; charset=utf-8');

// Include utility functions (must provide getRepositories() and collectFiles())[cite: 2]
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

// Get the 'artist' parameter from the request.[cite: 2]
// Compare against "" explicitly so an artist named "0" is not treated as missing.[cite: 2]
$artist = getFormParameter("artist");
if (!is_string($artist) || $artist === "") {
    fail(400, 'Artist not provided');
}

// Only accept artists that exist in levels.json. This also blocks path traversal[cite: 2]
// (e.g. "../something"), which matters because the scan below can delete files.[cite: 2]
$known = json_decode(file_get_contents("levels.json"), true) ?? [];
if (!array_key_exists($artist, $known)) {
    fail(404, 'Artist not recognized');
}

// Explore the artist's commissions folder.
$path = getRepositories()['commissions'] . $artist;
$files = is_dir($path) ? collectFiles($path) : [];

file_put_contents('php://stderr', date('[Y-m-d H:i:s] ') . "Debug2: " . "\n");

echo json_encode($files);