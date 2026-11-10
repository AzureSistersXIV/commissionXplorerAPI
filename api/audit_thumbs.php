<?php
/**
 * Audits the generated thumbnails and optionally repairs the defective ones.
 *
 * Thumbnail format: the focused side is THUMB_SIZE pixels. It is the HEIGHT for
 * landscape sources and the WIDTH for portrait or square sources (see
 * ThumbImage::createThumb()). Thumbnails made with the old format (always
 * THUMB_SIZE wide) are reported as defective on landscape sources and replaced
 * by --fix.
 *
 * Place this file next to thumbimage.class.php (the api folder) and run from the CLI:
 *
 *   php audit_thumbs.php                  report only, cut-looking thumbnails are compared
 *   php audit_thumbs.php --all-deep       force the content comparison for every thumbnail
 *   php audit_thumbs.php --fix            replace defective thumbnails with fresh ones
 *   php audit_thumbs.php /other/root      project root holding thumbs/ and commissions/
 *   php audit_thumbs.php --verbose        also list the thumbnails that are fine
 *
 * Why several checks: the weight of a thumbnail alone is not a reliable sign of a
 * complete image (a simple picture is legitimately light, and a thumbnail cut
 * after its top rows is still a valid WebP file of normal-looking size).
 *
 *   1. integrity   the WebP container is complete (declared size == real size)
 *   2. dimensions  the focused side (height if landscape, width otherwise) is the expected one
 *   3. weight      very light files are flagged as suspicious
 *   4. shape       a solid black bottom band hints at a cut (partially decoded) thumbnail
 *   5. content     the thumbnail is compared with a freshly generated one; a cut
 *                  thumbnail differs strongly, an older encode differs very little
 */
if (PHP_SAPI !== 'cli') {
	exit("Command line only.\n");
}

require __DIR__ . '/thumbimage.class.php';

const THUMB_SIZE = 250;           // size passed to createThumb() in getFullPaths(): height for landscape, width otherwise
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

/**
 * Checks the focused side of a thumbnail against THUMB_SIZE.
 * Landscape source (width > height): the height must be THUMB_SIZE.
 * Portrait or square source: the width must be THUMB_SIZE.
 * When the source geometry cannot be read, accepts either side being THUMB_SIZE.
 *
 * @param array      $thumbInfo  getimagesize() result of the thumbnail.
 * @param array|false $sourceInfo getimagesize() result of the source.
 * @return string|null A problem description, or null if the dimensions are fine.
 */
function dimensionProblem(array $thumbInfo, $sourceInfo): ?string
{
	[$w, $h] = $thumbInfo;
	if ($sourceInfo === false || $sourceInfo[0] <= 0 || $sourceInfo[1] <= 0) {
		if ($w !== THUMB_SIZE && $h !== THUMB_SIZE) {
			return "size {$w}x{$h}, neither side is " . THUMB_SIZE;
		}
		return null;
	}
	if ($sourceInfo[0] > $sourceInfo[1]) {
		return $h !== THUMB_SIZE
			? "height $h instead of " . THUMB_SIZE . " (landscape source, size {$w}x{$h})"
			: null;
	}
	return $w !== THUMB_SIZE
		? "width $w instead of " . THUMB_SIZE . " (portrait or square source, size {$w}x{$h})"
		: null;
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

/**
 * Tells whether the bottom band of a thumbnail is a uniform black area.
 *
 * When a source is only partially decoded, the rows that were not read stay at
 * zero and the resize keeps them black: a cut thumbnail ends with a solid black
 * band. Normal artwork can also end on black pixels, but a perfectly uniform
 * band is rare, so this is used as a hint: the thumbnail is then compared with a
 * freshly generated one before it is reported (see below).
 *
 * @param string $path     Path to the thumbnail.
 * @param float  $fraction Share of the image height to inspect, from the bottom.
 * @return bool True when that band is solid black.
 */
function hasSolidBlackBottom(string $path, float $fraction = 0.35): bool
{
	try {
		$image = new Imagick($path);
		$image->setIteratorIndex(0);
		$width = $image->getImageWidth();
		$height = $image->getImageHeight();
		if ($width < 1 || $height < 5) {
			$image->clear();
			$image->destroy();
			return false;
		}

		$bandHeight = max(1, (int) floor($height * $fraction));
		$image->setImagePage(0, 0, 0, 0);
		$image->cropImage($width, $bandHeight, 0, $height - $bandHeight);
		$image->setImagePage(0, 0, 0, 0);

		// Flatten on white so transparent pixels are not mistaken for black.
		$canvas = new Imagick();
		$canvas->newImage($width, $bandHeight, new ImagickPixel('white'));
		$canvas->compositeImage($image, Imagick::COMPOSITE_OVER, 0, 0);
		$image->clear();
		$image->destroy();

		$black = true;
		foreach ([Imagick::CHANNEL_RED, Imagick::CHANNEL_GREEN, Imagick::CHANNEL_BLUE] as $channel) {
			$channelMean = $canvas->getImageChannelMean($channel);
			if (!is_array($channelMean) || ($channelMean['mean'] ?? 1.0) > 1.0) {
				$black = false;
				break;
			}
		}
		$canvas->clear();
		$canvas->destroy();
		return $black;
	} catch (Throwable $e) {
		return false;
	}
}

$imagickAvailable = extension_loaded('imagick');
if (!$imagickAvailable) {
	echo "NOTICE: the Imagick extension is not loaded in this PHP binary.\n"
		. "        Only the integrity, dimension and weight checks will run; the content\n"
		. "        comparison and --fix are disabled. Use the PHP binary that has Imagick\n"
		. "        (the one your web server uses) for the full audit.\n\n";
	$fix = false;
} else {
	// ImageMagick derives each image's time-to-live from the global time limit
	// (AcquireImage). A finite limit makes a slow decode expire mid-read, which
	// leaves the rest of the image black and breaks the audit's pixel cache
	// ("time limit exceeded", "Unable to create new image"). Push it far away;
	// zero is not unlimited, it makes every new image expire immediately.
	$longTimeLimit = 100000000; // about three years
	Imagick::setResourceLimit(Imagick::RESOURCETYPE_TIME, $longTimeLimit);
	$effectiveTimeLimit = Imagick::getResourceLimit(Imagick::RESOURCETYPE_TIME);
	if (is_numeric($effectiveTimeLimit) && $effectiveTimeLimit >= 0
		&& $effectiveTimeLimit < $longTimeLimit) {
		echo "WARNING: ImageMagick enforces a time limit of {$effectiveTimeLimit} s\n"
			. "         (set by policy.xml or MAGICK_TIME_LIMIT). Slow decodes are cut\n"
			. "         mid-image, so the audit is stopped instead of replacing good\n"
			. "         thumbnails with cut ones.\n"
			. "         Remove or raise that time limit, then run the audit again.\n"
			. "         Check it with: identify -list policy | grep -i time\n"
			. "                        env | grep MAGICK_TIME_LIMIT\n";
		exit(1);
	}

	// The pixel cache of a large source can need hundreds of megabytes. /tmp is
	// often a small tmpfs on NAS boxes, where ImageMagick then fails with
	// "CacheResourcesExhausted"; put its cache on the thumbnail volume instead.
	$cacheDirectory = realpath($thumbsDir);
	if ($cacheDirectory === false) {
		$cacheDirectory = $thumbsDir;
	}
	if (is_writable($cacheDirectory) && function_exists('putenv')) {
		putenv('MAGICK_TEMPORARY_PATH=' . $cacheDirectory);
	}

	// Apply the same pixel cache limits as ThumbImage, then report what actually
	// applies: a policy.xml value caps them, and a low cap is the cause of
	// "CacheResourcesExhausted" on large sources.
	$cacheLimits = [
		Imagick::RESOURCETYPE_MEMORY => ThumbImage::CACHE_MEMORY_BYTES,
		Imagick::RESOURCETYPE_MAP => ThumbImage::CACHE_MAP_BYTES,
		Imagick::RESOURCETYPE_DISK => ThumbImage::CACHE_DISK_BYTES,
	];
	foreach ($cacheLimits as $type => $value) {
		Imagick::setResourceLimit($type, $value);
	}

	$formatLimit = static function (int $type): string {
		$value = Imagick::getResourceLimit($type);
		return is_numeric($value) && $value > 0
			? (string) (intdiv((int) $value, 1048576))
			: 'unlimited';
	};
	echo "ImageMagick cache limits: memory " . $formatLimit(Imagick::RESOURCETYPE_MEMORY)
		. " MB, map " . $formatLimit(Imagick::RESOURCETYPE_MAP)
		. " MB, disk " . $formatLimit(Imagick::RESOURCETYPE_DISK) . " MB\n";
	echo "ImageMagick cache directory: " . $cacheDirectory . "\n";
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
	// Use the real image type: several files are PNGs or GIFs with a .jpg name, and
	// judging by the extension reports perfectly good sources as truncated.
	$sourceInfo = @getimagesize($source);
	$sourceType = is_array($sourceInfo) ? $sourceInfo[2] : null;
	if ($sourceType === IMAGETYPE_GIF && filesize($source) > 0) {
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
	// A JPEG without its end-of-image marker is not reported here: some tools
	// append data after it and the file still decodes completely. A truly
	// truncated JPEG is caught by the generator (it decodes with a black bottom)
	// and appears as a "could not be generated" entry below.

	$problems = [];
	$info = @getimagesize($thumb);

	// 1. Integrity of the container.
	if (!webpIsComplete($thumb)) {
		$problems[] = 'incomplete or corrupt WebP file';
	}
	// 2. Dimensions: focused side depends on the orientation of the source.
	if ($info === false) {
		$problems[] = 'unreadable image';
	} else {
		$dimensionProblem = dimensionProblem($info, $sourceInfo);
		if ($dimensionProblem !== null) {
			$problems[] = $dimensionProblem;
		}
	}
	// 3. Weight.
	if ($size < TINY_BYTES) {
		$problems[] = "very light ($size bytes)";
	}

	// 4. Shape: a cut thumbnail (partial decode) ends with a solid black band.
	// This is only a hint, confirmed by the content comparison below; otherwise
	// artwork that legitimately ends on black would be reported as defective.
	$blackBottom = $imagickAvailable && $info !== false && hasSolidBlackBottom($thumb);
	if ($blackBottom) {
		$problems[] = 'solid black bottom band (possible cut thumbnail)';
	}

	// 5. Content: compare with a fresh thumbnail (always for GIFs, optionally for all).
	$isGif = strtolower(pathinfo($source, PATHINFO_EXTENSION)) === 'gif';
	// Light and black are only hints: a simple or dark picture is legitimate.
	$onlyLight = count($problems) === 1 && strpos($problems[0], 'very light') === 0;
	$onlyBlack = $blackBottom && count($problems) === 1;
	$fresh = null;
	if ($imagickAvailable && ($isGif || $allDeep || $problems)) {
		$fresh = sys_get_temp_dir() . '/audit_' . getmypid() . '.webp';
		@unlink($fresh);
		if ((new ThumbImage($source))->createThumb($fresh, THUMB_SIZE) && is_file($fresh)) {
			if (!$problems || $onlyLight || $onlyBlack) {
				try {
					$error = contentError($thumb, $fresh);
					if ($error === null) {
						$problems[] = 'different size than a fresh thumbnail';
					} elseif ($error > MAX_CONTENT_ERROR) {
						$problems[] = sprintf('content differs from a fresh thumbnail (error %.4f)', $error);
					} elseif ($onlyLight || $onlyBlack) {
						$problems = []; // legitimate: identical to a fresh thumbnail
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

echo "\nTruncated sources: {$counts['truncated']}\n";
echo "Checked: {$counts['checked']}, defective: {$counts['bad']}, repaired: {$counts['fixed']}, "
	. "orphans: {$counts['orphans']}, not regenerable: {$counts['unregenerable']}\n";
if ($counts['unregenerable'] > 0) {
	echo "Some thumbnails could not be rebuilt because their source is incomplete or unreadable\n"
		. "(see the Thumbnail creation failed lines in the error log). Re-download those sources.\n";
}
if (!$imagickAvailable) {
	echo "Content checks were NOT run (no Imagick): thumbnails of normal size but cut are not detected.\n";
}
if ($counts['bad'] > 0 && !$fix && $imagickAvailable) {
	echo "Run again with --fix to replace the defective thumbnails.\n";
}