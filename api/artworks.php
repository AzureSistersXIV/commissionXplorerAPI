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
$artist = $_GET['artist'] ?? getFormParameter("artist");
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
$rawLimit = $_GET['limit'] ?? getFormParameter('limit');
$hasLimit = array_key_exists('limit', $_GET) || $rawLimit !== false;
$rawCursor = $_GET['cursor'] ?? getFormParameter('cursor');

if (!$hasLimit && $rawCursor !== false) {
    fail(400, 'Cursor requires a limit');
}

if (!$hasLimit) {
    $files = is_dir($path) ? collectFiles($path) : [];
    sendJsonResponse($files, array_key_exists('artist', $_GET));
}

if (
    !(is_int($rawLimit) || (is_string($rawLimit) && ctype_digit($rawLimit)))
    || (int)$rawLimit < 1
) {
    fail(400, 'Limit must be an integer between 1 and 500');
}

$limit = (int)$rawLimit;
if ($limit > 500) {
    fail(400, 'Limit must be an integer between 1 and 500');
}

$offset = 0;
$listing = getArtworkListing($artist, $path);
if ($rawCursor !== false && $rawCursor !== '') {
    if (!is_string($rawCursor)) {
        fail(400, 'Invalid cursor');
    }

    $encodedCursor = strtr($rawCursor, '-_', '+/');
    $encodedCursor .= str_repeat('=', (4 - strlen($encodedCursor) % 4) % 4);
    $cursorData = base64_decode($encodedCursor, true);
    $cursor = $cursorData === false ? null : json_decode($cursorData, true);
    if (
        !is_array($cursor)
        || ($cursor['artist'] ?? null) !== $artist
        || ($cursor['version'] ?? null) !== $listing['version']
        || !isset($cursor['offset'])
        || !is_int($cursor['offset'])
        || $cursor['offset'] < 0
    ) {
        fail(400, 'Invalid cursor');
    }
    if ($cursor['offset'] > PHP_INT_MAX - $limit) {
        fail(400, 'Cursor offset is out of range');
    }
    $offset = $cursor['offset'];
}

try {
    $page = readArtworkListingPage(
        $listing['file'],
        $listing['manifest'],
        $listing['lock'],
        $listing['version'],
        $offset,
        $limit
    );
} catch (InvalidArgumentException $exception) {
    fail(400, 'Invalid cursor offset');
}
$nextCursor = $page['next_offset'] !== null
    ? rtrim(strtr(base64_encode(json_encode([
        'artist' => $artist,
        'version' => $listing['version'],
        'offset' => $page['next_offset'],
    ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=')
    : null;

sendJsonResponse([
    'items' => $page['items'],
    'nextCursor' => $nextCursor,
    'hasMore' => $page['next_offset'] !== null,
], array_key_exists('artist', $_GET));