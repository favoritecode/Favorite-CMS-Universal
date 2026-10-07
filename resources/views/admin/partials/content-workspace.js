/* Server drafts complement the original browser drafts and normal save/publish flow. */
(function () {
'use strict';
document.addEventListener('DOMContentLoaded', function () {
    var config = window.favoriteContentWorkspaceConfig;
    var form = document.getElementById(config.type + '-editor-form');
    if (!form || !form.favoriteEditor) return;
    var status = document.getElementById('workspace-status');
    var select = document.getElementById('workspace-snapshots');
    var restore = document.getElementById('workspace-restore');
    var timer, pending = false, submitting = false, lastSaved = '', serverEnabled = true, localDraft = null;
    var client = 'tab_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 14);
    var localKey = 'favorite_workspace_local_' + config.user + '_' + config.type + '_' + config.id;
    function hidden(name, value) { var field = form.elements.namedItem(name); if (!field) { field = document.createElement('input'); field.type = 'hidden'; field.name = name; form.appendChild(field); } field.value = value; }
    hidden('_workspace_client', client); hidden('_workspace_hash', ''); hidden('_workspace_baseline', config.baseline);
    function fields() { return {title: form.elements.namedItem('title').value, content: form.favoriteEditor.read(), excerpt: form.elements.namedItem('excerpt') ? form.elements.namedItem('excerpt').value : ''}; }
    function signature() { var data = []; new FormData(form).forEach(function(v,k) { if (!/^_(workspace|draft|token|content_revision)/.test(k) && k !== 'action_type' && k !== 'content') data.push([k, String(v)]); }); data.push(['editor-content', form.favoriteEditor.read()]); return JSON.stringify(data); }
    var original = signature();
    lastSaved = JSON.stringify(fields());
    function message(text) { status.textContent = text; }
    function localSave(snapshot) {
        // Post editor retains its existing multi-tab local draft implementation. Page gets a private fallback.
        if (config.type !== 'page') return;
        try { localStorage.setItem(localKey, JSON.stringify({fields:snapshot, baseline:config.baseline, token:form.elements.namedItem('_content_revision').value, time:new Date().toISOString()})); } catch(e) {}
    }
    function request(action, data) {
        var controller = new AbortController(), timeout = setTimeout(function() { controller.abort(); }, 15000);
        var options = {credentials:'same-origin', headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}, signal:controller.signal};
        var url = config.endpoint + '/' + action;
        if (data) { options.method = 'POST'; options.body = data; }
        else url += '?id=' + config.id;
        return fetch(url, options).then(function(response) { return response.json().then(function(body) { if (!response.ok || !body.success) { var error = new Error(body.error || 'Request failed.'); error.status = response.status; throw error; } return body; }); }).finally(function() { clearTimeout(timeout); });
    }
    function populate(body) {
        var previous = select.value;
        select.replaceChildren(new Option('Choose a saved version',''));
        if (localDraft) select.add(new Option('Browser draft — ' + localDraft.time, 'local'));
        body.drafts.forEach(function(d) { select.add(new Option('Server draft — ' + d.updated_at, 'draft:' + d.id)); });
        body.history.forEach(function(h) { select.add(new Option('Revision — ' + h.created_at + ' (#' + h.id + ')', 'history:' + h.id)); });
        if (Array.from(select.options).some(function(o) { return o.value === previous; })) select.value = previous;
        restore.disabled = !select.value;
    }
    function refresh() { return request('workspace').then(function(body) { populate(body); }); }
    function save() {
        if (pending || submitting) return;
        var snapshot = fields(), serialized = JSON.stringify(snapshot);
        if (serialized === lastSaved) return;
        localSave(snapshot);
        if (!serverEnabled) return;
        pending = true; message('Saving a private server draft…');
        var data = new FormData();
        Object.keys(snapshot).forEach(function(k) { data.append(k, snapshot[k]); });
        data.append('id', config.id); data.append('client_id', client); data.append('baseline', config.baseline);
        data.append('_token', form.elements.namedItem('_token').value); data.append('_content_revision', form.elements.namedItem('_content_revision').value);
        request('autosave', data).then(function(body) {
            lastSaved = serialized; hidden('_workspace_hash', body.hash); message('Server draft saved — ' + body.saved_at);
            return refresh();
        }).catch(function(error) {
            if (error.status === 409 || error.status === 403 || error.status === 503) serverEnabled = false;
            message(error.message + ' Your browser draft and normal save controls remain available.');
        }).finally(function() { pending = false; if (serverEnabled && !submitting && JSON.stringify(fields()) !== lastSaved) schedule(); });
    }
    function schedule() { clearTimeout(timer); localSave(fields()); timer = setTimeout(save, 8000); }
    form.addEventListener('input', schedule); form.addEventListener('change', schedule);
    // Media insertion/formatting tools can change editor contents without an input event.
    setInterval(function() { if (!submitting && JSON.stringify(fields()) !== lastSaved) save(); }, 30000);
    form.addEventListener('submit', function() { submitting = true; clearTimeout(timer); localSave(fields()); });
    window.addEventListener('beforeunload', function(e) { if (!submitting && signature() !== original) { localSave(fields()); e.preventDefault(); e.returnValue = ''; } });
    select.addEventListener('change', function() { restore.disabled = !select.value; });
    function stage(data) {
        form.elements.namedItem('title').value = data.title;
        if (form.elements.namedItem('excerpt')) form.elements.namedItem('excerpt').value = data.excerpt;
        form.elements.namedItem('_content_revision').value = data.token || '';
        form.favoriteEditor.set(data.content); form.dispatchEvent(new Event('input', {bubbles:true}));
        message('Version loaded into code mode. Review it, then use Save/Publish to apply.');
    }
    restore.addEventListener('click', function() {
        if (!select.value) return;
        if (signature() !== original && !window.confirm('Load this version over the title, content and excerpt currently in the editor? Other settings are kept.')) return;
        if (select.value === 'local') {
            var data = Object.assign({}, localDraft.fields), token = form.elements.namedItem('_content_revision').value;
            if (localDraft.baseline !== config.baseline) { message('This browser draft is based on an older version. Use a server draft or reopen the original tab to avoid replacing protected code.'); return; }
            if (localDraft.token && token) data.content = data.content.split('data-favorite-protected="' + localDraft.token + '-').join('data-favorite-protected="' + token + '-');
            data.token = token; stage(data); return;
        }
        var parts = select.value.split(':'), requestedSignature = signature();
        restore.disabled = true;
        fetch(config.endpoint + '/snapshot?id=' + config.id + '&kind=' + parts[0] + '&snapshot_id=' + parts[1], {credentials:'same-origin',headers:{'Accept':'application/json'}})
            .then(function(r) { return r.json().then(function(body) { if (!r.ok || !body.success) throw new Error(body.error || 'Could not load version.'); return body; }); })
            .then(function(body) { if (signature() !== requestedSignature) { message('The editor changed while loading. Select Load into editor again.'); return; } stage(body.fields); })
            .catch(function(error) { message(error.message); }).finally(function() { restore.disabled = !select.value; });
    });
    if (config.type === 'page') {
        try { var stored = JSON.parse(localStorage.getItem(localKey) || 'null'); if (stored && stored.fields && JSON.stringify(stored.fields) !== lastSaved) localDraft = stored; else localStorage.removeItem(localKey); } catch(e) {}
    }
    refresh().then(function() { message('Server autosave ready; drafts do not publish changes.'); }).catch(function(error) { serverEnabled = false; populate({drafts:[],history:[]}); message(error.message + ' Browser drafts and normal saves remain available.'); });
});
})();