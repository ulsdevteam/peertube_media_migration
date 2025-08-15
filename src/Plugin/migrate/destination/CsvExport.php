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
   $destination_data = $row->getDestination();
   $data = [
		'id' => $destination_data['id'],
        	'vid' => $destination_data['vid'],
		'name' => $destination_data['name'], 
		'oembed_url' => $destination_data['oembed_url'],
		'video_id' => $destination_data['oembed_video_data'],
		'parent_repo_item_id' => $destination_data['parent_repo_item_id']
	];

  //open file to write
  $tmp_path = 'temporary://' . "temp_media_export_" . date("Y_m_d") . ".csv";
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
        'video_id' => 'Oembed Video ID',
        'parent_repo_item_id' => 'Parent Repository ItemID',
	];
  }
}




