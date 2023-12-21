<?php

namespace Drupal\commerce_xps;

use Drupal\commerce_price\Price;
use Drupal\commerce_shipping\ShippingRate;
use Drupal\commerce_shipping\ShippingService;
//use USPS\ServiceDeliveryCalculator;

/**
 * Class XPSRateRequest.
 *
 * @package Drupal\commerce_xps
 */
class XPSRateRequest extends XPSRateRequestBase implements XPSRateRequestInterface {

  /**
   * Temporary IDs used for the shared service ids by XPS API.
   */
  const FIRST_CLASS_MAIL_ENVELOPE = 9999;
  const FIRST_CLASS_MAIL_LETTER = 9998;
  const FIRST_CLASS_MAIL_POSTCARDS = 9997;
  const FIRST_CLASS_MAIL_PACKAGE = 9996;

  /**
   * Resolve the rates from the RateRequest response.
   *
   * @param array $response
   *   The rate request array.
   *
   * @return array
   *   An array of ShippingRates or an empty array.
   */
  public function resolveRates(array $response) {
    $rates = [];

    // Parse the rate response and create shipping rates array.
    if (!empty($response['RateV4Response']['Package']['Postage'])) {

      // Convert the postage response to an array of rates when
      // only 1 rate is returned.
      if (!empty($response['RateV4Response']['Package']['Postage']['Rate'])) {
        $response['RateV4Response']['Package']['Postage'] = [$response['RateV4Response']['Package']['Postage']];
      }

      foreach ($response['RateV4Response']['Package']['Postage'] as $rate) {
        $price = $rate['Rate'];

        // Attempt to use an alternate rate class if selected.
        if (!empty($this->configuration['rate_options']['rate_class'])) {
          switch ($this->configuration['rate_options']['rate_class']) {
            case 'commercial_plus':
              $price = !empty($rate['CommercialPlusRate']) ? $rate['CommercialPlusRate'] : $price;
              break;
            case 'commercial':
              $price = !empty($rate['CommercialRate']) ? $rate['CommercialRate'] : $price;
              break;
          }
        }

        $service_code = $rate['@attributes']['CLASSID'];
        $service_name = $this->cleanServiceName($rate['MailService']);

        // Class code 0 is used for multiple services in the
        // response. The only way to determine which service
        // is returned is to parse the service name for matching
        // strings based on the service type. All other service
        // codes are unique and do not require this extra step.
        if ($service_code == 0) {
          if (stripos($service_name, 'Envelope') !== FALSE) {
            $service_code = self::FIRST_CLASS_MAIL_ENVELOPE;
          }
          elseif (stripos($service_name, 'Letter') !== FALSE) {
            $service_code = self::FIRST_CLASS_MAIL_LETTER;
          }
          elseif (stripos($service_name, 'Postcards') !== FALSE) {
            $service_code = self::FIRST_CLASS_MAIL_POSTCARDS;
          }
          elseif (stripos($service_name, 'Package') !== FALSE) {
            $service_code = self::FIRST_CLASS_MAIL_PACKAGE;
          }
          else {
            continue;
          }
        }

        // Only add the rate if this service is enabled.
        if (!in_array($service_code, $this->configuration['services'])) {
          continue;
        }

        $rates[] = new ShippingRate([
          'shipping_method_id' => $this->shippingMethod->id(),
          'service' => new ShippingService($service_code, $service_name),
          'amount' => new Price($price, 'USD'),
        ]);
      }
    }

    return $rates;
  }

  // /**
  //  * Checks the delivery date of a XPS shipment.
  //  *
  //  * @return array
  //  *   The delivery rate response.
  //  */
  // public function checkDeliveryDate() {
  //   $to_address = $this->commerceShipment->getShippingProfile()
  //     ->get('address');
  //   $from_address = $this->commerceShipment->getOrder()
  //     ->getStore()
  //     ->getAddress();

  //   // Initiate and set the username provided from usps.
  //   $delivery = new ServiceDeliveryCalculator($this->configuration['api_information']['user_id']);
  //   // Add the zip code we want to lookup the city and state.
  //   $delivery->addRoute(3, $from_address->getPostalCode(), $to_address->postal_code);
  //   // Perform the call and print out the results.
  //   $delivery->getServiceDeliveryCalculation();

  //   return $delivery->getArrayResponse();
  // }

  /**
   * Initialize the rate request object needed for the USPS API.
   */
  public function buildRate() {
    // Invoke the parent to initialize the uspsRequest.
    parent::buildRate();

    // Add each package to the request.
    foreach ($this->getPackages() as $package) {
      $this->xpsRequest->addPackage($package);
    }
  }

  /**
   * Utility function to translate service labels.
   *
   * @param string $service_code
   *   The service code.
   *
   * @return string
   *   The translated service code.
   */
  protected function translateServiceLables($service_code) {
    $label = '';
    if (strtolower($service_code) == 'parcel') {
      $label = 'ground';
    }

    return $label;
  }

  /**
   * Utility function to validate a USA zip code.
   *
   * @param string $zip_code
   *   The zip code.
   *
   * @return bool
   *   Returns TRUE if the zip code was validated.
   */
  protected function validateUsaZip($zip_code) {
    return preg_match("/^([0-9]{5})(-[0-9]{4})?$/i", $zip_code);
  }

}
