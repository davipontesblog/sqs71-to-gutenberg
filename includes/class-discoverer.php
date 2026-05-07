<?php
/**
 * Discoverer — finds posts on the live Squarespace site that don't yet
 * exist in WordPress, creates stub WP posts (with title, slug, date,
 * categories, tags, author), and runs the converter to populate content.
 *
 * Used after the initial XML import to catch up on posts that were
 * published on Squarespace AFTER the last import.
 *
 * @package Sqs71ToGutenberg
 */

namespace Sqs71ToGutenberg;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Discoverer {

	/** @var array<string,mixed> */
	private $settings;

	/** @var Archive_Index */
	private $archive;

	/** @var Post_Rewriter */
	private $rewriter;

	/** @var Author_Reassigner */
	private $authors;

	public function __construct( array $settings ) {
		$this->settings = $settings;
		$this->archive  = new Archive_Index( $settings );
		$this->rewriter = new Post_Rewriter( array_merge( $settings, array( 'dry_run' => false, 'force_reconvert' => true ) ) );
		$this->authors  = new Author_Reassigner( $settings );
	}

	/**
	 * Survey: list every Squarespace slug that doesn't yet exist in WP.
	 *
	 * @return array<int,array{slug:string,title:string,url:string,asset:string,author_name:string}>
	 */
	public function survey( $logger = null ) {
		$index = $this->archive->get( false, $logger );
		if ( ! $index ) return array();

		global $wpdb;
		$existing = $wpdb->get_col(
			"SELECT post_name FROM {$wpdb->posts}
			 WHERE post_type = 'post' AND post_status IN ('publish','private','draft','pending','future','trash')"
		);
		$existing = array_flip( array_filter( $existing ) );

		$missing = array();
		foreach ( $index as $slug => $entry ) {
			if ( isset( $existing[ $slug ] ) ) continue;
			$missing[] = array(
				'slug'        => $slug,
				'title'       => $entry['title'],
				'url'         => $entry['url'],
				'asset'       => $entry['asset'],
				'author_name' => $entry['author_name'],
			);
		}
		return $missing;
	}

	/**
	 * Import N missing posts. Creates stub WP post first (so we have an ID
	 * with the right slug + date + author for the converter to use), then
	 * fetches the source HTML and runs the parser/emitter to populate
	 * post_content.
	 *
	 * @param array{
	 *   limit?:int,
	 *   create_users?:bool,
	 *   default_status?:string,
	 *   logger?:callable
	 * } $opts
	 *
	 * @return array{imported:int,skipped:int,errors:int,created_users:int,processed:int}
	 */
	public function import_missing( $opts = array() ) {
		$limit          = max( 1, (int) ( $opts['limit'] ?? 50 ) );
		$create_users   = ! empty( $opts['create_users'] );
		$default_status = $opts['default_status'] ?? 'publish';
		$logger         = $opts['logger'] ?? static function ( $m ) { /* noop */ };

		$missing = $this->survey( $logger );
		if ( ! $missing ) {
			$logger( 'No missing posts found.' );
			return array( 'imported' => 0, 'skipped' => 0, 'errors' => 0, 'created_users' => 0, 'processed' => 0 );
		}

		$logger( count( $missing ) . ' Squarespace posts not in WP — processing first ' . $limit );
		$missing = array_slice( $missing, 0, $limit );

		$out = array( 'imported' => 0, 'skipped' => 0, 'errors' => 0, 'created_users' => 0, 'processed' => 0 );
		$index = $this->archive->get( false, $logger );

		foreach ( $missing as $m ) {
			$out['processed']++;
			$entry = $index[ $m['slug'] ] ?? null;
			if ( ! $entry ) {
				$out['skipped']++;
				continue;
			}

			// Fetch the live URL to get the post date and full content.
			$resp = wp_remote_get( $entry['url'], array( 'timeout' => 25 ) );
			if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
				$out['errors']++;
				$logger( "  {$m['slug']}: failed to fetch {$entry['url']}" );
				continue;
			}
			$html = wp_remote_retrieve_body( $resp );

			// Derive post_date from the URL's /YYYY/M/D/ segment when present.
			$post_date = $this->guess_post_date( $entry['url'] );

			// Resolve author → WP user (create if requested).
			$post_author = 0;
			if ( ! empty( $entry['author_id'] ) ) {
				$row = array(
					'author_id' => $entry['author_id'],
					'first'     => $entry['author_first'],
					'last'      => $entry['author_last'],
					'name'      => $entry['author_name'],
				);
				$user = $this->find_or_create_user( $row, $create_users, $logger );
				if ( $user ) {
					$post_author = $user->ID;
					if ( ! empty( $user->_sqs71_just_created ) ) $out['created_users']++;
				}
			}
			if ( ! $post_author ) {
				$post_author = (int) get_current_user_id();
			}

			// Insert post stub.
			$post_id = wp_insert_post( array(
				'post_type'    => 'post',
				'post_title'   => $entry['title'] ?: $m['slug'],
				'post_name'    => $m['slug'],
				'post_status'  => $default_status,
				'post_date'    => $post_date,
				'post_date_gmt' => get_gmt_from_date( $post_date ),
				'post_content' => '',
				'post_author'  => $post_author,
			), true );
			if ( is_wp_error( $post_id ) ) {
				$out['errors']++;
				$logger( "  {$m['slug']}: wp_insert_post failed: " . $post_id->get_error_message() );
				continue;
			}

			// Run parser + emitter on fetched HTML.
			$parser  = new Block_Parser();
			$media   = new Media_Importer( $this->settings );
			$emitter = new Block_Emitter( $media );
			$ast = $parser->parse( $html );
			if ( ! $ast ) {
				$logger( "  {$m['slug']}: parser returned no blocks" );
				$out['errors']++;
				continue;
			}
			$content = $emitter->emit( $ast, $post_id );
			$stats   = $emitter->get_stats();

			wp_update_post( array( 'ID' => $post_id, 'post_content' => $content ) );

			update_post_meta( $post_id, SQS71_TO_GUTENBERG_CONVERTED_META, current_time( 'mysql' ) );
			update_post_meta( $post_id, '_sqs71_source_url', esc_url_raw( $entry['url'] ) );
			update_post_meta( $post_id, '_sqs71_discovered', current_time( 'mysql' ) );

			$logger( "  IMPORTED #{$post_id} {$m['slug']} — " . count( $ast ) . ' blocks, ' . $stats['uploaded'] . ' images' );
			$out['imported']++;
		}

		return $out;
	}

	private function guess_post_date( $url ) {
		$path = parse_url( $url, PHP_URL_PATH );
		if ( $path && preg_match( '#/(\d{4})/(\d{1,2})/(\d{1,2})/#', $path, $m ) ) {
			return sprintf( '%04d-%02d-%02d 12:00:00', (int) $m[1], (int) $m[2], (int) $m[3] );
		}
		return current_time( 'mysql' );
	}

	private function find_or_create_user( $row, $create, $logger ) {
		// Reuse Author_Reassigner's find logic via a small reflection trick: we
		// can't call private methods, but its survey + creation logic is what
		// we want. So we inline the same priority order here.

		$users = get_users( array(
			'meta_key'   => Author_Reassigner::SQS_AUTHOR_ID_META,
			'meta_value' => $row['author_id'],
			'number'     => 1,
		) );
		if ( $users ) return $users[0];

		$name = trim( $row['name'] );
		if ( $name ) {
			global $wpdb;
			$id = $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->users} WHERE LOWER(display_name) = LOWER(%s) LIMIT 1",
				$name
			) );
			if ( $id ) return get_user_by( 'id', $id );
		}

		if ( ! $create ) return null;

		// Mint a new user.
		$first = trim( $row['first'] );
		$last  = trim( $row['last']  );
		if ( ! $name ) $name = trim( $first . ' ' . $last );
		if ( ! $name ) return null;

		$base = sanitize_user( strtolower( str_replace( ' ', '.', $name ) ), true );
		if ( ! $base ) $base = 'sqs71-' . substr( $row['author_id'], 0, 8 );
		$login = $base;
		$i = 2;
		while ( get_user_by( 'login', $login ) ) {
			$login = $base . '-' . $i++;
		}
		$user_id = wp_insert_user( array(
			'user_login'   => $login,
			'user_email'   => $login . '@' . ( $this->settings['default_email_domain'] ?? 'walkaboutchronicles.invalid' ),
			'user_pass'    => wp_generate_password( 32 ),
			'first_name'   => $first,
			'last_name'    => $last,
			'display_name' => $name,
			'role'         => 'author',
		) );
		if ( is_wp_error( $user_id ) ) {
			$logger( '  user create failed: ' . $user_id->get_error_message() );
			return null;
		}
		update_user_meta( $user_id, Author_Reassigner::SQS_AUTHOR_ID_META, $row['author_id'] );
		$logger( "  CREATED WP user #$user_id ($login) for $name" );
		$user = get_user_by( 'id', $user_id );
		$user->_sqs71_just_created = true;
		return $user;
	}
}
