<?php
/**
 * Orchestrates per-post conversion.
 *
 * @package Sqs71ToGutenberg
 */

namespace Sqs71ToGutenberg;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Post_Rewriter {

	/** @var array<string,mixed> */
	private $settings;

	/** @var Source_Fetcher */
	private $fetcher;

	/** @var Block_Parser */
	private $parser;

	/** @var Media_Importer */
	private $media;

	/** @var Block_Emitter */
	private $emitter;

	/** @var Content_Detector */
	private $detector;

	public function __construct( array $settings ) {
		$this->settings = $settings;
		$this->fetcher  = new Source_Fetcher( $settings );
		$this->parser   = new Block_Parser();
		$this->media    = new Media_Importer( $settings );
		$this->emitter  = new Block_Emitter( $this->media );
		$this->emitter->set_dry_run( ! empty( $settings['dry_run'] ) );
		$this->detector = new Content_Detector();
	}

	/**
	 * Convert a single post.
	 *
	 * @return array<string,mixed> Result summary suitable for logging.
	 */
	public function convert_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'id' => $post_id, 'status' => 'error', 'error' => 'post not found' );
		}

		$force = ! empty( $this->settings['force_reconvert'] );

		// Skip already-converted posts (this plugin's own meta marker) unless forced.
		if ( ! $force && get_post_meta( $post_id, SQS71_TO_GUTENBERG_CONVERTED_META, true ) ) {
			return array( 'id' => $post_id, 'slug' => $post->post_name, 'status' => 'skipped', 'reason' => 'already-converted-by-plugin' );
		}

		// Inspect content and decide what to do.
		$verdict = $this->detector->classify( $post->post_content );

		if ( ! $force && ! $verdict['should_convert'] ) {
			return array(
				'id'             => $post_id,
				'slug'           => $post->post_name,
				'status'         => 'skipped',
				'reason'         => $verdict['classification'],
				'detail'         => $verdict['reason'],
				'markers'        => $verdict['markers'],
			);
		}

		$candidates = $this->fetcher->build_url_candidates( $post );
		$fetched    = $this->fetcher->fetch_first( $candidates );

		if ( is_wp_error( $fetched ) ) {
			return array(
				'id'         => $post_id,
				'slug'       => $post->post_name,
				'status'     => 'error',
				'error'      => $fetched->get_error_message(),
				'candidates' => $candidates,
			);
		}

		$ast = $this->parser->parse( $fetched['html'] );
		if ( ! $ast ) {
			return array(
				'id'     => $post_id,
				'slug'   => $post->post_name,
				'source' => $fetched['url'],
				'status' => 'error',
				'error'  => 'no parseable content found at source URL',
			);
		}

		$new_content = $this->emitter->emit( $ast, $post_id );
		$stats       = $this->emitter->get_stats();

		if ( ! empty( $this->settings['dry_run'] ) ) {
			return array(
				'id'              => $post_id,
				'slug'            => $post->post_name,
				'source'          => $fetched['url'],
				'status'          => 'dry-run',
				'blocks'          => count( $ast ),
				'block_types'     => $this->summarize_block_types( $ast ),
				'images_uploaded' => $stats['uploaded'],
				'images_failed'   => $stats['failed'],
				'errors'          => $stats['errors'],
				'preview_head'    => mb_substr( $new_content, 0, 600 ),
			);
		}

		// Save.
		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $new_content,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'id'     => $post_id,
				'slug'   => $post->post_name,
				'source' => $fetched['url'],
				'status' => 'error',
				'error'  => $result->get_error_message(),
			);
		}

		update_post_meta( $post_id, SQS71_TO_GUTENBERG_CONVERTED_META, current_time( 'mysql' ) );
		update_post_meta( $post_id, '_sqs71_source_url', esc_url_raw( $fetched['url'] ) );

		return array(
			'id'              => $post_id,
			'slug'            => $post->post_name,
			'source'          => $fetched['url'],
			'status'          => 'converted',
			'blocks'          => count( $ast ),
			'block_types'     => $this->summarize_block_types( $ast ),
			'images_uploaded' => $stats['uploaded'],
			'images_failed'   => $stats['failed'],
			'errors'          => $stats['errors'],
		);
	}

	/**
	 * Run a batch of posts.
	 *
	 * @param int[] $post_ids
	 * @return array<int,array<string,mixed>>
	 */
	public function convert_batch( array $post_ids ) {
		$results = array();
		foreach ( $post_ids as $id ) {
			$results[] = $this->convert_post( (int) $id );
		}
		return $results;
	}

	private function summarize_block_types( array $ast ) {
		$counts = array();
		foreach ( $ast as $b ) {
			$t = $b['type'] ?? 'unknown';
			$counts[ $t ] = ( $counts[ $t ] ?? 0 ) + 1;
		}
		return $counts;
	}
}
