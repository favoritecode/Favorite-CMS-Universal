/* Activate the existing history contract for the generic core form. */
(function () {
'use strict';
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('core-fallback-form'), api = window.FavoriteBuilder;
    if (!form || !api) return;
    var frame = document.getElementById('core-preview-iframe');
    var status = document.getElementById('core-status-text'), dot = document.getElementById('core-status-dot');
    var undo = document.getElementById('core-btn-undo'), redo = document.getElementById('core-btn-redo');
    var historyTimer, previewTimer, applying = false, saving = false;
    function rows() { return Array.from(form.querySelectorAll('input[name^="sections["]')).map(function(c) { return {id:c.name.match(/^sections\[([^\]]+)\]/)[1], row:c.parentElement.parentElement}; }); }
    function orderInputs() {
        form.querySelectorAll('input[data-section-order]').forEach(function(c) { c.remove(); });
        rows().forEach(function(item, i, all) {
            var hidden = document.createElement('input'); hidden.type='hidden'; hidden.name='section_order[]'; hidden.value=item.id; hidden.dataset.sectionOrder='1'; form.appendChild(hidden);
            item.row.querySelectorAll('button[name="section_id"]').forEach(function(button) {
                var up = button.getAttribute('onclick').indexOf("'up'") >= 0;
                button.disabled = up ? i === 0 : i === all.length - 1;
                button.setAttribute('aria-label', (up ? 'Move up: ' : 'Move down: ') + item.id);
            });
        });
    }
    orderInputs();
    function controls() { return Array.from(form.querySelectorAll('input,textarea,select')).filter(function(c) { return c.name !== '_token' && c.name !== 'direction' && !c.dataset.sectionOrder; }); }
    var fieldKeys = new WeakMap();
    function keyedControls() { var count={}; return controls().map(function(c) { var base=c.name || c.id || '__color'; var index=count[base] || 0; count[base]=index+1; if(!fieldKeys.has(c))fieldKeys.set(c,base+':'+index); return {key:fieldKeys.get(c), element:c}; }); }
    function read() { return {controls:keyedControls().map(function(item) { var c=item.element; return {key:item.key, value:c.value, checked:c.checked}; }).sort(function(a,b) { return a.key.localeCompare(b.key); }), order:rows().map(function(r) { return r.id; })}; }
    var baseline = JSON.stringify(read()); api.history.push(read());
    status.setAttribute('role','status'); status.setAttribute('aria-live','polite');
    function dirty() { return JSON.stringify(read()) !== baseline; }
    function update(text) { dot.classList.toggle('dirty',dirty()); status.textContent=text || (dirty() ? 'Unsaved changes' : 'All changes saved'); }
    function commit() { clearTimeout(historyTimer); if (!applying) api.history.push(read()); update(); }
    function preview() {
        if (!frame) return;
        // Submit a read-only preview request into the existing frame. No settings are written.
        frame.name = 'favorite_customizer_preview';
        var previewForm=document.createElement('form'); previewForm.method='POST'; previewForm.action=form.action.replace(/\/save$/, '/preview'); previewForm.target=frame.name; previewForm.hidden=true;
        new FormData(form).forEach(function(value,name) { var c=document.createElement('input'); c.type='hidden'; c.name=name; c.value=value; previewForm.appendChild(c); });
        document.body.appendChild(previewForm); previewForm.submit(); previewForm.remove();
    }
    function changed() { if (applying) return; update(); clearTimeout(historyTimer); historyTimer=setTimeout(commit,300); clearTimeout(previewTimer); previewTimer=setTimeout(preview,900); }
    function apply(state) {
        if (!state) return;
        applying=true;
        keyedControls().forEach(function(item) { var c=item.element, saved=state.controls.find(function(v) { return v.key===item.key; }); if(!saved)return; c.value=saved.value; if(typeof saved.checked==='boolean')c.checked=saved.checked; });
        var items=rows(), parent=items[0] && items[0].row.parentElement;
        if (parent) state.order.forEach(function(id) { var item=items.find(function(r) { return r.id===id; }); if(item)parent.appendChild(item.row); });
        orderInputs(); applying=false; update(); clearTimeout(previewTimer); previewTimer=setTimeout(preview,150);
    }
    form.addEventListener('input',changed); form.addEventListener('change',changed);
    undo.addEventListener('click',function() { commit(); apply(api.history.undo(read())); });
    redo.addEventListener('click',function() { clearTimeout(historyTimer); apply(api.history.redo(read())); });
    form.addEventListener('submit',function(e) {
        var button=e.submitter;
        if (button && button.name==='section_id') {
            e.preventDefault(); commit();
            var items=rows(), index=items.findIndex(function(r) { return r.id===button.value; });
            var up=form.elements.namedItem('direction').value==='up', next=index+(up?-1:1);
            if(index>=0 && next>=0 && next<items.length) { var parent=items[index].row.parentElement; if(up)parent.insertBefore(items[index].row,items[next].row); else parent.insertBefore(items[next].row,items[index].row); orderInputs(); commit(); changed(); }
            return;
        }
        e.preventDefault();
        if(saving) return;
        commit(); saving=true;
        var savedState=JSON.stringify(read()), payload=new FormData(form);
        update('Saving…');
        form.setAttribute('aria-busy','true');
        var controller=new AbortController(), timeout=setTimeout(function(){controller.abort();},20000);
        fetch(form.action,{method:'POST',body:payload,credentials:'same-origin',signal:controller.signal,headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}})
            .then(function(response) { return response.json().then(function(body) { if(!response.ok || !body.success)throw new Error(body.error || 'Could not save changes.'); baseline=savedState; update(); clearTimeout(previewTimer); preview(); }); })
            .catch(function(error) { update(error.name==='AbortError' ? 'Save timed out. Your changes remain in the form; retry Save.' : error.message+' Your changes remain in the form.'); })
            .finally(function() { clearTimeout(timeout); saving=false; form.removeAttribute('aria-busy'); });
    });
    window.addEventListener('beforeunload',function(e) { if(dirty() || saving) { e.preventDefault(); e.returnValue=''; } });
});
})();