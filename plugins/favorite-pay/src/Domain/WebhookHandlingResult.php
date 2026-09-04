<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Domain;

final class WebhookHandlingResult
{
    private bool $success;
    private int $statusCode;
    private string $message;
    private ?PaymentAttempt $attempt;
    private bool $alreadyProcessed;

    public function __construct(
        bool $success,
        int $statusCode,
        string $message,
        ?PaymentAttempt $attempt = null,
        bool $alreadyProcessed = false
    ) {
        $this->success = $success;
        $this->statusCode = $statusCode;
        $this->message = $message;
        $this->attempt = $attempt;
        $this->alreadyProcessed = $alreadyProcessed;
    }

    public static function success(PaymentAttempt $attempt, string $message = "Webhook processed successfully."): self
    {
        return new self(true, 200, $message, $attempt, false);
    }

    public static function alreadyProcessed(PaymentAttempt $attempt, string $message = "Payment attempt already succeeded."): self
    {
        return new self(true, 200, $message, $attempt, true);
    }

    public static function rejected(string $message = "Webhook verification failed.", int $statusCode = 401): self
    {
        return new self(false, $statusCode, $message, null, false);
    }

    public static function notFound(string $message = "Payment attempt not found."): self
    {
        return new self(false, 404, $message, null, false);
    }

    public static function unsupported(string $message = "Gateway does not support webhooks."): self
    {
        return new self(false, 400, $message, null, false);
    }

    public static function mismatch(string $message = "Amount or currency mismatch."): self
    {
        return new self(false, 422, $message, null, false);
    }

    public static function failed(PaymentAttempt $attempt, string $message = "Payment marked as failed."): self
    {
        return new self(true, 200, $message, $attempt, false);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getAttempt(): ?PaymentAttempt
    {
        return $this->attempt;
    }

    public function isAlreadyProcessed(): bool
    {
        return $this->alreadyProcessed;
    }
}
