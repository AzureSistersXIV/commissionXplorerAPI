<?php

require_once "./thumbimage.class.php";

// Array to hold file extensions to ignore
$toIgnore = [];

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
 * Explore a directory path and optionally remove certain files.
 *
 * @param string $path The directory path to explore.
 * @param bool $remove Whether to remove certain files.
 * @return array An array of explored file paths.
 */
function explorePath(string $path, bool $remove = false): array
{
    if (!is_dir($path)) {
        throw new Exception("The path provided is not a directory: " . $path);
    }

    $explored = [];
    // Get the list of files in the directory, excluding "." and ".."
    $files = array_filter(@scandir($path), function ($file) {
        return $file !== "." && $file !== "..";
    });

    // Files to remove.
    $toRemove = ["desktop.ini", "Thumbs.db", "@eaDir"];

    // File extensions to ignore
    $toIgnore = [
        // System file format
        'ini',
        'db',
        'db@SynoEAStream',
        'png@SynoEAStream',
        'psd@SynoEAStream',
        'txt@SynoEAStream',

        // File edition formats.
        'clip',
        'CLIP',
        'psd',
        'PSD',

        // Brushes
        'abr',

        // Unknown formats for thumbnails.
        'tif',
        'TIF',
        'tiff',
        'TIFF',

        // 3D formats.
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

        // Compressed files formats.
        '7z',
        '7Z',
        'zip',
        'ZIP',
    ];

    foreach ($files as $key => $file) {
        // Check if the file should be removed
        if (in_array($file, $toRemove) && $remove) {
            if (is_dir($path . "/" . $file)) {
                // Remove the directory and its contents
                system('rm -rf -- ' . escapeshellarg($path . "/" . $file), $retval);
            } else {
                // Remove the file
                unlink($path . "/" . $file);
            }
        } else {
            // Get the file extension
            $extension = pathinfo($file, PATHINFO_EXTENSION);

            if (!empty($extension)) {
                // Check if the file extension is not in the ignore list
                if (!in_array($extension, $toIgnore)) {
                    $explored[] = $path . "/" . $file;
                }
            } else {
                // If the file has no extension, explore it as a directory
                unset($files[$key]);
                $explored = array_merge($explored, explorePath($path . "/" . $file, $remove));
            }
        }
    }

    return $explored;
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
    $thumbPath = str_replace("commissions/", "thumbs/", $artwork);

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