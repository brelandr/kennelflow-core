<?php
/**
 * WP-CLI: KennelFlow maintenance commands.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'WP_CLI' ) || exit;

/**
 * Class CliCleanupCommand
 */
class CliCleanupCommand {

	/**
	 * Run abandoned booking garbage collection (pending_payment older than 2 hours).
	 *
	 * ## EXAMPLES
	 *
	 *     wp kf-cleanup run
	 *
	 * @return void
	 */
	public function run() {
		$n = GarbageCollection::run();
		\WP_CLI::success(
			sprintf(
				/* translators: %d: number of bookings removed */
				_n(
					'Removed %d abandoned booking.',
					'Removed %d abandoned bookings.',
					$n,
					'kennelflow-core'
				),
				$n
			)
		);
	}
}
