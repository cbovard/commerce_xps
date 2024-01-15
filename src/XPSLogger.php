<?php

namespace Drupal\commerce_xps;

/**
 * Class that will handle Drupal logging.
 *
 * @package Drupal\commerce_xps
 */
class XPSLogger {

  /**
   * The configuration array.
   *
   * @var array
   */
  protected $configuration;

  /**
   * Constructs an XPS Logging Object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   */
  public function __construct($configuration) {
    $this->configuration = $configuration;
  }

  /**
   * Logs the Request Data to Watchdog.
   *
   * @param array $request
   *   The decoded request JSON object array to the XPS API.
   */
  public function logRequest($request) {
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
  public function logResponse($response) {
    if (!empty($this->configuration['options']['log']['response'])) {
      \Drupal::logger('Commerce XPS')->info('@message', ['@message' => print_r($response, TRUE)]);
    }
  }

}
