<?php

namespace Drupal\commerce_xps\Plugin\Commerce\ShippingMethod;

use Drupal\commerce_price\Price;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\Entity\ShippingMethodInterface;
use Drupal\commerce_shipping\PackageTypeManagerInterface;
use Drupal\commerce_shipping\Plugin\Commerce\ShippingMethod\ShippingMethodBase;
use Drupal\commerce_shipping\ShippingRate;
use Drupal\commerce_shipping\ShippingService;
use Drupal\physical\Weight;
use Drupal\physical\WeightUnit;
use Drupal\state_machine\WorkflowManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;
use Drupal\Component\Serialization\Json;

/**
 * Provides the XPS shipping method.
 *
 * @CommerceShippingMethod(
 *  id = "xps",
 *  label = @Translation("XPS Shipping"),
 * )
 */
class XPS extends ShippingMethodBase {

  private $rounder;

  protected $xpsRequest;

  /**
   * The commerce shipment entity.
   *
   * @var \Drupal\commerce_shipping\Entity\ShipmentInterface
   */
  protected ShipmentInterface $commerceShipment;

  /**
   * The shipping method being rated.
   *
   * @var \Drupal\commerce_shipping\Entity\ShippingMethodInterface
   */
  protected ShippingMethodInterface $shippingMethod;

  /**
   * Constructs a new FlatRate object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\commerce_shipping\PackageTypeManagerInterface $package_type_manager
   *   The package type manager.
   * @param \Drupal\state_machine\WorkflowManagerInterface $workflow_manager
   *   The workflow manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, PackageTypeManagerInterface $package_type_manager, WorkflowManagerInterface $workflow_manager) {

    // TODO - SEE below
    // $plugin_definition = $this->preparePluginDefinition($plugin_definition);

    parent::__construct($configuration, $plugin_id, $plugin_definition, $package_type_manager, $workflow_manager);

    // Custom Shipping Services here - TODO dynamic
    // $this->services['default'] = new ShippingService('default', $this->t('Home delivery'));
    $this->services['usps_priority'] = new ShippingService('usps_priority', $this->t('USPS Priority (1-3 Days)'));
    $this->services['usps_express'] = new ShippingService('usps_express', $this->t('USPS Priority Mail Express'));
    $this->services['usps_first_class'] = new ShippingService('usps_first_class', $this->t('USPS First Class'));
    $this->services['usps_ground_advantage'] = new ShippingService('usps_ground_advantage', $this->t('USPS Ground Advantage'));

    // Adding the Price Rounder - TODO needed?
    $this->rounder = \Drupal::service('commerce_price.rounder');
  }

  /**
   * {@inheritdoc}
   */
  public function calculateRates(ShipmentInterface $shipment) {

    // Only attempt to collect rates if an address exists on the shipment.
    if ($shipment->getShippingProfile()->get('address')->isEmpty()) {
      return [];
    }

    // Only attempt to collect rates for US addresses.
    if ($shipment->getShippingProfile()->get('address')->country_code != 'US') {
      return [];
    }

    // $shipping_amount = 72;

    // $amount = new Price((string) $shipping_amount, $shipment->getOrder()->getTotalPrice()->getCurrencyCode());
    // $amount = $this->rounder->round($amount);

    // $rates = [];

    // // Need to loop through the Shipping Services to add to the rates
    // foreach ($this->services as $service) {
    //   //Only add the rate if this service is enabled.
    //   if (!in_array($service->getId(), $this->configuration['services'])) {
    //     continue;
    //   }

    //   $rates[] = new ShippingRate([
    //     'shipping_method_id' => $this->parentEntity->id(),
    //     'service' => new ShippingService($service->getId(), $service->getLabel()),
    //     'amount' => $amount,
    //   ]);
    // }

    // return $rates;

    // TODO - NEEDED? Make sure a package type is set on the shipment.
    //$this->setPackageType($shipment);

    return $this->getRates($shipment, $this->parentEntity);

  }

  /**
   * {@inheritdoc}
   */
  public function getRates(ShipmentInterface $commerce_shipment, ShippingMethodInterface $shipping_method) {


    // Set the necessary info needed for the request.
    $this->setShipment($commerce_shipment);
    $this->setShippingMethod($shipping_method);


    //kint($this->configuration['api_information']['customer_id']);
    //kint($this->configuration['api_information']['api_key']);

    // XPS API
    $customer_id = $this->configuration['api_information']['customer_id'];
    $auth_api_key = 'RSIS ' . $this->configuration['api_information']['api_key'];

    // Initialize the Guzzle HTTP Client.
    $client = \Drupal::httpClient();
    // XPS Services Endpoint
    $uri = 'https://xpsshipper.com/restapi/v1/customers/'. $customer_id . '/services';

    // kint($uri);
    // kint($customer_id);
    // kint($auth_api_key);

    try {
      // Set various headers on a request
      $response = $client->get($uri, [
        'headers' => [
          'Authorization' => $auth_api_key
        ]
      ]);
      $stream = $response->getBody();
      $json_data = Json::decode($stream);

      // kint($json_data['services']);
    }
    // Guzzle Exceptions - /vendor/guzzlehttp/guzzle/src/Exception
    catch (ClientException $e) {
      \Drupal::messenger()->addError($e->getMessage());
      // TODO - need Watchdog loger here
      //watchdog_exception('Commerce XPS API', $e);
    }
    catch (\Exception $e) {
      \Drupal::messenger()->addError($e->getMessage());
      // TODO - need Watchdog loger here
      //watchdog_exception('Commerce XPS API', $e);
    }

    // Allow others to alter the rate.
   //$this->alterRate();

    // Fetch the rates.
    $this->logRequest();
    //$this->uspsRequest->getRate(10);
    $this->logResponse();


    //$response = [];
    //$response = $this->uspsRequest->getArrayResponse();
    //return $this->resolveRates($response);

    return $this->buildRate();
  }

  /**
   * Build the XPS Rate Request.
   */
  public function buildRate() {
    // $this->xpsRequest = new Rate(
    //   $this->configuration['api_information']['user_id']
    // );
    //$this->setMode();

    // kint($this->configuration['api_information']);

    //$this->xpsRequest;

    $shipping_amount = 64.50;
    $amount = new Price($shipping_amount, 'USD');

    $rates = [];

    // Need to loop through the Shipping Services to add to the rates
    foreach ($this->services as $service) {
      //Only add the rate if this service is enabled.
      if (!in_array($service->getId(), $this->configuration['services'])) {
        continue;
      }

      $rates[] = new ShippingRate([
        'shipping_method_id' => $this->parentEntity->id(),
        'service' => new ShippingService($service->getId(), $service->getLabel()),
        'amount' => $amount,
      ]);
    }

    return $rates;
  }


  //-----


  /**
   * {@inheritdoc}
   */
  public function resolveRates(array $response) {

    //$shipping_amount = 69;

    //$amount = new Price((string) $shipping_amount, $shipment->getOrder()->getTotalPrice()->getCurrencyCode());
    $amount = 72;

    // $amount = 0;

    $rates = [];

    // Need to loop through the Shipping Services to add to the rates
    foreach ($this->services as $service) {
      //Only add the rate if this service is enabled.
      if (!in_array($service->getId(), $this->configuration['services'])) {
        continue;
      }

      $rates[] = new ShippingRate([
        'shipping_method_id' => $this->parentEntity->id(),
        'service' => new ShippingService($service->getId(), $service->getLabel()),
        'amount' => $amount,
      ]);

    }

    // TODO - Reference
    // old code
    // $rates[] = new ShippingRate([
    //   'shipping_method_id' => $this->parentEntity->id(),
    //   'service' => $this->services['default'],
    //   'amount' => $amount,
    // ]);
    //----

    // Only add the rate if this service is enabled.
    // if (!in_array($service_code, $this->configuration['services'])) {
    //   continue;
    // }

    // $rates[] = new ShippingRate([
    //   'shipping_method_id' => $this->parentEntity->id(),
    //   'service' => new ShippingService($service_code, $service_name),
    //   'amount' => new Price($price, 'USD'),
    // ]);
    // --- leave for reference

    return $rates;

  }

  /**
   * Set the commerce shipment.
   *
   * @param \Drupal\commerce_shipping\Entity\ShipmentInterface $commerce_shipment
   *   The commerce shipment entity.
   */
  public function setShipment(ShipmentInterface $commerce_shipment) {
    $this->commerceShipment = $commerce_shipment;
  }

  /**
   * Set the shipping method being rated.
   *
   * @param \Drupal\commerce_shipping\Entity\ShippingMethodInterface $shipping_method
   *   The shipping method.
   */
  public function setShippingMethod(ShippingMethodInterface $shipping_method) {
    $this->shippingMethod = $shipping_method;
  }

  /**
   * Logs the Request Data to Watchdog.
   */
  protected function logRequest() {
    if (!empty($this->configuration['options']['log']['request'])) {
      \Drupal::logger('Commerce XPS')->info('Request Log');
      // $request = $this->uspsRequest->getPostData();
      // $this->logger->info('@message', ['@message' => print_r($request, TRUE)]);
    }
  }

  /**
   * Logs the Response Data to Watchdog.
   */
  protected function logResponse() {
    if (!empty($this->configuration['options']['log']['response'])) {
      \Drupal::logger('Commerce XPS')->info('Response Log');
      // $this->logger->info('@message', ['@message' => print_r($this->uspsRequest->getResponse(), TRUE)]);
    }
  }



  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'api_information' => [
        'api_key' => '',
        'customer_id' => '',
      ],
      'options' => [
        'log' => [],
      ],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // Select all services by default.
    if (empty($this->configuration['services'])) {
      $service_ids = array_keys($this->services);
      $this->configuration['services'] = array_combine($service_ids, $service_ids);
    }

    $description = $this->t('Update your XPS Shipping API information.');
    if (!$this->isConfigured()) {
      $description = $this->t('Fill in your XPS Shipping API information.');
    }
    $form['api_information'] = [
      '#type' => 'details',
      '#title' => $this->t('API information'),
      '#description' => $description,
      '#weight' => $this->isConfigured() ? 10 : -10,
      '#open' => !$this->isConfigured(),
    ];

    $form['api_information']['api_key'] = [
      '#type' => 'textfield',
      '#title' => t('API Key'),
      '#default_value' => $this->configuration['api_information']['api_key'],
      '#required' => TRUE,
    ];

    $form['api_information']['customer_id'] = [
      '#type' => 'textfield',
      '#title' => t('Customer ID'),
      '#default_value' => $this->configuration['api_information']['customer_id'],
      '#required' => TRUE,
    ];

    $form['options'] = [
      '#type' => 'details',
      '#title' => $this->t('XPS Options'),
      '#description' => $this->t('Additional options for XPS'),
    ];

    $form['options']['log'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Log the following messages for debugging'),
      '#options' => [
        'request' => $this->t('API request messages'),
        'response' => $this->t('API response messages'),
      ],
      '#default_value' => $this->configuration['options']['log'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);

      $this->configuration['api_information']['customer_id'] = $values['api_information']['customer_id'];
      $this->configuration['api_information']['api_key'] = $values['api_information']['api_key'];
      $this->configuration['options']['log'] = $values['options']['log'];
    }
    parent::submitConfigurationForm($form, $form_state);
  }

  // TODO - this is used with the Annotations
  // /**
  //  * Prepares the service array keys to support integer values.
  //  *
  //  * @param array $plugin_definition
  //  *   The plugin definition provided to the class.
  //  *
  //  * @return array
  //  *   The prepared plugin definition.
  //  */
  // private function preparePluginDefinition(array $plugin_definition) {
  //   // Cache and unset the parsed plugin definitions for services.
  //   $services = $plugin_definition['services'];
  //   unset($plugin_definition['services']);

  //   // TODO: Remove once core issue has been addressed.
  //   // See: https://www.drupal.org/node/2904467 for more information.
  //   foreach ($services as $key => $service) {
  //     // Remove the "_" from the service key.
  //     $key_trimmed = str_replace('_', '', $key);
  //     $plugin_definition['services'][$key_trimmed] = $service;
  //   }

  //   // Sort the options alphabetically.
  //   uasort($plugin_definition['services'], function (TranslatableMarkup $a, TranslatableMarkup $b) {
  //     return $a->getUntranslatedString() < $b->getUntranslatedString() ? -1 : 1;
  //   });

  //   return $plugin_definition;
  // }

  /**
   * Determine if we have the minimum information to connect to USPS.
   *
   * @return bool
   *   TRUE if there is enough information to connect, FALSE otherwise.
   */
  protected function isConfigured() {
    $api_config = $this->configuration['api_information'];

    if (empty($api_config['customer_id']) || empty($api_config['api_key'])) {
      return FALSE;
    }

    return TRUE;
  }



}
