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

	/**
	 * Reassign post_author to match the original Squarespace authorId.
	 *
	 * Useful when a WP XML import collapsed all posts under a single user.
	 * Walks the live Squarespace blog JSON, finds each post's author, and
	 * sets post_author. Optionally creates WP users (role: Author) for any
	 * Squarespace authors who don't already have a matching WP user.
	 *
	 * ## OPTIONS
	 *
	 * [--survey]
	 * : Just list unique Squarespace authors and their matching WP users; don't change anything.
	 *
	 * [--limit=<n>]
	 * : Posts per batch. Default 200.
	 *
	 * [--create-users]
	 * : Auto-create matching WP users for Squarespace authors that don't have one.
	 *
	 * [--all]
	 * : Loop until done.
	 *
	 * ## EXAMPLES
	 *
	 *   wp sqs71 reassign-authors --survey
	 *   wp sqs71 reassign-authors --create-users --all
	 *
	 * @when after_wp_load
	 */
	public function reassign_authors( $args, $assoc_args ) {
		$settings = sqs71_to_gutenberg_get_settings();
		if ( empty( $settings['source_domain'] ) ) {
			\WP_CLI::error( 'source_domain not configured. Visit Tools → Squarespace → Gutenberg first.' );
		}
		$reassigner = new Author_Reassigner( $settings );

		if ( ! empty( $assoc_args['survey'] ) ) {
			$rows = $reassigner->survey( static function ( $m ) { \WP_CLI::log( $m ); } );
			$table = array_map( static function ( $r ) {
				return array(
					'posts'      => $r['posts'],
					'name'       => $r['name'],
					'sqs_id'     => $r['author_id'],
					'wp_user'    => $r['wp_user_login'] ?: '(no match)',
					'wp_user_id' => $r['wp_user_id'] ?: '-',
				);
			}, $rows );
			\WP_CLI\Utils\format_items( 'table', $table, array( 'posts', 'name', 'sqs_id', 'wp_user', 'wp_user_id' ) );
			return;
		}

		$limit  = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 200;
		$create = ! empty( $assoc_args['create-users'] );
		$all    = ! empty( $assoc_args['all'] );

		$totals = array( 'set' => 0, 'unchanged' => 0, 'missing' => 0, 'created_users' => 0, 'processed' => 0 );

		do {
			$res = $reassigner->run_batch( array(
				'limit'        => $limit,
				'create_users' => $create,
				'logger'       => static function ( $m ) { \WP_CLI::log( $m ); },
			) );
			foreach ( $totals as $k => $_ ) { $totals[ $k ] += $res[ $k ] ?? 0; }
			\WP_CLI::log( sprintf( '  batch: set=%d unchanged=%d missing=%d created_users=%d processed=%d',
				$res['set'], $res['unchanged'], $res['missing'], $res['created_users'], $res['processed'] ) );
			if ( $res['processed'] === 0 ) break;
		} while ( $all );

		\WP_CLI::success( sprintf(
			'Done. Total: set=%d unchanged=%d missing=%d created_users=%d processed=%d',
			$totals['set'], $totals['unchanged'], $totals['missing'], $totals['created_users'], $totals['processed']
		) );
	}
}
