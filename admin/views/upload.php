<?php if (!defined('ABSPATH')) exit; ?>

<div class="wpbr-card">
    <h2>Upload do Backup</h2>
    <p class="description">Faça upload de um backup WPVivid (.zip) - editado ou original. Para backups multi-part, selecione todas as partes de uma vez. Nenhuma validação de integridade será feita.</p>

    <form method="post" enctype="multipart/form-data">
        <?php wp_nonce_field('wpbr_upload', 'wpbr_upload_nonce'); ?>

        <div class="wpbr-upload-area" id="wpbr-dropzone">
            <span class="dashicons dashicons-database-import"></span>
            <p><strong>Arraste os arquivos ZIP aqui ou clique para selecionar</strong></p>
            <p class="description">Tamanho máximo por arquivo: <?php echo size_format(wp_max_upload_size()); ?></p>
            <p class="description">Formatos aceitos: backup WPVivid (.zip) — suporta multi-part (part001, part002, etc.)</p>
            <span class="wpbr-filename" id="wpbr-filename"></span>
            <input type="file" name="wpbr_backup_files[]" id="wpbr-file" accept=".zip" multiple required>
        </div>

        <div class="wpbr-info-box">
            <h3>O que este plugin faz:</h3>
            <ul>
                <li>Aceita backups WPVivid mesmo depois de editados</li>
                <li>Suporta backups multi-part do WPVivid (part001, part002, etc.)</li>
                <li>Não verifica checksums ou integridade (o WPVivid bloqueia backups editados)</li>
                <li>Permite escolher quais partes restaurar (banco, temas, plugins, uploads)</li>
                <li>Troca URLs automaticamente ao restaurar em site diferente</li>
                <li>Converte prefixo de tabelas do banco de dados</li>
                <li>Corrige dados serializados após troca de URLs</li>
            </ul>
        </div>

        <?php submit_button('Analisar Backup', 'primary', 'wpbr_submit'); ?>
    </form>
</div>

<script>
(function() {
    var dropzone = document.getElementById('wpbr-dropzone');
    var fileInput = document.getElementById('wpbr-file');
    var filename = document.getElementById('wpbr-filename');

    if (!dropzone) return;

    dropzone.addEventListener('dragover', function(e) {
        e.preventDefault();
        this.classList.add('dragover');
    });
    dropzone.addEventListener('dragleave', function(e) {
        e.preventDefault();
        this.classList.remove('dragover');
    });
    dropzone.addEventListener('drop', function(e) {
        e.preventDefault();
        this.classList.remove('dragover');
    });
    fileInput.addEventListener('change', function() {
        if (this.files.length === 1) {
            var f = this.files[0];
            var size = f.size < 1048576 ? (f.size / 1024).toFixed(1) + ' KB' : (f.size / 1048576).toFixed(1) + ' MB';
            filename.textContent = f.name + ' (' + size + ')';
        } else if (this.files.length > 1) {
            var totalSize = 0;
            var names = [];
            for (var i = 0; i < this.files.length; i++) {
                names.push(this.files[i].name);
                totalSize += this.files[i].size;
            }
            var sizeStr = totalSize < 1048576 ? (totalSize / 1024).toFixed(1) + ' KB' : (totalSize / 1048576).toFixed(1) + ' MB';
            filename.textContent = names.join(', ') + ' — Total: ' + sizeStr;
        }
    });
})();
</script>
