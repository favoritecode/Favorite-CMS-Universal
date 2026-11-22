(()=>{
function initializeBuilderMode(){
'use strict';
const panel=document.getElementById('cms-builder-panel');if(!panel)return;
const form=panel.closest('form'),wrapper=panel.closest('.cms-simple-editor'),flag=document.getElementById('cms-builder-disconnect');
const visual=wrapper.querySelector('#mode-visual-btn,#page-mode-visual-btn'),code=wrapper.querySelector('#mode-code-btn,#page-mode-code-btn'),builder=wrapper.querySelector('[data-fpb-link]');
if(!visual||!code||!builder||wrapper.dataset.builderModeBound==='1')return;
wrapper.dataset.builderModeBound='1';
const tabs=[visual,code,builder];builder.removeAttribute('onclick');builder.parentElement.setAttribute('role','tablist');builder.parentElement.setAttribute('aria-label','Content editor mode');wrapper.firstElementChild.classList.add('cms-editor-mode-bar');
function active(tab){for(const item of tabs){const selected=item===tab;item.classList.toggle('active',selected);item.setAttribute('role','tab');item.setAttribute('aria-selected',String(selected));}wrapper.classList.toggle('cms-builder-mode',tab===builder);panel.hidden=tab!==builder;}
function simple(event){
 if(flag.value==='0'){
  const allowed=confirm('Switch to Simple Edit? When you save, the Builder design will be disconnected and the Visual/Code content will replace it. Your Builder design will remain as a backup. Continue?');
  if(!allowed){event.preventDefault();event.stopImmediatePropagation();return;}
  flag.value='1';
 }
 active(event.currentTarget);
 // Let the original Visual/Code handler run and keep its existing content sync.
}
visual.addEventListener('click',simple,true);code.addEventListener('click',simple,true);
builder.addEventListener('click',event=>{event.preventDefault();event.stopImmediatePropagation();flag.value='0';active(builder);},true);
const preview=document.getElementById('preview-post-btn');if(preview)preview.addEventListener('click',event=>{const frame=panel.querySelector('.cms-builder-preview');if(flag.value==='0'&&frame){event.preventDefault();event.stopImmediatePropagation();document.getElementById('preview-iframe').src=frame.src;document.getElementById('preview-modal').style.display='flex';}},true);
active(builder);
}
if(document.readyState==='complete')initializeBuilderMode();else document.addEventListener('DOMContentLoaded',initializeBuilderMode,{once:true});
})();
