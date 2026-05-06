<?php
/**
 * Parses Squarespace 7.1 rendered HTML into a normalized block AST.
 *
 * Output AST is an ordered array of associative arrays of the form:
 *   [ 'type' => 'image|gallery|paragraph|heading|list|quote|embed|separator|html', ...payload ]
 *
 * @package Sqs71ToGutenberg
 */

namespace Sqs71ToGutenberg;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Block_Parser {

	/**
	 * Parse a full HTML document and return the AST for the post body region.
	 *
	 * @param string $html
	 * @return array<int,array<string,mixed>>
	 */
	public function parse( $html ) {
		if ( ! $html || ! is_string( $html ) ) {
			return array();
		}

		libxml_use_internal_errors( true );
		$doc = new \DOMDocument();
		// Squarespace serves UTF-8; protect against mojibake.
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();

		$body = $this->find_post_body( $doc );
		if ( ! $body ) {
			return array();
		}

		return $this->walk( $body );
	}

	/**
	 * Locate the main post body container.
	 *
	 * Squarespace 7.1 uses a few different wrappers depending on theme; we look
	 * for the first .sqs-layout that lives inside an article/post container.
	 */
	private function find_post_body( \DOMDocument $doc ) {
		$xpath = new \DOMXPath( $doc );

		// Prefer the body of an article tag (single post pages).
		$candidates = array(
			'//article//*[contains(concat(" ", normalize-space(@class), " "), " sqs-layout ")][1]',
			'//*[@id="sqs-content"]//*[contains(concat(" ", normalize-space(@class), " "), " sqs-layout ")][1]',
			'//*[contains(concat(" ", normalize-space(@class), " "), " sqs-layout ")][1]',
		);

		foreach ( $candidates as $q ) {
			$nodes = $xpath->query( $q );
			if ( $nodes && $nodes->length > 0 ) {
				return $nodes->item( 0 );
			}
		}

		return null;
	}

	/**
	 * Walk a Squarespace layout/row/col structure and emit AST nodes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function walk( \DOMNode $node ) {
		$out = array();

		foreach ( $node->childNodes as $child ) {
			if ( ! ( $child instanceof \DOMElement ) ) {
				continue;
			}

			$class = $child->getAttribute( 'class' );

			// Recurse through layout containers.
			if ( $this->has_class( $class, 'sqs-row' ) || $this->has_class( $class, 'sqs-block-content' ) || preg_match( '/sqs-col-\d+/', $class ) ) {
				$out = array_merge( $out, $this->walk( $child ) );
				continue;
			}

			if ( $this->has_class( $class, 'sqs-block' ) ) {
				$node_blocks = $this->parse_block( $child );
				if ( $node_blocks ) {
					$out = array_merge( $out, $node_blocks );
				}
				continue;
			}

			// Sometimes plain content sits at the layout level.
			$out = array_merge( $out, $this->walk( $child ) );
		}

		return $out;
	}

	/**
	 * Dispatch on Squarespace block type.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function parse_block( \DOMElement $el ) {
		$class = $el->getAttribute( 'class' );

		if ( $this->has_class( $class, 'image-block' ) ) {
			$img = $this->find_first( $el, './/img' );
			if ( ! $img ) {
				return array();
			}
			$caption = $this->extract_caption( $el );
			return array(
				array(
					'type'    => 'image',
					'src'     => $this->image_src( $img ),
					'alt'     => $img->getAttribute( 'alt' ),
					'width'   => (int) $img->getAttribute( 'width' ),
					'height'  => (int) $img->getAttribute( 'height' ),
					'caption' => $caption,
				),
			);
		}

		if ( $this->has_class( $class, 'gallery-block' ) || $this->has_class( $class, 'gallery-summary-block' ) || $this->has_class( $class, 'sqs-gallery-block-grid' ) || $this->has_class( $class, 'sqs-gallery-block-slideshow' ) ) {
			// Skip <noscript> fallback img tags (Squarespace duplicates each image
			// inside both a real <img> and a <noscript> wrapper for non-JS clients).
			$imgs = $this->find_all( $el, './/img[not(ancestor::noscript)]' );
			$gallery_items = array();
			$seen          = array();
			foreach ( $imgs as $img ) {
				$src = $this->image_src( $img );
				if ( ! $src ) {
					continue;
				}
				// Dedupe on the path part only (strip ?format= variants).
				$key = preg_replace( '/[?#].*$/', '', $src );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$gallery_items[] = array(
					'src'    => $src,
					'alt'    => $img->getAttribute( 'alt' ),
					'width'  => (int) $img->getAttribute( 'width' ),
					'height' => (int) $img->getAttribute( 'height' ),
				);
			}
			if ( ! $gallery_items ) {
				return array();
			}
			return array(
				array(
					'type'    => 'gallery',
					'columns' => $this->guess_gallery_columns( $el, count( $gallery_items ) ),
					'items'   => $gallery_items,
				),
			);
		}

		if ( $this->has_class( $class, 'html-block' ) || $this->has_class( $class, 'markdown-block' ) || $this->has_class( $class, 'rte-block' ) ) {
			return $this->parse_text_block( $el );
		}

		if ( $this->has_class( $class, 'embed-block' ) ) {
			$iframe = $this->find_first( $el, './/iframe' );
			$url    = $iframe ? $iframe->getAttribute( 'src' ) : '';
			if ( ! $url ) {
				$a = $this->find_first( $el, './/a[@href]' );
				if ( $a ) {
					$url = $a->getAttribute( 'href' );
				}
			}
			if ( ! $url ) {
				return array();
			}
			return array(
				array(
					'type' => 'embed',
					'url'  => $url,
				),
			);
		}

		if ( $this->has_class( $class, 'video-block' ) ) {
			$iframe = $this->find_first( $el, './/iframe' );
			$src = $iframe ? $iframe->getAttribute( 'src' ) : '';
			if ( $src ) {
				return array(
					array(
						'type' => 'embed',
						'url'  => $src,
					),
				);
			}
		}

		if ( $this->has_class( $class, 'quote-block' ) ) {
			$blockquote = $this->find_first( $el, './/blockquote' );
			$text       = $blockquote ? trim( $blockquote->textContent ) : '';
			return $text
				? array(
					array(
						'type' => 'quote',
						'text' => $text,
					),
				)
				: array();
		}

		if ( $this->has_class( $class, 'horizontal-rule-block' ) || $this->has_class( $class, 'line-block' ) ) {
			return array(
				array( 'type' => 'separator' ),
			);
		}

		if ( $this->has_class( $class, 'spacer-block' ) ) {
			return array(); // Drop spacers; block editor handles spacing differently.
		}

		// Fallback: emit raw HTML so nothing is silently lost.
		$inner = $this->inner_html( $el );
		if ( trim( $inner ) === '' ) {
			return array();
		}
		return array(
			array(
				'type' => 'html',
				'html' => $inner,
			),
		);
	}

	/**
	 * Extract paragraphs / headings / lists from an html-block.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function parse_text_block( \DOMElement $el ) {
		// Drill into the inner content wrapper if present.
		$content = $this->find_first( $el, './/*[contains(concat(" ", normalize-space(@class), " "), " sqs-block-content ")]' );
		$root    = $content ?: $el;

		$out = array();
		foreach ( $root->childNodes as $child ) {
			$out = array_merge( $out, $this->extract_text_nodes( $child ) );
		}
		return $out;
	}

	/**
	 * Recursive walker for text blocks — flattens divs, recognizes headings/lists/paragraphs.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function extract_text_nodes( \DOMNode $node ) {
		if ( $node instanceof \DOMText ) {
			$txt = trim( $node->wholeText );
			return $txt === ''
				? array()
				: array( array( 'type' => 'paragraph', 'html' => esc_html( $txt ) ) );
		}

		if ( ! ( $node instanceof \DOMElement ) ) {
			return array();
		}

		$tag = strtolower( $node->tagName );

		if ( in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
			return array(
				array(
					'type'  => 'heading',
					'level' => (int) substr( $tag, 1 ),
					'html'  => $this->inner_html( $node ),
					'text'  => trim( $node->textContent ),
				),
			);
		}

		if ( $tag === 'p' ) {
			$inner = trim( $this->inner_html( $node ) );
			if ( $inner === '' ) {
				return array();
			}
			return array(
				array( 'type' => 'paragraph', 'html' => $inner ),
			);
		}

		if ( $tag === 'ul' || $tag === 'ol' ) {
			$items = array();
			foreach ( $node->getElementsByTagName( 'li' ) as $li ) {
				$items[] = $this->inner_html( $li );
			}
			return array(
				array(
					'type'    => 'list',
					'ordered' => $tag === 'ol',
					'items'   => $items,
				),
			);
		}

		if ( $tag === 'blockquote' ) {
			return array(
				array(
					'type' => 'quote',
					'text' => trim( $node->textContent ),
				),
			);
		}

		if ( $tag === 'figure' ) {
			$img = $this->find_first( $node, './/img' );
			if ( $img ) {
				return array(
					array(
						'type'    => 'image',
						'src'     => $this->image_src( $img ),
						'alt'     => $img->getAttribute( 'alt' ),
						'caption' => $this->find_first( $node, './/figcaption' ) ? $this->inner_html( $this->find_first( $node, './/figcaption' ) ) : '',
					),
				);
			}
		}

		if ( $tag === 'hr' ) {
			return array( array( 'type' => 'separator' ) );
		}

		// Recurse into other containers.
		$out = array();
		foreach ( $node->childNodes as $child ) {
			$out = array_merge( $out, $this->extract_text_nodes( $child ) );
		}
		return $out;
	}

	/* -------------------------- helpers -------------------------- */

	private function has_class( $class_attr, $needle ) {
		return preg_match( '/(^|\s)' . preg_quote( $needle, '/' ) . '(\s|$)/', $class_attr ) === 1;
	}

	private function find_first( \DOMNode $node, $xpath ) {
		$dx     = new \DOMXPath( $node->ownerDocument );
		$result = $dx->query( $xpath, $node );
		return ( $result && $result->length > 0 ) ? $result->item( 0 ) : null;
	}

	private function find_all( \DOMNode $node, $xpath ) {
		$dx     = new \DOMXPath( $node->ownerDocument );
		$result = $dx->query( $xpath, $node );
		$out = array();
		if ( $result ) {
			foreach ( $result as $n ) {
				$out[] = $n;
			}
		}
		return $out;
	}

	private function inner_html( \DOMNode $node ) {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}
		return $html;
	}

	/**
	 * Squarespace prefers data-image over src for lazy-loaded images.
	 */
	private function image_src( \DOMElement $img ) {
		foreach ( array( 'data-image', 'data-src', 'src' ) as $attr ) {
			$v = $img->getAttribute( $attr );
			if ( $v && false === stripos( $v, 'data:image' ) ) {
				return $v;
			}
		}
		return '';
	}

	private function extract_caption( \DOMElement $el ) {
		$cap = $this->find_first( $el, './/figcaption | .//*[contains(concat(" ", normalize-space(@class), " "), " image-caption ")] | .//*[contains(concat(" ", normalize-space(@class), " "), " caption ")]' );
		return $cap ? trim( $this->inner_html( $cap ) ) : '';
	}

	private function guess_gallery_columns( \DOMElement $el, $count ) {
		$class = $el->getAttribute( 'class' );
		if ( strpos( $class, 'columns-1' ) !== false ) {
			return 1;
		}
		if ( strpos( $class, 'columns-2' ) !== false ) {
			return 2;
		}
		if ( strpos( $class, 'columns-3' ) !== false ) {
			return 3;
		}
		if ( strpos( $class, 'columns-4' ) !== false ) {
			return 4;
		}
		// Fall back to a reasonable default based on item count.
		if ( $count <= 2 ) {
			return $count;
		}
		if ( $count <= 6 ) {
			return 3;
		}
		return 4;
	}
}
