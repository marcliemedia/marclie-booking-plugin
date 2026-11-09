<?php
/**
 * Composition root: lazily built singletons for services and repositories.
 * Tests may swap the clock via Container::setClock() (resets dependents).
 */

namespace Marclie\Booking;

use Marclie\Booking\Account\AccountService;
use Marclie\Booking\Account\Auth;
use Marclie\Booking\Account\CancelRequest;
use Marclie\Booking\Account\CardUpdate;
use Marclie\Booking\Account\Membership;
use Marclie\Booking\Account\PasswordReset;
use Marclie\Booking\Account\Registration;
use Marclie\Booking\Account\Verification;
use Marclie\Booking\Account\WpMembershipStore;
use Marclie\Booking\Domain\Clock;
use Marclie\Booking\Domain\Policy;
use Marclie\Booking\Domain\WpClock;
use Marclie\Booking\Email\Mailer;
use Marclie\Booking\Email\Scheduler as EmailScheduler;
use Marclie\Booking\Email\TemplateRepository;
use Marclie\Booking\Ical\ConflictDetector;
use Marclie\Booking\Ical\Exporter as IcalExporter;
use Marclie\Booking\Ical\FeedRepository;
use Marclie\Booking\Ical\Importer as IcalImporter;
use Marclie\Booking\Ical\SyncService as IcalSync;
use Marclie\Booking\Guests\GuestService;
use Marclie\Booking\Payment\BalanceCharger;
use Marclie\Booking\Payment\CheckoutService;
use Marclie\Booking\Payment\PaymentGateway;
use Marclie\Booking\Payment\PaymentLedger;
use Marclie\Booking\Payment\PaymentReconciler;
use Marclie\Booking\Payment\RefundService;
use Marclie\Booking\Payment\StripeConfig;
use Marclie\Booking\Payment\StripePaymentGateway;
use Marclie\Booking\Payment\WebhookHandler;
use Marclie\Booking\Payment\WpPaymentLedger;
use Marclie\Booking\Repository\BlockRepository;
use Marclie\Booking\Repository\BookingRepository;
use Marclie\Booking\Repository\CouponRedemptionRepository;
use Marclie\Booking\Repository\CouponRepository;
use Marclie\Booking\Repository\EmailTemplateRepository;
use Marclie\Booking\Repository\EventRepository;
use Marclie\Booking\Repository\GuestRepository;
use Marclie\Booking\Repository\NightsRepository;
use Marclie\Booking\Repository\PaymentRepository;
use Marclie\Booking\Repository\PriceRepository;
use Marclie\Booking\Repository\TokenRepository;
use Marclie\Booking\Service\AvailabilityCache;
use Marclie\Booking\Service\AvailabilityService;
use Marclie\Booking\Service\BlockService;
use Marclie\Booking\Service\BookingService;
use Marclie\Booking\Service\DashboardService;
use Marclie\Booking\Service\CancellationService;
use Marclie\Booking\Service\CouponService;
use Marclie\Booking\Service\HoldReaper;
use Marclie\Booking\Service\PricingService;
use Marclie\Booking\Service\PriceService;
use Marclie\Booking\Service\SearchService;
use Marclie\Booking\Service\ReferenceGenerator;
use Marclie\Booking\Units\PropertyUnitProvider;
use Marclie\Booking\Units\UnitProvider;

defined( 'ABSPATH' ) || exit;

final class Container {

	/** @var array<string, object> */
	private static array $instances = array();

	private static ?Clock $clock = null;

	/** Resolved payment gateway (null = online payments unavailable); false = not resolved yet. */
	private static PaymentGateway|null|false $gateway = false;

	/**
	 * @template T of object
	 * @param class-string<T> $id
	 * @param callable(): T   $factory
	 * @return T
	 */
	private static function shared( string $id, callable $factory ): object {
		return self::$instances[ $id ] ??= $factory();
	}

	/** Drops every built instance (tests, settings changes). */
	public static function reset(): void {
		self::$instances = array();
		self::$gateway   = false;
	}

	public static function setClock( ?Clock $clock ): void {
		self::$clock = $clock;
		self::reset();
	}

	public static function clock(): Clock {
		return self::$clock ??= new WpClock();
	}

	public static function policy(): Policy {
		return self::shared( Policy::class, static fn(): Policy => Policy::fromSettings( new Settings() ) );
	}

	public static function units(): UnitProvider {
		return self::shared( UnitProvider::class, static fn(): UnitProvider => new PropertyUnitProvider() );
	}

	/* Repositories --------------------------------------------------------- */

	public static function bookingRepository(): BookingRepository {
		return self::shared( BookingRepository::class, static fn() => new BookingRepository() );
	}

	public static function guestRepository(): GuestRepository {
		return self::shared( GuestRepository::class, static fn() => new GuestRepository() );
	}

	public static function nightsRepository(): NightsRepository {
		return self::shared( NightsRepository::class, static fn() => new NightsRepository() );
	}

	public static function blockRepository(): BlockRepository {
		return self::shared( BlockRepository::class, static fn() => new BlockRepository() );
	}

	public static function priceRepository(): PriceRepository {
		return self::shared( PriceRepository::class, static fn() => new PriceRepository() );
	}

	public static function couponRepository(): CouponRepository {
		return self::shared( CouponRepository::class, static fn() => new CouponRepository() );
	}

	public static function couponRedemptionRepository(): CouponRedemptionRepository {
		return self::shared( CouponRedemptionRepository::class, static fn() => new CouponRedemptionRepository() );
	}

	public static function emailTemplateRepository(): EmailTemplateRepository {
		return self::shared( EmailTemplateRepository::class, static fn() => new EmailTemplateRepository() );
	}

	public static function paymentRepository(): PaymentRepository {
		return self::shared( PaymentRepository::class, static fn() => new PaymentRepository() );
	}

	public static function eventRepository(): EventRepository {
		return self::shared( EventRepository::class, static fn() => new EventRepository() );
	}

	public static function tokenRepository(): TokenRepository {
		return self::shared( TokenRepository::class, static fn() => new TokenRepository() );
	}

	/* Services ------------------------------------------------------------- */

	public static function coupons(): CouponService {
		return self::shared( CouponService::class, static fn() => new CouponService( self::couponRepository(), self::clock() ) );
	}

	public static function pricing(): PricingService {
		return self::shared(
			PricingService::class,
			static fn() => new PricingService( self::priceRepository(), self::units(), self::coupons(), self::policy(), self::clock() )
		);
	}

	public static function availability(): AvailabilityService {
		return self::shared(
			AvailabilityService::class,
			static fn() => new AvailabilityService( self::nightsRepository(), self::blockRepository(), self::units() )
		);
	}

	public static function availabilityCache(): AvailabilityCache {
		return self::shared(
			AvailabilityCache::class,
			static fn() => new AvailabilityCache( self::availability(), self::priceRepository(), self::clock() )
		);
	}

	public static function search(): SearchService {
		return self::shared(
			SearchService::class,
			static fn() => new SearchService( self::units(), self::availability(), self::pricing(), self::priceRepository(), self::clock() )
		);
	}

	public static function guests(): GuestService {
		return self::shared(
			GuestService::class,
			static fn() => new GuestService(
				self::guestRepository(),
				self::bookingRepository(),
				self::eventRepository(),
				self::couponRedemptionRepository(),
				self::couponRepository(),
				self::paymentRepository()
			)
		);
	}

	public static function references(): ReferenceGenerator {
		return self::shared( ReferenceGenerator::class, static fn() => new ReferenceGenerator( self::bookingRepository() ) );
	}

	public static function bookings(): BookingService {
		return self::shared(
			BookingService::class,
			static fn() => new BookingService(
				self::bookingRepository(),
				self::availability(),
				self::guests(),
				self::references(),
				self::units(),
				self::eventRepository(),
				self::couponRepository(),
				self::couponRedemptionRepository(),
				self::coupons(),
				self::paymentRepository(),
				self::pricing(),
				self::policy(),
				self::clock(),
				self::tokenRepository()
			)
		);
	}

	public static function blocks(): BlockService {
		return self::shared( BlockService::class, static fn() => new BlockService( self::blockRepository(), self::availability(), self::units(), self::eventRepository() ) );
	}

	public static function dashboard(): DashboardService {
		return self::shared(
			DashboardService::class,
			static fn() => new DashboardService( self::bookingRepository(), self::paymentRepository(), self::nightsRepository(), self::eventRepository(), self::units(), self::clock(), self::icalSync() )
		);
	}

	public static function prices(): PriceService {
		return self::shared( PriceService::class, static fn() => new PriceService( self::priceRepository(), self::units(), self::eventRepository() ) );
	}

	public static function holdReaper(): HoldReaper {
		return self::shared( HoldReaper::class, static fn() => new HoldReaper( self::bookingRepository(), self::bookings(), self::clock() ) );
	}

	public static function cancellation(): CancellationService {
		return self::shared(
			CancellationService::class,
			static fn() => new CancellationService(
				self::bookingRepository(),
				self::paymentRepository(),
				self::bookings(),
				self::eventRepository(),
				self::refunds(),
				self::clock()
			)
		);
	}

	/* Emails --------------------------------------------------------------- */

	public static function emailTemplates(): TemplateRepository {
		return self::shared( TemplateRepository::class, static fn() => new TemplateRepository( self::emailTemplateRepository() ) );
	}

	public static function mailer(): Mailer {
		return self::shared( Mailer::class, static fn() => new Mailer( self::emailTemplates(), self::eventRepository() ) );
	}

	public static function emailScheduler(): EmailScheduler {
		return self::shared( EmailScheduler::class, static fn() => new EmailScheduler( self::bookingRepository(), self::mailer(), self::clock() ) );
	}

	/* Payments ------------------------------------------------------------- */

	/**
	 * Stripe when the wp-config.php keys are configured (StripeConfig), else null
	 * (checkout answers payments_unavailable). Resolved once per Container::reset().
	 */
	public static function paymentGateway(): ?PaymentGateway {
		if ( false === self::$gateway ) {
			$default = StripeConfig::configured() ? new StripePaymentGateway( StripeConfig::secretKey() ) : null;

			/**
			 * Payment gateway used for checkout, webhooks, balances and refunds.
			 * Tests swap in a fake; null disables online payments.
			 *
			 * @param PaymentGateway|null $gateway
			 */
			$gateway       = apply_filters( 'mbk_payment_gateway', $default );
			self::$gateway = $gateway instanceof PaymentGateway ? $gateway : null;
		}

		return self::$gateway;
	}

	public static function paymentLedger(): PaymentLedger {
		return self::shared(
			PaymentLedger::class,
			static fn() => new WpPaymentLedger( self::bookingRepository(), self::paymentRepository(), self::eventRepository(), self::bookings(), self::units() )
		);
	}

	public static function refunds(): RefundService {
		return self::shared( RefundService::class, static fn() => new RefundService( self::paymentLedger(), self::paymentGateway(), self::clock() ) );
	}

	public static function reconciler(): PaymentReconciler {
		return self::shared( PaymentReconciler::class, static fn() => new PaymentReconciler( self::paymentLedger(), self::paymentGateway(), self::refunds(), self::clock(), StripeConfig::siteId() ) );
	}

	public static function balanceCharger(): BalanceCharger {
		return self::shared( BalanceCharger::class, static fn() => new BalanceCharger( self::paymentLedger(), self::paymentGateway(), self::reconciler(), self::clock(), StripeConfig::siteId() ) );
	}

	public static function webhookHandler(): WebhookHandler {
		return self::shared( WebhookHandler::class, static fn() => new WebhookHandler( self::paymentLedger(), self::paymentGateway(), self::reconciler(), self::refunds() ) );
	}

	public static function checkout(): CheckoutService {
		return self::shared(
			CheckoutService::class,
			static fn() => new CheckoutService(
				self::bookings(),
				self::bookingRepository(),
				self::pricing(),
				self::guestRepository(),
				self::paymentRepository(),
				self::units(),
				self::paymentGateway(),
				self::reconciler(),
				self::clock(),
				StripeConfig::siteId()
			)
		);
	}

	/* iCal sync (R3) ------------------------------------------------------- */

	public static function icalFeeds(): FeedRepository {
		return self::shared( FeedRepository::class, static fn() => new FeedRepository() );
	}

	public static function icalImporter(): IcalImporter {
		return self::shared( IcalImporter::class, static fn() => new IcalImporter( self::icalFeeds(), self::clock() ) );
	}

	public static function icalExporter(): IcalExporter {
		return self::shared( IcalExporter::class, static fn() => new IcalExporter( self::bookingRepository(), self::blockRepository(), self::icalFeeds(), self::units(), self::clock() ) );
	}

	public static function icalConflicts(): ConflictDetector {
		return self::shared( ConflictDetector::class, static fn() => new ConflictDetector( self::nightsRepository(), self::bookingRepository(), self::eventRepository(), self::mailer(), self::clock() ) );
	}

	public static function icalSync(): IcalSync {
		return self::shared(
			IcalSync::class,
			static fn() => new IcalSync( self::icalFeeds(), self::icalImporter(), self::icalConflicts(), self::eventRepository(), self::mailer(), self::units(), self::clock() )
		);
	}

	/* Guest accounts (R2) -------------------------------------------------- */

	public static function membership(): Membership {
		return self::shared(
			Membership::class,
			static fn() => new Membership( new WpMembershipStore( self::bookingRepository(), self::couponRepository(), self::eventRepository() ), self::clock() )
		);
	}

	public static function accounts(): AccountService {
		return self::shared(
			AccountService::class,
			static fn() => new AccountService(
				self::guests(),
				self::guestRepository(),
				self::bookingRepository(),
				self::paymentRepository(),
				self::couponRepository(),
				self::couponRedemptionRepository(),
				self::eventRepository(),
				self::bookings(),
				self::membership(),
				self::mailer(),
				self::units(),
				self::clock()
			)
		);
	}

	public static function verification(): Verification {
		return self::shared( Verification::class, static fn() => new Verification( self::accounts(), self::eventRepository() ) );
	}

	public static function registration(): Registration {
		return self::shared( Registration::class, static fn() => new Registration( self::verification() ) );
	}

	public static function auth(): Auth {
		return self::shared( Auth::class, static fn() => new Auth() );
	}

	public static function passwordReset(): PasswordReset {
		return self::shared( PasswordReset::class, static fn() => new PasswordReset( self::accounts() ) );
	}

	public static function cardUpdate(): CardUpdate {
		return self::shared(
			CardUpdate::class,
			static fn() => new CardUpdate( self::paymentGateway(), self::accounts(), self::guestRepository(), self::bookingRepository(), self::paymentRepository(), self::eventRepository() )
		);
	}

	public static function cancelRequests(): CancelRequest {
		return self::shared( CancelRequest::class, static fn() => new CancelRequest( self::accounts(), self::eventRepository(), self::mailer(), self::clock() ) );
	}
}
