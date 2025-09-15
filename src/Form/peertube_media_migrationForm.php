<?php

namespace Drupal\peertube_media_migration\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Database;

/**
 * Configure resource migration settings
 */

class peertube_media_migrationForm extends ConfigFormBase {


    /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'peertube_media_migration_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['peertube_media_migration.settings'];
  }

  /**
   * {@inheritdoc}
    */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $config = $this->config('peertube_media_migration.settings');

    $form['connection'] = [
        '#type' => 'details',
        '#title' => t('Peertube API connection'),
        '#open' => TRUE,
    ];

    $form['connection']['peertube_media_migration_base_uri'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Peertube API Prefix'),
	'#config_target' => 'peertube_media_migration.settings:base_uri',
    ];
    return parent::buildForm($form, $form_state);
  }

}

?>
