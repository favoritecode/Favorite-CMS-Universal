<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Services;

use FavoriteCMS\Services\Mail\SmtpTransport;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MockSmtpStreamWrapper
{
    /** @var resource|null */
    public $context;
    private int $position = 0;
    private string $readBuffer = '';
    public static array $responses = [];
    public static array $commands = [];

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $this->readBuffer = implode('', self::$responses);
        $this->position = 0;
        return true;
    }

    public function stream_read(int $count): string
    {
        if ($this->position >= strlen($this->readBuffer)) {
            return '';
        }
        $nextNewline = strpos($this->readBuffer, "\n", $this->position);
        if ($nextNewline === false) {
            $len = min($count, strlen($this->readBuffer) - $this->position);
        } else {
            $len = min($count, ($nextNewline - $this->position) + 1);
        }
        $chunk = substr($this->readBuffer, $this->position, $len);
        $this->position += strlen($chunk);
        return $chunk;
    }

    public function stream_write(string $data): int
    {
        self::$commands[] = $data;
        return strlen($data);
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->readBuffer);
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }
}

class SmtpTransportTest extends TestCase
{
    protected static bool $wrapperRegistered = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!self::$wrapperRegistered) {
            stream_wrapper_register('mocksmtp', MockSmtpStreamWrapper::class);
            self::$wrapperRegistered = true;
        }
    }

    protected function tearDown(): void
    {
        SmtpTransport::$streamFactory = null;
        MockSmtpStreamWrapper::$responses = [];
        MockSmtpStreamWrapper::$commands = [];
        parent::tearDown();
    }

    public function testConstructorValidatesHost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SMTP host cannot be empty');
        new SmtpTransport('');
    }

    public function testConstructorRejectsCrlfInHost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CRLF injection detected in SMTP host');
        new SmtpTransport("smtp.example.com\r\nBcc: victim@domain.com");
    }

    public function testConstructorValidatesPortRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid SMTP port');
        new SmtpTransport('smtp.example.com', 70000);
    }

    public function testConstructorValidatesZeroPort(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid SMTP port');
        new SmtpTransport('smtp.example.com', 0);
    }

    public function testConstructorValidatesEncryptionEnum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid SMTP encryption');
        new SmtpTransport('smtp.example.com', 587, 'invalid_enc');
    }

    public function testConstructorRejectsCrlfInUsername(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CRLF injection detected in SMTP username');
        new SmtpTransport('smtp.example.com', 587, 'tls', "user\r\nadmin");
    }

    public function testConstructorRejectsCrlfInPassword(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CRLF injection detected in SMTP password');
        new SmtpTransport('smtp.example.com', 587, 'tls', 'user', "pass\r\nword");
    }

    public function testSuccessfulSendWithMockStreamAuthLogin(): void
    {
        MockSmtpStreamWrapper::$responses = [
            "220 smtp.example.com ESMTP\r\n",
            "250-smtp.example.com Hello\r\n250-AUTH LOGIN PLAIN\r\n250 8BITMIME\r\n",
            "334 VXNlcm5hbWU6\r\n", // Username:
            "334 UGFzc3dvcmQ6\r\n", // Password:
            "235 2.7.0 Authentication successful\r\n",
            "250 2.1.0 Ok\r\n",     // MAIL FROM
            "250 2.1.5 Ok\r\n",     // RCPT TO
            "354 End data with <CR><LF>.<CR><LF>\r\n", // DATA
            "250 2.0.0 Ok: queued\r\n", // message accepted
            "221 2.0.0 Bye\r\n",
        ];

        SmtpTransport::$streamFactory = function ($remoteSocket, &$errno, &$errstr, $timeout, $flags, $context) {
            $options = stream_context_get_options($context);
            $this->assertTrue($options['ssl']['verify_peer'] ?? false);
            $this->assertTrue($options['ssl']['verify_peer_name'] ?? false);
            $this->assertFalse($options['ssl']['allow_self_signed'] ?? true);
            return fopen('mocksmtp://smtp.example.com', 'r+');
        };

        $transport = new SmtpTransport(
            host: 'smtp.example.com',
            port: 587,
            encryption: 'none',
            username: 'testuser',
            password: 'secretpassword123',
            timeout: 5
        );

        $sent = $transport->send(
            to: 'recipient@example.com',
            encodedSubject: 'Test Subject',
            message: "Hello world.\n.This line starts with dot\nSecond line.",
            standardHeaders: ['From: "Test" <sender@example.com>'],
            fromEmail: 'sender@example.com'
        );

        $this->assertTrue($sent, 'Expected send() to succeed: ' . ($transport->getLastError() ?? ''));
        $this->assertNull($transport->getLastError());

        $fullLog = implode('', MockSmtpStreamWrapper::$commands);
        $this->assertStringContainsString('EHLO', $fullLog);
        $this->assertStringContainsString('AUTH LOGIN', $fullLog);
        $this->assertStringContainsString(base64_encode('testuser'), $fullLog);
        $this->assertStringContainsString(base64_encode('secretpassword123'), $fullLog);
        $this->assertStringContainsString('MAIL FROM:<sender@example.com>', $fullLog);
        $this->assertStringContainsString('RCPT TO:<recipient@example.com>', $fullLog);
        $this->assertStringContainsString('DATA', $fullLog);
        $this->assertStringContainsString('..This line starts with dot', $fullLog);
        $this->assertStringContainsString("\r\n.\r\n", $fullLog);
        $this->assertStringContainsString('QUIT', $fullLog);
    }

    public function testSendWithAuthPlainWhenAdvertised(): void
    {
        MockSmtpStreamWrapper::$responses = [
            "220 smtp.example.com ESMTP\r\n",
            "250-smtp.example.com Hello\r\n250-AUTH PLAIN\r\n250 8BITMIME\r\n",
            "235 2.7.0 Authentication successful\r\n",
            "250 2.1.0 Ok\r\n",
            "250 2.1.5 Ok\r\n",
            "354 End data\r\n",
            "250 2.0.0 Ok: queued\r\n",
            "221 2.0.0 Bye\r\n",
        ];

        SmtpTransport::$streamFactory = fn () => fopen('mocksmtp://smtp.example.com', 'r+');

        $transport = new SmtpTransport(
            host: 'smtp.example.com',
            port: 587,
            encryption: 'none',
            username: 'plainuser',
            password: 'plainpass123'
        );

        $sent = $transport->send(
            to: 'recipient@example.com',
            encodedSubject: 'Test Subject',
            message: 'Body',
            standardHeaders: ['From: sender@example.com'],
            fromEmail: 'sender@example.com'
        );

        $this->assertTrue($sent, 'Expected send() with AUTH PLAIN to succeed: ' . ($transport->getLastError() ?? ''));
        $fullLog = implode('', MockSmtpStreamWrapper::$commands);
        $expectedPlain = base64_encode("\0plainuser\0plainpass123");
        $this->assertStringContainsString("AUTH PLAIN {$expectedPlain}", $fullLog);
    }

    public function testTestConnectionSuccess(): void
    {
        MockSmtpStreamWrapper::$responses = [
            "220 smtp.example.com ESMTP\r\n",
            "250-smtp.example.com Hello\r\n250-AUTH LOGIN\r\n250 8BITMIME\r\n",
            "334 VXNlcm5hbWU6\r\n",
            "334 UGFzc3dvcmQ6\r\n",
            "235 2.7.0 Authentication successful\r\n",
            "221 2.0.0 Bye\r\n",
        ];

        SmtpTransport::$streamFactory = fn () => fopen('mocksmtp://smtp.example.com', 'r+');

        $transport = new SmtpTransport(
            host: 'smtp.example.com',
            port: 587,
            encryption: 'none',
            username: 'admin@domain.com',
            password: 'secretpassword'
        );

        $result = $transport->testConnection();
        $this->assertTrue($result['success'], 'Expected testConnection to succeed: ' . ($result['message'] ?? ''));
        $this->assertStringContainsString('successful', $result['message']);
        $this->assertTrue($result['details']['authenticated']);
    }

    public function testTestConnectionHandlesAuthenticationFailureSafely(): void
    {
        MockSmtpStreamWrapper::$responses = [
            "220 smtp.example.com ESMTP\r\n",
            "250-smtp.example.com Hello\r\n250-AUTH LOGIN\r\n250 8BITMIME\r\n",
            "334 VXNlcm5hbWU6\r\n",
            "334 UGFzc3dvcmQ6\r\n",
            "535 5.7.8 Authentication credentials invalid for supersecretpass\r\n",
        ];

        SmtpTransport::$streamFactory = fn () => fopen('mocksmtp://smtp.example.com', 'r+');

        $transport = new SmtpTransport(
            host: 'smtp.example.com',
            port: 587,
            encryption: 'none',
            username: 'admin@domain.com',
            password: 'supersecretpass'
        );

        $result = $transport->testConnection();
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('rejected by SMTP server (code 535)', $result['message']);

        // Assert password is NEVER exposed in the error message
        $this->assertStringNotContainsString('supersecretpass', $result['message']);
        $this->assertStringNotContainsString(base64_encode('supersecretpass'), $result['message']);
    }

    public function testSendRejectsInvalidRecipientWithoutContactingServer(): void
    {
        $contacted = false;
        SmtpTransport::$streamFactory = function () use (&$contacted) {
            $contacted = true;
            return fopen('mocksmtp://test', 'r+');
        };

        $transport = new SmtpTransport('smtp.example.com');
        $result = $transport->send(
            to: 'not-an-email',
            encodedSubject: 'Subj',
            message: 'Body',
            standardHeaders: [],
            fromEmail: 'sender@example.com'
        );

        $this->assertFalse($result);
        $this->assertFalse($contacted);
        $this->assertStringContainsString('Invalid recipient', (string)$transport->getLastError());
    }

    public function testSendRejectsInvalidSenderWithoutContactingServer(): void
    {
        $contacted = false;
        SmtpTransport::$streamFactory = function () use (&$contacted) {
            $contacted = true;
            return fopen('mocksmtp://test', 'r+');
        };

        $transport = new SmtpTransport('smtp.example.com');
        $result = $transport->send(
            to: 'recipient@example.com',
            encodedSubject: 'Subj',
            message: 'Body',
            standardHeaders: [],
            fromEmail: "invalid-sender\r\nBcc: evil@domain.com"
        );

        $this->assertFalse($result);
        $this->assertFalse($contacted);
        $this->assertStringContainsString('Invalid sender', (string)$transport->getLastError());
    }
}
