<?php
/**
 * Thin $wpdb helpers shared by the repositories: table names, (nestable)
 * transactions, error classification (MySQL and the SQLite integration),
 * write checks and UTC timestamps.
 */

namespace Marclie\Booking\Repository;

use Marclie\Booking\Install\Schema;

defined( 'ABSPATH' ) || exit;

final class Db {

	public const ERROR_DUPLICATE = 'duplicate';
	public const ERROR_LOCK      = 'lock';
	public const ERROR_OTHER     = 'other';

	private const MYSQL_DUPLICATE = 1062;
	private const MYSQL_DEADLOCK  = 1213;
	private const MYSQL_LOCK_WAIT = 1205;

	private static int $depth = 0;

	/** @var list<callable(): void> Callbacks waiting for the outermost COMMIT. */
	private static array $afterCommit = array();

	public static function table( string $name ): string {
		return Schema::table( $name );
	}

	/** Whether $wpdb talks to MySQL/MariaDB (otherwise the SQLite integration). */
	public static function isMysql(): bool {
		global $wpdb;

		return isset( $wpdb->dbh ) && $wpdb->dbh instanceof \mysqli;
	}

	public static function inTransaction(): bool {
		return self::$depth > 0;
	}

	/**
	 * Runs `$fn` once the current transaction has committed (dropped on
	 * rollback); immediately when no transaction is open.
	 *
	 * @param callable(): void $fn
	 */
	public static function afterCommit( callable $fn ): void {
		if ( self::$depth > 0 ) {
			self::$afterCommit[] = $fn;
			return;
		}

		$fn();
	}

	/**
	 * Runs `$fn` inside START TRANSACTION … COMMIT; rolls back and rethrows on any
	 * exception. Nested calls join the outer transaction (the outermost one commits
	 * or rolls back).
	 *
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	public static function transaction( callable $fn ) {
		if ( self::$depth > 0 ) {
			return $fn();
		}

		self::run( 'START TRANSACTION' );
		self::$depth = 1;
		try {
			$result = $fn();
		} catch ( \Throwable $e ) {
			self::$depth       = 0;
			self::$afterCommit = array();
			self::run( 'ROLLBACK' );
			throw $e;
		}
		self::$depth = 0;
		if ( false === self::run( 'COMMIT' ) ) {
			self::$afterCommit = array();
			throw new \RuntimeException( self::message( 'Commit failed' ) );
		}

		$callbacks         = self::$afterCommit;
		self::$afterCommit = array();
		foreach ( $callbacks as $callback ) {
			$callback();
		}

		return $result;
	}

	/**
	 * Runs a top-level transaction, retrying it once when it failed on a lock
	 * conflict (MySQL deadlock / lock wait timeout, SQLite busy).
	 *
	 * @template T
	 * @param callable(): T $fn Must start its own Db::transaction().
	 * @return T
	 * @throws LockConflictException When the retry also hits a lock conflict.
	 */
	public static function retryOnLock( callable $fn ) {
		try {
			return $fn();
		} catch ( LockConflictException $e ) {
			if ( self::inTransaction() ) {
				throw $e;
			}
			usleep( random_int( 20000, 80000 ) );

			return $fn();
		}
	}

	/**
	 * Executes a query with error output suppressed. Returns false on error;
	 * classify it right away with errorKind().
	 *
	 * @return int|bool
	 */
	public static function quiet( string $sql ) {
		global $wpdb;

		$wpdb->last_error = '';
		$suppress         = $wpdb->suppress_errors( true );
		$result           = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- callers prepare.
		$wpdb->suppress_errors( $suppress );

		return $result;
	}

	/**
	 * Classifies the last failed statement. Call before any other query.
	 *
	 * @return self::ERROR_*
	 */
	public static function errorKind(): string {
		global $wpdb;

		if ( self::isMysql() ) {
			$errno = mysqli_errno( $wpdb->dbh );
			if ( self::MYSQL_DUPLICATE === $errno ) {
				return self::ERROR_DUPLICATE;
			}
			if ( self::MYSQL_DEADLOCK === $errno || self::MYSQL_LOCK_WAIT === $errno ) {
				return self::ERROR_LOCK;
			}

			return self::ERROR_OTHER;
		}

		$error = (string) $wpdb->last_error;
		if ( preg_match( '/UNIQUE constraint failed/i', $error ) ) {
			return self::ERROR_DUPLICATE;
		}
		if ( preg_match( '/database (table )?is locked|SQLITE_BUSY/i', $error ) ) {
			return self::ERROR_LOCK;
		}

		return self::ERROR_OTHER;
	}

	/**
	 * Throws unless a $wpdb write succeeded (false = SQL error). Use for every
	 * write inside a transaction so the error triggers the rollback.
	 *
	 * @param int|bool $result
	 * @return int|bool The result, for chaining.
	 */
	public static function check( $result, string $what ) {
		if ( false === $result ) {
			throw new \RuntimeException( self::message( $what . ' failed' ) );
		}

		return $result;
	}

	/**
	 * Exception-safe message: `$context` plus the sanitized last error (tags
	 * stripped, 200 chars). The full error is logged only when WP_DEBUG is on.
	 */
	public static function message( string $context ): string {
		global $wpdb;

		$error = (string) $wpdb->last_error;
		if ( '' === $error ) {
			return $context . '.';
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[marclie-booking] ' . $context . ': ' . $error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		$clean = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $error ) ) );

		return $context . ': ' . ( strlen( $clean ) > 200 ? substr( $clean, 0, 200 ) . '…' : $clean );
	}

	/** Current time as DATETIME in UTC (system timestamps are UTC, see Schema). */
	public static function nowUtc(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	public static function utc( \DateTimeInterface $at ): string {
		return ( new \DateTimeImmutable( '@' . $at->getTimestamp() ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Builds `%d, %d, …` placeholders for an IN() list.
	 *
	 * @param array<int|string> $values
	 */
	public static function placeholders( array $values, string $format = '%d' ): string {
		return implode( ', ', array_fill( 0, count( $values ), $format ) );
	}

	/**
	 * Atomic named lock (a wp_options row): a plain INSERT on the UNIQUE
	 * option_name claims it; a held lease past its expiry is taken over with a
	 * compare-and-swap UPDATE. Returns the claim token (pass it to releaseLock),
	 * or null while another process holds the lock.
	 */
	public static function claimLock( string $name, int $ttlSeconds ): ?string {
		global $wpdb;

		$token = ( time() + $ttlSeconds ) . ':' . bin2hex( random_bytes( 8 ) );
		if ( false !== self::quiet( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $token ) ) ) {
			return $token;
		}
		if ( self::ERROR_DUPLICATE !== self::errorKind() ) {
			return null;
		}

		$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( '' !== $held && (int) strtok( $held, ':' ) >= time() ) {
			return null;
		}
		$swapped = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, $name, $held ) );

		return 1 === (int) $swapped ? $token : null;
	}

	/** Releases a lock claimed with claimLock() — only while this claim still owns it. */
	public static function releaseLock( string $name, string $token ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $token ) );
	}

	/** @return int|bool */
	private static function run( string $sql ) {
		global $wpdb;

		return $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static statement.
	}
}
