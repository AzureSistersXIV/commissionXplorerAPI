<?php
// Class Thumbimage
class ThumbImage
{
	// When true, a GIF without its final 0x3B trailer byte (a sign of a
	// truncated/in-progress file) is rejected instead of just being logged.
	private const REQUIRE_GIF_TRAILER = false;

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
	 * Tells whether a GIF file ends with the GIF trailer byte (0x3B).
	 * A missing trailer usually means the file is truncated or still being written.
	 *
	 * @return bool True if the last byte of the source is 0x3B.
	 */
	private function gifHasTrailer()
	{
		$handle = fopen($this->source, 'rb');
		if ($handle === false) {
			return false;
		}
		$hasTrailer = fseek($handle, -1, SEEK_END) === 0 && fread($handle, 1) === ";";
		fclose($handle);
		return $hasTrailer;
	}

	/**
	 * Method to create the picture thumbnail.
	 *
	 * @param string $destImagePath Path to save the thumbnail image (content is always WebP).
	 * @param int $thumbWidth Width of the thumbnail image. Default is 100.
	 * @return bool True if thumbnail created successfully, false otherwise.
	 */
    public function createThumb($destImagePath, $thumbWidth=100)
    {
		if (empty($this->source) || !file_exists($this->source)) {
			return false;
		}
		
		if (empty($destImagePath) || $thumbWidth <= 0) {
			return false;
		}
		
		$temporaryPath = $destImagePath . "." . getmypid() . "." . bin2hex(random_bytes(6)) . ".tmp";
		$thumb = null;
		try {
			$resourceLimits = [
				Imagick::RESOURCETYPE_MEMORY => 128 * 1024 * 1024,
				Imagick::RESOURCETYPE_MAP => 256 * 1024 * 1024,
				Imagick::RESOURCETYPE_DISK => 512 * 1024 * 1024,
				Imagick::RESOURCETYPE_TIME => 30,
				Imagick::RESOURCETYPE_THREAD => 1,
				Imagick::RESOURCETYPE_WIDTH => 100000,
				Imagick::RESOURCETYPE_HEIGHT => 100000,
			];
			foreach ($resourceLimits as $resource => $limit) {
				if (!Imagick::setResourceLimit($resource, $limit)) {
					throw new RuntimeException("Unable to set an Imagick resource limit.");
				}
			}

			$thumb = new Imagick();
			$thumb->pingImage($this->source);
			$isGif = strtoupper($thumb->getImageFormat()) === 'GIF';
			$thumb->clear();

			if ($isGif) {
				// Diagnostic: a GIF that does not end with its trailer byte is
				// probably incomplete, which makes ImageMagick return only part
				// of the picture without raising an error.
				if (!$this->gifHasTrailer()) {
					$message = "GIF has no trailer byte (possibly truncated): " . $this->source
						. " (" . filesize($this->source) . " bytes)";
					if (self::REQUIRE_GIF_TRAILER) {
						throw new RuntimeException($message);
					}
					error_log("Thumbnail warning: " . $message);
				}

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
				$thumb->readImage($this->source);
			}
			$thumb->setIteratorIndex(0);
			$thumb->setImageCompressionQuality(75);
			$thumb->thumbnailImage($thumbWidth, 0);
			$thumb->setImagePage(0, 0, 0, 0); // drop stale canvas/offset geometry
			$thumb->setImageFormat('webp');
			$thumb->writeImage('webp:' . $temporaryPath); // explicit encoder, ".tmp" is irrelevant
			if (!rename($temporaryPath, $destImagePath)) {
				throw new RuntimeException("Unable to publish generated thumbnail.");
			}

			return true;
		} catch (Throwable $e) {
			error_log("Thumbnail creation failed: " . $e->getMessage());
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