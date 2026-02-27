<?php
if (!defined('ABSPATH')) exit;

$pending = get_transient('wpbr_pending_restore');
if (!$pending) {
    echo '<div class="notice notice-error"><p>Sessão expirada. Faça upload novamente.</p></div>';
    echo '<a href="' . esc_url(admin_url('admin.php?page=wp-backup-restorer')) . '" class="button">Voltar</a>';
    return;
}

$source = $pending['source_info'];
$components = $pending['components'];
global $wpdb;
$current_prefix = $wpdb->prefix;
$current_url = home_url();

$needs_url_replace = !empty($source['site_url']) && rtrim($source['site_url'], '/') !== rtrim($current_url, '/');
$needs_prefix_change = !empty($source['table_prefix']) && $source['table_prefix'] !== $current_prefix;
?>

<div class="wpbr-card">
    <h2>Análise do Backup</h2>

    <!-- Source vs Target comparison -->
    <table class="widefat striped wpbr-compare-table">
        <thead>
            <tr>
                <th></th>
                <th>Backup (Origem)</th>
                <th>Site Atual (Destino)</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>URL do Site</strong></td>
                <td><code><?php echo esc_html($source['site_url'] ?: 'Não detectado'); ?></code></td>
                <td><code><?php echo esc_html($current_url); ?></code></td>
                <td>
                    <?php if ($needs_url_replace): ?>
                        <span class="wpbr-badge wpbr-badge-warn">Diferente - será substituído</span>
                    <?php else: ?>
                        <span class="wpbr-badge wpbr-badge-ok">Igual</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><strong>Prefixo de Tabelas</strong></td>
                <td><code><?php echo esc_html($source['table_prefix'] ?: 'Não detectado'); ?></code></td>
                <td><code><?php echo esc_html($current_prefix); ?></code></td>
                <td>
                    <?php if ($needs_prefix_change): ?>
                        <span class="wpbr-badge wpbr-badge-warn">Diferente - será convertido</span>
                    <?php else: ?>
                        <span class="wpbr-badge wpbr-badge-ok">Igual</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><strong>WordPress</strong></td>
                <td><?php echo esc_html($source['wp_version'] ?: '?'); ?></td>
                <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                <td>-</td>
            </tr>
        </tbody>
    </table>
</div>

<form method="post">
    <?php wp_nonce_field('wpbr_restore', 'wpbr_restore_nonce'); ?>

    <div class="wpbr-card">
        <h2>Componentes para Restaurar</h2>
        <p class="description">Selecione quais partes do backup deseja restaurar:</p>

        <div class="wpbr-components">
            <?php if (isset($components['database'])): ?>
            <label class="wpbr-component-option">
                <input type="checkbox" name="wpbr_components[]" value="database" checked>
                <span class="wpbr-component-card">
                    <span class="dashicons dashicons-database"></span>
                    <strong>Banco de Dados</strong>
                    <span class="description">Todas as tabelas, posts, páginas, configurações, dados do Elementor</span>
                </span>
            </label>
            <?php endif; ?>

            <?php if (isset($components['themes'])): ?>
            <label class="wpbr-component-option">
                <input type="checkbox" name="wpbr_components[]" value="themes" checked>
                <span class="wpbr-component-card">
                    <span class="dashicons dashicons-admin-appearance"></span>
                    <strong>Temas</strong>
                    <span class="description">
                        <?php
                        $pkg = $pending['package_info'];
                        if (!empty($pkg['child_file'])) {
                            foreach ($pkg['child_file'] as $key => $info) {
                                if (($info['file_type'] ?? '') === 'themes' && !empty($info['themes'])) {
                                    echo esc_html(implode(', ', array_keys($info['themes'])));
                                }
                            }
                        }
                        ?>
                    </span>
                </span>
            </label>
            <?php endif; ?>

            <?php if (isset($components['plugins'])): ?>
            <label class="wpbr-component-option">
                <input type="checkbox" name="wpbr_components[]" value="plugins" checked>
                <span class="wpbr-component-card">
                    <span class="dashicons dashicons-admin-plugins"></span>
                    <strong>Plugins</strong>
                    <span class="description">
                        <?php
                        if (!empty($pkg['child_file'])) {
                            foreach ($pkg['child_file'] as $key => $info) {
                                if (($info['file_type'] ?? '') === 'plugin' && !empty($info['plugin'])) {
                                    echo esc_html(implode(', ', array_keys($info['plugin'])));
                                }
                            }
                        }
                        ?>
                    </span>
                </span>
            </label>
            <?php endif; ?>

            <?php if (isset($components['uploads'])): ?>
            <label class="wpbr-component-option">
                <input type="checkbox" name="wpbr_components[]" value="uploads" checked>
                <span class="wpbr-component-card">
                    <span class="dashicons dashicons-format-image"></span>
                    <strong>Uploads (Mídia)</strong>
                    <span class="description">Imagens, vídeos, documentos da biblioteca de mídia</span>
                </span>
            </label>
            <?php endif; ?>

            <?php if (isset($components['content'])): ?>
            <label class="wpbr-component-option">
                <input type="checkbox" name="wpbr_components[]" value="content">
                <span class="wpbr-component-card">
                    <span class="dashicons dashicons-portfolio"></span>
                    <strong>Outros (wp-content)</strong>
                    <span class="description">Outros arquivos de wp-content</span>
                </span>
            </label>
            <?php endif; ?>
        </div>
    </div>

    <div class="wpbr-card">
        <h2>Opções de Restauração</h2>

        <table class="form-table">
            <tr>
                <th>Substituir URLs</th>
                <td>
                    <label>
                        <input type="checkbox" name="wpbr_replace_urls" value="1" <?php checked($needs_url_replace); ?>>
                        Substituir <code><?php echo esc_html($source['site_url']); ?></code>
                        por <code><?php echo esc_html($current_url); ?></code> no banco de dados
                    </label>
                    <p class="description">Inclui URLs em JSON (Elementor), dados serializados e texto simples.</p>
                </td>
            </tr>
            <tr>
                <th>Manter Usuários</th>
                <td>
                    <label>
                        <input type="checkbox" name="wpbr_skip_users" value="1" checked>
                        Não sobrescrever tabelas de usuários (manter seus usuários atuais)
                    </label>
                </td>
            </tr>
            <tr>
                <th>Sobrescrever Arquivos</th>
                <td>
                    <label>
                        <input type="checkbox" name="wpbr_overwrite" value="1" checked>
                        Sobrescrever arquivos existentes (temas, plugins, uploads)
                    </label>
                </td>
            </tr>
        </table>
    </div>

    <div class="wpbr-card wpbr-warning-card">
        <p><strong>ATENÇÃO:</strong> A restauração irá sobrescrever dados do seu site atual. Faça um backup antes de continuar.</p>
    </div>

    <p>
        <?php submit_button('Restaurar Backup', 'primary', 'wpbr_restore_submit', false); ?>
        <a href="<?php echo esc_url(admin_url('admin.php?page=wp-backup-restorer')); ?>" class="button">Cancelar</a>
    </p>
</form>
