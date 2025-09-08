# Peertube Migration module

The module includes tow migrations. custom\_peertube\_migration\_tn imports the images associated with remote videos from peertube data source into modern islandora site. custom\_peertube\_migration\_file and custom\_peertube\_migration\_vtt are to used to migrate the videos' language transcripts from peertube data source into modern islandora site. New drupal image and file entities will be created respectively through these migrations, and each new entity will be automatically associated with the drupal repository item linked to the original remote video.
## Usage
1. Install the module
    - Install via composer (`composer require drupal/custom_peertube_migration`)
2. Enable the module and its dependencies
    -   `drush en -y migrate_drupal`
    -   `drush en -y custom_peertube_migration`
    -   Confirm modules status (`drush pml --type=module --status=enabled | grep migrate_plus`)
3. Configurate Module Settings
   Peertube migration module configuration path `/admin/configuration/custom_peertube_migration`

## Migration dataflow
   -  ![Vtt migration dataflow] (dataflow/vtt-migration-dataflow.png)
