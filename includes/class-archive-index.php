<?php
/**
 * Archive_Index — walks the Squarespace blog JSON feed (?format=json-pretty)
 * and produces a slug-keyed map of posts containing the cover-image URL,
 * source filename, full URL and title.
 *
 * Used by the title-search resolver and the featured-image setter to find
 * the canonical live URL for any post by slug or title.
 *
 * The result is cached in a transient so subsequent calls in the same hour
 * skip the (expensive) full-archive walk.
 *
 * @package Sqs71ToGutenberg
 */

namespace Sqs71ToGutenberg;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Archive_Index {

	const TRANSIENT = 'sqs71_archive_index_v2';

	/** @var array<string,mixed> */
	private $settings;

	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Get the index. Pass force=true to bypass the cache and re-walk.
	 *
	 * @return array<string,array{title:string,url:string,asset:string,fname:string}>
	 */
	public function get( $force = false, $logger = null ) {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) && $cached ) {
				if ( $logger ) $logger( 'Archive_Index loaded from cache (' . count( $cached ) . ' entries).' );
				return $cached;
			}
		}

		$index = $this->build( $logger );
		set_transient( self::TRANSIENT, $index, HOUR_IN_SECONDS );
		return $index;
	}

	private function build( $logger = null ) {
		$source = untrailingslashit( $this->settings['source_domain'] ?? '' );
		if ( ! $source ) {
			if ( $logger ) $logger( 'Archive_Index: source_domain not configured' );
			return array();
		}

		// We don't know the blog URL prefix without configuration. The plugin
		// settings include a url_pattern; derive the blog "section" prefix from
		// the longest common prefix that ends in a non-placeholder segment.
		$pattern = $this->settings['url_pattern'] ?? '/{year}/{month}/{day}/{slug}';
		$blog_prefix = preg_replace( '#/\{[^}]+\}.*#', '', $pattern );
		if ( $blog_prefix === '' ) {
			$blog_prefix = ''; // archive lives at site root
		}

		$index = array();
		$offset = 0;
		$page = 0;
		$max_pages = 200;

		while ( $page < $max_pages ) {
			$url = $source . $blog_prefix . '?format=json-pretty' . ( $offset ? '&offset=' . $offset : '' );
			$resp = wp_remote_get( $url, array( 'timeout' => (int) ( $this->settings['request_timeout'] ?? 25 ) ) );
			if ( is_wp_error( $resp ) ) {
				if ( $logger ) $logger( 'Archive_Index fetch fail: ' . $resp->get_error_message() );
				break;
			}
			if ( wp_remote_retrieve_response_code( $resp ) !== 200 ) break;
			$json = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( ! $json || empty( $json['items'] ) ) break;

			foreach ( $json['items'] as $it ) {
				$urlId = $it['urlId'] ?? null;
				if ( ! $urlId ) continue;
				$slug = basename( $urlId );
				$index[ $slug ] = array(
					'title' => $it['title']    ?? '',
					'url'   => $source . ( $it['fullUrl'] ?? '' ),
					'asset' => $it['assetUrl'] ?? '',
					'fname' => $it['filename'] ?? '',
				);
			}

			if ( $logger ) $logger( "Archive_Index page $page offset=$offset got " . count( $json['items'] ) . " (total " . count( $index ) . ")" );

			$pag = $json['pagination'] ?? array();
			$next = $pag['nextPageOffset'] ?? ( $pag['nextPage'] ?? null );
			if ( ! $next && ! empty( $json['items'] ) ) {
				$oldest = end( $json['items'] );
				$next = $oldest['addedOn'] ?? null;
			}
			if ( ! $next || $next == $offset ) break;
			$offset = $next;
			$page++;
		}

		if ( $logger ) $logger( 'Archive_Index built: ' . count( $index ) . ' entries.' );
		return $index;
	}
}
