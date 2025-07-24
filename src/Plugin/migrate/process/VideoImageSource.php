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

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {

    //get peertube api prefix as the pattern to match
    $uri_prefix =\Drupal::config('custom_peertube_migration.settings')->get('base_uri'); 
    $escapedUri = preg_quote(substr($uri_prefix, strlen('https://')), '/');
    $pattern = "#". $escapedUri . "/w/([a-zA-Z0-9]+)#";

    //get source parameters
    $videoUrl = $value[0];
    $parent_repository_item_id = $value[1]; 
    $video_name = $value[2]; 
    
   if (!(preg_match("@^https://@i", $videoUrl) && preg_match($pattern, substr($videoUrl, strlen('https://')), $matches))) {  	
	throw new MigrateSkipRowException('Media Video Source URL does not match peertube pattern');
	} else {
   		// \Drupal::logger('custom_peertube_migration')->info('found matched row: @data', ['@data' => $pattern]);
    		$videoId =  $matches[1];
	
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
				if ($tn_file) {
					$media_term = $this->getMediaUseTerm('Thumbnail Image');
					$media_item = Media::create([
						'bundle' => 'image',
						'name' => "Thumbnail_{$data['name']}",
						'field_media_image' => [
							'target_id' => $tn_file->id(),
						'alt' => "Thumbnail of Video {$videoId}",
						],
					'field_media_use' => [ //refer to islandora media usage taxonomy
						'target_id' => $media_term->id(),
						],
					'field_media_of' => [ //refer to parent repository item 
						'target_id' => $parent_repository_item_id,
						],
					]);

					$media_item->save();
					return $media_item->id();
				}
			}
		}
	catch(\Exception $e) {
	\Drupal::logger('custom_peertube_migration')->error('Failed to connect Peertube API: @msg.', ['@msg' => $e->getMessage()]);
		}
	}
}

/**
 * Retrieve Media Usage from Taxonomy Terms. e.g. Service File, Thumbnail Image, Extracted Text 
 */
protected function getMediaUseTerm(string $usage_name) {
	$terms = \Drupal::entityTypeManager()
		->getStorage('taxonomy_term')
		->loadByProperties([
			'vid' => 'islandora_media_use',
			'name' => $usage_name,
		]);
		
	//handle if no needed media usage defined in taxonomy
	if ( empty($terms) ) {
		$term = Term::create ([
			'vid' => 'islandora_media_use',
			'name' => $usage_name,			
		]);
		$term->save();
		return $term;			
	} 
	return reset($terms); //only retrieve first term matched
   }
}
