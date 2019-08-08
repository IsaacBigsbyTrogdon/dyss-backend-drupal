<?php

namespace Drupal\ibt_api\Connection;

use Drupal\Core\Url;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Request as GuzzleRequest;


/**
 * Class MixcloudConnection
 *
 * @package Drupal\ibt_api
 */
class MixcloudConnection {

  /**
   * @var string mixcloudApi API version to use
   */
  protected $version = 'v1';

  /**
   * @var string API querying method
   */
  protected $method  = 'GET';

  /**
   * @var \Drupal\Core\Config\Config mixcloudApi settings
   */
  protected $config  = NULL;

  /**
   * @var array Store sensitive API info such as the private_key & password
   */
  protected $sensitiveConfig = [];



  /**
   * MixcloudConnection constructor.
   */
  public function __construct() {
    $this->config = \Drupal::config('ibt_api.settings');
  }

  /**
   * Get configuration or state setting for this mixcloudApi integration module.
   *
   * @param string $name this module's config or state.
   *
   * @return mixed
   */
  protected function getConfig($name) {
    $sensitive = [
      'private_key',
      'password',
    ];
    if (in_array($name, $sensitive)) {
      if (isset($this->sensitiveConfig[$name])) {
        return $this->sensitiveConfig[$name];
      }
      $this->sensitiveConfig[$name] = \Drupal::state()
        ->get('ibt_api.' . $name);
      return $this->sensitiveConfig[$name];
    }
    return $this->config->get('ibt_api.' . $name);
  }

  /**
   * Pings the mixcloudApi API for data.
   *
   * @param string $endpoint division endpoint to query
   * @param array  $options for Url building
   *
   * @return object
   */
  public function queryEndpoint($endpoint, $options = []) {
    try {
      $response = $this->callEndpoint($endpoint, $options);
      return json_decode($response->getBody());
    } catch (\Exception $e) {
      watchdog_exception('ibt_api', $e);
      return (object) [
        'response_type' => '',
        'response_data' => [],
        'pagination'    => (object) [
          'total_count'    => 0,
          'current_limit'  => 0,
          'current_offset' => 0,
        ],
      ];
    }
  }

  /**
   * Call the mixcloudApi API endpoint.
   *
   * @param string $endpoint
   * @param array  $options
   *
   * @return \Psr\Http\Message\ResponseInterface
   */
  public function callEndpoint($endpoint, $options = []) {
    $headers = $this->generateHeaders($this->requestUri($endpoint));
    $url     = isset($options['next_page']) ?
      $options['next_page'] : $this->requestUrl($endpoint, $options)
        ->toString();
    $client  = new GuzzleClient();
    $request = new GuzzleRequest($this->method, $url, $headers);
    return $client->send($request, ['timeout' => 30]);
  }

  /**
   * Build the URI part of the URL based on the endpoint and configuration.
   *
   * @param string $endpoint to the API data
   *
   * @return string
   */
  protected function requestUri($endpoint) {
//    $division = $this->getConfig('division');
//    return '/services/rest/' . $this->version . '/json/' . $division
//      . '/' . $endpoint . '/';
    return '/';
  }

  /**
   * Build a Url object of the URL data to query the MixCloud API.
   *
   * @param string $endpoint to the API data
   * @param array  $options to build the URL such as 'query_options'
   *
   * @return \Drupal\Core\Url
   */
  protected function requestUrl($endpoint, $options = []) {
    $url         = $this->getConfig('url');
    $public_key  = $this->getConfig('public_key');
    $territory   = $this->getConfig('territory');
    $request_uri = $this->requestUri($endpoint);
    $limit       = isset($options['limit']) ? $options['limit'] : 25;
    $offset      = 0;
    $start_time  = isset($options['start_time']) ? $options['start_time'] : NULL;
    $end_time    = isset($options['end_time']) ? $options['end_time'] : NULL;
    $url_query   = [
      'api_key'   => $public_key,
      'limit'     => $limit,
      'offset'    => $offset,
      'territory' => $territory,
    ];

    if (isset($start_time)) {
      $start_date             = new \DateTime('@' . $start_time);
      $url_query['startdate'] = $start_date->format('Y-m-d');
    }

    if (isset($end_time)) {
      $end_date             = new \DateTime('@' . $end_time);
      $url_query['enddate'] = $end_date->format('Y-m-d');
    }

    if (!empty($options['url_query']) && is_array($options['url_query'])) {
      $url_query = array_merge($url_query, $options['url_query']);
    }

    return Url::fromUri($url . $request_uri, [
      'query' => $url_query,
    ]);
  }

  /**
   * Build an array of headers to pass to the mixcloudApi API such as the
   * signature and account.
   *
   * @param string $request_uri to the API endpoint
   *
   * @return array
   */
  protected function generateHeaders($request_uri) {
    $username       = $this->getConfig('username');
    $password       = $this->getConfig('password');
    $private_key    = $this->getConfig('private_key');
    $request_method = 'GET';
    // Date must be UTC or signature will be invalid
    $original_timezone = date_default_timezone_get();
    date_default_timezone_set('UTC');
    $message = $request_uri . $request_method . date('mdYHi');
    $headers = [
      'x-signature' => $this->generateXSignature($message, $private_key),
      'x-account'   => $this->generateXAccount($username, $password),
    ];
    date_default_timezone_set($original_timezone);
    return $headers;
  }

  /**
   * Builds a hash for the x-signature to send to the mixcloudApi API according to
   * specifications.
   *
   * @param string $message
   * @param string $private_key
   *
   * @return string
   */
  protected function generateXSignature($message, $private_key) {
    return TRUE;
//    return some_encoding_process($message, $private_key);
  }

  /**
   * Builds a hash for the x-account to send to the mixcloudApi API according to
   * specifications.
   * @param string $username
   * @param string $password
   *
   * @return string
   */
  protected function generateXAccount($username, $password) {
    return TRUE;
//    return some_other_encoding_process($username, $password);
  }

}

