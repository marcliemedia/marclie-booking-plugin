<?php
/**
 * Renders and sends plugin emails through wp_mail().
 *
 * - Guest emails are rendered in the booking's language inside
 *   LanguageAdapter::withLanguage(), so cron, webhooks and the admin app
 *   (English or Croatian admin user) produce the guest's language.
 *   Admin emails use the site language.
 * - HTML body with a plain-text alternative (PHPMailer AltBody → multipart).
 * - From / Reply-To from Settings › Notifications, falling back to the ACF
 *   business email and brand name.
 * - Every attempt is logged in mbk_events: `email_sent` / `email_failed`
 *   with code, lang, to, subject (never the body).
 * - Filters: `mbk_email_enabled` (bool, code, booking|null) and
 *   `mbk_email_args` (to, subject, html, text, headers, code, lang, booking_id).
 */

namespace Marclie\Booking\Email;

use Marclie\Booking\Admin\AppPage;
use Marclie\Booking\Container;
use Marclie\Booking\I18n\LanguageAdapter;
use Marclie\Booking\Repository\EventRepository;
use Marclie\Booking\Settings;

defined( 'ABSPATH' ) || exit;

final class Mailer {

	public const ACTOR = 'system:mailer';

	public function __construct(
		private TemplateRepository $templates,
		private EventRepository $events
	) {}

	public function templates(): TemplateRepository {
		return $this->templates;
	}

	/**
	 * Sends a booking template to its recipient (guest or admin recipients).
	 *
	 * Options: `actor` (event actor), `force` (also when switched off: admin
	 * resend), `extra` (fact overrides: payment_link via `links`, alert_title,
	 * alert_message), `once` (skip when this code was already sent for the booking).
	 *
	 * @param array<string, mixed> $booking Booking row.
	 * @param array<string, mixed> $opts
	 */
	public function sendForBooking( string $code, array $booking, array $opts = array() ): bool {
		if ( ! EmailRegistry::exists( $code ) || 'guest_message' === $code ) {
			throw new \InvalidArgumentException( 'Unknown booking email template.' );
		}
		if ( empty( $opts['force'] ) && ! $this->enabled( $code, $booking ) ) {
			return false;
		}
		$bookingId = (int) $booking['id'];
		$guestId   = isset( $booking['guest_id'] ) ? (int) $booking['guest_id'] : null;
		$actor     = (string) ( $opts['actor'] ?? self::ACTOR );
		$guest     = EmailRegistry::GUEST === EmailRegistry::recipient( $code );
		$to        = $this->recipients( $code, $booking );
		if ( ! $to ) {
			return false;
		}
		$lang = $guest ? ( LanguageAdapter::normalize( $booking['lang'] ?? null ) ?? LanguageAdapter::DEFAULT ) : self::adminLang();

		// `once`: claim the send atomically (UNIQUE external_id) so overlapping cron runs never both send.
		$claim = null;
		if ( ! empty( $opts['once'] ) ) {
			if ( $this->events->hasEmailSent( $bookingId, $code ) ) {
				return false;
			}
			$claim = $this->events->claim( self::claimKey( $code, $bookingId ), 'email_sent', $actor, $bookingId, $guestId, self::payload( $code, $lang, $to, '' ) );
			if ( 0 === $claim ) {
				return false;
			}
		}

		$extra = (array) ( $opts['extra'] ?? array() );
		try {
			$message = LanguageAdapter::withLanguage(
				$lang,
				function () use ( $code, $lang, $booking, $extra ): array {
					$template = $this->templates->get( $code, $lang );
					$facts    = Context::bookingFacts( $booking, $lang, self::neededTags( $code, $template ), false, $extra );

					return Renderer::render( $code, $lang, $template, Context::build( $code, $facts, $lang, BusinessInfo::get() ), BusinessInfo::get() );
				}
			);
		} catch ( \Throwable $e ) {
			if ( null !== $claim ) {
				$this->events->updateEvent( $claim, 'email_failed', self::payload( $code, $lang, $to, '' ) + array( 'error' => substr( $e->getMessage(), 0, 300 ) ), true );
			}
			throw $e;
		}

		return $this->deliver( $code, $lang, $to, $message, $bookingId, $guestId, $actor, $booking, array( 'claim' => $claim ) );
	}

	/** External ID of the one-time send of `$code` for a booking (mbk_events.external_id, UNIQUE). */
	public static function claimKey( string $code, int $bookingId ): string {
		return 'email:' . $code . ':' . $bookingId;
	}

	/**
	 * admin_alert without a booking (e.g. a failing iCal feed) to the admin
	 * recipients in the admin language; switched off = not sent. `$texts` runs
	 * inside the admin language; its `subject_ref` fills {booking_ref} (e.g.
	 * unit and channel). `$adminFragment`: admin app route of the CTA (`#/…`).
	 *
	 * @param callable(): array{alert_title:string, alert_message:string, subject_ref?:string} $texts
	 */
	public function sendAdminAlert( callable $texts, string $adminFragment = '#/', string $actor = self::ACTOR ): bool {
		if ( ! $this->enabled( 'admin_alert', null ) ) {
			return false;
		}
		$to = self::adminRecipients();
		if ( ! $to ) {
			return false;
		}
		$lang = self::adminLang();

		$message = LanguageAdapter::withLanguage(
			$lang,
			function () use ( $texts, $lang, $adminFragment ): array {
				$facts          = (array) $texts();
				$facts['links'] = array( 'admin_link' => admin_url( 'admin.php?page=' . AppPage::SLUG . $adminFragment ) );

				return Renderer::render( 'admin_alert', $lang, $this->templates->get( 'admin_alert', $lang ), Context::build( 'admin_alert', $facts, $lang, BusinessInfo::get() ), BusinessInfo::get() );
			}
		);

		return $this->deliver( 'admin_alert', $lang, $to, $message, null, null, $actor, null );
	}

	/**
	 * Free email from the guest profile (guest_message) in the guest's language.
	 *
	 * @param array<string, mixed> $guest Guest row.
	 */
	public function sendToGuest( array $guest, string $subject, string $bodyHtml, ?string $lang, string $actor ): bool {
		$lang = LanguageAdapter::normalize( $lang ) ?? LanguageAdapter::normalize( $guest['lang'] ?? null ) ?? LanguageAdapter::DEFAULT;
		$to   = self::guestAddress( (string) $guest['email'] );
		if ( ! $to ) {
			return false;
		}

		$message = LanguageAdapter::withLanguage(
			$lang,
			function () use ( $guest, $subject, $bodyHtml, $lang ): array {
				$template = array(
					'subject'   => $subject,
					'preheader' => $this->templates->get( 'guest_message', $lang )['preheader'],
					'heading'   => '',
					'body_html' => $bodyHtml,
				);

				return Renderer::render( 'guest_message', $lang, TemplateRepository::clean( $template ), Context::build( 'guest_message', Context::guestFacts( $guest ), $lang, BusinessInfo::get() ), BusinessInfo::get() );
			}
		);

		return $this->deliver( 'guest_message', $lang, $to, $message, null, (int) $guest['id'], $actor, null );
	}

	/**
	 * Account email (verification, password reset, membership) to one address in
	 * `$lang`; switched-off templates are not sent. Logged like booking emails,
	 * with the guest when known.
	 *
	 * @param array<string, mixed> $facts Context::accountFacts().
	 */
	public function sendAccount( string $code, string $email, string $lang, array $facts, ?int $guestId, string $actor = self::ACTOR ): bool {
		if ( ! EmailRegistry::isAccountTemplate( $code ) ) {
			throw new \InvalidArgumentException( 'Unknown account email template.' );
		}
		if ( ! $this->enabled( $code, null ) ) {
			return false;
		}
		$to = self::guestAddress( $email );
		if ( ! $to ) {
			return false;
		}
		$lang = LanguageAdapter::normalize( $lang ) ?? LanguageAdapter::DEFAULT;

		$message = LanguageAdapter::withLanguage(
			$lang,
			fn(): array => Renderer::render( $code, $lang, $this->templates->get( $code, $lang ), Context::build( $code, $facts, $lang, BusinessInfo::get() ), BusinessInfo::get() )
		);

		return $this->deliver( $code, $lang, $to, $message, null, $guestId, $actor, null );
	}

	/**
	 * Rendered email for the editor: a draft or the saved version, with the
	 * registry sample or a real booking (inert links, no tokens issued).
	 *
	 * @param array<string, mixed>|null $draft   subject, preheader, heading, body_html.
	 * @param array<string, mixed>|null $booking Booking row.
	 * @return array{subject: string, html: string, text: string}
	 */
	public function preview( string $code, string $lang, ?array $draft, ?array $booking ): array {
		return LanguageAdapter::withLanguage(
			$lang,
			function () use ( $code, $lang, $draft, $booking ): array {
				$template = null !== $draft ? TemplateRepository::clean( $draft ) : $this->templates->get( $code, $lang );
				if ( 'guest_message' === $code ) {
					$facts = null !== $booking ? Context::guestFacts( $booking ) : EmailRegistry::sample( $code );
				} elseif ( EmailRegistry::isAccountTemplate( $code ) ) {
					$facts = Context::sampleFacts( $code, $lang );
				} elseif ( null !== $booking ) {
					$extra = 'admin_alert' === $code ? array_intersect_key( EmailRegistry::sample( $code ), array_flip( array( 'alert_title', 'alert_message' ) ) ) : array();
					$facts = Context::bookingFacts( $booking, $lang, self::neededTags( $code, $template ), true, $extra );
				} else {
					$facts = Context::sampleFacts( $code, $lang );
				}

				return Renderer::render( $code, $lang, $template, Context::build( $code, $facts, $lang, BusinessInfo::get() ), BusinessInfo::get() );
			}
		);
	}

	/**
	 * Test send of a preview to any address (subject prefixed "[Test]"); logged as an event without booking.
	 *
	 * @param array<string, mixed>|null $draft
	 * @param array<string, mixed>|null $booking
	 */
	public function sendTest( string $code, string $lang, string $to, ?array $draft, ?array $booking, string $actor ): bool {
		$message            = $this->preview( $code, $lang, $draft, $booking );
		$message['subject'] = '[Test] ' . $message['subject'];

		// Logged without the subject (it may carry a guest's name), only the booking it was rendered with.
		return $this->deliver(
			$code,
			$lang,
			array( $to ),
			$message,
			null,
			null,
			$actor,
			null,
			array(
				'type'    => 'email_test_sent',
				'payload' => array( 'booking_id' => null !== $booking ? (int) $booking['id'] : null ),
			)
		);
	}

	/**
	 * Booking templates that make sense to (re)send for the booking's current
	 * status, regardless of recipients (admin resend, BookingDetail).
	 *
	 * @param array<string, mixed> $booking
	 * @return list<string>
	 */
	public function applicableCodes( array $booking ): array {
		$id          = (int) $booking['id'];
		$status      = (string) $booking['status'];
		$today       = Container::clock()->today()->format( 'Y-m-d' );
		$outstanding = (int) $booking['total_cents'] > Container::paymentRepository()->netPaidCents( $id );
		$savedCard   = ! empty( $booking['stripe_customer_id'] ) && ! empty( $booking['stripe_payment_method_id'] );
		$sold        = in_array( $status, array( 'confirmed', 'paid', 'balance_failed', 'completed' ), true );
		$holdLost    = 'cancelled' === $status && (bool) array_filter( $this->events->forBooking( $id ), static fn( array $e ): bool => 'hold_lost' === $e['type'] );
		$payable     = in_array( $status, array( 'confirmed', 'balance_failed' ), true ) || ( 'pending_payment' === $status && empty( $booking['hold_expires_at'] ) );

		$codes = array(
			'guest_confirmed'        => in_array( $status, array( 'confirmed', 'paid', 'completed' ), true ),
			'admin_new_booking'      => $sold,
			'guest_balance_reminder' => 'confirmed' === $status && $outstanding && $savedCard && (string) $booking['balance_due_on'] >= $today,
			'guest_balance_paid'     => in_array( $status, array( 'paid', 'completed' ), true ),
			'guest_balance_failed'   => 'balance_failed' === $status,
			'guest_cancelled'        => 'cancelled' === $status && ! $holdLost,
			'guest_pre_arrival'      => in_array( $status, array( 'confirmed', 'paid' ), true ) && (string) $booking['arrival'] >= $today,
			'guest_hold_lost'        => $holdLost,
			'guest_payment_link'     => $payable && $outstanding && null !== Container::paymentGateway(),
		);

		return array_keys( array_filter( $codes ) );
	}

	/**
	 * applicableCodes() that also have a recipient (BookingDetail.resendable_codes).
	 *
	 * @param array<string, mixed> $booking
	 * @return list<string>
	 */
	public function resendableCodes( array $booking ): array {
		return array_values( array_filter( $this->applicableCodes( $booking ), fn( string $code ): bool => (bool) $this->recipients( $code, $booking ) ) );
	}

	/**
	 * @param array<string, mixed> $booking
	 * @return list<string>
	 */
	public function recipients( string $code, array $booking ): array {
		return EmailRegistry::GUEST === EmailRegistry::recipient( $code ) ? self::guestAddress( (string) $booking['email'] ) : self::adminRecipients();
	}

	/**
	 * Whether automatic sending of `$code` is on (settings + `mbk_email_enabled`).
	 *
	 * @param array<string, mixed>|null $booking
	 */
	public function enabled( string $code, ?array $booking = null ): bool {
		/**
		 * Filters whether an automatic email is sent.
		 *
		 * @param bool                      $enabled Template switched on in the admin app.
		 * @param string                    $code    EmailRegistry code.
		 * @param array<string, mixed>|null $booking
		 */
		return (bool) apply_filters( 'mbk_email_enabled', $this->templates->isEnabled( $code ), $code, $booking );
	}

	/**
	 * Settings › Notifications admin emails, else the ACF business email, else the site admin email.
	 *
	 * @return list<string>
	 */
	public static function adminRecipients(): array {
		$emails = Settings::adminEmails();
		if ( ! $emails ) {
			$fallback = BusinessInfo::get()['email'];
			$emails   = is_email( $fallback ) ? array( $fallback ) : array();
		}

		return array_values( array_filter( $emails, 'is_email' ) );
	}

	/**
	 * Admin emails follow the configured site language (WPLANG; empty = en_US),
	 * not the locale of the current request or a locale switch in progress.
	 */
	public static function adminLang(): string {
		$site = (string) get_option( 'WPLANG' );

		return LanguageAdapter::normalize( '' !== $site ? $site : 'en_US' ) ?? LanguageAdapter::DEFAULT;
	}

	/* Internals ---------------------------------------------------------- */

	/**
	 * Merge tags the template (and its call-to-action button) needs.
	 *
	 * @param array<string, string> $template
	 * @return list<string>
	 */
	private static function neededTags( string $code, array $template ): array {
		$used = MergeTags::used( implode( "\n", array( $template['subject'], $template['preheader'], $template['heading'], $template['body_html'] ) ) );
		$cta  = EmailRegistry::ctaTag( $code );
		if ( null !== $cta && in_array( 'cta_button', $used, true ) ) {
			$used[] = $cta;
		}

		return $used;
	}

	/** @return list<string> */
	private static function guestAddress( string $email ): array {
		return '' !== $email && is_email( $email ) && ! str_ends_with( $email, '.invalid' ) ? array( $email ) : array();
	}

	/**
	 * @return list<string>
	 */
	private static function headers(): array {
		$business = BusinessInfo::get();
		$from     = Settings::fromEmail();
		$from     = is_email( $from ) ? $from : $business['email'];
		$name     = Settings::fromName();
		$name     = '' !== $name ? $name : $business['name'];
		$replyTo  = Settings::replyTo();
		$replyTo  = is_email( $replyTo ) ? $replyTo : $business['email'];

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( is_email( $from ) ) {
			$headers[] = sprintf( 'From: %s <%s>', str_replace( array( '"', "\r", "\n", '<', '>' ), '', $name ), $from );
		}
		if ( is_email( $replyTo ) ) {
			$headers[] = 'Reply-To: ' . $replyTo;
		}

		return $headers;
	}

	/**
	 * @param list<string>                             $to
	 * @param array{subject: string, html: string, text: string} $message
	 * @param array<string, mixed>|null                $booking
	 */
	/**
	 * @param list<string> $to
	 * @return array{code: string, lang: string, to: string, subject: string}
	 */
	private static function payload( string $code, string $lang, array $to, string $subject ): array {
		return array(
			'code'    => $code,
			'lang'    => $lang,
			'to'      => implode( ', ', $to ),
			'subject' => $subject,
		);
	}

	/**
	 * Sends and logs. `$log`: `type` (event type when sent, default email_sent),
	 * `payload` (replaces the subject in the event), `claim` (id of the claim
	 * event to complete instead of logging a new one; released on failure).
	 *
	 * @param array<string, mixed> $log
	 */
	private function deliver( string $code, string $lang, array $to, array $message, ?int $bookingId, ?int $guestId, string $actor, ?array $booking, array $log = array() ): bool {
		/**
		 * Filters the email right before wp_mail().
		 *
		 * @param array{to: list<string>, subject: string, html: string, text: string, headers: list<string>, code: string, lang: string, booking_id: ?int} $args
		 * @param array<string, mixed>|null $booking
		 */
		$args = (array) apply_filters(
			'mbk_email_args',
			array(
				'to'         => $to,
				'subject'    => $message['subject'],
				'html'       => $message['html'],
				'text'       => $message['text'],
				'headers'    => self::headers(),
				'code'       => $code,
				'lang'       => $lang,
				'booking_id' => $bookingId,
			),
			$booking
		);

		$text  = (string) $args['text'];
		$error = null;
		$alt   = static function ( $phpmailer ) use ( $text ): void {
			$phpmailer->AltBody = $text;
		};
		$fail  = static function ( \WP_Error $e ) use ( &$error ): void {
			$error = $e->get_error_message();
		};
		add_action( 'phpmailer_init', $alt );
		add_action( 'wp_mail_failed', $fail );
		try {
			$sent = wp_mail( (array) $args['to'], (string) $args['subject'], (string) $args['html'], (array) $args['headers'] );
		} catch ( \Throwable $e ) {
			$sent  = false;
			$error = $e->getMessage();
		} finally {
			remove_action( 'phpmailer_init', $alt );
			remove_action( 'wp_mail_failed', $fail );
		}

		$payload = self::payload( $code, $lang, (array) $args['to'], (string) $args['subject'] );
		if ( isset( $log['payload'] ) ) {
			unset( $payload['subject'] );
			$payload += (array) $log['payload'];
		}
		if ( ! $sent ) {
			$payload['error'] = null !== $error ? substr( $error, 0, 300 ) : 'wp_mail returned false';
		}
		$type = $sent ? (string) ( $log['type'] ?? 'email_sent' ) : 'email_failed';
		if ( ! empty( $log['claim'] ) ) {
			// Failure releases the claim (external_id cleared) so the next run retries.
			$this->events->updateEvent( (int) $log['claim'], $type, $payload, ! $sent );
		} else {
			$this->events->log( $type, $actor, $bookingId, $guestId, $payload );
		}

		return (bool) $sent;
	}
}
