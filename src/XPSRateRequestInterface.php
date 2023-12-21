<?php

namespace Drupal\commerce_xps;

use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\Entity\ShippingMethodInterface;

/**
 * The interface for fetching and returning rates using the XPS Shipping REST API.
 *
 * @package Drupal\commerce_xps
 */
interface XPSRateRequestInterface {

  /**
   * Fetch rates for the shipping method.
   *
   * @param \Drupal\commerce_shipping\Entity\ShipmentInterface $commerce_shipment
   *   The commerce shipment.
   * @param \Drupal\commerce_shipping\Entity\ShippingMethodInterface $shipping_method
   *   The shipping method being rated.
   *
   * @return array
   *   An array of ShippingRate objects.
   */
  public function getRates(ShipmentInterface $commerce_shipment, ShippingMethodInterface $shipping_method);

  /**
   * Build the rate object.
   */
  public function buildRate();

  /**
   * Alter the rate object.
   */
  public function alterRate();

  /**
   * Parse the rate response and return shipping rates.
   *
   * @param array $response
   *   The USPS RateRequest response as an array.
   *
   * @return array
   *   Returns an array of ShippingRate objects
   */
  public function resolveRates(array $response);

}
