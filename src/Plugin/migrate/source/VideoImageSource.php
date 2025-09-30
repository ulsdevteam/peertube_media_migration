<?php

namespace Drupal\peertube_media_migration\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;      
use GuzzleHttp\Exception\RequestException;
use Drupal\migrate\Row;
use Drupal\migrate\Plugin\MigrationInterface; 
use Drupal\taxonomy\Entity\Term;

/**
 * pull video link from peertube based on media node from drupal
 *
 * @MigrateSource(
 *   id = "video_image_source"
 * )
 */

class VideoImageSource extends SourcePluginBase {

  //declare a local constant
  const PCDM_URI = 'http://pcdm.org/use#ThumbnailImage';

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

	//retry params
	$this->request_retry = [
		'retries_num' => $configuration['max_retires'] ?? 3,
		'delay' => $configuration['delay'] ?? 1  
	];

	//get media use of the new drupal image file entity
	$this->media_use = $this->getMediaUseTerm(self::PCDM_URI); 
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
	'video_name' => $this->t('Video Name'),
	'source_tn_urlpath' => $this->t('Thumbnail filepath'),
	'source_media_of' => $this->t('Parent Repository ItemID'),
	'source_meida_use' => $this->t('Media Use')
	];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
   //define ids used for identification
	$ids = [
		'video_id' => [
			'type' => 'string',
			],
	];
	return $ids;
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
	$arr_Data = $this->getVideoID($entity, $oembedUrl);
        if (empty($arr_Data)) {
		continue;
	}
        
        $tn_result = $this->videoImage_handler($arr_Data, $request_retry); //get thumbnail file_url
	if ( empty($tn_result) ) {
		continue;
	} 
	$rows[] = [
		'video_id' => $tn_result['video_id'], 
		'video_name' => $tn_result['video_name'],
                'source_tn_urlpath' => $tn_result['source_tn_urlpath'],
                'source_media_use' => $tn_result['source_media_use'],
		'source_media_of' => $tn_result['source_media_of'],
		]; 
     }
   	return $rows;
  }

  /**
    * get remote videoIDs
  */
  protected function getVideoID($entity, $fieldName) {
     $f_videoUrl = $entity->get($fieldName);                                                                                                      
     $videoUrl =$f_videoUrl->value; 

     if ( stripos($videoUrl, $this->pattern) !== 0) {
	//\Drupal::logger('peertube_media_migration')->info('Media Video: @name Source URL: @data  does not match peertube pattern.', ['@name'=>$entity->get("name")->value, '@data' => $videoUrl]);
	return NULL;
	} else {
	       $remainings = substr($videoUrl, strlen($this->pattern));
		//find first special char ?/ after pattern 
		$endPos1 = strpos($remainings, '/');
		$endPos2 = strpos($remainings, '?');
		$end = min( $endPos1 !== false ? $endPos1: PHP_INT_MAX, $endPos2 !== false ? $endPos2 : PHP_INT_MAX);

		$videoId = ($end === PHP_INT_MAX) ? $remainings : substr($remainings, 0, $end);
		$data_array = [
		'prefix' => trim($this->uri_prefix),
		'video_name' => $entity->label(),
		'video_id' => $videoId,
                'repo_item_id' => $entity->get('field_media_of')->target_id,
		];
		return $data_array;
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
 *Handle Peertube Video Thumbnail
*/
protected function videoImage_handler(array $arr_data, array $retries) {
	$apiUrl = $arr_data['prefix'] . '/api/v1/videos/' . $arr_data['video_id'];
	for ($try =0; $try <= $retries['retries_num']; $try++) {
        	try {
			$tn_file_data= [];
                	$request = \Drupal::httpClient()->get($apiUrl);
			$request_status = $request->getStatusCode();
                	$result = json_decode($request->getBody(), TRUE); //array resp
			
                	if ($request_status === 200) {
				if (!empty($result['thumbnailPath'])) {
                        		$tn_file_data = [
					'id' => $result['id'],
					'video_id' => $arr_data['video_id'],
					'video_name' => $arr_data['video_name'],
					'source_tn_urlpath' => $arr_data['prefix'] . $result['thumbnailPath'],
					'source_media_use' => $this->media_use->id() ?? '',
					'source_media_of' => $arr_data['repo_item_id']
                                        ];
				}
				return $tn_file_data;
        		}
		} catch(RequestException $e) { // handle RequestException (e.g. 4xx/5xx responses)
			if ($e->hasResponse()) {
				$resp = $e->getResponse();
				//handle peertube rate limit with Retry-After header
				if ($resp->getStatusCode() === 429) {
					if ($try < $retries['retries_num']) {
						$RetryAfter = $e->getResponse()->getHeader('Retry-After');
						$delay_time = !empty($RetryAfter) ? (int)$RetryAfter[0] * pow(2, $try) : $retries['delay'] * pow(2, $try);  //increase retry delay exponentially
						// \Drupal::logger('peertube_media_migration')->info('Reached Peertube rate limit. @delay seconds before retry @try on @VID',['@delay'=>$delay_time,'@try'=>$try+1,'@VID'=>$arr_data['video_id'],]);
						sleep($delay_time);
						continue;
					} else {
						\Drupal::logger('peertube_media_migration')->error('Max retries to retrieve peertube api on Video: @VID',['@VID' => $arr_data['video_id'],]);
						return [];
						}
				}
				//other statuscode 
				 \Drupal::logger('peertube_media_migration')->error('Max retries to retrieve peertube api on Video: @VID',['@VID' => $arr_data['video_id'],]);
				  return [];
			}
		} catch (\Exception $e) {
			\Drupal::logger('peertube_media_migration')->error('Failed to retrieve data from Peertube endpoint @err', ['@err'=>$e->getMessage()]);
 	              	return [];
        	}
	}
	return [];
  }
  /**
   * {@inheritdoc}
   */
  public function __toString() {
    return "Peertube Thumbnail migration";
  }

}
