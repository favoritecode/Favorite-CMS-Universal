<?php $contentLayout=\FavoriteCMS\Services\ContentLayout::get($contentType,(int)($contentRecord->id??0)); ?>
<input type="hidden" name="_layout_baseline" value="<?php echo $contentLayout; ?>">
<div class="form-card" style="margin-bottom:18px">
<label for="sidebar-layout"><strong>Content layout</strong></label>
<select name="sidebar_layout" id="sidebar-layout" class="form-control" style="margin-top:8px">
<?php foreach(['right'=>'Right sidebar (default)','left'=>'Left sidebar','none'=>'Full width — no sidebar'] as $value=>$label): ?>
<option value="<?php echo $value; ?>" <?php echo $contentLayout===$value?'selected':''; ?>><?php echo $label; ?></option>
<?php endforeach; ?></select><small>Applies to this page or post. Header and footer keep the theme width.</small>
</div>
