<?php

namespace Drupal\peertube_media_migration\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;      
use GuzzleHttp\Exception\RequestException;
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

  /**
   * Current Remote Video Oembed data
   * @var string
   */
  protected $source_oembed_video;

  /** 
   * Current Remote Video's parent repository
   * @var string
   */
  protected $parent_repo_item_id;

  protected $rows = [];
  protected $entities;
  protected $request_retry; //request peertube api parameters
  protected $media_use; //media usage info
  protected $uri_prefix;
  protected $pattern; //remote video oembedUrl filter pattern

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration) {
	parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
	
	//get yml configuration
	if (isset($configuration['entity_type']) ||
		isset($configuration['bundle']) ||
	   	isset($configuration['source_fields']) ||
	   	isset($configuration['conditions']) ||
	   	isset($configuration['source_oembed_video']) ||
	   	isset($configuration['parent_repo_item_id'])) {
	$entity_type = $configuration['entity_type'] ?? 'media';
	$bundle = $configuration['bundle'] ?? 'remote_video';                                                                                   
	$source_fields = $configuration['source_fields'];
	$conditions = $configuration['conditions'] ?? [];
	$this->source_oembed_video = $configuration['source_fields'][0] ?? '';
	$this->parent_repo_item_id = $configuration['source_fields'][1] ?? '';
	}
	//retry params to handle peertube api rate limits
	$this->request_retry = [
		'retries_num' => $configuration['max_retires'] ?? 3,
		'delay' => $configuration['delay'] ?? 1  
	];

	//get media use of the new drupal image file entity
	$this->media_use = $this->getMediaUseTerm(self::PCDM_TRANS_URI); 

	//get peertube api prefix as the pattern to match
	$this->uri_prefix = \Drupal::config('peertube_media_migration.settings')->get('base_uri');
	$this->pattern = trim($this->uri_prefix) . "/w/";
	
	//load remote_video entities
	$query = \Drupal::entityQuery($entity_type)
		->condition('bundle', $bundle)                                                                                                            
		->sort('mid', 'ASC')
		->accessCheck(FALSE);
	 if ( !empty($conditions) ) {
		foreach ($conditions as $cond) {
			$op = $cond['operator'] ?? '=';
			$query-> condition($cond['field'], $cond['value'], $op);
		}
	}
	$entity_ids = $query->execute();

	if ( empty($entity_ids) ) {
		\Drupal::logger('peertube_media_migration')->info('No entities found for type: @type, bundle: @bundle',['@type' => $entity_type, '@bundle' => $bundle,]);
		$this->entities =[];
        } else {
		$this->entities =\Drupal::entityTypeManager()                                                                                                       
			->getStorage($entity_type)
			->loadMultiple($entity_ids);
	}
  }

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
    $this->rows = $this->buildVids($this->entities, $this->source_oembed_video, $this->request_retry);
    return new \ArrayIterator($this->rows);  	
    }

  /**
    * Construct Video Rows from Contents
    */
  protected function buildVids(array $entities, string $oembedUrl, array $request_retry): array {

    $rows = [];
    foreach ($entities as $entity) {
	$arr_vttData = $this->getVideoID($entity, $oembedUrl);
        if (empty($arr_vttData)) {
		continue;
	}
        $captions = $this->videoCaptions_handler($arr_vttData, $request_retry); //get file_url
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
    $uri_prefix =\Drupal::config('peertube_media_migration.settings')->get('base_uri'); 
    $pattern = trim($uri_prefix) . "/w/";
    
     if ( stripos($videoUrl, $pattern) !== 0) {
	//\Drupal::logger('peertube_media_migration')->info('Media Video: @name Source URL: @data  does not match peertube pattern.', ['@name'=>$entity->get("name")->value, '@data' => $videoUrl]);
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
protected function videoCaptions_handler(array $arr_data, array $retries) {
	$captionUrl = $arr_data['prefix'] . '/api/v1/videos/' . $arr_data['video_id'] . '/captions';
	for ($try =0; $try <= $retries['retries_num']; $try++) {
        	try {
                	$result = \Drupal::httpClient()->get($captionUrl);
			$request_status = $result->getStatusCode();
                	$result_data= json_decode($result->getBody(), TRUE); //array resp
			$vtt_file_ids = []; 
			if ($request_status === 200) {
                		if (!empty($result_data['data'])){
                        		foreach ($result_data['data'] as $item) {
                                		if (empty($item['captionPath'])) {
                                        		continue;
                                		}
                                		$vtt_file_ids[] = [
							'video_id' => $arr_data['video_id'],
                                        		'source_caption_urlpath' => $arr_data['prefix'] . $item['captionPath'],
                                        		'source_media_use' => $this->media_use->id() ?? '',
							'source_media_of' => $arr_data['repo_item_id']
                                        	];
                        		} //end for
                        		return $vtt_file_ids; //array of created caption media ids
                		} else {
					//if no video caption data from peertube  
					return [];
					}
				}
        		} catch (RequestException $e) {
				if ($e->hasResponse()) {
				$resp = $e->getResponse();
				//handle peertube rate limit with Retry-After header
					if ($resp->getStatusCode() === 429) {
						if ($try < $retries['retries_num']) {
							$RetryAfter = $e->getResponse()->getHeader('Retry-After');
							$delay_time = !empty($RetryAfter) ? (int)$RetryAfter[0] : $retries['delay'];
							// \Drupal::logger('peertube_media_migration')->info('Reached Peertube rate limit. @delay seconds before retry @try on @VID',['@delay'=>$delay_time,'@try'=>$try+1,'@VID'=>$arr_data['video_id'],]);
							sleep($delay_time);
							continue;
						} else {
							\Drupal::logger('peertube_media_migration')->error('Max retries to retrieve peertube api on Video: @VID',['@VID' => $arr_data['video_id'],]);
							return [];
						}
					}
				}
			//other exception
                	\Drupal::logger('peertube_media_migration')->error('Failed to retrieve caption from Peertube endpoint @err', ['@err'=>$e->getMessage()]);
                	return [];
		}
	}
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    return "Peertube Transcription migration";
  }
}
