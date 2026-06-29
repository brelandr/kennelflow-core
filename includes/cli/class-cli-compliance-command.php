<?php
/**
 * WP-CLI: KennelFlow compliance / retention commands.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;
defined( 'WP_CLI' ) || exit;

/**
 * Class CliComplianceCommand
 */
class CliComplianceCommand {

	/**
	 * Run daily retention logic: archive medical records past the compliance retention period when Auto-Archive is enabled.
	 *
	 * ## EXAMPLES
	 *
	 *     wp kf-compliance run-retention
	 *
	 * @return void
	 */
	public function run_retention() {
		$n = ComplianceRetention::run();
		\WP_CLI::success(
			sprintf(
				/* translators: %d: number of rows updated */
				_n(
					'Archived %d medical record.',
					'Archived %d medical records.',
					$n,
					'kennelflow-core'
				),
				$n
			)
		);
	}
}
