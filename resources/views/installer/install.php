<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Favorite CMS &rsaquo; Installation</title>
    <style>
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            background: #f0f0f1;
            color: #3c434a;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            font-size: 14px;
            line-height: 1.5;
            min-height: 100vh;
            padding: 2em 1em;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .install-container {
            background: #fff;
            border: 1px solid #c3c4c7;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            max-width: 700px;
            width: 100%;
            padding: 2.5em;
            margin-bottom: 2em;
        }
        .header-logo {
            text-align: center;
            margin-bottom: 1.5em;
        }
        .header-logo h1 {
            font-size: 28px;
            font-weight: 600;
            color: #1d2327;
            letter-spacing: -0.5px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .header-logo .star {
            color: #e5a00d;
            font-size: 32px;
        }
        h2 {
            font-size: 18px;
            font-weight: 600;
            color: #1d2327;
            margin-bottom: 0.6em;
            padding-bottom: 0.4em;
            border-bottom: 1px solid #dcdcde;
        }
        p {
            margin-bottom: 1.2em;
            color: #50575e;
            font-size: 13.5px;
        }
        .alert {
            padding: 12px 16px;
            border-left: 4px solid #d63638;
            background: #fff;
            box-shadow: 0 1px 1px 0 rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5em;
            font-size: 13px;
        }
        .alert-danger {
            border-color: #d63638;
            background: #fcf0f1;
            color: #8a1f11;
        }
        .alert-danger ul {
            margin-left: 20px;
            margin-top: 6px;
        }
        .alert-danger li {
            margin-bottom: 4px;
        }
        .db-status {
            padding: 10px 14px;
            border-radius: 4px;
            margin-bottom: 1.5em;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .db-status.connected {
            background: #edfaef;
            border: 1px solid #68de7c;
            color: #1a6826;
        }
        .db-status.failed {
            background: #fcf0f1;
            border: 1px solid #f1aeb5;
            color: #8a1f11;
        }
        .form-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1em;
            margin-bottom: 1.5em;
        }
        .form-table th {
            vertical-align: top;
            text-align: left;
            padding: 16px 12px 16px 0;
            width: 180px;
            font-weight: 600;
            color: #1d2327;
            font-size: 14px;
        }
        .form-table td {
            padding: 12px 0;
        }
        .form-table input[type="text"],
        .form-table input[type="email"],
        .form-table input[type="password"] {
            width: 100%;
            max-width: 400px;
            padding: 8px 12px;
            border: 1px solid #8c8f94;
            border-radius: 4px;
            font-size: 14px;
            color: #2c3338;
            background-color: #fff;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
        .form-table input:focus {
            border-color: #2271b1;
            box-shadow: 0 0 0 1px #2271b1;
            outline: 2px solid transparent;
        }
        .form-table .description {
            display: block;
            margin-top: 6px;
            color: #646970;
            font-size: 12.5px;
            line-height: 1.4;
        }
        .btn-submit {
            background: #2271b1;
            border-color: #2271b1;
            color: #fff;
            text-decoration: none;
            text-shadow: none;
            display: inline-block;
            font-size: 14px;
            font-weight: 500;
            min-height: 38px;
            padding: 0 18px;
            cursor: pointer;
            border-width: 1px;
            border-style: solid;
            border-radius: 4px;
            white-space: nowrap;
            transition: background 0.15s ease-in-out;
        }
        .btn-submit:hover {
            background: #135e96;
            border-color: #135e96;
        }
        .btn-submit:disabled {
            background: #a7aaad;
            border-color: #a7aaad;
            cursor: not-allowed;
        }
        .footer-text {
            font-size: 12px;
            color: #646970;
            text-align: center;
        }
        .footer-text a {
            color: #2271b1;
            text-decoration: none;
        }
        .footer-text a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

    <div class="install-container">
        <div class="header-logo">
            <h1><span class="star">&#9733;</span> Favorite CMS</h1>
        </div>

        <h2>Welcome</h2>
        <p>Welcome to the Favorite CMS installation process! Just fill in the information below and you'll be on your way to using your website.</p>

        <?php if (!empty($dbStatus)): ?>
            <?php if ($dbStatus['connected']): ?>
                <div class="db-status connected">
                    <span>&#10003;</span>
                    <strong>Database:</strong> <?php echo htmlspecialchars($dbStatus['message'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php else: ?>
                <div class="db-status failed">
                    <span>&#10007;</span>
                    <div>
                        <strong>Database connection error:</strong>
                        <div style="margin-top: 4px;"><?php echo htmlspecialchars($dbStatus['message'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div style="margin-top: 4px; font-size: 12px; opacity: 0.9;">Please verify the database credentials in your <code>.env</code> file.</div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <strong>Please correct the following errors:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <h2>Information needed</h2>
        <p>Please provide the following information. Don't worry, you can always change these settings later.</p>

        <form method="POST" action="/install">
            <input type="hidden" name="_token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="site_name">Site Name</label></th>
                    <td>
                        <input type="text" name="site_name" id="site_name" value="<?php echo htmlspecialchars($old['site_name'] ?? 'Favorite CMS', ENT_QUOTES, 'UTF-8'); ?>" required autofocus>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="admin_username">Admin Username</label></th>
                    <td>
                        <input type="text" name="admin_username" id="admin_username" value="<?php echo htmlspecialchars($old['admin_username'] ?? 'admin', ENT_QUOTES, 'UTF-8'); ?>" required autocomplete="username">
                        <span class="description">Usernames can contain only alphanumeric characters, underscores, hyphens, and periods.</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="admin_password">Admin Password</label></th>
                    <td>
                        <input type="password" name="admin_password" id="admin_password" required autocomplete="new-password">
                        <span class="description">Choose a secure password (minimum 6 characters).</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="admin_password_confirm">Confirm Password</label></th>
                    <td>
                        <input type="password" name="admin_password_confirm" id="admin_password_confirm" required autocomplete="new-password">
                        <span class="description">Re-type your chosen password to confirm.</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="admin_email">Admin Email</label></th>
                    <td>
                        <input type="email" name="admin_email" id="admin_email" value="<?php echo htmlspecialchars($old['admin_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="admin@example.com" required autocomplete="email">
                        <span class="description">Double-check your email address before continuing.</span>
                    </td>
                </tr>
            </table>

            <p style="margin-top: 1.5em;">
                <button type="submit" class="btn-submit" <?php echo (!empty($dbStatus) && !$dbStatus['connected']) ? 'disabled' : ''; ?>>
                    Install Favorite CMS
                </button>
            </p>
        </form>
    </div>

    <div class="footer-text">
        <p>Favorite CMS &bull; Fast, Secure, Modular</p>
    </div>

</body>
</html>

