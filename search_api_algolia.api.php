<?php

/**
 * @file
 * Hooks provided by the Search API Algolia search module.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alter Algolia objects before they are sent to Algolia for indexing.
 *
 * @param $objects
 *   An array of objects ready to be indexed, generated from $items array.
 * @param \Drupal\search_api\IndexInterface $index
 *   The search index for which items are being indexed.
 * @param \Drupal\search_api\Item\ItemInterface[] $items
 *   An array of items to be indexed, keyed by their item IDs.
 */
function hook_search_api_algolia_objects_alter(array &$objects, \Drupal\search_api\IndexInterface $index, array $items) {
  // Adds a "foo" field with value "bar" to all documents.
  foreach ($objects as $key => $object) {
    $objects[$key]['foo'] = 'bar';
  }
}

/**
 * @} End of "addtogroup hooks".
 */
