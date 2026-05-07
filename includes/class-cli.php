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

	/**
	 * Set _thumbnail_id on posts using the Squarespace assetUrl (cover image).
	 *
	 * Useful when an XML import dropped featured-image links. Walks the live
	 * Squarespace blog JSON, matches each post by slug, and sets the WP
	 * featured image from the corresponding attachment in the Media Library
	 * (sideloads the image if missing).
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : How many posts to process per invocation. Default 100.
	 *
	 * [--retry]
	 * : Reprocess posts that were processed but didn't get a thumbnail.
	 *
	 * [--all]
	 * : Loop until all eligible posts have been processed.
	 *
	 * ## EXAMPLES
	 *
	 *   wp sqs71 set-featured --limit=50
	 *   wp sqs71 set-featured --all
	 *   wp sqs71 set-featured --retry --limit=200
	 *
	 * @when after_wp_load
	 */
	public function set_featured( $args, $assoc_args ) {
		$settings = sqs71_to_gutenberg_get_settings();
		if ( empty( $settings['source_domain'] ) ) {
			\WP_CLI::error( 'source_domain not configured. Visit Tools → Squarespace → Gutenberg first.' );
		}

		$limit = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 100;
		$retry = ! empty( $assoc_args['retry'] );
		$all   = ! empty( $assoc_args['all'] );

		$setter = new Featured_Image_Setter( $settings );

		$totals = array( 'set' => 0, 'imported' => 0, 'missing' => 0, 'errors' => 0, 'processed' => 0 );

		do {
			$res = $setter->run_batch( array(
				'limit'  => $limit,
				'retry'  => $retry,
				'logger' => static function ( $m ) { \WP_CLI::log( $m ); },
			) );
			foreach ( $totals as $k => $_ ) { $totals[ $k ] += $res[ $k ] ?? 0; }
			\WP_CLI::log( sprintf( '  batch: set=%d imported=%d missing=%d errors=%d', $res['set'], $res['imported'], $res['missing'], $res['errors'] ) );
			if ( $res['processed'] === 0 ) break;
		} while ( $all );

		\WP_CLI::success( sprintf(
			'Done. Total: set=%d imported=%d missing=%d errors=%d processed=%d',
			$totals['set'], $totals['imported'], $totals['missing'], $totals['errors'], $totals['processed']
		) );
	}
}
