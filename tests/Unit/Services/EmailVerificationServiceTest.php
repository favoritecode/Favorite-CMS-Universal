<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Hook;
use FavoriteCMS\Models\User;
use FavoriteCMS\Models\Role;
use FavoriteCMS\Services\EmailVerificationService;

class EmailVerificationServiceTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;
    protected EmailVerificationService $service;
    private static array $createdUserIds = [];

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db = static::$app->make(Database::class);

        static::$db->execute("INSERT IGNORE INTO `roles` (`name`, `slug`, `description`, `is_system`) VALUES ('Subscriber', 'subscriber', 'Regular registered user', 1)");
    }

    public static function tearDownAfterClass(): void
    {
        if (!empty(static::$createdUserIds)) {
            $inClause = implode(',', array_map('intval', static::$createdUserIds));
            static::$db->execute("DELETE FROM `email_verifications` WHERE `user_id` IN ({$inClause})");
            static::$db->execute("DELETE FROM `user_roles` WHERE `user_id` IN ({$inClause})");
            static::$db->execute("DELETE FROM `users` WHERE `id` IN ({$inClause})");
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EmailVerificationService(static::$db);
    }

    private function createTestUser(string $prefix, string $email, bool $verified = false): User
    {
        $unique = bin2hex(random_bytes(4));
        $username = "{$prefix}_{$unique}";
        $now = date('Y-m-d H:i:s');

        $userId = static::$db->insert('users', [
            'username'          => $username,
            'name'              => ucfirst($username),
            'email'             => $email,
            'password'          => password_hash('SecretPassword123!', PASSWORD_DEFAULT),
            'status'            => 'active',
            'email_verified_at' => $verified ? $now : null,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        static::$createdUserIds[] = (int)$userId;

        $subRole = Role::findBySlug('subscriber');
        if ($subRole) {
            static::$db->insert('user_roles', [
                'user_id' => (int)$userId,
                'role_id' => (int)$subRole->id,
            ]);
        }

        return User::find((int)$userId);
    }

    public function testCreateVerificationTokenGeneratesSecureTokenAndCleansPrevious(): void
    {
        $email = 'token_test_' . bin2hex(random_bytes(4)) . '@example.com';
        $user = $this->createTestUser('tok_u', $email, false);

        // 1. Create first token
        $token1 = $this->service->createVerificationToken($user, $email);
        $this->assertSame(64, strlen($token1));
        $this->assertTrue(ctype_xdigit($token1));

        $hash1 = hash('sha256', $token1);
        $row1 = static::$db->selectOne("SELECT * FROM `email_verifications` WHERE `user_id` = ?", [$user->id]);
        $this->assertNotNull($row1);
        $this->assertSame($hash1, $row1->token_hash);
        $this->assertSame(strtolower($email), $row1->email);

        // 2. Create second token for same user - old token must be replaced
        $token2 = $this->service->createVerificationToken($user, $email);
        $this->assertNotSame($token1, $token2);

        $records = static::$db->select("SELECT * FROM `email_verifications` WHERE `user_id` = ?", [$user->id]);
        $this->assertCount(1, $records, 'Old tokens for the user must be removed when issuing a new one');
        $this->assertSame(hash('sha256', $token2), $records[0]->token_hash);
    }

    public function testCooldownAndRateLimiting(): void
    {
        $email = 'cooldown_' . bin2hex(random_bytes(4)) . '@example.com';
        $user = $this->createTestUser('cool_u', $email, false);

        // Before any token, canResend is true
        $this->assertTrue($this->service->canResend($email));
        $this->assertSame(0, $this->service->getSecondsUntilResend($email));

        // After creating token, canResend is false (within 60s cooldown)
        $this->service->createVerificationToken($user, $email);
        $this->assertFalse($this->service->canResend($email));
        $this->assertGreaterThan(0, $this->service->getSecondsUntilResend($email));
        $this->assertLessThanOrEqual(60, $this->service->getSecondsUntilResend($email));

        // Simulate 61 seconds passing
        $pastDate = date('Y-m-d H:i:s', time() - 65);
        static::$db->execute(
            "UPDATE `email_verifications` SET `created_at` = ? WHERE `user_id` = ?",
            [$pastDate, $user->id]
        );

        $this->assertTrue($this->service->canResend($email));
        $this->assertSame(0, $this->service->getSecondsUntilResend($email));
    }

    public function testVerifyTokenRejectsInvalidOrMalformedTokens(): void
    {
        $this->assertFalse($this->service->verifyToken('')['success']);
        $this->assertFalse($this->service->verifyToken('short')['success']);
        $this->assertFalse($this->service->verifyToken('not-a-hex-string-of-appropriate-length-xxxxxxxxxxxxxxxxxxxxxxxx')['success']);
        $this->assertFalse($this->service->verifyToken(str_repeat('f', 64))['success']);
    }

    public function testVerifyTokenRejectsExpiredToken(): void
    {
        $email = 'expired_' . bin2hex(random_bytes(4)) . '@example.com';
        $user = $this->createTestUser('exp_u', $email, false);

        $token = $this->service->createVerificationToken($user, $email);

        // Manually expire token
        $pastExpires = date('Y-m-d H:i:s', time() - 3600);
        static::$db->execute(
            "UPDATE `email_verifications` SET `expires_at` = ? WHERE `user_id` = ?",
            [$pastExpires, $user->id]
        );

        $res = $this->service->verifyToken($token);
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('expired', strtolower($res['error'] ?? ''));

        // Expired record should be deleted
        $remaining = static::$db->selectOne("SELECT * FROM `email_verifications` WHERE `user_id` = ?", [$user->id]);
        $this->assertNull($remaining);
    }

    public function testVerifyTokenMarksRegistrationEmailVerifiedAndIsSingleUse(): void
    {
        $email = 'reg_verif_' . bin2hex(random_bytes(4)) . '@example.com';
        $user = $this->createTestUser('reg_v', $email, false);
        $this->assertFalse($user->isEmailVerified());

        $token = $this->service->createVerificationToken($user, $email);

        // First verification succeeds
        $res = $this->service->verifyToken($token);
        $this->assertTrue($res['success']);
        $this->assertFalse($res['isEmailChange']);
        $this->assertNull($res['error']);

        // Check user model
        $refreshed = User::find($user->id);
        $this->assertTrue($refreshed->isEmailVerified());
        $this->assertNotEmpty($refreshed->email_verified_at);

        // Second verification attempt fails (single-use)
        $res2 = $this->service->verifyToken($token);
        $this->assertFalse($res2['success']);
        $this->assertStringContainsString('already-used', strtolower($res2['error'] ?? ''));
    }

    public function testVerifyTokenAppliesEmailChange(): void
    {
        $oldEmail = 'primary_' . bin2hex(random_bytes(4)) . '@example.com';
        $newEmail = 'newaddr_' . bin2hex(random_bytes(4)) . '@example.com';
        $user = $this->createTestUser('change_u', $oldEmail, true);

        // User requests email change to $newEmail
        $token = $this->service->createVerificationToken($user, $newEmail);

        // Before verification, getPendingEmailChange returns new email
        $pending = $this->service->getPendingEmailChange((int)$user->id);
        $this->assertSame(strtolower($newEmail), $pending);

        // Verify with token
        $res = $this->service->verifyToken($token);
        $this->assertTrue($res['success']);
        $this->assertTrue($res['isEmailChange']);
        $this->assertSame(strtolower($newEmail), $res['newEmail']);

        // Refreshed user has updated email and is verified
        $refreshed = User::find($user->id);
        $this->assertSame(strtolower($newEmail), strtolower((string)$refreshed->email));
        $this->assertTrue($refreshed->isEmailVerified());

        // Pending change is cleared
        $this->assertNull($this->service->getPendingEmailChange((int)$user->id));
    }

    public function testSendVerificationEmailFilterInterception(): void
    {
        $email = 'hook_mail_' . bin2hex(random_bytes(4)) . '@example.com';
        $user = $this->createTestUser('hook_u', $email, false);

        $interceptedData = null;
        Hook::addFilter('pre_send_verification_email', function ($null, $args) use (&$interceptedData) {
            $interceptedData = $args;
            return true; // Pretend email was sent
        }, 10, 2);

        $sent = $this->service->sendVerificationEmail($user, $email, 'dummy_token_12345', false);
        $this->assertTrue($sent);
        $this->assertNotNull($interceptedData);
        $this->assertSame($email, $interceptedData['to']);
        $this->assertStringContainsString('verify-email?token=dummy_token_12345', $interceptedData['verificationUrl']);
    }
}
