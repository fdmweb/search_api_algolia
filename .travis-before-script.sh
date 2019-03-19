# Initialize composer_manager.
php modules/composer_manager/scripts/init.php
composer drupal-rebuild
composer update -n --lock --verbose
