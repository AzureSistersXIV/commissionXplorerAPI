<?php

const MAX_LEVEL = 4;           // highest level in the data (used for clamping)
const TRUE_LEVEL = 2;          // what ?isNsfw=true means
const FILE_INDEX_TTL = 600;
const ARTWORK_LIST_TTL = 600;

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

/**
 * Emit a JSON response with a content-derived validator for GET requests.
 */
function sendJsonResponse(mixed $value, bool $allowConditional = true): never
{
    $json = json_encode($value, JSON_THROW_ON_ERROR);
    sendJsonBody($json, $allowConditional);
}

/**
 * Emit an already-encoded JSON body with a content-derived validator.
 */
function sendJsonBody(string $json, bool $allowConditional = true): never
{
    $etag = '"' . hash("sha256", $json) . '"';
    header("ETag: " . $etag);
    header("Cache-Control: private, max-age=0, must-revalidate");

    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    if (
        $allowConditional
        && in_array($method, ['GET', 'HEAD'], true)
        && $ifNoneMatch !== ''
    ) {
        foreach (array_map('trim', explode(',', $ifNoneMatch)) as $candidate) {
            if ($candidate === '*' || preg_replace('/^W\//', '', $candidate) === $etag) {
                http_response_code(304);
                exit;
            }
        }
    }

    echo $json;
    exit;
}

/** Recursively list files. No sorting, no config reads. */
function collectFiles(string $path, bool $remove = false): array
{
    $out = [];
    foreach (iterateFiles($path, $remove) as $file) {
        $out[] = $file;
    }
    return $out;
}

/**
 * Iterate eligible files without materializing a directory's full contents.
 *
 * @return Generator<int, string>
 */
function iterateFiles(string $path, bool $remove = false): Generator
{
    global $toIgnore;

    $directory = @opendir($path);
    if ($directory === false) {
        return;
    }

    $toRemove = ["desktop.ini", "Thumbs.db", "@eaDir"];
    try {
        while (($entry = readdir($directory)) !== false) {
            if ($entry === "." || $entry === "..") {
                continue;
            }

            $fullPath = $path . "/" . $entry;
            if (in_array($entry, $toRemove, true)) {
                if ($remove) {
                    if (is_dir($fullPath)) {
                        system('rm -rf -- ' . escapeshellarg($fullPath));
                    } else {
                        unlink($fullPath);
                    }
                }
                continue;
            }

            if (is_dir($fullPath)) {
                yield from iterateFiles($fullPath, $remove);
                continue;
            }

            $extension = pathinfo($entry, PATHINFO_EXTENSION);
            if ($extension !== "" && !in_array($extension, $toIgnore, true)) {
                yield $fullPath;
            }
        }
    } finally {
        closedir($directory);
    }
}

/**
 * Ensure an on-disk, newline-delimited listing snapshot exists for an artist.
 *
 * @return array{file: string, manifest: string, lock: string, version: string}
 */
function getArtworkListing(string $artist, string $path): array
{
    $cacheBase = sys_get_temp_dir() . "/commission_artworks_"
        . sha1(getRepositories()["commissions"] . "\0" . $artist);
    $listingFile = $cacheBase . ".jsonl";
    $manifestFile = $cacheBase . ".json";
    $lockFile = $cacheBase . ".lock";
    $lock = @fopen($lockFile, "c");
    if ($lock === false) {
        throw new RuntimeException("Unable to lock artwork listing cache.");
    }

    $readFreshManifest = static function () use ($manifestFile, $listingFile): ?array {
        $manifest = is_file($manifestFile)
            ? json_decode((string)file_get_contents($manifestFile), true)
            : null;
        if (
            !is_array($manifest)
            || !is_file($listingFile)
            || !isset($manifest["created_at"], $manifest["version"])
            || !is_int($manifest["created_at"])
            || !is_string($manifest["version"])
            || time() - $manifest["created_at"] >= ARTWORK_LIST_TTL
        ) {
            return null;
        }
        return $manifest;
    };

    $lockHeld = false;
    try {
        if (!flock($lock, LOCK_SH)) {
            throw new RuntimeException("Unable to read artwork listing cache.");
        }
        $lockHeld = true;
        $manifest = $readFreshManifest();
        if ($manifest !== null) {
            return [
                'file' => $listingFile,
                'manifest' => $manifestFile,
                'lock' => $lockFile,
                'version' => $manifest["version"],
            ];
        }

        flock($lock, LOCK_UN);
        $lockHeld = false;
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException("Unable to refresh artwork listing cache.");
        }
        $lockHeld = true;
        $manifest = $readFreshManifest();
        if ($manifest !== null) {
            return [
                'file' => $listingFile,
                'manifest' => $manifestFile,
                'lock' => $lockFile,
                'version' => $manifest["version"],
            ];
        }

        $temporaryListing = $listingFile . "." . getmypid() . ".tmp";
        $output = @fopen($temporaryListing, "wb");
        if ($output === false) {
            throw new RuntimeException("Unable to create artwork listing cache.");
        }

        try {
            if (is_dir($path)) {
                foreach (iterateFiles($path) as $file) {
                    $line = json_encode($file, JSON_THROW_ON_ERROR) . "\n";
                    if (fwrite($output, $line) !== strlen($line)) {
                        throw new RuntimeException("Unable to write artwork listing cache.");
                    }
                }
            }
        } catch (Throwable $exception) {
            @unlink($temporaryListing);
            throw $exception;
        } finally {
            fclose($output);
        }

        if (is_file($manifestFile) && !unlink($manifestFile)) {
            @unlink($temporaryListing);
            throw new RuntimeException("Unable to invalidate stale artwork listing metadata.");
        }
        if (!rename($temporaryListing, $listingFile)) {
            @unlink($temporaryListing);
            throw new RuntimeException("Unable to publish artwork listing cache.");
        }

        $manifest = [
            "created_at" => time(),
            "version" => bin2hex(random_bytes(16)),
        ];
        $temporaryManifest = $manifestFile . "." . getmypid() . ".tmp";
        $manifestJson = json_encode($manifest, JSON_THROW_ON_ERROR);
        if (
            file_put_contents($temporaryManifest, $manifestJson, LOCK_EX) === false
            || !rename($temporaryManifest, $manifestFile)
        ) {
            @unlink($temporaryManifest);
            throw new RuntimeException("Unable to publish artwork listing metadata.");
        }

        return [
            'file' => $listingFile,
            'manifest' => $manifestFile,
            'lock' => $lockFile,
            'version' => $manifest["version"],
        ];
    } finally {
        if ($lockHeld) {
            flock($lock, LOCK_UN);
        }
        fclose($lock);
    }
}

/**
 * Read a bounded page from an indexed listing snapshot using a byte-offset cursor.
 *
 * @return array{items: list<string>, next_offset: ?int}
 */
function readArtworkListingPage(
    string $listingFile,
    string $manifestFile,
    string $lockFile,
    string $version,
    int $offset,
    int $limit
): array
{
    $lock = @fopen($lockFile, "c");
    if ($lock === false || !flock($lock, LOCK_SH)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new RuntimeException("Unable to read artwork listing snapshot.");
    }

    $input = @fopen($listingFile, "rb");
    if ($input === false) {
        flock($lock, LOCK_UN);
        fclose($lock);
        throw new RuntimeException("Unable to read artwork listing cache.");
    }

    try {
        $manifest = json_decode((string)file_get_contents($manifestFile), true);
        if (!is_array($manifest) || ($manifest["version"] ?? null) !== $version) {
            throw new InvalidArgumentException("Artwork listing snapshot has expired.");
        }
        $fileStat = fstat($input);
        if ($fileStat === false || $offset > $fileStat['size']) {
            throw new InvalidArgumentException("Invalid artwork listing cursor offset.");
        }
        if (fseek($input, $offset) !== 0) {
            throw new InvalidArgumentException("Invalid artwork listing cursor offset.");
        }
        if ($offset > 0) {
            fseek($input, $offset - 1);
            if (fgetc($input) !== "\n" || fseek($input, $offset) !== 0) {
                throw new InvalidArgumentException("Invalid artwork listing cursor offset.");
            }
        }

        $items = [];
        $nextOffset = null;
        while (($lineOffset = ftell($input)) !== false && ($line = fgets($input)) !== false) {
            $file = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (!is_string($file)) {
                throw new RuntimeException("Invalid artwork listing cache entry.");
            }
            if (count($items) === $limit) {
                $nextOffset = $lineOffset;
                break;
            }
            $items[] = $file;
        }

        return ['items' => $items, 'next_offset' => $nextOffset];
    } finally {
        fclose($input);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
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