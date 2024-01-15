<?php

namespace Drupal\commerce_xps;

use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\Entity\ShippingMethodInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_shipping\ShippingRate;
use Drupal\commerce_shipping\ShippingService;
use Drupal\Component\Serialization\Json;
use Drupal\commerce_xps\XPSLogger;

/**
 * Class that gets the XPS Shipping Rates.
 *
 * @package Drupal\commerce_xps
 */
class XPSRateCalculator {

  /**
   * The parent config entity.
   *
   * @var \Drupal\commerce_shipping\Entity\ShippingMethodInterface
   */
  protected $parentEntity;

  /**
   * The plugin instance configuration array.
   *
   * @var array
   */
  protected $configuration;

  /**
   * Constructs an XPS Shipping Rates object.
   *
   * @param string $parentEntity
   *   This is the Shipping Method from the parent entity.
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   */
  public function __construct($parentEntity, $configuration) {
    $this->parentEntity = $parentEntity;
    $this->configuration = $configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function xpsCalculateRates(ShipmentInterface $shipment) {
    // Only attempt to collect rates if an address exists on the shipment.
    if ($shipment->getShippingProfile()->get('address')->isEmpty()) {
      return [];
    }

    // Only attempt to collect rates for US addresses.
    if ($shipment->getShippingProfile()->get('address')->country_code != 'US') {
      return [];
    }

    return $this->getXpsRates($shipment);
  }

  /**
   * This will build the JSON object to send to the XPS API to get the rates.
   *
   * @param \Drupal\commerce_shipping\Entity\ShipmentInterface $commerce_shipment
   *   The commerce shipment entity.
   *
   * @return array $rates
   *   The XPS Shipping Rates array from the API.
   */
  public function getXpsRates(ShipmentInterface $shipment) {
    // Store Address
    $store_country_code = $shipment->getOrder()->getStore()->getAddress()->getCountryCode();
    $store_postal_code = $shipment->getOrder()->getStore()->getAddress()->getPostalCode();

    // Receiver Address
    /** @var \CommerceGuys\Addressing\Address $address */
    $address = $shipment->getShippingProfile()->get('address')->first();
    $receiver_city = $address->getLocality();
    $receiver_country = $address->getCountryCode();
    $receiver_zipcode = $address->getPostalCode();

    // Weight / Dimension Unit
    $weight_unit = $shipment->getWeight()->getUnit();
    $dim_unit = $this->getDimensionUnit($weight_unit);
    $weight_value = $shipment->getWeight()->getNumber();

    // Currency / Amount
    $store_currency_code = $shipment->getOrder()->getStore()->getDefaultCurrencyCode();
    $amount = $shipment->getOrder()->getTotalPrice()->getNumber();
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
  public function logRequest($request) {
    $logger = new XPSLogger($this->configuration);
    $logger->logRequest($request);
  }

  /**
   * Logs the Response Data to Watchdog.
   *
   * @param array $response
   *   The decoded response JSON object array from the XPS API.
   */
  public function logResponse($response) {
    $logger = new XPSLogger($this->configuration);
    $logger->logResponse($response);
  }

}
