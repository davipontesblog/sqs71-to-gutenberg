<?php
/**
 * Emits Gutenberg block markup from the AST produced by Block_Parser.
 *
 * @package Sqs71ToGutenberg
 */

namespace Sqs71ToGutenberg;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Block_Emitter {

	/** @var Media_Importer */
	private $media;

	/** @var int */
	private $parent_post = 0;

	/** @var bool */
	private $dry_run = false;

	/** @var array{uploaded:int,failed:int,errors:array<int,string>} */
	private $stats;

	public function __construct( Media_Importer $media ) {
		$this->media = $media;
		$this->reset_stats();
	}

	public function set_dry_run( $on ) {
		$this->dry_run = (bool) $on;
	}

	private function reset_stats() {
		$this->stats = array(
			'uploaded' => 0,
			'failed'   => 0,
			'errors'   => array(),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $blocks
	 * @param int $parent_post Post ID to attach images to.
	 * @return string Gutenberg block markup.
	 */
	public function emit( array $blocks, $parent_post = 0 ) {
		$this->parent_post = (int) $parent_post;
		$this->reset_stats();

		$out = '';
		foreach ( $blocks as $block ) {
			$out .= $this->emit_one( $block ) . "\n\n";
		}
		return rtrim( $out ) . "\n";
	}

	public function get_stats() {
		return $this->stats;
	}

	private function emit_one( array $block ) {
		switch ( $block['type'] ) {
			case 'image':
				return $this->emit_image( $block );

			case 'gallery':
				return $this->emit_gallery( $block );

			case 'paragraph':
				return $this->emit_paragraph( $block );

			case 'heading':
				return $this->emit_heading( $block );

			case 'list':
				return $this->emit_list( $block );

			case 'quote':
				return $this->emit_quote( $block );

			case 'embed':
				return $this->emit_embed( $block );

			case 'separator':
				return "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";

			case 'html':
				return $this->emit_html( $block['html'] );
		}
		return '';
	}

	/* -------------------- block emitters -------------------- */

	private function emit_image( array $block ) {
		$src = $block['src'] ?? '';
		if ( ! $src ) {
			return '';
		}

		if ( $this->dry_run ) {
			$this->stats['uploaded']++; // count "would-upload" for preview accuracy
			return $this->emit_image_html( $src, $block['alt'] ?? '', null, $block['caption'] ?? '' );
		}

		$import = $this->media->import( $src, $this->parent_post, $block['alt'] ?? '' );
		if ( is_wp_error( $import ) ) {
			$this->stats['failed']++;
			$this->stats['errors'][] = $import->get_error_message() . ' [' . $src . ']';
			return $this->emit_image_html( $src, $block['alt'] ?? '', null, $block['caption'] ?? '' );
		}

		$this->stats['uploaded']++;
		return $this->emit_image_html( $import['url'], $block['alt'] ?? '', $import['id'], $block['caption'] ?? '' );
	}

	private function emit_image_html( $url, $alt, $id = null, $caption = '' ) {
		$attrs = array( 'sizeSlug' => 'large' );
		if ( $id ) {
			$attrs['id'] = (int) $id;
		}

		$open = '<!-- wp:image ' . wp_json_encode( $attrs ) . ' -->';
		$figure_class = 'wp-block-image size-large';

		$caption_html = '';
		if ( $caption ) {
			$caption_html = '<figcaption class="wp-element-caption">' . $caption . '</figcaption>';
		}

		$img = sprintf(
			'<img src="%s" alt="%s"%s/>',
			esc_url( $url ),
			esc_attr( $alt ),
			$id ? ' class="wp-image-' . (int) $id . '"' : ''
		);

		return $open . "\n<figure class=\"$figure_class\">$img$caption_html</figure>\n<!-- /wp:image -->";
	}

	private function emit_gallery( array $block ) {
		$inner = '';
		$ids   = array();

		foreach ( $block['items'] as $item ) {
			if ( $this->dry_run ) {
				$this->stats['uploaded']++;
				$inner .= $this->emit_image_html( $item['src'], $item['alt'] ?? '', null, '' ) . "\n\n";
				continue;
			}

			$import = $this->media->import( $item['src'], $this->parent_post, $item['alt'] ?? '' );
			if ( is_wp_error( $import ) ) {
				$this->stats['failed']++;
				$this->stats['errors'][] = $import->get_error_message() . ' [' . $item['src'] . ']';
				$inner .= $this->emit_image_html( $item['src'], $item['alt'] ?? '', null, '' ) . "\n\n";
				continue;
			}
			$this->stats['uploaded']++;
			$ids[] = (int) $import['id'];
			$inner .= $this->emit_image_html( $import['url'], $item['alt'] ?? '', $import['id'], '' ) . "\n\n";
		}

		$attrs = array(
			'columns' => max( 1, min( 8, (int) ( $block['columns'] ?? 3 ) ) ),
			'linkTo'  => 'none',
		);
		if ( $ids ) {
			$attrs['ids'] = $ids;
		}

		return '<!-- wp:gallery ' . wp_json_encode( $attrs ) . " -->\n"
			. '<figure class="wp-block-gallery has-nested-images columns-' . $attrs['columns'] . ' is-cropped">' . "\n"
			. rtrim( $inner )
			. "\n</figure>\n<!-- /wp:gallery -->";
	}

	private function emit_paragraph( array $block ) {
		$html = trim( $block['html'] ?? '' );
		if ( $html === '' ) {
			return '';
		}
		return "<!-- wp:paragraph -->\n<p>$html</p>\n<!-- /wp:paragraph -->";
	}

	private function emit_heading( array $block ) {
		$level = (int) ( $block['level'] ?? 2 );
		if ( $level < 1 || $level > 6 ) {
			$level = 2;
		}
		$attrs = $level === 2 ? '' : ' ' . wp_json_encode( array( 'level' => $level ) );
		$text  = $block['html'] ?? esc_html( $block['text'] ?? '' );
		return "<!-- wp:heading$attrs -->\n<h$level class=\"wp-block-heading\">$text</h$level>\n<!-- /wp:heading -->";
	}

	private function emit_list( array $block ) {
		$ordered = ! empty( $block['ordered'] );
		$tag     = $ordered ? 'ol' : 'ul';
		$items_markup = '';
		foreach ( $block['items'] as $item ) {
			$items_markup .= "<!-- wp:list-item -->\n<li>$item</li>\n<!-- /wp:list-item -->\n";
		}
		$attrs = $ordered ? ' ' . wp_json_encode( array( 'ordered' => true ) ) : '';
		return "<!-- wp:list$attrs -->\n<$tag class=\"wp-block-list\">\n$items_markup</$tag>\n<!-- /wp:list -->";
	}

	private function emit_quote( array $block ) {
		$text = esc_html( $block['text'] ?? '' );
		return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><p>$text</p></blockquote>\n<!-- /wp:quote -->";
	}

	private function emit_embed( array $block ) {
		$url = esc_url( $block['url'] ?? '' );
		if ( ! $url ) {
			return '';
		}
		$attrs = wp_json_encode( array( 'url' => $url ) );
		return "<!-- wp:embed $attrs -->\n<figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">$url</div></figure>\n<!-- /wp:embed -->";
	}

	private function emit_html( $html ) {
		$safe = trim( wp_kses_post( $html ) );
		if ( $safe === '' ) {
			return '';
		}
		return "<!-- wp:html -->\n$safe\n<!-- /wp:html -->";
	}
}
