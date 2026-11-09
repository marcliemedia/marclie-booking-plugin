<?php
/**
 * Domain error 'validation': invalid input; context `fields` maps field => message.
 */

namespace Marclie\Booking\Domain\Exception;

defined( 'ABSPATH' ) || exit;

final class ValidationException extends BookingException {

	public const CODE = 'validation';

	/**
	 * @param array<string, string> $fields Field name => message.
	 */
	public static function fields( array $fields, string $message = 'Invalid data.' ): self {
		return new self( $message, array( 'fields' => $fields ) );
	}

	/** @return array<string, string> */
	public function fieldErrors(): array {
		return (array) ( $this->context()['fields'] ?? array() );
	}

	public function errorCode(): string {
		return self::CODE;
	}
}
