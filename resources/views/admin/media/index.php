<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
    <h1 class="page-title">Media Library</h1>
    <div style="font-size: 13px; color: var(--wp-text-muted); background: #f8fafc; padding: 8px 14px; border-radius: 6px; border: 1px solid var(--wp-border); text-align: right;">
        <div>
            <span>Role Allowance: <strong style="color: var(--wp-dark); text-transform: capitalize;"><?php echo htmlspecialchars($capabilities['user']['role_category'] ?? 'user'); ?></strong> (<strong><?php echo htmlspecialchars($capabilities['user']['configured_limit_formatted'] ?? $capabilities['user']['max_upload_formatted']); ?></strong>)</span>
            <span style="margin: 0 6px;">&bull;</span>
            <span>Effective Limit: <strong style="color: #0284c7;"><?php echo htmlspecialchars($capabilities['user']['max_upload_formatted']); ?></strong></span>
        </div>
        <?php if (!empty($capabilities['user']['is_server_capped']) && ($capabilities['user']['configured_limit_bytes'] ?? 0) > ($capabilities['user']['max_upload_bytes'] ?? 0)): ?>
            <div style="font-size: 11px; color: #b45309; margin-top: 4px;">
                &#9888; Server upload bottleneck: Capped at <strong><?php echo htmlspecialchars($capabilities['server']['effective_server_formatted']); ?></strong> by hosting PHP settings (<code>upload_max_filesize</code> / <code>post_max_size</code>).
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Upload Zone with AJAX Progress Bar & Drag-and-Drop -->
<div class="form-card" style="margin-bottom: 24px; padding: 20px; border: 2px dashed #cbd5e1; background: #fafafa; border-radius: 8px;" id="drop-zone">
    <div style="display: flex; flex-direction: column; align-items: center; text-align: center;">
        <div style="font-size: 36px; color: var(--wp-blue); margin-bottom: 8px;">&#128229;</div>
        <h2 style="font-size: 16px; font-weight: 600; margin-bottom: 4px; color: var(--wp-dark);">Drag & Drop Files Here to Upload</h2>
        <p style="font-size: 13px; color: var(--wp-text-muted); margin-bottom: 12px;">
            Supports large video (MP4, WebM, MKV), audio (MP3, WAV), images, documents, and archives up to <strong><?php echo htmlspecialchars($capabilities['user']['max_upload_formatted']); ?></strong>.
        </p>

        <form id="media-upload-form" method="POST" action="/admin/media/upload" enctype="multipart/form-data" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; justify-content: center;">
            <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <input type="file" id="media-file-input" name="file" class="form-control" style="max-width: 320px; background: #ffffff;">
            <button type="submit" id="start-upload-btn" class="btn btn-primary">&#128247; Upload File</button>
        </form>

        <!-- Progress Bar Container -->
        <div id="upload-progress-wrap" style="display: none; width: 100%; max-width: 460px; margin-top: 14px;">
            <div style="display: flex; justify-content: space-between; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">
                <span id="upload-filename">Uploading...</span>
                <span id="upload-percent">0%</span>
            </div>
            <div style="background: #e2e8f0; border-radius: 999px; height: 10px; overflow: hidden;">
                <div id="upload-progress-bar" style="width: 0%; height: 100%; background: var(--wp-blue); transition: width 0.15s ease;"></div>
            </div>
            <div id="upload-status-msg" style="font-size: 12px; margin-top: 6px; text-align: center;"></div>
        </div>
    </div>
</div>

<!-- Filters & Search Bar -->
<div style="margin-bottom: 18px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
    <div style="display: flex; gap: 6px; flex-wrap: wrap;">
        <a href="/admin/media" class="btn <?php echo ($currentCat === 'all') ? 'btn-primary' : 'btn-secondary'; ?>" style="font-size: 12px; padding: 4px 10px;">All Media</a>
        <a href="/admin/media?category=image" class="btn <?php echo ($currentCat === 'image') ? 'btn-primary' : 'btn-secondary'; ?>" style="font-size: 12px; padding: 4px 10px;">&#128444; Images</a>
        <a href="/admin/media?category=video" class="btn <?php echo ($currentCat === 'video') ? 'btn-primary' : 'btn-secondary'; ?>" style="font-size: 12px; padding: 4px 10px;">&#127916; Videos</a>
        <a href="/admin/media?category=audio" class="btn <?php echo ($currentCat === 'audio') ? 'btn-primary' : 'btn-secondary'; ?>" style="font-size: 12px; padding: 4px 10px;">&#127925; Audio</a>
        <a href="/admin/media?category=document" class="btn <?php echo ($currentCat === 'document') ? 'btn-primary' : 'btn-secondary'; ?>" style="font-size: 12px; padding: 4px 10px;">&#128196; Documents</a>
    </div>

    <form method="GET" action="/admin/media" style="display: flex; gap: 6px;">
        <?php if ($currentCat !== 'all'): ?>
            <input type="hidden" name="category" value="<?php echo htmlspecialchars($currentCat, ENT_QUOTES, 'UTF-8'); ?>">
        <?php endif; ?>
        <input type="text" name="s" class="form-control" style="font-size: 12px; padding: 4px 8px; width: 180px;" placeholder="Search media..." value="<?php echo htmlspecialchars($searchQuery ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" class="btn btn-secondary" style="font-size: 12px; padding: 4px 10px;">Search</button>
    </form>
</div>

<!-- Media Grid -->
<div id="media-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 16px;">
    <?php if (empty($mediaItems)): ?>
        <div class="form-card" style="grid-column: 1 / -1; text-align: center; color: var(--wp-text-muted); padding: 50px 20px;">
            <div style="font-size: 32px; margin-bottom: 8px;">&#128193;</div>
            <p style="font-size: 14px; margin-bottom: 4px;">No media files found matching the criteria.</p>
            <span style="font-size: 12px;">Drag and drop or select a file above to add items to your library.</span>
        </div>
    <?php else: ?>
        <?php foreach ($mediaItems as $item): ?>
            <div class="form-card" style="padding: 12px; display: flex; flex-direction: column; position: relative;">
                <div style="height: 130px; background: #0f172a; border-radius: 4px; display: flex; align-items: center; justify-content: center; overflow: hidden; margin-bottom: 10px; position: relative;">
                    <?php if ($item->isImage()): ?>
                        <img src="<?php echo htmlspecialchars($item->url, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($item->filename, ENT_QUOTES, 'UTF-8'); ?>" style="max-width: 100%; max-height: 100%; object-fit: cover;" loading="lazy">
                    <?php elseif ($item->isVideo()): ?>
                        <video src="<?php echo htmlspecialchars($item->url, ENT_QUOTES, 'UTF-8'); ?>" style="max-width: 100%; max-height: 100%; object-fit: contain;" preload="metadata" controls></video>
                    <?php elseif ($item->isAudio()): ?>
                        <div style="text-align: center; color: #ffffff; padding: 10px;">
                            <div style="font-size: 28px; margin-bottom: 4px;">&#127925;</div>
                            <audio src="<?php echo htmlspecialchars($item->url, ENT_QUOTES, 'UTF-8'); ?>" controls style="width: 170px; height: 32px;"></audio>
                        </div>
                    <?php elseif ($item->isDocument()): ?>
                        <span style="font-size: 40px;">&#128196;</span>
                    <?php elseif ($item->isArchive()): ?>
                        <span style="font-size: 40px;">&#128230;</span>
                    <?php else: ?>
                        <span style="font-size: 40px;">&#128193;</span>
                    <?php endif; ?>

                    <span style="position: absolute; top: 6px; right: 6px; background: rgba(0,0,0,0.65); color: #ffffff; font-size: 10px; font-weight: 600; padding: 2px 6px; border-radius: 3px; text-transform: uppercase;">
                        <?php echo htmlspecialchars($item->getTypeCategory()); ?>
                    </span>
                </div>

                <div style="font-weight: 600; font-size: 13px; word-break: break-all; margin-bottom: 4px; color: var(--wp-dark); line-height: 1.3;" title="<?php echo htmlspecialchars($item->filename, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($item->filename, ENT_QUOTES, 'UTF-8'); ?>
                </div>

                <div style="font-size: 11px; color: var(--wp-text-muted); margin-bottom: 8px;">
                    <?php echo htmlspecialchars($item->getFormattedSize(), ENT_QUOTES, 'UTF-8'); ?> &bull; <?php echo htmlspecialchars($item->mime_type ?? '', ENT_QUOTES, 'UTF-8'); ?>
                </div>

                <div style="margin-top: auto; display: flex; justify-content: space-between; align-items: center; font-size: 12px; border-top: 1px solid var(--wp-border); padding-top: 8px;">
                    <a href="<?php echo htmlspecialchars($item->url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" style="color: var(--wp-blue); text-decoration: none;">View File &#8599;</a>
                    <button type="button" class="copy-url-btn" data-url="<?php echo htmlspecialchars($item->url, ENT_QUOTES, 'UTF-8'); ?>" style="background: none; border: none; color: #64748b; font-size: 11px; cursor: pointer; text-decoration: underline;">Copy URL</button>
                    <a href="/admin/media/delete?id=<?php echo (int)$item->id; ?>" onclick="return confirm('Delete this media file permanently?');" style="color: var(--wp-danger); text-decoration: none;">Delete</a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var fileInput    = document.getElementById('media-file-input');
    var uploadForm   = document.getElementById('media-upload-form');
    var progressWrap = document.getElementById('upload-progress-wrap');
    var progressBar  = document.getElementById('upload-progress-bar');
    var percentText  = document.getElementById('upload-percent');
    var statusMsg    = document.getElementById('upload-status-msg');
    var dropZone     = document.getElementById('drop-zone');

    // Copy URL helper
    document.querySelectorAll('.copy-url-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var url = this.getAttribute('data-url');
            if (navigator.clipboard) {
                navigator.clipboard.writeText(url);
                this.textContent = 'Copied!';
                var self = this;
                setTimeout(function() { self.textContent = 'Copy URL'; }, 2000);
            } else {
                prompt('Copy URL:', url);
            }
        });
    });

    // AJAX Upload with XMLHttpRequest for real progress reporting
    function uploadFileViaAjax(file) {
        if (!file) return;

        var formData = new FormData();
        formData.append('file', file);
        formData.append('_token', '<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>');

        progressWrap.style.display = 'block';
        progressBar.style.width = '0%';
        percentText.textContent = '0%';
        statusMsg.style.color = '#334155';
        statusMsg.textContent = 'Uploading ' + file.name + '...';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/admin/media/upload-ajax', true);

        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable) {
                var percent = Math.round((e.loaded / e.total) * 100);
                progressBar.style.width = percent + '%';
                percentText.textContent = percent + '%';
            }
        };

        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.success) {
                        progressBar.style.width = '100%';
                        percentText.textContent = '100%';
                        statusMsg.style.color = '#16a34a';
                        statusMsg.textContent = 'Upload complete! Refreshing...';
                        setTimeout(function() { window.location.reload(); }, 900);
                        return;
                    }
                } catch(e) {}
            }

            var errMsg = 'Upload failed.';
            try {
                var errResp = JSON.parse(xhr.responseText);
                if (errResp.message) errMsg = errResp.message;
            } catch(e) {}

            statusMsg.style.color = '#dc2626';
            statusMsg.textContent = errMsg;
            progressBar.style.backgroundColor = '#dc2626';
        };

        xhr.onerror = function() {
            statusMsg.style.color = '#dc2626';
            statusMsg.textContent = 'Network error occurred during upload.';
        };

        xhr.send(formData);
    }

    uploadForm.addEventListener('submit', function(e) {
        if (fileInput.files.length > 0) {
            e.preventDefault();
            uploadFileViaAjax(fileInput.files[0]);
        }
    });

    // Drag and drop handlers
    dropZone.addEventListener('dragover', function(e) {
        e.preventDefault();
        dropZone.style.borderColor = 'var(--wp-blue)';
        dropZone.style.background = '#eff6ff';
    });

    dropZone.addEventListener('dragleave', function() {
        dropZone.style.borderColor = '#cbd5e1';
        dropZone.style.background = '#fafafa';
    });

    dropZone.addEventListener('drop', function(e) {
        e.preventDefault();
        dropZone.style.borderColor = '#cbd5e1';
        dropZone.style.background = '#fafafa';
        if (e.dataTransfer.files.length > 0) {
            uploadFileViaAjax(e.dataTransfer.files[0]);
        }
    });
});
</script>
