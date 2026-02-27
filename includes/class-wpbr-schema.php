<?php
if (!defined('ABSPATH')) {
    exit;
}

class WPBR_Schema {

    /**
     * Generate OpenAPI 3.0 schema
     */
    public function generate() {
        return array(
            'openapi' => '3.0.0',
            'info'    => array(
                'title'       => 'WP Backup Restorer AI API',
                'description' => 'REST API para leitura e modificação de templates Elementor no WordPress. Permite trocar textos, imagens, seções e widgets.',
                'version'     => WPBR_VERSION,
            ),
            'servers' => array(
                array('url' => rest_url(WPBR_API_NAMESPACE)),
            ),
            'security' => array(
                array('ApiKeyAuth' => array()),
                array('BasicAuth' => array()),
            ),
            'components' => array(
                'securitySchemes' => array(
                    'ApiKeyAuth' => array(
                        'type' => 'apiKey',
                        'in'   => 'header',
                        'name' => 'X-WPBR-API-Key',
                    ),
                    'BasicAuth' => array(
                        'type'   => 'http',
                        'scheme' => 'basic',
                    ),
                ),
                'schemas' => $this->get_schemas(),
            ),
            'paths' => $this->get_paths(),
        );
    }

    private function get_schemas() {
        return array(
            'PageSummary' => array(
                'type'       => 'object',
                'properties' => array(
                    'id'            => array('type' => 'integer'),
                    'title'         => array('type' => 'string'),
                    'post_type'     => array('type' => 'string'),
                    'status'        => array('type' => 'string'),
                    'permalink'     => array('type' => 'string', 'format' => 'uri'),
                    'widget_count'  => array('type' => 'integer'),
                    'last_modified' => array('type' => 'string', 'format' => 'date-time'),
                ),
            ),
            'Widget' => array(
                'type'       => 'object',
                'properties' => array(
                    'id'         => array('type' => 'string', 'description' => 'ID hex único do widget'),
                    'elType'     => array('type' => 'string', 'enum' => array('widget', 'section', 'column', 'container')),
                    'widgetType' => array('type' => 'string', 'description' => 'Tipo: heading, text-editor, image, button, icon-box, counter, form, etc.'),
                    'settings'   => array('type' => 'object', 'additionalProperties' => true),
                ),
            ),
            'TreeNode' => array(
                'type'       => 'object',
                'properties' => array(
                    'id'       => array('type' => 'string'),
                    'elType'   => array('type' => 'string'),
                    'widgetType' => array('type' => 'string'),
                    'settings' => array('type' => 'object', 'description' => 'Full widget settings (all Elementor properties). Use ?summary=true for a lightweight version with only key content fields.'),
                    'children' => array('type' => 'array', 'items' => array('$ref' => '#/components/schemas/TreeNode')),
                ),
            ),
            'MediaResult' => array(
                'type'       => 'object',
                'properties' => array(
                    'id'    => array('type' => 'integer', 'description' => 'WordPress attachment ID'),
                    'url'   => array('type' => 'string', 'format' => 'uri'),
                    'sizes' => array('type' => 'object', 'description' => 'Tamanhos disponíveis da imagem'),
                ),
            ),
            'BulkUpdateItem' => array(
                'type'       => 'object',
                'required'   => array('widget_id', 'settings'),
                'properties' => array(
                    'widget_id' => array('type' => 'string'),
                    'settings'  => array('type' => 'object', 'additionalProperties' => true),
                ),
            ),
        );
    }

    private function get_paths() {
        return array(
            '/pages' => array(
                'get' => array(
                    'operationId' => 'listPages',
                    'summary'     => 'Listar todas as páginas/templates Elementor',
                    'responses'   => array(
                        '200' => array(
                            'description' => 'Lista de páginas',
                            'content'     => array('application/json' => array(
                                'schema' => array('type' => 'array', 'items' => array('$ref' => '#/components/schemas/PageSummary')),
                            )),
                        ),
                    ),
                ),
            ),
            '/pages/{id}' => array(
                'get' => array(
                    'operationId' => 'getPageStructure',
                    'summary'     => 'Ver estrutura completa da página com árvore de widgets e settings completas',
                    'parameters'  => array(
                        array('name' => 'id', 'in' => 'path', 'required' => true, 'schema' => array('type' => 'integer'), 'description' => 'ID da página WordPress'),
                        array('name' => 'summary', 'in' => 'query', 'required' => false, 'schema' => array('type' => 'boolean', 'default' => false), 'description' => 'If true, return only key content fields instead of full settings. Default: false (full settings).'),
                    ),
                    'responses' => array(
                        '200' => array('description' => 'Estrutura da página', 'content' => array('application/json' => array('schema' => array('type' => 'object')))),
                        '404' => array('description' => 'Página não encontrada'),
                    ),
                ),
            ),
            '/pages/{id}/widgets' => array(
                'get' => array(
                    'operationId' => 'listWidgets',
                    'summary'     => 'Listar todos os widgets de uma página (lista plana com settings completas)',
                    'parameters'  => array(
                        array('name' => 'id', 'in' => 'path', 'required' => true, 'schema' => array('type' => 'integer')),
                        array('name' => 'summary', 'in' => 'query', 'required' => false, 'schema' => array('type' => 'boolean', 'default' => false), 'description' => 'If true, return only key content fields instead of full settings. Default: false (full settings).'),
                    ),
                    'responses' => array(
                        '200' => array('description' => 'Lista de widgets'),
                    ),
                ),
                'post' => array(
                    'operationId' => 'addWidget',
                    'summary'     => 'Adicionar novo widget a uma seção/coluna',
                    'parameters'  => array(
                        array('name' => 'id', 'in' => 'path', 'required' => true, 'schema' => array('type' => 'integer')),
                    ),
                    'requestBody' => array(
                        'required' => true,
                        'content'  => array('application/json' => array(
                            'schema' => array(
                                'type'       => 'object',
                                'required'   => array('target_id', 'widget'),
                                'properties' => array(
                                    'target_id' => array('type' => 'string', 'description' => 'ID da seção/coluna/container destino'),
                                    'position'  => array('type' => 'string', 'default' => 'end', 'description' => '"start", "end", ou "after:{widget_id}"'),
                                    'widget'    => array(
                                        'type'       => 'object',
                                        'required'   => array('widgetType'),
                                        'properties' => array(
                                            'widgetType' => array('type' => 'string', 'description' => 'Tipo: heading, text-editor, image, button, etc.'),
                                            'settings'   => array('type' => 'object', 'additionalProperties' => true),
                                        ),
                                    ),
                                ),
                            ),
                        )),
                    ),
                    'responses' => array(
                        '200' => array('description' => 'Widget adicionado'),
                    ),
                ),
            ),
            '/pages/{id}/widgets/{widget_id}' => array(
                'get' => array(
                    'operationId' => 'getWidget',
                    'summary'     => 'Ver settings completas de um widget específico',
                    'parameters'  => array(
                        array('name' => 'id', 'in' => 'path', 'required' => true, 'schema' => array('type' => 'integer')),
                        array('name' => 'widget_id', 'in' => 'path', 'required' => true, 'schema' => array('type' => 'string')),
                    ),
                    'responses' => array(
                        '200' => array('description' => 'Widget completo', 'content' => array('application/json' => array('schema' => array('$ref' => '#/components/schemas/Widget')))),
                        '404' => array('description' => 'Widget não encontrado'),
                    ),
                ),
                'put' => array(
                    'operationId' => 'updateWidget',
                    'summary'     => 'Atualizar settings de um widget (deep merge - só altera chaves enviadas)',
                    'description' => 'Exemplos: {"title": "Novo Título"} para heading, {"editor": "<p>Novo texto</p>"} para text-editor, {"image": {"url": "https://...", "id": 123}} para image',
                    'parameters'  => array(
                        array('name' => 'id', 'in' => 'path', 'required' => true, 'schema' => array('type' => 'integer')),
                        array('name' => 'widget_id', 'in' => 'path', 'required' => true, 'schema' => array('type' => 'string')),
                    ),
                    'requestBody' => array(
                        'required' => true,
                        'content'  => array('application/json' => array(
                            'schema' => array('type' => 'object', 'additionalProperties' => true, 'description' => 'Settings para merge no widget'),
                        )),
                    ),
                    'responses' => array(
                        '200' => array('description' => 'Widget atualizado'),
                        '404' => array('description' => 'Widget não encontrado'),
                    ),
                ),
                'delete' => array(
                    'operationId' => 'deleteWidget',
                    'summary'     => 'Remover um widget da página',
                    'parameters'  => array(
                        array('name' => 'id', 'in' => 'path', 'required' => true, 'schema' => array('type' => 'integer')),
                        array('name' => 'widget_id', 'in' => 'path', 'required' => true, 'schema' => array('type' => 'string')),
                    ),
                    'responses' => array(
                        '200' => array('description' => 'Widget removido'),
                    ),
                ),
            ),
            '/pages/{id}/sections/{section_id}' => array(
                'put' => array(
                    'operationId' => 'updateSection',
                    'summary'     => 'Atualizar settings de uma seção (background, padding, etc.)',
                    'parameters'  => array(
                        array('name' => 'id', 'in' => 'path', 'required' => true, 'schema' => array('type' => 'integer')),
                        array('name' => 'section_id', 'in' => 'path', 'required' => true, 'schema' => array('type' => 'string')),
                    ),
                    'requestBody' => array(
                        'required' => true,
                        'content'  => array('application/json' => array(
                            'schema' => array('type' => 'object', 'additionalProperties' => true),
                        )),
                    ),
                    'responses' => array(
                        '200' => array('description' => 'Seção atualizada'),
                    ),
                ),
            ),
            '/pages/{id}/bulk-update' => array(
                'post' => array(
                    'operationId' => 'bulkUpdateWidgets',
                    'summary'     => 'Atualizar múltiplos widgets de uma vez (1 escrita no DB)',
                    'parameters'  => array(
                        array('name' => 'id', 'in' => 'path', 'required' => true, 'schema' => array('type' => 'integer')),
                    ),
                    'requestBody' => array(
                        'required' => true,
                        'content'  => array('application/json' => array(
                            'schema' => array(
                                'type'       => 'object',
                                'required'   => array('updates'),
                                'properties' => array(
                                    'updates' => array('type' => 'array', 'items' => array('$ref' => '#/components/schemas/BulkUpdateItem')),
                                ),
                            ),
                        )),
                    ),
                    'responses' => array(
                        '200' => array('description' => 'Resultado do bulk update'),
                    ),
                ),
            ),
            '/media/upload' => array(
                'post' => array(
                    'operationId' => 'uploadMediaBase64',
                    'summary'     => 'Upload de imagem via base64 para a Media Library',
                    'requestBody' => array(
                        'required' => true,
                        'content'  => array('application/json' => array(
                            'schema' => array(
                                'type'       => 'object',
                                'required'   => array('data'),
                                'properties' => array(
                                    'data'     => array('type' => 'string', 'description' => 'Imagem em base64'),
                                    'filename' => array('type' => 'string', 'description' => 'Nome do arquivo (opcional)'),
                                ),
                            ),
                        )),
                    ),
                    'responses' => array(
                        '201' => array('description' => 'Imagem uploaded', 'content' => array('application/json' => array('schema' => array('$ref' => '#/components/schemas/MediaResult')))),
                    ),
                ),
            ),
            '/media/upload-from-url' => array(
                'post' => array(
                    'operationId' => 'uploadMediaFromUrl',
                    'summary'     => 'Download de imagem de URL e adiciona à Media Library',
                    'requestBody' => array(
                        'required' => true,
                        'content'  => array('application/json' => array(
                            'schema' => array(
                                'type'       => 'object',
                                'required'   => array('url'),
                                'properties' => array(
                                    'url' => array('type' => 'string', 'format' => 'uri', 'description' => 'URL da imagem'),
                                ),
                            ),
                        )),
                    ),
                    'responses' => array(
                        '201' => array('description' => 'Imagem uploaded', 'content' => array('application/json' => array('schema' => array('$ref' => '#/components/schemas/MediaResult')))),
                    ),
                ),
            ),
            '/backup/upload' => array(
                'post' => array(
                    'operationId' => 'uploadBackup',
                    'summary'     => 'Upload de backup WPVivid (.zip) via multipart ou URL',
                    'description' => 'Envie o ZIP via multipart/form-data (campo "file") OU JSON {"url": "https://..."}. Retorna componentes encontrados.',
                    'requestBody' => array(
                        'required' => true,
                        'content'  => array(
                            'multipart/form-data' => array(
                                'schema' => array(
                                    'type'       => 'object',
                                    'properties' => array(
                                        'file' => array('type' => 'string', 'format' => 'binary', 'description' => 'Arquivo ZIP do backup WPVivid'),
                                    ),
                                ),
                            ),
                            'application/json' => array(
                                'schema' => array(
                                    'type'       => 'object',
                                    'properties' => array(
                                        'url' => array('type' => 'string', 'format' => 'uri', 'description' => 'URL para baixar o ZIP'),
                                    ),
                                ),
                            ),
                        ),
                    ),
                    'responses' => array(
                        '200' => array('description' => 'Backup analisado, pronto para restore'),
                    ),
                ),
            ),
            '/backup/restore' => array(
                'post' => array(
                    'operationId' => 'restoreBackup',
                    'summary'     => 'Restaurar o backup previamente enviado',
                    'description' => 'Restaura os componentes selecionados. Se não especificar components, restaura todos encontrados.',
                    'requestBody' => array(
                        'required' => false,
                        'content'  => array('application/json' => array(
                            'schema' => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'components'   => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'Componentes: database, themes, plugins, uploads, content. Default: todos.'),
                                    'replace_urls' => array('type' => 'boolean', 'default' => true, 'description' => 'Trocar URLs do site de origem pelo atual'),
                                    'skip_users'   => array('type' => 'boolean', 'default' => true, 'description' => 'Não sobrescrever tabela de usuários'),
                                    'overwrite'    => array('type' => 'boolean', 'default' => true, 'description' => 'Sobrescrever arquivos existentes'),
                                ),
                            ),
                        )),
                    ),
                    'responses' => array(
                        '200' => array('description' => 'Restore concluído com resultado detalhado'),
                    ),
                ),
            ),
            '/backup/status' => array(
                'get' => array(
                    'operationId' => 'getBackupStatus',
                    'summary'     => 'Verificar status do restore (idle, pending, running, completed)',
                    'responses'   => array(
                        '200' => array('description' => 'Status atual do processo'),
                    ),
                ),
            ),
            '/schema' => array(
                'get' => array(
                    'operationId' => 'getOpenApiSchema',
                    'summary'     => 'Schema OpenAPI 3.0 desta API (para GPT Actions)',
                    'security'    => array(),
                    'responses'   => array(
                        '200' => array('description' => 'OpenAPI 3.0 JSON schema'),
                    ),
                ),
            ),
        );
    }
}
