<?php

namespace Drupal\commerce_xps\Plugin\Commerce\ShippingMethod;

use Drupal\commerce_price\Price;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\Entity\ShippingMethodInterface;
use Drupal\commerce_shipping\PackageTypeManagerInterface;
use Drupal\commerce_shipping\Plugin\Commerce\ShippingMethod\ShippingMethodBase;
use Drupal\commerce_shipping\ShippingRate;
use Drupal\commerce_shipping\ShippingService;
use Drupal\state_machine\WorkflowManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides the XPS shipping method.
 *
 * @CommerceShippingMethod(
 *  id = "xps",
 *  label = @Translation("XPS Shipping"),
 *  services = {
 *    "dhl_express_worldwide" = @translation("DHL Intl Express"),
 *    "fedex_international_priority" = @translation("FedEx International Priority®"),
 *    "fedex_international_economy" = @translation("FedEx International Economy®"),
 *    "fedex_ground_canada" = @translation("FedEx International Ground®"),
 *    "ups_standard" = @translation("UPS® Standard"),
 *    "ups_worldwide_express" = @translation("UPS Worldwide Express®"),
 *    "ups_express_plus" = @translation("UPS Worldwide Express Plus®"),
 *    "ups_worldwide_expedited" = @translation("UPS Worldwide Expedited®"),
 *    "ups_worldwide_saver" = @translation("UPS Worldwide Saver®"),
 *    "ups_next_day_air" = @translation("UPS Next Day Air®"),
 *    "ups_second_day_air" = @translation("UPS 2nd Day Air®"),
 *    "ups_ground" = @translation("UPS® Ground"),
 *    "usps_international_first_class" = @translation("USPS International First Class"),
 *    "fedex_priority_overnight" = @translation("FedEx Priority Overnight®"),
 *    "fedex_first_overnight" = @translation("FedEx First Overnight®"),
 *    "fedex_standard_overnight" = @translation("FedEx Standard Overnight®"),
 *    "fedex_two_day" = @translation("FedEx 2Day®"),
 *    "fedex_express_saver" = @translation("FedEx Express Saver®"),
 *    "fedex_ground" = @translation("FedEx Ground®"),
 *    "fedex_ground_home_delivery" = @translation("FedEx Home Delivery®"),
 *    "ups_next_day_air_early_am" = @translation("UPS Next Day Air® Early"),
 *    "ups_next_day_air_saver" = @translation("UPS Next Day Air Saver®"),
 *    "ups_second_day_air_am" = @translation("UPS 2nd Day Air A.M.®"),
 *    "ups_three_day_select" = @translation("UPS 3 Day Select®"),
 *    "usps_international_priority" = @translation("USPS International Priority"),
 *    "usps_international_express" = @translation("USPS International Express"),
 *    "usps_priority" = @translation("USPS Priority (1-3 Days)"),
 *    "usps_express" = @translation("USPS Priority Mail Express"),
 *    "usps_first_class" = @translation("USPS First Class"),
 *    "usps_ground_advantage" = @translation("USPS Ground Advantage"),
 *  }
 * )
 */
class XPS extends ShippingMethodBase {

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
    $plugin_definition = $this->preparePluginDefinition($plugin_definition, $configuration);
    parent::__construct($configuration, $plugin_id, $plugin_definition, $package_type_manager, $workflow_manager);
  }

  /**
   * Prepares the service array keys to support integer values.
   *
   * @param array $plugin_definition
   *   The plugin definition provided to the class.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   *
   * @return array $plugin_definition
   *   The prepared plugin definition.
   */
  private function preparePluginDefinition($plugin_definition, $configuration) {
    // Sort the Services alpha.
    uasort($plugin_definition['services'], function (TranslatableMarkup $a, TranslatableMarkup $b) {
      return $a->getUntranslatedString() < $b->getUntranslatedString() ? -1 : 1;
    });

    // Check of the XPS API info has been added.
    if((isset($configuration['api_information']['api_key']) && !empty($configuration['api_information']['api_key']))
      && (isset($configuration['api_information']['customer_id']) && !empty($configuration['api_information']['customer_id']))) {
      // Get the Services JSON from the XPS API.
      $xps_services = $this->getXpsServices($configuration);
    }
    else {
      // Add the Drupal DB Log that the API info is missing.
      \Drupal::logger('Commerce XPS')->error('@message', [
        '@message' => 'Your XPS API key and/or Customer ID is missing!'
      ]);

      // Return if empty XPS API Login.
      return $plugin_definition;
    }

    // We will rebuild the Services below based on supported countries.
    $services = $plugin_definition['services'];
    unset($plugin_definition['services']);

    // We need to store country code to filter out the services supported in the country.
    $store_country_code = \Drupal::service('commerce.current_country')->getCountry()->getCountryCode();

    // Create an array using serviceCode as the key from the XPS Service API Array.
    $services2Lookup = [];
    foreach ($xps_services['services'] as $item) {
      $services2Lookup[$item['serviceCode']] = $item;
    }

    // Loop through the plugin $services and look up information in $services2Lookup.
    foreach ($services as $key => $service) {
      // Assuming the key is the serviceCode in your case.
      $serviceCode = $key;

      // Check if the serviceCode exists in $services2Lookup XPS Array.
      if (isset($services2Lookup[$serviceCode])) {
        // This is the matched array we need to check below.
        $matchedService = $services2Lookup[$serviceCode];

        // Null will mean all countries are supported as per api docs.
        // Supported countries are null and the country code is not in the unsupported countries.
        if ($matchedService['supportedCountries'] === null && ($matchedService['unsupportedCountries'] !== null && !in_array($store_country_code, $matchedService['unsupportedCountries']))) {
          // Need to add Shipping service for this logic. US is not included as an example.
          $plugin_definition['services'][$serviceCode] = $service;
        } elseif ($matchedService['supportedCountries'] !== null && in_array($store_country_code, $matchedService['supportedCountries'])) {
          // Supported countries is not null and the country code is in supported countries.
          $plugin_definition['services'][$serviceCode] = $service;
        } elseif ($matchedService['unsupportedCountries'] !== null && in_array($store_country_code, $matchedService['unsupportedCountries'])) {
          // When the country is in the unsupported list continue.
          continue;
        } else {
          // Add the shipping service as this is a fallback.
          $plugin_definition['services'][$serviceCode] = $service;
        }
      }
    }

    return $plugin_definition;
  }

  /**
   * Get all the XPS Services and add the to the Shipping Methods.
   *
   * @param array $configuration
   *    A configuration login needed for the XPS API.
   *
   * @return array $json_array_data
   *   The XPS Services array from the API.
   */
  public function getXpsServices(array $configuration) {
    // XPS Services
    $auth_api_key = 'RSIS ' . $configuration['api_information']['api_key'];
    $customer_id = $configuration['api_information']['customer_id'];

    // XPS Services Endpoint
    $uri = 'https://xpsshipper.com/restapi/v1/customers/'. $customer_id . '/services';

    $json_array_data = null;

    // Initialize the Guzzle HTTP Client.
    $client = \Drupal::httpClient();
    try {
      // Set various headers on a request
      $response = $client->get($uri, [
        'headers' => [
          'Authorization' => $auth_api_key
        ]
      ]);
      $stream = $response->getBody();
      $json_array_data = Json::decode($stream);
    }
    // Guzzle Exceptions - /vendor/guzzlehttp/guzzle/src/Exception
    catch (ClientException $e) {
      \Drupal::messenger()->addError($e->getMessage());
    }
    catch (\Exception $e) {
      \Drupal::messenger()->addError($e->getMessage());
    }

    return $json_array_data;
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

    return $this->getXpsRates($shipment, $this->parentEntity);
  }

  /**
   * This will build the JSON object to send to the XPS API to get the rates.
   *
   * @param \Drupal\commerce_shipping\Entity\ShipmentInterface $commerce_shipment
   *   The commerce shipment entity.
   *
   * @param \Drupal\commerce_shipping\Entity\ShippingMethodInterface $shipping_method
   *   The shipping method.
   *
   * @return array $rates
   *   The XPS Shipping Rates array from the API.
   */
  public function getXpsRates(ShipmentInterface $commerce_shipment, ShippingMethodInterface $shipping_method) {

    // Set the necessary info needed for the request.
    $this->setShipment($commerce_shipment);
    $this->setShippingMethod($shipping_method);

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

    // Commerce Rounder Service
    $rounder = \Drupal::service('commerce_price.rounder');
    $amount = $rounder->round($amount);

    // Need to get the Carrier Code from the begining of the Service code.
    // This will be used when there is Fedex, DHL, UPS, USPS Service codes.
    $carrier_codes = [];

    foreach ($this->configuration['services'] as $service_code) {
      $parts = explode('_', $service_code);
      $firstPart = $parts[0];

      // Add the first part to the service code if it doesn't exist already
      if (!in_array($firstPart, $carrier_codes)) {
        $carrier_codes[] = $firstPart;
      }
    }

    // Rates array to be returned below.
    $rates = [];

    // Need to loop through the Carrier Codes to get the Rates for each one.
    foreach ($carrier_codes as $carrier_code) {
      // Create an array representing the JSON for XPS Rate Quotes.
      $jsonData = [
        "carrierCode" => $carrier_code,
        "serviceCode" => "",
        "packageTypeCode" => "",
        "sender" => [
          "country" => $store_country_code,
          "zip" => $store_postal_code
        ],
        "receiver" => [
          "city" => $receiver_city,
          "country" => $receiver_country,
          "zip" => $receiver_zipcode
        ],
        "residential" => true,
        "signatureOptionCode" => "DIRECT",
        "weightUnit" => $weight_unit,
        "dimUnit" => $dim_unit,
        "currency" => $store_currency_code,
        "customsCurrency" => $store_currency_code,
        "pieces" => [
          [
            "weight" => $weight_value,
            "length" => null,
            "width" => null,
            "height" => null,
            "insuranceAmount" => $amount->getNumber(),
            "declaredValue" => null
          ]
        ],
        "billing" => [
            "party" => "sender"
        ]
      ];

      // Get the all the XPS rate shipping quotes based on the Carrier code JSON POST.
      $xpsRateQuotes = $this->getXpsRateQuotes($jsonData);

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
    }

    return $rates;
  }

  /**
   * Get all the XPS Shipping quotes based on the services checked in the config.
   *
   * @param array $jsonData
   *    The Array before the JSON object for the XPS API.
   *
   * @return array $json_response
   *   The decoded JSON object array from XPS API.
   */
  public function getXpsRateQuotes(array $jsonData) {

    // Debug log the json request.
    $this->logRequest($jsonData);

    // Encode the Rate Quote array into a JSON string
    $jsonData = json_encode($jsonData);

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
      $this->logResponse($json_response);
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
   *
   * @param string weightUnit
   *   Dimension unit to use to get the base unit.
   *
   * @return string
   *   The measurement unit or null.
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
   * Logs the Request Data to Watchdog.
   *
   * @param array $request
   *   The decoded request JSON object array to the XPS API.
   */
  protected function logRequest($request) {
    if (!empty($this->configuration['options']['log']['request'])) {
      \Drupal::logger('Commerce XPS')->info('@message', ['@message' => print_r($request, TRUE)]);
    }
  }

  /**
   * Logs the Response Data to Watchdog.
   *
   * @param array $response
   *   The decoded response JSON object array from the XPS API.
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
   * Is the XPS API login information available.
   *
   * @return bool
   *   TRUE if the login information exists.
   */
  protected function isConfigured() {
    $api_config = $this->configuration['api_information'];

    if (empty($api_config['customer_id']) || empty($api_config['api_key'])) {
      return FALSE;
    }

    return TRUE;
  }

}
