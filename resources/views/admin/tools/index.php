<div class="page-header">
    <h1 class="page-title">Tools & System Status</h1>
</div>

<div class="form-row">
    <!-- Export / Backup -->
    <div class="form-card">
        <h2 style="font-size: 16px; font-weight: 600; margin-bottom: 12px;">Export Site Database Backup</h2>
        <p style="color: var(--wp-text-muted); font-size: 13px; margin-bottom: 16px;">
            Download a portable JSON snapshot containing your site's full database tables, settings, posts, pages, and users.
        </p>
        <a href="/admin/tools/export" class="btn btn-primary">&#128229; Download Database Backup (.json)</a>
    </div>

    <!-- Diagnostic Health -->
    <div class="form-card">
        <h2 style="font-size: 16px; font-weight: 600; margin-bottom: 12px;">System Environment Health</h2>
        <p style="color: var(--wp-text-muted); font-size: 13px; margin-bottom: 16px;">
            Verify your server meets all requirements for shared hosting stability.
        </p>
        <table class="wp-table">
            <tbody>
                <?php foreach ($diagnostics as $key => $val): ?>
                    <tr>
                        <td style="font-weight: 600; width: 160px;"><?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

