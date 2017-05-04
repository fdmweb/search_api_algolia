<?php

namespace Drupal\search_api_algolia\Plugin\search_api\backend;

use AlgoliaSearch\Client;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Plugin\PluginFormTrait;
use Drupal\search_api\Query\QueryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @SearchApiBackend(
 *   id = "search_api_algolia",
 *   label = @Translation("Algolia"),
 *   description = @Translation("Index items using a Algolia Search.")
 * )
 */
class SearchApiAlgoliaBackend extends BackendPluginBase implements PluginFormInterface {

  use PluginFormTrait;

  protected $algoliaIndex = NULL;

  /**
   * A connection to the Algolia server.
   *
   * @var \AlgoliaSearch\Client
   */
  protected $algoliaClient;

  /**
   * The logger to use for logging messages.
   *
   * @var \Psr\Log\LoggerInterface|null
   */
  protected $logger;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $backend = parent::create($container, $configuration, $plugin_id, $plugin_definition);

    /** @var \Drupal\Core\Extension\ModuleHandlerInterface $module_handler */
    $module_handler = $container->get('module_handler');
    $backend->setModuleHandler($module_handler);

    /** @var \Psr\Log\LoggerInterface $logger */
    $logger = $container->get('logger.channel.search_api_algolia');
    $backend->setLogger($logger);

    return $backend;
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
    try {
      $this->connect();
    }
    catch (\Exception $e) {
      $this->getLogger()->warning('Could not connect to Algolia backend.');
    }
    $info = array();

    // Application ID.
    $info[] = array(
      'label' => $this->t('Application ID'),
      'info' => $this->getApplicationId(),
    );

    // API Key.
    $info[] = array(
      'label' => $this->t('API Key'),
      'info' => $this->getApiKey(),
    );

    // Available indexes.
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
    $this->connect($index);

    $objects = array();
    /** @var \Drupal\search_api\Item\ItemInterface[] $items */
    foreach ($items as $id => $item) {
      $objects[$id] = $this->prepareItem($index, $item);
    }

    // Let other modules alter objects before sending them to Algolia.
    \Drupal::moduleHandler()->alter('search_api_algolia_objects', $objects, $index, $items);
    $this->alterAlgoliaObjects($objects, $index, $items);

    if (count($objects) > 0) {
      try {
        $this->getAlgoliaIndex()->saveObjects($objects);
      }
      catch (AlgoliaException $e) {
        $this->getLogger()->warning(Html::escape($e->getMessage()));
      }
    }

    return array_keys($objects);
  }

  /**
   * Indexes a single item on the specified index.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The index for which the item is being indexed.
   * @param \Drupal\search_api\Item\ItemInterface $item
   *   The item to index.
   */
  protected function indexItem(IndexInterface $index, ItemInterface $item) {
    $this->indexItems([$item->getId() => $item]);
  }

  /**
   * Prepares a single item for indexing.
   *
   * Used as a helper method in indexItem()/indexItems().
   *
   * @param \Drupal\search_api\Item\ItemInterface $item
   *   The item to index.
   */
  protected function prepareItem(IndexInterface $index, ItemInterface $item) {
    $item_id = $item->getId();
    $item_to_index = ['objectID' => $item_id];

    /** @var \Drupal\search_api\Item\FieldInterface $field */
    $item_fields = $item->getFields();
    $item_fields += $this->getSpecialFields($index, $item);
    foreach ($item_fields as $field_id => $field) {
      $type = $field->getType();
      $values = NULL;
      $field_values = $field->getValues();
      if (empty($field_values)) {
        continue;
      }
      foreach ($field_values as $field_value) {
        if (!$field_value) {
          continue;
        }
        switch ($type) {
          case 'text':
          case 'string':
          case 'uri':
            $field_value .= '';
            if (Unicode::strlen($field_value) > 10000) {
              $field_value = Unicode::substr(trim($field_value), 0, 10000);
            }
            $values[] = $field_value;
            break;

          case 'integer':
          case 'duration':
          case 'decimal':
            $values[] = 0 + $field_value;
            break;

          case 'boolean':
            $values[] = $field_value ? TRUE : FALSE;
            break;

          case 'date':
            if (is_numeric($field_value) || !$field_value) {
              $values[] = 0 + $field_value;
              break;
            }
            $values[] = strtotime($field_value);
            break;

          default:
            $values[] = $field_value;
        }
      }
      if (is_array($values) && count($values) <= 1) {
        $values = reset($values);
      }
      $item_to_index[$field->getFieldIdentifier()] = $values;
    }

    return $item_to_index;
  }

  /**
   * Applies custom modifications to indexed Algolia objects.
   *
   * This method allows subclasses to easily apply custom changes before the
   * objects are sent to Algolia. The method is empty by default.
   *
   * @param $objects
   *   An array of objects ready to be indexed, generated from $items array.
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index for which items are being indexed.
   * @param array $items
   *   An array of items being indexed.
   *
   * @see hook_search_api_algolia_objects_alter()
   */
  protected function alterAlgoliaObjects(array &$objects, IndexInterface $index, array $items) {
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
  public function deleteAllIndexItems(IndexInterface $index = NULL, $datasource_id = NULL) {
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
    $results = $query->getResults();
    $results->setResultItems(array());
    $results->setResultCount(0);
    return $results;
  }

  /**
   * Creates a connection to the Algolia Search server as configured in $this->configuration.
   */
  protected function connect($index = NULL) {
    if (!$this->getAlgolia()) {
      $this->algoliaClient = new Client($this->getApplicationId(), $this->getApiKey());

      if ($index && $index instanceof IndexInterface) {
        $this->setAlgoliaIndex($this->algoliaClient->initIndex($index->get('id')));
      }
    }
  }

  /**
   * Retrieves the logger to use.
   *
   * @return \Psr\Log\LoggerInterface
   *   The logger to use.
   */
  public function getLogger() {
    return $this->logger ?: \Drupal::service('logger.channel.search_api_algolia');
  }

  /**
   * Sets the logger to use.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger to use.
   *
   * @return $this
   */
  public function setLogger(LoggerInterface $logger) {
    $this->logger = $logger;
    return $this;
  }

  /**
   * Returns the module handler to use for this plugin.
   *
   * @return \Drupal\Core\Extension\ModuleHandlerInterface
   *   The module handler.
   */
  public function getModuleHandler() {
    return $this->moduleHandler ?: \Drupal::moduleHandler();
  }

  /**
   * Sets the module handler to use for this plugin.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler to use for this plugin.
   *
   * @return $this
   */
  public function setModuleHandler(ModuleHandlerInterface $module_handler) {
    $this->moduleHandler = $module_handler;
    return $this;
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
