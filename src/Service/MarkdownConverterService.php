<?php

namespace Drupal\nyx_content_sync\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;

/**
 * Serviço para converter nodes Drupal em Markdown.
 */
class MarkdownConverterService {

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Construtor.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    RendererInterface $renderer
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
  }

  /**
   * Converte node para Markdown.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node a converter.
   *
   * @return string
   *   Conteúdo em formato Markdown.
   */
  public function convertToMarkdown(NodeInterface $node): string {
    $markdown = [];

    // Título principal
    $markdown[] = '# ' . $node->getTitle();
    $markdown[] = '';

    // Metadados básicos
    $markdown[] = '**Tipo:** ' . $node->bundle();
    $markdown[] = '**Criado:** ' . date('Y-m-d H:i:s', $node->getCreatedTime());

    if ($node->getChangedTime() !== $node->getCreatedTime()) {
      $markdown[] = '**Atualizado:** ' . date('Y-m-d H:i:s', $node->getChangedTime());
    }

    $markdown[] = '';
    $markdown[] = '---';
    $markdown[] = '';

    // Processa campos
    $view_builder = $this->entityTypeManager->getViewBuilder('node');
    $build = $view_builder->view($node, 'full');

    foreach ($node->getFieldDefinitions() as $field_name => $field_definition) {
      // Ignora campos do sistema
      if (in_array($field_name, ['nid', 'uuid', 'vid', 'type', 'status', 'uid', 'created', 'changed', 'promote', 'sticky'])) {
        continue;
      }

      if ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
        $field_label = $field_definition->getLabel();
        $field_value = $this->extractFieldValue($node, $field_name);

        if (!empty($field_value)) {
          $markdown[] = '## ' . $field_label;
          $markdown[] = '';
          $markdown[] = $field_value;
          $markdown[] = '';
        }
      }
    }

    return implode("\n", $markdown);
  }

  /**
   * Converte múltiplos nodes para um único arquivo Markdown.
   *
   * @param array $nodes
   *   Array de nodes a converter.
   * @param string $content_type
   *   Tipo de conteúdo.
   *
   * @return string
   *   Conteúdo consolidado em formato Markdown.
   */
  public function convertMultipleToMarkdown(array $nodes, string $content_type): string {
    $markdown = [];

    // Cabeçalho principal do documento
    $content_type_label = $this->getContentTypeLabel($content_type);
    $markdown[] = '# ' . $content_type_label;
    $markdown[] = '';
    $markdown[] = '**Total de itens:** ' . count($nodes);
    $markdown[] = '**Atualizado em:** ' . date('Y-m-d H:i:s');
    $markdown[] = '';
    $markdown[] = '---';
    $markdown[] = '';

    // Índice
    $markdown[] = '## Índice';
    $markdown[] = '';
    foreach ($nodes as $node) {
      $markdown[] = '- [' . $node->getTitle() . '](#node-' . $node->id() . ')';
    }
    $markdown[] = '';
    $markdown[] = '---';
    $markdown[] = '';

    // Conteúdo de cada node
    foreach ($nodes as $index => $node) {
      if ($index > 0) {
        $markdown[] = '';
        $markdown[] = '---';
        $markdown[] = '';
      }

      // Título com âncora
      $markdown[] = '<a id="node-' . $node->id() . '"></a>';
      $markdown[] = '';
      $markdown[] = '## ' . $node->getTitle();
      $markdown[] = '';

      // Metadados
      $markdown[] = '**ID:** ' . $node->id();
      $markdown[] = '**Criado:** ' . date('Y-m-d H:i:s', $node->getCreatedTime());

      if ($node->getChangedTime() !== $node->getCreatedTime()) {
        $markdown[] = '**Atualizado:** ' . date('Y-m-d H:i:s', $node->getChangedTime());
      }

      $markdown[] = '';

      // Campos do node
      foreach ($node->getFieldDefinitions() as $field_name => $field_definition) {
        // Ignora campos do sistema
        if (in_array($field_name, ['nid', 'uuid', 'vid', 'type', 'status', 'uid', 'created', 'changed', 'promote', 'sticky', 'title'])) {
          continue;
        }

        if ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
          $field_label = $field_definition->getLabel();
          $field_value = $this->extractFieldValue($node, $field_name);

          if (!empty($field_value)) {
            $markdown[] = '### ' . $field_label;
            $markdown[] = '';
            $markdown[] = $field_value;
            $markdown[] = '';
          }
        }
      }
    }

    return implode("\n", $markdown);
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
    $node_type = \Drupal::entityTypeManager()
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
