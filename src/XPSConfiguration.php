<?php

namespace Drupal\commerce_xps;

use Drupal\Component\Serialization\Json;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Class that sets the XPS Plugin and API services.
 *
 * @package Drupal\commerce_xps
 */
class XPSConfiguration {

  /**
   * Sorts the XPS Shipping services if available based on country.
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
  public function preparePluginDefinition($plugin_definition, $configuration) {
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

}
