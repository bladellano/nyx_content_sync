# Nyx Content Sync

Módulo Drupal para sincronização automática de conteúdo com o Nyx-Index-Hub para indexação via Google Gemini File Search API.

## Características

- Sincronização automática ao criar/atualizar/deletar nodes
- Conversão de conteúdo Drupal para Markdown
- Sincronização em lote por tipo de conteúdo
- Validação de Store Names via Group Key
- Armazenamento local de arquivos Markdown gerados
- Configuração via interface ou variáveis de ambiente

## Instalação

```bash
cd drupal
composer require drupal/nyx_content_sync
drush en nyx_content_sync -y
```

## Configuração

### Variáveis de Ambiente

Adicione no arquivo `.env`:

```bash
NYX_HUB_URL=http://nyx-ai.docker.local
NYX_GROUP_KEY=7c6f9217-5f48-4418-b0f0-784a795b072d
```

### Interface de Configuração

Acesse: **Administração > Configuração > Serviços > Nyx Content Sync Settings**

1. **Hub URL**: URL do servidor Nyx-Index-Hub
2. **Group Key**: UUID do grupo no Hub
3. **Mapeamentos**: Associe tipos de conteúdo aos Store Names

Exemplo de mapeamento:
```
FAQ → fileSearchStores/faq-5dz79bayg0cx
Article → fileSearchStores/article-xyz123abc
```

## Funcionamento

### Sincronização em Lote

O módulo sincroniza TODOS os nodes publicados do mesmo tipo de conteúdo em um único arquivo Markdown consolidado.

**Exemplo**: Se você tem 50 FAQs publicados, todos serão convertidos em um único arquivo `FAQ.md` e enviados juntos para o Hub.

### Fluxo de Operação

1. **Criar/Atualizar Node**
   - Carrega todos os nodes publicados do tipo
   - Converte para Markdown consolidado com índice
   - Salva arquivo em `public://nyx_content_sync/`
   - Envia para Nyx-Index-Hub
   - Hub faz upload para Google Gemini API

2. **Deletar Node**
   - Se ainda existem nodes do tipo: re-sincroniza todos os restantes
   - Se foi o último node: remove o arquivo do Hub

### Formato Markdown Gerado

Cada sincronização gera um arquivo com:

```markdown
# FAQ

**Total de itens:** 10
**Atualizado em:** 2026-02-25 10:30:00

---

## Índice

- [Como funciona?](#node-1)
- [Qual o preço?](#node-2)
...

---

<a id="node-1"></a>

## Como funciona?

**ID:** 1
**Criado:** 2026-02-20 14:00:00

### Pergunta

Como funciona?

### Resposta

Explicação detalhada...

---

<a id="node-2"></a>

## Qual o preço?
...
```

## Estrutura do Módulo

```
nyx_content_sync/
├── config/
│   ├── install/
│   │   └── nyx_content_sync.settings.yml
│   └── schema/
│       └── nyx_content_sync.schema.yml
├── src/
│   ├── Form/
│   │   └── SettingsForm.php
│   └── Service/
│       ├── HubClientService.php       # Comunicação HTTP com Hub
│       ├── MarkdownConverterService.php # Conversão Drupal → Markdown
│       └── SyncManagerService.php      # Orquestração
├── nyx_content_sync.info.yml
├── nyx_content_sync.module             # Hooks de entity
├── nyx_content_sync.routing.yml
├── nyx_content_sync.services.yml
└── nyx_content_sync.links.menu.yml
```

## Serviços

### SyncManagerService

Gerencia o ciclo completo de sincronização.

```php
$sync_manager = \Drupal::service('nyx_content_sync.sync_manager');

// Sincronizar node
$sync_manager->syncContent($node);

// Deletar conteúdo
$sync_manager->deleteContent($node);

// Verificar se tipo está habilitado
if ($sync_manager->isContentTypeEnabled('faq')) {
  // Tipo está mapeado
}
```

### HubClientService

Comunicação HTTP com o Nyx-Index-Hub.

```php
$hub_client = \Drupal::service('nyx_content_sync.hub_client');

// Validar store
$valid = $hub_client->validateStore($group_key, $store_name);

// Enviar conteúdo
$success = $hub_client->uploadContent(
  $group_key,
  $store_name,
  $content_id,
  $markdown,
  $metadata
);

// Deletar conteúdo
$success = $hub_client->deleteContent($group_key, $store_name, $content_id);
```

### MarkdownConverterService

Conversão de nodes para Markdown.

```php
$converter = \Drupal::service('nyx_content_sync.markdown_converter');

// Converter múltiplos nodes
$markdown = $converter->convertMultipleToMarkdown($nodes, $content_type);

// Converter node único
$markdown = $converter->convertToMarkdown($node);
```

**Tipos de campo suportados:**
- text, text_long, text_with_summary
- string, integer, decimal, float
- list_string, list_integer
- entity_reference
- link
- datetime

## API Endpoints (Nyx-Index-Hub)

### POST /api/nyx-sync/validate-store

Valida se Store Name pertence ao Group Key.

```json
// Request
{
  "group_key": "uuid",
  "store_name": "fileSearchStores/xxx"
}

// Response
{
  "valid": true,
  "message": "Store válida"
}
```

### POST /api/nyx-sync/upload

Envia conteúdo para indexação.

```json
// Request
{
  "group_key": "uuid",
  "store_name": "fileSearchStores/xxx",
  "content_id": "content_type_faq",
  "markdown": "# FAQ\n\n...",
  "metadata": {
    "content_type": "faq",
    "total_nodes": 10,
    "node_ids": [1, 2, 3],
    "last_updated": 1708700000
  }
}

// Response
{
  "success": true,
  "message": "Conteúdo enviado com sucesso",
  "file_name": "fileSearchStores/xxx/documents/yyy",
  "replaced_file": "fileSearchStores/xxx/documents/zzz"
}
```

### POST /api/nyx-sync/delete

Remove conteúdo indexado.

```json
// Request
{
  "group_key": "uuid",
  "store_name": "fileSearchStores/xxx",
  "content_id": "content_type_faq"
}

// Response
{
  "success": true,
  "message": "Conteúdo removido com sucesso",
  "deleted_file": "fileSearchStores/xxx/documents/yyy"
}
```

## Arquivos Markdown

Os arquivos gerados são salvos em:

```
drupal/web/sites/default/files/nyx_content_sync/
└── faq_2026-02-25_10-30-00.md
└── article_2026-02-25_10-35-00.md
```

Cada sincronização gera um novo arquivo com timestamp. Útil para:
- Visualizar o conteúdo antes do envio
- Debug de problemas de formatação
- Auditoria do que foi indexado

## Logs

Visualizar logs do módulo:

```bash
# Filtrar apenas logs do módulo
drush watchdog:tail --type=nyx_content_sync

# Logs gerais
drush watchdog:tail
```

**Logs importantes:**
- `Sincronizando X nodes do tipo Y`
- `Arquivo Markdown salvo em: /path/to/file.md`
- `Re-sincronizando X nodes do tipo Y após delete`
- `Deletando último node do tipo X - removendo storage`

## Troubleshooting

### Conteúdo não sincroniza

1. Verifique se tipo de conteúdo está mapeado na configuração
2. Confirme que `NYX_HUB_URL` e `NYX_GROUP_KEY` estão corretos
3. Verifique permissões em `web/sites/default/files/nyx_content_sync/`
4. Consulte logs: `drush watchdog:tail --type=nyx_content_sync`

### Erro de validação de Store

- Confirme que Store Name existe no Hub
- Verifique se UUID do Group Key está correto
- Teste a conexão com o Hub: `curl http://nyx-ai.docker.local/api/nyx-sync/validate-store`

### Timeout no upload

- Aumente `max_execution_time` em `php.ini` (padrão: 300s)
- Verifique conectividade de rede com o Hub
- Reduza quantidade de nodes por tipo de conteúdo

### Arquivo Markdown não é salvo

- Verifique permissões: `chmod 777 web/sites/default/files/nyx_content_sync/`
- Confirme que diretório existe
- Verifique logs para mensagens de erro

## Hooks Implementados

O módulo usa hooks do Drupal para sincronização automática:

```php
// nyx_content_sync.module

function nyx_content_sync_entity_insert(EntityInterface $entity) {
  if ($entity instanceof NodeInterface) {
    $sync_manager = \Drupal::service('nyx_content_sync.sync_manager');
    $sync_manager->syncContent($entity);
  }
}

function nyx_content_sync_entity_update(EntityInterface $entity) {
  if ($entity instanceof NodeInterface) {
    $sync_manager = \Drupal::service('nyx_content_sync.sync_manager');
    $sync_manager->syncContent($entity);
  }
}

function nyx_content_sync_entity_delete(EntityInterface $entity) {
  if ($entity instanceof NodeInterface) {
    $sync_manager = \Drupal::service('nyx_content_sync.sync_manager');
    $sync_manager->deleteContent($entity);
  }
}
```

## Uso Programático

### Sincronizar tipo de conteúdo manualmente

```php
// Carregar todos os nodes do tipo
$node_storage = \Drupal::entityTypeManager()->getStorage('node');
$nodes = $node_storage->loadByProperties([
  'type' => 'faq',
  'status' => 1,
]);

// Pegar qualquer node como referência
$node = reset($nodes);

// Sincronizar
$sync_manager = \Drupal::service('nyx_content_sync.sync_manager');
$success = $sync_manager->syncContent($node);

if ($success) {
  \Drupal::messenger()->addMessage('Sincronização concluída com sucesso.');
}
```

### Verificar configuração

```php
$config = \Drupal::config('nyx_content_sync.settings');
$hub_url = $config->get('hub_url');
$group_key = $config->get('group_key');
$mappings = $config->get('content_type_mappings');

// Ou via ambiente
$hub_url = getenv('NYX_HUB_URL');
$group_key = getenv('NYX_GROUP_KEY');
```

## Limitações Conhecidas

1. **Sincronização síncrona**: Operações longas podem causar timeout. Considere implementar Queue API para produção.
2. **Sem versionamento**: Não há histórico de versões anteriores dos arquivos.
3. **Sem retry**: Falhas não são automaticamente retentadas.
4. **Sem autenticação**: Endpoints do Hub são públicos (`_access: 'TRUE'`).

Para informações sobre melhorias e limitações, consulte `drupal/docs/REFATORACAO-E-MELHORIAS.md`.

## Licença

Mesmo que o projeto principal (Nyx-Index-Hub).
