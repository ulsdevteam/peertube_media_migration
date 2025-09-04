<?php

namespace Drupal\custom_peertube_migration\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;      
use Drupal\migrate\Row;
use Drupal\migrate\Plugin\MigrationInterface; 
use Drupal\migrate\MigrateSkipRowException;
use Drupal\taxonomy\Entity\Term;

/**
 * pull video link from peertube based on media node from drupal
 *
 * @MigrateSource(
 *   id = "video_vtt_source"
 * )
 */

class VideoVttSource extends SourcePluginBase {

  //declare a local constant
  const PCDM_TRANS_URI = 'http://pcdm.org/use#Transcript';

  protected $count;
  protected $rows = [];
  protected $entities;
  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
	'video_id' => $this->t('Video ID'),
        'source_caption_urlpath' => $this->t('Vtt file path'),
	'vtt_filename' => $this->t('Vtt file name'),
	'source_media_of' => $this->t('Parent Repository ItemID'),
	'source_meida_use' => $this->t('vtt Media Use')
	];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
   //define ids used for identification
    return [
      'vtt_filename' => [
	 'type' => 'string',
	],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function initializeIterator(): \Traversable { 
    $this->rows = $this->buildVids();
    return new \ArrayIterator($this->rows);  	
    }

  /**
    * Construct Video Rows from Contents
    */
  protected function buildVids(): array {
    //get yml configuration
    $entity_type = $this->configuration['entity_type'] ?? 'media';
    $bundle = $this->configuration['bundle'] ?? 'remote_video';
    $source_fields = $this->configuration['source_fields']; 
    $source_oembed_video = $this->configuration['source_fields'][0] ?? '';
    $parent_repo_item_id = $this->configuration['source_fields'][1] ?? '';
    $video_name = $this->configuration['source_fields'][2] ?? '';
    $conditions = $this->configuration['conditions'] ?? [];

   //retrieve remote videos
   $query = \Drupal::entityQuery($entity_type)
	->condition('bundle', $bundle)
	->accessCheck(FALSE);
    if ( !empty($conditions) ) {
    	foreach ($conditions as $cond) {
		$op = $cond['operator'] ?? '=';
		$query-> condition($cond['field'], $cond['value'], $op);
			}
	}
    $entity_ids = $query->execute();

    //return an empty iterator if no entity found
    if ( empty($entity_ids) ) {
	\Drupal::logger('custom_peertube_migration')->info('No entities found for type: @type, bundle: @bundle',['@type' => $entity_type, '@bundle' => $bundle,]);
	return [];
	}

    $entities =\Drupal::entityTypeManager()
	->getStorage($entity_type)
	->loadMultiple($entity_ids);
  
    $rows = [];
    foreach ($entities as $entity) {
	$arr_vttData = $this->getVideoID($entity, $source_oembed_video);
        if (empty($arr_vttData)) {
		continue;
	}
        $captions = $this->videoCaptions_handler($arr_vttData); //get file_url
	if ( empty($captions) ) {
		 continue;
	}
	foreach ($captions as $caption) {
		$rows[] = [
			'vtt_filename' => basename($caption['source_caption_urlpath'], '.vtt'), 
			'video_id' => $caption['video_id'],
                	'source_caption_urlpath' => $caption['source_caption_urlpath'],
                	'source_media_use' => $caption['source_media_use'],
			'source_media_of' => $caption['source_media_of']
		]; 
	}
    }
   return $rows;
  }

  /**
    * get remote videoIDs
  */
  protected function getVideoID($entity, $fieldName) {
     $f_videoUrl = $entity->get($fieldName);                                                                                                      
     $videoUrl =$f_videoUrl->value; 

    //get peertube api prefix as the pattern to match
    $uri_prefix =\Drupal::config('custom_peertube_migration.settings')->get('base_uri'); 
    $pattern = trim($uri_prefix) . "/w/";
    
     if ( stripos($videoUrl, $pattern) !== 0) {
	\Drupal::logger('custom_peertube_migration')->info('Media Video: @name Source URL: @data  does not match peertube pattern.', ['@name'=>$entity->get("name")->value, '@data' => $videoUrl]);
	return NULL;
	} else {
	       $remainings = substr($videoUrl, strlen($pattern));
		//find first special char ?/ after pattern 
		$endPos1 = strpos($remainings, '/');
		$endPos2 = strpos($remainings, '?');
		$end = min( $endPos1 !== false ? $endPos1: PHP_INT_MAX, $endPos2 !== false ? $endPos2 : PHP_INT_MAX);

		$videoId = ($end === PHP_INT_MAX) ? $remainings : substr($remainings, 0, $end);
		$vtt_array = [
		'prefix' => trim($uri_prefix),
		'video_name' => $entity->label(),
		'video_id' => $videoId,
                'repo_item_id' => $entity->get('field_media_of')->target_id,
		];
		return $vtt_array;
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

/** 
 *Handle Peertube Video Caption vtt files
*/
protected function videoCaptions_handler(array $arr_data) {
	$captionUrl = $arr_data['prefix'] . '/api/v1/videos/' . $arr_data['video_id'] . '/captions';
        $vtt_media_use = $this->getMediaUseTerm(self::PCDM_TRANS_URI);
        try {
                $result = \Drupal::httpClient()->get($captionUrl);
                $result_data= json_decode($result->getBody(), TRUE); //array resp
                if (!empty($result_data['data'])){
                        $vtt_file_ids = [];
                        foreach ($result_data['data'] as $item) {
                                if (empty($item['captionPath'])) {
                                        continue;
                                }
                                $vtt_file_ids[] = [
					'video_id' => $arr_data['video_id'],
                                        'source_caption_urlpath' => $arr_data['prefix'] . $item['captionPath'],
                                        'source_media_use' => $vtt_media_use->id() ?? '',
					'source_media_of' => $arr_data['repo_item_id']
                                        ];
                        } //end for

                        return $vtt_file_ids; //array of created caption media ids
                }
        }
        catch(\Exception $e) {
                \Drupal::logger('custom_peertube_migration')->error('Failed to retrieve caption from Peertube endpoint @err', ['@err'=>$e->getMessage()]);
                return [];
        }
  }
  /**
   * {@inheritdoc}
   */
  public function __toString() {
    return "Peertube Transcription migration";
  }

 /**
  * {@inheritdoc}
  */
  public function prepareRow(Row $row) {
	$check_filename = $row->getSourceProperty('vtt_filename');
//	$unique_id = basename($source_caption_urlpath, '.vtt');
//	$row->setSourceProperty('vtt_filename', $check_filename);
	return parent::prepareRow($row);
  }  

}
