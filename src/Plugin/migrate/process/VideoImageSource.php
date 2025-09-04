<?php

namespace Drupal\custom_peertube_migration\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\migrate\MigrateSkipRowException;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Drupal\Core\File\FileRepositoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystem;
use Drupal\taxonomy\Entity\Term;

/**
 * pull video link from peertube based on media node from drupal
 *
 * @MigrateProcessPlugin(
 *   id = "video_image_source"
 * )
 */

class VideoImageSource extends ProcessPluginBase {

  //declare an thumbnail constant
  const PCDM_URI = 'http://pcdm.org/use#ThumbnailImage';

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {

    //get peertube api prefix as the pattern to match
    $uri_prefix =\Drupal::config('custom_peertube_migration.settings')->get('base_uri'); 
    $pattern = trim($uri_prefix) . "/w/";
    
    //get source parameters
    $videoUrl = $value[0];
    $parent_repository_item_id = $value[1]; 
    $video_name = $value[2]; 
    
     if ( stripos($videoUrl, $pattern) !== 0) {
	\Drupal::logger('custom_peertube_migration')->info('Video URL Not matched on record: @data', ['@data' => $video_name]);
	throw new MigrateSkipRowException('Media Video Source URL does not match peertube pattern');
	} else {
    		//$videoId = basename(substr($videoUrl, strlen($pattern))); //only take the lastpart of videoUrl
	       
		$remainings = substr($videoUrl, strlen($pattern));
		//find first special char ?/ after pattern
		$endPos1 = strpos($remainings, '/');
		$endPos2 = strpos($remainings, '?');
		
		//retrieve the string ended from the first occurrence special char after pattern 
		$end = min( $endPos1 !== false ? $endPos1: PHP_INT_MAX, $endPos2 !== false ? $endPos2 : PHP_INT_MAX);
		$videoId = ($end === PHP_INT_MAX) ? $remainings : substr($remainings, 0, $end);
		
		//Step1. handle image migration into drupal
		$peertube_api = $uri_prefix . '/api/v1/videos/' . $videoId;
		$peertube_client = \Drupal::httpClient();
		try {
			$resp = $peertube_client->get($peertube_api);
			$data = json_decode($resp->getBody(), TRUE);
		
			if (!empty($data['thumbnailPath'] )) {
				$dir = 'public://peertube_thumbnails/';
				$file_system = \Drupal::service('file_system');
				$file_system->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);
				$full_fp = $uri_prefix.$data['thumbnailPath'];
			
				//download and save thumbnail file
				$tn_file = \Drupal::service('file.repository')->writeData(
				file_get_contents($full_fp), $dir . $file_system->basename($data['thumbnailPath']),
					FileExists::Replace);
			
				//set permanent
				$tn_file->setPermanent();
				$tn_file->save();
				
				//create media entity
				if (!$tn_file) {
					return NULL;
				}
				$media_term = $this->getMediaUseTerm(self::PCDM_URI);
				if($media_term) {
					//return array for image fields needed
					$data_result = [
						'field_media_image' => [
							'target_id' => $tn_file->id(),
							'alt' => "Thumbnail of Video {$videoId}",
							],
						'field_media_use' => [
							'target_id' => $media_term->id(),
							]
						];
					return $data_result;
					}
				}//path
			} //try
		catch(\Exception $e) {
		\Drupal::logger('custom_peertube_migration')->error('Failed to connect Peertube API: @msg.', ['@msg' => $e->getMessage()]);
		return NULL;
		}
	}
}

/** 
 * Retrieve Media Usage from Taxonomy Terms. e.g. Service File, Thumbnail Image, Extracted Text 
 */
protected function getMediaUseTerm(string $uri) {
	$term_query = \Drupal::entityQuery('taxonomy_term')
			->accessCheck(FALSE)
			->condition('vid', 'islandora_media_use')
			->condition('field_external_uri.uri', trim($uri)); 
	$term_results = $term_query->execute();
	
	$terms = Term::loadMultiple($term_results);
        $target_term = NULL;

        //filter term objects via a given value of target_name 
        foreach ($terms as $term) {
        	$target_term =  $term;
                break;
        }
        return $target_term;//only retrieve first term matched
   }
}
