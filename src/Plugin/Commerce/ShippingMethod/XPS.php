<?php

namespace Drupal\commerce_xps\Plugin\Commerce\ShippingMethod;

use Drupal\commerce_shipping\Entity\ShipmentInterface;

/**
 * Provides the XPS shipping method.
 *
 * TODO - add.
 */
class XPS extends XPSBase {

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

    // Make sure a package type is set on the shipment.
    // $this->setPackageType($shipment);
    // return $this->uspsRateService->getRates($shipment, $this->parentEntity);
    return null;
  }

}
