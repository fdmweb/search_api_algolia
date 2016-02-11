<?php

/**
 * @file
 * Contains \Drupal\search_api_algolia\Plugin\search_api\backend\SearchApiAlgoliaBackend.
 */

namespace Drupal\search_api_algolia\Plugin\search_api\backend;

#use Drupal\Core\Config\Config;
#use Drupal\Core\Extension\ModuleHandlerInterface;
use AlgoliaSearch\Index;
use Drupal\Core\Form\FormStateInterface;
#use Drupal\Core\Language\LanguageManagerInterface;
#use Drupal\Core\Url;
#use Drupal\search_api\Item\FieldInterface;
#use Drupal\search_api\Query\ConditionInterface;
#use Drupal\search_api\SearchApiException;
use Drupal\search_api\IndexInterface;
#use Drupal\search_api\Query\ConditionGroupInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Backend\BackendPluginBase;
#use Drupal\search_api\Query\ResultSetInterface;
use Drupal\search_api\Utility;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @SearchApiBackend(
 *   id = "search_api_algolia",
 *   label = @Translation("Algolia"),
 *   description = @Translation("Index items using a Algolia Search.")
 * )
 */
class SearchApiAlgoliaBackend extends BackendPluginBase {

  protected $algoliaIndex = NULL;

  /**
   * A connection to the Algolia server.
   *
   * @var \AlgoliaSearch\Client
   */
  protected $algoliaClient;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return array(
      'application_id' => '',
      'api_key' => '',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['help'] = array(
      '#markup' => '<p>' . $this->t('The application ID and API key an be found and configured at <a href="@link" target="blank">@link</a>.', array('@link' => 'https://www.algolia.com/licensing')) . '</p>',
    );
    $form['application_id'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Application ID'),
      '#description' => $this->t('The application ID from your Algolia subscription.'),
      '#default_value' => $this->getApplicationId(),
      '#required' => TRUE,
      '#size' => 60,
      '#maxlength' => 128,
    );
    $form['api_key'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#description' => $this->t('The API key from your Algolia subscription.'),
      '#default_value' => $this->getApiKey(),
      '#required' => TRUE,
      '#size' => 60,
      '#maxlength' => 128,
    );
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function viewSettings() {
    $this->connect();
    $info = array();

    // Application ID
    $info[] = array(
      'label' => $this->t('Application ID'),
      'info' => $this->getApplicationId(),
    );

    // API Key
    $info[] = array(
      'label' => $this->t('API Key'),
      'info' => $this->getApiKey(),
    );

    // Available indexes
    $indexes = $this->getAlgolia()->listIndexes();
    $indexes_list = array();
    if (isset($indexes['items'])) {
      foreach ($indexes['items'] as $index) {
        $indexes_list[] = $index['name'];
      }
    }
    $info[] = array(
      'label' => $this->t('Available Algolia indexes'),
      'info' => implode(', ', $indexes_list),
    );

    return $info;
  }

  /**
   * {@inheritdoc}
   */
  public function removeIndex($index) {
    // Only delete the index's data if the index isn't read-only.
    if (!is_object($index) || empty($index->get('read_only'))) {
      $this->deleteAllIndexItems($index);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function indexItems(IndexInterface $index, array $items) {
    // Connect to the Algolia service.
    $this->connect($index);

    // Note the max size of a record for Algolia is 10KB.
    $algolia_data = array();

    /*
    foreach ($items as $id => $item) {
      $algolia_data[$id] = array('objectID' => $id);

      foreach ($item as $name => $field) {

        $this->addIndexField($doc, $field_names[$name], $field_names_single_value[$name], $field->getValues(), $field->getType());
      }

      if ($doc) {
        $documents[] = $doc;
        $ret[] = $id;
      }
    }
*/

    foreach ($items as $id => $item) {
      $algolia_data[$id] = array('objectID' => $id);

      foreach ($item as $key => $field) {
        $algolia_data[$id][$key] = $field->getValues();
      }
    }

    try {
      $this->getAlgoliaIndex()->addObjects(array_values($algolia_data));
      //$algolia_index->addObjects(array_values($algolia_data));
    }
    catch (AlgoliaException $e) {
      //throw new SearchApiException($e->getMessage());
    }

  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(IndexInterface $index, array $ids) {
    // Connect to the Algolia service.
    $this->connect($index);

    // Deleting all items included in the $ids array.
    foreach ($ids as $id) {
      $this->getAlgoliaIndex()->deleteObject($id);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllIndexItems(IndexInterface $index = NULL) {
    if ($index) {
      // Connect to the Algolia service.
      $this->connect($index);

      // Clcearing the full index.
      $this->getAlgoliaIndex()->clearIndex();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function search(QueryInterface $query) {
    // This plugin does not support searching and we therefore just return an empty search result.
    $results = Utility::createSearchResultSet($query);
    $results->setResultItems(array());
    $results->setResultCount(0);
    return $results;
  }

  /**
   * Creates a connection to the Algolia Search server as configured in $this->configuration.
   */
  protected function connect($index = NULL) {
    if (!$this->getAlgolia()) {
      $this->algoliaClient = new \AlgoliaSearch\Client($this->getApplicationId(), $this->getApiKey());

      if ($index && $index instanceof IndexInterface) {
        $this->setAlgoliaIndex($this->algoliaClient->initIndex($index->get('name')));
      }
    }
  }

  /**
   * Returns the AlgoliaSearch client.
   *
   * @return \AlgoliaSearch\Client
   *   The algolia instance object.
   */
  public function getAlgolia() {
    return $this->algoliaClient;
  }

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
   * Get the ApplicationID (provided by Algolia).
   */
  protected function getApplicationId() {
    return $this->configuration['application_id'];
  }

  /**
   * Get the API key (provided by Algolia).
   */
  protected function getApiKey() {
    return $this->configuration['api_key'];
  }

}