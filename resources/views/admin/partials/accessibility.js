/* Keyboard support for the existing admin controls; existing click handlers remain authoritative. */
(function () {
'use strict';
document.addEventListener('DOMContentLoaded', function () {
    var serial=0;
    document.querySelectorAll('label:not([for])').forEach(function(label) {
        var container=label.parentElement, control=container && container.querySelector('input:not([type=hidden]),select,textarea');
        if(control && !label.contains(control)) { if(!control.id)control.id='cms-field-'+(++serial); label.htmlFor=control.id; }
    });
    document.querySelectorAll('input[type=color]').forEach(function(c) { if(!c.hasAttribute('aria-label'))c.setAttribute('aria-label','Color picker'); });
    document.querySelectorAll('[contenteditable=true]').forEach(function(c) { if(!c.hasAttribute('role'))c.setAttribute('role','textbox'); c.setAttribute('aria-multiline','true'); if(!c.hasAttribute('aria-label'))c.setAttribute('aria-label','Visual content editor'); });
    document.querySelectorAll('[id$="-error"],[id$="-status"]').forEach(function(c) { if(!c.hasAttribute('role'))c.setAttribute('role',c.id.endsWith('-error')?'alert':'status'); });
    var dialogs=Array.from(document.querySelectorAll('[role=dialog],#media-modal,#preview-modal,#core-templates-modal,#widget-preview-modal,.modal-overlay'));
    var stack=[];
    function visible(node) { return !node.hidden && getComputedStyle(node).display!=='none' && getComputedStyle(node).visibility!=='hidden'; }
    function focusables(dialog) { return Array.from(dialog.querySelectorAll('button,input:not([type=hidden]),select,textarea,a[href],iframe,[tabindex]:not([tabindex="-1"]),[contenteditable=true]')).filter(function(c) { return !c.disabled && visible(c) && c.getClientRects().length>0; }); }
    dialogs.forEach(function(dialog) {
        dialog.setAttribute('role','dialog'); dialog.setAttribute('aria-modal','true'); dialog.tabIndex=-1;
        if(!dialog.hasAttribute('aria-label') && !dialog.hasAttribute('aria-labelledby')) { var heading=dialog.querySelector('h1,h2,h3'); if(heading) { if(!heading.id)heading.id='cms-dialog-title-'+(++serial); dialog.setAttribute('aria-labelledby',heading.id); } else dialog.setAttribute('aria-label',dialog.id.replace(/[-_]/g,' ') || 'Dialog'); }
        var open=false, previous=null;
        function sync() {
            var shown=visible(dialog); if(shown===open)return; open=shown;
            if(shown) { previous=document.activeElement; stack.push(dialog); var list=focusables(dialog); (list[0] || dialog).focus(); }
            else { stack=stack.filter(function(item) { return item!==dialog; }); if(previous && previous.isConnected && visible(previous))previous.focus(); }
        }
        new MutationObserver(sync).observe(dialog,{attributes:true,attributeFilter:['class','style','hidden']}); sync();
    });
    document.addEventListener('keydown',function(e) {
        var dialog=stack[stack.length-1];
        if(dialog && e.key==='Escape') { var close=dialog.querySelector('button[id^="close-"],button[id$="-close"],[data-modal-close],.modal-close'); if(close) { e.preventDefault(); close.click(); } }
        if(dialog && e.key==='Tab') {
            var list=focusables(dialog); if(!list.length) { e.preventDefault(); dialog.focus(); return; }
            var first=list[0],last=list[list.length-1];
            if(e.shiftKey && (document.activeElement===first || !dialog.contains(document.activeElement))) { e.preventDefault(); last.focus(); }
            else if(!e.shiftKey && (document.activeElement===last || !dialog.contains(document.activeElement))) { e.preventDefault(); first.focus(); }
        }
        var card=e.target.closest && e.target.closest('.media-picker-card');
        if(card && e.target===card && (e.key==='Enter' || e.key===' ')) { e.preventDefault(); card.click(); }
    });
    var grid=document.getElementById('modal-media-grid');
    if(grid) { function enhanceCards() { grid.querySelectorAll('.media-picker-card').forEach(function(c) { c.tabIndex=0; c.setAttribute('role','button'); c.setAttribute('aria-label','Select media: '+(c.dataset.filename || c.dataset.name || c.dataset.id || 'item')); c.setAttribute('aria-pressed',c.classList.contains('selected')?'true':'false'); }); } enhanceCards(); new MutationObserver(enhanceCards).observe(grid,{childList:true}); grid.addEventListener('click',function() { setTimeout(enhanceCards,0); }); }
});
})();