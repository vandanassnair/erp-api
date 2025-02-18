<?php

namespace Drupal\qad_web_joblisting\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a prayer time block.
 *
 * @Block(
 *   id = "jobslist_block",
 *   admin_label = @Translation("Jobs Listing"),
 *   category = @Translation("Custom"),
 * )
 */
class JobsListBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Constructs a new ApiDataBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ClientInterface $http_client) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $current_language = \Drupal::languageManager()->getCurrentLanguage()->getId();
    $jobs_response = $this->httpClient->get('https://qadwebsite.applab.qa/'.$current_language .'/api/events');
    $data = json_decode($jobs_response->getBody()->getContents(), TRUE);
    return [
      '#theme' => 'jobslisting_block',
      '#items' => $data,
      '#cache' => ['max-age' => 0],
    ];
  }

}
