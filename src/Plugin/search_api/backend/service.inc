<?php

/**
 * @file
 * Contains SearchApiAlgoliaService.
 */

/**
 * SearchApiAlgoliaService class.
 */
class SearchApiAlgoliaService extends SearchApiAbstractService {

  protected $algoliaIndex = NULL;


  /**
   * Get the Algolia index.
   */
  protected function getAlgoliaIndex() {
    return $this->algoliaIndex;
  }


  /**
   * Set the Algolia index.
   */
  protected function setAlgoliaIndex($index) {
    $this->algoliaIndex = $index;
  }


  /**
   * Connect to the Algolia service.
   *
   * Uses the application ID et API key provided in the UI.
   */
  protected function connect($index) {
    // @todo Make the absence of lib more visible (error message on the status
    // report page for instance, and during the install process).
    if (($library = libraries_load('algolia')) && !empty($library['loaded'])) {
      $algolia_client = new \AlgoliaSearch\Client($this->getApplicationID(), $this->getApiKey());
      $this->setAlgoliaIndex($algolia_client->initIndex($index->name));
    }
    else {
      throw new SearchApiException(t('Algolia library could not be found or did not load properly.'));
    }
  }


  /**
   * Get the ApplicationID (provided by Algolia).
   */
  protected function getApplicationId() {
    return $this->options['application_id'];
  }


  /**
   * Get the API key (provided by Algolia).
   */
  protected function getApiKey() {
    return $this->options['api_key'];
  }


  /**
   * Defines the configuration form displayed when creating a new server.
   */
  public function configurationForm(array $form, array &$form_state) {

    $form['application_id'] = array(
      '#type' => 'textfield',
      '#title' => t('Application ID'),
      '#size' => 60,
      '#maxlength' => 128,
      '#required' => TRUE,
      '#default_value' => $this->getApplicationId(),
    );

    $form['api_key'] = array(
      '#type' => 'textfield',
      '#title' => t('API key'),
      '#size' => 60,
      '#maxlength' => 128,
      '#required' => TRUE,
      '#default_value' => $this->getAPIKey(),
    );

    return $form;
  }


  /**
   * Implements viewSettings.
   */
  public function viewSettings() {
    // @todo Write service options (SearchApiAlgoliaService class).
    $output = '';

    return $output;
  }


  /**
   * Implements indexItems.
   *
   * Naming convention:
   * - items
   *   - item
   *     - property
   *       - type
   *       - entity_type
   *       - value : single value or array.
   */
  public function indexItems(SearchApiIndex $index, array $items) {
    $items_to_index = array();
    $items_indexed = array();

    // Retrieving the entity key ('nid' for nodes, 'uid' for users, etc...).
    $entity_key = entity_get_info($index->item_type)['entity keys']['id'];

    // Connect to the Algolia service.
    $this->connect($index);

    // Iterate over all the items ready for indexing.
    foreach ($items as $item) {
      $item_to_index = array();
      // And then iterate over all the properties of these items.
      foreach ($item as $key => $property) {
        // Do not index the property if its value is not set.
        if (isset($property['value'])) {
          // Do not process the entity id at that stage. Its value is indexed
          // separately as it requires a special property name (ObjectID) in
          // order to be indexed by Algolia.
          if ($key != $entity_key) {
            // If the property type is defined as a list and entity_type is set,
            // we are facing a multivalued entity (checking list<integeger> is
            // not enough as this could be used by a standard select, with no
            // reference).
            // @todo Handle multivalued fields which are not entity reference.
            if (preg_match('/^list<[^>]+>/', $property['type']) && isset($property['entity_type'])) {
              $entity_type = $property['entity_type'];
              foreach ($property['value'] as $property_list_value) {
                $wrapper = entity_metadata_wrapper($entity_type, entity_load_single($entity_type, $property_list_value));
                $item_to_index[$key][] = $wrapper->label();
              }
            }
            // The property only bears a single value.
            else {
              // If entity_type is defined, it means we are facing an entity
              // reference. We then use an entity wrapper to output it.
              if (isset($property['entity_type'])) {
                $entity_type = $property['entity_type'];
                $wrapper = entity_metadata_wrapper($entity_type, entity_load_single($entity_type, $property['value']));
                $property_value = $wrapper->label();
              }
              // Simple property value (no entity to dereference).
              else {
                if ($property['type'] == 'integer') {
                  // We need to transtype the content of integer fields (as they
                  // are stored as string) so that Algolia sees them as
                  // integers.
                  // @todo MAYBE: if more types need to be recognized, make
                  // something more scalable here.
                  $property_value = (int) $property['value'];
                }
                else {
                  $property_value = $property['value'];
                }
              }
              $item_to_index[$key] = $property_value;
            }
          }
          else {
            // Match the entity key to the objectID primary key required by
            // Algolia.
            $item_to_index['objectID'] = $item[$entity_key]['value'];
          }
        }
      }
      // Add the properly formatted items to the batch.
      $items_to_index[] = $item_to_index;
    }

    try {
      // Once a batch is ready, send them over to Algolia.
      $items_indexed = $this->getAlgoliaIndex()->saveObjects($items_to_index);
    }
    catch (Exception $e) {
      throw new SearchApiException($e->getMessage());
    }

    // Returns an array of properly indexed objectIDs for Search API's
    // statistics. Depending on the type of entity being indexed, this can be
    // an array of 'nids', 'uids', 'tids', etc...
    return $items_indexed['objectIDs'];
  }


  /**
   * Implements deleteItems.
   */
  public function deleteItems($ids = 'all', SearchApiIndex $index = NULL) {
    // Connect to the Algolia service.
    $this->connect($index);

    if ($ids == 'all') {
      // Action triggered from the index admin page, when "Clear all indexed
      // data" button is clicked.
      $this->getAlgoliaIndex()->clearIndex();
    }
    else {
      // Action triggered when individual nodes are deleted. When deleting
      // multiple nodes from the admin content page, DeleteItems is being called
      // as many times as the number of nodes being deleted, making the foreach
      // irrelevant. There might be other contexts in which calls to deleteItems
      // are bundled though.
      foreach ($ids as $id) {
        $this->getAlgoliaIndex()->deleteObject($id);
      }
    }
  }


  /**
   * Implements search.
   */
  public function search(SearchApiQueryInterface $query) {
    // Set 'result count' at 0 by default.
    $result = array('result count' => 0);
    return $result;
  }

}
