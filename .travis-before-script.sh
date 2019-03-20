#!/bin/bash

set -e $DRUPAL_TI_DEBUG

# Ensure the right Drupal version is installed.
# The first time this is run, it will install Drupal.
# Note: This function is re-entrant.
drupal_ti_ensure_drupal

# Change to the Drupal directory
cd "$DRUPAL_TI_DRUPAL_DIR"
echo "Show current DIR"
pwd
ls -la
# Algolia Search
composer require "algolia/algoliasearch-client-php:^2.0"
ls -la
# Update composer
composer install --prefer-source
