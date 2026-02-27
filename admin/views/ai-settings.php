<?php if (!defined('ABSPATH')) exit; ?>

<div class="wrap wpbr-ai-settings">
    <h1><?php esc_html_e('AI Editor', 'wp-backup-restorer'); ?></h1>
    <p class="description"><?php esc_html_e('API REST para IA editar templates Elementor.', 'wp-backup-restorer'); ?></p>

    <!-- API Key Section -->
    <div class="wpbr-card">
        <h2><?php esc_html_e('API Key', 'wp-backup-restorer'); ?></h2>
        <p class="description"><?php esc_html_e('Use esta chave no header X-WPBR-API-Key para autenticar requisições.', 'wp-backup-restorer'); ?></p>

        <?php $api_key = get_option('wpbr_api_key', ''); ?>
        <div class="wpbr-key-row">
            <input type="text" id="wpbr-api-key" class="regular-text wpbr-mono"
                   value="<?php echo esc_attr($api_key); ?>" readonly>
            <button type="button" class="button" id="wpbr-copy-key"><?php esc_html_e('Copiar', 'wp-backup-restorer'); ?></button>
            <button type="button" class="button" id="wpbr-regenerate-key"><?php esc_html_e('Regenerar', 'wp-backup-restorer'); ?></button>
        </div>
        <span id="wpbr-copy-msg" class="wpbr-msg" style="display:none;"><?php esc_html_e('Copiado!', 'wp-backup-restorer'); ?></span>
    </div>

    <!-- Endpoints Reference -->
    <div class="wpbr-card">
        <h2><?php esc_html_e('Endpoints Disponíveis', 'wp-backup-restorer'); ?></h2>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Método', 'wp-backup-restorer'); ?></th>
                    <th><?php esc_html_e('Endpoint', 'wp-backup-restorer'); ?></th>
                    <th><?php esc_html_e('Descrição', 'wp-backup-restorer'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr><td><code>GET</code></td><td><code>/pages</code></td><td>Listar páginas Elementor</td></tr>
                <tr><td><code>GET</code></td><td><code>/pages/{id}</code></td><td>Estrutura da página (árvore)</td></tr>
                <tr><td><code>GET</code></td><td><code>/pages/{id}/widgets</code></td><td>Lista plana de widgets</td></tr>
                <tr><td><code>GET</code></td><td><code>/pages/{id}/widgets/{wid}</code></td><td>Settings completas de um widget</td></tr>
                <tr><td><code>PUT</code></td><td><code>/pages/{id}/widgets/{wid}</code></td><td>Atualizar widget (deep merge)</td></tr>
                <tr><td><code>POST</code></td><td><code>/pages/{id}/widgets</code></td><td>Adicionar widget</td></tr>
                <tr><td><code>DELETE</code></td><td><code>/pages/{id}/widgets/{wid}</code></td><td>Remover widget</td></tr>
                <tr><td><code>PUT</code></td><td><code>/pages/{id}/sections/{sid}</code></td><td>Atualizar seção</td></tr>
                <tr><td><code>POST</code></td><td><code>/pages/{id}/bulk-update</code></td><td>Atualizar múltiplos widgets</td></tr>
                <tr><td><code>POST</code></td><td><code>/media/upload</code></td><td>Upload imagem (base64)</td></tr>
                <tr><td><code>POST</code></td><td><code>/media/upload-from-url</code></td><td>Upload imagem de URL</td></tr>
                <tr><td><code>GET</code></td><td><code>/schema</code></td><td>OpenAPI 3.0 schema (público)</td></tr>
            </tbody>
        </table>
    </div>

    <!-- Quick Start -->
    <div class="wpbr-card">
        <h2><?php esc_html_e('Como Usar', 'wp-backup-restorer'); ?></h2>

        <h3>1. Testar com cURL</h3>
        <pre class="wpbr-code">curl -H "X-WPBR-API-Key: <?php echo esc_html($api_key); ?>" \
  <?php echo esc_html(rest_url(WPBR_API_NAMESPACE . '/pages')); ?></pre>

        <h3>2. Trocar texto de um heading</h3>
        <pre class="wpbr-code">curl -X PUT \
  -H "X-WPBR-API-Key: <?php echo esc_html($api_key); ?>" \
  -H "Content-Type: application/json" \
  -d '{"title": "Novo Título"}' \
  <?php echo esc_html(rest_url(WPBR_API_NAMESPACE . '/pages/PAGE_ID/widgets/WIDGET_ID')); ?></pre>

        <h3>3. Trocar imagem de um widget</h3>
        <pre class="wpbr-code">curl -X PUT \
  -H "X-WPBR-API-Key: <?php echo esc_html($api_key); ?>" \
  -H "Content-Type: application/json" \
  -d '{"image": {"url": "https://exemplo.com/nova-foto.jpg", "id": ""}}' \
  <?php echo esc_html(rest_url(WPBR_API_NAMESPACE . '/pages/PAGE_ID/widgets/WIDGET_ID')); ?></pre>

        <h3>4. GPT Actions / OpenAPI Schema</h3>
        <p><?php esc_html_e('Cole esta URL no campo de schema do GPT Actions:', 'wp-backup-restorer'); ?></p>
        <pre class="wpbr-code"><?php echo esc_html(rest_url(WPBR_API_NAMESPACE . '/schema')); ?></pre>

        <h3>5. Bulk update (múltiplos widgets de uma vez)</h3>
        <pre class="wpbr-code">curl -X POST \
  -H "X-WPBR-API-Key: <?php echo esc_html($api_key); ?>" \
  -H "Content-Type: application/json" \
  -d '{"updates": [
    {"widget_id": "abc123", "settings": {"title": "Título 1"}},
    {"widget_id": "def456", "settings": {"editor": "&lt;p&gt;Novo texto&lt;/p&gt;"}}
  ]}' \
  <?php echo esc_html(rest_url(WPBR_API_NAMESPACE . '/pages/PAGE_ID/bulk-update')); ?></pre>
    </div>
</div>

<script>
jQuery(function($) {
    // Copy API key
    $('#wpbr-copy-key').on('click', function() {
        var key = $('#wpbr-api-key').val();
        navigator.clipboard.writeText(key).then(function() {
            $('#wpbr-copy-msg').fadeIn(200).delay(1500).fadeOut(200);
        });
    });

    // Regenerate API key
    $('#wpbr-regenerate-key').on('click', function() {
        if (!confirm('<?php echo esc_js(__('Regenerar a API key? A chave anterior parará de funcionar.', 'wp-backup-restorer')); ?>')) {
            return;
        }
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wpbr_regenerate_key',
                nonce: '<?php echo wp_create_nonce('wpbr_regenerate_key'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    $('#wpbr-api-key').val(response.data.key);
                    location.reload();
                }
            }
        });
    });
});
</script>
