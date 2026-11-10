<?php
// Class Thumbimage
class ThumbImage
{
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
	 * Method to create the picture thumbnail.
	 *
	 * @param string $destImagePath Path to save the thumbnail image.
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
				$thumb->readImage($this->source . '[0]');
				$compositedFrame = $thumb->coalesceImages();
				$thumb->clear();
				$thumb->destroy();
				$thumb = $compositedFrame;
			} else {
				$thumb->readImage($this->source);
			}
			$thumb->setIteratorIndex(0);
			$thumb->setImageCompressionQuality(75);
			$thumb->thumbnailImage($thumbWidth, 0);
			$thumb->setImageFormat('webp');
			$thumb->writeImage($temporaryPath);
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
