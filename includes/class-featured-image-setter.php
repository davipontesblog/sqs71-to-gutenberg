<?php
/**
 * Featured_Image_Setter — sets each WP post's _thumbnail_id from the
 * cover image (assetUrl) reported by the Squarespace blog JSON.
 *
 * Useful when an XML import dropped featured images. Looks up each post by
 * slug in the Archive_Index, finds the matching attachment in the Media
 * Library (sideloads it if missing), and writes _thumbnail_id.
 *
 * Falls back to _sqs71_resolved_url meta for posts whose WP slug doesn't
 * match the live site (typical with -2/-3 numeric-suffix duplicate-import
 * artifacts).
 *
 * @package Sqs71ToGutenberg
 */

namespace Sqs71ToGutenberg;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Featured_Image_Setter {

	const DONE_META = '_sqs71_featured_set';

	/** @var array<string,mixed> */
	private $settings;

	/** @var Archive_Index */
	private $archive;

	/** @var Media_Importer */
	private $media;

	public function __construct( array $settings ) {
		$this->settings = $settings;
		$this->archive  = new Archive_Index( $settings );
		$this->media    = new Media_Importer( $settings );
	}

	/**
	 * Run a batch.
	 *
	 * @param array{
	 *   limit?:int,
	 *   retry?:bool,
	 *   force?:bool,
	 *   logger?:callable
	 * } $opts
	 *
	 * @return array{set:int,imported:int,missing:int,errors:int,processed:int}
	 */
	public function run_batch( $opts = array() ) {
		$limit  = max( 1, (int) ( $opts['limit'] ?? 100 ) );
		$retry  = ! empty( $opts['retry'] );
		$logger = $opts['logger'] ?? static function ( $m ) { /* noop */ };

		$index = $this->archive->get( ! empty( $opts['force_index'] ), $logger );
		if ( ! $index ) {
			$logger( 'Featured_Image_Setter: archive index empty.' );
			return array( 'set' => 0, 'imported' => 0, 'missing' => 0, 'errors' => 0, 'processed' => 0 );
		}

		$post_ids = $this->select_posts( $limit, $retry );
		$logger( 'Found ' . count( $post_ids ) . ' posts to process' . ( $retry ? ' (retry mode)' : '' ) );

		$out = array( 'set' => 0, 'imported' => 0, 'missing' => 0, 'errors' => 0, 'processed' => 0 );

		foreach ( $post_ids as $pid ) {
			$out['processed']++;
			$post = get_post( $pid );
			if ( ! $post ) continue;

			$entry = $index[ $post->post_name ] ?? null;
			if ( ! $entry ) {
				// Fallback: resolved URL (set by the title-search resolver) gives
				// us the live-site slug for posts that have a different WP slug.
				$resolved = get_post_meta( $pid, '_sqs71_resolved_url', true );
				if ( $resolved ) {
					$path = parse_url( $resolved, PHP_URL_PATH );
					if ( $path ) {
						$alt = trim( basename( rtrim( $path, '/' ) ) );
						if ( $alt && isset( $index[ $alt ] ) ) {
							$entry = $index[ $alt ];
							$logger( "  #$pid {$post->post_name} — using resolved slug \"$alt\"" );
						}
					}
				}
			}

			if ( ! $entry || empty( $entry['asset'] ) ) {
				$out['missing']++;
				update_post_meta( $pid, self::DONE_META, 'no-asset' );
				$logger( "  #$pid {$post->post_name} — no assetUrl in archive index" );
				continue;
			}

			$attachment_id = $this->find_attachment( $entry['asset'], $entry['fname'] );

			if ( ! $attachment_id ) {
				$res = $this->media->import( $entry['asset'], $pid, $entry['title'] ?: $post->post_title );
				if ( is_wp_error( $res ) ) {
					$out['errors']++;
					update_post_meta( $pid, self::DONE_META, 'import-failed' );
					$logger( "  #$pid {$post->post_name} — import failed: " . $res->get_error_message() );
					continue;
				}
				$attachment_id = (int) $res['id'];
				$out['imported']++;
			}

			update_post_meta( $pid, '_thumbnail_id', $attachment_id );
			update_post_meta( $pid, self::DONE_META, 'set' );
			$out['set']++;
		}

		return $out;
	}

	private function select_posts( $limit, $retry ) {
		global $wpdb;

		if ( $retry ) {
			// Posts that lack _thumbnail_id but have been processed before.
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id'
				 WHERE p.post_type = 'post' AND p.post_status = 'publish'
				   AND pm.meta_id IS NULL
				 ORDER BY p.post_date DESC LIMIT %d",
				$limit
			) );
			if ( $ids ) {
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$wpdb->query( $wpdb->prepare(
					"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ($placeholders)",
					array_merge( array( self::DONE_META ), $ids )
				) );
			}
			return array_map( 'intval', $ids );
		}

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm_t ON pm_t.post_id = p.ID AND pm_t.meta_key = '_thumbnail_id'
			 LEFT JOIN {$wpdb->postmeta} pm_d ON pm_d.post_id = p.ID AND pm_d.meta_key = %s
			 WHERE p.post_type = 'post' AND p.post_status = 'publish'
			   AND pm_t.meta_id IS NULL
			   AND pm_d.meta_id IS NULL
			 ORDER BY p.post_date DESC LIMIT %d",
			self::DONE_META,
			$limit
		) ) );
	}

	private function find_attachment( $asset_url, $filename ) {
		global $wpdb;

		// 1) Match on _sqs71_source_url meta.
		$candidates = array_unique( array(
			$asset_url,
			preg_replace( '/\?.*$/', '', $asset_url ),
		) );
		foreach ( $candidates as $candidate ) {
			$id = $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sqs71_source_url' AND meta_value = %s LIMIT 1",
				$candidate
			) );
			if ( $id ) return (int) $id;
		}
		// 2) LIKE on the path.
		$path = parse_url( $asset_url, PHP_URL_PATH );
		if ( $path ) {
			$id = $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sqs71_source_url' AND meta_value LIKE %s LIMIT 1",
				'%' . $wpdb->esc_like( $path ) . '%'
			) );
			if ( $id ) return (int) $id;
		}
		// 3) Filename match against _wp_attached_file.
		if ( $filename ) {
			$base = pathinfo( $filename, PATHINFO_FILENAME );
			$id = $wpdb->get_var( $wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm
				 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_wp_attached_file' AND pm.meta_value LIKE %s
				   AND p.post_type = 'attachment' LIMIT 1",
				'%/' . $wpdb->esc_like( $base ) . '.%'
			) );
			if ( $id ) return (int) $id;
		}
		return 0;
	}
}
