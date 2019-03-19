# Initialize composer_manager.
php modules/composer_manager/scripts/init.php
composer drupal-rebuild
# Change to the Drupal directory
cd "$DRUPAL_TI_DRUPAL_DIR"
composer require algolia/algoliasearch-client-php:^2.0
composer update -n --lock --verbose
