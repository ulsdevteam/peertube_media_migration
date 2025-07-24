<?php

namespace Drupal\custom_peertube_migration\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Database;

/**
 * Configure resource migration settings
 */

class custom_peertube_migrationForm extends ConfigFormBase {


    /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'custom_peertube_migration_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['custom_peertube_migration.settings'];
  }

  /**
   * {@inheritdoc}
    */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $config = $this->config('custom_peertube_migration.settings');

    $form['connection'] = [
        '#type' => 'details',
        '#title' => t('Peertube API connection'),
        '#open' => TRUE,
    ];

    $form['connection']['custom_peertube_migration_base_uri'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Peertube API Prefix'),
	'#config_target' => 'custom_peertube_migration.settings:base_uri',
    ];
    return parent::buildForm($form, $form_state);
  }

}

?>
