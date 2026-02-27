<?php
if (!defined('ABSPATH')) exit;

$results = get_transient('wpbr_restore_results');
if (!$results) {
    echo '<div class="notice notice-error"><p>Nenhum resultado encontrado.</p></div>';
    echo '<a href="' . esc_url(admin_url('admin.php?page=wp-backup-restorer')) . '" class="button">Voltar</a>';
    return;
}

delete_transient('wpbr_restore_results');

$has_errors = false;
foreach ($results as $r) {
    if (is_wp_error($r) || (!empty($r['errors']) && is_array($r['errors']))) {
        $has_errors = true;
        break;
    }
}
?>

<div class="wpbr-card">
    <h2>
        <?php if (!$has_errors): ?>
            <span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span>
            Restauração Concluída!
        <?php else: ?>
            <span class="dashicons dashicons-warning" style="color:#dba617;"></span>
            Restauração Concluída com Avisos
        <?php endif; ?>
    </h2>

    <table class="widefat striped">
        <thead>
            <tr>
                <th>Componente</th>
                <th>Resultado</th>
                <th>Detalhes</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($results as $component => $result): ?>
                <tr>
                    <td><strong><?php echo esc_html(ucfirst($component)); ?></strong></td>
                    <td>
                        <?php if (is_wp_error($result)): ?>
                            <span class="wpbr-badge wpbr-badge-error">Erro</span>
                        <?php elseif (!empty($result['errors'])): ?>
                            <span class="wpbr-badge wpbr-badge-warn">Parcial</span>
                        <?php else: ?>
                            <span class="wpbr-badge wpbr-badge-ok">OK</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (is_wp_error($result)): ?>
                            <?php echo esc_html($result->get_error_message()); ?>
                        <?php elseif ($component === 'database'): ?>
                            <?php echo esc_html($result['tables_created']); ?> tabelas criadas,
                            <?php echo esc_html($result['queries_run']); ?> queries executadas
                            <?php if ($result['url_replaced']): ?>
                                <br>URLs substituídas automaticamente
                            <?php endif; ?>
                            <?php if ($result['prefix_changed']): ?>
                                <br>Prefixo de tabelas convertido
                            <?php endif; ?>
                        <?php else: ?>
                            <?php echo esc_html($result['files_restored']); ?> arquivos restaurados
                            <?php if ($result['files_skipped'] > 0): ?>
                                , <?php echo esc_html($result['files_skipped']); ?> pulados
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if (!is_wp_error($result) && !empty($result['errors'])): ?>
                            <details style="margin-top:8px;">
                                <summary style="cursor:pointer;color:#b32d2e;"><?php echo count($result['errors']); ?> erros</summary>
                                <ul style="font-size:12px;margin-top:4px;">
                                    <?php foreach ($result['errors'] as $err): ?>
                                        <li><?php echo esc_html($err); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="wpbr-card">
    <h2>Próximos Passos</h2>
    <ul>
        <li>Visite <a href="<?php echo esc_url(home_url()); ?>" target="_blank">seu site</a> para verificar se está funcionando</li>
        <li>Se necessário, vá em <strong>Configurações > Links Permanentes</strong> e clique "Salvar" para atualizar permalinks</li>
        <li>Verifique se os plugins estão ativos em <strong>Plugins</strong></li>
        <li>Se usou Elementor, visite <strong>Elementor > Ferramentas > Regenerar CSS</strong></li>
    </ul>
</div>

<p>
    <a href="<?php echo esc_url(admin_url('admin.php?page=wp-backup-restorer')); ?>" class="button button-primary">Novo Restore</a>
    <a href="<?php echo esc_url(home_url()); ?>" class="button" target="_blank">Ver Site</a>
</p>
