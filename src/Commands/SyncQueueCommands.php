<?php

namespace Drupal\nyx_content_sync\Commands;

use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\nyx_content_sync\Service\QueueManagerService;
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
   * Construtor.
   */
  public function __construct(
    QueueFactory $queue_factory,
    QueueWorkerManagerInterface $queue_worker_manager,
    QueueManagerService $queue_manager
  ) {
    parent::__construct();
    $this->queueFactory = $queue_factory;
    $this->queueWorkerManager = $queue_worker_manager;
    $this->queueManager = $queue_manager;
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

}
