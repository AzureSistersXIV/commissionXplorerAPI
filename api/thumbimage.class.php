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
	 */
    public function createThumb($destImagePath, $thumbWidth=100)
    {		
		try{
			$thumb = new Imagick($this->source);
			$thumb->resizeImage($thumbWidth, 0, Imagick::FILTER_LANCZOS,1);
			$thumb->writeImage($destImagePath);
		}catch(Exception $e){
			// Handle exception if image processing fails.
		}
    }
}