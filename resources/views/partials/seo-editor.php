<?php
$seoEditorConfig=['site'=>(string)\FavoriteCMS\Models\Setting::get('general','site_name','Favorite CMS'),'separator'=>(string)\FavoriteCMS\Models\Setting::get('seo','title_separator','|')];
$seoEditorIssues=$seoEditorItem?\FavoriteCMS\Services\SeoAudit::issues($seoEditorItem):[];
$seoRelated=$seoEditorItem?\FavoriteCMS\Services\SeoAudit::related($seoEditorItem):[];
?>
<div class="form-card" id="seo-editor-preview" style="background:var(--admin-surface-subtle);padding:14px;">
 <h4 style="margin-bottom:8px;">Search preview <span class="description">(approximate)</span></h4>
 <strong id="seo-preview-title" style="color:var(--admin-link);overflow-wrap:anywhere;"></strong>
 <p id="seo-preview-description" style="margin-block:8px;overflow-wrap:anywhere;"></p>
 <p class="description" id="seo-preview-source" role="status" aria-live="polite"></p>
 <p class="description">Leave metadata empty for automatic defaults. Length feedback is guidance; search engines may display a different snippet.</p>
 <?php if($seoEditorIssues): ?><details style="margin-top:10px;"><summary>Review saved content (<?php echo count($seoEditorIssues); ?>)</summary><?php foreach($seoEditorIssues as $seoIssue): ?><p><?php echo htmlspecialchars($seoIssue,ENT_QUOTES,'UTF-8'); ?></p><?php endforeach; ?></details><?php endif; ?>
 <?php if($seoRelated): ?><details style="margin-top:10px;"><summary>Related internal links to consider</summary><?php foreach($seoRelated as $link): ?><p><a target="_blank" href="<?php echo htmlspecialchars($link['url'],ENT_QUOTES,'UTF-8'); ?>"><?php echo htmlspecialchars($link['title'],ENT_QUOTES,'UTF-8'); ?></a><br><small><?php echo htmlspecialchars($link['url'],ENT_QUOTES,'UTF-8'); ?></small></p><?php endforeach; ?></details><?php endif; ?>
</div>
<script>
(function(){
 'use strict';
 var config=<?php echo json_encode($seoEditorConfig,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
 function init(){
  var panel=document.getElementById('seo-editor-preview'),form=panel && panel.closest('form');if(!form)return;
  var title=form.querySelector('[name="title"]'),metaTitle=form.querySelector('[name="meta_title"]'),description=form.querySelector('[name="meta_description"]'),excerpt=form.querySelector('[name="excerpt"]');
  function text(html,stripShortcodes){
   var doc=new DOMParser().parseFromString((stripShortcodes ? String(html).replace(/\[(?:\/?[a-z][a-z0-9_-]*)(?:\s[^\]]*)?\]/gi,' ') : String(html)),'text/html');
   doc.querySelectorAll('script,style,template,noscript').forEach(function(el){el.remove();});
   doc.querySelectorAll('p,div,h1,h2,h3,h4,h5,h6,li,br,section,article,blockquote,td,tr').forEach(function(el){el.appendChild(doc.createTextNode(' '));});
   return (doc.body.textContent||'').replace(/[\s\u00a0]+/g,' ').trim();
  }
  function summary(html){var value=text(html,true);if(Array.from(value).length<=160)return value;var cut=Array.from(value).slice(0,161).join(''),space=cut.lastIndexOf(' ');if(space>88)return cut.slice(0,space).trim()+'…';if(typeof Intl.Segmenter==='function'){var result='';for(var part of new Intl.Segmenter(undefined,{granularity:'grapheme'}).segment(value)){if(Array.from(result+part.segment).length>160)break;result+=part.segment;}return result+'…';}return Array.from(value).slice(0,160).join('')+'…';}
  function update(){
   var currentTitle=text(metaTitle.value)||text(title.value)+(config.site?' '+config.separator+' '+config.site:'');
   var content=form.favoriteEditor?form.favoriteEditor.read():(form.querySelector('[name="content"]')||{}).value||'';
   var currentDesc=text(description.value)||summary((excerpt&&excerpt.value.trim())?excerpt.value:content)||text(title.value);
   panel.querySelector('#seo-preview-title').textContent=currentTitle;
   panel.querySelector('#seo-preview-description').textContent=currentDesc;
   var autoTitle=!metaTitle.value.trim(),autoDesc=!description.value.trim();
   panel.querySelector('#seo-preview-source').textContent=(autoTitle?'Automatic title':'Custom title')+' · '+(autoDesc?'Automatic description':'Custom description')+' · '+Array.from(currentTitle).length+' / '+Array.from(currentDesc).length+' characters'+(Array.from(currentTitle).length>70?' · Consider a shorter title':'');
  }
  var timer;form.addEventListener('input',function(){clearTimeout(timer);timer=setTimeout(update,180);});form.addEventListener('change',update);update();
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
</script>