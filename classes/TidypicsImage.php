<?php
/**
 * Tidypics Image class
 *
 * @package TidypicsImage
 * @author Cash Costello
 * @license http://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2
 */


class TidypicsImage extends ElggFile {
	protected function initialise_attributes() {
		parent::initialise_attributes();

		$this->attributes['subtype'] = "image";
	}

	public function __construct($guid = null) {
		parent::__construct($guid);
	}

	/**
	 *
	 * @warning container_guid must be set first
	 *
	 * @param array $data
	 * @return bool
	 */
	public function save($data = null) {

		if (!parent::save()) {
			return false;
		}

		if ($data) {
			// new image
			$this->simpletype = "image";
			$this->saveImageFile($data);
			$this->saveThumbnails();
		}

		return true;
	}

	/**
	 * Get the title of the image
	 *
	 * @return string
	 */
	public function getTitle() {
		return $this->title;
	}

	/**
	 * Get the src URL for the image
	 * 
	 * @return string
	 */
	public function getSrcUrl($size = 'small') {
		return "photos/thumbnail/$this->guid/$size/";
	}

	/**
	 * delete image
	 *
	 * @return bool
	 */
	public function delete() {

		// check if batch should be deleted
		$batch = elgg_get_entities_from_relationship(array(
			'relationship' => 'belongs_to_batch',
			'relationship_guid' => $this->guid,
			'inverse_relationship' => false,
		));
		if ($batch) {
			$batch = $batch[0];
			$count = elgg_get_entities_from_relationship(array(
				'relationship' => 'belongs_to_batch',
				'relationship_guid' => $batch->guid,
				'inverse_relationship' => true,
				'count' => true,
			));
			if ($count == 1) {
				// last image so delete batch
				$batch->delete();
			}
		}

		$album = get_entity($this->container_guid);
		if ($album) {
			$album->removeImage($this->guid);
		}

		$this->removeThumbnails();

		// update quota
		$owner = $this->getOwnerEntity();
		$owner->image_repo_size = (int)$owner->image_repo_size - $this->size();

		return parent::delete();
	}

	/**
	 * Set the internal filenames
	 */
	protected function setOriginalFilename($originalName) {
		$prefix = "image/" . $this->container_guid . "/";
		$filestorename = elgg_strtolower(time() . $originalName);
		$this->setFilename($prefix . $filestorename);
		$this->originalfilename = $originalName;
	}

	/**
	 * Save the uploaded image
	 * 
	 * @param array $data
	 */
	protected function saveImageFile($data) {
		$this->checkUploadErrors($data);

		// we need to make sure the directory for the album exists
		// @note for group albums, the photos are distributed among the users
		$dir = tp_get_img_dir() . $this->getContainerGUID();
		if (!file_exists($dir)) {
			mkdir($dir, 0755, true);
		}

		// move the uploaded file into album directory
		$this->setOriginalFilename($data['name']);
		$filename = $this->getFilenameOnFilestore();
		$result = move_uploaded_file($data['tmp_name'], $filename);
		if (!$result) {
			return false;
		}

		$owner = $this->getOwnerEntity();
		$owner->image_repo_size = (int)$owner->image_repo_size + $size;

		return true;
	}

	protected function checkUploadErrors($data) {
		// check for upload errors
		if ($data['error']) {
			if ($data['error'] == 1) {
				trigger_error('Tidypics warning: image exceeded server php upload limit', E_USER_WARNING);
				throw new Exception(elgg_echo('tidypics:image_mem'));
			} else {
				throw new Exception(elgg_echo('tidypics:unk_error'));
			}
		}

		// must be an image
		if (!tp_upload_check_format($data['type'])) {
			throw new Exception(elgg_echo('tidypics:not_image'));
		}

		// make sure file does not exceed memory limit
		if (!tp_upload_check_max_size($data['size'])) {
			throw new Exception(elgg_echo('tidypics:image_mem'));
		}

		// make sure the in memory image size does not exceed memory available
		$imginfo = getimagesize($data['tmp_name']);
		if (!tp_upload_memory_check($image_lib, $imginfo[0] * $imginfo[1])) {
			trigger_error('Tidypics warning: image memory size too large for resizing so rejecting', E_USER_WARNING);
			throw new Exception(elgg_echo('tidypics:image_pixels'));
		}
	}

	/**
	 * Save the image thumbnails
	 */
	protected function saveThumbnails() {
		elgg_load_library('tidypics:resize');

		$imageLib = elgg_get_plugin_setting('image_lib', 'tidypics');
		
		$prefix = "image/" . $this->container_guid . "/";
		$filename = $this->getFilename();
		$filename = substr($filename, strrpos($filename, '/') + 1);
		
		if ($imageLib == 'ImageMagick') {
			// ImageMagick command line
			if (tp_create_im_cmdline_thumbnails($this, $prefix, $filename) != true) {
				trigger_error('Tidypics warning: failed to create thumbnails - ImageMagick command line', E_USER_WARNING);
			}
		} else if ($imageLib == 'ImageMagickPHP') {
			// imagick php extension
			if (tp_create_imagick_thumbnails($this, $prefix, $filename) != true) {
				trigger_error('Tidypics warning: failed to create thumbnails - ImageMagick PHP', E_USER_WARNING);
			}
		} else {
			if (tp_create_gd_thumbnails($this, $prefix, $filename) != true) {
				trigger_error('Tidypics warning: failed to create thumbnails - GD', E_USER_WARNING);
			}
		}
	}

	/**
	 * Get the image data of a thumbnail
	 *
	 * @param string $size
	 * @return string
	 */
	public function getThumbnail($size) {
		switch ($size) {
			case 'thumb':
				$thumb = $this->thumbnail;
				break;
			case 'small':
				$thumb = $this->smallthumb;
				break;
			case 'large':
				$thumb = $this->largethumb;
				break;
			default:
				return '';
				break;
		}

		if (!$thumb) {
			return '';
		}

		$file = new ElggFile();
		$file->owner_guid = $this->getObjectOwnerGUID();
		$file->setFilename($thumb);
		return $file->grabFile();
	}

	/**
	 * Extract EXIF Data from image
	 *
	 * @warning image file must be saved first
	 */
	public function extractExifData() {
		include_once dirname(dirname(__FILE__)) . "/lib/exif.php";
		td_get_exif($this);
	}
	
	/**
	 * Has the photo been tagged with "in this photo" tags
	 *
	 * @return true/false
	 */
	public function isPhotoTagged() {
		$num_tags = count_annotations($this->getGUID(), 'object', 'image', 'phototag');
		if ($num_tags > 0) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Get an array of photo tag information
	 *
	 * @return array of json representations of the tags and the tag link text
	 */
	public function getPhotoTags() {
		global $CONFIG;

		// get tags as annotations
		$photo_tags = get_annotations($this->getGUID(), 'object', 'image', 'phototag');
		if (!$photo_tags) {
			// no tags or user doesn't have permission to tags, so return
			return false;
		}

		$photo_tags_json = "[";
		foreach ($photo_tags as $p) {
			$photo_tag = unserialize($p->value);

			// create link to page with other photos tagged with same tag
			$phototag_text = $photo_tag->value;
			$phototag_link = $CONFIG->wwwroot . 'search/?tag=' . $phototag_text . '&amp;subtype=image&amp;object=object';
			if ($photo_tag->type === 'user') {
				$user = get_entity($photo_tag->value);
				if ($user) {
					$phototag_text = $user->name;
				} else {
					$phototag_text = "unknown user";
				}

				$phototag_link = $CONFIG->wwwroot . "pg/photos/tagged/" . $photo_tag->value;
			}

			if (isset($photo_tag->x1)) {
				// hack to handle format of Pedro Prez's tags - ugh
				$photo_tag->coords = "\"x1\":\"{$photo_tag->x1}\",\"y1\":\"{$photo_tag->y1}\",\"width\":\"{$photo_tag->width}\",\"height\":\"{$photo_tag->height}\"";
				$photo_tags_json .= '{' . $photo_tag->coords . ',"text":"' . $phototag_text . '","id":"' . $p->id . '"},';
			} else {
				$photo_tags_json .= '{' . $photo_tag->coords . ',"text":"' . $phototag_text . '","id":"' . $p->id . '"},';
			}

			// prepare variable arrays for tagging view
			$photo_tag_links[$p->id] = array('text' => $phototag_text, 'url' => $phototag_link);
		}

		$photo_tags_json = rtrim($photo_tags_json,',');
		$photo_tags_json .= ']';

		$ret_data = array('json' => $photo_tags_json, 'links' => $photo_tag_links);
		return $ret_data;
	}

	/**
	 * Get the view information for this image
	 *
	 * @param $viewer_guid the guid of the viewer (0 if not logged in)
	 * @return array with number of views, number of unique viewers, and number of views for this viewer
	 */
	public function getViews($viewer_guid) {
		$views = get_annotations($this->getGUID(), "object", "image", "tp_view", "", 0, 99999);
		if ($views) {
			$total_views = count($views);

			if ($this->owner_guid == $viewer_guid) {
				// get unique number of viewers
				foreach ($views as $view) {
					$diff_viewers[$view->owner_guid] = 1;
				}
				$unique_viewers = count($diff_viewers);
			}
			else if ($viewer_guid) {
				// get the number of times this user has viewed the photo
				$my_views = 0;
				foreach ($views as $view) {
					if ($view->owner_guid == $viewer_guid) {
						$my_views++;
					}
				}
			}

			$view_info = array("total" => $total_views, "unique" => $unique_viewers, "mine" => $my_views);
		}
		else {
			$view_info = array("total" => 0, "unique" => 0, "mine" => 0);
		}

		return $view_info;
	}

	/**
	 * Add a tidypics view annotation to this image
	 *
	 * @param $viewer_guid
	 * @return none
	 */
	public function addView($viewer_guid) {
		if ($viewer_guid != $this->owner_guid && tp_is_person()) {
			create_annotation($this->getGUID(), "tp_view", "1", "integer", $viewer_guid, ACCESS_PUBLIC);
		}
	}

	/**
	 * Remove thumbnails - usually in preparation for deletion
	 *
	 * The thumbnails are not actually ElggObjects so we create
	 * temporary objects to delete them.
	 */
	protected function removeThumbnails() {
		$thumbnail = $this->thumbnail;
		$smallthumb = $this->smallthumb;
		$largethumb = $this->largethumb;

		//delete standard thumbnail image
		if ($thumbnail) {
			$delfile = new ElggFile();
			$delfile->owner_guid = $this->getOwner();
			$delfile->setFilename($thumbnail);
			$delfile->delete();
		}
		//delete small thumbnail image
		if ($smallthumb) {
			$delfile = new ElggFile();
			$delfile->owner_guid = $this->getOwner();
			$delfile->setFilename($smallthumb);
			$delfile->delete();
		}
		//delete large thumbnail image
		if ($largethumb) {
			$delfile = new ElggFile();
			$delfile->owner_guid = $this->getOwner();
			$delfile->setFilename($largethumb);
			$delfile->delete();
		}
	}
}