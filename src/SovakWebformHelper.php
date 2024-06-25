<?php

namespace Drupal\sovak_webform;

use Drupal\Core\Database\Connection;
use Drupal\node\Entity\Node;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Class SovakWebformHelper.
 *
 */
class SovakWebformHelper {

  // Allows use of the t() function for string translations
  use StringTranslationTrait;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   *
   * @param Connection $connection
   *   The database connection, injected as a dependency.
   */
  public function __construct(Connection $connection) {
    $this->connection = $connection;
  }

  /**
   * Retrieves all published publications from the database.
   *
   * @return array
   *   An array of publication node IDs.
   */
  public function getPublications() {
    // Query to select all published nodes of type 'portfolio'
    $query = $this->connection->select('node', 'n');
    $query->fields('n', ['nid']);
    $query->leftJoin('node_field_data', 'b', 'n.nid = b.nid');
    $query->condition('n.type', 'portfolio', '=');
    $query->condition('b.status', 1, '=');
    $query = $query->execute();
    $results = $query->fetchAll();

    return $results;
  }

  /**
   * Retrieves all webform submissions for a specific node and webform.
   *
   * @param string $webform_id
   *   The ID of the webform.
   * @param int $nid
   *   The node ID.
   *
   * @return array
   *   An array of submission IDs.
   */
  public function getWebformSubmissionsForId($webform_id, $nid) {
    // Query to select all submissions for a specific webform and node
    $query = $this->connection->select('webform_submission', 'n');
    $query->fields('n', ['sid']);
    $query->condition('n.webform_id', $webform_id, '=');
    $query->condition('n.entity_type', 'node', '=');
    $query->condition('n.entity_id', $nid, '=');

    $query = $query->execute();
    $results = $query->fetchAll();

    return $results;
  }

  /**
   * Creates a formatted table of orders from unserialized data.
   *
   * @param array $unserialized_data
   *   The unserialized order data.
   *
   * @return array
   *   A renderable array representing the order table.
   */
  public function createOrdersTable($unserialized_data) {
    $data = [];
    $total_amount = 0;
    $header = [
      $this->t('Name'),
      $this->t('Price'),
      $this->t('amount'),
      $this->t('total'),
    ];
    
    // Process each item in the order
    foreach ($unserialized_data as $d) {
      if ($d['price'] != 0) {
        $publication = Node::load($d['id']);
        $total = ($d['price'] * $d['quantity']);
        $total_amount += $total;
        $data[] = [
          $publication->getTitle(),
          $d['price'] . t('CZK'),
          $d['quantity'],
          $total . t('CZK (including VAT)'),
        ];
      }
    }

    // Create the main table
    $output[] = [
      '#theme' => 'table',
      '#cache' => ['disabled' => TRUE],
      '#header' => $header,
      '#rows' => $data,
    ];
    
    // Create the total amount table
    $output[] = [
      '#theme' => 'table',
      '#header' => [$this->t('Total amount')],
      '#rows' => [[$total_amount . ' ' . t('CZK (including VAT)')]],
    ];
    return $output;
  }

  /**
   * Processes and serializes order values from form submission.
   *
   * @param array $values
   *   The form values.
   * @param array $form
   *   The form array (passed by reference).
   *
   * @return array
   *   The processed and serialized order data.
   */
  public function serializeOrderValues($values, &$form) {
    $to_save = []; 

    foreach ($values as $machine_name => $value) {
      if (strpos($machine_name, 'amount_quantity') !== FALSE && $value != 0) {
        $nid = (int) filter_var($machine_name, FILTER_SANITIZE_NUMBER_INT);
        $publication = Node::load($nid);
        if ($publication) {
          $price = $publication->get('field_cena')->getString();

          // Prepare form elements for display
          $form['elements'][$nid] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Publications'),
            '#weight' => -1,
          ];

          $form['elements'][$nid]['amount_publication_' . $nid] = [
            '#type' => 'item',
            '#title' => $this->t('Name'),
            '#markup' => $publication->getTitle(),
          ];

          $form['elements'][$nid]['amount_price_' . $nid] = [
            '#type' => 'item',
            '#title' => $this->t('Price'),
            '#markup' => $price . ' ' . $this->t('CZK'),
          ];

          $form['elements'][$nid]['amount_quantity_' . $nid] = [
            '#type' => 'item',
            '#title' => $this->t('Quantity'),
            '#markup' => $value,
          ];

          // Add this item to the array to be saved
          $to_save[] = [
            'id' => $nid,
            'title' => $publication->getTitle(),
            'price' => $price,
            // 'quantity' => $value, // This line is commented out in the original code
          ];
        }
      }
    }
    $formated_data_store = ['objednavky' => $this->formatOrderDetails($to_save)];

    return $formated_data_store;
  }

  /**
   * Formats order details into a human-readable string.
   *
   * @param array $orderData
   *   The order data to format.
   *
   * @return string
   *   A formatted string containing order details.
   */
  public function formatOrderDetails($orderData) {
    $formattedDetails = '';
    $totalOrderPrice = 0;

    if (is_array($orderData) && !empty($orderData)) {
      foreach ($orderData as $item) {
        $quantity = intval($item['quantity']);
        $price = floatval($item['price']);
        $finalItemPrice = $quantity * $price;
        $totalOrderPrice += $finalItemPrice;

        // Format details for 'koupit_vice_publikaci' webform
        if ($item['webform_id'] == 'koupit_vice_publikaci') {
          $formattedDetails .= "Název publikace: " . $item['title'] . "\n";
          $formattedDetails .= "Cena: " . $item['price'] . "\n";
          $formattedDetails .= "Počet kusů: " . $item['quantity'] . "\n";
          $formattedDetails .= "Celková cena za publikaci: " . $finalItemPrice . "\n";
        }
        $formattedDetails .= "-------\n";
      }
      // Append the total order price
      $formattedDetails .= "Výsledná cena objednávky: " . $totalOrderPrice . "\n";
    } else {
      $formattedDetails = "No order details available.\n";
    }
    return $formattedDetails;
  }
}
