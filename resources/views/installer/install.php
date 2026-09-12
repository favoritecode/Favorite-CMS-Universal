<?php
['e' => $h, 'field' => $field] = require __DIR__ . '/../partials/standalone/view-helpers.php';

$old = is_array($old ?? null) ? $old : [];
$errors = array_values(array_filter((array)($errors ?? []), 'is_string'));
$notices = array_values(array_filter((array)($notices ?? []), 'is_string'));
$errorGroups = is_array($errorGroups ?? null) ? $errorGroups : [];
$formMode = ($formMode ?? 'install') === 'restore' ? 'restore' : 'install';
$mode = (string)($old['setup_mode'] ?? $dbDefaults['setup_mode'] ?? 'environment');
$manualDatabase = in_array($mode, ['recommended', 'advanced', 'automatic'], true);
$databasePrepared = (bool)($databasePrepared ?? false);
$value = static fn (string $key, string $fallback = ''): string => (string)($old[$key] ?? $dbDefaults[$key] ?? $fallback);

$installSteps = [
    'welcome'      => 'Welcome',
    'requirements' => 'Requirements',
    ...($manualDatabase ? ['database' => 'Advanced Database Setup'] : []),
    'site'         => 'Site Information',
    'admin'        => 'Admin Account',
    'review'       => 'Install',
];
$stepErrors = static fn (string $step): array => array_values(array_filter((array)($errorGroups[$step] ?? []), 'is_string'));

// Map every error to the step that owns it (errors without a group stay in the summary only).
$errorsWithStep = [];
foreach ($errors as $message) {
    $owner = null;
    foreach ($errorGroups as $step => $messages) {
        if (in_array($message, (array)$messages, true)) {
            $owner = (string)$step;
            break;
        }
    }
    $errorsWithStep[] = [$message, $owner];
}

$initialStep = 'welcome';
foreach (['database', 'site', 'admin', 'review', 'restore'] as $candidate) {
    if ($stepErrors($candidate) !== []) {
        $initialStep = $candidate;
        break;
    }
}
if ($initialStep === 'welcome' && !empty($focusStep) && is_string($focusStep)) {
    $initialStep = $focusStep;
}
if ($initialStep === 'welcome' && $errors !== []) {
    $initialStep = $formMode === 'restore' ? 'restore' : 'review';
}
if ($initialStep === 'restore') {
    $formMode = 'restore';
}

$statusLabels = ['pass' => 'Pass', 'warn' => 'Warning', 'fail' => 'Failed'];
$counts = ['pass' => 0, 'warn' => 0, 'fail' => 0];
foreach ($checks as $check) {
    $status = isset($statusLabels[$check['status'] ?? '']) ? $check['status'] : 'fail';
    $counts[$status]++;
}

$renderStepErrors = static function (string $step) use ($stepErrors, $h): string {
    $messages = $stepErrors($step);
    if ($messages === []) {
        return '';
    }
    $html = '<div class="fc-alert fc-alert--error" id="step-' . $h($step) . '-errors"><p class="fc-alert__title">Please review this step</p><ul>';
    foreach ($messages as $message) {
        $html .= '<li>' . $h($message) . '</li>';
    }
    return $html . '</ul></div>';
};

$advancedOpen = in_array($mode, ['advanced', 'automatic'], true) || $stepErrors('database') !== [];
$dbState = (string)($dbStatus['state'] ?? '');
$uploadLimit = (string)ini_get('upload_max_filesize');
$postLimit = (string)ini_get('post_max_size');
$pageTitle = 'Install Favorite CMS';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../partials/standalone/head.php'; ?>
</head>
<body>
<a class="fc-skip-link" href="#fc-main">Skip to the installer</a>

<div class="fc-installer" id="fc-installer"
     data-initial-step="<?php echo $h($initialStep); ?>"
     data-initial-mode="<?php echo $h($formMode); ?>"
     data-focus="<?php echo ($errors !== [] || $notices !== []) ? '1' : '0'; ?>">

    <aside class="fc-rail">
        <div class="fc-rail__top">
            <span class="fc-brand"><span class="fc-brand__mark" aria-hidden="true">&#9733;</span><span class="fc-brand__name">Favorite CMS</span></span>

            <div class="fc-rail__progress" data-js-only hidden>
                <span data-progress-label>Step 1 of <?php echo count($installSteps); ?>: Welcome</span>
                <div class="fc-rail__bar" aria-hidden="true"><span data-progress-bar></span></div>
            </div>

            <nav aria-label="Installation steps">
                <ol class="fc-steps">
                    <?php $number = 0; foreach ($installSteps as $key => $title): $number++; $flagged = $stepErrors($key) !== []; ?>
                        <li class="fc-step<?php echo $flagged ? ' has-error' : ''; ?>" data-step-item="<?php echo $h($key); ?>" data-step-title="<?php echo $h($title); ?>">
                            <a href="#step-<?php echo $h($key); ?>" data-goto="<?php echo $h($key); ?>"<?php echo in_array($key, ['welcome', 'requirements'], true) ? '' : ' data-mode="install"'; ?>>
                                <span class="fc-step__num" aria-hidden="true"><?php echo $number; ?></span>
                                <span><?php echo $h($title); ?><?php if ($flagged): ?><span class="fc-visually-hidden"> (needs attention)</span><?php endif; ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                    <?php $restoreFlagged = $stepErrors('restore') !== []; ?>
                    <li class="fc-step<?php echo $restoreFlagged ? ' has-error' : ''; ?>" data-step-item="restore" data-step-title="Restore backup">
                        <a href="<?php echo $h($installAction . '?mode=restore'); ?>"<?php echo $formMode === 'restore' ? ' data-goto="restore" data-mode="restore"' : ''; ?>>
                            <span class="fc-step__num" aria-hidden="true">&#8634;</span>
                            <span>Restore backup<?php if ($restoreFlagged): ?><span class="fc-visually-hidden"> (needs attention)</span><?php endif; ?></span>
                        </a>
                    </li>
                </ol>
            </nav>
        </div>
        <p class="fc-rail__note">Favorite CMS Universal<?php echo defined('APP_VERSION') ? ' v' . $h(APP_VERSION) : ''; ?><br>Protected by CSRF verification. Nothing is written until you install or restore.</p>
    </aside>

    <main class="fc-main" id="fc-main" tabindex="-1">
        <div class="fc-main__inner">
            <h1 class="fc-visually-hidden">Favorite CMS installation</h1>

            <?php if ($errors !== []): ?>
                <div class="fc-alert fc-alert--error" role="alert" data-error-summary>
                    <p class="fc-alert__title"><?php echo count($errors) === 1 ? 'Please fix the following issue before continuing.' : 'Please fix the following ' . count($errors) . ' issues before continuing.'; ?></p>
                    <ul>
                        <?php $linkedOwners = []; foreach ($errorsWithStep as [$message, $owner]): ?>
                            <li data-error-step="<?php echo $h($owner ?? ''); ?>">
                                <?php echo $h($message); ?>
                                <?php if ($owner !== null && !isset($linkedOwners[$owner])): $linkedOwners[$owner] = true; ?>
                                    <a href="#step-<?php echo $h($owner); ?>" data-goto="<?php echo $h($owner); ?>"<?php echo $owner === 'restore' ? ' data-mode="restore"' : ' data-mode="install"'; ?>>Go to <?php echo $h($installSteps[$owner] ?? 'Restore backup'); ?></a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php foreach ($notices as $notice): ?>
                <div class="fc-alert <?php echo stripos($notice, 'successfully') !== false ? 'fc-alert--success' : 'fc-alert--info'; ?>" role="status"><?php echo $h($notice); ?></div>
            <?php endforeach; ?>

            <!-- Welcome -->
            <section class="fc-panel" id="step-welcome" data-step="welcome" aria-labelledby="step-welcome-title">
                <header class="fc-panel__head">
                    <p class="fc-panel__eyebrow">Step 1 &middot; Welcome</p>
                    <h2 class="fc-panel__title" id="step-welcome-title" tabindex="-1">Let&rsquo;s set up Favorite CMS</h2>
                    <p class="fc-panel__lead">This installer checks your server, connects your database and creates your administrator account. It usually takes about two minutes.</p>
                </header>

                <div class="fc-choices">
                    <a class="fc-choice" href="<?php echo $h($installAction); ?>"<?php echo $formMode === 'install' ? ' data-goto="requirements" data-mode="install"' : ''; ?>>
                        <span class="fc-pill fc-pill--pass fc-choice__tag">Recommended</span>
                        <span class="fc-choice__title">Fresh installation</span>
                        <span class="fc-choice__text">Start a new website with an empty database.</span>
                    </a>
                    <a class="fc-choice" href="<?php echo $h($installAction . '?mode=restore'); ?>"<?php echo $formMode === 'restore' ? ' data-goto="requirements" data-mode="restore"' : ''; ?>>
                        <span class="fc-choice__title">Restore from a backup</span>
                        <span class="fc-choice__text">Move an existing Favorite CMS site to this server using its backup .zip archive.</span>
                    </a>
                </div>
            </section>

            <!-- Requirements -->
            <section class="fc-panel" id="step-requirements" data-step="requirements" aria-labelledby="step-requirements-title">
                <header class="fc-panel__head">
                    <p class="fc-panel__eyebrow">Step 2 &middot; Requirements</p>
                    <h2 class="fc-panel__title" id="step-requirements-title" tabindex="-1">System requirements</h2>
                    <p class="fc-panel__lead">Your server environment, required PHP extensions and writable directories.</p>
                </header>

                <div class="fc-summary">
                    <?php if ($counts['fail'] > 0): ?>
                        <span class="fc-pill fc-pill--fail"><?php echo (int)$counts['fail']; ?> failed</span>
                    <?php else: ?>
                        <span class="fc-pill fc-pill--pass">All required checks passed</span>
                    <?php endif; ?>
                    <?php if ($counts['warn'] > 0): ?>
                        <span class="fc-pill fc-pill--warn"><?php echo (int)$counts['warn']; ?> <?php echo $counts['warn'] === 1 ? 'warning' : 'warnings'; ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($hasRequirementFailures): ?>
                    <div class="fc-alert fc-alert--error">
                        <p class="fc-alert__title">Installation is blocked</p>
                        <p>Resolve the failed checks below (for example, enable the PHP extension or make the directory writable), then reload this page.</p>
                    </div>
                <?php endif; ?>

                <ul class="fc-reqs">
                    <?php foreach ($checks as $check): $status = isset($statusLabels[$check['status'] ?? '']) ? $check['status'] : 'fail'; ?>
                        <li class="fc-req fc-req--<?php echo $h($status); ?>">
                            <span class="fc-pill fc-pill--<?php echo $h($status); ?>"><?php echo $h($statusLabels[$status]); ?></span>
                            <span class="fc-req__body">
                                <span class="fc-req__label"><?php echo $h($check['label']); ?></span>
                                <span class="fc-req__msg"><?php echo $h($check['message']); ?></span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <div class="fc-panel__actions">
                    <button type="button" class="fc-btn fc-btn--secondary" data-goto="welcome" data-js-only hidden>Back</button>
                    <div class="fc-panel__actions-end">
                        <?php if (!$databasePrepared && $formMode !== 'restore'): ?>
                            <form id="database-prepare-form" method="POST" action="<?php echo $h($installAction); ?>" data-show-mode="install">
                                <input type="hidden" name="_token" value="<?php echo $h($token); ?>">
                                <input type="hidden" name="setup_mode" value="environment">
                                <button type="submit" name="db_action" value="prepare_database" class="fc-btn fc-btn--primary"<?php echo $hasRequirementFailures ? ' disabled' : ''; ?>>Continue</button>
                            </form>
                        <?php else: ?>
                            <button type="button" class="fc-btn fc-btn--primary" data-goto="<?php echo $manualDatabase ? 'database' : 'site'; ?>" data-mode="install" data-show-mode="install" data-js-only hidden>Continue</button>
                        <?php endif; ?>
                        <button type="button" class="fc-btn fc-btn--primary" data-goto="restore" data-mode="restore" data-show-mode="restore" data-js-only hidden>Continue</button>
                    </div>
                </div>
            </section>

            <!-- Fresh installation -->
            <?php if ($databasePrepared && $formMode !== 'restore'): ?>
            <form id="install-form" method="POST" action="<?php echo $h($installAction); ?>" autocomplete="off" class="fc-main__inner">
                <input type="hidden" name="_token" value="<?php echo $h($token); ?>">
                <input type="hidden" name="setup_mode" value="<?php echo $manualDatabase ? 'advanced' : 'environment'; ?>">

                <?php if ($manualDatabase): ?>
                <section class="fc-panel" id="step-database" data-step="database" aria-labelledby="step-database-title">
                    <header class="fc-panel__head">
                        <p class="fc-panel__eyebrow">Step 3 &middot; Database</p>
                        <h2 class="fc-panel__title" id="step-database-title" tabindex="-1">Advanced Database Setup</h2>
                        <p class="fc-panel__lead">Enter the MySQL or MariaDB credentials from your hosting control panel. Favorite CMS creates all of its tables automatically.</p>
                    </header>

                    <?php echo $renderStepErrors('database'); ?>

                    <div id="database-feedback" class="fc-alert fc-alert--info" role="status"<?php echo empty($old['database_fallback_notice']) ? ' hidden' : ''; ?>><?php echo $h((string)($old['database_fallback_notice'] ?? '')); ?></div>

                    <?php if ($dbState === 'installed'): ?>
                        <div class="fc-alert fc-alert--warning"><p><?php echo $h($dbStatus['message']); ?></p></div>
                    <?php elseif ($dbState === 'partial'): ?>
                        <div class="fc-alert fc-alert--info"><p><?php echo $h($dbStatus['message']); ?></p></div>
                    <?php endif; ?>

                    <fieldset class="fc-fieldset">
                        <legend class="fc-legend">Credentials</legend>
                        <div class="fc-grid-2">
                            <?php echo $field(['id' => 'db_name', 'label' => 'Database name', 'value' => $value('db_name'), 'required' => true, 'placeholder' => 'e.g. u123456_fvcms', 'hint' => 'The database created in your hosting control panel.', 'attrs' => ['data-error' => 'Enter the database name.']]); ?>
                            <?php echo $field(['id' => 'db_username', 'label' => 'Database username', 'value' => $value('db_username'), 'required' => true, 'placeholder' => 'e.g. u123456_admin', 'autocomplete' => 'off', 'hint' => 'The database user assigned to that database.', 'attrs' => ['data-error' => 'Enter the database username.']]); ?>
                        </div>
                        <?php echo $field(['id' => 'db_password', 'label' => 'Database password', 'type' => 'password', 'optional' => true, 'autocomplete' => 'new-password', 'toggle' => true, 'hint' => 'Leave empty only for local servers whose database user has no password (for example XAMPP root).']); ?>
                    </fieldset>

                    <details class="fc-details"<?php echo $advancedOpen ? ' open' : ''; ?>>
                        <summary>Advanced settings: host, port, table prefix and automatic creation</summary>
                        <div class="fc-details__body">
                            <div class="fc-grid-2">
                                <?php echo $field(['id' => 'db_host', 'label' => 'Database host', 'value' => $value('db_host', 'localhost'), 'required' => true, 'hint_html' => 'Almost always <code>localhost</code> on shared hosting and XAMPP.', 'attrs' => ['data-error' => 'Enter the database host.']]); ?>
                                <?php echo $field(['id' => 'db_port', 'label' => 'Database port', 'value' => $value('db_port', '3306'), 'required' => true, 'hint_html' => 'The standard MySQL port is <code>3306</code>.', 'attrs' => ['inputmode' => 'numeric', 'pattern' => '[0-9]{1,5}', 'data-error' => 'Enter a port number such as 3306.']]); ?>
                            </div>
                            <?php echo $field(['id' => 'db_prefix', 'label' => 'Table prefix', 'value' => $value('db_prefix'), 'required' => true, 'hint' => 'Keeps these tables separate when several sites share one database. Generated automatically.', 'attrs' => ['pattern' => '[A-Za-z][A-Za-z0-9_]*', 'data-error' => 'Use letters, numbers and underscores, starting with a letter.']]); ?>

                            <div class="fc-subpanel">
                                <label class="fc-check" for="auto_create_toggle">
                                    <input type="checkbox" id="auto_create_toggle" name="setup_mode" value="automatic" data-toggles="auto-create-fields"<?php echo $mode === 'automatic' ? ' checked' : ''; ?>>
                                    <span><strong>Create the database automatically</strong><br>Uses a privileged MySQL account (VPS or local server). Not available on typical shared hosting. When enabled, the credentials above become the new database user.</span>
                                </label>
                                <div class="fc-grid-2" id="auto-create-fields">
                                    <?php echo $field(['id' => 'db_admin_username', 'label' => 'Privileged username', 'optional' => true, 'placeholder' => 'root', 'autocomplete' => 'off']); ?>
                                    <?php echo $field(['id' => 'db_admin_password', 'label' => 'Privileged password', 'type' => 'password', 'optional' => true, 'autocomplete' => 'new-password', 'toggle' => true]); ?>
                                </div>
                            </div>
                        </div>
                    </details>

                    <div class="fc-panel__actions">
                        <button type="button" class="fc-btn fc-btn--secondary" data-goto="requirements" data-js-only hidden>Back</button>
                        <div class="fc-panel__actions-end">
                            <button type="submit" name="db_action" value="test_database" class="fc-btn fc-btn--secondary" formnovalidate>Test Database Connection</button>
                            <button type="button" class="fc-btn fc-btn--primary" data-goto="site" data-js-only hidden>Continue</button>
                        </div>
                    </div>
                </section>

                <?php endif; ?>
                <section class="fc-panel" id="step-site" data-step="site" aria-labelledby="step-site-title">
                    <header class="fc-panel__head">
                        <p class="fc-panel__eyebrow">Step <?php echo $manualDatabase ? 4 : 3; ?> &middot; Site</p>
                        <h2 class="fc-panel__title" id="step-site-title" tabindex="-1">Site Information</h2>
                        <p class="fc-panel__lead">You can change the site name at any time from the dashboard.</p>
                    </header>

                    <?php echo $renderStepErrors('site'); ?>

                    <div class="fc-grid-2">
                        <?php echo $field(['id' => 'site_name', 'label' => 'Site name', 'value' => $value('site_name', 'Favorite CMS'), 'required' => true, 'hint' => 'Shown in the browser title, header and emails.', 'attrs' => ['data-error' => 'Enter a site name.']]); ?>
                        <?php echo $field(['id' => 'site_url', 'label' => 'Site URL', 'value' => $value('site_url', (string)$detectedUrl), 'required' => true, 'hint' => 'Detected from the address you used to open this installer, including any subdirectory.', 'attrs' => ['inputmode' => 'url', 'data-error' => 'Enter the full site address, for example https://example.com/.']]); ?>
                    </div>

                    <div class="fc-panel__actions" data-js-only hidden>
                        <button type="button" class="fc-btn fc-btn--secondary" data-goto="<?php echo $manualDatabase ? 'database' : 'requirements'; ?>">Back</button>
                        <div class="fc-panel__actions-end">
                            <button type="button" class="fc-btn fc-btn--primary" data-goto="admin">Continue</button>
                        </div>
                    </div>
                </section>

                <section class="fc-panel" id="step-admin" data-step="admin" aria-labelledby="step-admin-title">
                    <header class="fc-panel__head">
                        <p class="fc-panel__eyebrow">Step <?php echo $manualDatabase ? 5 : 4; ?> &middot; Administrator</p>
                        <h2 class="fc-panel__title" id="step-admin-title" tabindex="-1">Admin Account</h2>
                        <p class="fc-panel__lead">This account gets full access to the dashboard. Choose a strong password and keep it safe.</p>
                    </header>

                    <?php echo $renderStepErrors('admin'); ?>

                    <div class="fc-grid-2">
                        <?php echo $field(['id' => 'admin_username', 'label' => 'Username', 'value' => $value('admin_username'), 'required' => true, 'autocomplete' => 'username', 'placeholder' => 'e.g. admin', 'hint' => '3-60 characters: letters, numbers, dots, dashes or underscores.', 'attrs' => ['pattern' => '[A-Za-z0-9_.\-]{3,60}', 'data-error' => 'Use 3-60 letters, numbers, dots, dashes or underscores.']]); ?>
                        <?php echo $field(['id' => 'admin_email', 'label' => 'Email address', 'type' => 'email', 'value' => $value('admin_email'), 'required' => true, 'autocomplete' => 'email', 'placeholder' => 'you@example.com', 'hint' => 'Used for account recovery and notifications.', 'attrs' => ['data-error' => 'Enter a valid email address.']]); ?>
                        <?php echo $field(['id' => 'admin_password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'toggle' => true, 'describedby' => ['admin-password-rules'], 'attrs' => ['minlength' => '10', 'pattern' => '(?=.*[A-Za-z])(?=.*[0-9]).{10,}', 'data-error' => 'Use at least 10 characters, including a letter and a number.']]); ?>
                        <?php echo $field(['id' => 'admin_password_confirm', 'label' => 'Confirm password', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'toggle' => true, 'attrs' => ['data-match' => 'admin_password', 'data-match-message' => 'The passwords do not match.', 'data-error' => 'Re-enter the password.']]); ?>
                    </div>

                    <ul class="fc-rules" id="admin-password-rules" data-password-rules="admin_password" data-password-confirm="admin_password_confirm" aria-label="Password requirements">
                        <li data-rule="min:10">At least 10 characters<span class="fc-visually-hidden" data-rule-state></span></li>
                        <li data-rule="letter">Contains a letter<span class="fc-visually-hidden" data-rule-state></span></li>
                        <li data-rule="number">Contains a number<span class="fc-visually-hidden" data-rule-state></span></li>
                        <li data-rule="match">Both passwords match<span class="fc-visually-hidden" data-rule-state></span></li>
                    </ul>

                    <div class="fc-panel__actions" data-js-only hidden>
                        <button type="button" class="fc-btn fc-btn--secondary" data-goto="site">Back</button>
                        <div class="fc-panel__actions-end">
                            <button type="button" class="fc-btn fc-btn--primary" data-goto="review">Review</button>
                        </div>
                    </div>
                </section>

                <section class="fc-panel" id="step-review" data-step="review" aria-labelledby="step-review-title">
                    <header class="fc-panel__head">
                        <p class="fc-panel__eyebrow">Step <?php echo $manualDatabase ? 6 : 5; ?> &middot; Install</p>
                        <h2 class="fc-panel__title" id="step-review-title" tabindex="-1">Install</h2>
                        <p class="fc-panel__lead">Installing verifies the database connection, creates all tables, creates your administrator account and locks the installer.</p>
                    </header>

                    <?php echo $renderStepErrors('review'); ?>

                    <dl class="fc-review" data-js-only hidden>
                        <div class="fc-review__row"><dt>Site name</dt><dd data-review="site_name"></dd></div>
                        <div class="fc-review__row"><dt>Site URL</dt><dd data-review="site_url"></dd></div>
                        <div class="fc-review__row"><dt>Administrator</dt><dd data-review="admin_username"></dd></div>
                        <div class="fc-review__row"><dt>Administrator email</dt><dd data-review="admin_email"></dd></div>
                    </dl>
                    <p class="fc-hint" data-nojs-only>Check the details you entered above, then install. Passwords are never shown.</p>

                    <?php if ($hasRequirementFailures): ?>
                        <div class="fc-alert fc-alert--error"><p>Installation is disabled because some server requirements failed. Resolve them and reload this page.</p></div>
                    <?php endif; ?>

                    <div class="fc-panel__actions">
                        <button type="button" class="fc-btn fc-btn--secondary" data-goto="admin" data-js-only hidden>Back</button>
                        <div class="fc-panel__actions-end">
                            <button type="submit" id="btn-install-submit" name="db_action" value="install" class="fc-btn fc-btn--primary fc-btn--lg"<?php echo $hasRequirementFailures ? ' disabled' : ''; ?>>Install Favorite CMS</button>
                        </div>
                    </div>
                </section>
            </form>
            <?php endif; ?>

            <!-- Restore from backup -->
            <?php if ($formMode === 'restore'): ?>
            <form id="restore-form" method="POST" action="<?php echo $h($installAction); ?>" enctype="multipart/form-data" autocomplete="off" class="fc-main__inner">
                <input type="hidden" name="_token" value="<?php echo $h($token); ?>">
                <input type="hidden" name="db_action" value="restore">

                <section class="fc-panel" id="step-restore" data-step="restore" aria-labelledby="step-restore-title">
                    <header class="fc-panel__head">
                        <p class="fc-panel__eyebrow">Restore from a backup</p>
                        <h2 class="fc-panel__title" id="step-restore-title" tabindex="-1">Restore an existing site</h2>
                        <p class="fc-panel__lead">Upload a <code>favorite_cms_backup_*.zip</code> archive. Database tables, media uploads, themes and plugins are restored and stored site links are updated to the address below.</p>
                    </header>

                    <?php echo $renderStepErrors('restore'); ?>

                    <div class="fc-alert fc-alert--warning">
                        <p>Restoring writes into the target database. Existing Favorite CMS tables with the same prefix are overwritten.</p>
                    </div>

                    <div class="fc-field">
                        <div class="fc-dropzone" data-dropzone>
                            <p class="fc-dropzone__title">Drop your backup .zip here</p>
                            <label class="fc-label" for="backup_file">or choose the backup archive <span class="fc-label__req" aria-hidden="true">*</span></label>
                            <input type="file" id="backup_file" name="backup_file" accept=".zip,application/zip" required aria-describedby="backup_file-hint backup_file-error" data-error="Choose a Favorite CMS backup .zip archive.">
                            <p class="fc-dropzone__file" data-file-name hidden></p>
                        </div>
                        <p class="fc-hint" id="backup_file-hint">This server accepts uploads up to <?php echo $h($uploadLimit !== '' ? $uploadLimit : 'unknown'); ?> per file (request limit <?php echo $h($postLimit !== '' ? $postLimit : 'unknown'); ?>).</p>
                        <p class="fc-field-error" id="backup_file-error" hidden></p>
                    </div>

                    <fieldset class="fc-fieldset">
                        <legend class="fc-legend">Target database</legend>
                        <div class="fc-grid-2">
                            <?php echo $field(['id' => 'res_db_name', 'name' => 'db_name', 'label' => 'Database name', 'value' => $value('db_name'), 'required' => true, 'attrs' => ['data-error' => 'Enter the database name.']]); ?>
                            <?php echo $field(['id' => 'res_db_username', 'name' => 'db_username', 'label' => 'Database username', 'value' => $value('db_username'), 'required' => true, 'autocomplete' => 'off', 'attrs' => ['data-error' => 'Enter the database username.']]); ?>
                        </div>
                        <?php echo $field(['id' => 'res_db_password', 'name' => 'db_password', 'label' => 'Database password', 'type' => 'password', 'optional' => true, 'autocomplete' => 'new-password', 'toggle' => true]); ?>
                        <?php echo $field(['id' => 'res_site_url', 'name' => 'site_url', 'label' => 'New site URL', 'value' => $value('site_url', (string)$detectedUrl), 'required' => true, 'hint' => 'All stored links in the backup are migrated to this address.', 'attrs' => ['inputmode' => 'url', 'data-error' => 'Enter the full site address.']]); ?>
                    </fieldset>

                    <div class="fc-panel__actions">
                        <button type="button" class="fc-btn fc-btn--secondary" data-goto="requirements" data-js-only hidden>Back</button>
                        <div class="fc-panel__actions-end">
                            <button type="submit" class="fc-btn fc-btn--primary">Restore &amp; Migrate Site</button>
                        </div>
                    </div>
                </section>
            </form>

            <?php endif; ?>
            <p class="fc-footer-note">Favorite CMS Universal installer &middot; responses are sent with no-cache headers.</p>
        </div>
    </main>
</div>

<div class="fc-overlay" data-overlay hidden>
    <div class="fc-overlay__card" role="status" aria-live="polite">
        <span class="fc-spinner" aria-hidden="true"></span>
        <p class="fc-choice__title" data-overlay-title>Working&hellip;</p>
        <p class="fc-hint" data-overlay-text></p>
    </div>
</div>

<?php include __DIR__ . '/../partials/standalone/scripts.php'; ?>
</body>
</html>
