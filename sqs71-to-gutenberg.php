<?php
/**
 * Plugin Name:       Squarespace 7.1 → Gutenberg Converter
 * Plugin URI:        https://github.com/example/sqs71-to-gutenberg
 * Description:       Re-scrapes the live Squarespace 7.1 source site and rewrites WordPress post content as proper Gutenberg block markup (wp:image, wp:gallery, wp:paragraph, wp:heading, wp:embed, etc.). Designed for migrations where the standard XML import drops gallery structure or leaves Squarespace HTML soup behind.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Walkabout Chronicles migration
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sqs71-to-gutenberg
 *
 * @package Sqs71ToGutenberg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SQS71_TO_GUTENBERG_VERSION', '0.1.0' );
define( 'SQS71_TO_GUTENBERG_FILE', __FILE__ );
define( 'SQS71_TO_GUTENBERG_PATH', plugin_dir_path( __FILE__ ) );
define( 'SQS71_TO_GUTENBERG_URL', plugin_dir_url( __FILE__ ) );
define( 'SQS71_TO_GUTENBERG_OPTION', 'sqs71_to_gutenberg_settings' );
define( 'SQS71_TO_GUTENBERG_LOG_OPTION', 'sqs71_to_gutenberg_log' );
define( 'SQS71_TO_GUTENBERG_CONVERTED_META', '_sqs71_converted_at' );

require_once SQS71_TO_GUTENBERG_PATH . 'includes/class-content-detector.php';
require_once SQS71_TO_GUTENBERG_PATH . 'includes/class-source-fetcher.php';
require_once SQS71_TO_GUTENBERG_PATH . 'includes/class-block-parser.php';
require_once SQS71_TO_GUTENBERG_PATH . 'includes/class-media-importer.php';
require_once SQS71_TO_GUTENBERG_PATH . 'includes/class-block-emitter.php';
require_once SQS71_TO_GUTENBERG_PATH . 'includes/class-post-rewriter.php';
require_once SQS71_TO_GUTENBERG_PATH . 'includes/class-archive-index.php';
require_once SQS71_TO_GUTENBERG_PATH . 'includes/class-featured-image-setter.php';
require_once SQS71_TO_GUTENBERG_PATH . 'includes/class-author-reassigner.php';
require_once SQS71_TO_GUTENBERG_PATH . 'includes/class-discoverer.php';
require_once SQS71_TO_GUTENBERG_PATH . 'admin/class-tools-page.php';

add_action(
	'plugins_loaded',
	static function () {
		( new \Sqs71ToGutenberg\Admin\Tools_Page() )->hook();

		// Optional WP-CLI command — gives third parties a way to run batches from the shell.
		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			require_once SQS71_TO_GUTENBERG_PATH . 'includes/class-cli.php';
			\WP_CLI::add_command( 'sqs71', \Sqs71ToGutenberg\CLI::class );
		}
	}
);

/**
 * Default settings used when the plugin is first installed.
 *
 * @return array<string,mixed>
 */
function sqs71_to_gutenberg_default_settings() {
	return array(
		'source_domain'     => '',
		'url_pattern'       => '/{year}/{month}/{day}/{slug}',
		'batch_size'        => 5,
		'dry_run'           => true,
		'force_reconvert'   => false,
		'date_offset_days'  => 0,
		'image_quality'     => '2500w',
		'concurrency'       => 1,
		'request_timeout'   => 30,
	);
}

/**
 * Read and merge plugin settings.
 *
 * @return array<string,mixed>
 */
function sqs71_to_gutenberg_get_settings() {
	$saved = get_option( SQS71_TO_GUTENBERG_OPTION, array() );

	return wp_parse_args( is_array( $saved ) ? $saved : array(), sqs71_to_gutenberg_default_settings() );
}
