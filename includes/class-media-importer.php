<?php
/**
 * Imports remote images into the WordPress media library.
 *
 * Caches by source URL within a single run so the same Squarespace asset
 * doesn't get downloaded twice in one batch.
 *
 * @package Sqs71ToGutenberg
 */

namespace Sqs71ToGutenberg;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Media_Importer {

	/** @var array<string,mixed> */
	private $settings;

	/** @var array<string,array{id:int,url:string}> */
	private $cache = array();

	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Sideload an image and return its attachment ID + final URL, or WP_Error.
	 *
	 * @param string $remote_url   The image URL on the source CDN.
	 * @param int    $parent_post  Optional post to attach to.
	 * @param string $alt          Optional alt text to set on the attachment.
	 *
	 * @return array{id:int,url:string}|\WP_Error
	 */
	public function import( $remote_url, $parent_post = 0, $alt = '' ) {
		$key = $this->cache_key( $remote_url );
		if ( isset( $this->cache[ $key ] ) ) {
			return $this->cache[ $key ];
		}

		// Pre-existing local? Resolve to attachment if we already imported in a previous run.
		$existing = $this->find_existing_by_source( $remote_url );
		if ( $existing ) {
			$this->cache[ $key ] = $existing;
			return $existing;
		}

		// Always request a high-quality variant from Squarespace CDN.
		$fetch_url = $this->ensure_format( $remote_url, $this->settings['image_quality'] ?? '2500w' );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $fetch_url, (int) ( $this->settings['request_timeout'] ?? 30 ) );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$file_array = array(
			'name'     => $this->guess_filename( $remote_url ),
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file_array, $parent_post );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return $attachment_id;
		}

		// Track the original source URL so future runs (or other tools) can dedupe.
		update_post_meta( $attachment_id, '_sqs71_source_url', esc_url_raw( $remote_url ) );

		if ( $alt !== '' ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}

		$result = array(
			'id'  => (int) $attachment_id,
			'url' => wp_get_attachment_url( $attachment_id ),
		);

		$this->cache[ $key ] = $result;
		return $result;
	}

	/**
	 * Look for an existing media library entry that we previously imported
	 * from this source URL.
	 *
	 * @return array{id:int,url:string}|null
	 */
	private function find_existing_by_source( $remote_url ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'   => '_sqs71_source_url',
					'value' => esc_url_raw( $remote_url ),
				),
			),
			'no_found_rows'  => true,
		);
		$q = new \WP_Query( $args );
		if ( $q->have_posts() ) {
			$id = $q->posts[0];
			return array( 'id' => (int) $id, 'url' => wp_get_attachment_url( $id ) );
		}
		return null;
	}

	private function cache_key( $url ) {
		// Normalize Squarespace URLs by stripping the format= parameter so the
		// same image at different sizes resolves to one attachment.
		$parts = wp_parse_url( $url );
		if ( isset( $parts['query'] ) ) {
			parse_str( $parts['query'], $q );
			unset( $q['format'] );
			$parts['query'] = http_build_query( $q );
		}
		return ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( $parts['path'] ?? '' ) . ( ! empty( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}

	private function ensure_format( $url, $size ) {
		if ( strpos( $url, 'squarespace-cdn.com' ) === false ) {
			return $url;
		}
		$sep = strpos( $url, '?' ) === false ? '?' : '&';
		if ( strpos( $url, 'format=' ) !== false ) {
			return $url;
		}
		return $url . $sep . 'format=' . rawurlencode( $size );
	}

	private function guess_filename( $url ) {
		$parts = wp_parse_url( $url );
		$path  = $parts['path'] ?? '';
		$name  = basename( $path );
		$name  = preg_replace( '/[^a-zA-Z0-9._-]/', '_', $name );
		if ( $name === '' ) {
			$name = 'image-' . substr( md5( $url ), 0, 10 );
		}
		// Make sure it has an extension; default to .jpg.
		if ( strpos( $name, '.' ) === false ) {
			$name .= '.jpg';
		}
		return $name;
	}
}
