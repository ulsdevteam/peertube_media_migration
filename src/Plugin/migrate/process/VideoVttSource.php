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
 *   id = "video_vtt_source"
 * )
 */

class VideoVttSource extends ProcessPluginBase {

  //declare a local constant
  const PCDM_TRANS_URI = 'http://pcdm.org/use#Transcript';

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
	       $remainings = substr($videoUrl, strlen($pattern));
		//find first special char ?/ after pattern 
		$endPos1 = strpos($remainings, '/');
		$endPos2 = strpos($remainings, '?');
		$end = min( $endPos1 !== false ? $endPos1: PHP_INT_MAX, $endPos2 !== false ? $endPos2 : PHP_INT_MAX);

		$videoId = ($end === PHP_INT_MAX) ? $remainings : substr($remainings, 0, $end);

		//step2.  handle video captions migration
		//construct params for vtt
		$vtt_array = [
		'prefix' => $uri_prefix,
		'video_name'=> $video_name,
		'video_id' => $videoId,
		'repo_item_id' => $parent_repository_item_id ?? '' 
		];
		$this->videoCaptions_handler($vtt_array); 
	}
}

/** 
 * Retrieve Media Usage from Taxonomy Terms. e.g. Service File, Thumbnail Image, Extracted Text 
 */
protected function getMediaUseTerm(string $uri) {
        $term_query = \Drupal::entityQuery('taxonomy_term')
			->accessCheck(FALSE)
			->condition('vid', 'islandora_media_use')
			->condition('field_external_uri.uri', trim($uri));                                                                              $term_results = $term_query->execute();
	$terms = Term::loadMultiple($term_results);
        $target_term = NULL;

        //filter term objects via a given value of target_name 
        foreach ($terms as $term) {
        	$target_term =  $term;
                break;
        }
        return $target_term;//only retrieve first term matched
}

/** 
 *Handle Peertube Video Caption vtt files
*/
protected function videoCaptions_handler(array $arr_data) {
	$captionUrl = $arr_data['prefix'] . '/api/v1/videos/' . $arr_data['video_id'] . '/captions';
	try {
		$result = \Drupal::httpClient()->get($captionUrl);
		$result_data= json_decode($result->getBody(), TRUE); //array resp
		if (!empty($result_data['data'])){
			$vtt_file_ids = [];
			foreach ($result_data['data'] as $item) {
				if (empty($item['captionPath'])) continue;
				
				//download vtt
				$vtt_dir = 'public://peertube_vtts/';
				\Drupal::service('file_system')->prepareDirectory($vtt_dir, FileSystemInterface::CREATE_DIRECTORY);
				$vtt_file = \Drupal::service('file.repository')->writeData(
					file_get_contents($arr_data['prefix'] . $item['captionPath']),
						$vtt_dir . \Drupal::service('file_system')->basename($item['captionPath']),
						FileExists::Replace);
					 
				//set permanent                                                                                            
                        	$vtt_file->setPermanent();                                                                                  
                        	$vtt_file->save();   
					
				//set media use for caption file
				if($vtt_file) {
					$vtt_media_use = $this->getMediaUseTerm(self::PCDM_TRANS_URI);
					$vtt_media = Media::create ([
						'bundle' => 'file',
						'name' => "Caption_" . $arr_data['video_name'] ."_" .$item['language']['id'],
						'field_media_file' => [
							'target_id' => $vtt_file->id(),
							],
						'field_media_use' => [
							'target_id' => $vtt_media_use->id(),
							],
						'field_media_of' => [
							'target_id' => $arr_data['repo_item_id'],
							],
					]);

				$vtt_media->save();
				$vtt_file_ids[] = $vtt_media->id();
				}
			} //end for
				return $vtt_file_ids; //array of created caption media ids
		}
	}
	catch(\Exception $e) {
		\Drupal::logger('custom_peertube_migration')->error('Failed to retrieve caption from Peertube endpoint @err', ['@err'=>$e->getMessage()]);
	}
  }
}
