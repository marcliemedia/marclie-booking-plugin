<?php
/**
 * Guest-account pages (phase 10): /prijava/, /registracija/, /moj-racun/ (+ ?verify=TOKEN) and
 * /moj-racun/zaboravljena-lozinka/ (+ ?login&key). Server-rendered markup (mockup BEM blocks
 * `w-auth-split`, `w-account-board`, `w-page-title`); account.js posts the forms to the account REST
 * routes. The my-account board reads the same GET routes the API exposes (internal dispatch as
 * the logged-in user), so the page and the API never disagree.
 *
 * Routing (template_redirect): logged-out visitors of /moj-racun/ go to /prijava/?redirect=…;
 * logged-in visitors of /prijava/ and /registracija/ go to their (same-origin) redirect or to
 * /moj-racun/; ?verify=TOKEN shows a password form (the token is checked, not claimed) whose
 * POST account/verify {token, password} signs in and lands on /moj-racun/?verified=1.
 */

namespace Marclie\Booking\Frontend;

use Marclie\Booking\Account\Membership;
use Marclie\Booking\Account\Roles;
use Marclie\Booking\Container;
use Marclie\Booking\I18n\Countries;
use Marclie\Booking\I18n\Formatter;
use Marclie\Booking\I18n\LanguageAdapter;
use Marclie\Booking\Plugin;
use Marclie\Booking\Settings;

defined( 'ABSPATH' ) || exit;

final class AccountPages {

	public const LOGIN    = 'prijava';
	public const REGISTER = 'registracija';
	public const ACCOUNT  = 'moj_racun';
	public const FORGOT   = 'zaboravljena_lozinka';

	/** Page-map key => HR path (fallback before `wp mbk pages --create` ran). */
	private const PATHS = array(
		self::LOGIN    => 'prijava',
		self::REGISTER => 'registracija',
		self::ACCOUNT  => 'moj-racun',
		self::FORGOT   => 'moj-racun/zaboravljena-lozinka',
	);

	/** Board tab ids = panel ids = URL hashes (my-account.html#bookings). */
	public const TABS = array( 'overview', 'bookings', 'payments', 'coupons', 'membership', 'profile' );

	/** ?verify=TOKEN of this request: pending (password form) or unusable (invalid-link state). */
	private static ?string $verifyToken = null;

	private static bool $verifyPending = false;

	public static function hooks(): void {
		add_action( 'template_redirect', array( self::class, 'route' ), 5 );
	}

	/* Routing ------------------------------------------------------------------ */

	public static function route(): void {
		$page = self::currentPage();
		// The Bricks builder edits these pages as a logged-in editor: never redirect it.
		if ( null === $page || ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) ) {
			return;
		}

		// Per-visitor pages: never cached by the browser or a page cache.
		nocache_headers();
		$lang = LanguageAdapter::current();

		if ( self::ACCOUNT === $page ) {
			// The token is only checked here, never claimed: confirming needs the sign-up password
			// (POST account/verify from the form), so a leaked link alone opens nothing.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token is the credential.
			$token = isset( $_GET['verify'] ) ? sanitize_text_field( wp_unslash( $_GET['verify'] ) ) : '';
			if ( '' !== $token && ! self::isGuest() ) {
				self::$verifyToken   = $token;
				self::$verifyPending = Container::verification()->isPending( $token );
				return;
			}
			if ( ! self::isGuest() ) {
				wp_safe_redirect( add_query_arg( 'redirect', rawurlencode( self::url( self::ACCOUNT, $lang ) ), self::url( self::LOGIN, $lang ) ) );
				exit;
			}
			return;
		}

		if ( self::isGuest() && in_array( $page, array( self::LOGIN, self::REGISTER ), true ) ) {
			wp_safe_redirect( self::redirectTarget( $lang ) );
			exit;
		}
	}

	/**
	 * A guest account is signed in (role `mbk_guest`). Staff accounts are not guest accounts: for
	 * them the account pages behave as for visitors (log in as a guest to see a board).
	 */
	public static function isGuest(): bool {
		return is_user_logged_in() && Roles::hasGuestRole( wp_get_current_user() );
	}

	/** Page-map key of the current page, null elsewhere. */
	public static function currentPage(): ?string {
		if ( ! is_page() ) {
			return null;
		}
		$id = (int) get_queried_object_id();
		foreach ( array_keys( self::PATHS ) as $key ) {
			if ( $id && self::pageId( $key ) === $id ) {
				return $key;
			}
		}

		return null;
	}

	private static function pageId( string $key ): int {
		$pages = Settings::pages();
		if ( ! empty( $pages[ $key ] ) ) {
			return (int) $pages[ $key ];
		}
		$page = get_page_by_path( self::PATHS[ $key ] );

		return $page instanceof \WP_Post ? (int) $page->ID : 0;
	}

	/** Language-specific URL of an account page (page map, else the HR path). */
	public static function url( string $key, string $lang ): string {
		$url = LanguageAdapter::pageUrl( $key, $lang );
		if ( null === $url ) {
			$url = home_url( '/' . ( self::PATHS[ $key ] ?? $key ) . '/' );
			if ( 'en' === $lang ) {
				$url = add_query_arg( 'lang', 'en', $url );
			}
		}

		return $url;
	}

	/**
	 * Where to go after logging in: the `redirect` param when it is a same-origin URL that is not
	 * an auth page itself, else /moj-racun/.
	 */
	public static function redirectTarget( string $lang ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- validated below, never trusted.
		$raw    = isset( $_GET['redirect'] ) ? esc_url_raw( wp_unslash( $_GET['redirect'] ) ) : '';
		$target = '' !== $raw ? wp_validate_redirect( $raw, '' ) : '';
		if ( '' !== $target ) {
			$path = (string) wp_parse_url( $target, PHP_URL_PATH );
			foreach ( array( self::LOGIN, self::REGISTER ) as $key ) {
				if ( untrailingslashit( (string) wp_parse_url( self::url( $key, $lang ), PHP_URL_PATH ) ) === untrailingslashit( $path ) ) {
					$target = '';
				}
			}
		}

		return '' !== $target ? $target : self::url( self::ACCOUNT, $lang );
	}

	/** /prijava/?redirect=$url (login link of the checkout). */
	public static function loginUrl( string $lang, string $redirect = '' ): string {
		$url = self::url( self::LOGIN, $lang );

		return '' === $redirect ? $url : add_query_arg( 'redirect', rawurlencode( $redirect ), $url );
	}

	/* Widgets ------------------------------------------------------------------ */

	/** @param bool $preview Builder: the form even for the logged-in editor. */
	public static function login( bool $preview = false ): string {
		$lang = LanguageAdapter::current();
		if ( ! $preview && self::isGuest() ) {
			return self::signedIn( $lang );
		}

		Assets::enqueue( 'mbk-account' );

		return Renderer::render(
			'account/login',
			array(
				'lang'     => $lang,
				'redirect' => self::redirectTarget( $lang ),
				'register' => self::url( self::REGISTER, $lang ),
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only.
				'reset'    => isset( $_GET['reset'] ),
			)
		);
	}

	public static function register( bool $preview = false ): string {
		$lang = LanguageAdapter::current();
		if ( ! $preview && self::isGuest() ) {
			return self::signedIn( $lang );
		}

		Assets::enqueue( 'mbk-account' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only prefill (hvala invite).
		$email = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '';

		return Renderer::render(
			'account/register',
			array(
				'lang'    => $lang,
				'email'   => is_email( $email ) ? $email : '',
				'terms'   => Renderer::pageUrl( 'uvjeti', $lang ),
				'privacy' => Renderer::privacyUrl( $lang ),
				'login'   => self::url( self::LOGIN, $lang ),
			)
		);
	}

	/** Forgotten password: the request form, or the new-password form from the email link. */
	public static function forgotPassword( bool $preview = false ): string {
		$lang = LanguageAdapter::current();
		Assets::enqueue( 'mbk-account' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the reset key is the credential, checked by the REST route.
		$login = isset( $_GET['login'] ) ? sanitize_user( wp_unslash( $_GET['login'] ) ) : '';
		$key   = isset( $_GET['key'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable

		if ( ! $preview && '' !== $login && '' !== $key ) {
			return Renderer::render(
				'account/reset',
				array(
					'lang'   => $lang,
					'login'  => $login,
					'key'    => (string) $key,
					'forgot' => self::url( self::FORGOT, $lang ),
					'done'   => add_query_arg( 'reset', '1', self::url( self::LOGIN, $lang ) ),
				)
			);
		}

		return Renderer::render(
			'account/forgot',
			array(
				'lang'  => $lang,
				'login' => self::url( self::LOGIN, $lang ),
			)
		);
	}

	/**
	 * My account. `$part`: `head` (h1 + "Signed in as"), `board` (tabs + panels) or `all`.
	 *
	 * @param array<string, mixed>|null $sample Builder: sample data instead of the REST reads.
	 */
	public static function account( string $part = 'all', ?array $sample = null ): string {
		$lang = LanguageAdapter::current();
		$part = in_array( $part, array( 'head', 'board' ), true ) ? $part : 'all';

		if ( null === $sample && ! self::isGuest() ) {
			if ( 'head' === $part ) {
				return Renderer::render( 'account/account', array( 'part' => 'head', 'data' => null, 'lang' => $lang ) );
			}
			if ( null !== self::$verifyToken ) {
				Assets::enqueue( 'mbk-account' );
				return Renderer::render(
					'account/verify',
					array(
						'lang'     => $lang,
						'with_h1'  => 'all' === $part,
						'token'    => self::$verifyPending ? self::$verifyToken : '',
						'done'     => add_query_arg( 'verified', '1', self::url( self::ACCOUNT, $lang ) ),
						'login'    => self::url( self::LOGIN, $lang ),
						'register' => self::url( self::REGISTER, $lang ),
					)
				);
			}
			// Shortcode outside /moj-racun/ (no redirect there): the login form instead.
			return self::login();
		}

		Assets::enqueue( 'mbk-account' );
		$data = $sample ?? self::data( $lang );

		return Renderer::render(
			'account/account',
			array(
				'part' => $part,
				'data' => $data,
				'lang' => $lang,
			)
		);
	}

	/** Logged-in visitor on a login/register widget outside the mapped pages. */
	private static function signedIn( string $lang ): string {
		Assets::enqueue();
		$user = wp_get_current_user();

		return '<p class="w-auth-split__lede w-text-l" data-mbk-widget>' . esc_html(
			/* translators: %s: email address. */
			sprintf( __( 'You are logged in as %s.', 'marclie-booking' ), $user->user_email )
		) . ' <a href="' . esc_url( self::url( self::ACCOUNT, $lang ) ) . '">' . esc_html__( 'Go to my account', 'marclie-booking' ) . '</a></p>';
	}

	/* Board data ---------------------------------------------------------------- */

	/**
	 * GET /account/{route} as the current user (internal dispatch); [] on error.
	 *
	 * @return array<string, mixed>
	 */
	private static function get( string $route, string $lang ): array {
		$request = new \WP_REST_Request( 'GET', '/' . Plugin::REST_NAMESPACE . '/account/' . $route );
		$request->set_header( 'X-MBK-Lang', $lang );
		$response = rest_do_request( $request );
		$data     = $response->is_error() ? array() : $response->get_data();

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Profile of the logged-in visitor (checkout step-1 prefill); [] when logged out.
	 *
	 * @return array{first_name?: string, last_name?: string, email?: string, phone?: string, country?: string}
	 */
	public static function profile( string $lang ): array {
		if ( ! self::isGuest() ) {
			return array();
		}
		$user = wp_get_current_user();

		return array_map(
			static fn( $v ): string => is_scalar( $v ) ? (string) $v : '',
			array_intersect_key(
				self::get( 'profile', $lang ) + array(
					'first_name' => $user->first_name,
					'last_name'  => $user->last_name,
					'email'      => $user->user_email,
				),
				array_flip( array( 'first_name', 'last_name', 'email', 'phone', 'country' ) )
			)
		);
	}

	/**
	 * Everything the board shows, read through the account GET routes as the current user.
	 *
	 * @return array<string, mixed>
	 */
	private static function data( string $lang ): array {
		$user     = wp_get_current_user();
		$profile  = self::get( 'profile', $lang ) + array(
			'first_name' => $user->first_name,
			'last_name'  => $user->last_name,
			'email'      => $user->user_email,
		);
		$bookings = self::get( 'bookings', $lang );
		$payments = self::get( 'payments', $lang );
		$coupons  = self::get( 'coupons', $lang );

		return array(
			'profile'    => $profile,
			'bookings'   => array(
				'upcoming' => self::withPlace( (array) ( $bookings['upcoming'] ?? array() ), $lang ),
				'past'     => self::withPlace( (array) ( $bookings['past'] ?? array() ), $lang ),
			),
			'payments'   => array(
				'items' => array_values( (array) ( $payments['items'] ?? array() ) ),
				'card'  => is_array( $payments['card'] ?? null ) ? $payments['card'] : null,
			),
			'coupons'    => array_values( (array) ( $coupons['items'] ?? $coupons ) ),
			'membership' => self::get( 'membership', $lang ) + array(
				'tier'            => 'clan',
				'completed_stays' => 0,
				'member_since'    => $user->user_registered,
			),
			'verified'   => isset( $_GET['verified'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only.
			'urls'       => self::urls( $lang ),
		);
	}

	/**
	 * Booking items with `unit.place` ("House 2 · Ground floor") for the card kicker.
	 *
	 * @param array<int, mixed> $items
	 * @return list<array<string, mixed>>
	 */
	private static function withPlace( array $items, string $lang ): array {
		$out = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$item['unit'] = (array) ( $item['unit'] ?? array() ) + array(
				'title'     => '',
				'permalink' => '',
				'thumb'     => null,
			);
			$unit = empty( $item['unit']['id'] ) ? null : Container::units()->get( (int) $item['unit']['id'] );
			if ( null !== $unit && empty( $item['unit']['place'] ) ) {
				$item['unit']['place'] = Renderer::unitCard( $unit, $lang )['place'];
			}
			$out[] = $item;
		}

		return $out;
	}

	/**
	 * Links of the board (logout, search, contact, guide).
	 *
	 * @return array<string, string>
	 */
	public static function urls( string $lang ): array {
		$guide = get_page_by_path( 'kako-doci', OBJECT, 'post' );

		return array(
			'logout'  => wp_logout_url( 'en' === $lang ? add_query_arg( 'lang', 'en', home_url( '/' ) ) : home_url( '/' ) ),
			'search'  => Renderer::pageUrl( 'pretraga', $lang ),
			'contact' => Renderer::contactUrl( $lang ),
			'guide'   => $guide instanceof \WP_Post && 'publish' === $guide->post_status ? (string) get_permalink( $guide ) : '',
			'home'    => 'en' === $lang ? add_query_arg( 'lang', 'en', home_url( '/' ) ) : home_url( '/' ),
			'phone'   => Renderer::businessPhone(),
			'email'   => Renderer::businessEmail(),
		);
	}

	/* View helpers (templates) --------------------------------------------------- */

	/** "12.–19. srpnja 2027." / "12–19 July 2027"; full dates across months or years. */
	public static function dateRange( string $from, string $to, string $lang ): string {
		$a = date_create_immutable( $from, wp_timezone() );
		$b = date_create_immutable( $to, wp_timezone() );
		if ( ! $a || ! $b ) {
			return '';
		}
		if ( $a->format( 'Y-m' ) === $b->format( 'Y-m' ) ) {
			return $a->format( 'j' ) . ( 'en' === $lang ? '' : '.' ) . '–' . Formatter::date( $to, $lang );
		}

		return Formatter::date( $from, $lang ) . ' – ' . Formatter::date( $to, $lang );
	}

	/** Payment table date (a site-timezone Y-m-d): HR "28. 6. 2027.", EN "28 Jun 2027". */
	public static function shortDate( string $date, string $lang ): string {
		$d = date_create_immutable( substr( $date, 0, 10 ), wp_timezone() );
		if ( ! $d ) {
			return '';
		}

		return 'en' === $lang ? Formatter::date( $d, $lang, Formatter::SHORT ) : $d->format( 'j. n. Y.' );
	}

	/** "ožujka 2026." / "March 2026" (month in the genitive after "od"). */
	public static function monthYear( string $date, string $lang ): string {
		$long = Formatter::date( $date, $lang );

		return (string) preg_replace( '/^\d{1,2}\.?\s+/u', '', $long );
	}

	/** "2 odrasle osobe · 1 dijete (5–10) · 1 dijete (0–4)". */
	public static function party( int $adults, int $children, int $infants = 0 ): string {
		/* translators: %d: number of adults. */
		$parts = array( sprintf( _n( '%d adult', '%d adults', $adults, 'marclie-booking' ), $adults ) );
		if ( $children > 0 ) {
			/* translators: %d: number of children aged 5–10. */
			$parts[] = sprintf( _n( '%d child (5–10)', '%d children (5–10)', $children, 'marclie-booking' ), $children );
		}
		if ( $infants > 0 ) {
			/* translators: %d: number of children aged 0–4. */
			$parts[] = sprintf( _n( '%d child (0–4)', '%d children (0–4)', $infants, 'marclie-booking' ), $infants );
		}

		return implode( ' · ', $parts );
	}

	/** "7 noćenja". */
	public static function nights( int $nights ): string {
		/* translators: %d: number of nights. */
		return sprintf( _n( '%d night', '%d nights', $nights, 'marclie-booking' ), $nights );
	}

	/**
	 * Status pill of a booking: [label, modifier class].
	 *
	 * @return array{0: string, 1: string}
	 */
	public static function status( string $status ): array {
		return match ( $status ) {
			'confirmed', 'paid' => array( __( 'Confirmed', 'marclie-booking' ), 'w-account-board__status--ok' ),
			'balance_failed'    => array( __( 'Balance payment failed', 'marclie-booking' ), '' ),
			'pending_payment'   => array( __( 'Awaiting payment', 'marclie-booking' ), '' ),
			'completed'         => array( __( 'Completed', 'marclie-booking' ), '' ),
			'expired'           => array( __( 'Expired', 'marclie-booking' ), 'w-account-board__status--off' ),
			default             => array( __( 'Cancelled', 'marclie-booking' ), 'w-account-board__status--off' ),
		};
	}

	/** Payment kind after the apartment name: akontacija / ostatak iznosa / povrat. */
	public static function paymentKind( string $kind ): string {
		return match ( $kind ) {
			'deposit' => __( 'deposit', 'marclie-booking' ),
			'full'    => __( 'full amount', 'marclie-booking' ),
			'balance' => __( 'balance', 'marclie-booking' ),
			'refund'  => __( 'refund', 'marclie-booking' ),
			default   => __( 'payment', 'marclie-booking' ),
		};
	}

	/**
	 * Payment status pill: [label, modifier class].
	 *
	 * @return array{0: string, 1: string}
	 */
	public static function paymentStatus( string $status ): array {
		return match ( $status ) {
			'scheduled'         => array( __( 'Scheduled', 'marclie-booking' ), '' ),
			'succeeded', 'paid' => array( __( 'Paid', 'marclie-booking' ), 'w-account-board__status--ok' ),
			'refunded'          => array( __( 'Refunded', 'marclie-booking' ), '' ),
			'failed'            => array( __( 'Failed', 'marclie-booking' ), 'w-account-board__status--off' ),
			default             => array( __( 'Processing', 'marclie-booking' ), '' ),
		};
	}

	/** "Visa •••• 0486" ('' without a card). */
	public static function card( ?array $card ): string {
		if ( empty( $card['last4'] ) ) {
			return '';
		}
		$brand = (string) ( $card['brand'] ?? '' );
		$brand = match ( strtolower( $brand ) ) {
			'amex'       => 'American Express',
			'mastercard' => 'Mastercard',
			''           => __( 'Card', 'marclie-booking' ),
			default      => ucfirst( $brand ),
		};

		return $brand . ' •••• ' . $card['last4'];
	}

	/** Tier label: Član / Srebrni / Zlatni. */
	public static function tierName( string $tier ): string {
		return match ( $tier ) {
			'zlatni'  => __( 'Gold', 'marclie-booking' ),
			'srebrni' => __( 'Silver', 'marclie-booking' ),
			default   => _x( 'Member', 'membership tier', 'marclie-booking' ),
		};
	}

	/** Overview tile title: Član / Srebrni član / Zlatni član. */
	public static function tierMember( string $tier ): string {
		return match ( $tier ) {
			'zlatni'  => __( 'Gold member', 'marclie-booking' ),
			'srebrni' => __( 'Silver member', 'marclie-booking' ),
			default   => _x( 'Member', 'membership tier', 'marclie-booking' ),
		};
	}

	/**
	 * Progress to the next tier: null at the top tier.
	 *
	 * @param array<string, mixed> $membership
	 * @return array{next: string, done: int, max: int, left: int}|null
	 */
	public static function progress( array $membership ): ?array {
		$tier = (string) ( $membership['tier'] ?? Membership::CLAN );
		$done = (int) ( $membership['completed_stays'] ?? 0 );
		$next = Membership::nextTier( $tier );
		if ( null === $next ) {
			return null;
		}
		$max = Membership::TIERS[ $next ];

		return array(
			'next' => $next,
			'done' => min( $done, $max ),
			'max'  => $max,
			'left' => max( 0, $max - $done ),
		);
	}

	/** "Još 2 završena boravka do Zlatne razine." */
	public static function staysLeft( int $left, string $next ): string {
		return 'zlatni' === $next
			/* translators: %d: completed stays still needed. */
			? sprintf( _n( '%d more completed stay to reach Gold.', '%d more completed stays to reach Gold.', $left, 'marclie-booking' ), $left )
			/* translators: %d: completed stays still needed. */
			: sprintf( _n( '%d more completed stay to reach Silver.', '%d more completed stays to reach Silver.', $left, 'marclie-booking' ), $left );
	}

	/** aria-label of the tier meter. */
	public static function meterLabel( string $next ): string {
		return 'zlatni' === $next ? __( 'Completed stays towards Gold', 'marclie-booking' ) : __( 'Completed stays towards Silver', 'marclie-booking' );
	}

	/** "1 od 3" inside the meter. */
	public static function meterText( int $done, int $max ): string {
		/* translators: 1: completed stays, 2: stays needed for the next tier. */
		return sprintf( __( '%1$d of %2$d', 'marclie-booking' ), $done, $max );
	}

	/**
	 * Coupon card texts: [description, meta line].
	 *
	 * @param array<string, mixed> $coupon
	 * @return array{0: string, 1: string}
	 */
	public static function couponTexts( array $coupon, string $lang ): array {
		$amount = 'fixed' === ( $coupon['type'] ?? 'pct' )
			? Formatter::price( (int) ( $coupon['amount'] ?? 0 ), $lang )
			: Formatter::percent( (int) ( $coupon['amount'] ?? 0 ), $lang );
		$min    = (int) ( $coupon['min_nights'] ?? 0 );

		$text = match ( (string) ( $coupon['kind'] ?? 'other' ) ) {
			/* translators: %s: discount, e.g. "5 %". */
			'direct'  => sprintf( __( 'Book direct: %s off any stay booked on this website.', 'marclie-booking' ), $amount ),
			/* translators: %s: discount, e.g. "5 %". */
			'welcome' => sprintf( __( 'Welcome coupon: %s off your first booking as a member.', 'marclie-booking' ), $amount ),
			'silver'  => $min > 0
				/* translators: 1: discount, e.g. "5 %", 2: minimum nights. */
				? sprintf( __( 'Silver member coupon: %1$s off a stay of %2$d nights or more.', 'marclie-booking' ), $amount, $min )
				/* translators: %s: discount, e.g. "5 %". */
				: sprintf( __( 'Silver member coupon: %s off one stay.', 'marclie-booking' ), $amount ),
			/* translators: %s: discount, e.g. "10 %". */
			'gold'    => sprintf( __( 'Gold member coupon: %s off one stay.', 'marclie-booking' ), $amount ),
			default   => $min > 0
				/* translators: 1: discount, 2: minimum nights. */
				? sprintf( __( '%1$s off a stay of %2$d nights or more.', 'marclie-booking' ), $amount, $min )
				/* translators: %s: discount. */
				: sprintf( __( '%s off your stay.', 'marclie-booking' ), $amount ),
		};

		$meta = array();
		if ( ! empty( $coupon['used'] ) ) {
			$meta[] = empty( $coupon['used_at'] )
				? __( 'Used', 'marclie-booking' )
				/* translators: %s: date. */
				: sprintf( __( 'Used on %s', 'marclie-booking' ), Formatter::date( (string) $coupon['used_at'], $lang ) );
			if ( ! empty( $coupon['used_unit_title'] ) ) {
				$meta[] = (string) $coupon['used_unit_title'];
			}
		} elseif ( ! empty( $coupon['expired'] ) ) {
			$meta[] = empty( $coupon['valid_to'] )
				? __( 'Expired', 'marclie-booking' )
				/* translators: %s: date. */
				: sprintf( __( 'Expired on %s', 'marclie-booking' ), Formatter::date( (string) $coupon['valid_to'], $lang ) );
		} else {
			$meta[] = empty( $coupon['valid_to'] )
				? __( 'No end date', 'marclie-booking' )
				/* translators: %s: date. */
				: sprintf( __( 'Valid until %s', 'marclie-booking' ), Formatter::date( (string) $coupon['valid_to'], $lang ) );
			if ( ! empty( $coupon['single_use'] ) ) {
				$meta[] = __( 'one use', 'marclie-booking' );
			}
		}

		return array( $text, implode( ' · ', $meta ) );
	}

	/** Coupon usable now. */
	public static function couponReady( array $coupon ): bool {
		return empty( $coupon['used'] ) && empty( $coupon['expired'] );
	}

	/** Country options for the profile form. */
	public static function countries( string $lang ): array {
		return Countries::names( $lang );
	}

	/* Builder sample -------------------------------------------------------------- */

	/**
	 * Board data for the Bricks builder (no account behind the editor): one upcoming and one past
	 * stay of the first unit, payments, coupons and the Silver tier.
	 *
	 * @return array<string, mixed>
	 */
	public static function sample( ?\Marclie\Booking\Units\Unit $unit, \DateTimeImmutable $today ): array {
		$lang  = LanguageAdapter::current();
		$thumb = null !== $unit && $unit->thumbnailId ? (string) wp_get_attachment_image_url( $unit->thumbnailId, 'medium_large' ) : null;
		$item  = static fn( string $arrival, int $nights, string $status, string $ref ): array => array(
			'ref'               => $ref,
			'status'            => $status,
			'arrival'           => $arrival,
			'departure'         => ( new \DateTimeImmutable( $arrival ) )->modify( '+' . $nights . ' days' )->format( 'Y-m-d' ),
			'nights'            => $nights,
			'adults'            => 2,
			'children'          => 2,
			'total_cents'       => 108500,
			'paid_cents'        => 32550,
			'balance_cents'     => 75950,
			'balance_due'       => ( new \DateTimeImmutable( $arrival ) )->modify( '-14 days' )->format( 'Y-m-d' ),
			'refunded_cents'    => 0,
			'coupon_code'       => null,
			'free_cancel_until' => ( new \DateTimeImmutable( $arrival ) )->modify( '-30 days' )->format( 'Y-m-d' ),
			'cancel_requested'  => false,
			'unit'              => array(
				'id'        => null === $unit ? 0 : $unit->id,
				'title'     => null === $unit ? 'Apartman 101' : $unit->title,
				'permalink' => null === $unit ? '#' : $unit->permalink,
				'thumb'     => $thumb,
			),
			'actions'           => array(
				'rebook_url'         => '#',
				'manage_url'         => '#',
				'can_request_cancel' => true,
			),
		);

		$next   = $today->modify( '+60 days' )->format( 'Y-m-d' );
		$past   = $today->modify( '-90 days' )->format( 'Y-m-d' );
		$prefix = Settings::refPrefix();

		return array(
			'profile'    => array(
				'first_name' => 'Lena',
				'last_name'  => 'Hofer',
				'email'      => 'lena.hofer@example.com',
				'phone'      => '+43 664 218 3307',
				'country'    => 'AT',
				'lang'       => $lang,
				'newsletter' => false,
			),
			'bookings'   => array(
				'upcoming' => self::withPlace( array( $item( $next, 7, 'confirmed', $prefix . '-27-0712-101' ) ), $lang ),
				'past'     => self::withPlace( array( array( 'status' => 'completed', 'paid_cents' => 103075, 'balance_cents' => 0 ) + $item( $past, 7, 'completed', $prefix . '-26-0802-004' ) ), $lang ),
			),
			'payments'   => array(
				'items' => array(
					array(
						'date'         => $today->modify( '+46 days' )->format( 'Y-m-d' ),
						'ref'          => $prefix . '-27-0712-101',
						'unit_title'   => null === $unit ? 'Apartman 101' : $unit->title,
						'kind'         => 'balance',
						'amount_cents' => 75950,
						'status'       => 'scheduled',
						'card'         => array( 'brand' => 'visa', 'last4' => '0486' ),
					),
					array(
						'date'         => $today->format( 'Y-m-d' ),
						'ref'          => $prefix . '-27-0712-101',
						'unit_title'   => null === $unit ? 'Apartman 101' : $unit->title,
						'kind'         => 'deposit',
						'amount_cents' => 32550,
						'status'       => 'succeeded',
						'card'         => array( 'brand' => 'visa', 'last4' => '0486' ),
					),
				),
				'card'  => array(
					'brand'     => 'visa',
					'last4'     => '0486',
					'exp_month' => 9,
					'exp_year'  => 2029,
				),
			),
			'coupons'    => array(
				array(
					'code'   => (string) Container::policy()->defaultCoupon,
					'type'   => 'pct',
					'amount' => 5,
					'kind'   => 'direct',
				),
				array(
					'code'       => 'SILVER-1-' . $today->format( 'Y' ),
					'type'       => 'pct',
					'amount'     => 5,
					'min_nights' => 7,
					'valid_to'   => $today->modify( '+1 year' )->format( 'Y-m-d' ),
					'single_use' => true,
					'kind'       => 'silver',
				),
			),
			'membership' => array(
				'tier'            => 'srebrni',
				'completed_stays' => 1,
				'next_tier'       => 'zlatni',
				'member_since'    => $today->modify( '-8 months' )->format( 'Y-m-d' ),
			),
			'verified'   => false,
			'urls'       => self::urls( $lang ),
		);
	}
}
