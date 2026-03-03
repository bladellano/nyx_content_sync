<?php

namespace Drupal\nyx_content_sync\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Serviço para comunicação com Nyx-Index-Hub.
 */
class HubClientService {

  /**
   * HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Construtor.
   */
  public function __construct(
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory
  ) {
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('nyx_content_sync');
    $this->configFactory = $config_factory;
  }

  /**
   * Valida se o storeName pertence ao Group Key.
   *
   * @param string $group_key
   *   Group Key do projeto.
   * @param string $store_name
   *   Store name a validar.
   *
   * @return bool
   *   TRUE se válido.
   */
  public function validateStore(string $group_key, string $store_name): bool {
    try {
      $hub_url = $this->getHubUrl();
      $url = rtrim($hub_url, '/') . '/api/nyx-sync/validate-store';

      $response = $this->httpClient->request('POST', $url, [
        'auth' => $this->getAuthCredentials(),
        'json' => [
          'group_key' => $group_key,
          'store_name' => $store_name,
        ],
        'timeout' => 10,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      return !empty($data['valid']);
    }
    catch (RequestException $e) {
      $this->logger->error('Erro ao validar store: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Envia arquivo Markdown para o Hub.
   *
   * @param string $group_key
   *   Group Key do projeto.
   * @param string $store_name
   *   Store name de destino.
   * @param string $content_id
   *   ID único do conteúdo.
   * @param string $markdown_content
   *   Conteúdo em Markdown.
   * @param array $metadata
   *   Metadados adicionais.
   *
   * @return bool
   *   TRUE se enviado com sucesso.
   */
  public function uploadContent(
    string $group_key,
    string $store_name,
    string $content_id,
    string $markdown_content,
    array $metadata = []
  ): bool {
    return $this->sendRequest('/api/nyx-sync/upload', [
      'group_key' => $group_key,
      'store_name' => $store_name,
      'content_id' => $content_id,
      'markdown' => $markdown_content,
      'metadata' => $metadata,
    ], 30);
  }

  /**
   * Remove conteúdo do Hub.
   *
   * @param string $group_key
   *   Group Key do projeto.
   * @param string $store_name
   *   Store name de destino.
   * @param string $content_id
   *   ID único do conteúdo.
   *
   * @return bool
   *   TRUE se removido com sucesso.
   */
  public function deleteContent(
    string $group_key,
    string $store_name,
    string $content_id
  ): bool {
    return $this->sendRequest('/api/nyx-sync/delete', [
      'group_key' => $group_key,
      'store_name' => $store_name,
      'content_id' => $content_id,
    ], 10);
  }

  /**
   * Envia requisição para o Hub.
   */
  private function sendRequest(string $endpoint, array $data, int $timeout = 10): bool {
    try {
      $response = $this->httpClient->request('POST', rtrim($this->getHubUrl(), '/') . $endpoint, [
        'auth' => $this->getAuthCredentials(),
        'json' => $data,
        'timeout' => $timeout,
      ]);
      return in_array($response->getStatusCode(), [200, 201, 204]);
    }
    catch (RequestException $e) {
      $this->logger->error('Erro em @endpoint: @msg', ['@endpoint' => $endpoint, '@msg' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Obtém URL do Hub (prioritiza variável de ambiente).
   */
  protected function getHubUrl(): string {
    return getenv('NYX_HUB_URL') ?: $this->configFactory->get('nyx_content_sync.settings')->get('hub_url') ?: '';
  }

  /**
   * Obtém credenciais Basic Auth.
   */
  protected function getAuthCredentials(): array {
    return [getenv('NYX_API_USERNAME') ?: 'api_sync', getenv('NYX_API_PASSWORD') ?: ''];
  }

}
