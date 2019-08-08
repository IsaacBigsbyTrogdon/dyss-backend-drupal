<?php

namespace Drupal\ibt_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ibt_api\Connection\MixcloudConnection;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\ClientException;

/**
 * Provides controller methods for the mixcloudApi API integration overview.
 */
class mixCloudApiOverviewController extends ControllerBase {

  /**
   * {@inheritdoc}
   */
  public function showOverview() {
    $build = [];

    list($response, $json) = $this->pingEndpoint($build);
    // If response data was built and returned, display it with a sample of the
    // objects returned
    if (isset($response)) {
      $build['response'] = [
        '#theme' => 'item_list',
        '#title' => t('Response: @r', [
          '@r' => $response->getReasonPhrase(),
        ]),
        '#items' => [
          'code' => t('Code: @c', ['@c' => $response->getStatusCode()]),
        ],
      ];
    }
    if (isset($json)) {
      $t=1;
      $build['response_data'] = [
        '#theme' => 'item_list',
        '#title' => t('Response Data:'),
        '#items' => [
          'response-type' => t('Response Type: @t', [
            '@t' => $json->name ?? t('Unknown'),
          ]),
          'total-count' => t('Total Count: @c', [
            '@c' => isset($json->data) ? count($json->data) : t('Unknown'),
          ]),
        ],
      ];
      $this->displayPaginationData($json, $build);
      $this->displayDataSample($json, $build);
    }
    return $build;
  }

  /**
   * Ping the mixcloudApi API for basic data.
   *
   * @param array $build render array
   *
   * @return array of [$response, $json]
   */
  protected function pingEndpoint(&$build) {
    $connection = new MixcloudConnection();
    $response   = NULL;
    $json       = NULL;
    try {
      $response = $connection->callEndpoint('ApiDetailsFull', [
        'limit'     => 10,
        'url_query' => [
          'sort' => 'gid asc',
        ]
      ]);
      $json = json_decode($response->getBody());
      $t1=1;
    } catch (ServerException $e) {
      // Handle their server-side errors
      $build['server_error'] = [
        '#theme' => 'item_list',
        '#title' => t('Server Exception: @r', [
          '@r' => $e->getResponse()->getReasonPhrase(),
        ]),
        '#items' => [
          'url'  => t('URL: @u', ['@u' => $e->getRequest()->getUri()]),
          'code' => t('Code: @c', ['@c' => $e->getResponse()->getStatusCode()]),
        ],
      ];
      $build['exception'] = [
        '#markup' => $e->getMessage(),
      ];
      watchdog_exception('ibt_api', $e);
    } catch (ClientException $e) {
      // Handle client-side error (e.g., authorization failures)
      $build['client_error'] = [
        '#theme' => 'item_list',
        '#title' => t('Client Exception: @r', [
          '@r' => $e->getResponse()->getReasonPhrase(),
        ]),
        '#items' => [
          'url'  => t('URL: @u', ['@u' => $e->getRequest()->getUri()]),
          'code' => t('Code: @c', ['@c' => $e->getResponse()->getStatusCode()]),
        ],
      ];
      $build['exception'] = [
        '#markup' => $e->getMessage(),
      ];
      watchdog_exception('ibt_api', $e);
    } catch (\Exception $e) {
      // Handle general PHP exemptions
      $build['php_error'] = [
        '#theme' => 'item_list',
        '#title' => t('PHP Exception'),
        '#items' => [
          'code' => t('Code: @c', ['@c' => $e->getCode()]),
        ],
      ];
      $build['exception'] = [
        '#markup' => $e->getMessage(),
      ];
      watchdog_exception('ibt_api', $e);
    }
    return [$response, $json];
  }

  /**
   * Build out any available data for pagination.
   *
   * @param object $json
   * @param array  $build render array
   */
  protected function displayPaginationData($json, &$build) {
    if (isset($json->pagination->current_limit)) {
      $build['response_data']['#items']['current-limit'] = t('Current Limit: @l', [
        '@l' => $json->pagination->current_limit,
      ]);
    }
    if (isset($json->pagination->current_offset)) {
      $build['response_data']['#items']['current-offset'] = t('Current Offset: @o', [
        '@o' => $json->pagination->current_offset,
      ]);
    }
    if (isset($json->pagination->first)) {
      $build['response_data']['#items']['first'] = t('First URL: @f', [
        '@f' => $json->pagination->first,
      ]);
    }
    if (isset($json->pagination->prev)) {
      $build['response_data']['#items']['prev'] = t('Previous URL: @p', [
        '@p' => $json->pagination->prev,
      ]);
    }
    if (isset($json->pagination->next)) {
      $build['response_data']['#items']['next'] = t('Next URL: @n', [
        '@n' => $json->pagination->next,
      ]);
    }
    if (isset($json->pagination->last)) {
      $build['response_data']['#items']['last'] = t('Last URL: @l', [
        '@l' => $json->pagination->last,
      ]);
    }
  }

  /**
   * Build out a sample of the data returned.
   *
   * @param object $json
   * @param array  $build render array
   */
  protected function displayDataSample($json, &$build) {
    if (isset($json->response_data[0])) {
      $tea_data = $json->response_data[0];
      $build['tea_sample'] = [
        '#prefix' => '<pre>',
        '#markup' => print_r($tea_data, TRUE),
        '#suffix' => '</pre>',
      ];
    }
  }

}

