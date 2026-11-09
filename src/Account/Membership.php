<?php
/**
 * Guest membership tiers and member coupons (R2, WordPress-free).
 *
 * - Član (`clan`): on a verified signup; welcome coupon 5 % (one use).
 * - Srebrni (`srebrni`): after 1 completed stay; every membership year a
 *   coupon 5 % for stays of 7+ nights, valid 12 months; earlier check-in.
 * - Zlatni (`zlatni`): after 3 completed stays; every membership year a
 *   coupon 10 %, valid 12 months; later check-out.
 *
 * Tiers never go down. Member coupons are personal (`guest_id`, one use) with
 * a random code. Every issue is claimed first under an idempotency key
 * (`membership:welcome:{guest}`, `membership:{tier}:{guest}:{year index}`), so
 * running the daily cron twice — or concurrently — issues a coupon once.
 */

namespace Marclie\Booking\Account;

use Marclie\Booking\Domain\Clock;

defined( 'ABSPATH' ) || exit;

final class Membership {

	public const CLAN    = 'clan';
	public const SREBRNI = 'srebrni';
	public const ZLATNI  = 'zlatni';

	/** Tier => completed stays needed, lowest first. */
	public const TIERS = array(
		self::CLAN    => 0,
		self::SREBRNI => 1,
		self::ZLATNI  => 3,
	);

	/** Coupon kind => [code prefix, percent, min nights]. */
	public const COUPONS = array(
		'welcome'     => array( 'WELCOME', 5, 0 ),
		self::SREBRNI => array( 'SILVER', 5, 7 ),
		self::ZLATNI  => array( 'GOLD', 10, 0 ),
	);

	/** Member coupons are valid this many months from the day they are issued. */
	public const VALID_MONTHS = 12;

	private const CODE_ATTEMPTS = 3;

	/** Crockford base32 (no I, L, O, U): unambiguous when read or typed. */
	private const BASE32 = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	/** @var \Closure(): string */
	private \Closure $suffix;

	/**
	 * @param (callable(): string)|null $suffix Random code suffix (tests pass a deterministic one).
	 */
	public function __construct( private MembershipStore $store, private Clock $clock, ?callable $suffix = null ) {
		$this->suffix = null !== $suffix ? \Closure::fromCallable( $suffix ) : static fn(): string => self::randomSuffix();
	}

	/**
	 * 80 random bits as 16 base32 characters in groups of four
	 * (`7K2M-Q9XD-4HTB-P0RA`): personal codes cannot be guessed.
	 */
	public static function randomSuffix(): string {
		$bits = '';
		foreach ( str_split( random_bytes( 10 ) ) as $byte ) {
			$bits .= str_pad( decbin( ord( $byte ) ), 8, '0', STR_PAD_LEFT );
		}
		$chars = '';
		foreach ( str_split( $bits, 5 ) as $chunk ) {
			$chars .= self::BASE32[ bindec( $chunk ) ];
		}

		return implode( '-', str_split( $chars, 4 ) );
	}

	/* Pure rules ---------------------------------------------------------- */

	public static function isTier( mixed $tier ): bool {
		return is_string( $tier ) && isset( self::TIERS[ $tier ] );
	}

	/** Highest tier reached with `$stays` completed stays. */
	public static function tierFor( int $stays ): string {
		$tier = self::CLAN;
		foreach ( self::TIERS as $name => $min ) {
			if ( $stays >= $min ) {
				$tier = $name;
			}
		}

		return $tier;
	}

	public static function rank( string $tier ): int {
		return (int) array_search( $tier, array_keys( self::TIERS ), true );
	}

	public static function nextTier( string $tier ): ?string {
		$names = array_keys( self::TIERS );
		$index = array_search( $tier, $names, true );

		return false === $index ? self::SREBRNI : ( $names[ $index + 1 ] ?? null );
	}

	/** Completed stays still missing for the next tier (0 at the top). */
	public static function staysToNext( string $tier, int $stays ): int {
		$next = self::nextTier( $tier );

		return null === $next ? 0 : max( 0, self::TIERS[ $next ] - $stays );
	}

	/**
	 * Perk keys of a tier (higher tiers include the lower ones).
	 *
	 * @return list<string>
	 */
	public static function perks( string $tier ): array {
		$perks = array( 'bookings_overview', 'welcome_coupon' );
		if ( self::rank( $tier ) >= self::rank( self::SREBRNI ) ) {
			$perks[] = 'silver_coupon';
			$perks[] = 'early_checkin';
		}
		if ( self::rank( $tier ) >= self::rank( self::ZLATNI ) ) {
			$perks[] = 'gold_coupon';
			$perks[] = 'late_checkout';
		}

		return $perks;
	}

	/** Whole membership years between `$sinceYmd` and `$todayYmd` (0 in the first year). */
	public static function yearIndex( string $sinceYmd, string $todayYmd ): int {
		$since = new \DateTimeImmutable( $sinceYmd );
		$today = new \DateTimeImmutable( $todayYmd );

		return $today < $since ? 0 : (int) $since->diff( $today )->y;
	}

	/**
	 * Coupon row of a member coupon issued on `$today`.
	 *
	 * @param string $kind `welcome`, `srebrni` or `zlatni`.
	 * @return array<string, mixed>
	 */
	public static function couponRow( string $kind, int $guestId, string $suffix, \DateTimeImmutable $today ): array {
		if ( ! isset( self::COUPONS[ $kind ] ) ) {
			throw new \InvalidArgumentException( 'Unknown member coupon kind.' );
		}
		[ $prefix, $percent, $minNights ] = self::COUPONS[ $kind ];

		return array(
			'code'            => $prefix . '-' . strtoupper( $suffix ),
			'type'            => 'pct',
			'amount'          => $percent,
			'min_nights'      => $minNights,
			'book_from'       => null,
			'book_to'         => $today->modify( sprintf( '+%d months', self::VALID_MONTHS ) )->modify( '-1 day' )->format( 'Y-m-d' ),
			'stay_from'       => null,
			'stay_to'         => null,
			'unit_ids'        => null,
			'usage_limit'     => 1,
			'per_guest_limit' => 1,
			'guest_id'        => $guestId,
			'active'          => true,
		);
	}

	/** Kind of a member coupon from its code (`welcome`, `srebrni`, `zlatni`), or null. */
	public static function couponKind( string $code ): ?string {
		foreach ( self::COUPONS as $kind => [ $prefix ] ) {
			if ( str_starts_with( strtoupper( $code ), $prefix . '-' ) ) {
				return $kind;
			}
		}

		return null;
	}

	/* Issuing ------------------------------------------------------------- */

	/**
	 * Starts the membership of a freshly verified account: tier Član (unless a
	 * higher one is due) and the welcome coupon (once per guest).
	 *
	 * @return array{tier: string, previous: ?string, changed: bool, coupons: list<array<string, mixed>>}
	 */
	public function start( int $userId, int $guestId ): array {
		$welcome = $this->issue( 'membership:welcome:' . $guestId, 'welcome', $guestId );
		$result  = $this->sync( $userId, $guestId );
		if ( null !== $welcome ) {
			array_unshift( $result['coupons'], $welcome );
		}

		return $result;
	}

	/**
	 * Brings the stored tier up to date with the completed stays and issues the
	 * coupon of the current membership year of Srebrni/Zlatni. Idempotent.
	 *
	 * @return array{tier: string, previous: ?string, changed: bool, coupons: list<array<string, mixed>>}
	 */
	public function sync( int $userId, int $guestId ): array {
		$today   = $this->clock->today();
		$todayYmd = $today->format( 'Y-m-d' );
		$stored  = $this->store->tierOf( $userId );
		$current = self::isTier( $stored['tier'] ) ? (string) $stored['tier'] : null;
		$reached = self::tierFor( $this->store->completedStays( $guestId, $todayYmd ) );
		$tier    = null !== $current && self::rank( $current ) >= self::rank( $reached ) ? $current : $reached;
		$since   = null !== $stored['since'] && '' !== $stored['since'] ? (string) $stored['since'] : $todayYmd;
		$changed = $tier !== $current;

		if ( $changed ) {
			$since = $todayYmd;
			$this->store->setTier( $userId, $tier, $since );
		}

		$coupons = array();
		if ( isset( self::COUPONS[ $tier ] ) ) {
			$coupon = $this->issue( sprintf( 'membership:%s:%d:%d', $tier, $guestId, self::yearIndex( $since, $todayYmd ) ), $tier, $guestId );
			if ( null !== $coupon ) {
				$coupons[] = $coupon;
			}
		}

		return array(
			'tier'     => $tier,
			'previous' => $current,
			'changed'  => $changed,
			'coupons'  => $coupons,
		);
	}

	/**
	 * Claims `$key` and inserts the coupon; null when the key was claimed before.
	 *
	 * @return array<string, mixed>|null The inserted row (with `id`).
	 */
	private function issue( string $key, string $kind, int $guestId ): ?array {
		$claim = $this->store->claim( $key, $guestId, array( 'kind' => $kind ) );
		if ( 0 === $claim ) {
			return null;
		}

		try {
			for ( $attempt = 0; $attempt < self::CODE_ATTEMPTS; $attempt++ ) {
				$row = self::couponRow( $kind, $guestId, ( $this->suffix )(), $this->clock->today() );
				$id  = $this->store->insertCoupon( $row );
				if ( $id > 0 ) {
					$row['id'] = $id;
					$this->store->complete(
						$claim,
						array(
							'kind'      => $kind,
							'coupon_id' => $id,
							'code'      => $row['code'],
						)
					);

					return $row;
				}
			}
		} catch ( \Throwable $e ) {
			$this->store->release( $claim );
			throw $e;
		}

		$this->store->release( $claim );
		throw new \RuntimeException( 'Could not create a member coupon.' );
	}
}
