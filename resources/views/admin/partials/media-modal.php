<!-- Reusable Enhanced Media Library & Direct Upload Modal -->
<div id="media-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 10000; align-items: center; justify-content: center;">
    <div style="background: #ffffff; width: 92%; max-width: 900px; height: 85vh; border-radius: 8px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2);">
        <!-- Modal Header with Tabs -->
        <div style="padding: 12px 20px; border-bottom: 1px solid var(--wp-border); display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
            <div style="display: flex; gap: 8px; align-items: center;">
                <button type="button" id="tab-browse-media" class="modal-tab-btn active" style="padding: 6px 14px; font-size: 13px; font-weight: 600; border: 1px solid var(--wp-blue); background: #ffffff; color: var(--wp-blue); border-radius: 4px; cursor: pointer;">
                    &#128193; Browse Media
                </button>
                <button type="button" id="tab-upload-media" class="modal-tab-btn" style="padding: 6px 14px; font-size: 13px; font-weight: 600; border: 1px solid var(--wp-border); background: #f8fafc; color: #64748b; border-radius: 4px; cursor: pointer;">
                    &#128229; Upload New Media
                </button>
            </div>
            <button type="button" id="close-media-modal" style="background: none; border: none; font-size: 22px; cursor: pointer; color: #64748b;">&times;</button>
        </div>

        <!-- Modal Body 1: Browse Media Library -->
        <div id="modal-view-browse" style="display: flex; flex: 1; overflow: hidden;">
            <!-- Left Grid: Media items -->
            <div style="flex: 1; padding: 16px; overflow-y: auto; border-right: 1px solid var(--wp-border);">
                <!-- Media Search & Category Filter -->
                <div style="display: flex; justify-content: space-between; gap: 10px; margin-bottom: 14px; flex-wrap: wrap;">
                    <div style="display: flex; gap: 4px;">
                        <button type="button" class="media-filter-btn active" data-cat="all">All</button>
                        <button type="button" class="media-filter-btn" data-cat="image">Images</button>
                        <button type="button" class="media-filter-btn" data-cat="video">Videos</button>
                        <button type="button" class="media-filter-btn" data-cat="document">Docs</button>
                    </div>
                    <input type="text" id="modal-search-input" placeholder="Search media..." style="padding: 4px 8px; font-size: 12px; border: 1px solid var(--wp-border); border-radius: 4px; width: 160px;">
                </div>

                <?php if (empty($mediaItems)): ?>
                    <div id="modal-empty-state" style="text-align: center; padding: 50px 20px; color: var(--wp-text-muted);">
                        <p style="font-size: 15px; margin-bottom: 8px;">No media files uploaded yet.</p>
                        <button type="button" id="empty-go-upload-btn" class="btn btn-primary" style="font-size: 12px;">Upload Your First File</button>
                    </div>
                <?php endif; ?>

                <div id="modal-media-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(115px, 1fr)); gap: 10px;">
                    <?php foreach ($mediaItems ?? [] as $m): ?>
                        <div class="media-picker-card" 
                             data-id="<?php echo (int)$m->id; ?>" 
                             data-url="<?php echo htmlspecialchars($m->url, ENT_QUOTES, 'UTF-8'); ?>"
                             data-name="<?php echo htmlspecialchars($m->filename, ENT_QUOTES, 'UTF-8'); ?>"
                             data-cat="<?php echo htmlspecialchars($m->getTypeCategory()); ?>"
                             data-mime="<?php echo htmlspecialchars($m->mime_type ?? ''); ?>"
                             data-size="<?php echo htmlspecialchars($m->getFormattedSize()); ?>">
                            <?php if ($m->isImage()): ?>
                                <img src="<?php echo htmlspecialchars($m->url, ENT_QUOTES, 'UTF-8'); ?>" 
                                     alt="<?php echo htmlspecialchars($m->filename, ENT_QUOTES, 'UTF-8'); ?>" 
                                     loading="lazy">
                            <?php elseif ($m->isVideo()): ?>
                                <div style="height: 80px; display: flex; align-items: center; justify-content: center; background: #0f172a; color: #ffffff; font-size: 24px;">&#127916;</div>
                            <?php elseif ($m->isAudio()): ?>
                                <div style="height: 80px; display: flex; align-items: center; justify-content: center; background: #0f172a; color: #ffffff; font-size: 24px;">&#127925;</div>
                            <?php else: ?>
                                <div style="height: 80px; display: flex; align-items: center; justify-content: center; background: #e2e8f0; font-size: 24px;">&#128196;</div>
                            <?php endif; ?>
                            <div class="card-name"><?php echo htmlspecialchars($m->filename, ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div id="modal-media-more-wrap" style="text-align: center; margin-top: 14px;">
                    <button type="button" id="modal-media-more-btn" class="btn btn-secondary" style="font-size: 12px;<?php echo count($mediaItems ?? []) < (int)($mediaTotal ?? 0) ? '' : ' display: none;'; ?>">Load more media</button>
                    <div id="modal-media-status" style="font-size: 12px; color: var(--wp-text-muted); margin-top: 6px;">
                        <?php if ((int)($mediaTotal ?? 0) > 0): ?>
                            Showing <?php echo count($mediaItems ?? []); ?> of <?php echo (int)$mediaTotal; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Sidebar: Item Details & Formatting Options -->
            <div style="width: 260px; padding: 16px; background: #f8fafc; display: flex; flex-direction: column; overflow-y: auto;">
                <h4 style="font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 10px; border-bottom: 1px solid var(--wp-border); padding-bottom: 6px;">Attachment Details</h4>
                <div id="attachment-details-empty" style="color: var(--wp-text-muted); font-size: 12px; font-style: italic;">
                    Select an item from the library to view details and insert options.
                </div>
                <div id="attachment-details-wrap" style="display: none; font-size: 12px;">
                    <div id="attachment-thumb" style="max-height: 120px; overflow: hidden; border-radius: 4px; margin-bottom: 10px; text-align: center; background: #ffffff; border: 1px solid var(--wp-border);"></div>
                    <div style="font-weight: 600; word-break: break-all; margin-bottom: 4px;" id="attachment-filename"></div>
                    <div style="color: #64748b; margin-bottom: 10px;" id="attachment-meta"></div>

                    <!-- Insertion Options for Content Mode -->
                    <div id="content-insert-options">
                        <div class="form-group" style="margin-bottom: 8px;">
                            <label style="font-size: 11px; font-weight: 600;">Alt Text</label>
                            <input type="text" id="insert-alt-text" class="form-control" style="font-size: 12px; padding: 4px;">
                        </div>
                        <div class="form-group" style="margin-bottom: 8px;">
                            <label style="font-size: 11px; font-weight: 600;">Alignment</label>
                            <select id="insert-align-select" class="form-control" style="font-size: 12px; padding: 4px;">
                                <option value="none">None (Inline)</option>
                                <option value="center" selected>Center</option>
                                <option value="left">Left</option>
                                <option value="right">Right</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 8px;">
                            <label style="font-size: 11px; font-weight: 600;">Size</label>
                            <select id="insert-size-select" class="form-control" style="font-size: 12px; padding: 4px;">
                                <option value="full" selected>Full Size</option>
                                <option value="medium">Medium (600px)</option>
                                <option value="thumbnail">Thumbnail (250px)</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Body 2: Upload New Media (Direct AJAX with Progress) -->
        <div id="modal-view-upload" style="display: none; flex: 1; padding: 30px; overflow-y: auto; background: #f8fafc;">
            <div id="modal-upload-zone" style="max-width: 500px; margin: 20px auto; border: 2px dashed #94a3b8; border-radius: 8px; padding: 40px 20px; text-align: center; background: #ffffff;">
                <div style="font-size: 44px; color: var(--wp-blue); margin-bottom: 10px;">&#128229;</div>
                <h3 style="font-size: 16px; font-weight: 600; margin-bottom: 6px;">Drop files to upload</h3>
                <p style="font-size: 12px; color: var(--wp-text-muted); margin-bottom: 16px;">
                    Supports movies, web-series video, audio, images, documents, and archives.
                </p>
                <input type="file" id="modal-file-input" style="display: none;">
                <button type="button" id="modal-select-file-btn" class="btn btn-primary" style="padding: 8px 20px; font-size: 13px;">Select File</button>

                <!-- Upload Progress -->
                <div id="modal-progress-wrap" style="display: none; margin-top: 20px; text-align: left;">
                    <div style="display: flex; justify-content: space-between; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 4px;">
                        <span id="modal-progress-filename">Uploading...</span>
                        <span id="modal-progress-percent">0%</span>
                    </div>
                    <div style="background: #e2e8f0; border-radius: 999px; height: 10px; overflow: hidden;">
                        <div id="modal-progress-bar" style="width: 0%; height: 100%; background: var(--wp-blue); transition: width 0.15s ease;"></div>
                    </div>
                    <div id="modal-upload-status" style="font-size: 12px; margin-top: 6px; text-align: center;"></div>
                </div>
            </div>
        </div>

        <!-- Modal Footer Actions -->
        <div style="padding: 12px 20px; border-top: 1px solid var(--wp-border); display: flex; justify-content: flex-end; gap: 10px; background: #ffffff;">
            <button type="button" id="cancel-media-selection" class="btn btn-secondary" style="font-size: 13px;">Cancel</button>
            <button type="button" id="confirm-media-selection" class="btn btn-primary" style="font-size: 13px;" disabled>Insert Into Post</button>
        </div>
    </div>
</div>

