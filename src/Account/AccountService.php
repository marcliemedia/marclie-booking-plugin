<?php
/**
 * Guest accounts (R2): the WordPress user of a guest, its link to the CRM
 * guest and bookings, and the data of /moj-racun/ (overview, bookings,
 * payments, coupons, membership, profile). A user only ever sees bookings
 * whose `user_id` is their own.
 *
 * User meta: `mbk_email_verified_at` (UTC DATETIME), `mbk_tier`
 * (clan|srebrni|zlatni), `mbk_tier_since` (Y-m-d), `mbk_marketing_consent_at`,
 * `mbk_lang` (hr|en), `mbk_card` (brand/last4/exp of the card saved through
 * the account). The guest link is `mbk_guests.user_id`; bookings carry
 * `mbk_bookings.user_id`.
 */

namespace Marclie\Booking\Account;

use Marclie\Booking\Container;
use Marclie\Booking\Domain\Clock;
use Marclie\Booking\Domain\Exception\ValidationException;
use Marclie\Booking\Email\Context;
use Marclie\Booking\Email\Mailer;
use Marclie\Booking\Frontend\Renderer;
use Marclie\Booking\Guests\GuestService;
use Marclie\Booking\I18n\Countries;
use Marclie\Booking\I18n\LanguageAdapter;
use Marclie\Booking\Repository\BookingRepository;
use Marclie\Booking\Repository\CouponRedemptionRepository;
use Marclie\Booking\Repository\CouponRepository;
use Marclie\Booking\Repository\Db;
use Marclie\Booking\Repository\EventRepository;
use Marclie\Booking\Repository\GuestRepository;
use Marclie\Booking\Repository\PaymentRepository;
use Marclie\Booking\Repository\TokenRepository;
use Marclie\Booking\Service\BookingService;
use Marclie\Booking\Service\SearchService;
use Marclie\Booking\Settings;
use Marclie\Booking\Units\UnitProvider;

defined( 'ABSPATH' ) || exit;

final class AccountService {

	public const META_VERIFIED = 'mbk_email_verified_at';

	/** The confirmed address itself (normalized): verification holds only while it equals user_email. */
	public const META_VERIFIED_EMAIL = 'mbk_email_verified';

	public const META_TIER       = 'mbk_tier';
	public const META_TIER_SINCE = 'mbk_tier_since';
	public const META_CONSENT    = 'mbk_marketing_consent_at';
	public const META_LANG       = 'mbk_lang';
	public const META_CARD       = 'mbk_card';

	/** Plain manage tokens reused per booking (user meta: booking ID => [token, expires_at UTC]). */
	public const META_MANAGE_TOKENS = 'mbk_manage_tokens';

	public const ACTOR_PREFIX = 'account:';

	/** Account "manage" links (hvala page) are valid this long. */
	public const MANAGE_LINK_HOURS = 24;

	/** Bookings of the "upcoming" list (still running). */
	private const UPCOMING = array( 'pending_payment', 'confirmed', 'paid', 'balance_failed' );

	/** Bookings a guest may ask to cancel (before arrival). */
	private const CANCELLABLE = array( 'confirmed', 'paid', 'balance_failed' );

	/** Fallback paths of the account pages before `wp mbk pages --create` ran. */
	private const PATHS = array(
		'prijava'              => '/prijava/',
		'registracija'         => '/registracija/',
		'moj_racun'            => '/moj-racun/',
		'zaboravljena_lozinka' => '/moj-racun/zaboravljena-lozinka/',
	);

	public function __construct(
		private GuestService $guests,
		private GuestRepository $guestRepo,
		private BookingRepository $bookings,
		private PaymentRepository $payments,
		private CouponRepository $coupons,
		private CouponRedemptionRepository $redemptions,
		private EventRepository $events,
		private BookingService $bookingService,
		private Membership $membership,
		private Mailer $mailer,
		private UnitProvider $units,
		private Clock $clock
	) {}

	/* Helpers -------------------------------------------------------------- */

	/** URL of an account page (page-map key) in `$lang`. */
	public static function pageUrl( string $key, string $lang ): string {
		$url = LanguageAdapter::pageUrl( $key, $lang );
		if ( null === $url ) {
			$url = home_url( self::PATHS[ $key ] ?? '/' . $key . '/' );
			if ( 'en' === $lang ) {
				$url = add_query_arg( 'lang', 'en', $url );
			}
		}

		return $url;
	}

	/** Language of the account's emails: `mbk_lang`, else Croatian. */
	public static function userLang( int $userId ): string {
		return LanguageAdapter::normalize( (string) get_user_meta( $userId, self::META_LANG, true ) ) ?? LanguageAdapter::DEFAULT;
	}

	/** Translated tier name (current text-domain language). */
	public static function tierLabel( string $tier ): string {
		return match ( $tier ) {
			Membership::SREBRNI => __( 'Silver', 'marclie-booking' ),
			Membership::ZLATNI  => __( 'Gold', 'marclie-booking' ),
			default             => __( 'Member', 'marclie-booking' ),
		};
	}

	/**
	 * The address the user confirmed, while it is still their `user_email`
	 * (an email change — however it happened — voids the verification).
	 */
	public static function verifiedEmailOf( int $userId ): ?string {
		$user = get_userdata( $userId );
		if ( ! $user instanceof \WP_User ) {
			return null;
		}
		$verified = (string) get_user_meta( $userId, self::META_VERIFIED_EMAIL, true );
		$current  = GuestRepository::normalizeEmail( (string) $user->user_email );

		return '' !== $verified && '' !== $current && hash_equals( $verified, $current ) ? $current : null;
	}

	public static function isVerified( \WP_User $user ): bool {
		return null !== self::verifiedEmailOf( $user->ID );
	}

	/** Records that the user proved access to their current address. */
	public static function markVerified( \WP_User $user ): void {
		update_user_meta( $user->ID, self::META_VERIFIED, Db::nowUtc() );
		update_user_meta( $user->ID, self::META_VERIFIED_EMAIL, GuestRepository::normalizeEmail( (string) $user->user_email ) );
	}

	/** `profile_update`: a changed email address voids the verification. */
	public static function onProfileUpdate( $userId, $oldUser = null ): void {
		$user = get_userdata( (int) $userId );
		if ( ! $user instanceof \WP_User || ! $oldUser instanceof \WP_User ) {
			return;
		}
		if ( GuestRepository::normalizeEmail( (string) $oldUser->user_email ) !== GuestRepository::normalizeEmail( (string) $user->user_email ) ) {
			delete_user_meta( $user->ID, self::META_VERIFIED );
			delete_user_meta( $user->ID, self::META_VERIFIED_EMAIL );
		}
	}

	/** Stored tier of the user (Član when none is stored yet). */
	public static function tierOf( int $userId ): string {
		$tier = (string) get_user_meta( $userId, self::META_TIER, true );

		return Membership::isTier( $tier ) ? $tier : Membership::CLAN;
	}

	/** Event actor of an account action. */
	public static function actor( int $userId ): string {
		return self::ACTOR_PREFIX . $userId;
	}

	/**
	 * `window.mbkFront.account` (Assets::config()).
	 *
	 * @return array{loggedIn: bool, nonce: string, user: array{first_name: string, email: string, tier: string}|null}
	 */
	public function frontConfig(): array {
		$user = wp_get_current_user();
		$in   = Roles::hasGuestRole( $user );

		return array(
			'loggedIn' => $in,
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'user'     => $in
				? array(
					'first_name' => (string) $user->first_name,
					'email'      => (string) $user->user_email,
					'tier'       => self::tierOf( $user->ID ),
				)
				: null,
		);
	}

	/**
	 * @return array{id: int, first_name: string, last_name: string, email: string, tier: string, email_verified: bool}
	 */
	public function summary( \WP_User $user ): array {
		return array(
			'id'             => $user->ID,
			'first_name'     => (string) $user->first_name,
			'last_name'      => (string) $user->last_name,
			'email'          => (string) $user->user_email,
			'tier'           => self::tierOf( $user->ID ),
			'email_verified' => self::isVerified( $user ),
		);
	}

	/**
	 * The CRM guest of the user: the guest of the verified address, and only
	 * while it is linked to this user (null for unverified accounts).
	 *
	 * @return array<string, mixed>|null
	 */
	public function guestFor( \WP_User $user ): ?array {
		$email = self::verifiedEmailOf( $user->ID );
		$guest = null === $email ? null : $this->guestRepo->findByEmail( $email );

		return null !== $guest && (int) ( $guest['user_id'] ?? 0 ) === $user->ID ? $guest : null;
	}

	/**
	 * Verification form (`/moj-racun/?verify=` + the password chosen at
	 * registration): creates and signs in the account. See Verification::verify().
	 *
	 * @return \WP_User|\WP_Error
	 */
	public function verify( string $token, string $password ) {
		return Container::verification()->verify( $token, $password );
	}

	/**
	 * Links a verified user to the guest of their email (created when missing)
	 * and to that guest's bookings without a user. Returns the guest ID.
	 */
	public function link( \WP_User $user, string $lang, bool $newsletter = false ): int {
		$email = self::verifiedEmailOf( $user->ID );
		if ( null === $email ) {
			throw new \LogicException( 'Only a verified account can be linked to its guest.' );
		}
		$guestId = $this->guests->upsertFromBooking(
			array(
				'email'             => $email,
				'first_name'        => (string) $user->first_name,
				'last_name'         => (string) $user->last_name,
				'marketing_consent' => $newsletter,
				'user_id'           => $user->ID,
			),
			$lang,
			true
		);
		$guest = $this->guestRepo->find( $guestId );
		if ( null !== $guest && (int) ( $guest['user_id'] ?? 0 ) !== $user->ID ) {
			Db::check( $this->guestRepo->update( $guestId, array( 'user_id' => $user->ID ) ), 'Linking guest' );
		}

		$linked = $this->bookings->linkUser( $user->ID, $guestId, $email );
		Db::check( false !== $linked, 'Linking bookings' );
		$this->events->log( 'account_linked', self::actor( $user->ID ), null, $guestId, array( 'bookings' => (int) $linked ) );

		return $guestId;
	}

	/**
	 * User ID for an online checkout: the signed-in guest account when the
	 * booking email is its verified address, else null.
	 */
	public function checkoutUserId( string $email ): ?int {
		$user     = wp_get_current_user();
		$verified = $user->exists() ? self::verifiedEmailOf( $user->ID ) : null;

		return null !== $verified && $verified === GuestRepository::normalizeEmail( $email ) ? $user->ID : null;
	}

	/**
	 * Membership start of a freshly verified account: tier, welcome coupon and
	 * the welcome email (plus a tier email when earlier stays already count).
	 */
	public function startMembership( \WP_User $user, int $guestId ): void {
		$result = $this->membership->start( $user->ID, $guestId );
		$lang   = self::userLang( $user->ID );
		foreach ( $result['coupons'] as $coupon ) {
			$welcome = 'welcome' === Membership::couponKind( (string) $coupon['code'] );
			$this->sendMemberEmail( $welcome ? 'account_welcome' : 'account_tier_up', $user, $guestId, $welcome ? Membership::CLAN : $result['tier'], $coupon, $lang );
		}
	}

	/**
	 * Cron `mbk_daily`: tiers of every linked, verified account and the
	 * member coupons due (idempotent), then expired account manage links.
	 */
	public function runMembership(): void {
		$after = 0;
		do {
			$batch = $this->guestRepo->linkedToUsers( $after, 200 );
			foreach ( $batch as $pair ) {
				$after = $pair['guest_id'];
				$user  = get_userdata( $pair['user_id'] );
				// Only the guest of the address the user verified (never a guest linked by another email).
				if ( ! $user instanceof \WP_User || self::verifiedEmailOf( $user->ID ) !== $pair['email'] ) {
					continue;
				}
				try {
					$result = $this->membership->sync( $user->ID, $pair['guest_id'] );
					foreach ( $result['coupons'] as $coupon ) {
						$this->sendMemberEmail( 'account_tier_up', $user, $pair['guest_id'], $result['tier'], $coupon, self::userLang( $user->ID ) );
					}
				} catch ( \Throwable $e ) {
					error_log( '[marclie-booking] membership user ' . $user->ID . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
		} while ( count( $batch ) === 200 );

		Container::tokenRepository()->deleteExpired( TokenRepository::MANAGE, Db::utc( $this->clock->now() ) );
	}

	/** @param array<string, mixed> $coupon */
	private function sendMemberEmail( string $code, \WP_User $user, int $guestId, string $tier, array $coupon, string $lang ): void {
		$this->mailer->sendAccount(
			$code,
			(string) $user->user_email,
			$lang,
			Context::accountFacts(
				array(
					'first_name' => (string) $user->first_name,
					'last_name'  => (string) $user->last_name,
					'email'      => (string) $user->user_email,
				),
				array(
					'tier'   => $tier,
					'coupon' => $coupon,
				),
				array( 'account_link' => self::pageUrl( 'moj_racun', $lang ) )
			),
			$guestId,
			WpMembershipStore::ACTOR
		);
	}

	/* /moj-racun/ data ----------------------------------------------------- */

	/** @return array<string, mixed> */
	public function overview( \WP_User $user, string $lang ): array {
		$bookings = $this->bookings( $user, $lang );
		$coupons  = array_values( array_filter( $this->coupons( $user ), static fn( array $c ): bool => ! $c['used'] && ! $c['expired'] ) );
		$expiries = array_values( array_filter( array_column( $coupons, 'valid_to' ) ) );
		sort( $expiries );

		return array(
			'user'                 => $this->summary( $user ),
			'next_stay'            => $bookings['upcoming'][0] ?? null,
			'upcoming_count'       => count( $bookings['upcoming'] ),
			'membership'           => $this->membership( $user ),
			'coupons_available'    => count( $coupons ),
			'coupon_next_expiry'   => $expiries[0] ?? null,
		);
	}

	/**
	 * Upcoming bookings (soonest first) and past/cancelled ones (newest first).
	 * Checkout holds and expired bookings are left out.
	 *
	 * @return array{upcoming: list<array<string, mixed>>, past: list<array<string, mixed>>}
	 */
	public function bookings( \WP_User $user, string $lang ): array {
		$today    = $this->clock->today()->format( 'Y-m-d' );
		$upcoming = array();
		$past     = array();
		$rows     = $this->bookings->forUser( $user->ID );
		$requests = $this->cancelRequests( array_map( static fn( array $b ): int => (int) $b['id'], $rows ) );

		foreach ( $rows as $booking ) {
			$status = (string) $booking['status'];
			if ( 'expired' === $status || ( 'pending_payment' === $status && null !== $booking['hold_expires_at'] ) ) {
				continue;
			}
			$isUpcoming = in_array( $status, self::UPCOMING, true ) && (string) $booking['departure'] > $today;
			$item       = $this->bookingItem( $user->ID, $booking, $requests[ (int) $booking['id'] ] ?? null, $isUpcoming, $today, $lang );
			if ( $isUpcoming ) {
				$upcoming[] = $item;
			} else {
				$past[] = $item;
			}
		}
		usort( $upcoming, static fn( array $a, array $b ): int => strcmp( $a['arrival'], $b['arrival'] ) );

		return array(
			'upcoming' => $upcoming,
			'past'     => $past,
		);
	}

	/**
	 * The user's own booking by reference, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public function ownBooking( \WP_User $user, string $ref ): ?array {
		$booking = $this->bookings->findByRef( $ref );

		return null !== $booking && (int) ( $booking['user_id'] ?? 0 ) === $user->ID ? $booking : null;
	}

	/**
	 * `cancel_requested` events per booking ID (latest).
	 *
	 * @param list<int> $bookingIds
	 * @return array<int, array<string, mixed>>
	 */
	private function cancelRequests( array $bookingIds ): array {
		$out = array();
		foreach ( $bookingIds as $id ) {
			foreach ( $this->events->forBooking( $id ) as $event ) {
				if ( 'cancel_requested' === $event['type'] ) {
					$out[ $id ] = $event;
				}
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed>      $booking
	 * @param array<string, mixed>|null $request Latest cancel_requested event.
	 * @return array<string, mixed>
	 */
	private function bookingItem( int $userId, array $booking, ?array $request, bool $upcoming, string $today, string $lang ): array {
		$id       = (int) $booking['id'];
		$payments = $this->payments->forBooking( $id );
		$paid     = PaymentRepository::net( $payments );
		$total    = (int) $booking['total_cents'];
		$status   = (string) $booking['status'];
		$unit     = $this->units->get( (int) $booking['unit_id'] );
		$thumb    = null !== $unit && $unit->thumbnailId ? wp_get_attachment_image_url( $unit->thumbnailId, 'medium_large' ) : false;
		$house    = null !== $unit && null !== $unit->houseSlug ? get_term_by( 'slug', $unit->houseSlug, 'kuca' ) : false;
		$refunded = 0;
		foreach ( $payments as $row ) {
			if ( 'refund' === $row['kind'] && in_array( $row['status'], PaymentRepository::REFUND_COUNTED, true ) ) {
				$refunded += (int) $row['amount_cents'];
			}
		}
		$running = in_array( $status, self::UPCOMING, true );

		$manage = null;
		if ( $upcoming ) {
			$manage = add_query_arg(
				array(
					'ref' => rawurlencode( (string) $booking['ref'] ),
					't'   => $this->manageToken( $userId, $id ),
				),
				Renderer::pageUrl( 'hvala', $lang )
			);
		}

		return array(
			'ref'                 => (string) $booking['ref'],
			'status'              => $status,
			'arrival'             => (string) $booking['arrival'],
			'departure'           => (string) $booking['departure'],
			'nights'              => (int) $booking['nights'],
			'adults'              => (int) $booking['adults'],
			'children'            => (int) $booking['children'],
			'infants'             => (int) $booking['infants'],
			'total_cents'         => $total,
			'paid_cents'          => $paid,
			'balance_cents'       => $running ? max( 0, $total - $paid ) : 0,
			'balance_due'         => $running && $total > $paid ? self::nullable( $booking['balance_due_on'] ) : null,
			'refunded_cents'      => $refunded,
			'coupon_code'         => self::nullable( $booking['coupon_code'] ),
			'free_cancel_until'   => $running && ! empty( $booking['free_cancel_until'] ) && (string) $booking['free_cancel_until'] >= $today ? (string) $booking['free_cancel_until'] : null,
			'cancel_requested'    => null !== $request,
			'cancel_requested_at' => null !== $request ? self::iso( (string) $request['created_at'] ) : null,
			'unit'                => array(
				'id'        => (int) $booking['unit_id'],
				'title'     => null === $unit ? '' : $unit->title,
				'house'     => $house instanceof \WP_Term ? $house->name : '',
				'permalink' => null === $unit ? '' : SearchService::permalink( $unit, $lang ),
				'thumb'     => $thumb ? (string) $thumb : null,
			),
			'actions'             => array(
				'rebook_url'         => null === $unit ? null : Renderer::bookingUrl( $unit, null, (int) $booking['adults'], (int) $booking['children'], $lang, (int) $booking['infants'] ),
				'manage_url'         => $manage,
				'can_request_cancel' => in_array( $status, self::CANCELLABLE, true ) && (string) $booking['arrival'] > $today && null === $request,
			),
		);
	}

	/**
	 * Manage token of a booking for its owner: the stored one while it has at
	 * least an hour left, else a new 24-hour token (one per booking, not per view).
	 */
	private function manageToken( int $userId, int $bookingId ): string {
		$now    = $this->clock->now();
		$stored = get_user_meta( $userId, self::META_MANAGE_TOKENS, true );
		$stored = is_array( $stored ) ? $stored : array();
		$entry  = $stored[ $bookingId ] ?? null;
		if ( is_array( $entry ) && ! empty( $entry['t'] ) && (string) ( $entry['exp'] ?? '' ) > Db::utc( $now->modify( '+1 hour' ) ) ) {
			return (string) $entry['t'];
		}

		$expires = $now->modify( sprintf( '+%d hours', self::MANAGE_LINK_HOURS ) );
		$token   = $this->bookingService->issueToken( $bookingId, TokenRepository::MANAGE, $expires );
		$nowUtc  = Db::utc( $now );
		$stored  = array_filter( $stored, static fn( $e ): bool => is_array( $e ) && (string) ( $e['exp'] ?? '' ) > $nowUtc );
		$stored[ $bookingId ] = array(
			't'   => $token,
			'exp' => Db::utc( $expires ),
		);
		update_user_meta( $userId, self::META_MANAGE_TOKENS, $stored );

		return $token;
	}

	/**
	 * Payment rows of the user's bookings (newest first) with the balance
	 * still to be charged as a `scheduled` row, and the saved card.
	 *
	 * @return array{items: list<array<string, mixed>>, card: array{brand: string, last4: string, exp_month: ?int, exp_year: ?int}|null, card_update_available: bool}
	 */
	public function payments( \WP_User $user ): array {
		$today = $this->clock->today()->format( 'Y-m-d' );
		$items = array();
		$card  = null;
		foreach ( $this->bookings->forUser( $user->ID ) as $booking ) {
			$status = (string) $booking['status'];
			if ( 'expired' === $status || ( 'pending_payment' === $status && null !== $booking['hold_expires_at'] ) ) {
				continue;
			}
			$unit      = $this->units->get( (int) $booking['unit_id'] );
			$title     = null === $unit ? '' : $unit->title;
			$rows      = $this->payments->forBooking( (int) $booking['id'] );
			$scheduled = false;
			foreach ( $rows as $row ) {
				$isCard = PaymentRepository::METHOD_STRIPE === $row['method'] && '' !== (string) $row['card_last4'];
				if ( 'scheduled' === $row['status'] ) {
					$scheduled = true;
				}
				$items[] = array(
					'id'           => (int) $row['id'],
					'date'         => self::siteDate( (string) $row['created_at'] ),
					'ref'          => (string) $booking['ref'],
					'unit_title'   => $title,
					'kind'         => (string) $row['kind'],
					'method'       => (string) $row['method'],
					'amount_cents' => (int) $row['amount_cents'],
					'status'       => self::paymentStatus( $row ),
					'card'         => $isCard
						? array(
							'brand' => (string) $row['card_brand'],
							'last4' => (string) $row['card_last4'],
						)
						: null,
				);
				if ( $isCard && 'refund' !== $row['kind'] && 'succeeded' === $row['status'] && ! empty( $booking['stripe_payment_method_id'] ) ) {
					$card = array(
						'brand'     => (string) $row['card_brand'],
						'last4'     => (string) $row['card_last4'],
						'exp_month' => null,
						'exp_year'  => null,
					);
				}
			}

			$outstanding = (int) $booking['total_cents'] - PaymentRepository::net( $rows );
			if ( ! $scheduled && 'confirmed' === $status && $outstanding > 0 && ! empty( $booking['balance_due_on'] ) && (string) $booking['balance_due_on'] >= $today ) {
				$items[] = array(
					'id'           => null,
					'date'         => (string) $booking['balance_due_on'],
					'ref'          => (string) $booking['ref'],
					'unit_title'   => $title,
					'kind'         => 'balance',
					'method'       => PaymentRepository::METHOD_STRIPE,
					'amount_cents' => $outstanding,
					'status'       => 'scheduled',
					'card'         => null,
				);
			}
		}
		usort( $items, static fn( array $a, array $b ): int => strcmp( $b['date'], $a['date'] ) ?: ( (int) $b['id'] <=> (int) $a['id'] ) );

		$saved = get_user_meta( $user->ID, self::META_CARD, true );
		if ( is_array( $saved ) && ! empty( $saved['last4'] ) ) {
			$card = array(
				'brand'     => (string) ( $saved['brand'] ?? '' ),
				'last4'     => (string) $saved['last4'],
				'exp_month' => isset( $saved['exp_month'] ) ? (int) $saved['exp_month'] : null,
				'exp_year'  => isset( $saved['exp_year'] ) ? (int) $saved['exp_year'] : null,
			);
		}
		foreach ( $items as &$item ) {
			if ( 'scheduled' === $item['status'] && null !== $card ) {
				$item['card'] = array(
					'brand' => $card['brand'],
					'last4' => $card['last4'],
				);
			}
		}
		unset( $item );

		return array(
			'items'                 => $items,
			'card'                  => $card,
			'card_update_available' => null !== Container::paymentGateway(),
		);
	}

	/** @param array<string, mixed> $row */
	private static function paymentStatus( array $row ): string {
		$status = (string) $row['status'];
		if ( 'refund' === $row['kind'] ) {
			return 'succeeded' === $status ? 'refunded' : ( 'failed' === $status ? 'failed' : 'pending' );
		}

		return match ( $status ) {
			'succeeded' => 'paid',
			'refunded'  => 'refunded',
			'scheduled' => 'scheduled',
			'failed'    => 'failed',
			default     => 'pending',
		};
	}

	/**
	 * The user's own coupons (assigned to or redeemed by their guest) and the
	 * public direct-booking coupon. One coupon per booking.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function coupons( \WP_User $user ): array {
		$today   = $this->clock->today()->format( 'Y-m-d' );
		$guest   = $this->guestFor( $user );
		$guestId = null === $guest ? 0 : (int) $guest['id'];
		$rows    = $guestId ? $this->coupons->rowsForGuest( $guestId ) : array();
		$direct  = $this->coupons->findRowByCode( Settings::defaultCoupon() );
		if ( null !== $direct && null === $direct['guest_id'] && ! in_array( (int) $direct['id'], array_map( 'intval', array_column( $rows, 'id' ) ), true ) ) {
			$rows[] = $direct;
		}

		$used = array();
		foreach ( $guestId ? $this->redemptions->forGuest( $guestId ) : array() as $redemption ) {
			$used[ (int) $redemption['coupon_id'] ] ??= $redemption;
		}

		$items = array();
		foreach ( $rows as $row ) {
			$coupon     = CouponRepository::toCoupon( $row );
			$redemption = $used[ $coupon->id ] ?? null;
			$booking    = null !== $redemption ? $this->bookings->find( (int) $redemption['booking_id'] ) : null;
			$singleUse  = 1 === $coupon->usageLimit || 1 === $coupon->perGuestLimit;
			$isUsed     = null !== $redemption && ( $singleUse || null !== $coupon->guestId );
			$exhausted  = null !== $coupon->usageLimit && $coupon->usedCount >= $coupon->usageLimit;
			$expired    = ! $coupon->active || ( null !== $coupon->bookTo && $coupon->bookTo < $today ) || ( null !== $coupon->stayTo && $coupon->stayTo < $today ) || ( $exhausted && ! $isUsed );
			$kind       = Membership::couponKind( $coupon->code );
			if ( null === $kind ) {
				$kind = strtoupper( $coupon->code ) === strtoupper( Settings::defaultCoupon() ) ? 'direct' : 'other';
			} elseif ( Membership::SREBRNI === $kind ) {
				$kind = 'silver';
			} elseif ( Membership::ZLATNI === $kind ) {
				$kind = 'gold';
			}
			$unit = null !== $booking ? $this->units->get( (int) $booking['unit_id'] ) : null;

			$items[] = array(
				'code'            => $coupon->code,
				'kind'            => $kind,
				'type'            => $coupon->type,
				'amount'          => $coupon->amount,
				'min_nights'      => $coupon->minNights,
				'valid_to'        => $coupon->bookTo,
				'single_use'      => $singleUse,
				'used'            => $isUsed,
				'used_at'         => null !== $redemption ? self::siteDate( (string) $redemption['created_at'] ) : null,
				'used_ref'        => null !== $booking ? (string) $booking['ref'] : null,
				'used_unit_title' => null !== $unit ? $unit->title : null,
				'expired'         => $expired,
			);
		}
		// Usable first (soonest expiry first), then used/expired.
		usort(
			$items,
			static fn( array $a, array $b ): int => ( (int) ( $a['used'] || $a['expired'] ) <=> (int) ( $b['used'] || $b['expired'] ) )
				?: strcmp( (string) ( $a['valid_to'] ?? '9999' ), (string) ( $b['valid_to'] ?? '9999' ) )
		);

		return $items;
	}

	/** @return array<string, mixed> */
	public function membership( \WP_User $user ): array {
		$guest = $this->guestFor( $user );
		$stays = null === $guest ? 0 : $this->bookings->countCompletedStays( (int) $guest['id'], $this->clock->today()->format( 'Y-m-d' ) );
		$tier  = self::tierOf( $user->ID );
		$since = (string) get_user_meta( $user->ID, self::META_TIER_SINCE, true );
		$tiers = array();
		foreach ( Membership::TIERS as $key => $min ) {
			$tiers[] = array(
				'key'       => $key,
				'min_stays' => $min,
				'perks'     => Membership::perks( $key ),
			);
		}

		return array(
			'tier'            => $tier,
			'completed_stays' => $stays,
			'next_tier'       => Membership::nextTier( $tier ),
			'stays_to_next'   => Membership::staysToNext( $tier, $stays ),
			'member_since'    => self::siteDate( (string) $user->user_registered ),
			'tier_since'      => '' !== $since ? $since : null,
			'perks'           => Membership::perks( $tier ),
			'tiers'           => $tiers,
		);
	}

	/* Profile -------------------------------------------------------------- */

	/** @return array{first_name: string, last_name: string, email: string, phone: string, country: string, lang: string, newsletter: bool} */
	public function profile( \WP_User $user ): array {
		$guest = $this->guestFor( $user );

		return array(
			'first_name' => (string) $user->first_name,
			'last_name'  => (string) $user->last_name,
			'email'      => (string) $user->user_email,
			'phone'      => (string) ( $guest['phone'] ?? '' ),
			'country'    => (string) ( $guest['country'] ?? '' ),
			'lang'       => self::userLang( $user->ID ),
			'newsletter' => '' !== (string) get_user_meta( $user->ID, self::META_CONSENT, true ),
		);
	}

	/**
	 * @param array<string, mixed> $patch first_name, last_name, phone, country, lang, newsletter.
	 * @return array<string, mixed> The updated profile.
	 * @throws ValidationException
	 */
	public function updateProfile( \WP_User $user, array $patch ): array {
		$text   = static fn( string $key ): string => trim( sanitize_text_field( (string) $patch[ $key ] ) );
		$errors = array();
		$row    = array();

		foreach ( array( 'first_name', 'last_name' ) as $field ) {
			if ( array_key_exists( $field, $patch ) ) {
				$row[ $field ] = $text( $field );
				if ( '' === $row[ $field ] || mb_strlen( $row[ $field ] ) > 100 ) {
					$errors[ $field ] = 'first_name' === $field ? __( 'Enter your first name.', 'marclie-booking' ) : __( 'Enter your last name.', 'marclie-booking' );
				}
			}
		}
		if ( array_key_exists( 'phone', $patch ) ) {
			$row['phone'] = $text( 'phone' );
			if ( '' !== $row['phone'] && ! preg_match( '/^\+?[0-9][0-9 ()\/.-]{5,38}$/', $row['phone'] ) ) {
				$errors['phone'] = __( 'Enter a valid phone number with the country code.', 'marclie-booking' );
			}
		}
		if ( array_key_exists( 'country', $patch ) ) {
			$row['country'] = strtoupper( $text( 'country' ) );
			if ( '' !== $row['country'] && ! Countries::isValid( $row['country'] ) ) {
				$errors['country'] = __( 'Choose your country.', 'marclie-booking' );
			}
		}
		if ( array_key_exists( 'lang', $patch ) ) {
			$row['lang'] = (string) $patch['lang'];
			if ( ! in_array( $row['lang'], array( 'hr', 'en' ), true ) ) {
				$errors['lang'] = __( 'Choose Croatian or English.', 'marclie-booking' );
			}
		}
		if ( $errors ) {
			throw ValidationException::fields( $errors );
		}

		$userData = array_intersect_key( $row, array_flip( array( 'first_name', 'last_name' ) ) );
		if ( $userData ) {
			$userData['ID']           = $user->ID;
			$userData['display_name'] = trim( ( $row['first_name'] ?? $user->first_name ) . ' ' . ( $row['last_name'] ?? $user->last_name ) );
			$updated                  = wp_update_user( $userData );
			if ( is_wp_error( $updated ) ) {
				throw new \RuntimeException( $updated->get_error_message() );
			}
		}
		if ( isset( $row['lang'] ) ) {
			update_user_meta( $user->ID, self::META_LANG, $row['lang'] );
		}
		if ( array_key_exists( 'newsletter', $patch ) ) {
			if ( rest_sanitize_boolean( $patch['newsletter'] ) ) {
				if ( '' === (string) get_user_meta( $user->ID, self::META_CONSENT, true ) ) {
					update_user_meta( $user->ID, self::META_CONSENT, Db::utc( $this->clock->now() ) );
				}
			} else {
				delete_user_meta( $user->ID, self::META_CONSENT );
			}
			$row['marketing_consent'] = rest_sanitize_boolean( $patch['newsletter'] );
		}

		$guest = $this->guestFor( $user );
		if ( null !== $guest && $row ) {
			$this->guests->updateProfile( (int) $guest['id'], $row, self::actor( $user->ID ) );
		}

		$fresh = get_userdata( $user->ID );

		return $this->profile( $fresh instanceof \WP_User ? $fresh : $user );
	}

	/**
	 * Password change (the session cookie is renewed by the caller).
	 *
	 * @throws ValidationException
	 */
	public function changePassword( \WP_User $user, string $current, string $new ): void {
		$errors = array();
		if ( ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
			$errors['current'] = __( 'The current password is not correct.', 'marclie-booking' );
		}
		$weak = Registration::passwordError( $new );
		if ( null !== $weak ) {
			$errors['new'] = $weak;
		}
		if ( $errors ) {
			throw ValidationException::fields( $errors );
		}

		wp_set_password( $new, $user->ID );
		$guest = $this->guestFor( $user );
		$this->events->log( 'account_password_changed', self::actor( $user->ID ), null, null === $guest ? null : (int) $guest['id'] );
	}

	/**
	 * GDPR account deletion: the WordPress user is deleted; the guest and its
	 * bookings stay (accounting duty) without the user link.
	 *
	 * @throws ValidationException
	 */
	public function delete( \WP_User $user, string $password ): void {
		if ( ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			throw ValidationException::fields( array( 'password' => __( 'The password is not correct.', 'marclie-booking' ) ) );
		}
		if ( ! Roles::isGuestOnly( $user ) ) {
			throw ValidationException::fields( array( 'password' => __( 'Staff accounts cannot be deleted here.', 'marclie-booking' ) ) );
		}

		$guest      = $this->guestFor( $user );
		$bookingIds = array_map( static fn( array $b ): int => (int) $b['id'], $this->bookings->forUser( $user->ID ) );
		Db::transaction(
			function () use ( $user, $guest, $bookingIds ): void {
				Db::check( $this->bookings->unlinkUser( $user->ID ), 'Unlinking bookings' );
				Db::check( $this->guestRepo->unlinkUser( $user->ID ), 'Unlinking guest' );
				// Links handed out by the account stop working with it.
				Db::check( Container::tokenRepository()->deleteForBookings( $bookingIds, TokenRepository::MANAGE ), 'Revoking manage links' );
				$this->events->log( 'account_deleted', self::actor( $user->ID ), null, null === $guest ? null : (int) $guest['id'] );
			}
		);

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_logout();
		wp_delete_user( $user->ID );
	}

	/* Formatting ----------------------------------------------------------- */

	private static function nullable( mixed $value ): ?string {
		return null === $value || '' === $value ? null : (string) $value;
	}

	/** UTC DATETIME → Y-m-d in the site timezone. */
	private static function siteDate( string $utc ): ?string {
		if ( '' === $utc || str_starts_with( $utc, '0000' ) ) {
			return null;
		}

		return ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' );
	}

	/** UTC DATETIME → ISO 8601 in the site timezone. */
	private static function iso( string $utc ): string {
		return ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( DATE_ATOM );
	}
}
