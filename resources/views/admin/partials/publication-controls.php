<?php
$publicationRecord=$publicationType==='post'?($post??null):($page??null);
$publicationActor=$currentUser??\FavoriteCMS\Models\User::find((int)($_SESSION['auth_user_id']??0));
$canPublish=$publicationActor&&($publicationType==='post'?$publicationActor->canDirectPublish():$publicationActor->canManagePages());
$publicationStatus=$publicationRecord->status??'draft';
$publicationTime=$publicationRecord->scheduled_at??null;
$publicationTimezone=\FavoriteCMS\Core\DateTime::getTimezone();
$publicationValue=$publicationTime?\FavoriteCMS\Core\DateTime::format($publicationTime,'Y-m-d\TH:i'):'';
?>
<div class="publication-controls" data-publication-controls>
    <label for="status" class="publication-label">Status</label>
    <div class="publication-save-row">
        <select id="status" name="status" class="form-control" aria-controls="publication-schedule">
            <?php foreach(['draft'=>'Draft']+($canPublish?['published'=>'Published','scheduled'=>'Scheduled']:[])+($publicationType==='post'?['pending'=>'Pending review']:[])+($publicationStatus==='rejected'?['rejected'=>'Rejected']:[]) as $value=>$label): ?>
                <option value="<?php echo $value; ?>" <?php echo $publicationStatus===$value?'selected':''; ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" id="<?php echo $publicationType==='post'?'post-save-btn':'page-save-btn'; ?>" class="btn btn-primary">Save</button>
    </div>
    <div id="publication-schedule" <?php echo $publicationStatus==='scheduled'?'':'hidden'; ?>>
        <p class="publication-summary" data-schedule-summary role="status" aria-live="polite"></p>
        <details data-schedule-editor <?php echo $publicationTime?'':'open'; ?>>
            <summary>Change date &amp; time</summary>
            <label for="scheduled-at">Publish on (<?php echo htmlspecialchars($publicationTimezone,ENT_QUOTES,'UTF-8'); ?>)</label>
            <input id="scheduled-at" name="scheduled_at" type="datetime-local" class="form-control" value="<?php echo htmlspecialchars($publicationValue,ENT_QUOTES,'UTF-8'); ?>" data-site-timezone="<?php echo htmlspecialchars($publicationTimezone,ENT_QUOTES,'UTF-8'); ?>" <?php echo $publicationStatus==='scheduled'?'required':''; ?>>
        </details>
    </div>
    <p class="publication-help" data-publication-help><?php echo !$canPublish&&$publicationType==='post'?'Publication requires moderator approval.':'Choose a status, then save.'; ?></p>
</div>
<style>
.publication-label{display:block;font-size:12px;font-weight:600;margin-bottom:7px}.publication-save-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:center}.publication-save-row .form-control{min-width:0;margin:0}.publication-save-row .btn{height:38px;padding:0 16px}.publication-controls [hidden]{display:none!important}.publication-summary{font-size:12px;line-height:1.55;margin:12px 0 8px;color:var(--admin-info-text,var(--wp-text))}.publication-controls details{border:1px solid var(--wp-border);border-radius:6px;padding:9px}.publication-controls summary{font-size:12px;cursor:pointer;font-weight:600}.publication-controls details label{display:block;font-size:11px;margin:10px 0 6px;color:var(--wp-text-muted)}.publication-controls input{width:100%;min-width:0}.publication-help{font-size:11px;line-height:1.5;color:var(--wp-text-muted);margin:10px 0 0}
</style>
<script>
(function(){var root=document.querySelector('[data-publication-controls]');if(!root)return;var status=root.querySelector('#status'),panel=root.querySelector('#publication-schedule'),date=root.querySelector('#scheduled-at'),summary=root.querySelector('[data-schedule-summary]'),details=root.querySelector('details'),help=root.querySelector('[data-publication-help]');function update(){var scheduled=status.value==='scheduled';panel.hidden=!scheduled;date.required=scheduled;date.disabled=!scheduled;summary.textContent=date.value?'Scheduled for '+new Date(date.value).toLocaleString([], {dateStyle:'medium',timeStyle:'short'})+' · '+date.dataset.siteTimezone:'Choose when this content should go live.';help.textContent=scheduled?'Save to confirm this schedule. You can change it later.':status.value==='published'?'Save to publish now.':status.value==='pending'?'Save to submit for moderator review.':'Save keeps this content private as a draft.';}status.addEventListener('change',function(){if(status.value==='scheduled'&&!date.value)details.open=true;update();});date.addEventListener('input',update);date.addEventListener('invalid',function(){details.open=true;});update();})();
</script>
