<?php
/**
 * Guest account registration (/registracija/): first name, last name, email,
 * password (8+ characters), accepted terms, optional newsletter consent.
 *
 * The answer never tells whether an email has an account, not even by its
 * timing: both paths hash the password and queue one AccountMail job; a new
 * address then gets the verification email (account_verify, Verification),
 * an address with an account gets account_exists (sign-in and password-reset
 * links) at most once per 15 minutes. `users_can_register` stays off — this
 * is the only way in.
 */

namespace Marclie\Booking\Account;

use Marclie\Booking\Domain\Exception\ValidationException;
use Marclie\Booking\Repository\GuestRepository;

defined( 'ABSPATH' ) || exit;

final class Registration {

	public const MIN_PASSWORD = 8;

	/** WordPress hashes at most 4096 bytes; longer is certainly not a typed password. */
	public const MAX_PASSWORD = 200;

	public function __construct( private Verification $verification ) {}

	/** Translated problem with a new password, or null when it is acceptable. */
	public static function passwordError( string $password ): ?string {
		$length = mb_strlen( $password );
		if ( $length < self::MIN_PASSWORD ) {
			/* translators: %d: minimum number of characters */
			return sprintf( __( 'Use at least %d characters.', 'marclie-booking' ), self::MIN_PASSWORD );
		}
		if ( $length > self::MAX_PASSWORD ) {
			/* translators: %d: maximum number of characters */
			return sprintf( __( 'Use at most %d characters.', 'marclie-booking' ), self::MAX_PASSWORD );
		}

		return null;
	}

	/**
	 * Validated registration data.
	 *
	 * @param array<string, mixed> $input first_name, last_name, email, password, terms, newsletter, lang.
	 * @return array{email: string, first_name: string, last_name: string, password: string, newsletter: bool, lang: string}
	 * @throws ValidationException
	 */
	public static function validate( array $input ): array {
		$text = static fn( string $key ): string => trim( sanitize_text_field( (string) ( $input[ $key ] ?? '' ) ) );
		$data = array(
			'first_name' => $text( 'first_name' ),
			'last_name'  => $text( 'last_name' ),
			'email'      => GuestRepository::normalizeEmail( $text( 'email' ) ),
			'password'   => (string) ( $input['password'] ?? '' ),
			'newsletter' => ! empty( $input['newsletter'] ),
			'lang'       => in_array( $input['lang'] ?? null, array( 'hr', 'en' ), true ) ? (string) $input['lang'] : 'hr',
		);

		$errors = array();
		if ( '' === $data['first_name'] || mb_strlen( $data['first_name'] ) > 100 ) {
			$errors['first_name'] = __( 'Enter your first name.', 'marclie-booking' );
		}
		if ( '' === $data['last_name'] || mb_strlen( $data['last_name'] ) > 100 ) {
			$errors['last_name'] = __( 'Enter your last name.', 'marclie-booking' );
		}
		if ( '' === $data['email'] || strlen( $data['email'] ) > 100 || ! is_email( $data['email'] ) ) {
			$errors['email'] = __( 'Enter a valid email address.', 'marclie-booking' );
		}
		$weak = self::passwordError( $data['password'] );
		if ( null !== $weak ) {
			$errors['password'] = $weak;
		}
		if ( true !== ( $input['terms'] ?? null ) ) {
			$errors['terms'] = __( 'Please accept the terms and the privacy policy.', 'marclie-booking' );
		}
		if ( $errors ) {
			throw ValidationException::fields( $errors );
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $input
	 * @throws ValidationException
	 */
	public function register( array $input ): void {
		$data = self::validate( $input );

		if ( false !== email_exists( $data['email'] ) ) {
			// Same work as a new registration (one password hash, one queued job): no timing difference.
			wp_hash_password( $data['password'] );
			AccountMail::queue(
				array(
					'kind'  => 'exists',
					'email' => $data['email'],
					'lang'  => $data['lang'],
				)
			);

			return;
		}

		AccountMail::queue(
			array(
				'kind'       => 'verify',
				'email'      => $data['email'],
				'first_name' => $data['first_name'],
				'last_name'  => $data['last_name'],
				'lang'       => $data['lang'],
				'token'      => $this->verification->createPending( $data ),
			)
		);
	}
}
