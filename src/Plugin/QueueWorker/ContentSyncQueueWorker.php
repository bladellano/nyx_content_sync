<?php

namespace Drupal\nyx_content_sync\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\nyx_content_sync\Service\SyncManagerService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Processa jobs de sincronização de conteúdo em background.
 *
 * @QueueWorker(
 *   id = "nyx_content_sync_queue",
 *   title = @Translation("Nyx Content Sync Queue Worker"),
 *   cron = {"time" = 60}
 * )
 */
class ContentSyncQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Sync manager service.
   *
   * @var \Drupal\nyx_content_sync\Service\SyncManagerService
   */
  protected $syncManager;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Construtor.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    SyncManagerService $sync_manager,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerInterface $logger
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->syncManager = $sync_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('nyx_content_sync.sync_manager'),
      $container->get('entity_type.manager'),
      $container->get('logger.factory')->get('nyx_content_sync')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    if (!isset($data['operation']) || !isset($data['node_id']) || !isset($data['content_type'])) {
      $this->logger->error('Job de fila inválido: dados obrigatórios ausentes');
      return;
    }

    $operation = $data['operation'];
    $node_id = $data['node_id'];
    $content_type = $data['content_type'];
    $excluded_node = $data['excluded_node'] ?? NULL;

    try {
      // Carrega o node
      $node_storage = $this->entityTypeManager->getStorage('node');
      $node = $node_storage->load($node_id);

      if (!$node) {
        $this->logger->warning('Node @id não encontrado para processar job', ['@id' => $node_id]);
        return;
      }

      // Verifica se é NodeInterface
      if (!$node instanceof \Drupal\node\NodeInterface) {
        $this->logger->error('Entidade @id não é um node válido', ['@id' => $node_id]);
        return;
      }

      // Processa de acordo com a operação
      switch ($operation) {
        case 'sync':
          $this->logger->info('Processando sincronização do node @id (tipo: @type)', [
            '@id' => $node_id,
            '@type' => $content_type,
          ]);
          $this->syncManager->syncContent($node);
          break;

        case 'delete':
          $this->logger->info('Processando delete do node @id (tipo: @type)', [
            '@id' => $node_id,
            '@type' => $content_type,
          ]);
          $this->syncManager->deleteContent($node);
          break;

        default:
          $this->logger->error('Operação desconhecida: @op', ['@op' => $operation]);
          break;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Erro ao processar job de sincronização: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e; // Re-throw para que o job seja marcado como falhado
    }
  }

}
