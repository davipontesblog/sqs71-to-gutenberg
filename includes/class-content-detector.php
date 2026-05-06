<?php
/**
 * Classifies post_content so we know whether to convert, skip, or flag.
 *
 * The decision matrix:
 *
 *   contains <!-- wp:  AND  no sqs-block class       → already-gutenberg  (skip)
 *   contains sqs-block (any combo)                   → squarespace-html   (convert)
 *   no markers, has HTML tags                        → mixed/unknown      (skip unless --force)
 *   empty / whitespace-only                          → empty              (skip)
 *
 * @package Sqs71ToGutenberg
 */

namespace Sqs71ToGutenberg;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Content_Detector {

	const ALREADY_GUTENBERG = 'already-gutenberg';
	const SQUARESPACE_HTML  = 'squarespace-html';
	const UNKNOWN_HTML      = 'unknown-html';
	const EMPTY_CONTENT     = 'empty';

	/**
	 * Classify a post_content string.
	 *
	 * @param string $content
	 * @return array{
	 *   classification: string,
	 *   should_convert: bool,
	 *   reason: string,
	 *   markers: array<string,int>
	 * }
	 */
	public function classify( $content ) {
		$content = (string) $content;

		if ( trim( wp_strip_all_tags( $content ) ) === '' && strpos( $content, '<img' ) === false ) {
			return $this->result( self::EMPTY_CONTENT, false, 'post content is empty', array() );
		}

		$gutenberg_blocks = preg_match_all( '/<!--\s*wp:[a-z][a-z0-9\/_-]*/i', $content );
		$sqs_blocks       = preg_match_all( '/class="[^"]*\bsqs-block\b/i', $content );
		$sqs_classes      = preg_match_all( '/class="[^"]*\bsqs-(?:layout|row|col-\d+|gallery)\b/i', $content );
		$has_html_tags    = (bool) preg_match( '/<[a-z][^>]*>/i', $content );

		$markers = array(
			'gutenberg_blocks' => (int) $gutenberg_blocks,
			'sqs_blocks'       => (int) $sqs_blocks,
			'sqs_classes'      => (int) $sqs_classes,
		);

		// Squarespace soup wins regardless of how many wp:blocks are present —
		// even a partially-converted post needs to be re-processed.
		if ( $sqs_blocks > 0 || $sqs_classes > 0 ) {
			return $this->result(
				self::SQUARESPACE_HTML,
				true,
				sprintf(
					'found %d sqs-block(s) / %d sqs layout class(es)',
					$sqs_blocks,
					$sqs_classes
				),
				$markers
			);
		}

		if ( $gutenberg_blocks > 0 ) {
			return $this->result(
				self::ALREADY_GUTENBERG,
				false,
				sprintf( 'found %d Gutenberg block delimiter(s) and no Squarespace markers', $gutenberg_blocks ),
				$markers
			);
		}

		if ( $has_html_tags ) {
			return $this->result(
				self::UNKNOWN_HTML,
				false,
				'post is plain HTML with no Squarespace or Gutenberg markers — needs human review before auto-conversion',
				$markers
			);
		}

		// Plain text with no HTML — leave alone (likely intentional).
		return $this->result( self::EMPTY_CONTENT, false, 'post is plain text only', $markers );
	}

	private function result( $classification, $should_convert, $reason, $markers ) {
		return array(
			'classification' => $classification,
			'should_convert' => (bool) $should_convert,
			'reason'         => $reason,
			'markers'        => $markers,
		);
	}
}
