<?php

const MAX_LEVEL = 4;           // highest level in the data (used for clamping)
const TRUE_LEVEL = 2;          // what ?isNsfw=true means
const FILE_INDEX_TTL = 600;

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

/**
 * Collect a bounded page of files while traversing in scandir order.
 *
 * @return list<string>
 */
function collectFilesPage(string $path, int $offset, int $limit): array
{
    $files = [];
    $skipped = 0;
    $collect = function (string $directory) use (&$collect, &$files, &$skipped, $offset, $limit): bool {
        global $toIgnore;

        $entries = @scandir($directory);
        if ($entries === false) {
            return false;
        }

        $toRemove = ["desktop.ini", "Thumbs.db", "@eaDir"];
        foreach ($entries as $entry) {
            if ($entry === "." || $entry === ".." || in_array($entry, $toRemove, true)) {
                continue;
            }

            $fullPath = $directory . "/" . $entry;
            if (is_dir($fullPath)) {
                if ($collect($fullPath)) {
                    return true;
                }
                continue;
            }

            $extension = pathinfo($entry, PATHINFO_EXTENSION);
            if ($extension === "" || in_array($extension, $toIgnore, true)) {
                continue;
            }
            if ($skipped < $offset) {
                $skipped++;
                continue;
            }

            $files[] = $fullPath;
            if (count($files) >= $limit) {
                return true;
            }
        }
        return false;
    };

    $collect($path);
    return $files;
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
 * Collect file counts and the newest file without retaining every path.
 *
 * @param array{file_count: int, image_count: int, latest_file: ?string, latest_mtime: int} $summary
 */
function summarizeFiles(
    string $path,
    array &$summary,
    bool $trackLatest = false,
    ?array $repositories = null,
    bool $isThumbnail = false
): void
{
    global $toIgnore;

    $entries = @scandir($path);
    if ($entries === false) {
        return;
    }

    $toRemove = ["desktop.ini", "Thumbs.db", "@eaDir"];
    foreach ($entries as $entry) {
        if ($entry === "." || $entry === ".." || in_array($entry, $toRemove, true)) {
            continue;
        }

        $fullPath = $path . "/" . $entry;
        if (is_dir($fullPath)) {
            summarizeFiles($fullPath, $summary, $trackLatest, $repositories, $isThumbnail);
            continue;
        }

        $extension = pathinfo($entry, PATHINFO_EXTENSION);
        if ($extension === "" || in_array($extension, $toIgnore, true)) {
            continue;
        }

        $summary['file_count']++;
        if (in_array($extension, ["jpg", "jpeg", "png", "PNG", "gif"], true)) {
            $summary['image_count']++;
        }

        if ($trackLatest) {
            $mtime = $isThumbnail && $repositories !== null
                ? commissionMtime($fullPath, $repositories)
                : (@filemtime($fullPath) ?: 0);
            if ($mtime > $summary['latest_mtime']) {
                $summary['latest_file'] = $fullPath;
                $summary['latest_mtime'] = $mtime;
            }
        }
    }
}

/**
 * Get cached file metadata for the requested artists.
 *
 * The cache is invalidated when either configuration file changes and expires
 * periodically to pick up filesystem changes made outside this API.
 *
 * @param array<int, string|int>|null $requestedArtists Defaults to all configured artists.
 * @return array<string|int, array{
 *   scanned_at: int,
 *   latest_ready: bool,
 *   commissions: array{file_count: int, image_count: int, latest_file: ?string, latest_mtime: int},
 *   thumbnails: array{file_count: int, image_count: int, latest_file: ?string, latest_mtime: int}
 * }>
 */
function getArtistFileIndex(?array $requestedArtists = null, bool $includeLatest = false): array
{
    $repositories = getRepositories();
    $artistFile = __DIR__ . "/levels.json";
    $configFile = __DIR__ . "/api.json";
    $cacheFile = sys_get_temp_dir() . "/commission_file_index_"
        . sha1($repositories["commissions"] . "\0" . $repositories["thumbs"]) . ".json";
    $signature = [
        'artists' => is_file($artistFile) ? hash_file('sha256', $artistFile) : null,
        'config' => is_file($configFile) ? hash_file('sha256', $configFile) : null,
    ];
    $artists = json_decode((string)file_get_contents($artistFile), true);
    if (!is_array($artists)) {
        $artists = [];
    }
    $requestedArtists ??= array_keys($artists);
    $requestedArtists = array_values(array_filter(
        array_unique($requestedArtists),
        static fn($artist): bool =>
            (is_string($artist) || is_int($artist)) && array_key_exists($artist, $artists)
    ));

    $readCache = static function () use ($cacheFile, $signature): ?array {
        if (!is_file($cacheFile)) {
            return null;
        }
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (
            !is_array($cached)
            || ($cached['signature'] ?? null) !== $signature
            || !isset($cached['index'])
            || !is_array($cached['index'])
        ) {
            return null;
        }
        return $cached['index'];
    };

    $index = $readCache() ?? [];
    $needsRefresh = static function (array $candidateIndex) use ($requestedArtists, $includeLatest): bool {
        foreach ($requestedArtists as $artist) {
            $scannedAt = $candidateIndex[$artist]['scanned_at'] ?? 0;
            if (
                !is_int($scannedAt)
                || time() - $scannedAt >= FILE_INDEX_TTL
                || ($includeLatest && ($candidateIndex[$artist]['latest_ready'] ?? false) !== true)
            ) {
                return true;
            }
        }
        return false;
    };
    $selectRequested = static fn(array $candidateIndex): array =>
        array_intersect_key($candidateIndex, array_flip($requestedArtists));

    if (!$needsRefresh($index)) {
        return $selectRequested($index);
    }

    $lock = @fopen($cacheFile . ".lock", "c");
    if ($lock !== false && flock($lock, LOCK_EX)) {
        $index = $readCache() ?? [];
        if (!$needsRefresh($index)) {
            flock($lock, LOCK_UN);
            fclose($lock);
            return $selectRequested($index);
        }
    } else {
        error_log("Unable to lock file metadata cache; rebuilding without a lock.");
        if (is_resource($lock)) {
            fclose($lock);
        }
        $lock = false;
    }

    foreach ($requestedArtists as $artist) {
        $commissionSummary = [
            'file_count' => 0,
            'image_count' => 0,
            'latest_file' => null,
            'latest_mtime' => 0,
        ];
        $thumbnailSummary = [
            'file_count' => 0,
            'image_count' => 0,
            'latest_file' => null,
            'latest_mtime' => 0,
        ];
        summarizeFiles($repositories["commissions"] . $artist, $commissionSummary, $includeLatest);
        summarizeFiles(
            $repositories["thumbs"] . $artist,
            $thumbnailSummary,
            $includeLatest,
            $repositories,
            true
        );
        $index[$artist] = [
            'scanned_at' => time(),
            'latest_ready' => $includeLatest,
            'commissions' => $commissionSummary,
            'thumbnails' => $thumbnailSummary,
        ];
    }

    if (is_resource($lock)) {
        $payload = json_encode([
            'signature' => $signature,
            'index' => $index,
        ]);
        $temporaryFile = $cacheFile . "." . getmypid() . ".tmp";
        if ($payload === false || file_put_contents($temporaryFile, $payload, LOCK_EX) === false) {
            error_log("Unable to write file metadata cache.");
            @unlink($temporaryFile);
        } elseif (!rename($temporaryFile, $cacheFile)) {
            error_log("Unable to publish file metadata cache.");
            @unlink($temporaryFile);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    return $selectRequested($index);
}

/**
 * Invalidate filesystem-derived API responses after thumbnail generation.
 */
function invalidateFilesystemCaches(): void
{
    $repositories = getRepositories();
    $indexFile = sys_get_temp_dir() . "/commission_file_index_"
        . sha1($repositories["commissions"] . "\0" . $repositories["thumbs"]) . ".json";
    $lock = @fopen($indexFile . ".lock", "c");
    if ($lock !== false && flock($lock, LOCK_EX)) {
        if (is_file($indexFile) && !unlink($indexFile)) {
            error_log("Unable to invalidate file metadata cache.");
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    } else {
        error_log("Unable to lock file metadata cache for invalidation.");
        if (is_resource($lock)) {
            fclose($lock);
        }
    }

    foreach (glob(sys_get_temp_dir() . '/artist_thumbs_*.json') ?: [] as $cacheFile) {
        if (is_file($cacheFile) && !unlink($cacheFile)) {
            error_log("Unable to invalidate artist thumbnail cache: " . $cacheFile);
        }
    }
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

    $thumbnailDirectory = dirname("../" . $thumbPath);
    if (
        !is_dir($thumbnailDirectory)
        && !@mkdir($thumbnailDirectory, 0755, true)
        && !is_dir($thumbnailDirectory)
    ) {
        throw new RuntimeException("Unable to create thumbnail directory.");
    }

    // Check if the thumbnail file exists
    if (!file_exists("../" . $thumbPath)) {
        $destination = $host . $thumbPath;
        $lockPath = sys_get_temp_dir() . "/thumbnail_" . sha1($destination) . ".lock";
        $lock = @fopen($lockPath, "c");
        if ($lock === false) {
            throw new RuntimeException("Unable to open thumbnail generation lock.");
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new RuntimeException("Unable to acquire thumbnail generation lock.");
        }

        try {
            if (!file_exists("../" . $thumbPath)) {
                $objThumbImage = new ThumbImage($host . $commPath);
                if ($objThumbImage->createThumb($destination, 250)) {
                    invalidateFilesystemCaches();
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
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
    $index = getArtistFileIndex(array_keys($artists));

    // Evaluate every artist in the dataset and store their calculated percentage.
    foreach ($artists as $artist => $sfw) {
        $percentages[$artist] = percentageFromIndex($index[$artist] ?? null);
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
    if ($artist === "") {
        $repositories = getRepositories();
        $commissionSummary = [
            'file_count' => 0,
            'image_count' => 0,
            'latest_file' => null,
            'latest_mtime' => 0,
        ];
        $thumbnailSummary = [
            'file_count' => 0,
            'image_count' => 0,
            'latest_file' => null,
            'latest_mtime' => 0,
        ];
        summarizeFiles($repositories["commissions"], $commissionSummary);
        summarizeFiles($repositories["thumbs"], $thumbnailSummary);
        return percentageFromIndex([
            'commissions' => $commissionSummary,
            'thumbnails' => $thumbnailSummary,
        ]);
    }

    $index = getArtistFileIndex([$artist]);
    return percentageFromIndex($index[$artist] ?? null);
}

/**
 * Calculate thumbnail coverage using indexed file counts.
 *
 * @param array{commissions: array{image_count: int}, thumbnails: array{file_count: int}}|null $artistIndex
 */
function percentageFromIndex(?array $artistIndex): float
{
    $commissionCount = $artistIndex['commissions']['image_count'] ?? 0;
    if ($commissionCount === 0) {
        return 0.0;
    }

    return min(1.0, ($artistIndex['thumbnails']['file_count'] ?? 0) / $commissionCount);
}