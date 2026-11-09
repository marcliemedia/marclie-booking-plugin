<?php
/**
 * /admin/settings — read and update the `mbk_settings` sections
 * (policy, pages, notifications, advanced). Values are validated here (422 with
 * fields) and normalized again by Settings::sanitize() on save.
 */

namespace Marclie\Booking\Rest\Admin;

use Marclie\Booking\Container;
use Marclie\Booking\Domain\Exception\ValidationException;
use Marclie\Booking\Install\Activator;
use Marclie\Booking\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsController extends BaseController {

	private const TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

	public function register(): void {
		$cap = Activator::CAP_SETTINGS;

		$this->route( '/settings', $this->endpoint( \WP_REST_Server::READABLE, array( $this, 'show' ), $cap ) );
		$this->route(
			'/settings/(?P<section>policy|pages|notifications|advanced)',
			$this->endpoint( 'PATCH', array( $this, 'update' ), $cap )
		);
	}

	/** @return array<string, mixed> */
	public function show(): array {
		$settings = Settings::all();
		$pages    = get_pages(
			array(
				'post_status' => 'publish',
				'sort_column' => 'post_title',
			)
		);

		return array(
			'policy'        => $settings['policy'],
			'pages'         => array_map( 'intval', $settings['pages'] ),
			'notifications' => $settings['notifications'],
			'advanced'      => array_map( 'boolval', $settings['advanced'] ),
			'page_options'  => array_map(
				static fn( \WP_Post $page ): array => array(
					'id'    => $page->ID,
					'title' => '' !== $page->post_title ? $page->post_title : sprintf( '#%d', $page->ID ),
				),
				$pages ?: array()
			),
		);
	}

	/** @return array<string, mixed> */
	public function update( \WP_REST_Request $request ): array {
		$section = (string) $request['section'];
		$body    = $request->get_json_params();
		$body    = is_array( $body ) ? $body : $request->get_body_params();
		$values  = array_intersect_key( (array) $body, Settings::DEFAULTS[ $section ] );
		// Email on/off is changed only through PATCH /email-templates/{code}: a stale settings form must not undo it.
		unset( $values['disabled_codes'] );

		$errors = match ( $section ) {
			'policy'        => self::validatePolicy( $values ),
			'pages'         => self::validatePages( $values ),
			'notifications' => self::validateNotifications( $values ),
			'advanced'      => self::validateAdvanced( $values ),
			default         => array(),
		};
		if ( $errors ) {
			throw ValidationException::fields( $errors );
		}

		if ( $values ) {
			Settings::update( array( $section => $values ) );
			Container::eventRepository()->log(
				'settings_changed',
				self::actor(),
				null,
				null,
				array(
					'section' => $section,
					'keys'    => array_keys( $values ),
				)
			);
		}

		return $this->show();
	}

	/**
	 * @param array<string, mixed> $v
	 * @return array<string, string>
	 */
	private static function validatePolicy( array $v ): array {
		$errors = array();
		$ranges = array(
			'deposit_pct'      => array( 0, 100 ),
			'balance_days'     => array( 0, 365 ),
			'free_cancel_days' => array( 0, 365 ),
			'hold_minutes'     => array( 5, 120 ),
		);
		foreach ( $ranges as $key => [ $min, $max ] ) {
			if ( array_key_exists( $key, $v ) && ( ! is_int( $v[ $key ] ) || $v[ $key ] < $min || $v[ $key ] > $max ) ) {
				/* translators: 1: minimum, 2: maximum */
				$errors[ $key ] = sprintf( __( 'Enter a whole number between %1$d and %2$d.', 'marclie-booking' ), $min, $max );
			}
		}
		foreach ( array( 'checkin_from', 'checkin_to', 'checkout_until' ) as $key ) {
			if ( array_key_exists( $key, $v ) && ( ! is_string( $v[ $key ] ) || ! preg_match( self::TIME, $v[ $key ] ) ) ) {
				$errors[ $key ] = __( 'Use the HH:MM format.', 'marclie-booking' );
			}
		}
		if ( array_key_exists( 'default_coupon', $v ) ) {
			$code = is_string( $v['default_coupon'] ) ? strtoupper( trim( $v['default_coupon'] ) ) : null;
			// Only a changed code must exist (a fresh install keeps the DIRECT5 default before seeding).
			$unchanged = $code === strtoupper( (string) Settings::all()['policy']['default_coupon'] );
			if ( null === $code ) {
				$errors['default_coupon'] = __( 'Enter a coupon code.', 'marclie-booking' );
			} elseif ( '' !== $code && ! $unchanged && null === Container::couponRepository()->findRowByCode( $code ) ) {
				$errors['default_coupon'] = __( 'This coupon does not exist.', 'marclie-booking' );
			}
		}
		if ( array_key_exists( 'ref_prefix', $v ) && ( ! is_string( $v['ref_prefix'] ) || ! preg_match( '/^[A-Za-z0-9]{1,6}$/', trim( $v['ref_prefix'] ) ) ) ) {
			$errors['ref_prefix'] = __( 'Use 1–6 letters or digits.', 'marclie-booking' );
		}

		return $errors;
	}

	/**
	 * @param array<string, mixed> $v
	 * @return array<string, string>
	 */
	private static function validatePages( array $v ): array {
		$errors = array();
		foreach ( $v as $key => $id ) {
			if ( ! is_int( $id ) || $id < 0 ) {
				$errors[ $key ] = __( 'Choose a page.', 'marclie-booking' );
			} elseif ( $id > 0 && ( 'page' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) ) {
				$errors[ $key ] = __( 'Choose a published page.', 'marclie-booking' );
			}
		}

		return $errors;
	}

	/**
	 * @param array<string, mixed> $v
	 * @return array<string, string>
	 */
	private static function validateAdvanced( array $v ): array {
		$errors = array();
		foreach ( $v as $key => $value ) {
			if ( ! is_bool( $value ) ) {
				$errors[ $key ] = __( 'Use true or false.', 'marclie-booking' );
			}
		}

		return $errors;
	}

	/**
	 * @param array<string, mixed> $v
	 * @return array<string, string>
	 */
	private static function validateNotifications( array $v ): array {
		$errors = array();
		foreach ( array( 'from_name', 'from_email', 'reply_to' ) as $key ) {
			if ( array_key_exists( $key, $v ) && ! is_string( $v[ $key ] ) ) {
				$errors[ $key ] = __( 'Enter text.', 'marclie-booking' );
			}
		}
		if ( array_key_exists( 'from_name', $v ) && is_string( $v['from_name'] ) && mb_strlen( $v['from_name'] ) > 100 ) {
			$errors['from_name'] = __( 'Use at most 100 characters.', 'marclie-booking' );
		}
		if ( array_key_exists( 'admin_emails', $v ) ) {
			$list   = is_array( $v['admin_emails'] ) && array_filter( $v['admin_emails'], 'is_string' ) === $v['admin_emails'];
			$emails = $list ? $v['admin_emails'] : ( is_string( $v['admin_emails'] ) ? ( preg_split( '/[\s,;]+/', $v['admin_emails'] ) ?: array() ) : null );
			if ( null === $emails ) {
				$errors['admin_emails'] = __( 'Enter a list of email addresses.', 'marclie-booking' );
				$emails                 = array();
			}
			foreach ( array_filter( array_map( 'trim', $emails ) ) as $email ) {
				if ( ! is_email( $email ) ) {
					/* translators: %s: email address */
					$errors['admin_emails'] = sprintf( __( '%s is not a valid email address.', 'marclie-booking' ), $email );
					break;
				}
			}
		}
		foreach ( array( 'from_email', 'reply_to' ) as $key ) {
			if ( ! isset( $errors[ $key ] ) && array_key_exists( $key, $v ) && '' !== trim( (string) $v[ $key ] ) && ! is_email( trim( (string) $v[ $key ] ) ) ) {
				$errors[ $key ] = __( 'Enter a valid email address.', 'marclie-booking' );
			}
		}

		return $errors;
	}
}
