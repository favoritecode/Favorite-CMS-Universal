<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Favorite CMS &rsaquo; Success</title>
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
            max-width: 600px;
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
            font-size: 20px;
            font-weight: 600;
            color: #1d2327;
            margin-bottom: 0.6em;
            padding-bottom: 0.4em;
            border-bottom: 1px solid #dcdcde;
        }
        p {
            margin-bottom: 1.2em;
            color: #50575e;
            font-size: 14px;
        }
        .success-box {
            background: #edfaef;
            border-left: 4px solid #00a32a;
            padding: 12px 16px;
            margin-bottom: 1.5em;
            font-size: 13.5px;
            color: #1a6826;
        }
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1em;
            margin-bottom: 1.8em;
        }
        .info-table th {
            text-align: left;
            padding: 10px 12px 10px 0;
            width: 130px;
            font-weight: 600;
            color: #1d2327;
        }
        .info-table td {
            padding: 10px 0;
            color: #2c3338;
        }
        .btn-login {
            background: #2271b1;
            border-color: #2271b1;
            color: #fff;
            text-decoration: none;
            text-shadow: none;
            display: inline-block;
            font-size: 14px;
            font-weight: 500;
            line-height: 36px;
            padding: 0 18px;
            cursor: pointer;
            border-width: 1px;
            border-style: solid;
            border-radius: 4px;
            white-space: nowrap;
            transition: background 0.15s ease-in-out;
        }
        .btn-login:hover {
            background: #135e96;
            border-color: #135e96;
            color: #fff;
        }
        .footer-text {
            font-size: 12px;
            color: #646970;
            text-align: center;
        }
    </style>
</head>
<body>

    <div class="install-container">
        <div class="header-logo">
            <h1><span class="star">&#9733;</span> Favorite CMS</h1>
        </div>

        <h2>Success!</h2>
        <div class="success-box">
            Favorite CMS has been installed successfully. Thank you, and enjoy!
        </div>

        <table class="info-table">
            <tr>
                <th scope="row">Site Name:</th>
                <td><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>
            <tr>
                <th scope="row">Username:</th>
                <td><code><?php echo htmlspecialchars($adminUsername, ENT_QUOTES, 'UTF-8'); ?></code></td>
            </tr>
            <tr>
                <th scope="row">Email:</th>
                <td><?php echo htmlspecialchars($adminEmail, ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>
            <tr>
                <th scope="row">Password:</th>
                <td><em>Your chosen password</em></td>
            </tr>
        </table>

        <p>
            <a href="<?php echo htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn-login">Log In</a>
        </p>
    </div>

    <div class="footer-text">
        <p>Favorite CMS &bull; Fast, Secure, Modular</p>
    </div>

</body>
</html>
