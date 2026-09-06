<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Services;

use DateTimeImmutable;
use DateTimeInterface;
use FavoriteCMS\Digital\Domain\MembershipStatus;
use FavoriteCMS\Digital\Domain\ProductType;
use FavoriteCMS\Digital\Repositories\ProductRepository;
use FavoriteCMS\Digital\Support\MembershipPeriodCalculator;
use FavoriteCMS\Digital\Support\ProductPricingCalculator;
use InvalidArgumentException;
use LogicException;

/**
 * Membership Lifecycle Service
 *
 * Implements locked Phase 4 membership business rules:
 * - Weekly (7 days) & Monthly (1 calendar month with deterministic month-end clamping)
 * - Zero loss of paid time on renewal or plan change
 * - Auto-renewal off by default, non-destructive when disabled
 * - Grace period handling on renewal failure (1 day weekly, 3 days monthly default)
 * - Deterministic access and entitlement checking
 */
class MembershipLifecycleService
{
    protected ProductRepository $repo;

    public function __construct(ProductRepository $repo)
    {
        $this->repo = $repo;
    }

    public function getRepo(): ProductRepository
    {
        return $this->repo;
    }

    // -------------------------------------------------------------------------
    // Plan Management
    // -------------------------------------------------------------------------

    /**
     * Create a new membership product and associated plan tier.
     *
     * @param array $productData Core product fields (title, slug, original_price, discount_percent, etc.)
     * @param array $planData    Membership plan fields (plan_type, grace_period_days, allows_auto_renewal)
     * @return int Product ID of the created membership
     */
    public function createPlan(array $productData, array $planData): int
    {
        $validatedPlan = $this->validatePlanData($planData);

        // Force product_type to membership
        $productData['product_type'] = ProductType::MEMBERSHIP;

        // Auto-generate slug if not provided
        $title = trim((string)($productData['title'] ?? 'Membership Plan'));
        $rawSlug = trim((string)($productData['slug'] ?? ''));
        if ($rawSlug === '') {
            $slug = strtolower(trim((string)preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-'));
            if ($slug === '') {
                $slug = 'membership-plan-' . uniqid();
            }
            $baseSlug = $slug;
            $counter = 1;
            while ($this->repo->findProductBySlug($slug)) {
                $slug = $baseSlug . '-' . $counter++;
            }
            $productData['slug'] = $slug;
        }

        // Normalize pricing
        $origPrice = (float)($productData['original_price'] ?? 0.0);
        $discountPercent = (float)($productData['discount_percent'] ?? 0.0);
        $isFree = !empty($productData['is_free']);

        $finalPrice = ProductPricingCalculator::deriveFinalPrice($origPrice, $discountPercent, $isFree);
        $productData['original_price'] = number_format($origPrice, 2, '.', '');
        $productData['discount_percent'] = number_format($discountPercent, 2, '.', '');
        $productData['final_price'] = $finalPrice;
        $productData['is_free'] = $isFree ? 1 : 0;

        $productId = $this->repo->createProduct($productData);

        $planRecord = [
            'product_id'          => $productId,
            'plan_type'           => $validatedPlan['plan_type'],
            'duration_count'      => $validatedPlan['duration_count'],
            'duration_unit'       => $validatedPlan['duration_unit'],
            'grace_period_days'   => $validatedPlan['grace_period_days'],
            'allows_auto_renewal' => $validatedPlan['allows_auto_renewal'],
        ];

        $this->repo->createMembershipPlan($planRecord);

        return $productId;
    }

    /**
     * Update an existing membership product and associated plan tier.
     */
    public function updatePlan(int $productId, array $productData, array $planData): bool
    {
        $validatedPlan = $this->validatePlanData($planData);

        // Force product_type to membership
        $productData['product_type'] = ProductType::MEMBERSHIP;

        // Auto-generate slug if not provided
        $title = trim((string)($productData['title'] ?? 'Membership Plan'));
        $rawSlug = trim((string)($productData['slug'] ?? ''));
        if ($rawSlug === '') {
            $slug = strtolower(trim((string)preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-'));
            if ($slug === '') {
                $slug = 'membership-plan-' . uniqid();
            }
            $baseSlug = $slug;
            $counter = 1;
            while ($this->repo->findProductBySlug($slug, $productId)) {
                $slug = $baseSlug . '-' . $counter++;
            }
            $productData['slug'] = $slug;
        }

        // Normalize pricing
        $origPrice = (float)($productData['original_price'] ?? 0.0);
        $discountPercent = (float)($productData['discount_percent'] ?? 0.0);
        $isFree = !empty($productData['is_free']);

        $finalPrice = ProductPricingCalculator::deriveFinalPrice($origPrice, $discountPercent, $isFree);
        $productData['original_price'] = number_format($origPrice, 2, '.', '');
        $productData['discount_percent'] = number_format($discountPercent, 2, '.', '');
        $productData['final_price'] = $finalPrice;
        $productData['is_free'] = $isFree ? 1 : 0;

        $this->repo->updateProduct($productId, $productData);

        $existingPlan = $this->repo->findMembershipPlanByProductId($productId);
        $planRecord = [
            'plan_type'           => $validatedPlan['plan_type'],
            'duration_count'      => $validatedPlan['duration_count'],
            'duration_unit'       => $validatedPlan['duration_unit'],
            'grace_period_days'   => $validatedPlan['grace_period_days'],
            'allows_auto_renewal' => $validatedPlan['allows_auto_renewal'],
        ];

        if ($existingPlan) {
            $this->repo->updateMembershipPlan((int)$existingPlan->id, $planRecord);
        } else {
            $planRecord['product_id'] = $productId;
            $this->repo->createMembershipPlan($planRecord);
        }

        return true;
    }

    /**
     * Validate and normalize membership plan input data.
     *
     * @throws InvalidArgumentException
     */
    public function validatePlanData(array $data): array
    {
        $planType = strtolower(trim((string)($data['plan_type'] ?? '')));
        if (!in_array($planType, ['weekly', 'monthly'], true)) {
            throw new InvalidArgumentException("Invalid plan type '{$planType}'. Supported plan types: 'weekly', 'monthly'.");
        }

        if ($planType === 'weekly') {
            $durationCount = max(1, (int)($data['duration_count'] ?? 1));
            $durationUnit = 'week';
            $defaultGrace = 1;
        } else {
            $durationCount = max(1, (int)($data['duration_count'] ?? 1));
            $durationUnit = 'month';
            $defaultGrace = 3;
        }

        $graceDays = isset($data['grace_period_days']) && $data['grace_period_days'] !== ''
            ? (int)$data['grace_period_days']
            : $defaultGrace;

        if ($graceDays < 0) {
            throw new InvalidArgumentException('Grace period days cannot be negative.');
        }

        $allowsAutoRenewal = !empty($data['allows_auto_renewal']) ? 1 : 0;

        return [
            'plan_type'           => $planType,
            'duration_count'      => $durationCount,
            'duration_unit'       => $durationUnit,
            'grace_period_days'   => $graceDays,
            'allows_auto_renewal' => $allowsAutoRenewal,
        ];
    }

    public function getPlan(int $planId): ?object
    {
        return $this->repo->findMembershipPlan($planId);
    }

    public function getPlanByProductId(int $productId): ?object
    {
        return $this->repo->findMembershipPlanByProductId($productId);
    }

    public function listPlans(): array
    {
        return $this->repo->listMembershipPlans();
    }

    // -------------------------------------------------------------------------
    // Customer Membership Lifecycle Operations
    // -------------------------------------------------------------------------

    /**
     * Activate a membership for a user under a specific plan.
     *
     * ZERO LOSS OF PAID TIME:
     * If user already has an active or grace period membership, the new period is
     * appended onto the existing expires_at timestamp so no paid time is lost.
     * If the user has no active membership or it has expired, duration starts from now.
     */
    public function activateMembership(int $userId, int $planId, bool $autoRenew = false, ?DateTimeInterface $now = null): object
    {
        $plan = $this->getPlan($planId);
        if (!$plan) {
            throw new InvalidArgumentException("Membership plan not found: {$planId}");
        }

        $nowDt = $now ? DateTimeImmutable::createFromInterface($now) : new DateTimeImmutable('now');
        $nowStr = $nowDt->format('Y-m-d H:i:s');

        // Auto-renewal is only allowed if plan supports it
        $canAutoRenew = (bool)($plan->allows_auto_renewal ?? 0);
        $effectiveAutoRenew = ($autoRenew && $canAutoRenew) ? 1 : 0;

        // Check for existing active/grace/cancelled membership with remaining time
        $existing = $this->repo->findActiveMembershipForUser($userId, $nowStr);

        if ($existing) {
            // Member has active remaining time: append new duration onto current expires_at
            $currentExpiryDt = new DateTimeImmutable($existing->expires_at);
            $newExpiryDt = MembershipPeriodCalculator::calculateExtensionExpiry(
                $currentExpiryDt,
                $nowDt,
                (string)$plan->duration_unit,
                (int)$plan->duration_count
            );

            $updateData = [
                'plan_id'          => $plan->id,
                'status'           => MembershipStatus::ACTIVE,
                'expires_at'       => $newExpiryDt->format('Y-m-d H:i:s'),
                'grace_expires_at' => null,
                'auto_renew'       => $effectiveAutoRenew,
            ];

            $this->repo->updateMembership((int)$existing->id, $updateData);
            return (object)$this->repo->findMembership((int)$existing->id);
        }

        // Fresh activation starting from now
        $expiryDt = MembershipPeriodCalculator::calculatePeriodExpiry(
            $nowDt,
            (string)$plan->duration_unit,
            (int)$plan->duration_count
        );

        $membershipId = $this->repo->createMembership([
            'user_id'          => $userId,
            'plan_id'          => $plan->id,
            'status'           => MembershipStatus::ACTIVE,
            'started_at'       => $nowStr,
            'expires_at'       => $expiryDt->format('Y-m-d H:i:s'),
            'grace_expires_at' => null,
            'auto_renew'       => $effectiveAutoRenew,
        ]);

        return (object)$this->repo->findMembership($membershipId);
    }

    /**
     * Manually or programmatically extend a membership.
     * Preserves any existing remaining active time.
     */
    public function extendMembership(int $membershipId, ?int $newPlanId = null, ?DateTimeInterface $now = null): object
    {
        $membership = $this->repo->findMembership($membershipId);
        if (!$membership) {
            throw new InvalidArgumentException("Membership not found: {$membershipId}");
        }

        $targetPlanId = $newPlanId ?? (int)$membership->plan_id;
        $plan = $this->getPlan($targetPlanId);
        if (!$plan) {
            throw new InvalidArgumentException("Membership plan not found: {$targetPlanId}");
        }

        $nowDt = $now ? DateTimeImmutable::createFromInterface($now) : new DateTimeImmutable('now');
        $currentExpiryDt = new DateTimeImmutable($membership->expires_at);

        $newExpiryDt = MembershipPeriodCalculator::calculateExtensionExpiry(
            $currentExpiryDt,
            $nowDt,
            (string)$plan->duration_unit,
            (int)$plan->duration_count
        );

        $updateData = [
            'plan_id'          => $plan->id,
            'status'           => MembershipStatus::ACTIVE,
            'expires_at'       => $newExpiryDt->format('Y-m-d H:i:s'),
            'grace_expires_at' => null,
        ];

        $this->repo->updateMembership($membershipId, $updateData);
        return (object)$this->repo->findMembership($membershipId);
    }

    /**
     * Opt into auto-renewal.
     */
    public function enableAutoRenewal(int $membershipId): bool
    {
        $membership = $this->repo->findMembership($membershipId);
        if (!$membership) {
            throw new InvalidArgumentException("Membership not found: {$membershipId}");
        }

        $plan = $this->getPlan((int)$membership->plan_id);
        if (!$plan || empty($plan->allows_auto_renewal)) {
            throw new LogicException('This membership plan does not support automatic renewal.');
        }

        return $this->repo->updateMembership($membershipId, ['auto_renew' => 1]);
    }

    /**
     * Opt out of auto-renewal.
     *
     * Business rule: Disabling auto-renewal does NOT immediately terminate or shorten
     * current paid time. The membership remains active through expires_at.
     */
    public function disableAutoRenewal(int $membershipId): bool
    {
        $membership = $this->repo->findMembership($membershipId);
        if (!$membership) {
            throw new InvalidArgumentException("Membership not found: {$membershipId}");
        }

        return $this->repo->updateMembership($membershipId, ['auto_renew' => 0]);
    }

    /**
     * Handle a successful recurring renewal charge.
     *
     * Extends expires_at by 1 plan duration from the current expires_at, ensures status is active,
     * and clears any grace period.
     */
    public function processRenewalSuccess(int $membershipId, ?DateTimeInterface $now = null): object
    {
        $membership = $this->repo->findMembership($membershipId);
        if (!$membership) {
            throw new InvalidArgumentException("Membership not found: {$membershipId}");
        }

        $plan = $this->getPlan((int)$membership->plan_id);
        if (!$plan) {
            throw new InvalidArgumentException("Plan not found for membership: {$membershipId}");
        }

        $nowDt = $now ? DateTimeImmutable::createFromInterface($now) : new DateTimeImmutable('now');
        $currentExpiryDt = new DateTimeImmutable($membership->expires_at);

        $newExpiryDt = MembershipPeriodCalculator::calculateExtensionExpiry(
            $currentExpiryDt,
            $nowDt,
            (string)$plan->duration_unit,
            (int)$plan->duration_count
        );

        $this->repo->updateMembership($membershipId, [
            'status'           => MembershipStatus::ACTIVE,
            'expires_at'       => $newExpiryDt->format('Y-m-d H:i:s'),
            'grace_expires_at' => null,
        ]);

        return (object)$this->repo->findMembership($membershipId);
    }

    /**
     * Handle a failed recurring renewal charge.
     *
     * Transitions membership into 'grace' state.
     * grace_expires_at = expires_at + grace_period_days.
     * If grace_period_days is 0, transitions immediately to 'expired'.
     */
    public function processRenewalFailure(int $membershipId, ?DateTimeInterface $now = null): object
    {
        $membership = $this->repo->findMembership($membershipId);
        if (!$membership) {
            throw new InvalidArgumentException("Membership not found: {$membershipId}");
        }

        $plan = $this->getPlan((int)$membership->plan_id);
        $graceDays = (int)($plan->grace_period_days ?? 1);

        if ($graceDays <= 0) {
            $this->repo->updateMembership($membershipId, [
                'status'           => MembershipStatus::EXPIRED,
                'grace_expires_at' => null,
            ]);
            return (object)$this->repo->findMembership($membershipId);
        }

        $expiryDt = new DateTimeImmutable($membership->expires_at);
        $graceExpiryDt = $expiryDt->modify("+{$graceDays} days");

        $this->repo->updateMembership($membershipId, [
            'status'           => MembershipStatus::GRACE,
            'grace_expires_at' => $graceExpiryDt->format('Y-m-d H:i:s'),
        ]);

        return (object)$this->repo->findMembership($membershipId);
    }

    /**
     * Recover from grace period upon subsequent successful payment.
     *
     * Clears grace_expires_at and restores active status.
     * If current expires_at is already in the past, extends from now.
     */
    public function recoverFromGrace(int $membershipId, ?DateTimeInterface $now = null): object
    {
        $membership = $this->repo->findMembership($membershipId);
        if (!$membership) {
            throw new InvalidArgumentException("Membership not found: {$membershipId}");
        }

        $plan = $this->getPlan((int)$membership->plan_id);
        $nowDt = $now ? DateTimeImmutable::createFromInterface($now) : new DateTimeImmutable('now');
        $expiryDt = new DateTimeImmutable($membership->expires_at);

        $updateData = [
            'status'           => MembershipStatus::ACTIVE,
            'grace_expires_at' => null,
        ];

        // If expires_at is in the past, renew from now
        if ($expiryDt <= $nowDt && $plan) {
            $newExpiryDt = MembershipPeriodCalculator::calculateExtensionExpiry(
                $expiryDt,
                $nowDt,
                (string)$plan->duration_unit,
                (int)$plan->duration_count
            );
            $updateData['expires_at'] = $newExpiryDt->format('Y-m-d H:i:s');
        }

        $this->repo->updateMembership($membershipId, $updateData);
        return (object)$this->repo->findMembership($membershipId);
    }

    /**
     * Immediately expire a membership (manual admin revocation or automated expiration).
     */
    public function expireMembership(int $membershipId): bool
    {
        $membership = $this->repo->findMembership($membershipId);
        if (!$membership) {
            throw new InvalidArgumentException("Membership not found: {$membershipId}");
        }

        return $this->repo->updateMembership($membershipId, [
            'status'     => MembershipStatus::EXPIRED,
            'auto_renew' => 0,
        ]);
    }

    /**
     * Cancel a membership.
     *
     * Disables auto_renew and marks status as cancelled.
     * Paid access remains active until expires_at!
     */
    public function cancelMembership(int $membershipId): bool
    {
        $membership = $this->repo->findMembership($membershipId);
        if (!$membership) {
            throw new InvalidArgumentException("Membership not found: {$membershipId}");
        }

        return $this->repo->updateMembership($membershipId, [
            'status'     => MembershipStatus::CANCELLED,
            'auto_renew' => 0,
        ]);
    }

    // -------------------------------------------------------------------------
    // Status & Access Checks
    // -------------------------------------------------------------------------

    /**
     * Determine if a user has active membership access (including grace period).
     */
    public function hasActiveMembership(int $userId, ?DateTimeInterface $now = null): bool
    {
        return $this->getActiveMembership($userId, $now) !== null;
    }

    /**
     * Get the current effective active membership record for a user.
     *
     * Returns null if no membership exists, or if expired and grace period ended.
     */
    public function getActiveMembership(int $userId, ?DateTimeInterface $now = null): ?object
    {
        $nowDt = $now ? DateTimeImmutable::createFromInterface($now) : new DateTimeImmutable('now');
        $nowStr = $nowDt->format('Y-m-d H:i:s');

        $membership = $this->repo->findActiveMembershipForUser($userId, $nowStr);
        if (!$membership) {
            return null;
        }

        // Extra guard: If status is grace, verify grace_expires_at has not lapsed
        if ($membership->status === MembershipStatus::GRACE) {
            if (empty($membership->grace_expires_at) || $membership->grace_expires_at <= $nowStr) {
                return null;
            }
        }

        // If status is active or cancelled, verify expires_at has not lapsed
        if (in_array($membership->status, [MembershipStatus::ACTIVE, MembershipStatus::CANCELLED], true)) {
            if ($membership->expires_at <= $nowStr) {
                return null;
            }
        }

        return $membership;
    }

    /**
     * Check whether a user is eligible to access a product under their membership.
     *
     * If the digital product has is_membership_eligible = 1, active members can access it.
     * If is_membership_eligible = 0, membership access does not apply.
     */
    public function isEligibleForMembershipContent(int $userId, int $productId, ?DateTimeInterface $now = null): bool
    {
        $details = $this->repo->findProductDetails($productId);
        if (!$details || empty($details->is_membership_eligible)) {
            return false;
        }

        if ($userId <= 0) {
            return false;
        }

        return $this->hasActiveMembership($userId, $now);
    }

    /**
     * Automated sweep to expire memberships whose paid and grace periods have ended.
     *
     * @return int Count of memberships transitioned to expired
     */
    public function checkAndExpireMemberships(?DateTimeInterface $now = null): int
    {
        $nowDt = $now ? DateTimeImmutable::createFromInterface($now) : new DateTimeImmutable('now');
        $nowStr = $nowDt->format('Y-m-d H:i:s');

        $expiredCount = 0;

        $candidates = $this->repo->findExpiredCandidates($nowStr);

        foreach ($candidates as $c) {
            $this->expireMembership((int)$c->id);
            $expiredCount++;
        }

        return $expiredCount;
    }
}
