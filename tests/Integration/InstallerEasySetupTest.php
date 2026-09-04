<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Installer\DatabaseProvisioner;
use FavoriteCMS\Installer\UrlResolver;
use PDOException;
use PHPUnit\Framework\TestCase;

class InstallerEasySetupTest extends TestCase
{
    protected DatabaseProvisioner $provisioner;
    protected UrlResolver $urls;

    protected function setUp(): void
    {
        $this->provisioner = new DatabaseProvisioner();
        $this->urls = new UrlResolver();
    }

    protected function tearDown(): void
    {
        putenv('DB_HOST');
        putenv('DB_PORT');
        putenv('DB_DATABASE');
        putenv('DB_USERNAME');
        putenv('DB_PASSWORD');
        putenv('DATABASE_URL');
        unset($_ENV['DB_HOST'], $_ENV['DB_PORT'], $_ENV['DB_DATABASE'], $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], $_ENV['DATABASE_URL']);
    }

    public function testAutomaticPrefixGeneration(): void
    {
        $prefix1 = $this->provisioner->generateTablePrefix();
        $prefix2 = $this->provisioner->generateTablePrefix();

        $this->assertStringStartsWith('fvcms_', $prefix1);
        $this->assertStringEndsWith('_', $prefix1);
        $this->assertMatchesRegularExpression('/^fvcms_[0-9a-f]{4}_$/', $prefix1);
        $this->assertNotEquals($prefix1, $prefix2, 'Generated table prefixes should be unique.');
    }

    public function testEnvironmentCredentialsDetection(): void
    {
        // 1. Test standard DB_* environment variables
        putenv('DB_HOST=mysql.internal.net');
        putenv('DB_PORT=3307');
        putenv('DB_DATABASE=custom_db_name');
        putenv('DB_USERNAME=custom_user');
        putenv('DB_PASSWORD=secret_pass');

        $detected = $this->provisioner->detectEnvironmentCredentials();

        $this->assertSame('mysql.internal.net', $detected['host']);
        $this->assertSame('3307', $detected['port']);
        $this->assertSame('custom_db_name', $detected['database']);
        $this->assertSame('custom_user', $detected['username']);
        $this->assertSame('secret_pass', $detected['password']);

        // 2. Test 12-factor DATABASE_URL detection
        putenv('DATABASE_URL=mysql://admin_url:url_pass@db.example.com:3308/url_dbname');
        $detectedUrl = $this->provisioner->detectEnvironmentCredentials();

        $this->assertSame('db.example.com', $detectedUrl['host']);
        $this->assertSame('3308', $detectedUrl['port']);
        $this->assertSame('admin_url', $detectedUrl['username']);
        $this->assertSame('url_pass', $detectedUrl['password']);
        $this->assertSame('url_dbname', $detectedUrl['database']);
    }

    public function testNormalizationDefaultsForSharedHosting(): void
    {
        $input = [
            'db_name'     => 'u999_fvcms',
            'db_username' => 'u999_admin',
            'db_password' => 'MySecurePass!123',
            // db_host and db_port omitted
        ];

        $normalized = $this->provisioner->normalize($input);

        $this->assertSame('localhost', $normalized['host'], 'Default host on shared hosting must be localhost.');
        $this->assertSame('3306', $normalized['port'], 'Default port on shared hosting must be 3306.');
        $this->assertSame('u999_fvcms', $normalized['database']);
        $this->assertSame('u999_admin', $normalized['username']);
        $this->assertStringStartsWith('fvcms_', $normalized['prefix'], 'Table prefix must be auto-generated when omitted.');
    }

    public function testNormalizationPreservesManualAdvancedInput(): void
    {
        $input = [
            'db_host'     => '192.168.1.100',
            'db_port'     => '3309',
            'db_name'     => 'custom_cms',
            'db_username' => 'db_user',
            'db_password' => 'secret',
            'db_prefix'   => 'mycorp_',
        ];

        $normalized = $this->provisioner->normalize($input);

        $this->assertSame('192.168.1.100', $normalized['host']);
        $this->assertSame('3309', $normalized['port']);
        $this->assertSame('custom_cms', $normalized['database']);
        $this->assertSame('mycorp_', $normalized['prefix']);
    }

    public function testCategorizedDatabaseErrorMessages(): void
    {
        // 1. Access Denied (1045)
        $e1045 = new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'baduser'@'localhost' (using password: YES)");
        $msg1045 = $this->provisioner->formatDatabaseError($e1045, ['database' => 'demo_db']);
        $this->assertStringContainsString('Incorrect database username or password', $msg1045);
        $this->assertStringNotContainsString('using password', $msg1045);

        // 2. Unknown database (1049)
        $e1049 = new PDOException("SQLSTATE[HY000] [1049] Unknown database 'non_existent_db'");
        $msg1049 = $this->provisioner->formatDatabaseError($e1049, ['database' => 'non_existent_db', 'host' => 'localhost']);
        $this->assertStringContainsString('The database "non_existent_db" does not exist', $msg1049);
        $this->assertStringContainsString('Please create the database first in your hosting control panel', $msg1049);

        // 3. Host unreachable (2002)
        $e2002 = new PDOException("SQLSTATE[HY000] [2002] Connection refused");
        $msg2002 = $this->provisioner->formatDatabaseError($e2002, ['host' => 'remote.db.invalid']);
        $this->assertStringContainsString('Could not reach database host "remote.db.invalid"', $msg2002);
        $this->assertStringContainsString('the host should almost always be "localhost"', $msg2002);

        // 4. Server timeout (2006)
        $e2006 = new PDOException("SQLSTATE[HY000] [2006] MySQL server has gone away");
        $msg2006 = $this->provisioner->formatDatabaseError($e2006);
        $this->assertStringContainsString('timed out', $msg2006);

        // 5. Insufficient privileges (1142)
        $e1142 = new PDOException("SQLSTATE[42000] [1142] CREATE command denied to user 'guest'@'localhost' for table 'test'");
        $msg1142 = $this->provisioner->formatDatabaseError($e1142, ['database' => 'test_db']);
        $this->assertStringContainsString('user has insufficient privileges', $msg1142);
        $this->assertStringContainsString('ALL PRIVILEGES', $msg1142);
    }

    public function testSubdomainAndSubdirectoryUrlDetection(): void
    {
        // 1. Subdomain URL resolution
        $reqSubdomain = new Request(
            server: [
                'HTTP_HOST'       => 'blog.mysite.com',
                'HTTPS'           => 'on',
                'SERVER_PORT'     => '443',
                'REQUEST_URI'     => '/install',
                'SCRIPT_NAME'     => '/index.php',
                'REQUEST_METHOD'  => 'GET',
            ]
        );

        $urlSubdomain = $this->urls->currentBaseUrl($reqSubdomain);
        $this->assertSame('https://blog.mysite.com/', $urlSubdomain);

        // 2. Subdirectory URL resolution
        $reqSubdir = new Request(
            server: [
                'HTTP_HOST'       => 'example.com',
                'SERVER_PORT'     => '80',
                'REQUEST_URI'     => '/my-subfolder/cms/install',
                'SCRIPT_NAME'     => '/my-subfolder/cms/index.php',
                'REQUEST_METHOD'  => 'GET',
            ]
        );

        $urlSubdir = $this->urls->currentBaseUrl($reqSubdir);
        $this->assertSame('http://example.com/my-subfolder/cms/', $urlSubdir);
    }
}
