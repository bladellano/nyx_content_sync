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
    try {
      $hub_url = $this->getHubUrl();
      $url = rtrim($hub_url, '/') . '/api/nyx-sync/upload';

      $response = $this->httpClient->request('POST', $url, [
        'auth' => $this->getAuthCredentials(),
        'json' => [
          'group_key' => $group_key,
          'store_name' => $store_name,
          'content_id' => $content_id,
          'markdown' => $markdown_content,
          'metadata' => $metadata,
        ],
        'timeout' => 30,
      ]);

      $status_code = $response->getStatusCode();
      if ($status_code === 200 || $status_code === 201) {
        $this->logger->info('Conteúdo @id enviado para @store', [
          '@id' => $content_id,
          '@store' => $store_name,
        ]);
        return TRUE;
      }

      return FALSE;
    }
    catch (RequestException $e) {
      $this->logger->error('Erro ao enviar conteúdo @id: @message', [
        '@id' => $content_id,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
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
    try {
      $hub_url = $this->getHubUrl();
      $url = rtrim($hub_url, '/') . '/api/nyx-sync/delete';

      $response = $this->httpClient->request('POST', $url, [
        'auth' => $this->getAuthCredentials(),
        'json' => [
          'group_key' => $group_key,
          'store_name' => $store_name,
          'content_id' => $content_id,
        ],
        'timeout' => 10,
      ]);

      $status_code = $response->getStatusCode();
      return ($status_code === 200 || $status_code === 204);
    }
    catch (RequestException $e) {
      $this->logger->error('Erro ao deletar conteúdo @id: @message', [
        '@id' => $content_id,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Obtém URL do Hub das configurações ou variável de ambiente.
   *
   * @return string
   *   URL do Hub.
   */
  protected function getHubUrl(): string {
    // Prioriza variável de ambiente
    $env_url = getenv('NYX_HUB_URL');
    if ($env_url) {
      return $env_url;
    }

    // Fallback para configuração
    $config = $this->configFactory->get('nyx_content_sync.settings');
    return $config->get('hub_url') ?: '';
  }

  /**
   * Obtém credenciais de autenticação Basic Auth.
   *
   * @return array
   *   Array com [username, password].
   */
  protected function getAuthCredentials(): array {
    $username = getenv('NYX_API_USERNAME') ?: 'api_sync';
    $password = getenv('NYX_API_PASSWORD') ?: '';

    return [$username, $password];
  }

}
