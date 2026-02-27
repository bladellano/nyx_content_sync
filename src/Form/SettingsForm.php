<?php

namespace Drupal\nyx_content_sync\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\NodeType;

/**
 * Formulário de configuração do NyxContentSync.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['nyx_content_sync.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'nyx_content_sync_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('nyx_content_sync.settings');

    $form['connection'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Conexão com Nyx-Index-Hub'),
      '#collapsible' => FALSE,
    ];

    $form['connection']['hub_url'] = [
      '#type' => 'url',
      '#title' => $this->t('URL do Hub'),
      '#default_value' => $config->get('hub_url') ?: getenv('NYX_HUB_URL'),
      '#description' => $this->t('URL base do Nyx-Index-Hub. Pode ser configurado via variável de ambiente NYX_HUB_URL.'),
      '#required' => FALSE,
      '#placeholder' => 'http://nyx-ai.docker.local',
    ];

    $form['connection']['group_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Group Key'),
      '#default_value' => $config->get('group_key') ?: getenv('NYX_GROUP_KEY'),
      '#description' => $this->t('Chave UUID do grupo no Nyx-Index-Hub. Pode ser configurado via variável de ambiente NYX_GROUP_KEY.'),
      '#required' => FALSE,
      '#placeholder' => 'c2e510ab-fe3f-45e7-a3de-cf3680562d83',
    ];

    $form['connection']['async_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Modo Assíncrono'),
      '#default_value' => $config->get('async_mode') ?: FALSE,
      '#description' => $this->t('Quando habilitado, a sincronização é processada em background via fila. Recomendado para evitar timeouts em sites com muito conteúdo. A fila é processada pelo cron.'),
    ];

    $form['mappings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Mapeamento de Tipos de Conteúdo'),
      '#collapsible' => FALSE,
    ];

    $form['mappings']['info'] = [
      '#markup' => '<p>' . $this->t('Configure quais tipos de conteúdo devem ser sincronizados e seus respectivos Store Names.') . '</p>',
    ];

    // Obtém tipos de conteúdo disponíveis
    $content_types = NodeType::loadMultiple();
    $content_type_options = [];
    foreach ($content_types as $type) {
      $content_type_options[$type->id()] = $type->label();
    }

    // Carrega mapeamentos existentes
    $mappings = $config->get('content_type_mappings') ?: [];

    // Container para os mapeamentos
    $form['mappings']['content_type_mappings'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Tipo de Conteúdo'),
        $this->t('Store Name'),
        $this->t('Ativo'),
      ],
      '#empty' => $this->t('Nenhum mapeamento configurado.'),
    ];

    // Adiciona linha para cada tipo de conteúdo
    foreach ($content_type_options as $type_id => $type_label) {
      // Busca mapeamento existente
      $existing_mapping = NULL;
      foreach ($mappings as $mapping) {
        if (isset($mapping['content_type']) && $mapping['content_type'] === $type_id) {
          $existing_mapping = $mapping;
          break;
        }
      }

      $form['mappings']['content_type_mappings'][$type_id]['content_type'] = [
        '#type' => 'markup',
        '#markup' => $type_label,
      ];

      $form['mappings']['content_type_mappings'][$type_id]['store_name'] = [
        '#type' => 'textfield',
        '#default_value' => $existing_mapping['store_name'] ?? '',
        '#size' => 60,
        '#placeholder' => 'fileSearchStores/nome-hash',
      ];

      $form['mappings']['content_type_mappings'][$type_id]['enabled'] = [
        '#type' => 'checkbox',
        '#default_value' => !empty($existing_mapping['store_name']),
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['test'] = [
      '#type' => 'submit',
      '#value' => $this->t('Testar Conexão'),
      '#submit' => ['::testConnection'],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Testa conexão com o Hub.
   */
  public function testConnection(array &$form, FormStateInterface $form_state) {
    $hub_url = $form_state->getValue('hub_url');
    $group_key = $form_state->getValue('group_key');

    if (empty($hub_url) || empty($group_key)) {
      $this->messenger()->addError($this->t('Configure URL e Group Key antes de testar.'));
      return;
    }

    try {
      $url = rtrim($hub_url, '/') . '/api/nyx-sync/validate-store';
      $http_client = \Drupal::httpClient();

      $response = $http_client->request('POST', $url, [
        'auth' => [
          getenv('NYX_API_USERNAME') ?: 'api_sync',
          getenv('NYX_API_PASSWORD') ?: '',
        ],
        'json' => [
          'group_key' => $group_key,
          'store_name' => 'test',
        ],
        'timeout' => 10,
      ]);

      if ($response->getStatusCode() === 200) {
        $this->messenger()->addStatus($this->t('Conexão estabelecida com sucesso!'));
      }
      else {
        $this->messenger()->addWarning($this->t('Conexão estabelecida, mas houve um problema na validação.'));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Erro ao conectar: @message', [
        '@message' => $e->getMessage(),
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('nyx_content_sync.settings');

    // Salva configurações de conexão
    $config->set('hub_url', $form_state->getValue('hub_url'));
    $config->set('group_key', $form_state->getValue('group_key'));
    $config->set('async_mode', $form_state->getValue('async_mode'));

    // Processa mapeamentos
    $mappings = [];
    $mappings_table = $form_state->getValue('content_type_mappings');

    foreach ($mappings_table as $type_id => $values) {
      if (!empty($values['enabled']) && !empty($values['store_name'])) {
        $mappings[] = [
          'content_type' => $type_id,
          'store_name' => $values['store_name'],
        ];
      }
    }

    $config->set('content_type_mappings', $mappings);
    $config->save();

    parent::submitForm($form, $form_state);
  }

}
