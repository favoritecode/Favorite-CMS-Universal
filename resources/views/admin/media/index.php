<div class="page-header">
    <h1 class="page-title">Media Library</h1>
</div>

<!-- Upload Box -->
<div class="form-card" style="margin-bottom: 24px; padding: 20px;">
    <h2 style="font-size: 15px; font-weight: 600; margin-bottom: 12px;">Upload New Media</h2>
    <form method="POST" action="/admin/media/upload" enctype="multipart/form-data" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        <input type="file" name="file" required class="form-control" style="max-width: 320px;">
        <button type="submit" class="btn btn-primary">Upload File</button>
        <span class="description">Allowed types: Images (JPG, PNG, GIF, WebP, SVG), PDF, ZIP, TXT. Maximum: 20MB.</span>
    </form>
</div>

<!-- Media Grid -->
<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px;">
    <?php if (empty($mediaItems)): ?>
        <div class="form-card" style="grid-column: 1 / -1; text-align: center; color: var(--wp-text-muted); padding: 40px;">
            No media files uploaded yet. Select a file above to add to the library.
        </div>
    <?php else: ?>
        <?php foreach ($mediaItems as $item): ?>
            <div class="form-card" style="padding: 12px; display: flex; flex-direction: column;">
                <div style="height: 120px; background: #f8fafc; border: 1px solid #f0f0f1; border-radius: 4px; display: flex; align-items: center; justify-content: center; overflow: hidden; margin-bottom: 10px;">
                    <?php if ($item->isImage()): ?>
                        <img src="<?php echo htmlspecialchars($item->url, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($item->filename, ENT_QUOTES, 'UTF-8'); ?>" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                    <?php else: ?>
                        <span style="font-size: 32px;">📄</span>
                    <?php endif; ?>
                </div>

                <div style="font-weight: 600; font-size: 13px; word-break: break-all; margin-bottom: 4px;">
                    <?php echo htmlspecialchars($item->filename, ENT_QUOTES, 'UTF-8'); ?>
                </div>

                <div style="font-size: 11px; color: var(--wp-text-muted); margin-bottom: 8px;">
                    <?php echo htmlspecialchars($item->getFormattedSize(), ENT_QUOTES, 'UTF-8'); ?> &bull; <?php echo htmlspecialchars($item->mime_type ?? '', ENT_QUOTES, 'UTF-8'); ?>
                </div>

                <div style="margin-top: auto; display: flex; justify-content: space-between; align-items: center; font-size: 12px; border-top: 1px solid #f0f0f1; padding-top: 8px;">
                    <a href="<?php echo htmlspecialchars($item->url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" style="color: var(--wp-blue);">View / URL</a>
                    <a href="/admin/media/delete?id=<?php echo (int)$item->id; ?>" onclick="return confirm('Delete this media file permanently?');" style="color: var(--wp-danger);">Delete</a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

