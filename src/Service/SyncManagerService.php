<?php

namespace Drupal\nyx_content_sync\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;

/**
 * Gerencia sincronização de conteúdo com o Hub.
 */
class SyncManagerService {

  /**
   * Hub client service.
   *
   * @var \Drupal\nyx_content_sync\Service\HubClientService
   */
  protected $hubClient;

  /**
   * Markdown converter service.
   *
   * @var \Drupal\nyx_content_sync\Service\MarkdownConverterService
   */
  protected $markdownConverter;

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * File system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Construtor.
   */
  public function __construct(
    HubClientService $hub_client,
    MarkdownConverterService $markdown_converter,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    FileSystemInterface $file_system
  ) {
    $this->hubClient = $hub_client;
    $this->markdownConverter = $markdown_converter;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('nyx_content_sync');
    $this->fileSystem = $file_system;
  }

  /**
   * Verifica se tipo de conteúdo está habilitado para sync.
   *
   * @param string $content_type
   *   Machine name do tipo de conteúdo.
   *
   * @return bool
   *   TRUE se habilitado.
   */
  public function isContentTypeEnabled(string $content_type): bool {
    $mappings = $this->getContentTypeMappings();
    return isset($mappings[$content_type]);
  }

  /**
   * Sincroniza node com o Hub.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node a sincronizar.
   *
   * @return bool
   *   TRUE se sincronizado com sucesso.
   */
  public function syncContent(NodeInterface $node): bool {
    $content_type = $node->bundle();

    if (!$this->isContentTypeEnabled($content_type)) {
      return FALSE;
    }

    $config = $this->getSyncConfig($content_type);
    if (!$config) {
      return FALSE;
    }

    $nodes = $this->loadPublishedNodes($content_type);
    if (empty($nodes)) {
      $this->logger->warning('Nenhum node publicado do tipo @type encontrado', ['@type' => $content_type]);
      return FALSE;
    }

    return $this->performSync($content_type, $nodes, $config, ['triggered_by' => $node->id()]);
  }

  /**
   * Remove conteúdo do Hub.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node a remover.
   *
   * @return bool
   *   TRUE se removido com sucesso.
   */
  public function deleteContent(NodeInterface $node): bool {
    $content_type = $node->bundle();

    if (!$this->isContentTypeEnabled($content_type)) {
      return FALSE;
    }

    $config = $this->getSyncConfig($content_type);
    if (!$config) {
      return FALSE;
    }

    $nodes = $this->loadPublishedNodes($content_type, $node->id());

    // Se não há mais nodes, deleta o arquivo
    if (empty($nodes)) {
      $this->logger->info('Deletando último node do tipo @type - removendo storage', ['@type' => $content_type]);
      return $this->hubClient->deleteContent($config['group_key'], $config['store_name'], 'content_type_' . $content_type);
    }

    // Re-sincroniza nodes restantes
    return $this->performSync($content_type, $nodes, $config, [
      'action' => 'delete_resync',
      'deleted_node_id' => $node->id(),
    ]);
  }

  /**
   * Obtém configuração de sincronização.
   *
   * @param string $content_type
   *   Tipo de conteúdo.
   *
   * @return array|null
   *   Config [group_key, store_name] ou NULL.
   */
  private function getSyncConfig(string $content_type): ?array {
    $group_key = $this->getGroupKey();
    $store_name = $this->getStoreNameForContentType($content_type);

    if (empty($group_key) || empty($store_name)) {
      $this->logger->error('Configuração incompleta para @type', ['@type' => $content_type]);
      return NULL;
    }

    if (!$this->hubClient->validateStore($group_key, $store_name)) {
      $this->logger->error('Store @store inválida para group key', ['@store' => $store_name]);
      return NULL;
    }

    return ['group_key' => $group_key, 'store_name' => $store_name];
  }

  /**
   * Carrega nodes publicados do tipo.
   *
   * @param string $content_type
   *   Tipo de conteúdo.
   * @param int|null $exclude_nid
   *   ID do node a excluir.
   *
   * @return array
   *   Array de nodes.
   */
  private function loadPublishedNodes(string $content_type, ?int $exclude_nid = NULL): array {
    $query = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
      ->condition('type', $content_type)
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->sort('created', 'ASC');

    if ($exclude_nid) {
      $query->condition('nid', $exclude_nid, '!=');
    }

    $nids = $query->execute();
    return $nids ? \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids) : [];
  }

/**
   * Executa sincronização.
   *
   * @param string $content_type
   *   Tipo de conteúdo.
   * @param array $nodes
   *   Nodes a sincronizar.
   * @param array $config
   *   Configuração [group_key, store_name].
   * @param array $extra_metadata
   *   Metadados adicionais.
   *
   * @return bool
   *   TRUE se sucesso.
   */
  private function performSync(string $content_type, array $nodes, array $config, array $extra_metadata = []): bool {
    $markdown = $this->markdownConverter->convertMultipleToMarkdown($nodes, $content_type);

    $markdown_path = $this->saveMarkdownFile($content_type, $markdown);
    if ($markdown_path) {
      $this->logger->info('Arquivo Markdown salvo em: @path', ['@path' => $markdown_path]);
    }

    $metadata = array_merge([
      'content_type' => $content_type,
      'total_nodes' => count($nodes),
      'node_ids' => array_keys($nodes),
      'last_updated' => time(),
    ], $extra_metadata);

    $this->logger->info('Sincronizando @count nodes do tipo @type', [
      '@count' => count($nodes),
      '@type' => $content_type,
    ]);

    return $this->hubClient->uploadContent(
      $config['group_key'],
      $config['store_name'],
      'content_type_' . $content_type,
      $markdown,
      $metadata
    );
  }

  /**
   * Obtém Group Key.
   *
   * @return string
   *   Group Key.
   */
  protected function getGroupKey(): string {
    // Prioriza variável de ambiente
    $env_key = getenv('NYX_GROUP_KEY');
    if ($env_key) {
      return $env_key;
    }

    // Fallback para configuração
    $config = $this->configFactory->get('nyx_content_sync.settings');
    return $config->get('group_key') ?: '';
  }

  /**
   * Obtém mapeamentos de tipos de conteúdo.
   *
   * @return array
   *   Array associativo [content_type => store_name].
   */
  protected function getContentTypeMappings(): array {
    $config = $this->configFactory->get('nyx_content_sync.settings');
    $mappings = $config->get('content_type_mappings') ?: [];

    // Converte array de objetos para array associativo
    $result = [];
    foreach ($mappings as $mapping) {
      if (isset($mapping['content_type']) && isset($mapping['store_name'])) {
        $result[$mapping['content_type']] = $mapping['store_name'];
      }
    }

    return $result;
  }

  /**
   * Obtém store name para um tipo de conteúdo.
   *
   * @param string $content_type
   *   Machine name do tipo de conteúdo.
   *
   * @return string|null
   *   Store name ou NULL.
   */
  protected function getStoreNameForContentType(string $content_type): ?string {
    $mappings = $this->getContentTypeMappings();
    return $mappings[$content_type] ?? NULL;
  }

  /**
   * Salva arquivo Markdown no sistema de arquivos.
   *
   * @param string $content_type
   *   Tipo de conteúdo.
   * @param string $markdown
   *   Conteúdo Markdown.
   *
   * @return string|null
   *   Caminho do arquivo salvo ou NULL em caso de erro.
   */
  protected function saveMarkdownFile(string $content_type, string $markdown): ?string {
    // Diretório seguindo boas práticas do Drupal
    $directory = 'public://nyx_content_sync';

    // Garante que o diretório existe
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    // Nome do arquivo com timestamp
    $timestamp = date('Y-m-d_H-i-s');
    $filename = sprintf('%s_%s.md', $content_type, $timestamp);
    $uri = $directory . '/' . $filename;

    try {
      // Salva o arquivo
      $file_uri = $this->fileSystem->saveData($markdown, $uri, FileSystemInterface::EXISTS_REPLACE);

      // Retorna o caminho real do arquivo
      return $this->fileSystem->realpath($file_uri);
    }
    catch (\Exception $e) {
      $this->logger->error('Erro ao salvar arquivo Markdown: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

}
