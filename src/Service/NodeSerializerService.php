<?php

namespace Drupal\nyx_content_sync\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Serviço para serializar nodes em arrays para envio ao Hub.
 */
class NodeSerializerService {

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Construtor.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Serializa node para array.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node a serializar.
   *
   * @return array
   *   Dados do node em formato array.
   */
  public function serializeNode(NodeInterface $node): array {
    $data = [
      'nid' => $node->id(),
      'uuid' => $node->uuid(),
      'title' => $node->getTitle(),
      'bundle' => $node->bundle(),
      'bundle_label' => $this->getContentTypeLabel($node->bundle()),
      'created' => $node->getCreatedTime(),
      'changed' => $node->getChangedTime(),
      'published' => $node->isPublished(),
      'fields' => [],
    ];

    // Processa campos customizados
    foreach ($node->getFieldDefinitions() as $field_name => $field_definition) {
      // Ignora campos do sistema
      if (in_array($field_name, ['nid', 'uuid', 'vid', 'type', 'status', 'uid', 'created', 'changed', 'promote', 'sticky', 'title'])) {
        continue;
      }

      if ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
        $field_value = $this->extractFieldValue($node, $field_name);

        if (!empty($field_value)) {
          $data['fields'][] = [
            'name' => $field_name,
            'label' => $field_definition->getLabel(),
            'type' => $field_definition->getType(),
            'value' => $field_value,
          ];
        }
      }
    }

    return $data;
  }

  /**
   * Serializa múltiplos nodes.
   *
   * @param array $nodes
   *   Array de nodes.
   *
   * @return array
   *   Array de dados serializados.
   */
  public function serializeMultipleNodes(array $nodes): array {
    $serialized = [];
    foreach ($nodes as $node) {
      if ($node instanceof NodeInterface) {
        $serialized[] = $this->serializeNode($node);
      }
    }
    return $serialized;
  }

  /**
   * Obtém label do tipo de conteúdo.
   *
   * @param string $content_type
   *   Machine name do tipo de conteúdo.
   *
   * @return string
   *   Label do tipo de conteúdo.
   */
  protected function getContentTypeLabel(string $content_type): string {
    $node_type = $this->entityTypeManager
      ->getStorage('node_type')
      ->load($content_type);

    return $node_type ? $node_type->label() : ucfirst($content_type);
  }

  /**
   * Extrai valor do campo em formato texto.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node.
   * @param string $field_name
   *   Nome do campo.
   *
   * @return string
   *   Valor do campo.
   */
  protected function extractFieldValue(NodeInterface $node, string $field_name): string {
    $field = $node->get($field_name);
    $field_type = $field->getFieldDefinition()->getType();

    switch ($field_type) {
      case 'text_with_summary':
      case 'text_long':
      case 'text':
        $value = $field->value;
        return $this->htmlToMarkdown($value);

      case 'string':
        return $field->value;

      case 'integer':
      case 'decimal':
      case 'float':
        return (string) $field->value;

      case 'list_string':
      case 'list_integer':
        $items = [];
        foreach ($field as $item) {
          $items[] = $item->value;
        }
        return implode(', ', $items);

      case 'entity_reference':
        $items = [];
        if (!$field->isEmpty()) {
          foreach ($field as $item) {
            $entity = $item->entity;
            if ($entity && method_exists($entity, 'label')) {
              $items[] = $entity->label();
            }
          }
        }
        return implode(', ', $items);

      case 'link':
        $items = [];
        foreach ($field as $item) {
          $items[] = '[' . $item->title . '](' . $item->uri . ')';
        }
        return implode("\n", $items);

      case 'datetime':
        return $field->value;

      default:
        // Tenta renderizar como string
        return strip_tags($field->getString());
    }
  }

  /**
   * Converte HTML básico para Markdown.
   *
   * @param string $html
   *   HTML.
   *
   * @return string
   *   Markdown.
   */
  protected function htmlToMarkdown(string $html): string {
    if (empty($html)) {
      return '';
    }

    $replacements = [
      // Negrito
      '/<(strong|b)[^>]*>(.*?)<\/(strong|b)>/i' => '**$2**',
      // Itálico
      '/<(em|i)[^>]*>(.*?)<\/(em|i)>/i' => '*$2*',
      // Links
      '/<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/i' => '[$2]($1)',
      // Quebras de linha
      '/<br\s*\/?>/i' => "\n",
      '/<p[^>]*>/i' => "\n",
      '/<\/p>/i' => "\n",
      // Listas
      '/<li[^>]*>/i' => "\n- ",
      '/<\/(ul|ol|li)>/i' => '',
      '/<(ul|ol)[^>]*>/i' => "\n",
    ];

    // Headers h1-h4
    for ($i = 1; $i <= 4; $i++) {
      $html = preg_replace('/<h' . $i . '[^>]*>(.*?)<\/h' . $i . '>/i', "\n" . str_repeat('#', $i) . " $1\n", $html);
    }

    $markdown = preg_replace(array_keys($replacements), array_values($replacements), $html);
    $markdown = strip_tags($markdown);
    $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);

    return trim($markdown);
  }

}
