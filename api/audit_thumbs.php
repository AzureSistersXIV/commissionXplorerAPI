<?php
/**
 * Audits the generated thumbnails and optionally repairs the defective ones.
 *
 * Place this file next to thumbimage.class.php (the api folder) and run from the CLI:
 *
 *   php audit_thumbs.php                  report only, GIF thumbnails checked in depth
 *   php audit_thumbs.php --all-deep       also compare non-GIF thumbnails with a fresh one
 *   php audit_thumbs.php --fix            replace defective thumbnails with fresh ones
 *   php audit_thumbs.php /other/root      project root holding thumbs/ and commissions/
 *   php audit_thumbs.php --verbose        also list the thumbnails that are fine
 *
 * Why several checks: the weight of a thumbnail alone is not a reliable sign of a
 * complete image (a simple picture is legitimately light, and a thumbnail cut
 * after its top rows is still a valid WebP file of normal-looking size).
 *
 *   1. integrity   the WebP container is complete (declared size == real size)
 *   2. dimensions  the width is the expected one
 *   3. weight      very light files are flagged as suspicious
 *   4. content     the thumbnail is compared with a freshly generated one; a cut
 *                  thumbnail differs strongly, an older encode differs very little
 */
if (PHP_SAPI !== 'cli') {
	exit("Command line only.\n");
}

require __DIR__ . '/thumbimage.class.php';

const EXPECTED_WIDTH = 250;       // width passed to createThumb() in getFullPaths()
const TINY_BYTES = 1500;          // below this a thumbnail is flagged as suspiciously light
const MAX_CONTENT_ERROR = 0.002;  // normalised mean square error tolerated vs a fresh thumbnail

$args = array_slice($argv, 1);
$fix = in_array('--fix', $args, true);
$allDeep = in_array('--all-deep', $args, true);
$verbose = in_array('--verbose', $args, true);
$positional = array_values(array_filter($args, fn($a) => strpos($a, '--') !== 0));
$root = rtrim($positional[0] ?? dirname(__DIR__), '/');
$thumbsDir = $root . '/thumbs';
$commissionsDir = $root . '/commissions';
if (!is_dir($thumbsDir) || !is_dir($commissionsDir)) {
	exit("Expected $thumbsDir and $commissionsDir to exist. Pass the project root as argument.\n");
}

/** True if the file is a complete WebP container (RIFF size matches the file size). */
function webpIsComplete(string $path): bool
{
	$handle = fopen($path, 'rb');
	if ($handle === false) {
		return false;
	}
	$head = fread($handle, 12);
	fclose($handle);
	if (strlen($head) < 12 || substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WEBP') {
		return false;
	}
	$declared = unpack('V', substr($head, 4, 4))[1];
	return $declared + 8 === filesize($path);
}

/** Loads an image and flattens it on white so transparent areas compare fairly. */
function flattened(string $path): Imagick
{
	$image = new Imagick($path);
	$image->setIteratorIndex(0);
	$canvas = new Imagick();
	$canvas->newImage($image->getImageWidth(), $image->getImageHeight(), new ImagickPixel('white'));
	$canvas->compositeImage($image, Imagick::COMPOSITE_OVER, 0, 0);
	$image->destroy();
	return $canvas;
}

/** Normalised mean square error between two images (0 = identical, 1 = opposite). */
function contentError(string $pathA, string $pathB): ?float
{
	$a = flattened($pathA);
	$b = flattened($pathB);
	if ($a->getImageWidth() !== $b->getImageWidth() || $a->getImageHeight() !== $b->getImageHeight()) {
		$a->destroy();
		$b->destroy();
		return null; // different geometry
	}
	$result = $a->compareImages($b, Imagick::METRIC_MEANSQUAREERROR);
	$a->destroy();
	$b->destroy();
	$result[0]->destroy();
	return (float) $result[1];
}

$imagickAvailable = extension_loaded('imagick');
if (!$imagickAvailable) {
	echo "NOTICE: the Imagick extension is not loaded in this PHP binary.\n"
		. "        Only the integrity, width and weight checks will run; the content\n"
		. "        comparison and --fix are disabled. Use the PHP binary that has Imagick\n"
		. "        (the one your web server uses) for the full audit.\n\n";
	$fix = false;
}

$counts = ['checked' => 0, 'bad' => 0, 'fixed' => 0, 'orphans' => 0, 'unregenerable' => 0, 'truncated' => 0];
$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($thumbsDir, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
	$thumb = $file->getPathname();
	if (!$file->isFile() || strtolower(substr($thumb, -5)) !== '.webp') {
		continue;
	}
	$relative = substr($thumb, strlen($thumbsDir) + 1);
	$source = $commissionsDir . '/' . substr($relative, 0, -5); // strip ".webp"
	$size = filesize($thumb);
	$counts['checked']++;

	if (!is_file($source)) {
		$counts['orphans']++;
		// Old naming scheme? A thumbnail "name.webp" next to a source "name.<ext>"
		// is never used: the app looks for "name.<ext>.webp".
		$stem = substr(basename($thumb), 0, -5);
		$sourceDir = dirname($source);
		$similar = null;
		if (is_dir($sourceDir)) {
			foreach (scandir($sourceDir) as $candidate) {
				if ($candidate !== '.' && $candidate !== '..'
					&& pathinfo($candidate, PATHINFO_FILENAME) === $stem
					&& is_file($sourceDir . '/' . $candidate)) {
					$similar = $candidate;
					break;
				}
			}
		}
		if ($similar !== null) {
			echo "ORPHAN   $relative (old naming scheme: source exists as \"$similar\", "
				. "the app expects \"$similar.webp\")\n";
		} else {
			echo "ORPHAN   $relative (source missing)\n";
		}
		continue;
	}

	// A GIF source that does not end with the GIF trailer byte (";") is incomplete.
	// A thumbnail of it is consistent with the file but shows only part of the picture,
	// and the comparison with a fresh thumbnail cannot detect that.
	if (strtolower(pathinfo($source, PATHINFO_EXTENSION)) === 'gif' && filesize($source) > 0) {
		$sourceHandle = fopen($source, 'rb');
		if ($sourceHandle !== false) {
			fseek($sourceHandle, -1, SEEK_END);
			$lastByte = fread($sourceHandle, 1);
			fclose($sourceHandle);
			if ($lastByte !== ';') {
				$counts['truncated']++;
				$sourceSize = filesize($source);
				$note = $sourceSize % 1048576 === 0
					? sprintf(' - exactly %d MiB, typical of an upload or transfer size limit', $sourceSize / 1048576)
					: '';
				echo "SOURCE   " . substr($relative, 0, -5)
					. " (GIF without its end byte: the source file is probably truncated, $sourceSize bytes$note)\n";
			}
		}
	}

	$problems = [];
	$info = @getimagesize($thumb);

	// 1. Integrity of the container.
	if (!webpIsComplete($thumb)) {
		$problems[] = 'incomplete or corrupt WebP file';
	}
	// 2. Dimensions.
	if ($info === false) {
		$problems[] = 'unreadable image';
	} elseif ($info[0] !== EXPECTED_WIDTH) {
		$problems[] = 'width ' . $info[0] . ' instead of ' . EXPECTED_WIDTH;
	}
	// 3. Weight.
	if ($size < TINY_BYTES) {
		$problems[] = "very light ($size bytes)";
	}

	// 4. Content: compare with a fresh thumbnail (always for GIFs, optionally for all).
	$isGif = strtolower(pathinfo($source, PATHINFO_EXTENSION)) === 'gif';
	// Light is only a hint: a simple picture legitimately gives a small thumbnail.
	$onlyLight = count($problems) === 1 && strpos($problems[0], 'very light') === 0;
	$fresh = null;
	if ($imagickAvailable && ($isGif || $allDeep || $problems)) {
		$fresh = sys_get_temp_dir() . '/audit_' . getmypid() . '.webp';
		@unlink($fresh);
		if ((new ThumbImage($source))->createThumb($fresh, EXPECTED_WIDTH) && is_file($fresh)) {
			if (!$problems || $onlyLight) {
				try {
					$error = contentError($thumb, $fresh);
					if ($error === null) {
						$problems[] = 'different size than a fresh thumbnail';
					} elseif ($error > MAX_CONTENT_ERROR) {
						$problems[] = sprintf('content differs from a fresh thumbnail (error %.4f)', $error);
					} elseif ($onlyLight) {
						$problems = []; // light but identical to a fresh thumbnail: legitimate
					}
				} catch (Throwable $e) {
					$problems[] = 'comparison failed: ' . $e->getMessage();
				}
			}
		} else {
			$fresh = null;
			$counts['unregenerable']++;
			echo "SKIPPED  $relative (a fresh thumbnail could not be generated, see error log)\n";
		}
	}

	if (!$problems) {
		if ($verbose) {
			echo "ok       $relative ($size bytes)\n";
		}
		if ($fresh !== null) {
			@unlink($fresh);
		}
		continue;
	}

	$counts['bad']++;
	echo "BAD      $relative ($size bytes): " . implode('; ', $problems) . "\n";
	if ($fix && $fresh !== null) {
		// Overwrite the existing file IN PLACE (no rename of a new file): the owner,
		// permissions and ACL of the thumbnail are preserved. This matters when the
		// audit runs as root on a NAS where the web server user must keep read access.
		// The new content is verified as a complete WebP before it is copied.
		if (webpIsComplete($fresh) && copy($fresh, $thumb)) {
			$counts['fixed']++;
			echo "         -> replaced by a fresh thumbnail (" . filesize($thumb) . " bytes)\n";
		} else {
			echo "         -> replacement FAILED\n";
		}
	}
	if ($fresh !== null) {
		@unlink($fresh);
	}
}

echo "\nTruncated GIF sources: {$counts['truncated']}\n";
echo "Checked: {$counts['checked']}, defective: {$counts['bad']}, repaired: {$counts['fixed']}, "
	. "orphans: {$counts['orphans']}, not regenerable: {$counts['unregenerable']}\n";
if (!$imagickAvailable) {
	echo "Content checks were NOT run (no Imagick): thumbnails of normal size but cut are not detected.\n";
}
if ($counts['bad'] > 0 && !$fix && $imagickAvailable) {
	echo "Run again with --fix to replace the defective thumbnails.\n";
}