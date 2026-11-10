<?php
// Class Thumbimage
class ThumbImage
{
	// When true, a GIF without its final 0x3B trailer byte (a sign of a
	// truncated/in-progress file) is rejected instead of just being logged.
	private const REQUIRE_GIF_TRAILER = false;

	// Sources above this size are decoded from their path, streamed by
	// ImageMagick, instead of being loaded whole in PHP memory.
	private const MAX_BLOB_BYTES = 64 * 1024 * 1024;

	// Pixel cache limits. They are public so audit_thumbs.php can report the
	// values that actually apply (policy.xml can lower them). The disk limit
	// stays at 1 GiB: 2 GiB overflows a signed 32-bit integer, which is what a
	// 32-bit NAS PHP does, and the negative value then disables the disk cache.
	public const CACHE_MEMORY_BYTES = 128 * 1024 * 1024;
	public const CACHE_MAP_BYTES = 512 * 1024 * 1024;
	public const CACHE_DISK_BYTES = 1024 * 1024 * 1024;

	// Picture source for thumbnail.
    private $source;

	/**
	 * Constructor to initialize the source image path.
	 *
	 * @param string $sourceImagePath Path to the source image.
	 */
    public function __construct($sourceImagePath)
    {
        $this->source = $sourceImagePath;
    }

	/**
	 * Method to get the picture source for thumbnail.
	 *
	 * @return string The source image path.
	 */
	public function getSource(){
		return $this->source;
	}

	/**
	 * Reads the source file once (in memory for the usual sizes).
	 *
	 * Decoding a blob instead of a path guarantees ImageMagick decodes exactly
	 * the bytes that were read and checked here. A short read (slow disk, flaky
	 * USB or network volume) used to let ImageMagick return only the first rows
	 * of the picture, the rest being left black, and that cut thumbnail was
	 * then published as a valid one.
	 *
	 * @param string $format Receives the detected image format (upper case).
	 * @return string The complete source file contents, or an empty string for a
	 *                source larger than MAX_BLOB_BYTES (streamed by ImageMagick).
	 * @throws RuntimeException When the source cannot be read completely.
	 */
	private function readSourceBlob(&$format)
	{
		clearstatcache(true, $this->source);
		$expectedSize = @filesize($this->source);
		if ($expectedSize !== false && $expectedSize > self::MAX_BLOB_BYTES) {
			// Too large to duplicate in PHP memory: keep the streaming read.
			$probe = new Imagick();
			try {
				if (!$probe->pingImage($this->source)) {
					throw new RuntimeException("Unable to identify the source image: " . $this->source);
				}
				$format = strtoupper($probe->getImageFormat());
			} finally {
				$probe->clear();
				$probe->destroy();
			}
			return '';
		}

		$blob = false;
		// One immediate retry: a short read on a busy volume is often transient.
		for ($attempt = 0; $attempt < 2; $attempt++) {
			$read = @file_get_contents($this->source);
			if ($read !== false && ($expectedSize === false || strlen($read) === $expectedSize)) {
				$blob = $read;
				break;
			}
		}
		if ($blob === false || $blob === '') {
			throw new RuntimeException("Unable to read the complete source image: " . $this->source);
		}

		$probe = new Imagick();
		try {
			if (!$probe->pingImageBlob($blob)) {
				throw new RuntimeException("Unable to identify the source image: " . $this->source);
			}
			$format = strtoupper($probe->getImageFormat());
		} finally {
			$probe->clear();
			$probe->destroy();
		}

		$this->assertSourceIsComplete($blob, $format);
		return $blob;
	}

	/**
	 * Rejects a source file that is visibly truncated.
	 *
	 * ImageMagick can return the rows it managed to decode without raising an
	 * error, so without this check a truncated file produces a valid-looking
	 * WebP cut after its first rows.
	 *
	 * @param string $blob   Complete file contents.
	 * @param string $format Detected image format (upper case).
	 * @throws RuntimeException When the container is incomplete.
	 */
	private function assertSourceIsComplete($blob, $format)
	{
		$length = strlen($blob);
		switch ($format) {
			case 'PNG':
				// A complete PNG ends with an empty IEND chunk (12 bytes).
				if (substr($blob, -12) !== "\x00\x00\x00\x00IEND\xAE\x42\x60\x82") {
					throw new RuntimeException("PNG source has no end chunk (truncated file): " . $this->source);
				}
				break;
			case 'JPEG':
				// The end-of-image marker may be followed by trailing metadata, so
				// inspect the last 64 KiB rather than only the last two bytes.
				if (strpos(substr($blob, -65536), "\xFF\xD9") === false) {
					$size = strlen($blob);
					$note = $size % 1048576 === 0
						? sprintf(' - exactly %d MiB, typical of an upload or transfer size limit', $size / 1048576)
						: '';
					throw new RuntimeException("JPEG source has no end marker (truncated file, $size bytes$note): "
						. $this->source);
				}
				break;
			case 'WEBP':
				$declared = unpack('V', substr($blob, 4, 4))[1];
				if (substr($blob, 0, 4) !== 'RIFF' || substr($blob, 8, 4) !== 'WEBP'
					|| $declared + 8 !== $length) {
					throw new RuntimeException("WebP source is not a complete container: " . $this->source);
				}
				break;
			case 'GIF':
				if ($blob[$length - 1] !== ';') {
					$message = "GIF has no trailer byte (possibly truncated): " . $this->source
						. " ($length bytes)";
					if (self::REQUIRE_GIF_TRAILER) {
						throw new RuntimeException($message);
					}
					error_log("Thumbnail warning: " . $message);
				}
				break;
		}
	}

	/**
	 * Tells whether a file is a complete WebP container (RIFF size matches).
	 *
	 * @param string $path Path to the file.
	 * @return bool True when the container is complete.
	 */
	private function isCompleteWebp($path)
	{
		clearstatcache(true, $path);
		$handle = @fopen($path, 'rb');
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
	 * Method to create the picture thumbnail.
	 *
	 * @param string $destImagePath Path to save the thumbnail image (content is always WebP).
	 * @param int $thumbSize Size in pixels of the focused side: the height for landscape
	 *                       sources, the width for portrait or square sources. Default is 100.
	 * @return bool True if thumbnail created successfully, false otherwise.
	 */
    public function createThumb($destImagePath, $thumbSize=100)
    {
		if (empty($this->source) || !file_exists($this->source)) {
			return false;
		}
		
		if (empty($destImagePath) || $thumbSize <= 0) {
			return false;
		}
		
		$temporaryPath = $destImagePath . "." . getmypid() . "." . bin2hex(random_bytes(6)) . ".tmp";

		// A large source can need hundreds of megabytes of pixel cache. /tmp is
		// often a small tmpfs on NAS boxes, which makes ImageMagick fail with
		// "CacheResourcesExhausted"; keep the cache on the thumbnail volume.
		// Callers (getFullPaths, the audit) may already have chosen a directory.
		if (getenv('MAGICK_TEMPORARY_PATH') === false) {
			$cacheDirectory = dirname($destImagePath);
			if (is_dir($cacheDirectory) && is_writable($cacheDirectory)
				&& function_exists('putenv')) {
				putenv('MAGICK_TEMPORARY_PATH=' . $cacheDirectory);
			}
		}

		$thumb = null;
		try {
			$resourceLimits = [
				Imagick::RESOURCETYPE_MEMORY => self::CACHE_MEMORY_BYTES,
				Imagick::RESOURCETYPE_MAP => self::CACHE_MAP_BYTES,
				Imagick::RESOURCETYPE_DISK => self::CACHE_DISK_BYTES,
				Imagick::RESOURCETYPE_THREAD => 1,
				Imagick::RESOURCETYPE_WIDTH => 100000,
				Imagick::RESOURCETYPE_HEIGHT => 100000,
			];
			foreach ($resourceLimits as $resource => $limit) {
				if (!Imagick::setResourceLimit($resource, $limit)) {
					throw new RuntimeException("Unable to set an Imagick resource limit.");
				}
			}

			// ImageMagick gives each new image a time-to-live of "now + time
			// limit" (AcquireImage). A finite limit, such as the 30 s an older
			// version of this class set, makes a slow decode expire in the middle
			// of the pixel cache: the rows already read are returned and the rest
			// stay black, which is exactly how cut thumbnails were produced.
			// Push the limit far away instead of setting 0: zero is not
			// "unlimited", it makes every new image expire immediately. A value
			// from policy.xml is a hard cap, so report it instead of pretending
			// the limit is gone.
			$longTimeLimit = 100000000; // about three years
			if (Imagick::setResourceLimit(Imagick::RESOURCETYPE_TIME, $longTimeLimit)) {
				$effectiveTimeLimit = Imagick::getResourceLimit(Imagick::RESOURCETYPE_TIME);
				static $timeLimitWarningIssued = false;
				if (!$timeLimitWarningIssued && is_numeric($effectiveTimeLimit)
					&& $effectiveTimeLimit >= 0 && $effectiveTimeLimit < $longTimeLimit) {
					error_log("Thumbnail warning: ImageMagick enforces a "
						. $effectiveTimeLimit . " s time limit (policy); large decodes may be cut.");
					$timeLimitWarningIssued = true;
				}
			}

			$format = '';
			$blob = $this->readSourceBlob($format);
			$isGif = $format === 'GIF';

			$thumb = new Imagick();
			if ($isGif) {
				// Read only the first frame, then rebuild the full logical canvas
				// by hand when the frame is a partial rectangle with an offset.
				$thumb->readImage($this->source . '[0]');
				$page = $thumb->getImagePage();
				$frameW = $thumb->getImageWidth();
				$frameH = $thumb->getImageHeight();
				if ($page['width'] > 0 && $page['height'] > 0
					&& ($page['width'] != $frameW || $page['height'] != $frameH
						|| $page['x'] != 0 || $page['y'] != 0)) {
					$canvas = new Imagick();
					$canvas->newImage($page['width'], $page['height'], new ImagickPixel('transparent'));
					$canvas->compositeImage($thumb, Imagick::COMPOSITE_OVER, $page['x'], $page['y']);
					$thumb->clear();
					$thumb->destroy();
					$thumb = $canvas;
				}
			} else {
				if ($format === 'JPEG') {
					// Let libjpeg shrink the picture while decoding (it only scales by
					// 1/2, 1/4 or 1/8 and never goes below this size on either side).
					// Without it a very large JPEG is fully decoded in memory and
					// exceeds the pixel cache limits ("cache resources exhausted").
					// Twice the thumbnail size keeps plenty of detail for the final resize.
					$decodeHint = (int) $thumbSize * 2;
					$thumb->setOption('jpeg:size', $decodeHint . 'x' . $decodeHint);
				}
				if ($blob === '') {
					// Very large source: let ImageMagick stream it.
					$thumb->readImage($this->source);
				} else {
					try {
						$thumb->readImageBlob($blob);
					} catch (Throwable $e) {
						throw new RuntimeException("Unable to decode the source image ("
							. strlen($blob) . " bytes): " . $this->source, 0, $e);
					}

					// The decoded picture must have the geometry announced by its
					// header. A scaled JPEG decode is smaller, so compare the ratio.
					$sourceInfo = @getimagesizefromstring($blob);
					if ($sourceInfo !== false && $sourceInfo[0] > 0 && $sourceInfo[1] > 0) {
						$expectedRatio = $sourceInfo[0] / $sourceInfo[1];
						$decodedRatio = $thumb->getImageWidth() / max(1, $thumb->getImageHeight());
						if (abs($expectedRatio - $decodedRatio) > 0.01) {
							throw new RuntimeException("The decoded source does not match its announced geometry: "
								. $this->source);
						}
					}
				}
			}
			$thumb->setIteratorIndex(0);
			$thumb->setImageCompressionQuality(75);

			// Landscape: fit by height. Portrait (and square): fit by width.
			// Passing 0 for the other dimension keeps the aspect ratio.
			if ($thumb->getImageWidth() > $thumb->getImageHeight()) {
				$thumb->thumbnailImage(0, $thumbSize);
			} else {
				$thumb->thumbnailImage($thumbSize, 0);
			}

			$thumb->setImagePage(0, 0, 0, 0); // drop stale canvas/offset geometry
			$thumb->setImageFormat('webp');
			$thumb->writeImage('webp:' . $temporaryPath); // explicit encoder, ".tmp" is irrelevant

			// Never publish a half-written WebP: the next request will try again.
			if (@getimagesize($temporaryPath) === false || !$this->isCompleteWebp($temporaryPath)) {
				throw new RuntimeException("The generated thumbnail is not a complete WebP file.");
			}
			if (!rename($temporaryPath, $destImagePath)) {
				throw new RuntimeException("Unable to publish generated thumbnail.");
			}

			return true;
		} catch (Throwable $e) {
			error_log("Thumbnail creation failed for " . $this->source . ": " . $e->getMessage());
			return false;
		} finally {
			if ($thumb instanceof Imagick) {
				$thumb->clear();
				$thumb->destroy();
			}
			if (is_file($temporaryPath)) {
				unlink($temporaryPath);
			}
		}
    }
}
