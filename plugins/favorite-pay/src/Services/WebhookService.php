<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Services;

use FavoriteCMS\Pay\Contracts\PaymentServiceInterface;
use FavoriteCMS\Pay\Contracts\WebhookGatewayInterface;
use FavoriteCMS\Pay\Contracts\WebhookServiceInterface;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\Domain\WebhookHandlingResult;
use FavoriteCMS\Pay\Support\SafeLogger;

class WebhookService implements WebhookServiceInterface
{
    private GatewayRegistry $gatewayRegistry;
    private PaymentServiceInterface $paymentService;

    public function __construct(
        GatewayRegistry $gatewayRegistry,
        PaymentServiceInterface $paymentService
    ) {
        $this->gatewayRegistry = $gatewayRegistry;
        $this->paymentService = $paymentService;
    }

    public function handle(string $gatewayId, array $headers, string|array $payload): WebhookHandlingResult
    {
        // 1. Gateway resolution
        if (!$this->gatewayRegistry->has($gatewayId)) {
            SafeLogger::warning("Webhook received for unknown gateway '{$gatewayId}'.", [
                'gateway_id' => $gatewayId,
            ]);
            return WebhookHandlingResult::notFound("Payment gateway '{$gatewayId}' not found.");
        }

        $gateway = $this->gatewayRegistry->get($gatewayId);

        // 2. Capability check
        if (!($gateway instanceof WebhookGatewayInterface)) {
            SafeLogger::warning("Webhook received for non-webhook gateway '{$gatewayId}'.", [
                'gateway_id' => $gatewayId,
            ]);
            return WebhookHandlingResult::unsupported("Gateway '{$gatewayId}' does not support webhook processing.");
        }

        // 3. Driver verification (Signature & Authentication)
        $verified = $gateway->verifyWebhook($headers, $payload);
        if (!$verified->isVerified()) {
            SafeLogger::warning("Webhook verification rejected by gateway '{$gatewayId}'.", [
                'gateway_id' => $gatewayId,
                'error'      => $verified->getErrorMessage(),
            ]);
            return WebhookHandlingResult::rejected(
                $verified->getErrorMessage() ?? "Webhook signature verification failed."
            );
        }

        // 4. Resolve payment attempt
        $txOrAttemptId = $verified->getTransactionId();
        $providerRef = $verified->getProviderReference();

        $attempt = null;
        if (method_exists($this->paymentService, 'findAttemptForWebhook')) {
            $attempt = $this->paymentService->findAttemptForWebhook($gateway->getId(), $txOrAttemptId, $providerRef);
        } elseif ($txOrAttemptId !== null && method_exists($this->paymentService, 'getAttempt')) {
            $attempt = $this->paymentService->getAttempt($txOrAttemptId);
        }

        if (!$attempt) {
            SafeLogger::warning("Payment attempt not found for webhook reference.", [
                'gateway_id'    => $gatewayId,
                'ref_id'        => $txOrAttemptId,
                'provider_ref'  => $providerRef,
            ]);
            return WebhookHandlingResult::notFound("Associated payment attempt not found.");
        }

        // 5. Strict Amount & Currency verification (Money minor units)
        $expectedAmount = $attempt->getAmount();
        $verifiedAmount = $verified->getAmount();

        if ($verifiedAmount !== null) {
            if (!$verifiedAmount->equals($expectedAmount)) {
                SafeLogger::error("Webhook amount or currency mismatch detected.", [
                    'gateway_id'      => $gatewayId,
                    'attempt_id'      => $attempt->getId(),
                    'expected_amount' => $expectedAmount->getAmount(),
                    'expected_curr'   => $expectedAmount->getCurrency(),
                    'verified_amount' => $verifiedAmount->getAmount(),
                    'verified_curr'   => $verifiedAmount->getCurrency(),
                ]);
                return WebhookHandlingResult::mismatch(
                    "Amount/currency mismatch: Verified amount or currency ({$verifiedAmount->getCurrency()} {$verifiedAmount->getAmount()}) does not match expected attempt ({$expectedAmount->getCurrency()} {$expectedAmount->getAmount()})."
                );
            }
        }

        // 6. Idempotency check: already succeeded?
        if ($attempt->getStatus() === PaymentStatus::SUCCEEDED) {
            return WebhookHandlingResult::alreadyProcessed($attempt, "Payment attempt has already succeeded.");
        }

        // 7. Update status via PaymentService
        if ($verified->getStatus() === PaymentStatus::SUCCEEDED) {
            if (method_exists($this->paymentService, 'markAttemptSuccessfulViaWebhook')) {
                $updated = $this->paymentService->markAttemptSuccessfulViaWebhook(
                    $attempt->getId(),
                    $providerRef,
                    $verified->getRawData()
                );
            } else {
                $updated = $attempt->markApproved(0, "Verified via webhook");
            }

            SafeLogger::info("Payment attempt successfully verified via webhook.", [
                'gateway_id'   => $gatewayId,
                'attempt_id'   => $attempt->getId(),
                'provider_ref' => $providerRef,
            ]);

            return WebhookHandlingResult::success($updated, "Payment successfully verified and settled.");
        }

        if ($verified->getStatus() === PaymentStatus::FAILED) {
            if (method_exists($this->paymentService, 'markAttemptFailedViaWebhook')) {
                $updated = $this->paymentService->markAttemptFailedViaWebhook(
                    $attempt->getId(),
                    $verified->getErrorMessage() ?? "Payment failed at provider.",
                    $providerRef,
                    $verified->getRawData()
                );
            } else {
                $updated = $attempt->markRejected(0, $verified->getErrorMessage() ?? "Failed via webhook");
            }

            return WebhookHandlingResult::failed($updated, "Payment marked as failed.");
        }

        return WebhookHandlingResult::success($attempt, "Webhook processed.");
    }
}
