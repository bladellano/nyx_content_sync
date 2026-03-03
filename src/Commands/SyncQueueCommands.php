<?php

namespace Drupal\nyx_content_sync\Commands;

use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\nyx_content_sync\Service\QueueManagerService;
use Drupal\nyx_content_sync\Service\SyncManagerService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Comandos Drush para gerenciar fila de sincronização.
 */
class SyncQueueCommands extends DrushCommands {

  /**
   * Queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * Queue worker manager.
   *
   * @var \Drupal\Core\Queue\QueueWorkerManagerInterface
   */
  protected $queueWorkerManager;

  /**
   * Queue manager service.
   *
   * @var \Drupal\nyx_content_sync\Service\QueueManagerService
   */
  protected $queueManager;

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
   * Construtor.
   */
  public function __construct(
    QueueFactory $queue_factory,
    QueueWorkerManagerInterface $queue_worker_manager,
    QueueManagerService $queue_manager,
    SyncManagerService $sync_manager,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    parent::__construct();
    $this->queueFactory = $queue_factory;
    $this->queueWorkerManager = $queue_worker_manager;
    $this->queueManager = $queue_manager;
    $this->syncManager = $sync_manager;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Processa fila de sincronização.
   *
   * @command nyx:sync-queue:process
   * @aliases nyx-sync-process
   * @usage nyx:sync-queue:process
   *   Processa todos os itens da fila de sincronização.
   * @usage nyx:sync-queue:process --limit=10
   *   Processa até 10 itens da fila.
   * @option limit
   *   Número máximo de itens a processar. 0 = todos.
   */
  public function processQueue($options = ['limit' => 0]) {
    $queue_name = QueueManagerService::QUEUE_NAME;
    $queue = $this->queueFactory->get($queue_name);
    $queue_worker = $this->queueWorkerManager->createInstance($queue_name);

    $limit = (int) $options['limit'];
    $count = 0;
    $errors = 0;

    $total = $queue->numberOfItems();
    $this->output()->writeln("Processando fila: {$total} itens pendentes");

    while (($limit === 0 || $count < $limit) && ($item = $queue->claimItem())) {
      try {
        $count++;
        $this->output()->writeln("Processando item {$count}...");

        $queue_worker->processItem($item->data);
        $queue->deleteItem($item);

        $this->logger()->success("Item processado com sucesso");
      }
      catch (SuspendQueueException $e) {
        $queue->releaseItem($item);
        $this->logger()->warning("Fila suspensa: {$e->getMessage()}");
        break;
      }
      catch (\Exception $e) {
        $errors++;
        $queue->deleteItem($item);
        $this->logger()->error("Erro ao processar item: {$e->getMessage()}");
      }
    }

    $remaining = $queue->numberOfItems();
    $this->output()->writeln("");
    $this->output()->writeln("Processamento concluído:");
    $this->output()->writeln("  - Processados: {$count}");
    $this->output()->writeln("  - Erros: {$errors}");
    $this->output()->writeln("  - Restantes: {$remaining}");
  }

  /**
   * Mostra status da fila.
   *
   * @command nyx:sync-queue:status
   * @aliases nyx-sync-status
   * @usage nyx:sync-queue:status
   *   Mostra quantos itens estão na fila.
   */
  public function queueStatus() {
    $size = $this->queueManager->getQueueSize();
    $this->output()->writeln("Itens na fila de sincronização: {$size}");

    $config = \Drupal::config('nyx_content_sync.settings');
    $async_mode = $config->get('async_mode') ?: FALSE;
    $mode = $async_mode ? 'Assíncrono (fila habilitada)' : 'Síncrono (fila desabilitada)';
    $this->output()->writeln("Modo de sincronização: {$mode}");
  }

  /**
   * Limpa toda a fila.
   *
   * @command nyx:sync-queue:clear
   * @aliases nyx-sync-clear
   * @usage nyx:sync-queue:clear
   *   Remove todos os itens da fila de sincronização.
   */
  public function clearQueue() {
    if ($this->io()->confirm('Tem certeza que deseja limpar toda a fila?')) {
      if ($this->queueManager->clearQueue()) {
        $this->logger()->success('Fila limpa com sucesso');
      }
      else {
        $this->logger()->error('Erro ao limpar fila');
      }
    }
  }

  /**
   * Sincroniza todos os nodes publicados de um tipo de conteúdo.
   *
   * @command nyx:sync-all
   * @aliases nyx-sync-bulk
   * @param string $content_type Tipo de conteúdo (opcional, sincroniza todos os configurados se omitido)
   * @option limit Número máximo de nodes a sincronizar
   * @option batch-size Tamanho do lote (pausa entre lotes para não sobrecarregar)
   * @usage nyx:sync-all
   *   Sincroniza todos os tipos de conteúdo configurados.
   * @usage nyx:sync-all faq
   *   Sincroniza todos os FAQs publicados.
   * @usage nyx:sync-all faq --limit=50
   *   Sincroniza até 50 FAQs publicados.
   * @usage nyx:sync-all faq --batch-size=20
   *   Sincroniza FAQs em lotes de 20 (pausa de 2s entre lotes).
   */
  public function syncAll($content_type = NULL, $options = ['limit' => 0, 'batch-size' => 50]) {
    $limit = (int) $options['limit'];
    $batch_size = (int) $options['batch-size'];

    // Se tipo específico não foi informado, sincroniza todos os configurados
    if (empty($content_type)) {
      $mappings = $this->syncManager->getContentTypeMappings();
      if (empty($mappings)) {
        $this->logger()->error('Nenhum tipo de conteúdo configurado para sincronização');
        return;
      }

      $this->output()->writeln("Sincronizando " . count($mappings) . " tipos de conteúdo configurados...\n");

      foreach ($mappings as $type => $store) {
        $this->syncContentType($type, $limit, $batch_size);
        $this->output()->writeln("");
      }

      $this->logger()->success('Sincronização bulk completa!');
      return;
    }

    // Sincroniza tipo específico
    if (!$this->syncManager->isContentTypeEnabled($content_type)) {
      $this->logger()->error("Tipo de conteúdo '{$content_type}' não está configurado para sincronização");
      return;
    }

    $this->syncContentType($content_type, $limit, $batch_size);
    $this->logger()->success('Sincronização completa!');
  }

  /**
   * Sincroniza todos os nodes de um tipo de conteúdo.
   */
  protected function syncContentType(string $content_type, int $limit, int $batch_size): void {
    $this->output()->writeln("<info>Sincronizando tipo: {$content_type}</info>");

    // Busca nodes publicados
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', $content_type)
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->sort('created', 'ASC');

    if ($limit > 0) {
      $query->range(0, $limit);
    }

    $nids = $query->execute();
    $total = count($nids);

    if ($total === 0) {
      $this->output()->writeln("  Nenhum node publicado encontrado");
      return;
    }

    $this->output()->writeln("  Total de nodes: {$total}");

    $success = 0;
    $errors = 0;
    $processed = 0;

    // Processa em lotes
    $chunks = array_chunk($nids, $batch_size);

    foreach ($chunks as $chunk_index => $chunk) {
      $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($chunk);

      foreach ($nodes as $node) {
        // Verifica se é NodeInterface
        if (!$node instanceof \Drupal\node\NodeInterface) {
          continue;
        }

        $processed++;

        try {
          if ($this->syncManager->syncContent($node)) {
            $success++;
            $this->output()->write(".");
          }
          else {
            $errors++;
            $this->output()->write("E");
          }
        }
        catch (\Exception $e) {
          $errors++;
          $this->output()->write("E");
          $this->logger()->warning("Erro node {$node->id()}: {$e->getMessage()}");
        }

        // Progress a cada 10 nodes
        if ($processed % 10 === 0) {
          $this->output()->write(" [{$processed}/{$total}]");
        }
      }

      // Pausa entre lotes para não sobrecarregar (exceto no último)
      if ($chunk_index < count($chunks) - 1) {
        sleep(2);
      }
    }

    $this->output()->writeln("");
    $this->output()->writeln("  <info>✓ Sucesso: {$success} | ✗ Erros: {$errors}</info>");
  }

}
