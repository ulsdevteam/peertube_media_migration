<?php
namespace Drupal\custom_peertube_migration\Plugin\migrate\destination;

use Drupal\migrate\Plugin\migrate\destination\DestinationBase;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;

/**
 * Provide peertube export videoID to destination
 *
 * @MigrateDestination(
 *   id = "csv_export"
 * )
 */

class CsvExport extends DestinationBase {

  protected $filePath;

 //trigger by migrate::import
 public function import(Row $row, array $old_destination_id_values=[]) {
   //get source data
   $source = $row->getSource();
   $destination_data = $row->getDestination();

   $data = [
		'id' => $source['mid'],
        	'vid' => $source['vid'],
		'name' => $this->arr_to_str($source['name'] ?? ''), 
		'oembed_url' => $this->arr_to_str($source['field_media_oembed_video'] ?? ''),
                'tn_id' => $this->arr_to_str($source['field_tn_uri'] ?? ''),
		'video_id' => $destination_data['oembed_video_data'],
		'parent_repo_item_id' => $destination_data['parent_repo_item_id']
	];

  //open file to write
  $tmp_path = 'temporary://temp_media_export.csv';                                                                
  $filePath =  \Drupal::service('file_system')->realpath($tmp_path); 
  $headers = !file_exists($filePath) || filesize($filePath) ==0;
  $file = fopen($filePath, 'a');
  if ( $headers ) {
 		fputcsv($file, array_keys($data));	
  	}
\Drupal::logger('custom_peertube_migration')->info('CSV value: @data', ['@data' => print_r($data,TRUE)]);
  fputcsv($file, array_values($data));
  fclose($file);
  return [$data['id']];
}

/**
  * convert array value to string
  */
  protected function arr_to_str($value): string {
	if (is_array($value)) {
		if (isset($value[0]['value'])) {
			return (string) $value[0]['value'];
		}
	}
	return (string) $value;
  }

/**
   * {@inheritdoc}
   */
//use Media ID as unique identifier for destination records
 public function getIds() {
	return [
		'id' => ['type' => 'integer'],
		];
}

/**
   * {@inheritdoc}
   */
//define Destination field metadata
  public function fields() {
    return [
	'id' => 'Media ID',
        'vid' => 'VID',
	'name' => 'Media Name',
        'oembed_url' => 'Oembed Video URL',
        'tn_id' => 'Thumbnail URI',
        'video_id' => 'Oembed Video ID',
        'parent_repo_item_id' => 'Parent Repository ItemID',
	];
  }
}




