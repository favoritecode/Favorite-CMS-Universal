<?php

declare(strict_types=1);

namespace FavoriteCMS\Http\Controllers\Admin;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Services\Update\UpdateManager;
use Throwable;

class UpdateController
{
    protected Application $app;
    protected UpdateManager $updateManager;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->updateManager = new UpdateManager($app);
    }

    public function index(Request $request): Response
    {
        $forceCheck = ($request->get('refresh') === '1');
        $discovery = $this->updateManager->getDiscovery()->check($forceCheck);
        $health = $this->updateManager->preUpdateCheck();
        $state = $this->updateManager->getState();
        $logs = $this->updateManager->getLogs(30);

        $pendingPackage = $_SESSION['_pending_update_package'] ?? null;

        // Flash notices
        $notice = $_SESSION['_flash_notice'] ?? null;
        $error = $_SESSION['_flash_error'] ?? null;
        unset($_SESSION['_flash_notice'], $_SESSION['_flash_error']);

        $viewData = [
            'pageTitle'       => 'Core Updates',
            'activeMenu'      => 'updates',
            'currentVersion'  => defined('APP_VERSION') ? APP_VERSION : '1.0.0-beta',
            'discovery'       => $discovery,
            'health'          => $health,
            'state'           => $state,
            'logs'            => $logs,
            'pendingPackage'  => $pendingPackage,
            'inProgress'      => $this->updateManager->isUpdateInProgress(),
            'notice'          => $notice,
            'error'           => $error,
            'csrfToken'       => $_SESSION['_token'] ?? '',
            'contentView'     => APP_ROOT . '/resources/views/admin/updates/index.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    public function check(Request $request): Response
    {
        $this->validateCsrf($request);

        try {
            $discovery = $this->updateManager->getDiscovery()->check(true);
            if ($discovery['update_available']) {
                $_SESSION['_flash_notice'] = "A new Core release is available: v{$discovery['latest_version']}.";
            } else {
                $_SESSION['_flash_notice'] = "Your Favorite CMS Universal installation is up to date (version " . (defined('APP_VERSION') ? APP_VERSION : '1.0.0') . ").";
            }
        } catch (Throwable $e) {
            $_SESSION['_flash_error'] = "Failed to check for updates: " . $e->getMessage();
        }

        return Response::redirect('/admin/updates');
    }

    public function upload(Request $request): Response
    {
        $this->validateCsrf($request);

        if ($this->updateManager->isUpdateInProgress()) {
            $_SESSION['_flash_error'] = "Cannot upload a new package while an update is currently in progress.";
            return Response::redirect('/admin/updates');
        }

        if (empty($_FILES['update_package']['name']) || !is_uploaded_file($_FILES['update_package']['tmp_name'])) {
            $_SESSION['_flash_error'] = "Please select a valid Favorite CMS update ZIP file to upload.";
            return Response::redirect('/admin/updates');
        }

        $tmpUpload = $_FILES['update_package']['tmp_name'];
        $origName = basename((string)$_FILES['update_package']['name']);

        if (!str_ends_with(strtolower($origName), '.zip')) {
            $_SESSION['_flash_error'] = "Only .zip packages are accepted for Core updates.";
            return Response::redirect('/admin/updates');
        }

        $tempDir = APP_ROOT . '/storage/temp';
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $stagedZip = $tempDir . '/manual_update_' . bin2hex(random_bytes(6)) . '.zip';
        if (!move_uploaded_file($tmpUpload, $stagedZip)) {
            $_SESSION['_flash_error'] = "Failed to move uploaded update package to temporary staging.";
            return Response::redirect('/admin/updates');
        }

        // Validate package immediately
        $validation = $this->updateManager->getValidator()->validate($stagedZip);
        if (!$validation['valid']) {
            @unlink($stagedZip);
            $_SESSION['_flash_error'] = "Invalid update package: " . implode('; ', $validation['errors']);
            return Response::redirect('/admin/updates');
        }

        $_SESSION['_pending_update_package'] = [
            'path'        => $stagedZip,
            'filename'    => $origName,
            'version'     => $validation['version'],
            'product'     => $validation['product'],
            'size'        => $validation['size'],
            'sha256'      => $validation['sha256'],
            'uploaded_at' => date('c'),
        ];

        $_SESSION['_flash_notice'] = "Package validated successfully! Ready to update to version {$validation['version']}. Please review details and confirm below.";
        return Response::redirect('/admin/updates');
    }

    public function apply(Request $request): Response
    {
        $this->validateCsrf($request);

        $source = (string)$request->post('source', 'manual');
        $zipPath = null;
        $expectedSha256 = null;

        if ($source === 'manual') {
            $pending = $_SESSION['_pending_update_package'] ?? null;
            if (!$pending || empty($pending['path']) || !is_file($pending['path'])) {
                $_SESSION['_flash_error'] = "No valid pending update package found. Please upload the package again.";
                return Response::redirect('/admin/updates');
            }
            $zipPath = $pending['path'];
            $expectedSha256 = $pending['sha256'];
        } elseif ($source === 'remote') {
            // Remote package download from GitHub
            $discovery = $this->updateManager->getDiscovery()->check(false);
            if (empty($discovery['download_url'])) {
                $_SESSION['_flash_error'] = "Remote package download URL is not available.";
                return Response::redirect('/admin/updates');
            }

            $tempDir = APP_ROOT . '/storage/temp';
            if (!is_dir($tempDir)) {
                @mkdir($tempDir, 0775, true);
            }
            $zipPath = $tempDir . '/remote_update_' . bin2hex(random_bytes(6)) . '.zip';

            try {
                $this->updateManager->getDiscovery()->downloadPackage($discovery['download_url'], $zipPath);
                $expectedSha256 = $discovery['sha256'] ?: null;
            } catch (Throwable $e) {
                $_SESSION['_flash_error'] = "Failed to download update package: " . $e->getMessage();
                return Response::redirect('/admin/updates');
            }
        } else {
            $_SESSION['_flash_error'] = "Invalid update source requested.";
            return Response::redirect('/admin/updates');
        }

        try {
            $result = $this->updateManager->runUpdate($zipPath, [
                'expected_sha256' => $expectedSha256,
            ]);

            // Clear pending upload state
            unset($_SESSION['_pending_update_package']);
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }

            $_SESSION['_flash_notice'] = "Core update successfully applied! Upgraded from {$result['previous_version']} to {$result['updated_version']}. A full pre-update backup was saved as '{$result['backup_file']}'.";

        } catch (Throwable $e) {
            $_SESSION['_flash_error'] = "Core update failed: " . $e->getMessage();
        }

        return Response::redirect('/admin/updates');
    }

    public function cancelUpload(Request $request): Response
    {
        $this->validateCsrf($request);

        $pending = $_SESSION['_pending_update_package'] ?? null;
        if ($pending && !empty($pending['path']) && is_file($pending['path'])) {
            @unlink($pending['path']);
        }
        unset($_SESSION['_pending_update_package']);

        $_SESSION['_flash_notice'] = "Pending update package cleared.";
        return Response::redirect('/admin/updates');
    }

    public function status(Request $request): Response
    {
        $state = $this->updateManager->getState();
        $state['in_progress'] = $this->updateManager->isUpdateInProgress();
        $state['logs'] = $this->updateManager->getLogs(15);

        return Response::json($state, 200);
    }

    protected function validateCsrf(Request $request): void
    {
        $token = (string)$request->post('_token', '');
        $sessionToken = (string)($_SESSION['_token'] ?? '');

        if ($token === '' || !hash_equals($sessionToken, $token)) {
            throw new \RuntimeException('Security check failed (invalid CSRF token). Please refresh and try again.');
        }
    }
}

