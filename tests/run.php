<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$tempRoot = sys_get_temp_dir() . '/commissionXplorer-tests-' . bin2hex(random_bytes(6));
$apiRoot = $tempRoot . '/serve/api';
$commissionsRoot = $tempRoot . '/commissions';
$thumbsRoot = $tempRoot . '/serve/thumbs';
$cacheRoot = $tempRoot . '/cache';
/** @var array{port: int, process: resource, pipes: array<int, resource>}|null */
$server = null;
$assertions = 0;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

function assertTrue(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function requestApi(string $path, string $method = 'GET', ?array $json = null): array
{
    global $server;
    if ($server === null) {
        throw new RuntimeException('Test server has not been started');
    }

    $url = 'http://127.0.0.1:' . $server['port'] . '/' . ltrim($path, '/');
    $options = [
        'http' => [
            'method' => $method,
            'ignore_errors' => true,
            'timeout' => 5,
        ],
    ];

    if ($json !== null) {
        $options['http']['header'] = "Content-Type: application/json\r\n";
        $options['http']['content'] = json_encode($json, JSON_THROW_ON_ERROR);
    }

    $body = file_get_contents($url, false, stream_context_create($options));
    if ($body === false) {
        throw new RuntimeException("Request failed: $method $path");
    }

    $status = 0;
    foreach ($http_response_header as $header) {
        if (preg_match('/^HTTP\/\S+ (\d{3})/', $header, $matches)) {
            $status = (int)$matches[1];
        }
    }

    return [$status, $body];
}

function jsonResponse(string $body): mixed
{
    try {
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException("Invalid JSON response: " . substr($body, 0, 500), 0, $exception);
    }
}

function clearArtistCache(): void
{
    global $cacheRoot;
    foreach (glob($cacheRoot . '/artist_thumbs_*.json') ?: [] as $cacheFile) {
        unlink($cacheFile);
    }
}

function makeDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException("Could not create fixture directory: $path");
    }
}

function writeTestImage(string $path): void
{
    $image = base64_decode(
        '/9j/4AAQSkZJRgABAQAAAAAAAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/wAALCAACAAIBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AVN//2Q==',
        true
    );
    if ($image === false || file_put_contents($path, $image) === false) {
        throw new RuntimeException("Could not create fixture image: $path");
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        if (file_exists($path)) {
            unlink($path);
        }
        return;
    }

    $entries = new FilesystemIterator($path);
    foreach ($entries as $entry) {
        $entry->isDir() && !$entry->isLink()
            ? removeTree($entry->getPathname())
            : unlink($entry->getPathname());
    }
    rmdir($path);
}

function initializeFixtures(): void
{
    global $projectRoot, $tempRoot, $apiRoot, $commissionsRoot, $thumbsRoot, $cacheRoot;

    makeDirectory($apiRoot);
    makeDirectory($commissionsRoot);
    makeDirectory($thumbsRoot);
    makeDirectory($cacheRoot);

    foreach (glob($projectRoot . '/api/*.php') as $source) {
        if (!copy($source, $apiRoot . '/' . basename($source))) {
            throw new RuntimeException("Could not copy endpoint: $source");
        }
    }

    $artists = [
        'Level0' => 0,
        'Level1' => 1,
        'Level2' => 2,
        'Level3' => 3,
        'Level4' => 4,
        'NoThumb' => 0,
        'EmptyArtist' => 0,
    ];
    file_put_contents($apiRoot . '/levels.json', json_encode($artists, JSON_THROW_ON_ERROR));
    file_put_contents($apiRoot . '/api.json', json_encode([
        'commissions' => $commissionsRoot . '/',
        'thumbs' => $thumbsRoot . '/',
        'servers' => [],
    ], JSON_THROW_ON_ERROR));

    foreach (['Level0', 'Level1', 'Level2', 'Level3', 'Level4', 'NoThumb'] as $artist) {
        makeDirectory($commissionsRoot . '/' . $artist);
    }
    foreach ([
        'Level0' => ['a.jpg'],
        'Level1' => ['a.jpg', 'b.jpg'],
        'Level2' => ['a.jpg'],
        'Level3' => ['a.jpg'],
        'Level4' => ['a.jpg'],
        'NoThumb' => ['a.jpg'],
    ] as $level => $files) {
        $artist = $level;
        $rating = (int)str_replace('Level', '', $level);
        if ($level === 'NoThumb') {
            $rating = -1;
        }
        foreach ($files as $index => $file) {
            writeTestImage($commissionsRoot . '/' . $artist . '/' . $file);
            touch($commissionsRoot . '/' . $artist . '/' . $file, 1700000000 + $rating * 100 + $index);
        }
    }

    foreach (['Level0', 'Level1', 'Level2', 'Level3', 'Level4'] as $artist) {
        makeDirectory($thumbsRoot . '/' . $artist);
    }
    foreach (['Level0/a.jpg.webp', 'Level1/a.jpg.webp', 'Level2/a.jpg.webp', 'Level3/a.jpg.webp', 'Level4/a.jpg.webp'] as $thumb) {
        file_put_contents($thumbsRoot . '/' . $thumb, 'fixture thumbnail');
    }
    putenv('TMPDIR=' . $cacheRoot);
}

function runTests(): void
{
    global $apiRoot, $tempRoot, $server;

    $serverSocket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($serverSocket === false) {
        throw new RuntimeException("Could not allocate test port: $errorMessage");
    }
    $address = stream_socket_get_name($serverSocket, false);
    fclose($serverSocket);
    $port = (int)substr(strrchr($address, ':'), 1);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $apiRoot],
        $descriptors,
        $pipes,
        $apiRoot
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start PHP test server');
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $server = ['port' => $port, 'process' => $process, 'pipes' => $pipes];

    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.1);
        if ($socket !== false) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    assertTrue($ready, 'PHP test server should start');

    $expectedByExactLevel = [
        [null, ['EmptyArtist', 'Level0', 'NoThumb'], 'missing'],
        ['', ['EmptyArtist', 'Level0', 'NoThumb'], 'empty value'],
        ['0', ['EmptyArtist', 'Level0', 'NoThumb'], 'zero'],
        ['1', ['Level1'], 'one'],
        ['2', ['Level2'], 'two'],
        ['3', ['Level3'], 'three'],
        ['4', ['Level4'], 'four'],
        ['99', ['Level4'], 'clamped value'],
        ['-1', ['EmptyArtist', 'Level0', 'NoThumb'], 'negative'],
        ['2.5', ['EmptyArtist', 'Level0', 'NoThumb'], 'decimal'],
        ['false', ['EmptyArtist', 'Level0', 'NoThumb'], 'false boolean'],
        ['empty', ['EmptyArtist', 'Level0', 'NoThumb'], 'invalid text'],
        ['banana', ['EmptyArtist', 'Level0', 'NoThumb'], 'arbitrary text'],
        ['TRUE', ['Level2'], 'uppercase boolean'],
    ];
    foreach ($expectedByExactLevel as [$value, $expectedArtists, $label]) {
        clearArtistCache();
        $query = $label === 'missing' ? '' : '?isNsfw=' . rawurlencode((string)$value);
        [$status, $body] = requestApi('artists.php' . $query);
        assertSameValue(200, $status, "artists.php accepts $label rating input");
        assertSameValue(
            $expectedArtists,
            array_keys(jsonResponse($body)),
            "artists.php filters the expected exact rating for $label"
        );
    }

    clearArtistCache();
    [, $body] = requestApi('artists.php?isNsfw=true');
    assertSameValue(['Level1', 'Level2'], array_keys(jsonResponse($body)), 'lowercase true selects levels 1 and 2');

    clearArtistCache();
    [, $body] = requestApi('artists.php?isNsfw=2&inclusive=true');
    assertSameValue(['EmptyArtist', 'Level0', 'Level1', 'Level2', 'NoThumb'], array_keys(jsonResponse($body)), 'inclusive rating includes all lower levels');

    clearArtistCache();
    [, $body] = requestApi('artists.php?isNsfw=true&inclusive=true');
    assertSameValue(['Level1', 'Level2'], array_keys(jsonResponse($body)), 'inclusive does not change lowercase true special handling');

    clearArtistCache();
    [, $body] = requestApi('artists.php?isNsfw=0');
    assertSameValue('./assets/img/folder.svg', jsonResponse($body)['NoThumb'], 'artist without a thumbnail uses the default folder image');

    clearArtistCache();
    [, $body] = requestApi('artists.php?isNsfw=true');
    $trueArtists = array_keys(jsonResponse($body));
    [, $body] = requestApi('artists.php?isNsfw=2');
    assertSameValue($trueArtists, array_keys(jsonResponse($body)), 'artist cache is shared by requests with the same parsed rating');

    clearArtistCache();
    file_put_contents($apiRoot . '/levels.json', '[]');
    [, $body] = requestApi('artists.php');
    assertSameValue([], jsonResponse($body), 'empty artist configuration returns an empty list');
    file_put_contents($apiRoot . '/levels.json', json_encode([
        'Level0' => 0, 'Level1' => 1, 'Level2' => 2, 'Level3' => 3, 'Level4' => 4,
        'NoThumb' => 0, 'EmptyArtist' => 0,
    ], JSON_THROW_ON_ERROR));
    clearArtistCache();

    [, $body] = requestApi('last.php');
    assertSameValue('Level0', array_key_first(jsonResponse($body)), 'last.php defaults to level 0');
    [, $body] = requestApi('last.php?isNsfw=true');
    assertSameValue('Level2', array_key_first(jsonResponse($body)), 'last.php includes through level 2 for true');
    [, $body] = requestApi('last.php?isNsfw=1');
    assertSameValue('Level1', array_key_first(jsonResponse($body)), 'last.php includes artists at or below a numeric maximum');
    [, $body] = requestApi('last.php?isNsfw=false');
    assertSameValue('Level0', array_key_first(jsonResponse($body)), 'last.php false selects level 0');
    [, $body] = requestApi('last.php?isNsfw=99');
    assertSameValue('Level4', array_key_first(jsonResponse($body)), 'last.php caps levels above 4');
    [, $body] = requestApi('last.php?isNsfw=invalid');
    assertSameValue('Level0', array_key_first(jsonResponse($body)), 'last.php invalid input defaults to level 0');
    file_put_contents($apiRoot . '/levels.json', '[]');
    [, $body] = requestApi('last.php');
    assertSameValue([], jsonResponse($body), 'last.php returns an empty list when there are no eligible artists');
    file_put_contents($apiRoot . '/levels.json', json_encode([
        'Level0' => 0, 'Level1' => 1, 'Level2' => 2, 'Level3' => 3, 'Level4' => 4,
        'NoThumb' => 0, 'EmptyArtist' => 0,
    ], JSON_THROW_ON_ERROR));

    [$status, $body] = requestApi('artworks.php', 'GET', ['artist' => 'Level0']);
    assertSameValue(200, $status, 'artworks.php accepts a GET JSON body');
    assertSameValue(1, count(jsonResponse($body)), 'artworks.php lists artist commission files');
    foreach ([[], ['artist' => ''], ['artist' => 123], ['artist' => null]] as $invalidArtist) {
        [$status, $body] = requestApi('artworks.php', 'GET', $invalidArtist);
        assertSameValue(400, $status, 'artworks.php rejects missing or invalid artist input');
        assertSameValue(['error' => 'Artist not provided'], jsonResponse($body), 'artworks.php returns the documented 400 error');
    }
    [$status, $body] = requestApi('artworks.php', 'GET', ['artist' => 'Unknown']);
    assertSameValue(404, $status, 'artworks.php rejects unknown artists');
    assertSameValue(['error' => 'Artist not recognized'], jsonResponse($body), 'artworks.php returns the documented 404 error');
    [$status, $body] = requestApi('artworks.php', 'GET', ['artist' => 'EmptyArtist']);
    assertSameValue(200, $status, 'artworks.php accepts a known artist without a commission folder');
    assertSameValue([], jsonResponse($body), 'missing or empty commission folder returns an empty list');
    [$status] = requestApi('artworks.php');
    assertSameValue(400, $status, 'artworks.php treats an absent GET body as a missing artist');

    [$status, $body] = requestApi('thumbnail.php', 'POST', ['artworks' => ['../commissions/Level0/a.jpg']]);
    assertSameValue(200, $status, 'thumbnail.php accepts an artworks array');
    $pluralResult = jsonResponse($body);
    assertSameValue(1, count($pluralResult), 'thumbnail.php returns one result per artwork');
    assertTrue(is_file($tempRoot . '/thumbs/Level0/a.jpg.webp'), 'thumbnail.php generates a missing thumbnail');
    [$status, $body] = requestApi('thumbnail.php', 'POST', ['artwork' => '../commissions/Level0/a.jpg']);
    assertSameValue(200, $status, 'thumbnail.php accepts a singular artwork');
    assertSameValue($pluralResult[0], jsonResponse($body), 'singular and plural thumbnail responses have the same shape');
    [$status, $body] = requestApi('thumbnail.php', 'POST', [
        'artwork' => '',
        'artworks' => ['../commissions/Level0/a.jpg'],
    ]);
    assertSameValue(200, $status, 'empty singular artwork falls back to the plural parameter');
    assertSameValue($pluralResult, jsonResponse($body), 'empty singular artwork uses the plural request');
    [$status] = requestApi('thumbnail.php', 'POST', ['artwork' => '../commissions/Level0/missing.jpg']);
    assertTrue($status >= 500, 'thumbnail.php reports a missing source as a server failure, not a success response');

    [, $body] = requestApi('progress.php', 'POST', ['action' => 'thumbnails', 'artist' => 'Level0']);
    assertSameValue(1.0, (float)jsonResponse($body), 'progress endpoint reports complete thumbnail coverage');
    [, $body] = requestApi('progress.php', 'POST', ['action' => 'thumbnails', 'artist' => 'Level1']);
    assertSameValue(0.5, (float)jsonResponse($body), 'progress endpoint reports partial thumbnail coverage');
    [, $body] = requestApi('progress.php', 'POST', ['action' => 'thumbnails', 'artist' => 'EmptyArtist']);
    assertSameValue(0.0, (float)jsonResponse($body), 'progress endpoint reports zero when there are no commissions');
    [, $body] = requestApi('progress.php', 'POST', ['action' => 'thumbnails', 'artist' => 'Unknown']);
    assertSameValue(0.0, (float)jsonResponse($body), 'progress endpoint reports zero for an artist with no matching files');
    file_put_contents($GLOBALS['thumbsRoot'] . '/Level0/extra.webp', 'extra thumbnail');
    [, $body] = requestApi('progress.php', 'POST', ['action' => 'thumbnails', 'artist' => 'Level0']);
    assertSameValue(1.0, (float)jsonResponse($body), 'progress is capped at 1.0 when extra thumbnails exist');
    [$status] = requestApi('progress.php', 'POST', ['action' => 'unknown', 'artist' => 'Level0']);
    assertTrue($status >= 500, 'progress endpoint does not return success for an unknown action');
    [$status] = requestApi('progress.php', 'POST', ['artist' => 'Level0']);
    assertTrue($status >= 500, 'progress endpoint does not return success for a missing action');
    [$status, $body] = requestApi('progress.php', 'POST', ['action' => 'thumbnails']);
    assertSameValue(200, $status, 'progress endpoint accepts a missing artist as an empty name');
    $missingArtistProgress = (float)jsonResponse($body);
    assertTrue(
        $missingArtistProgress >= 0.0 && $missingArtistProgress <= 1.0,
        'a missing progress artist scans the repository roots and returns a ratio'
    );
    [$status] = requestApi('api/progress.php', 'POST', ['action' => 'thumbnails', 'artist' => 'Level0']);
    assertSameValue(404, $status, 'the saved local collection progress URL has a doubled api path');

    [$status, $body] = requestApi('showAllProgress.php');
    assertSameValue(200, $status, 'showAllProgress.php returns success');
    $allProgress = jsonResponse($body);
    assertSameValue(1.0, (float)$allProgress['Level0'], 'all-progress includes complete artists');
    assertSameValue(0.5, (float)$allProgress['Level1'], 'all-progress includes partial artists');
    assertSameValue(0.0, (float)$allProgress['EmptyArtist'], 'all-progress handles artists with no commissions');
    file_put_contents($apiRoot . '/levels.json', 'not json');
    [$status, $body] = requestApi('showAllProgress.php');
    assertSameValue(200, $status, 'showAllProgress.php accepts invalid artist configuration');
    assertSameValue([], jsonResponse($body), 'invalid artist configuration returns an empty list');
}

try {
    if (!extension_loaded('imagick')) {
        throw new RuntimeException('The Imagick PHP extension is required for thumbnail endpoint tests.');
    }

    initializeFixtures();
    runTests();
    fwrite(STDOUT, "OK ($assertions assertions)\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "FAIL: " . $exception->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($server !== null && is_resource($server['process'])) {
        proc_terminate($server['process']);
        foreach ($server['pipes'] as $pipe) {
            fclose($pipe);
        }
        proc_close($server['process']);
    }
    if (is_dir($tempRoot)) {
        removeTree($tempRoot);
    }
}

if (isset($exitCode)) {
    exit($exitCode);
}
