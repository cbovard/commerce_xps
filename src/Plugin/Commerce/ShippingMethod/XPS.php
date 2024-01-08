<?php

namespace Drupal\commerce_xps\Plugin\Commerce\ShippingMethod;

use Drupal\commerce_price\Price;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\Entity\ShippingMethodInterface;
use Drupal\commerce_store\Resolver\StoreCountryResolver;
use Drupal\commerce_shipping\PackageTypeManagerInterface;
use Drupal\commerce_shipping\Plugin\Commerce\ShippingMethod\ShippingMethodBase;
use Drupal\commerce_shipping\ShippingRate;
use Drupal\commerce_shipping\ShippingService;
use Drupal\state_machine\WorkflowManagerInterface;
use Drupal\Core\Form\FormStateInterface;
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

  private $currentCountry;

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
   * Constructs an XPS Shipping Rates object.
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
    parent::__construct($configuration, $plugin_id, $plugin_definition, $package_type_manager, $workflow_manager);

    // Adding the Price Rounder.
    $this->rounder = \Drupal::service('commerce_price.rounder');

    // Get Current Store Country.
    $this->currentCountry = \Drupal::service('commerce.current_country');

    // Get the XPS Services.
    $this->getServices();

    // kint("faak");
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
   * Get all the XPS Services and add the to the Shipping Methods.
   */
  public function getServices() {
    // // XPS https://xpsshipper.com/restapi/docs/v1-ecommerce/endpoints/list-services/
    // $customer_id = $this->configuration['api_information']['customer_id'];
    // $auth_api_key = 'RSIS ' . $this->configuration['api_information']['api_key'];

    // // Initialize the Guzzle HTTP Client.
    // $client = \Drupal::httpClient();
    // // XPS Services Endpoint
    // $uri = 'https://xpsshipper.com/restapi/v1/customers/'. $customer_id . '/services';

    // try {
    //   // Set various headers on a request
    //   $response = $client->get($uri, [
    //     'headers' => [
    //       'Authorization' => $auth_api_key
    //     ]
    //   ]);
    //   $stream = $response->getBody();
    //   $json_data = Json::decode($stream);
    // }
    // // Guzzle Exceptions - /vendor/guzzlehttp/guzzle/src/Exception
    // catch (ClientException $e) {
    //   \Drupal::messenger()->addError($e->getMessage());
    // }
    // catch (\Exception $e) {
    //   \Drupal::messenger()->addError($e->getMessage());
    // }

    // // Need the store country to see if the service is available.
    // // For testing can swap out with a country code uppercase. ex: US, CA.
    // $store_country_code = $this->currentCountry->getCountry()->getCountryCode();

    // // Loop through XPS Services
    // foreach ($json_data['services'] as $service) {
    //   // Get Service Label and code.
    //   $service_label = $this->cleanServiceLabel($service['serviceLabel']);
    //   $service_code = $service['serviceCode'];

    //   // Need to check if inbound is false as we are only shipping out.
    //   if($service['inbound'] == false) {
    //     // Null will mean all countries are supported as per api docs.
    //     // Supported countries are null and the country code is not in the unsupported countries.
    //     if ($service['supportedCountries'] === null && ($service['unsupportedCountries'] !== null && !in_array($store_country_code, $service['unsupportedCountries']))) {
    //       // Need to add Shipping service for this logic. US is not included as an example.
    //       $this->services[$service_code] = new ShippingService($service_code, $this->t($service_label));
    //     } elseif ($service['supportedCountries'] !== null && in_array($store_country_code, $service['supportedCountries'])) {
    //       // Supported countries is not null and the country code is in supported countries.
    //       $this->services[$service_code] = new ShippingService($service_code, $this->t($service_label));
    //     } elseif ($service['unsupportedCountries'] !== null && in_array($store_country_code, $service['unsupportedCountries'])) {
    //       // When the country is in the unsupported list continue.
    //       continue;
    //     } else {
    //       // Add all shipping services as this is a fallback.
    //       $this->services[$service_code] = new ShippingService($service_code, $this->t($service_label));
    //     }
    //   }
    // }

    // Custom Shipping Services here - TODO dynamic
    $this->services['usps_priority'] = new ShippingService('usps_priority', $this->t('USPS Priority (1-3 Days)'));
    $this->services['usps_express'] = new ShippingService('usps_express', $this->t('USPS Priority Mail Express'));
    $this->services['usps_first_class'] = new ShippingService('usps_first_class', $this->t('USPS First Class'));
    $this->services['usps_ground_advantage'] = new ShippingService('usps_ground_advantage', $this->t('USPS Ground Advantage'));
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

    return $this->getRates($shipment, $this->parentEntity);
  }

  /**
   * {@inheritdoc}
   */
  public function getRates(ShipmentInterface $commerce_shipment, ShippingMethodInterface $shipping_method) {

    // Set the necessary info needed for the request.
    $this->setShipment($commerce_shipment);
    $this->setShippingMethod($shipping_method);

    return $this->getShippingRates();
  }

  /**
   * Get the XPS Shipping Rates
   */
  public function getShippingRates() {
    // Get the all the XPS rate shipping quotes.
    $xpsRateQuotes = $this->getRateQuotes($this->configuration['services']);

    $rates = [];
    // Loop through the quotes and build the Services with the rates.
    foreach ($xpsRateQuotes['quotes'] as $quote) {
      // Only add the rate if this service is enabled.
      if (!in_array($quote['serviceCode'], $this->configuration['services'])) {
        continue;
      }

      $rates[] = new ShippingRate([
        'shipping_method_id' => $this->parentEntity->id(),
        'service' => new ShippingService($quote['serviceCode'], $quote['serviceDescription']),
        'amount' => new Price($quote['totalAmount'], $quote['currency']),
      ]);
    }

    return $rates;
  }

  /**
   * Get all the XPS Shipping quotes based on the services checked in the config.
   */
  public function getRateQuotes(array $services) {

    // Store Address
    $store_country_code = $this->commerceShipment->getOrder()->getStore()->getAddress()->getCountryCode();
    $store_postal_code = $this->commerceShipment->getOrder()->getStore()->getAddress()->getPostalCode();

    // Receiver Address
    /** @var \CommerceGuys\Addressing\Address $address */
    $address = $this->commerceShipment->getShippingProfile()->get('address')->first();
    $receiver_city = $address->getLocality();
    $receiver_country = $address->getCountryCode();
    $receiver_zipcode = $address->getPostalCode();

    // Weight / Dimension Unit
    $weight_unit = $this->commerceShipment->getWeight()->getUnit();
    $dim_unit = $this->getDimensionUnit($weight_unit);
    $weight_value = $this->commerceShipment->getWeight()->getNumber();

    // Currency / Amount
    $store_currency_code = $this->commerceShipment->getOrder()->getStore()->getDefaultCurrencyCode();
    $amount = $this->commerceShipment->getOrder()->getTotalPrice()->getNumber();
    $amount = new Price($amount, $store_currency_code);
    $amount = $this->rounder->round($amount);

    // Need to get the Carrier Code from the begining of the Service code.
    // This will be used when there is Fedex, DHL, UPS, USPS Service codes.
    $carrier_codes = [];

    foreach ($services as $service_code) {
      $parts = explode('_', $service_code);
      $firstPart = $parts[0];

      // Add the first part to the service code if it doesn't exist already
      if (!in_array($firstPart, $carrier_codes)) {
        $carrier_codes[] = $firstPart;
      }
    }

    // TODO This will need to be put into a loop and sent to the API request using the above.
    // Create an array representing the JSON for XPS Rate Quotes.
    $data = array(
        "carrierCode" => "usps",
        "serviceCode" => "",
        "packageTypeCode" => "",
        "sender" => array(
            "country" => $store_country_code,
            "zip" => $store_postal_code
        ),
        "receiver" => array(
            "city" => $receiver_city,
            "country" => $receiver_country,
            "zip" => $receiver_zipcode
        ),
        "residential" => true,
        "signatureOptionCode" => "DIRECT",
        "weightUnit" => $weight_unit,
        "dimUnit" => $dim_unit,
        "currency" => $store_currency_code,
        "customsCurrency" => $store_currency_code,
        "pieces" => array(
            array(
                "weight" => $weight_value,
                "length" => null,
                "width" => null,
                "height" => null,
                "insuranceAmount" => $amount->getNumber(),
                "declaredValue" => null
            )
        ),
        "billing" => array(
            "party" => "sender"
        )
    );

    // Encode the Rate Quote array into a JSON string
    $jsonData = json_encode($data);

    // Debug log the json request.
    // $this->logRequest($jsonData);

    // XPS API
    $customer_id = $this->configuration['api_information']['customer_id'];
    $auth_api_key = 'RSIS ' . $this->configuration['api_information']['api_key'];

    // Initialize the Guzzle HTTP Client.
    $client = \Drupal::httpClient();

    // XPS Rate Quote Endpoint
    $uri = 'https://xpsshipper.com/restapi/v1/customers/'. $customer_id . '/quote';

    try {
      // Set various headers on a request
      $response = $client->post($uri, [
        'headers' => [
          'Authorization' => $auth_api_key,
          'Content-Type'     => 'application/json',
        ],
        'body' => $jsonData
      ]);
      $stream = $response->getBody();
      $json_response = Json::decode($stream);

      // Debug log the json response.
      //$this->logResponse($json_response);
    }
    // Guzzle Exceptions - /vendor/guzzlehttp/guzzle/src/Exception
    catch (ClientException $e) {
      \Drupal::logger('commerce_xps')->error($e->getMessage());
    }
    catch (\Exception $e) {
      \Drupal::logger('commerce_xps')->error($e->getMessage());
    }

    return $json_response;
  }

  /**
   * Get the measurement unit based on the weight unit type.
   */
  public function getDimensionUnit(string $weightUnit) {
    $imperialUnits = array("oz", "lb");
    $metricUnits = array("mg", "g", "kg");

    // Check if the weight unit is imperial or metric and return the corresponding dimension unit
    if (in_array($weightUnit, $imperialUnits)) {
        return "in"; // Inches for imperial units
    } elseif (in_array($weightUnit, $metricUnits)) {
        return "cm"; // Centimeters for metric units
    } else {
      // Handle unsupported or unknown units
      return null;
    }
  }

  /**
   * Utility function to clean the XPS Service Label.
   *
   * @param string $service
   *   The service id.
   *
   * @return string
   *   The cleaned up service id.
   */
  public function cleanServiceLabel($service_label) {
    // Remove the html encoded trademarks markup.
    $service_label = str_replace('&lt;sup&gt;&#8482;&lt;/sup&gt;', '', $service_label);
    $service_label = str_replace('&lt;sup&gt;&#174;&lt;/sup&gt;', '', $service_label);
    return $service_label;
  }

  /**
   * Logs the Request Data to Watchdog.
   */
  protected function logRequest($request) {
    if (!empty($this->configuration['options']['log']['request'])) {
      \Drupal::logger('Commerce XPS')->info('@message', ['@message' => print_r($request, TRUE)]);
    }
  }

  /**
   * Logs the Response Data to Watchdog.
   */
  protected function logResponse($response) {
    if (!empty($this->configuration['options']['log']['response'])) {
      \Drupal::logger('Commerce XPS')->info('@message', ['@message' => print_r($response, TRUE)]);
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
    // if (empty($this->configuration['services'])) {
    $service_ids = array_keys($this->services);
    //   $this->configuration['services'] = array_combine($service_ids, $service_ids);
    // }

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

  /**
   * Is the API information present.
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
