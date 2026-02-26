<?php

namespace Drupal\nyx_content_sync\Service;

use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;

/**
 * Serviço para gerenciar fila de sincronização.
 */
class QueueManagerService {

  /**
   * Queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * Logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Nome da fila.
   */
  const QUEUE_NAME = 'nyx_content_sync_queue';

  /**
   * Construtor.
   */
  public function __construct(
    QueueFactory $queue_factory,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->queueFactory = $queue_factory;
    $this->logger = $logger_factory->get('nyx_content_sync');
  }

  /**
   * Adiciona job de sincronização na fila.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node a sincronizar.
   *
   * @return bool
   *   TRUE se adicionado com sucesso.
   */
  public function queueSync(NodeInterface $node): bool {
    return $this->addToQueue([
      'operation' => 'sync',
      'node_id' => $node->id(),
      'content_type' => $node->bundle(),
      'title' => $node->label(),
      'timestamp' => time(),
    ]);
  }

  /**
   * Adiciona job de delete na fila.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node a deletar.
   *
   * @return bool
   *   TRUE se adicionado com sucesso.
   */
  public function queueDelete(NodeInterface $node): bool {
    return $this->addToQueue([
      'operation' => 'delete',
      'node_id' => $node->id(),
      'content_type' => $node->bundle(),
      'title' => $node->label(),
      'timestamp' => time(),
    ]);
  }

  /**
   * Adiciona item na fila.
   *
   * @param array $data
   *   Dados do job.
   *
   * @return bool
   *   TRUE se adicionado.
   */
  protected function addToQueue(array $data): bool {
    try {
      $queue = $this->queueFactory->get(self::QUEUE_NAME);
      $queue->createItem($data);

      $this->logger->info('Job adicionado à fila: @op para node @id (@title)', [
        '@op' => $data['operation'],
        '@id' => $data['node_id'],
        '@title' => $data['title'],
      ]);

      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Erro ao adicionar job na fila: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Obtém número de itens na fila.
   *
   * @return int
   *   Número de itens pendentes.
   */
  public function getQueueSize(): int {
    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    return $queue->numberOfItems();
  }

  /**
   * Limpa toda a fila.
   *
   * @return bool
   *   TRUE se limpou com sucesso.
   */
  public function clearQueue(): bool {
    try {
      $queue = $this->queueFactory->get(self::QUEUE_NAME);
      $queue->deleteQueue();

      $this->logger->info('Fila de sincronização limpa');
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Erro ao limpar fila: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

}
