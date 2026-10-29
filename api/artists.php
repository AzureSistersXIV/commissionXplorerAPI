<?php
// CORS headers must be at the TOP
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=utf-8');

// Include utility functions (must provide getRepositories() and newestThumbnail())
require_once "./utilitaires.php";

const DEFAULT_THUMB = "./assets/img/folder.svg";
const CACHE_TTL = 600; // seconds

// Parse the 'isNsfw' query parameter into a maximum NSFW level.
// Read $_GET directly so ?isNsfw[]=1 can't trigger a TypeError.
$rawNsfw = $_GET["isNsfw"] ?? null;
$maxLevel = parseMaxLevel(is_string($rawNsfw) ? $rawNsfw : null);

// Serve from cache when it is fresh and newer than levels.json
$cacheFile = sys_get_temp_dir() . "/artist_thumbs_{$maxLevel}.json";
if (
    is_file($cacheFile)
    && time() - filemtime($cacheFile) < CACHE_TTL
    && filemtime($cacheFile) >= filemtime("levels.json")
) {
    readfile($cacheFile);
    exit;
}

// Load artists data from JSON file
$artists = json_decode(file_get_contents("levels.json"), true) ?? [];

// Keep only artists whose level is less than or equal to the allowed level
$artists = array_filter($artists, fn($level): bool => $level <= $maxLevel);

// Resolve each artist's thumbnail: the one whose original commission is newest
$thumbsBase = getRepositories()["thumbs"];

foreach ($artists as $artist => $level) {
    $artists[$artist] = newestThumbnail($thumbsBase . $artist) ?? DEFAULT_THUMB;
}

// Sort artists naturally and case-insensitively
ksort($artists, SORT_NATURAL | SORT_FLAG_CASE);

$json = json_encode($artists);

// Write the cache atomically so concurrent requests never read a partial file
$tmp = $cacheFile . "." . getmypid() . ".tmp";
if (file_put_contents($tmp, $json, LOCK_EX) !== false) {
    rename($tmp, $cacheFile);
}

echo $json;