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
$artists = json_decode(file_get_contents("sfw.json"), true);
// Load the path to commissions from the configuration file
$pathCommission = json_decode(file_get_contents("api.json"), true)['commissions'];
$pathThumbs = json_decode(file_get_contents("api.json"), true)['thumbs'];

$sfw = ["artists" => ["count" => 0, "details" => []], "commissions" => ["count" => 0, "details" => []], "thumbnails" => ["count" => 0, "details" => []]];
$nsfw = ["artists" => ["count" => 0, "details" => []], "commissions" => ["count" => 0, "details" => []], "thumbnails" => ["count" => 0, "details" => []]];

foreach($artists as $artist => $key){
	$comms = explorePath($pathCommission.$artist, true);
	try{
		$thumbs = explorePath($pathThumbs.$artist, true);
	}catch(Exception $e){
		http_response_code(200);
    	echo json_encode(['error' => "\"{$artist}\" has no thumbnails."]);
		exit(0);
	}

	if($key){
		$sfw["artists"]["count"]++;
		$sfw["artists"]["details"][] = $artist;

		$sfw["commissions"]["count"] += count($comms);
		$sfw["commissions"]["details"][$artist] = count($comms);

		$sfw["thumbnails"]["count"] += count($thumbs);
		$sfw["thumbnails"]["details"][$artist] = count($thumbs);
	}else{
		$nsfw["artists"]["count"]++;
		$nsfw["artists"]["details"][] = $artist;

		$nsfw["commissions"]["count"] += count($comms);
		$nsfw["commissions"]["details"][$artist] = count($comms);

		$nsfw["thumbnails"]["count"] += count($thumbs);
		$nsfw["thumbnails"]["details"][$artist] = count($thumbs);
	}
}

$stats = [
	"sfw" => $sfw,
	"nsfw" => $nsfw
];

echo json_encode($stats);