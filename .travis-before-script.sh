#!/bin/bash

set -e $DRUPAL_TI_DEBUG

# Ensure the right Drupal version is installed.
# The first time this is run, it will install Drupal.
# Note: This function is re-entrant.
drupal_ti_ensure_drupal

# Change to the Drupal directory
cd "$DRUPAL_TI_DRUPAL_DIR"
# Algolia Search
composer require "algolia/algoliasearch-client-php:^2.0"
cd "$DRUPAL_TI_DRUPAL_DIR/$DRUPAL_TI_MODULES_PATH"

# Manually clone the dependencies
git clone --depth 1 --branch 8.x-1.x http://git.drupal.org/project/composer_manager.git

# Initialize composer manager.
php "$DRUPAL_TI_DRUPAL_DIR/$DRUPAL_TI_MODULES_PATH/composer_manager/scripts/init.php"
composer drupal-rebuild
composer update -n --verbose -y


