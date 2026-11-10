<?php
// Handle preflight OPTIONS request for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *"); // Allow all origins
    header("Access-Control-Allow-Methods: POST, OPTIONS"); // Allow POST and OPTIONS methods
    header("Access-Control-Allow-Headers: Content-Type"); // Allow Content-Type header
    header("Access-Control-Max-Age: 86400"); // Cache preflight response for 24 hours
    exit(0);
}

// Set CORS header for all responses
header("Access-Control-Allow-Origin: *");
// Set content type to JSON
header('Content-Type: application/json; charset=utf-8');

// Include utility functions
require_once "./utilitaires.php";

// Load artists data from JSON file
$artists = json_decode(file_get_contents("levels.json"), true);
$fileIndex = getArtistFileIndex();

$sfw = ["artists" => ["count" => 0, "details" => []], "commissions" => ["count" => 0, "details" => []], "thumbnails" => ["count" => 0, "details" => []]];
$nsfw = ["artists" => ["count" => 0, "details" => []], "commissions" => ["count" => 0, "details" => []], "thumbnails" => ["count" => 0, "details" => []]];

foreach($artists as $artist => $key){
	$commsCount = $fileIndex[$artist]['commissions']['file_count'] ?? 0;
	$thumbsCount = $fileIndex[$artist]['thumbnails']['file_count'] ?? 0;

	if($key){
		$sfw["artists"]["count"]++;
		$sfw["artists"]["details"][] = $artist;

		$sfw["commissions"]["count"] += $commsCount;
		$sfw["commissions"]["details"][$artist] = $commsCount;

		$sfw["thumbnails"]["count"] += $thumbsCount;
		$sfw["thumbnails"]["details"][$artist] = $thumbsCount;
	}else{
		$nsfw["artists"]["count"]++;
		$nsfw["artists"]["details"][] = $artist;

		$nsfw["commissions"]["count"] += $commsCount;
		$nsfw["commissions"]["details"][$artist] = $commsCount;

		$nsfw["thumbnails"]["count"] += $thumbsCount;
		$nsfw["thumbnails"]["details"][$artist] = $thumbsCount;
	}
}

$stats = [
	"sfw" => $sfw,
	"nsfw" => $nsfw
];

echo json_encode($stats);