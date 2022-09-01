<?php

namespace Drupal\currency_layer_proxy\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Provides a custom resource to work as a middleware for currency api.
 *
 * @RestResource(
 *   id = "currency_converter_api",
 *   label = @Translation("Currency Converter Resource"),
 *   uri_paths = {
 *     "canonical" = "/api/currency"
 *   }
 * )
 */
class CurrencyConverterApi extends ResourceBase {

  /**
   * The request stack service.
   *
   * @var Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * GuzzleHttp\ClientInterface definition.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $client;

  /**
   * Settings.
   *
   * @var \Drupal\Core\Site\Settings
   */
  protected $settings;

  /**
   * Cache Interface.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cacheStorage;

  /**
   * Time Interface.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $timeStorage;

  /**
   * Constructs a Drupal\rest\Plugin\ResourceBase object.
   *
   * @param array $config
   *   A configuration array containing the information about plugin instance.
   * @param string $module_id
   *   The module_id for the plugin instance.
   * @param mixed $module_definition
   *   The plugin implementation definition.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger service instance.
   * @param Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   A request service instance.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   A http client instance.
   * @param \Drupal\Core\Site\Settings $settings
   *   A settings service instance.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   A cache default service instance.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   A time service instance.
   */
  public function __construct(array $config, $module_id, $module_definition, array $serializer_formats, LoggerInterface $logger, RequestStack $request_stack, ClientInterface $http_client, Settings $settings, CacheBackendInterface $cache, TimeInterface $time) {
    parent::__construct($config, $module_id, $module_definition, $serializer_formats, $logger);
    $this->requestStack = $request_stack;
    $this->client = $http_client;
    $this->settings = $settings;
    $this->cacheStorage = $cache;
    $this->timeStorage = $time;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $config, $module_id, $module_definition) {
    return new static(
      $config,
      $module_id,
      $module_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('sample_rest_resource'),
      $container->get('request_stack'),
      $container->get('http_client'),
      $container->get('settings'),
      $container->get('cache.default'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Responds to currency layer GET requests.
   *
   * @return \Drupal\rest\ResourceResponse
   *   Returning converted currencies in json format.
   */
  public function get() {
    // To fetch query paramters.
    $query = $this->requestStack->getCurrentRequest()->query->all();
    $source = $query['source'] ?? '';
    $currencies = $query['currencies'] ?? '';
    // The order of the currencies is not important so sorted before store.
    $currencies_arr = explode(',', $currencies);
    sort($currencies_arr);
    $sorted_currencies_string = implode('_', $currencies_arr);
    $cache_id = 'currency_layer_api:source_' . $source . '_currencies_' . $sorted_currencies_string;
    // To fetch ENV variables.
    $external_api = $this->settings->get('API_URL');
    $token = $this->settings->get('API_TOKEN');
    // If ENV variables is not set then return this response.
    $final_response = [
      "error" => [
        "message" => 'Something Went Wrong. Please contact to site administrator.',
        "code" => 400,
      ],
    ];
    // If it's same request then return cached data else external api response.
    if ($this->cacheStorage->get($cache_id, TRUE)) {
      $final_response = $this->cacheStorage->get($cache_id, TRUE)->data;
    }
    elseif (!empty($external_api) && !empty($token)) {
      $get_url = $external_api . '?source=' . $source . '&currencies=' . $currencies . '&apikey=' . $token;
      // Defined options for GET request.
      $options = [
        'connect_timeout' => 300,
        'headers' => [
          "Content-Type: text/plain",
        ],
        'verify' => TRUE,
      ];
      try {
        $response = $this->client->request('GET', $get_url, $options);
        $data = $response->getBody()->getContents();
        if ($data) {
          $decoded_external_api_response = json_decode($data, TRUE);
          // Return converted currencies.
          $final_response = $decoded_external_api_response['quotes'] ?? '';
          if (empty($final_response)) {
            $final_response = [
              "error" => [
                "message" => 'Incorrect Data.',
                "code" => 400,
              ],
            ];
          }
          $this->cacheStorage->set($cache_id, $final_response, $this->timeStorage->getRequestTime() + (86400));
        }
      }
      catch (RequestException $e) {
        $response = $e->getResponse();
        $code = $response->getStatusCode();
        $message = $response->getReasonPhrase();
        // Return error response.
        $final_response = [
          "error" => [
            "message" => $message,
            "code" => $code,
          ],
        ];
      }
    }
    $build = [
      '#cache' => [
        'contexts' => 'url.query_args',
      ],
    ];
    return (new ResourceResponse($final_response))->addCacheableDependency($build);
  }

}
