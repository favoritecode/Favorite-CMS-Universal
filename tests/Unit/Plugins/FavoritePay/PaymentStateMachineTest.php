<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use FavoriteCMS\Pay\Domain\PaymentStatus;
use PHPUnit\Framework\TestCase;

class PaymentStateMachineTest extends TestCase
{
    public function testPendingTransitions(): void
    {
        $pending = PaymentStatus::PENDING;
        $this->assertTrue($pending->canTransitionTo(PaymentStatus::AWAITING_VERIFICATION));
        $this->assertTrue($pending->canTransitionTo(PaymentStatus::PROCESSING));
        $this->assertTrue($pending->canTransitionTo(PaymentStatus::CANCELLED));
        $this->assertTrue($pending->canTransitionTo(PaymentStatus::FAILED));
        $this->assertTrue($pending->canTransitionTo(PaymentStatus::SUCCEEDED));
    }

    public function testAwaitingVerificationTransitions(): void
    {
        $status = PaymentStatus::AWAITING_VERIFICATION;
        $this->assertTrue($status->canTransitionTo(PaymentStatus::SUCCEEDED));
        $this->assertTrue($status->canTransitionTo(PaymentStatus::FAILED));
        $this->assertTrue($status->canTransitionTo(PaymentStatus::CANCELLED));
        $this->assertFalse($status->canTransitionTo(PaymentStatus::PENDING));
    }

    public function testSucceededTransitions(): void
    {
        $succeeded = PaymentStatus::SUCCEEDED;
        $this->assertTrue($succeeded->isFinal());
        $this->assertTrue($succeeded->canTransitionTo(PaymentStatus::REFUNDED));
        $this->assertTrue($succeeded->canTransitionTo(PaymentStatus::PARTIALLY_REFUNDED));
        $this->assertFalse($succeeded->canTransitionTo(PaymentStatus::FAILED));
        $this->assertFalse($succeeded->canTransitionTo(PaymentStatus::PENDING));
    }

    public function testTerminalStatesCannotTransition(): void
    {
        $failed = PaymentStatus::FAILED;
        $this->assertTrue($failed->isFinal());
        $this->assertFalse($failed->canTransitionTo(PaymentStatus::SUCCEEDED));
        $this->assertFalse($failed->canTransitionTo(PaymentStatus::PENDING));

        $cancelled = PaymentStatus::CANCELLED;
        $this->assertTrue($cancelled->isFinal());
        $this->assertFalse($cancelled->canTransitionTo(PaymentStatus::SUCCEEDED));

        $refunded = PaymentStatus::REFUNDED;
        $this->assertTrue($refunded->isFinal());
        $this->assertFalse($refunded->canTransitionTo(PaymentStatus::SUCCEEDED));
    }
}
