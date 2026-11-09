<?php
/**
 * Base domain exception carrying a machine error code + context.
 */

namespace Marclie\Booking\Domain\Exception;

defined( 'ABSPATH' ) || exit;

abstract class BookingException extends \RuntimeException {

	/** @var array<string, mixed> */
	private array $context;

	/**
	 * @param array<string, mixed> $context Extra data for API/UI (e.g. required nights).
	 */
	public function __construct( string $message = '', array $context = array(), ?\Throwable $previous = null ) {
		parent::__construct( '' !== $message ? $message : static::CODE, 0, $previous );
		$this->context = $context;
	}

	abstract public function errorCode(): string;

	/** @return array<string, mixed> */
	public function context(): array {
		return $this->context;
	}
}
