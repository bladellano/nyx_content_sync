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

    // Se node não está publicado, remove do Hub
    if (!$node->isPublished()) {
      return $this->deleteContent($node);
    }

    $config = $this->getSyncConfig($content_type);
    if (!$config) {
      return FALSE;
    }

    // Converte node para Markdown
    $markdown = $this->markdownConverter->convertToMarkdown($node);

    // Valida markdown não vazio
    if (empty(trim($markdown))) {
      $this->logger->warning('Node @id gerou markdown vazio', ['@id' => $node->id()]);
      return FALSE;
    }

    $content_id = $this->generateContentId($node);

    // Salva arquivo localmente (opcional, para debug)
    $this->saveMarkdownFile($content_id, $markdown);

    // Metadados
    $metadata = [
      'content_type' => $content_type,
      'node_id' => $node->id(),
      'title' => $node->getTitle(),
      'created' => $node->getCreatedTime(),
      'changed' => $node->getChangedTime(),
    ];

    // Envia para o Hub
    return $this->hubClient->uploadContent(
      $config['group_key'],
      $config['store_name'],
      $content_id,
      $markdown,
      $metadata
    );
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

    $content_id = $this->generateContentId($node);

    return $this->hubClient->deleteContent(
      $config['group_key'],
      $config['store_name'],
      $content_id
    );
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
    $mappings = $this->getContentTypeMappings();
    $store_name = $mappings[$content_type] ?? NULL;

    if (empty($group_key) || empty($store_name)) {
      return NULL;
    }

    if (!$this->hubClient->validateStore($group_key, $store_name)) {
      return NULL;
    }

    return ['group_key' => $group_key, 'store_name' => $store_name];
  }

  /**
   * Gera ID único: {content_type}-{node_id}.
   */
  protected function generateContentId(NodeInterface $node): string {
    return $node->bundle() . '-' . $node->id();
  }

  /**
   * Obtém Group Key (prioritiza variável de ambiente).
   */
  protected function getGroupKey(): string {
    return getenv('NYX_GROUP_KEY') ?: $this->configFactory->get('nyx_content_sync.settings')->get('group_key') ?: '';
  }

  /**
   * Obtém mapeamentos [content_type => store_name].
   */
  public function getContentTypeMappings(): array {
    $mappings = $this->configFactory->get('nyx_content_sync.settings')->get('content_type_mappings') ?: [];
    $result = [];
    foreach ($mappings as $mapping) {
      if (isset($mapping['content_type'], $mapping['store_name'])) {
        $result[$mapping['content_type']] = $mapping['store_name'];
      }
    }
    return $result;
  }

  /**
   * Salva arquivo Markdown localmente (debug).
   */
  protected function saveMarkdownFile(string $content_id, string $markdown): void {
    try {
      $directory = 'public://nyx_content_sync';
      $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      $this->fileSystem->saveData($markdown, "$directory/$content_id.md", FileSystemInterface::EXISTS_REPLACE);
    }
    catch (\Exception $e) {
      // Falha no debug não deve bloquear sincronização
      $this->logger->warning('Falha ao salvar arquivo debug: @msg', ['@msg' => $e->getMessage()]);
    }
  }

}
