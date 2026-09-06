<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteDigital;

use DateTimeImmutable;
use DateTimeZone;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Digital\Controllers\AdminMembershipController;
use FavoriteCMS\Digital\Domain\MembershipStatus;
use FavoriteCMS\Digital\Domain\ProductStatus;
use FavoriteCMS\Digital\Domain\ProductType;
use FavoriteCMS\Digital\FavoriteDigitalPlugin;
use FavoriteCMS\Digital\Repositories\ProductRepository;
use FavoriteCMS\Digital\Services\DigitalFileStorageService;
use FavoriteCMS\Digital\Services\MembershipLifecycleService;
use FavoriteCMS\Digital\Services\ProductManagementService;
use FavoriteCMS\Digital\Support\MembershipPeriodCalculator;
use FavoriteCMS\Models\User;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Throwable;

class MembershipLifecycleTest extends TestCase
{
    private Application $app;
    private Database $sqliteDb;
    private PDO $sqlitePdo;
    private ProductRepository $repository;
    private MembershipLifecycleService $membershipService;
    private ProductManagementService $productService;
    private AdminMembershipController $controller;

    protected function setUp(): void
    {
        if (!defined('PHPUNIT_RUNNING')) {
            define('PHPUNIT_RUNNING', true);
        }

        $this->app = new Application();

        $this->sqlitePdo = new PDO('sqlite::memory:', '', '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ]);

        $this->sqliteDb = new class($this->sqlitePdo) extends Database {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
                $this->config = ['driver' => 'sqlite'];
                $this->prefix = '';
            }
        };

        $this->sqliteDb->registerPrefixableTables(FavoriteDigitalPlugin::TABLES);

        // Run migrations
        $migrator = new Migrator($this->sqliteDb);
        $migrator->migrate(APP_ROOT . '/plugins/favorite-digital/database/migrations');

        $this->app->singleton(Database::class, fn () => $this->sqliteDb);

        $this->repository = new ProductRepository($this->sqliteDb);
        $this->membershipService = new MembershipLifecycleService($this->repository);
        $storage = new DigitalFileStorageService(sys_get_temp_dir());
        $this->productService = new ProductManagementService($this->repository, $storage);

        $this->controller = new AdminMembershipController(
            $this->app,
            $this->membershipService,
            $this->productService
        );

        $this->app->singleton(ProductRepository::class, fn () => $this->repository);
        $this->app->singleton(MembershipLifecycleService::class, fn () => $this->membershipService);
        $this->app->singleton(ProductManagementService::class, fn () => $this->productService);
        $this->app->singleton(AdminMembershipController::class, fn () => $this->controller);

        $_SESSION = [
            'auth_user_id'   => 1,
            'auth_user_name' => 'Admin User',
            '_token'         => 'valid_membership_csrf_token',
        ];

        $adminUser = new class extends User {
            public int $id = 1;
            public function isActive(): bool { return true; }
            public function can(string $capability): bool { return true; }
        };
        $GLOBALS['_test_current_user'] = $adminUser;
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($GLOBALS['_test_current_user']);
    }

    private function createWeeklyPlan(string $title = 'Weekly Pro Pass', string $price = '10.00', bool $autoRenew = true, int $graceDays = 1): int
    {
        return $this->membershipService->createPlan([
            'title'          => $title,
            'original_price' => $price,
            'status'         => ProductStatus::PUBLISHED,
        ], [
            'plan_type'           => 'weekly',
            'grace_period_days'   => $graceDays,
            'allows_auto_renewal' => $autoRenew ? 1 : 0,
        ]);
    }

    private function createMonthlyPlan(string $title = 'Monthly VIP Pass', string $price = '30.00', bool $autoRenew = true, int $graceDays = 3): int
    {
        return $this->membershipService->createPlan([
            'title'          => $title,
            'original_price' => $price,
            'status'         => ProductStatus::PUBLISHED,
        ], [
            'plan_type'           => 'monthly',
            'grace_period_days'   => $graceDays,
            'allows_auto_renewal' => $autoRenew ? 1 : 0,
        ]);
    }

    private function assertPrice(string $expected, mixed $actual): void
    {
        $this->assertSame($expected, number_format((float)$actual, 2, '.', ''));
    }

    // =========================================================================
    // 1. Calendar Arithmetic & Deterministic Clamping
    // =========================================================================

    public function testWeeklyMembershipDurationIsExactlySevenDays(): void
    {
        $start = new DateTimeImmutable('2026-03-01 10:00:00');
        $expiry = MembershipPeriodCalculator::calculatePeriodExpiry($start, 'week', 1);

        $this->assertSame('2026-03-08 10:00:00', $expiry->format('Y-m-d H:i:s'));
        $interval = $start->diff($expiry);
        $this->assertSame(7, $interval->days);
    }

    public function testMonthlyMembershipDurationStandardMonth(): void
    {
        $start = new DateTimeImmutable('2026-04-15 14:30:00');
        $expiry = MembershipPeriodCalculator::calculatePeriodExpiry($start, 'month', 1);

        $this->assertSame('2026-05-15 14:30:00', $expiry->format('Y-m-d H:i:s'));
    }

    public function testMonthlyClampingJan31ToFeb28InCommonYear(): void
    {
        $start = new DateTimeImmutable('2026-01-31 23:59:59');
        $expiry = MembershipPeriodCalculator::calculatePeriodExpiry($start, 'month', 1);

        // 2026 is not a leap year -> clamps strictly to Feb 28
        $this->assertSame('2026-02-28 23:59:59', $expiry->format('Y-m-d H:i:s'));
    }

    public function testMonthlyClampingJan31ToFeb29InLeapYear(): void
    {
        $start = new DateTimeImmutable('2028-01-31 12:00:00');
        $expiry = MembershipPeriodCalculator::calculatePeriodExpiry($start, 'month', 1);

        // 2028 is a leap year -> clamps strictly to Feb 29
        $this->assertSame('2028-02-29 12:00:00', $expiry->format('Y-m-d H:i:s'));
    }

    public function testMonthlyClampingMar31ToApr30(): void
    {
        $start = new DateTimeImmutable('2026-03-31 08:15:00');
        $expiry = MembershipPeriodCalculator::calculatePeriodExpiry($start, 'month', 1);

        $this->assertSame('2026-04-30 08:15:00', $expiry->format('Y-m-d H:i:s'));
    }

    public function testMonthlyClampingAug31ToSep30(): void
    {
        $start = new DateTimeImmutable('2026-08-31 18:45:00');
        $expiry = MembershipPeriodCalculator::calculatePeriodExpiry($start, 'month', 1);

        $this->assertSame('2026-09-30 18:45:00', $expiry->format('Y-m-d H:i:s'));
    }

    public function testMonthlyYearWrapDec31ToJan31(): void
    {
        $start = new DateTimeImmutable('2026-12-31 23:59:59');
        $expiry = MembershipPeriodCalculator::calculatePeriodExpiry($start, 'month', 1);

        $this->assertSame('2027-01-31 23:59:59', $expiry->format('Y-m-d H:i:s'));
    }

    public function testPreservesExactTimeOfDayAndTimezone(): void
    {
        $tz = new DateTimeZone('Asia/Dhaka');
        $start = new DateTimeImmutable('2026-05-31 17:42:19', $tz);
        $expiry = MembershipPeriodCalculator::calculatePeriodExpiry($start, 'month', 1);

        $this->assertSame('2026-06-30 17:42:19', $expiry->format('Y-m-d H:i:s'));
        $this->assertSame('Asia/Dhaka', $expiry->getTimezone()->getName());
    }

    // =========================================================================
    // 2. Plan Creation, Validation & Product Repository
    // =========================================================================

    public function testCreateWeeklyPlanCreatesProductAndPlanRecords(): void
    {
        $prodId = $this->createWeeklyPlan('Weekly Member Pass', '15.00', true, 1);

        $product = $this->repository->findProduct($prodId);
        $this->assertNotNull($product);
        $this->assertSame('Weekly Member Pass', $product->title);
        $this->assertSame(ProductType::MEMBERSHIP, $product->product_type);
        $this->assertPrice('15.00', $product->final_price);

        $plan = $this->repository->findMembershipPlanByProductId($prodId);
        $this->assertNotNull($plan);
        $this->assertSame('weekly', $plan->plan_type);
        $this->assertSame(1, (int)$plan->duration_count);
        $this->assertSame('week', $plan->duration_unit);
        $this->assertSame(1, (int)$plan->grace_period_days);
        $this->assertSame(1, (int)$plan->allows_auto_renewal);
    }

    public function testCreateMonthlyPlanDefaultsAndGraceDays(): void
    {
        $prodId = $this->createMonthlyPlan('Monthly Gold', '49.99', false, 3);

        $plan = $this->repository->findMembershipPlanByProductId($prodId);
        $this->assertNotNull($plan);
        $this->assertSame('monthly', $plan->plan_type);
        $this->assertSame(1, (int)$plan->duration_count);
        $this->assertSame('month', $plan->duration_unit);
        $this->assertSame(3, (int)$plan->grace_period_days);
        $this->assertSame(0, (int)$plan->allows_auto_renewal);
    }

    public function testPlanValidationRejectsInvalidPlanType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid plan type 'yearly'");

        $this->membershipService->validatePlanData(['plan_type' => 'yearly']);
    }

    public function testPlanValidationRejectsNegativeGraceDays(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Grace period days cannot be negative.');

        $this->membershipService->validatePlanData([
            'plan_type'         => 'weekly',
            'grace_period_days' => -5,
        ]);
    }

    public function testUpdatePlanModifiesProductAndPlanAttributes(): void
    {
        $prodId = $this->createMonthlyPlan('VIP Pass', '25.00', false, 2);

        $this->membershipService->updatePlan($prodId, [
            'title'            => 'VIP Pass Premium',
            'original_price'   => '35.00',
            'discount_percent' => '10.00',
            'status'           => ProductStatus::PUBLISHED,
        ], [
            'plan_type'           => 'monthly',
            'grace_period_days'   => 5,
            'allows_auto_renewal' => 1,
        ]);

        $product = $this->repository->findProduct($prodId);
        $this->assertSame('VIP Pass Premium', $product->title);
        $this->assertPrice('31.50', $product->final_price); // 35.00 - 10%

        $plan = $this->repository->findMembershipPlanByProductId($prodId);
        $this->assertSame(5, (int)$plan->grace_period_days);
        $this->assertSame(1, (int)$plan->allows_auto_renewal);
    }

    // =========================================================================
    // 3. Customer Membership Activation & Zero Loss of Paid Time
    // =========================================================================

    public function testFreshActivationStartsFromCurrentTime(): void
    {
        $prodId = $this->createMonthlyPlan();
        $plan = $this->repository->findMembershipPlanByProductId($prodId);

        $now = new DateTimeImmutable('2026-05-10 10:00:00');
        $mem = $this->membershipService->activateMembership(101, (int)$plan->id, false, $now);

        $this->assertSame(101, (int)$mem->user_id);
        $this->assertSame((int)$plan->id, (int)$mem->plan_id);
        $this->assertSame(MembershipStatus::ACTIVE, $mem->status);
        $this->assertSame('2026-05-10 10:00:00', $mem->started_at);
        $this->assertSame('2026-06-10 10:00:00', $mem->expires_at);
        $this->assertSame(0, (int)$mem->auto_renew);
    }

    public function testActiveExtensionZeroLossOfPaidTime(): void
    {
        $prodId = $this->createMonthlyPlan();
        $plan = $this->repository->findMembershipPlanByProductId($prodId);

        // Step 1: User activates on May 1, expires June 1
        $t1 = new DateTimeImmutable('2026-05-01 12:00:00');
        $mem1 = $this->membershipService->activateMembership(102, (int)$plan->id, false, $t1);
        $this->assertSame('2026-06-01 12:00:00', $mem1->expires_at);

        // Step 2: On May 20 (still 12 days remaining), user renews for another month.
        // Rule: Expiry must be June 1 + 1 month = July 1 (Zero paid time lost!)
        $t2 = new DateTimeImmutable('2026-05-20 15:30:00');
        $mem2 = $this->membershipService->activateMembership(102, (int)$plan->id, false, $t2);

        $this->assertSame((int)$mem1->id, (int)$mem2->id, 'Should update existing membership record');
        $this->assertSame('2026-07-01 12:00:00', $mem2->expires_at);
        $this->assertSame(MembershipStatus::ACTIVE, $mem2->status);
    }

    public function testMultipleConsecutiveExtensionsAccumulateCleanly(): void
    {
        $prodId = $this->createWeeklyPlan();
        $plan = $this->repository->findMembershipPlanByProductId($prodId);

        $t0 = new DateTimeImmutable('2026-06-01 09:00:00');
        $mem = $this->membershipService->activateMembership(103, (int)$plan->id, false, $t0);
        $this->assertSame('2026-06-08 09:00:00', $mem->expires_at); // +7 days

        // Extend by another week immediately
        $mem = $this->membershipService->extendMembership((int)$mem->id, (int)$plan->id, $t0);
        $this->assertSame('2026-06-15 09:00:00', $mem->expires_at); // +14 days

        // Extend by a third week
        $mem = $this->membershipService->extendMembership((int)$mem->id, (int)$plan->id, $t0);
        $this->assertSame('2026-06-22 09:00:00', $mem->expires_at); // +21 days
    }

    public function testExpiredMembershipStartsFromNowAndDoesNotBackdate(): void
    {
        $prodId = $this->createMonthlyPlan();
        $plan = $this->repository->findMembershipPlanByProductId($prodId);

        // Created in January, expired in February
        $this->repository->createMembership([
            'user_id'          => 104,
            'plan_id'          => $plan->id,
            'status'           => MembershipStatus::EXPIRED,
            'started_at'       => '2026-01-01 00:00:00',
            'expires_at'       => '2026-02-01 00:00:00',
            'grace_expires_at' => null,
            'auto_renew'       => 0,
        ]);

        // Re-purchases on April 15
        $now = new DateTimeImmutable('2026-04-15 11:00:00');
        $mem = $this->membershipService->activateMembership(104, (int)$plan->id, false, $now);

        // Must start from April 15, not backdated
        $this->assertSame('2026-04-15 11:00:00', $mem->started_at);
        $this->assertSame('2026-05-15 11:00:00', $mem->expires_at);
        $this->assertSame(MembershipStatus::ACTIVE, $mem->status);
    }

    public function testWeeklyToMonthlyPlanSwitchPreservesRemainingDays(): void
    {
        $weeklyProd = $this->createWeeklyPlan();
        $weeklyPlan = $this->repository->findMembershipPlanByProductId($weeklyProd);

        $monthlyProd = $this->createMonthlyPlan();
        $monthlyPlan = $this->repository->findMembershipPlanByProductId($monthlyProd);

        // User starts weekly membership on June 10 -> expires June 17
        $t1 = new DateTimeImmutable('2026-06-10 12:00:00');
        $mem = $this->membershipService->activateMembership(105, (int)$weeklyPlan->id, false, $t1);
        $this->assertSame('2026-06-17 12:00:00', $mem->expires_at);

        // On June 12 (5 days left), user upgrades to Monthly plan
        $t2 = new DateTimeImmutable('2026-06-12 12:00:00');
        $updated = $this->membershipService->activateMembership(105, (int)$monthlyPlan->id, false, $t2);

        // June 17 + 1 calendar month = July 17!
        $this->assertSame('2026-07-17 12:00:00', $updated->expires_at);
        $this->assertSame((int)$monthlyPlan->id, (int)$updated->plan_id);
    }

    public function testMonthlyToWeeklyPlanSwitchPreservesRemainingDays(): void
    {
        $monthlyProd = $this->createMonthlyPlan();
        $monthlyPlan = $this->repository->findMembershipPlanByProductId($monthlyProd);

        $weeklyProd = $this->createWeeklyPlan();
        $weeklyPlan = $this->repository->findMembershipPlanByProductId($weeklyProd);

        // User starts monthly on Jan 15 -> expires Feb 15
        $t1 = new DateTimeImmutable('2026-01-15 10:00:00');
        $mem = $this->membershipService->activateMembership(106, (int)$monthlyPlan->id, false, $t1);
        $this->assertSame('2026-02-15 10:00:00', $mem->expires_at);

        // On Feb 1, extends with weekly pass -> Feb 15 + 7 days = Feb 22
        $t2 = new DateTimeImmutable('2026-02-01 10:00:00');
        $updated = $this->membershipService->activateMembership(106, (int)$weeklyPlan->id, false, $t2);

        $this->assertSame('2026-02-22 10:00:00', $updated->expires_at);
    }

    // =========================================================================
    // 4. Auto-Renewal Business Rules
    // =========================================================================

    public function testAutoRenewalDefaultIsOff(): void
    {
        $prodId = $this->createMonthlyPlan('VIP Auto', '30.00', true);
        $plan = $this->repository->findMembershipPlanByProductId($prodId);

        $mem = $this->membershipService->activateMembership(107, (int)$plan->id);
        $this->assertSame(0, (int)$mem->auto_renew, 'Default purchase must have auto_renew OFF');
    }

    public function testAutoRenewalExplicitOptInAllowedIfPlanPermits(): void
    {
        $prodId = $this->createMonthlyPlan('VIP Auto Allowed', '30.00', true);
        $plan = $this->repository->findMembershipPlanByProductId($prodId);

        $mem = $this->membershipService->activateMembership(108, (int)$plan->id, true);
        $this->assertSame(1, (int)$mem->auto_renew);
    }

    public function testAutoRenewalCannotBeEnabledIfPlanDisallows(): void
    {
        $prodId = $this->createMonthlyPlan('No Auto Plan', '30.00', false); // allows_auto_renewal = 0
        $plan = $this->repository->findMembershipPlanByProductId($prodId);

        // Attempting to activate with auto_renew = true should be forced to 0
        $mem = $this->membershipService->activateMembership(109, (int)$plan->id, true);
        $this->assertSame(0, (int)$mem->auto_renew);

        // Attempting to enable later should throw LogicException
        $this->expectException(LogicException::class);
        $this->membershipService->enableAutoRenewal((int)$mem->id);
    }

    public function testDisablingAutoRenewalPreservesCurrentPaidTime(): void
    {
        $prodId = $this->createMonthlyPlan('VIP Auto', '30.00', true);
        $plan = $this->repository->findMembershipPlanByProductId($prodId);

        $now = new DateTimeImmutable('2026-05-01 10:00:00');
        $mem = $this->membershipService->activateMembership(110, (int)$plan->id, true, $now);
        $this->assertSame(1, (int)$mem->auto_renew);
        $origExpiry = $mem->expires_at;

        // Customer opts out of auto-renewal
        $this->membershipService->disableAutoRenewal((int)$mem->id);

        $reloaded = $this->repository->findMembership((int)$mem->id);
        $this->assertSame(0, (int)$reloaded->auto_renew);
        $this->assertSame(MembershipStatus::ACTIVE, $reloaded->status);
        $this->assertSame($origExpiry, $reloaded->expires_at, 'Paid time must not be altered or shortened');
    }

    public function testRenewalSuccessExtendsFromCurrentExpiryAndClearsGrace(): void
    {
        $prodId = $this->createMonthlyPlan('VIP Auto', '30.00', true);
        $plan = $this->repository->findMembershipPlanByProductId($prodId);

        $t1 = new DateTimeImmutable('2026-05-01 10:00:00');
        $mem = $this->membershipService->activateMembership(111, (int)$plan->id, true, $t1);
        $this->assertSame('2026-06-01 10:00:00', $mem->expires_at);

        // Renewal triggers on June 1
        $t2 = new DateTimeImmutable('2026-06-01 10:00:00');
        $renewed = $this->membershipService->processRenewalSuccess((int)$mem->id, $t2);

        $this->assertSame('2026-07-01 10:00:00', $renewed->expires_at);
        $this->assertSame(MembershipStatus::ACTIVE, $renewed->status);
        $this->assertNull($renewed->grace_expires_at);
    }

    // =========================================================================
    // 5. Grace Period Lifecycle
    // =========================================================================

    public function testRenewalFailureTransitionsToGracePeriodWeeklyDefaultOneDay(): void
    {
        $prodId = $this->createWeeklyPlan('Weekly Auto', '10.00', true, 1);
        $plan = $this->repository->findMembershipPlanByProductId($prodId);

        $t1 = new DateTimeImmutable('2026-05-01 10:00:00');
        $mem = $this->membershipService->activateMembership(112, (int)$plan->id, true, $t1);
        $this->assertSame('2026-05-08 10:00:00', $mem->expires_at);

        // Renewal payment fails on May 8
        $t2 = new DateTimeImmutable('2026-05-08 10:00:00');
        $graceMem = $this->membershipService->processRenewalFailure((int)$mem->id, $t2);

        $this->assertSame(MembershipStatus::GRACE, $graceMem->status);
        // May 8 + 1 day = May 9
        $this->assertSame('2026-05-09 10:00:00', $graceMem->grace_expires_at);
    }

    public function testRenewalFailureTransitionsToGracePeriodMonthlyDefaultThreeDays(): void
    {
        $prodId = $this->createMonthlyPlan('Monthly Auto', '30.00', true, 3);
        $plan = $this->repository->findMembershipPlanByProductId($prodId);

        $t1 = new DateTimeImmutable('2026-05-01 10:00:00');
        $mem = $this->membershipService->activateMembership(113, (int)$plan->id, true, $t1);
        $this->assertSame('2026-06-01 10:00:00', $mem->expires_at);

        // Renewal payment fails on June 1
        $t2 = new DateTimeImmutable('2026-06-01 10:00:00');
        $graceMem = $this->membershipService->processRenewalFailure((int)$mem->id, $t2);

        $this->assertSame(MembershipStatus::GRACE, $graceMem->status);
        // June 1 + 3 days = June 4
        $this->assertSame('2026-06-04 10:00:00', $graceMem->grace_expires_at);
    }

    public function testRenewalFailureZeroGraceDaysImmediatelyExpires(): void
    {
        $prodId = $this->createWeeklyPlan('No Grace Weekly', '10.00', true, 0); // 0 grace days
        $plan = $this->repository->findMembershipPlanByProductId($prodId);

        $mem = $this->membershipService->activateMembership(114, (int)$plan->id, true);
        $failed = $this->membershipService->processRenewalFailure((int)$mem->id);

        $this->assertSame(MembershipStatus::EXPIRED, $failed->status);
        $this->assertNull($failed->grace_expires_at);
    }

    public function testGracePeriodMaintainsActiveStoreAccessWhileWindowIsOpen(): void
    {
        $prodId = $this->createMonthlyPlan('Monthly Auto', '30.00', true, 3);
        $plan = $this->repository->findMembershipPlanByProductId($prodId);

        $t1 = new DateTimeImmutable('2026-05-01 10:00:00');
        $mem = $this->membershipService->activateMembership(115, (int)$plan->id, true, $t1);

        // Enters grace on June 1 (grace until June 4 10:00)
        $tFail = new DateTimeImmutable('2026-06-01 10:00:00');
        $this->membershipService->processRenewalFailure((int)$mem->id, $tFail);

        // Check access on June 2 (during grace): must be TRUE
        $duringGrace = new DateTimeImmutable('2026-06-02 12:00:00');
        $this->assertTrue($this->membershipService->hasActiveMembership(115, $duringGrace));

        // Check access on June 5 (grace expired): must be FALSE
        $afterGrace = new DateTimeImmutable('2026-06-05 12:00:00');
        $this->assertFalse($this->membershipService->hasActiveMembership(115, $afterGrace));
    }

    public function testGraceRecoveryRestoresActiveStatusAndClearsGraceTimestamp(): void
    {
        $prodId = $this->createMonthlyPlan('Monthly Auto', '30.00', true, 3);
        $plan = $this->repository->findMembershipPlanByProductId($prodId);

        $t1 = new DateTimeImmutable('2026-05-01 10:00:00');
        $mem = $this->membershipService->activateMembership(116, (int)$plan->id, true, $t1);
        $this->membershipService->processRenewalFailure((int)$mem->id, new DateTimeImmutable('2026-06-01 10:00:00'));

        // Customer updates payment on June 2
        $tRecovery = new DateTimeImmutable('2026-06-02 14:00:00');
        $recovered = $this->membershipService->recoverFromGrace((int)$mem->id, $tRecovery);

        $this->assertSame(MembershipStatus::ACTIVE, $recovered->status);
        $this->assertNull($recovered->grace_expires_at);
        $this->assertTrue($this->membershipService->hasActiveMembership(116, $tRecovery));
    }

    // =========================================================================
    // 6. Membership Expiration, Cancellation & Sweep
    // =========================================================================

    public function testImmediateAdminExpirationRevokesAccess(): void
    {
        $prodId = $this->createMonthlyPlan();
        $plan = $this->repository->findMembershipPlanByProductId($prodId);

        $mem = $this->membershipService->activateMembership(117, (int)$plan->id, true);
        $this->assertTrue($this->membershipService->hasActiveMembership(117));

        $this->membershipService->expireMembership((int)$mem->id);

        $reloaded = $this->repository->findMembership((int)$mem->id);
        $this->assertSame(MembershipStatus::EXPIRED, $reloaded->status);
        $this->assertSame(0, (int)$reloaded->auto_renew);
        $this->assertFalse($this->membershipService->hasActiveMembership(117));
    }

    public function testCancellationPreservesPaidTimeUntilExpiresAt(): void
    {
        $prodId = $this->createMonthlyPlan();
        $plan = $this->repository->findMembershipPlanByProductId($prodId);

        $start = new DateTimeImmutable('2026-05-01 10:00:00');
        $mem = $this->membershipService->activateMembership(118, (int)$plan->id, true, $start);

        // Cancel on May 10
        $this->membershipService->cancelMembership((int)$mem->id);

        $reloaded = $this->repository->findMembership((int)$mem->id);
        $this->assertSame(MembershipStatus::CANCELLED, $reloaded->status);
        $this->assertSame(0, (int)$reloaded->auto_renew);

        // Must still have access on May 25
        $tBeforeExpiry = new DateTimeImmutable('2026-05-25 10:00:00');
        $this->assertTrue($this->membershipService->hasActiveMembership(118, $tBeforeExpiry));

        // Must lose access on June 2
        $tAfterExpiry = new DateTimeImmutable('2026-06-02 10:00:00');
        $this->assertFalse($this->membershipService->hasActiveMembership(118, $tAfterExpiry));
    }

    public function testCheckAndExpireMembershipsSweepsLapsedRecords(): void
    {
        $prodId = $this->createMonthlyPlan();
        $plan = $this->repository->findMembershipPlanByProductId($prodId);

        // Membership A: active, non-renewing, expired yesterday
        $this->repository->createMembership([
            'user_id'          => 201,
            'plan_id'          => $plan->id,
            'status'           => MembershipStatus::ACTIVE,
            'started_at'       => '2026-04-01 10:00:00',
            'expires_at'       => '2026-05-01 10:00:00',
            'grace_expires_at' => null,
            'auto_renew'       => 0,
        ]);

        // Membership B: grace, grace_expires_at passed
        $this->repository->createMembership([
            'user_id'          => 202,
            'plan_id'          => $plan->id,
            'status'           => MembershipStatus::GRACE,
            'started_at'       => '2026-04-01 10:00:00',
            'expires_at'       => '2026-05-01 10:00:00',
            'grace_expires_at' => '2026-05-04 10:00:00',
            'auto_renew'       => 1,
        ]);

        // Membership C: active, still valid
        $this->repository->createMembership([
            'user_id'          => 203,
            'plan_id'          => $plan->id,
            'status'           => MembershipStatus::ACTIVE,
            'started_at'       => '2026-05-01 10:00:00',
            'expires_at'       => '2026-06-01 10:00:00',
            'grace_expires_at' => null,
            'auto_renew'       => 0,
        ]);

        $sweepTime = new DateTimeImmutable('2026-05-05 10:00:00');
        $count = $this->membershipService->checkAndExpireMemberships($sweepTime);

        $this->assertSame(2, $count);

        $memA = $this->repository->findActiveMembershipForUser(201, '2026-05-05 10:00:00');
        $this->assertNull($memA);

        $memB = $this->repository->findActiveMembershipForUser(202, '2026-05-05 10:00:00');
        $this->assertNull($memB);

        $memC = $this->repository->findActiveMembershipForUser(203, '2026-05-05 10:00:00');
        $this->assertNotNull($memC);
    }

    // =========================================================================
    // 7. Content Eligibility & Restricting Access
    // =========================================================================

    public function testProductNotEligibleForMembershipCannotBeClaimedViaMembership(): void
    {
        $prodId = $this->productService->createDigitalProduct([
            'title' => 'Standard Non-Member Product',
        ], [
            'version'                => '1.0.0',
            'is_membership_eligible' => 0,
        ]);

        // Active member does NOT get it as a membership benefit
        $planProd = $this->createMonthlyPlan();
        $plan = $this->repository->findMembershipPlanByProductId($planProd);
        $this->membershipService->activateMembership(999, (int)$plan->id);

        $this->assertFalse($this->membershipService->isEligibleForMembershipContent(999, $prodId));
    }

    public function testProductEligibleForMembershipRestrictsNonMembers(): void
    {
        $prodId = $this->productService->createDigitalProduct([
            'title' => 'Exclusive Member Asset',
        ], [
            'version'                => '1.0.0',
            'is_membership_eligible' => 1,
        ]);

        // Non-member denied
        $this->assertFalse($this->membershipService->isEligibleForMembershipContent(301, $prodId));
        // Guest denied
        $this->assertFalse($this->membershipService->isEligibleForMembershipContent(0, $prodId));

        // Grant membership
        $planProd = $this->createMonthlyPlan();
        $plan = $this->repository->findMembershipPlanByProductId($planProd);
        $this->membershipService->activateMembership(301, (int)$plan->id);

        // Active member allowed
        $this->assertTrue($this->membershipService->isEligibleForMembershipContent(301, $prodId));
    }

    // =========================================================================
    // 8. Admin Controller & Security Enforcement
    // =========================================================================

    public function testAdminControllerRequiresAuthentication(): void
    {
        unset($_SESSION['auth_user_id']);
        unset($GLOBALS['_test_current_user']);

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $response = $this->controller->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/login', $response->getHeaders()['Location'] ?? '');
    }

    public function testAdminControllerBlocksInactiveUsers(): void
    {
        $inactiveUser = new class extends User {
            public int $id = 99;
            public function isActive(): bool { return false; }
            public function can(string $c): bool { return true; }
        };
        $GLOBALS['_test_current_user'] = $inactiveUser;
        $_SESSION['auth_user_id'] = 99;

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $response = $this->controller->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAdminControllerBlocksUnauthorizedUsersWithoutManageOptions(): void
    {
        $regularUser = new class extends User {
            public int $id = 88;
            public function isActive(): bool { return true; }
            public function can(string $c): bool { return false; }
        };
        $GLOBALS['_test_current_user'] = $regularUser;
        $_SESSION['auth_user_id'] = 88;

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $response = $this->controller->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAdminControllerRejectsInvalidCsrfTokenOnPost(): void
    {
        $request = new Request([], [
            'action' => 'store_plan',
            'title'  => 'Hacked Plan',
            '_token' => 'invalid_token_123',
        ], ['REQUEST_METHOD' => 'POST']);

        $response = $this->controller->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('Security token expired or invalid (CSRF failure). Please try again.', $_SESSION['flash_error'] ?? '');
    }

    public function testAdminControllerStorePlanAction(): void
    {
        $request = new Request([], [
            'action'              => 'store_plan',
            'title'               => 'Controller Created Monthly',
            'slug'                => 'controller-created-monthly',
            'original_price'      => '19.99',
            'discount_percent'    => '0.00',
            'plan_type'           => 'monthly',
            'grace_period_days'   => '3',
            'allows_auto_renewal' => '1',
            '_token'              => 'valid_membership_csrf_token',
        ], ['REQUEST_METHOD' => 'POST']);

        $response = $this->controller->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('created successfully', $_SESSION['flash_success'] ?? '');

        $plans = $this->membershipService->listPlans();
        $this->assertCount(1, $plans);
        $this->assertSame('Controller Created Monthly', $plans[0]->title);
    }

    public function testAdminControllerExtendAction(): void
    {
        $prodId = $this->createMonthlyPlan();
        $plan = $this->repository->findMembershipPlanByProductId($prodId);
        $mem = $this->membershipService->activateMembership(401, (int)$plan->id);

        $request = new Request([], [
            'action'      => 'extend',
            'id'          => (string)$mem->id,
            'new_plan_id' => (string)$plan->id,
            '_token'      => 'valid_membership_csrf_token',
        ], ['REQUEST_METHOD' => 'POST']);

        $response = $this->controller->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('extended successfully', $_SESSION['flash_success'] ?? '');
    }

    public function testAdminControllerToggleAutoRenewAction(): void
    {
        $prodId = $this->createMonthlyPlan('Toggle Auto', '20.00', true);
        $plan = $this->repository->findMembershipPlanByProductId($prodId);
        $mem = $this->membershipService->activateMembership(402, (int)$plan->id, false);
        $this->assertSame(0, (int)$mem->auto_renew);

        // Turn ON
        $reqOn = new Request([], [
            'action' => 'toggle_auto_renew',
            'id'     => (string)$mem->id,
            'enable' => '1',
            '_token' => 'valid_membership_csrf_token',
        ], ['REQUEST_METHOD' => 'POST']);
        $this->controller->handle($reqOn);

        $updated = $this->repository->findMembership((int)$mem->id);
        $this->assertSame(1, (int)$updated->auto_renew);

        // Turn OFF
        $reqOff = new Request([], [
            'action' => 'toggle_auto_renew',
            'id'     => (string)$mem->id,
            'enable' => '0',
            '_token' => 'valid_membership_csrf_token',
        ], ['REQUEST_METHOD' => 'POST']);
        $this->controller->handle($reqOff);

        $updated2 = $this->repository->findMembership((int)$mem->id);
        $this->assertSame(0, (int)$updated2->auto_renew);
    }

    public function testAdminControllerManualExpireAction(): void
    {
        $prodId = $this->createMonthlyPlan();
        $plan = $this->repository->findMembershipPlanByProductId($prodId);
        $mem = $this->membershipService->activateMembership(403, (int)$plan->id);

        $request = new Request([], [
            'action' => 'expire',
            'id'     => (string)$mem->id,
            '_token' => 'valid_membership_csrf_token',
        ], ['REQUEST_METHOD' => 'POST']);

        $response = $this->controller->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('manually expired', $_SESSION['flash_success'] ?? '');

        $reloaded = $this->repository->findMembership((int)$mem->id);
        $this->assertSame(MembershipStatus::EXPIRED, $reloaded->status);
    }

    public function testAdminControllerRecoverGraceAction(): void
    {
        $prodId = $this->createMonthlyPlan();
        $plan = $this->repository->findMembershipPlanByProductId($prodId);
        $mem = $this->membershipService->activateMembership(404, (int)$plan->id);
        $this->membershipService->processRenewalFailure((int)$mem->id);

        $request = new Request([], [
            'action' => 'recover_grace',
            'id'     => (string)$mem->id,
            '_token' => 'valid_membership_csrf_token',
        ], ['REQUEST_METHOD' => 'POST']);

        $response = $this->controller->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('recovered from grace', $_SESSION['flash_success'] ?? '');

        $reloaded = $this->repository->findMembership((int)$mem->id);
        $this->assertSame(MembershipStatus::ACTIVE, $reloaded->status);
        $this->assertNull($reloaded->grace_expires_at);
    }

    public function testAdminControllerGetViewsRenderCleanly(): void
    {
        $prodId = $this->createMonthlyPlan('Dashboard Plan', '19.99');
        $plan = $this->repository->findMembershipPlanByProductId($prodId);
        $mem = $this->membershipService->activateMembership(501, (int)$plan->id);

        // Index
        $reqIndex = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $htmlIndex = $this->controller->handle($reqIndex);
        $this->assertIsString($htmlIndex);
        $this->assertStringContainsString('Membership Management', $htmlIndex);
        $this->assertStringContainsString('Dashboard Plan', $htmlIndex);

        // Create Plan Form
        $reqCreate = new Request(['action' => 'create_plan'], [], ['REQUEST_METHOD' => 'GET']);
        $htmlCreate = $this->controller->handle($reqCreate);
        $this->assertIsString($htmlCreate);
        $this->assertStringContainsString('Create Membership Plan', $htmlCreate);

        // Edit Plan Form
        $reqEdit = new Request(['action' => 'edit_plan', 'id' => (string)$prodId], [], ['REQUEST_METHOD' => 'GET']);
        $htmlEdit = $this->controller->handle($reqEdit);
        $this->assertIsString($htmlEdit);
        $this->assertStringContainsString('Edit Membership Tier', $htmlEdit);

        // View Customer Membership
        $reqView = new Request(['action' => 'view_membership', 'id' => (string)$mem->id], [], ['REQUEST_METHOD' => 'GET']);
        $htmlView = $this->controller->handle($reqView);
        $this->assertIsString($htmlView);
        $this->assertStringContainsString('Subscription #' . $mem->id, $htmlView);
        $this->assertStringContainsString('User #501', $htmlView);
    }

    // =========================================================================
    // 9. Dual SQLite & Prefix Database Compatibility
    // =========================================================================

    public function testPrefixTableResolutionWithPrefixedDatabase(): void
    {
        $customPdo = new PDO('sqlite::memory:', '', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $customDb = new class($customPdo) extends Database {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
                $this->config = ['driver' => 'sqlite', 'prefix' => 'fvt_'];
                $this->prefix = 'fvt_';
            }
        };

        $customDb->registerPrefixableTables(FavoriteDigitalPlugin::TABLES);

        $this->assertSame('`fvt_favorite_digital_membership_plans`', $customDb->quoteIdentifier('favorite_digital_membership_plans'));
        $this->assertSame('`fvt_favorite_digital_memberships`', $customDb->quoteIdentifier('favorite_digital_memberships'));
    }

    public function testLiveMySqlOrMariaDbIfAvailable(): void
    {
        try {
            $mysqlPdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=test', 'root', '', [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            ]);
        } catch (PDOException) {
            $this->markTestSkipped('MySQL not reachable on 127.0.0.1:3306 with test database.');
        }

        $mysqlDb = new class($mysqlPdo) extends Database {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
                $this->config = ['driver' => 'mysql', 'prefix' => 'fvt_'];
                $this->prefix = 'fvt_';
            }
        };

        $mysqlDb->registerPrefixableTables(FavoriteDigitalPlugin::TABLES);

        // Drop existing test tables if any
        $mysqlDb->execute("DROP TABLE IF EXISTS `fvt_favorite_digital_memberships`");
        $mysqlDb->execute("DROP TABLE IF EXISTS `fvt_favorite_digital_membership_plans`");
        $mysqlDb->execute("DROP TABLE IF EXISTS `fvt_favorite_digital_products`");
        $mysqlDb->execute("DROP TABLE IF EXISTS `fvt_migrations`");
        $mysqlDb->execute("DROP TABLE IF EXISTS `cms_migrations`");
        $mysqlDb->execute("DROP TABLE IF EXISTS `migrations`");

        // Run migrations
        $migrator = new Migrator($mysqlDb);
        $migrator->migrate(APP_ROOT . '/plugins/favorite-digital/database/migrations');

        $repo = new ProductRepository($mysqlDb);
        $service = new MembershipLifecycleService($repo);

        $prodId = $service->createPlan([
            'title'          => 'MySQL VIP Pass',
            'original_price' => '49.99',
            'status'         => ProductStatus::PUBLISHED,
        ], [
            'plan_type'           => 'monthly',
            'grace_period_days'   => 3,
            'allows_auto_renewal' => 1,
        ]);

        $this->assertGreaterThan(0, $prodId);
        $plan = $repo->findMembershipPlanByProductId($prodId);
        $this->assertNotNull($plan);

        $now = new DateTimeImmutable('2026-05-15 12:00:00');
        $mem = $service->activateMembership(888, (int)$plan->id, true, $now);
        $this->assertSame('2026-06-15 12:00:00', $mem->expires_at);

        $this->assertTrue($service->hasActiveMembership(888, $now));

        // Cleanup
        $mysqlDb->execute("DROP TABLE IF EXISTS `fvt_favorite_digital_memberships`");
        $mysqlDb->execute("DROP TABLE IF EXISTS `fvt_favorite_digital_membership_plans`");
        $mysqlDb->execute("DROP TABLE IF EXISTS `fvt_favorite_digital_products`");
        $mysqlDb->execute("DROP TABLE IF EXISTS `fvt_migrations`");
        $mysqlDb->execute("DROP TABLE IF EXISTS `cms_migrations`");
        $mysqlDb->execute("DROP TABLE IF EXISTS `migrations`");
    }
}
