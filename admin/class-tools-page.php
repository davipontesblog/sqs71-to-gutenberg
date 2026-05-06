<?php
/**
 * Tools → Squarespace Converter admin page.
 *
 * @package Sqs71ToGutenberg
 */

namespace Sqs71ToGutenberg\Admin;

use Sqs71ToGutenberg\Post_Rewriter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tools_Page {

	const SLUG  = 'sqs71-to-gutenberg';
	const NONCE = 'sqs71_to_gutenberg_run';

	public function hook() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_save_settings' ) );
		add_action( 'wp_ajax_sqs71_run_batch', array( $this, 'ajax_run_batch' ) );
	}

	public function register_menu() {
		add_management_page(
			__( 'Squarespace → Gutenberg', 'sqs71-to-gutenberg' ),
			__( 'Squarespace → Gutenberg', 'sqs71-to-gutenberg' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render_page' )
		);
	}

	public function maybe_save_settings() {
		if ( empty( $_POST['sqs71_save_settings'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( self::NONCE );

		$settings = sqs71_to_gutenberg_default_settings();

		$settings['source_domain']    = esc_url_raw( wp_unslash( $_POST['source_domain'] ?? '' ) );
		$settings['url_pattern']      = sanitize_text_field( wp_unslash( $_POST['url_pattern'] ?? '/{year}/{month}/{day}/{slug}' ) );
		$settings['batch_size']       = max( 1, min( 50, (int) ( $_POST['batch_size'] ?? 5 ) ) );
		$settings['dry_run']          = ! empty( $_POST['dry_run'] );
		$settings['force_reconvert']  = ! empty( $_POST['force_reconvert'] );
		$settings['date_offset_days'] = (int) ( $_POST['date_offset_days'] ?? 0 );
		$settings['image_quality']    = sanitize_text_field( wp_unslash( $_POST['image_quality'] ?? '2500w' ) );
		$settings['request_timeout']  = max( 5, min( 120, (int) ( $_POST['request_timeout'] ?? 30 ) ) );

		update_option( SQS71_TO_GUTENBERG_OPTION, $settings );
		add_settings_error( 'sqs71', 'saved', __( 'Settings saved.', 'sqs71-to-gutenberg' ), 'success' );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = sqs71_to_gutenberg_get_settings();

		settings_errors( 'sqs71' );

		$counts = $this->collect_post_counts();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Squarespace 7.1 → Gutenberg Converter', 'sqs71-to-gutenberg' ); ?></h1>
			<p><?php esc_html_e( 'Re-scrapes the live source site and rewrites WordPress post content as proper Gutenberg block markup. Use Dry Run first to preview the AST before saving.', 'sqs71-to-gutenberg' ); ?></p>

			<h2><?php esc_html_e( 'Settings', 'sqs71-to-gutenberg' ); ?></h2>
			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="source_domain"><?php esc_html_e( 'Source domain', 'sqs71-to-gutenberg' ); ?></label></th>
						<td>
							<input type="url" name="source_domain" id="source_domain" class="regular-text" value="<?php echo esc_attr( $settings['source_domain'] ); ?>" placeholder="https://www.example.com" />
							<p class="description"><?php esc_html_e( 'The live Squarespace 7.1 site we are scraping from. No trailing slash.', 'sqs71-to-gutenberg' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="url_pattern"><?php esc_html_e( 'Post URL pattern', 'sqs71-to-gutenberg' ); ?></label></th>
						<td>
							<input type="text" name="url_pattern" id="url_pattern" class="regular-text" value="<?php echo esc_attr( $settings['url_pattern'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Placeholders: {year} {month} {day} {slug}. Example: /walkabout-chronicles/{year}/{month}/{day}/{slug}', 'sqs71-to-gutenberg' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="batch_size"><?php esc_html_e( 'Batch size', 'sqs71-to-gutenberg' ); ?></label></th>
						<td><input type="number" name="batch_size" id="batch_size" min="1" max="50" value="<?php echo esc_attr( $settings['batch_size'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="dry_run"><?php esc_html_e( 'Dry run', 'sqs71-to-gutenberg' ); ?></label></th>
						<td><label><input type="checkbox" name="dry_run" id="dry_run" value="1" <?php checked( $settings['dry_run'] ); ?> /> <?php esc_html_e( 'Preview only — do not write to posts.', 'sqs71-to-gutenberg' ); ?></label></td>
					</tr>
					<tr>
						<th><label for="force_reconvert"><?php esc_html_e( 'Force reconvert', 'sqs71-to-gutenberg' ); ?></label></th>
						<td><label><input type="checkbox" name="force_reconvert" id="force_reconvert" value="1" <?php checked( $settings['force_reconvert'] ); ?> /> <?php esc_html_e( 'Re-run on posts already marked converted.', 'sqs71-to-gutenberg' ); ?></label></td>
					</tr>
					<tr>
						<th><label for="date_offset_days"><?php esc_html_e( 'Date offset (days)', 'sqs71-to-gutenberg' ); ?></label></th>
						<td>
							<input type="number" name="date_offset_days" id="date_offset_days" value="<?php echo esc_attr( $settings['date_offset_days'] ); ?>" />
							<p class="description"><?php esc_html_e( 'If imported post dates are off by N days vs. the live site, set this to align URLs. The fetcher also tries ±1 day automatically.', 'sqs71-to-gutenberg' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="image_quality"><?php esc_html_e( 'Image quality (Squarespace ?format=)', 'sqs71-to-gutenberg' ); ?></label></th>
						<td><input type="text" name="image_quality" id="image_quality" value="<?php echo esc_attr( $settings['image_quality'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="request_timeout"><?php esc_html_e( 'Request timeout (seconds)', 'sqs71-to-gutenberg' ); ?></label></th>
						<td><input type="number" name="request_timeout" id="request_timeout" min="5" max="120" value="<?php echo esc_attr( $settings['request_timeout'] ); ?>" /></td>
					</tr>
				</table>
				<p class="submit"><button class="button button-primary" name="sqs71_save_settings" value="1"><?php esc_html_e( 'Save settings', 'sqs71-to-gutenberg' ); ?></button></p>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Run conversion', 'sqs71-to-gutenberg' ); ?></h2>
			<p><?php
				printf(
					/* translators: 1: total posts, 2: converted posts, 3: remaining posts */
					esc_html__( 'Posts in DB: %1$d total · %2$d already converted · %3$d remaining.', 'sqs71-to-gutenberg' ),
					(int) $counts['total'],
					(int) $counts['converted'],
					(int) $counts['remaining']
				);
			?></p>

			<p>
				<label><strong><?php esc_html_e( 'Run on specific post slugs (comma-separated, optional):', 'sqs71-to-gutenberg' ); ?></strong></label><br />
				<input type="text" id="sqs71-slugs" class="regular-text" placeholder="routines, the-shifting, 1600-pennsylvania-avenue-nw" />
				<button type="button" class="button button-primary" id="sqs71-run">
					<?php esc_html_e( 'Run batch', 'sqs71-to-gutenberg' ); ?>
				</button>
				<span id="sqs71-progress" style="margin-left:1em;color:#666"></span>
			</p>

			<h3><?php esc_html_e( 'Log', 'sqs71-to-gutenberg' ); ?></h3>
			<pre id="sqs71-log" style="background:#fff;border:1px solid #ddd;padding:1em;max-height:480px;overflow:auto;font-family:Menlo,Consolas,monospace;font-size:12px"></pre>
		</div>

		<script>
		(function(){
			const btn=document.getElementById('sqs71-run');
			const log=document.getElementById('sqs71-log');
			const progress=document.getElementById('sqs71-progress');
			const slugs=document.getElementById('sqs71-slugs');
			btn.addEventListener('click',async function(){
				btn.disabled=true;
				progress.textContent='Running…';
				log.textContent='';
				try{
					const fd=new FormData();
					fd.append('action','sqs71_run_batch');
					fd.append('_ajax_nonce',<?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>);
					if(slugs.value.trim())fd.append('slugs',slugs.value.trim());
					const r=await fetch(ajaxurl,{method:'POST',credentials:'include',body:fd});
					const j=await r.json();
					log.textContent=JSON.stringify(j,null,2);
					progress.textContent=j.success?('Done. Processed '+(j.data?.results?.length||0)+' post(s).'):'Error.';
				}catch(e){
					log.textContent='Error: '+e.message;
					progress.textContent='Failed.';
				}finally{
					btn.disabled=false;
				}
			});
		})();
		</script>
		<?php
	}

	public function ajax_run_batch() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => 'forbidden' ), 403 );
		}
		check_ajax_referer( self::NONCE );

		$settings = sqs71_to_gutenberg_get_settings();
		if ( empty( $settings['source_domain'] ) ) {
			wp_send_json_error( array( 'error' => 'source_domain not configured' ), 400 );
		}

		$slugs_raw = isset( $_POST['slugs'] ) ? sanitize_text_field( wp_unslash( $_POST['slugs'] ) ) : '';

		$post_ids = array();

		if ( $slugs_raw ) {
			$slugs = array_filter( array_map( 'trim', explode( ',', $slugs_raw ) ) );
			foreach ( $slugs as $slug ) {
				$found = get_posts(
					array(
						'name'           => $slug,
						'post_type'      => 'post',
						'post_status'    => 'any',
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'no_found_rows'  => true,
					)
				);
				if ( $found ) {
					$post_ids[] = (int) $found[0];
				}
			}
		} else {
			$args = array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => (int) $settings['batch_size'],
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			);
			if ( empty( $settings['force_reconvert'] ) ) {
				$args['meta_query'] = array(
					array(
						'key'     => SQS71_TO_GUTENBERG_CONVERTED_META,
						'compare' => 'NOT EXISTS',
					),
				);
			}
			$post_ids = get_posts( $args );
		}

		if ( ! $post_ids ) {
			wp_send_json_success( array( 'results' => array(), 'message' => 'no posts to process' ) );
		}

		// Allow long-running PHP for batch.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

		$rewriter = new Post_Rewriter( $settings );
		$results  = $rewriter->convert_batch( $post_ids );

		wp_send_json_success(
			array(
				'settings_dry_run' => ! empty( $settings['dry_run'] ),
				'count'            => count( $results ),
				'results'          => $results,
			)
		);
	}

	private function collect_post_counts() {
		global $wpdb;
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish'" );
		$converted = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
				 JOIN {$wpdb->posts} p ON p.ID=pm.post_id
				 WHERE pm.meta_key=%s AND p.post_type='post' AND p.post_status='publish'",
				SQS71_TO_GUTENBERG_CONVERTED_META
			)
		);
		return array(
			'total'     => $total,
			'converted' => $converted,
			'remaining' => max( 0, $total - $converted ),
		);
	}
}
