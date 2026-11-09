<?php
/**
 * A statement failed on a transient lock conflict (MySQL 1213/1205, SQLite busy);
 * the enclosing top-level transaction may be retried (Db::retryOnLock()).
 */

namespace Marclie\Booking\Repository;

defined( 'ABSPATH' ) || exit;

final class LockConflictException extends \RuntimeException {}
