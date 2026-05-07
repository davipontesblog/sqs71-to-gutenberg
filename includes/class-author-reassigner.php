<?php
/**
 * Author_Reassigner — reassigns each WP post's `post_author` to match the
 * Squarespace authorId reported by the live blog JSON.
 *
 * Useful when a WP XML import collapsed all posts under a single user. Walks
 * the Archive_Index, builds a unique-author map, finds (or optionally
 * creates) a matching WP user for each Squarespace author, then updates
 * post_author per post.
 *
 * @package Sqs71ToGutenberg
 */

namespace Sqs71ToGutenberg;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Author_Reassigner {

	const DONE_META          = '_sqs71_author_set';
	const SQS_AUTHOR_ID_META = '_sqs71_author_id'; // Stored on WP user accounts.

	/** @var array<string,mixed> */
	private $settings;

	/** @var Archive_Index */
	private $archive;

	public function __construct( array $settings ) {
		$this->settings = $settings;
		$this->archive  = new Archive_Index( $settings );
	}

	/**
	 * Survey: list every unique Squarespace author across the archive,
	 * along with post counts and the matching WP user (if any).
	 *
	 * @return array<int,array{
	 *   author_id:string,
	 *   first:string,
	 *   last:string,
	 *   name:string,
	 *   posts:int,
	 *   wp_user_id:int,
	 *   wp_user_login:string,
	 * }>
	 */
	public function survey( $logger = null ) {
		$index = $this->archive->get( false, $logger );
		$by_id = array();
		foreach ( $index as $slug => $entry ) {
			$aid = $entry['author_id'] ?? '';
			if ( ! $aid ) continue;
			if ( ! isset( $by_id[ $aid ] ) ) {
				$by_id[ $aid ] = array(
					'author_id' => $aid,
					'first'     => $entry['author_first'] ?? '',
					'last'      => $entry['author_last']  ?? '',
					'name'      => $entry['author_name']  ?? '',
					'posts'     => 0,
				);
			}
			$by_id[ $aid ]['posts']++;
		}

		// Resolve to WP users.
		foreach ( $by_id as &$row ) {
			$user = $this->find_wp_user( $row );
			$row['wp_user_id']    = $user ? (int) $user->ID : 0;
			$row['wp_user_login'] = $user ? $user->user_login : '';
		}

		usort( $by_id, static function ( $a, $b ) { return $b['posts'] - $a['posts']; } );
		return array_values( $by_id );
	}

	/**
	 * Run a batch.
	 *
	 * @param array{
	 *   limit?:int,
	 *   create_users?:bool,
	 *   default_email_domain?:string,
	 *   logger?:callable
	 * } $opts
	 *
	 * @return array{set:int,unchanged:int,missing:int,created_users:int,processed:int}
	 */
	public function run_batch( $opts = array() ) {
		$limit         = max( 1, (int) ( $opts['limit'] ?? 200 ) );
		$create_users  = ! empty( $opts['create_users'] );
		$email_domain  = $opts['default_email_domain'] ?? 'walkaboutchronicles.invalid';
		$logger        = $opts['logger'] ?? static function ( $m ) { /* noop */ };

		$index = $this->archive->get( false, $logger );
		if ( ! $index ) {
			$logger( 'Author_Reassigner: archive index empty.' );
			return array( 'set' => 0, 'unchanged' => 0, 'missing' => 0, 'created_users' => 0, 'processed' => 0 );
		}

		$post_ids = $this->select_posts( $limit );
		$logger( 'Found ' . count( $post_ids ) . ' posts to process' );

		$out = array( 'set' => 0, 'unchanged' => 0, 'missing' => 0, 'created_users' => 0, 'processed' => 0 );
		$user_cache = array();

		foreach ( $post_ids as $pid ) {
			$out['processed']++;
			$post = get_post( $pid );
			if ( ! $post ) continue;

			$entry = $index[ $post->post_name ] ?? null;
			if ( ! $entry ) {
				// Fallback: try resolved-slug from _sqs71_resolved_url meta.
				$resolved = get_post_meta( $pid, '_sqs71_resolved_url', true );
				if ( $resolved ) {
					$path = parse_url( $resolved, PHP_URL_PATH );
					if ( $path ) {
						$alt = trim( basename( rtrim( $path, '/' ) ) );
						if ( $alt && isset( $index[ $alt ] ) ) {
							$entry = $index[ $alt ];
						}
					}
				}
			}

			if ( ! $entry || empty( $entry['author_id'] ) ) {
				$out['missing']++;
				update_post_meta( $pid, self::DONE_META, 'no-author' );
				continue;
			}

			$aid = $entry['author_id'];
			if ( ! isset( $user_cache[ $aid ] ) ) {
				$user = $this->find_wp_user( array(
					'author_id' => $aid,
					'first'     => $entry['author_first'],
					'last'      => $entry['author_last'],
					'name'      => $entry['author_name'],
				) );

				if ( ! $user && $create_users ) {
					$user = $this->create_wp_user( $aid, $entry, $email_domain, $logger );
					if ( $user ) {
						$out['created_users']++;
					}
				}

				$user_cache[ $aid ] = $user ? (int) $user->ID : 0;
				if ( $user ) {
					$logger( "  Squarespace author {$entry['author_name']} ($aid) → WP user #{$user->ID} ({$user->user_login})" );
				} else {
					$logger( "  Squarespace author {$entry['author_name']} ($aid) → NO MATCHING WP USER" );
				}
			}

			$user_id = $user_cache[ $aid ];
			if ( ! $user_id ) {
				$out['missing']++;
				update_post_meta( $pid, self::DONE_META, 'no-wp-user' );
				continue;
			}

			if ( (int) $post->post_author === $user_id ) {
				$out['unchanged']++;
			} else {
				wp_update_post( array(
					'ID'          => $pid,
					'post_author' => $user_id,
				) );
				$out['set']++;
			}
			update_post_meta( $pid, self::DONE_META, 'set:' . $user_id );
		}

		return $out;
	}

	/* ----------------------- internals ----------------------- */

	private function select_posts( $limit ) {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
			 WHERE p.post_type = 'post' AND p.post_status = 'publish'
			   AND pm.meta_id IS NULL
			 ORDER BY p.post_date DESC LIMIT %d",
			self::DONE_META,
			$limit
		) ) );
	}

	/**
	 * Look up an existing WP user that should map to this Squarespace author.
	 * Match priority:
	 *   1. WP user with usermeta sqs71_author_id == $author_id
	 *   2. WP user with display_name matching name (case-insensitive)
	 *   3. WP user with first+last name match
	 */
	private function find_wp_user( $row ) {
		// 1) by stored squarespace author id
		$users = get_users( array(
			'meta_key'   => self::SQS_AUTHOR_ID_META,
			'meta_value' => $row['author_id'],
			'number'     => 1,
		) );
		if ( $users ) return $users[0];

		// 2) by display_name (exact, case-insensitive)
		$name = trim( $row['name'] );
		if ( $name ) {
			$users = get_users( array(
				'search'         => $name,
				'search_columns' => array( 'display_name' ),
				'number'         => 1,
			) );
			if ( $users ) return $users[0];

			global $wpdb;
			$id = $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->users} WHERE LOWER(display_name) = LOWER(%s) LIMIT 1",
				$name
			) );
			if ( $id ) return get_user_by( 'id', $id );
		}

		// 3) by first+last matching display_name
		$first = trim( $row['first'] );
		$last  = trim( $row['last'] );
		if ( $first && $last ) {
			$combo = trim( $first . ' ' . $last );
			global $wpdb;
			$id = $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->users} WHERE LOWER(display_name) = LOWER(%s) LIMIT 1",
				$combo
			) );
			if ( $id ) return get_user_by( 'id', $id );
		}

		return null;
	}

	private function create_wp_user( $author_id, $entry, $email_domain, $logger ) {
		$name  = trim( $entry['author_name'] );
		$first = trim( $entry['author_first'] );
		$last  = trim( $entry['author_last'] );
		if ( ! $name ) {
			$name = trim( $first . ' ' . $last );
		}
		if ( ! $name ) {
			$logger( "  cannot create WP user — Squarespace author $author_id has no name" );
			return null;
		}

		$base_login = sanitize_user( strtolower( str_replace( ' ', '.', $name ) ), true );
		if ( ! $base_login ) $base_login = 'sqs71-' . substr( $author_id, 0, 8 );
		$login = $base_login;
		$i = 2;
		while ( get_user_by( 'login', $login ) ) {
			$login = $base_login . '-' . $i++;
		}

		$email = $login . '@' . $email_domain;

		$user_id = wp_insert_user( array(
			'user_login'   => $login,
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 32 ),
			'first_name'   => $first,
			'last_name'    => $last,
			'display_name' => $name,
			'role'         => 'author',
		) );

		if ( is_wp_error( $user_id ) ) {
			$logger( "  WP user create failed for $name: " . $user_id->get_error_message() );
			return null;
		}

		update_user_meta( $user_id, self::SQS_AUTHOR_ID_META, $author_id );
		if ( ! empty( $entry['author_avatar'] ) ) {
			update_user_meta( $user_id, '_sqs71_author_avatar_url', esc_url_raw( $entry['author_avatar'] ) );
		}

		$logger( "  CREATED WP user #$user_id ($login) for Squarespace author $name ($author_id)" );
		return get_user_by( 'id', $user_id );
	}
}
