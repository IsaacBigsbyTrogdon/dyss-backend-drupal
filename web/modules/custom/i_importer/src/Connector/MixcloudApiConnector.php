<?php

namespace Drupal\i_importer\Connector;

use Drupal\Component\Serialization\Json;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Response;

class MixcloudApiConnector {

  /**
   * @var string
   */
  private $base_url = NULL;

  /**
   * @var string
   */
  private $access_token = NULL;

  /**
   * @var \GuzzleHttp\Client
   */
  private $httpClient = NULL;

  private $default_params = [];

  /**
   * @var null|ClientException
   */
  private $lastException = NULL;

  public function __construct() {
//  public function __construct($access_token, $base_url) {
//    $this->access_token = $access_token;
//    $this->base_url = $base_url;
    $this->httpClient = \Drupal::httpClient();
  }

  /**
   * @return string
   */
  public function getBaseUrl() {
    return $this->base_url;
  }

  /**
   * @return \GuzzleHttp\Client
   */
  public function getHttpClient() {
    return $this->httpClient;
  }

  public function getDefaultParams() {
    return $this->default_params;
  }

  public function setDefaultParams($params = []) {
    $this->default_params = $params;
  }

  public function getLastException() {
    return $this->lastException;
  }

  /**
   * @param $path
   * @param array $params
   * @return bool|mixed
   */
  public function query($path, array $params = []) {
    $method = 'get';
    return $this->httpRequest($method, $path, $params);
  }

  /**
   * @param $path
   * @param array $params
   * @return bool|mixed
   */
  public function post($path, array $params = []) {
    $method = 'post';
    return $this->httpRequest($method, $path, $params);
  }

  /**
   * @param $method
   * @param $path
   * @param array $params
   * @return bool|mixed
   */
  protected function httpRequest($method, $path, array $params = []) {
    $base_url = $this->getBaseUrl();
    $client = $this->getHttpClient();

    if (empty($params)) {
      $request_params = $this->default_params;
    }
    else {
      $request_params = array_merge_recursive($params, $this->default_params);
    }

    try {
      /** @var Response $response */
      $response = $client->{$method}($base_url . $path, $request_params);
    } catch (ClientException $e) {
      $this->lastException = $e;
      return FALSE;
    }

    $body = $response->getBody()->getContents();
    $status = $response->getStatusCode();

    if ($body && ($status == 200 || $status == 201)) {
      return Json::decode($body);
    }

    return FALSE;
  }

}
