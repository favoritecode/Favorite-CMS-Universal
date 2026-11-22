<?php if ($builderState): $be=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); ?>
<style>
.cms-has-builder.cms-builder-mode :is(#visual-toolbar,#visual-mode-container,#code-toolbar,#code-mode-container,#page-visual-toolbar,#page-visual-container,#page-code-container){display:none!important}
.cms-has-builder .mode-tab-btn{background:transparent!important;color:var(--wp-text-muted)!important;box-shadow:none!important}
.cms-has-builder .mode-tab-btn.active{background:var(--admin-surface)!important;color:var(--wp-blue)!important;box-shadow:0 1px 3px #0001!important}
.cms-builder-panel{position:relative;min-height:420px;background:var(--admin-surface);isolation:isolate}
.cms-builder-panel[hidden]{display:none!important}
.cms-builder-panel iframe{display:block;width:100%;height:520px;border:0;pointer-events:none}
.cms-builder-panel .cms-builder-edit-overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;background:linear-gradient(transparent,#00000012,transparent)}
.cms-builder-panel .cms-builder-edit-overlay a{pointer-events:auto;display:inline-flex;align-items:center;gap:9px;max-width:calc(100% - 32px);padding:13px 20px;border-radius:8px;box-shadow:0 5px 22px #0003;text-decoration:none;text-align:center;white-space:normal;font-weight:600}
.cms-has-builder.cms-builder-mode #editor-word-count,.cms-has-builder.cms-builder-mode #editor-char-count{visibility:hidden}
@media(max-width:600px){.cms-builder-panel{min-height:340px}.cms-builder-panel iframe{height:400px}.cms-has-builder>.cms-editor-mode-bar{gap:6px!important}.cms-has-builder .mode-tab-btn{padding-inline:8px!important}}
</style>
<input type="hidden" name="_builder_baseline" value="<?php echo $be($builderState['baseline']); ?>">
<input type="hidden" name="_builder_disconnect" id="cms-builder-disconnect" value="0">
<section class="cms-builder-panel" id="cms-builder-panel" aria-label="Favorite Page Builder design preview">
<?php if(!empty($builderState['preview'])): ?><iframe class="cms-builder-preview" title="Favorite Page Builder preview" sandbox="allow-scripts" src="<?php echo $be($builderState['preview']); ?>" loading="lazy"></iframe><?php else: ?><p style="padding:24px;color:var(--wp-text-muted)">This content uses a Favorite Page Builder design. Design preview requires Builder access. Choose Visual or Code to switch to Simple Edit.</p><?php endif; ?>
<?php if(!empty($builderState['editor'])): ?><div class="cms-builder-edit-overlay"><a class="btn btn-primary" href="<?php echo $be($builderState['editor']); ?>">▦ Edit with Favorite Page Builder</a></div><?php endif; ?>
</section>
<?php endif; ?>
