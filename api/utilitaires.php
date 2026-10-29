<?php

const MAX_LEVEL = 4;           // highest level in the data (used for clamping)
const TRUE_LEVEL = 2;          // what ?isNsfw=true means

require_once "./thumbimage.class.php";

// Array to hold file extensions to ignore
$toIgnore = [
        'ini',
        'db',
        'db@SynoEAStream',
        'png@SynoEAStream',
        'psd@SynoEAStream',
        'txt@SynoEAStream',
        'clip',
        'CLIP',
        'psd',
        'PSD',
        'psb',
        'abr',
        'tif',
        'TIF',
        'tiff',
        'TIFF',
        'fbx',
        'FBX',
        'stl',
        'STL',
        'ztl',
        'ZTL',
        'tga',
        'TGA',
        'obj',
        'OBJ',
        '7z',
        '7Z',
        'zip',
        'ZIP',
    ];

/**
 * Get a query parameter from the URL.
 *
 * @param string $arg The name of the query parameter.
 * @return string|bool The value of the query parameter or false if not found.
 */
function getQueryParameter(string $arg): string|bool
{
    // Check if the query parameter exists in the URL
    if (isset($_GET) && array_key_exists($arg, $_GET)) {
        return $_GET[$arg];
    }
    return false;
}

/**
 * Get a form parameter from POST data or JSON input.
 *
 * @param string $arg The name of the form parameter.
 * @return mixed The value of the form parameter or false if not found.
 */
function getFormParameter(string $arg): mixed
{
    // Check if the form parameter exists in POST data
    if (isset($_POST) && array_key_exists($arg, $_POST)) {
        return $_POST[$arg];
    }

    // Read the raw input data
    $input = file_get_contents('php://input');
    // Decode the JSON input data
    $data = json_decode($input, true);
    // Check if the form parameter exists in the JSON input data
    if ($data !== null && array_key_exists($arg, $data)) {
        return $data[$arg];
    }

    return false;
}

/**
 * Give the max level of nsfw based on the raw input.
 * @param mixed $raw The raw input value to parse.
 * @return int The parsed max level of nsfw (0 to MAX_LEVEL).
 */
function parseMaxLevel(?string $raw): int
{
    if ($raw === null) {
        return 0;
    }

    $lower = strtolower($raw);
    if ($lower === 'true')  return TRUE_LEVEL;
    if ($lower === 'false') return 0;

    if (ctype_digit($raw)) {
        return min((int)$raw, MAX_LEVEL);
    }

    return 0;
}

/**
 * Get the paths to repositories
 * @return array
 */
function getRepositories(): array
{
    static $repos = null;
    return $repos ??= json_decode(file_get_contents("api.json"), true);
}

/** Recursively list files. No sorting, no config reads. */
function collectFiles(string $path, bool $remove = false): array
{
    global $toIgnore;

    $entries = @scandir($path);
    if ($entries === false) {
        return [];
    }

    $toRemove = ["desktop.ini", "Thumbs.db", "@eaDir"];
    $out = [];

    foreach ($entries as $entry) {
        if ($entry === "." || $entry === "..") continue;
        $full = $path . "/" . $entry;

        // Junk entries: delete if asked, always skip otherwise
        if (in_array($entry, $toRemove, true)) {
            if ($remove) {
                is_dir($full)
                    ? system('rm -rf -- ' . escapeshellarg($full))
                    : unlink($full);
            }
            continue;
        }

        if (is_dir($full)) {
            array_push($out, ...collectFiles($full, $remove));
            continue;
        }

        $ext = pathinfo($entry, PATHINFO_EXTENSION);
        if ($ext !== "" && !in_array($ext, $toIgnore, true)) {
            $out[] = $full;
        }
    }

    return $out;
}

/** Map a thumbnail path to its original commission file's mtime. */
function commissionMtime(string $thumb, array $repos): int
{
    $orig = str_replace($repos["thumbs"], $repos["commissions"], $thumb);
    $orig = preg_replace('/\.webp$/', '', $orig);
    return @filemtime($orig) ?: 0;
}

function explorePath(string $path, bool $remove = false): array
{
    if (!is_dir($path)) {
        return [];
    }

    $explored = collectFiles($path, $remove);
    $repos = getRepositories();

    if (str_starts_with($path, $repos["thumbs"])) {
        // One stat per file, one sort for the whole tree
        $times = [];
        foreach ($explored as $f) {
            $times[$f] = commissionMtime($f, $repos);
        }
        usort($explored, fn($a, $b) => $times[$b] <=> $times[$a]);
    }

    return $explored;
}

/** Newest thumbnail of a folder without sorting: a single max() pass. */
function newestThumbnail(string $path): ?string
{
    if (!is_dir($path)) {
        return null;
    }

    $repos = getRepositories();
    $best = null;
    $bestTime = -1;

    foreach (collectFiles($path) as $f) {
        $t = commissionMtime($f, $repos);
        if ($t > $bestTime) {
            $bestTime = $t;
            $best = $f;
        }
    }

    return $best;
}

/**
 * Get the most recent file in a directory (recursively) and its datetime.
 *
 * @param string $path The directory path to explore.
 * @return array|null ['file' => string, 'datetime' => string] or null if none found
 */
function getLastFileWithDatetime(string $path): ?array
{
    global $toIgnore;

    if (!is_dir($path)) {
        throw new Exception("The path provided is not a directory: " . $path);
    }

    $latestFile = null;
    $latestTime = 0;

    $files = array_filter(@scandir($path), function ($file) {
        return $file !== "." && $file !== "..";
    });

    foreach ($files as $file) {
        $fullPath = $path . "/" . $file;

        if (is_dir($fullPath)) {
            // Recurse into subdirectories
            $result = getLastFileWithDatetime($fullPath);

            if ($result && strtotime($result['datetime']) > $latestTime) {
                $latestTime = strtotime($result['datetime']);
                $latestFile = $result['file'];
            }
        } else {
            $extension = pathinfo($file, PATHINFO_EXTENSION);

            if (!empty($extension) && !in_array($extension, $toIgnore)) {
                $fileTime = filemtime($fullPath);

                if ($fileTime > $latestTime) {
                    $latestTime = $fileTime;
                    $latestFile = $fullPath;
                }
            }
        }
    }

    if ($latestFile === null) {
        return null;
    }

    return [
        'file' => $latestFile,
        'datetime' => date('Y-m-d H:i:s', $latestTime),
    ];
}

/**
 * Get the full paths for an artwork and its thumbnail.
 *
 * @param string $artwork The relative path to the artwork.
 * @return array An array containing the full paths to the artwork and its thumbnail.
 * @throws Exception If the artwork link is not found.
 */
function getFullPaths(string $artwork): array
{
    // Set error reporting settings
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', '/path/to/custom.log');

    $host = "../";
    $commPath = $artwork;
    $thumbPath = str_replace("commissions/", "thumbs/", $artwork) . ".webp";

    // Check if the artwork file exists
    if (!file_exists("../" . $commPath)) {
        throw new Exception("Artwork link not found:" . $commPath);
    }

    $dirPath = "";
    // Split the thumbnail path into directories
    $words = explode("/", "../" . $thumbPath);
    foreach ($words as $key => $word) {
        $dirPath .= $word . ($key < count($words) - 1 ? "/" : "");
        // Create the directory if it does not exist
        if (!is_dir($dirPath) && $key < count($words) - 1) {
            mkdir($dirPath, 0755, false);
        }
    }

    // Check if the thumbnail file exists
    if (!file_exists("../" . $thumbPath)) {
        // Create the directory for the thumbnail if it does not exist
        $temp = explode("/", $thumbPath);
        unset($temp[count($temp) - 1]);
        $temp = "../" . implode("/", $temp);
        if (!is_dir($temp))
            mkdir($temp, 0777, false);

        // Create the thumbnail image
        $objThumbImage = new ThumbImage($host . $commPath);
        $objThumbImage->createThumb($host . $thumbPath, 250);
    }

    return [$host . $commPath, $host . $thumbPath];
}

/**
 * Returns the percentage of thumbnail images relative to commission images
 * for each artist listed in levels.json.
 *
 * Assumes:
 * - levels.json contains a map like ["ArtistName" => 0-4]
 * - api.json contains repository paths under keys "thumbs" and "commissions"
 *
 * @return array<string, float> A map of artist names to percentage values
 */
function verifyPercentage(): array
{
    // Read the artist metadata file and decode the JSON into an associative array.
    // If the file is missing or invalid, treat it as an empty list.
    $artists = json_decode(file_get_contents("levels.json"), true);

    if ($artists === null) {
        $artists = [];
    }

    $percentages = [];

    // Evaluate every artist in the dataset and store their calculated percentage.
    foreach ($artists as $artist => $sfw) {
        $percentages[$artist] = getPercentage($artist);
    }

    return $percentages;
}

/**
 * Calculates how many thumbnail images exist relative to the number of commission images
 * for a specific artist.
 *
 * The ratio is clamped to 1.0 max, because a percentage above 100% is not meaningful
 * in this context. If the artist has no commission images, the result is 0.0.
 *
 * @param string $artist Artist name/folder name used to locate their assets
 * @return float Percentage value between 0.0 and 1.0
 */
function getPercentage(string $artist): float
{
    // Load repository paths from JSON config.
    // Expected format:
    // {
    //   "thumbs": "/path/to/thumbs/",
    //   "commissions": "/path/to/commissions/"
    // }
    $repositories = json_decode(file_get_contents("api.json"), true);

    // Default to empty strings if the config keys are missing, to avoid warnings.
    $thumbsBase = $repositories["thumbs"] ?? "";
    $commissionsBase = $repositories["commissions"] ?? "";

    // Explore the artist's thumbnails folder and commissions folder.
    // These functions likely return arrays of filenames or file paths.
    $thumbsPath = explorePath($thumbsBase . $artist);
    $commsPath = explorePath($commissionsBase . $artist);

    // Keep only valid image files in the commissions folder.
    // Supported extensions are common web image formats.
    $commFolder = array_filter($commsPath, function ($file) {
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        return in_array($extension, ["jpg", "jpeg", "png", "PNG", "gif"]);
    });

    // If there are no commission images, return 0.0 to avoid division by zero.
    if (count($commFolder) != 0) {
        // Compute ratio of thumbnails to commissions.
        $ratio = count($thumbsPath) / count($commFolder);

        // Clamp the result to a maximum of 1.0
        // because an artist cannot have more than 100% of the available commissions
        // represented in thumbnails.
        if ($ratio > 1.0) {
            return 1.0;
        }

        return $ratio;
    } else {
        return 0.0;
    }
}