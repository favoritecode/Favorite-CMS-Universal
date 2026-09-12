/* Favorite CMS - standalone UI enhancements (installer + authentication).
 * Progressive enhancement only: every form works without this script. */
(function () {
    'use strict';

    var doc = document;

    function one(selector, context) { return (context || doc).querySelector(selector); }
    function all(selector, context) { return Array.prototype.slice.call((context || doc).querySelectorAll(selector)); }

    // ------------------------------------------------------------------
    // Field validation messages
    // ------------------------------------------------------------------
    function validateField(field) {
        if (field.disabled || field.type === 'hidden' || field.type === 'submit' || field.type === 'button' || !field.willValidate) {
            return true;
        }

        var customMessage = '';
        var matchId = field.getAttribute('data-match');
        if (matchId) {
            var other = doc.getElementById(matchId);
            if (other && field.value !== other.value) {
                customMessage = field.getAttribute('data-match-message') || 'The values do not match.';
            }
        }
        field.setCustomValidity(customMessage);

        var valid = field.checkValidity();
        var error = field.id ? doc.getElementById(field.id + '-error') : null;

        if (valid) {
            field.removeAttribute('aria-invalid');
            if (error) { error.textContent = ''; error.hidden = true; }
        } else {
            field.setAttribute('aria-invalid', 'true');
            if (error) {
                error.textContent = customMessage || field.getAttribute('data-error') || field.validationMessage;
                error.hidden = false;
            }
        }

        return valid;
    }

    function firstInvalidWithin(container) {
        var firstInvalid = null;
        all('input, select, textarea', container).forEach(function (field) {
            if (!validateField(field) && !firstInvalid) {
                firstInvalid = field;
            }
        });
        return firstInvalid;
    }

    function focusField(field) {
        var details = field.closest('details');
        if (details) { details.open = true; }
        field.focus();
    }

    doc.addEventListener('input', function (event) {
        var target = event.target;
        if (target && target.getAttribute && target.getAttribute('aria-invalid') === 'true') {
            validateField(target);
        }
    });

    function setBusy(button, label) {
        if (!button) { return; }
        var spinner = doc.createElement('span');
        spinner.className = 'fc-spinner';
        spinner.setAttribute('aria-hidden', 'true');
        button.classList.add('is-busy');
        button.setAttribute('aria-disabled', 'true');
        button.textContent = '';
        button.appendChild(spinner);
        button.appendChild(doc.createTextNode(' ' + label));
    }

    function showOverlay(title, text) {
        var overlay = one('[data-overlay]');
        if (!overlay) { return; }
        var titleEl = one('[data-overlay-title]', overlay);
        var textEl = one('[data-overlay-text]', overlay);
        if (titleEl) { titleEl.textContent = title; }
        if (textEl) { textEl.textContent = text; }
        overlay.hidden = false;
    }

    // ------------------------------------------------------------------
    // Password visibility toggles and requirement checklists
    // ------------------------------------------------------------------
    all('[data-toggle-password]').forEach(function (button) {
        var input = doc.getElementById(button.getAttribute('data-toggle-password'));
        if (!input) { return; }
        var label = button.getAttribute('data-label') || 'password';
        button.hidden = false;
        button.addEventListener('click', function () {
            var reveal = input.type === 'password';
            input.type = reveal ? 'text' : 'password';
            button.textContent = reveal ? 'Hide' : 'Show';
            button.setAttribute('aria-pressed', reveal ? 'true' : 'false');
            button.setAttribute('aria-label', (reveal ? 'Hide ' : 'Show ') + label);
        });
    });

    all('[data-password-rules]').forEach(function (list) {
        var input = doc.getElementById(list.getAttribute('data-password-rules'));
        var confirm = doc.getElementById(list.getAttribute('data-password-confirm') || '');
        if (!input) { return; }

        function update() {
            var value = input.value;
            all('[data-rule]', list).forEach(function (item) {
                var rule = item.getAttribute('data-rule');
                var met = false;
                if (rule.indexOf('min:') === 0) { met = value.length >= parseInt(rule.slice(4), 10); }
                else if (rule === 'letter') { met = /[A-Za-z]/.test(value); }
                else if (rule === 'number') { met = /\d/.test(value); }
                else if (rule === 'match') { met = !!confirm && value !== '' && value === confirm.value; }
                item.classList.toggle('is-met', met);
                var state = one('[data-rule-state]', item);
                if (state) { state.textContent = met ? ' (met)' : ' (not yet met)'; }
            });
        }

        input.addEventListener('input', update);
        if (confirm) { confirm.addEventListener('input', update); }
        update();
    });

    all('[data-toggles]').forEach(function (checkbox) {
        var target = doc.getElementById(checkbox.getAttribute('data-toggles'));
        if (!target) { return; }
        var sync = function () { target.hidden = !checkbox.checked; };
        checkbox.addEventListener('change', sync);
        sync();
    });

    // ------------------------------------------------------------------
    // Drag & drop file selection (native file input remains the fallback)
    // ------------------------------------------------------------------
    function formatSize(bytes) {
        if (bytes >= 1048576) { return (bytes / 1048576).toFixed(1) + ' MB'; }
        if (bytes >= 1024) { return Math.round(bytes / 1024) + ' KB'; }
        return bytes + ' B';
    }

    all('[data-dropzone]').forEach(function (zone) {
        var input = one('input[type="file"]', zone);
        var output = one('[data-file-name]', zone);
        if (!input) { return; }

        function render() {
            var file = input.files && input.files[0];
            if (output) {
                output.hidden = !file;
                output.textContent = file ? 'Selected: ' + file.name + ' (' + formatSize(file.size) + ')' : '';
            }
        }

        input.addEventListener('change', function () { render(); validateField(input); });
        ['dragenter', 'dragover'].forEach(function (name) {
            zone.addEventListener(name, function (event) { event.preventDefault(); zone.classList.add('is-dragover'); });
        });
        ['dragleave', 'dragend'].forEach(function (name) {
            zone.addEventListener(name, function () { zone.classList.remove('is-dragover'); });
        });
        zone.addEventListener('drop', function (event) {
            event.preventDefault();
            zone.classList.remove('is-dragover');
            if (event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files.length) {
                try { input.files = event.dataTransfer.files; } catch (ignore) { /* older browsers: use the file picker */ }
                render();
                validateField(input);
            }
        });
    });

    // ------------------------------------------------------------------
    // Simple enhanced forms (authentication screens)
    // ------------------------------------------------------------------
    all('form[data-enhance-form]').forEach(function (form) {
        form.noValidate = true;
        form.addEventListener('submit', function (event) {
            if (form.getAttribute('data-submitting')) { event.preventDefault(); return; }
            var invalid = firstInvalidWithin(form);
            if (invalid) { event.preventDefault(); focusField(invalid); return; }
            var button = event.submitter || one('[type="submit"]', form);
            form.setAttribute('data-submitting', '1');
            setBusy(button, (button && button.getAttribute('data-busy-label')) || 'Please wait...');
        });
    });

    // ------------------------------------------------------------------
    // Installer step wizard
    // ------------------------------------------------------------------
    var root = doc.getElementById('fc-installer');
    if (!root) { return; }

    var flows = {
        install: ['welcome', 'requirements', 'database', 'site', 'admin', 'review'],
        restore: ['welcome', 'requirements', 'restore']
    };
    var panels = {};
    all('[data-step]', root).forEach(function (panel) { panels[panel.getAttribute('data-step')] = panel; });
    flows.install = flows.install.filter(function (step) { return !!panels[step]; });
    var stepItems = all('[data-step-item]', root);
    var rail = one('.fc-rail', root);
    var progressLabel = one('[data-progress-label]', root);
    var progressBar = one('[data-progress-bar]', root);
    var mode = root.getAttribute('data-initial-mode') === 'restore' ? 'restore' : 'install';
    var current = root.getAttribute('data-initial-step') || 'welcome';
    var furthest = 0;

    all('[data-js-only]', root).forEach(function (el) { el.hidden = false; });
    all('[data-nojs-only]', root).forEach(function (el) { el.hidden = true; });
    all('form', root).forEach(function (form) { form.noValidate = true; });
    if (rail) { rail.classList.add('is-enhanced'); }

    function flow() { return flows[mode]; }

    function stepTitle(step) {
        var item = one('[data-step-item="' + step + '"]', root);
        return item ? item.getAttribute('data-step-title') : step;
    }

    function fillReview() {
        all('[data-review]', root).forEach(function (target) {
            var field = doc.getElementById(target.getAttribute('data-review'));
            var value = field ? field.value.trim() : '';
            target.textContent = value !== '' ? value : (target.getAttribute('data-empty') || 'Not provided');
        });
    }

    function show(step, moveFocus) {
        if (flow().indexOf(step) === -1) {
            mode = flows.restore.indexOf(step) !== -1 && flows.install.indexOf(step) === -1 ? 'restore' : 'install';
        }
        if (flow().indexOf(step) === -1) { step = 'welcome'; }

        current = step;
        var index = flow().indexOf(step);
        furthest = Math.max(furthest, index);

        Object.keys(panels).forEach(function (key) { panels[key].hidden = key !== step; });
        all('[data-show-mode]', root).forEach(function (el) { el.hidden = el.getAttribute('data-show-mode') !== mode; });

        stepItems.forEach(function (item) {
            var name = item.getAttribute('data-step-item');
            var position = flow().indexOf(name);
            var link = one('a', item);
            var number = one('.fc-step__num', item);
            item.hidden = position === -1;
            item.classList.toggle('is-current', name === step);
            item.classList.toggle('is-done', position > -1 && position < index);
            item.classList.toggle('is-locked', position > furthest);
            if (number && position > -1) { number.textContent = String(position + 1); }
            if (link) {
                if (name === step) { link.setAttribute('aria-current', 'step'); } else { link.removeAttribute('aria-current'); }
                if (position > furthest) { link.setAttribute('aria-disabled', 'true'); } else { link.removeAttribute('aria-disabled'); }
            }
        });

        if (progressLabel) { progressLabel.textContent = 'Step ' + (index + 1) + ' of ' + flow().length + ': ' + stepTitle(step); }
        if (progressBar) { progressBar.style.width = Math.round(((index + 1) / flow().length) * 100) + '%'; }
        if (step === 'review') { fillReview(); }

        if (moveFocus) {
            var heading = one('.fc-panel__title', panels[step]);
            if (panels[step].scrollIntoView) { panels[step].scrollIntoView({ block: 'start' }); }
            if (heading) { heading.focus(); }
        }
    }

    function go(target, targetMode) {
        if (targetMode && flows[targetMode]) { mode = targetMode; }
        var from = flow().indexOf(current);
        var to = flow().indexOf(target);
        if (to > from && from > -1 && panels[current]) {
            var invalid = firstInvalidWithin(panels[current]);
            if (invalid) { focusField(invalid); return; }
            if (mode === 'install' && current === 'database' && window.fetch) {
                checkDatabase(target);
                return;
            }
        }
        show(target, true);
    }

    root.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-goto]');
        if (!trigger || !root.contains(trigger)) { return; }
        event.preventDefault();
        if (trigger.getAttribute('aria-disabled') === 'true') { return; }
        go(trigger.getAttribute('data-goto'), trigger.getAttribute('data-mode'));
    });

    var installForm = doc.getElementById('install-form');
    var databaseChecking = false;
    var databaseRevision = 0;

    function clearDatabaseErrors() {
        var errors = doc.getElementById('step-database-errors');
        if (errors) { errors.remove(); }
        all('[data-error-step="database"]', root).forEach(function (item) { item.remove(); });
        all('[data-error-summary]', root).forEach(function (summary) {
            var remaining = all('li', summary).length;
            if (!remaining) { summary.remove(); }
            else {
                var title = one('.fc-alert__title', summary);
                if (title) { title.textContent = remaining === 1 ? 'Please fix the following issue before continuing.' : 'Please fix the following ' + remaining + ' issues before continuing.'; }
            }
        });
        var item = one('[data-step-item="database"]', root);
        if (item) {
            item.classList.remove('has-error');
            all('.fc-visually-hidden', item).forEach(function (label) { label.remove(); });
        }
    }

    function databaseFeedback(message, state) {
        var feedback = doc.getElementById('database-feedback');
        if (!feedback) { return; }
        feedback.textContent = message;
        feedback.className = 'fc-alert fc-alert--' + state;
        feedback.hidden = false;
    }

    function checkDatabase(nextStep) {
        if (databaseChecking || !installForm || !panels.database) { return; }
        var invalid = firstInvalidWithin(panels.database);
        if (invalid) { focusField(invalid); return; }
        databaseChecking = true;
        var revision = databaseRevision;
        var data = new FormData(installForm);
        data.set('db_action', 'test_database');
        data.set('_response', 'json');
        databaseFeedback('Checking database connection...', 'info');
        window.fetch(installForm.action, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(function (result) {
                if (revision !== databaseRevision) { return; }
                clearDatabaseErrors();
                databaseFeedback(result.message || 'Unable to verify the database connection.', result.ok === true ? 'success' : 'error');
                if (result.ok === true && nextStep && current === 'database' && mode === 'install') { show(nextStep, true); }
            })
            .catch(function () {
                if (revision === databaseRevision) { databaseFeedback('Unable to verify the database connection. Please try again.', 'error'); }
            })
            .then(function () { databaseChecking = false; });
    }

    if (panels.database) {
        panels.database.addEventListener('input', function () {
            databaseRevision++;
            clearDatabaseErrors();
            databaseFeedback('Database details changed. Continue or test the connection to verify them.', 'info');
        });
    }
    if (installForm) {
        installForm.addEventListener('submit', function (event) {
            if (installForm.getAttribute('data-submitting')) { event.preventDefault(); return; }
            var button = event.submitter || null;
            var action = button ? button.value : 'install';
            var invalid;

            if (action === 'test_database') {
                invalid = firstInvalidWithin(panels.database);
                if (invalid) { event.preventDefault(); show('database', false); focusField(invalid); return; }
                if (window.fetch) { event.preventDefault(); checkDatabase(null); return; }
                installForm.setAttribute('data-submitting', '1');
                setBusy(button, 'Testing connection...');
                return;
            }

            for (var i = 0; i < flows.install.length; i++) {
                var panel = panels[flows.install[i]];
                if (!panel) { continue; }
                invalid = firstInvalidWithin(panel);
                if (invalid) { event.preventDefault(); mode = 'install'; show(flows.install[i], false); focusField(invalid); return; }
            }

            installForm.setAttribute('data-submitting', '1');
            setBusy(button, 'Installing...');
            showOverlay('Installing Favorite CMS', 'Creating the database tables and your administrator account. This usually takes a few seconds. Please keep this tab open.');
        });
    }

    var restoreForm = doc.getElementById('restore-form');
    if (restoreForm) {
        restoreForm.addEventListener('submit', function (event) {
            if (restoreForm.getAttribute('data-submitting')) { event.preventDefault(); return; }
            var invalid = panels.restore ? firstInvalidWithin(panels.restore) : null;
            if (invalid) { event.preventDefault(); mode = 'restore'; show('restore', false); focusField(invalid); return; }
            restoreForm.setAttribute('data-submitting', '1');
            setBusy(event.submitter || one('[type="submit"]', restoreForm), 'Restoring...');
            showOverlay('Restoring your site', 'Uploading the backup, restoring the database and files, and updating site URLs. Large backups can take a while. Please keep this tab open.');
        });
    }

    show(current, root.getAttribute('data-focus') === '1');
})();
