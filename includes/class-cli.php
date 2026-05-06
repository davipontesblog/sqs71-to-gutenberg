<?php
/**
 * WP-CLI command for batch conversion. Optional but handy for large runs.
 *
 * @package Sqs71ToGutenberg
 */

namespace Sqs71ToGutenberg;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLI {

	/**
	 * Convert one or more posts.
	 *
	 * ## OPTIONS
	 *
	 * [--slugs=<slugs>]
	 * : Comma-separated post slugs.
	 *
	 * [--all]
	 * : Process all unconverted posts (respects batch-size).
	 *
	 * [--batch-size=<n>]
	 * : Override batch size.
	 *
	 * [--dry-run]
	 * : Don't write changes.
	 *
	 * [--force]
	 * : Re-convert already-converted posts.
	 *
	 * ## EXAMPLES
	 *
	 *   wp sqs71 convert --slugs=routines,the-shifting
	 *   wp sqs71 convert --all --batch-size=20
	 *
	 * @when after_wp_load
	 */
	public function convert( $args, $assoc_args ) {
		$settings = sqs71_to_gutenberg_get_settings();
		if ( empty( $settings['source_domain'] ) ) {
			\WP_CLI::error( 'source_domain is not configured. Visit Tools → Squarespace → Gutenberg first.' );
		}

		if ( isset( $assoc_args['batch-size'] ) ) {
			$settings['batch_size'] = max( 1, (int) $assoc_args['batch-size'] );
		}
		$settings['dry_run']         = ! empty( $assoc_args['dry-run'] );
		$settings['force_reconvert'] = ! empty( $assoc_args['force'] );

		$post_ids = array();

		if ( ! empty( $assoc_args['slugs'] ) ) {
			$slugs = array_filter( array_map( 'trim', explode( ',', $assoc_args['slugs'] ) ) );
			foreach ( $slugs as $slug ) {
				$found = get_posts(
					array(
						'name'           => $slug,
						'post_type'      => 'post',
						'post_status'    => 'any',
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'no_found_rows'  => true,
					)
				);
				if ( $found ) {
					$post_ids[] = (int) $found[0];
				} else {
					\WP_CLI::warning( "Slug not found: $slug" );
				}
			}
		} elseif ( ! empty( $assoc_args['all'] ) ) {
			$args = array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => (int) $settings['batch_size'],
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			);
			if ( empty( $settings['force_reconvert'] ) ) {
				$args['meta_query'] = array(
					array(
						'key'     => SQS71_TO_GUTENBERG_CONVERTED_META,
						'compare' => 'NOT EXISTS',
					),
				);
			}
			$post_ids = get_posts( $args );
		} else {
			\WP_CLI::error( 'Pass --slugs=... or --all.' );
		}

		if ( ! $post_ids ) {
			\WP_CLI::success( 'Nothing to do.' );
			return;
		}

		$rewriter = new Post_Rewriter( $settings );

		$progress = \WP_CLI\Utils\make_progress_bar( 'Converting', count( $post_ids ) );

		foreach ( $post_ids as $id ) {
			$res = $rewriter->convert_post( (int) $id );
			\WP_CLI::log( sprintf( '[%s] #%d %s — %s', $res['status'], $res['id'], $res['slug'] ?? '', $res['source'] ?? ( $res['error'] ?? '' ) ) );
			$progress->tick();
		}

		$progress->finish();
		\WP_CLI::success( 'Batch complete.' );
	}
}
