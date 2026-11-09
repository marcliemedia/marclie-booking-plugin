<?php
/**
 * Deactivation: unschedule cron only. Data, settings and roles stay.
 */

namespace Marclie\Booking\Install;

use Marclie\Booking\Cron;

defined( 'ABSPATH' ) || exit;

final class Deactivator {

	public static function deactivate(): void {
		Cron::clear();
	}
}
