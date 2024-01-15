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

use Drupal\commerce_xps\XPSConfiguration;
use Drupal\commerce_xps\XPSRateCalculator;

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
   * The plugin instance configuration array.
   *
   * @var array
   */
  protected $configuration;

  /**
   * The XPS Configuration object.
   *
   * @var \Drupal\commerce_xps\XPSConfiguration
   */
  protected $xpsConfiguration;

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
    // We have to create the instance of this class here to pull in the XPS Services.
    // These services are checked for the store country in the plugin below.
    $this->xpsConfiguration = new XPSConfiguration();
    $plugin_definition = $this->xpsConfiguration->preparePluginDefinition($plugin_definition, $configuration);

    parent::__construct($configuration, $plugin_id, $plugin_definition, $package_type_manager, $workflow_manager);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateRates(ShipmentInterface $shipment) {
    // Collect rates for addresses only.
    if ($shipment->getShippingProfile()->get('address')->isEmpty()) {
      return [];
    }

    // Get the XPS Shipping rates.
    $this->xpsRateCalculator = new XPSRateCalculator($this->parentEntity, $this->configuration);
    return $this->xpsRateCalculator->xpsCalculateRates($shipment);
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
