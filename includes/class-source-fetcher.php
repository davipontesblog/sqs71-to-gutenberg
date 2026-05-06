<?php
/**
 * Squarespace source page fetcher.
 *
 * @package Sqs71ToGutenberg
 */

namespace Sqs71ToGutenberg;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Source_Fetcher {

	/** @var array<string,mixed> */
	private $settings;

	/** @var array<string,string> */
	private $cache = array();

	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Build the source URL for a given post.
	 *
	 * Substitutes {year}, {month}, {day}, {slug} placeholders in the configured
	 * url_pattern. Month and day are zero-padded but the live Squarespace URLs
	 * usually use unpadded month/day, so we also try a fallback.
	 */
	public function build_url_candidates( \WP_Post $post ) {
		$pattern = $this->settings['url_pattern'];
		$slug    = $post->post_name;
		$ts      = strtotime( $post->post_date );

		$offset_days = (int) ( $this->settings['date_offset_days'] ?? 0 );
		if ( 0 !== $offset_days ) {
			$ts = strtotime( ( $offset_days >= 0 ? '+' : '' ) . $offset_days . ' days', $ts );
		}

		$year         = wp_date( 'Y', $ts );
		$month_padded = wp_date( 'm', $ts );
		$day_padded   = wp_date( 'd', $ts );
		$month_short  = wp_date( 'n', $ts );
		$day_short    = wp_date( 'j', $ts );

		$base = untrailingslashit( $this->settings['source_domain'] );

		$swap = static function ( $p, $y, $m, $d, $slug ) {
			return strtr(
				$p,
				array(
					'{year}'  => $y,
					'{month}' => $m,
					'{day}'   => $d,
					'{slug}'  => $slug,
				)
			);
		};

		$candidates = array(
			$base . $swap( $pattern, $year, $month_short,  $day_short,  $slug ),
			$base . $swap( $pattern, $year, $month_padded, $day_padded, $slug ),
		);

		// Try date offset by ±1 day to handle timezone shifts between WP import and live site.
		foreach ( array( -1, 1 ) as $off ) {
			$ts2 = strtotime( ( $off >= 0 ? '+' : '' ) . $off . ' days', $ts );
			$candidates[] = $base . $swap( $pattern, wp_date( 'Y', $ts2 ), wp_date( 'n', $ts2 ), wp_date( 'j', $ts2 ), $slug );
		}

		return array_values( array_unique( $candidates ) );
	}

	/**
	 * Fetch a URL via WP HTTP API. Caches per-instance.
	 *
	 * @return string|\WP_Error HTML body, or WP_Error on failure.
	 */
	public function fetch( $url ) {
		if ( isset( $this->cache[ $url ] ) ) {
			return $this->cache[ $url ];
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => (int) ( $this->settings['request_timeout'] ?? 30 ),
				'user-agent' => 'sqs71-to-gutenberg/' . SQS71_TO_GUTENBERG_VERSION,
				'redirection' => 5,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 400 ) {
			return new \WP_Error( 'sqs71_http_error', "HTTP $code from $url" );
		}

		$body = wp_remote_retrieve_body( $response );
		$this->cache[ $url ] = $body;

		return $body;
	}

	/**
	 * Try each URL candidate; return the first one that succeeds.
	 *
	 * @return array{url:string,html:string}|\WP_Error
	 */
	public function fetch_first( array $candidates ) {
		$last_err = null;
		foreach ( $candidates as $url ) {
			$res = $this->fetch( $url );
			if ( ! is_wp_error( $res ) ) {
				return array(
					'url'  => $url,
					'html' => $res,
				);
			}
			$last_err = $res;
		}
		return $last_err ?: new \WP_Error( 'sqs71_no_candidates', 'No candidate URLs provided.' );
	}
}
