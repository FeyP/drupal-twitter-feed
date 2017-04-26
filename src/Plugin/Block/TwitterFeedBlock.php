<?php

namespace Drupal\twitter_feed\Plugin\Block;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Twitter Feed block.
 *
 * @Block(
 *   id = "twitter_feed_block",
 *   admin_label = @Translation("Twitter Feed"),
 *   category = @Translation("Media")
 * )
 */
class TwitterFeedBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The GuzzleHTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Access token provided by Twitter API.
   *
   * @var string
   */
  protected $accessToken;

  /**
   * Creates a TwitterFeedBlock instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The GuzzleHTTP client.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config_factory, ClientInterface $http_client) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $config_factory;
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
      $container->get('config.factory'),
      $container->get('http_client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $config = is_object($this->configFactory) ? $this->configFactory->get('twitter_feed.settings') : \Drupal::config('twitter_feed.settings');
    return [
      'username' => '',
      'num_tweets' => $config->get('max_tweets'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $config = $this->configuration;

    $defaults = $this->defaultConfiguration();
    $options = range(0, $defaults['num_tweets']);

    $form['username'] = [
      '#type' => 'textfield',
      '#title' => t('Twitter username'),
      '#default_value' => $config['username'],
      '#maxlength' => 512,
      '#description' => t('The Twitter username whose tweets will be displayed.'),
      '#required' => TRUE,
    ];

    $form['num_tweets'] = [
      '#type' => 'select',
      '#title' => $this->t('Number of tweets to display'),
      '#default_value' => $config['num_tweets'],
      '#options' => $options,
      '#description' => $this->t('This will be the number of tweets displayed in the block.'),
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['username'] = $form_state->getValue('username');
    $this->configuration['num_tweets'] = $form_state->getValue('num_tweets');
  }

  /**
   * {@inheritdoc}
   */
  public function build() {

    // Get tweets.
    $tweets = $this->getBearerToken()->performRequest();

    // Grab text and created at.
    $tweets_text = [];
    foreach ($tweets as $key => $tweet) {
      $tweets_text[$key]['text'] = $this->prepareTweet($tweet['text']);
      $tweets_text[$key]['created_at'] = $tweet['created_at'];
    }

    return [
      '#theme' => 'twitter_feed_block',
      '#username' => $this->configuration['username'],
      '#tweets' => $tweets_text,
    ];
  }

  /**
   * Gets a bearer token from Twitter API.
   */
  protected function getBearerToken() {
    if ($this->accessToken) {
      return $this;
    }

    $config = $this->configFactory->get('twitter_feed.settings');
    $encoded_key = base64_encode($config->get('twitter_api_key') . ':' . $config->get('twitter_secret_key'));

    $body = 'grant_type=client_credentials';
    $options = [
      'headers' => [
        'Authorization' => 'Basic ' . $encoded_key,
        'Content-Length' => strlen($body),
        'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8',
        'Accept-Encoding' => 'gzip',
        'User-Agent' => 'Finn\'s Twitter App v1.0.0',
      ],
      'body' => $body,
    ];
    $response = $this->httpClient->post('https://api.twitter.com/oauth2/token', $options);
    if ($response) {
      $parsed_response = Json::decode($response->getBody());
      $this->accessToken = isset($parsed_response['access_token']) ? $parsed_response['access_token'] : NULL;
    }
    return $this;
  }

  /**
   * Perform a reqeuest to Twitter API.
   *
   * @return mixed
   *   Parsed response (JSON).
   */
  protected function performRequest() {
    $options = [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->accessToken,
        'User-Agent' => 'Finn\'s Twitter App v1.0.0',
      ],
    ];

    $response = $this->httpClient->get('https://api.twitter.com/1.1/statuses/user_timeline.json?screen_name=' . $this->configuration['username'] . '&count=' . $this->configuration['num_tweets'], $options);
    $parsed_response = $response ? Json::decode($response->getBody()) : NULL;
    return $parsed_response;
  }

  /**
   * Links #hashtags, @mentions, and links in a tweet body.
   *
   * @param string $tweet
   *   Tweet body to prepare.
   *
   * @return string
   *   Prepared tweet body.
   */
  protected function prepareTweet($tweet = '') {
    $tweet = $this->formatLinks($tweet);
    $tweet = $this->formatHashtags($tweet);
    $tweet = $this->formatMentions($tweet);

    return $tweet;
  }

  /**
   * Links hash tags in a tweet body.
   *
   * @param string $tweet
   *   Tweet body to prepare.
   *
   * @return string
   *   Prepared tweet body.
   */
  protected function formatHashtags($tweet = '') {
    if (strpos($tweet, '#') !== FALSE) {
      $tweet = preg_replace('/(^|\s)#(\w*[a-zA-ZüöäßÜÄÖ_]+\w*)/', ' <a href="https://twitter.com/hashtag/$2" target="_blank">#$2</a>', $tweet);
    }
    return $tweet;
  }

  /**
   * Link URLs in a tweet body.
   *
   * @param string $tweet
   *   Tweet body to prepare.
   *
   * @return string
   *   Prepared tweet body.
   */
  protected function formatLinks($tweet = '') {
    // @todo: Make better check for URLs.
    if ((strpos($tweet, 'http') !== FALSE)) {
      $pattern = "/(?i)\b((?:https?:\/\/|www\d{0,3}[.]|[a-z0-9.\-]+[.][a-z]{2,4}\/)(?:[^\s()<>]+|\(([^\s()<>]+|(\([^\s()<>]+\)))*\))+(?:\(([^\s()<>]+|(\([^\s()<>]+\)))*\)|[^\s`!()\[\]{};:'\".,<>?«»“”‘’]))/";
      $tweet = preg_replace($pattern, '<a href="$1" target="_blank">$1</a>', $tweet);
    }
    return $tweet;
  }

  /**
   * Links mentions in a tweet body.
   *
   * @param string $tweet
   *   Tweet body to prepare.
   *
   * @return string
   *   Prepared tweet body.
   */
  protected function formatMentions($tweet = '') {
    if (strpos($tweet, '@') !== FALSE) {
      $tweet = preg_replace('/(^|\s)@(\w*[a-zA-Z_]+\w*)/', ' <a href="https://twitter.com/$2" target="_blank">@$2</a>', $tweet);
    }
    return $tweet;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    $cache_tags = parent::getCacheTags();
    $cache_tags[] = 'config:twitter_feed.block';
    return $cache_tags;
  }

  /**
   * {@inheritdoc}
   */
  protected function getRequiredCacheContexts() {
    return ['cache_context.user.roles', 'cache_context.language'];
  }

}
