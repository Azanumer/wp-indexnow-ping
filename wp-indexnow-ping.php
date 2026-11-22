<?php
/**
 * Plugin Name: WP IndexNow Ping
 * Plugin URI:  https://github.com/Azanumer/wp-indexnow-ping
 * Description: Instantly notifies Bing (and other IndexNow search engines) when your content is published, updated or deleted — no waiting for the next crawl.
 * Version:     1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author:      Azan Umer
 * Author URI:  https://azanumer.cv
 * License:     MIT
 * Text Domain: wp-indexnow-ping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WIP_VERSION', '1.0.0' );
define( 'WIP_OPTION', 'wip_settings' );
define( 'WIP_LOG_OPTION', 'wip_log' );
define( 'WIP_ENDPOINT', 'https://api.indexnow.org/indexnow' );

/* ------------------------------------------------------------------ */
/* Activation / deactivation                                           */
/* ------------------------------------------------------------------ */

/**
 * On activation: generate the API key (if none) and publish the key file
 * at the site root, as required by the IndexNow protocol.
 */
function wip_activate() {
	$settings = get_option( WIP_OPTION, array() );

	if ( empty( $settings['key'] ) ) {
		$settings['key'] = function_exists( 'wp_generate_password' )
			? wp_generate_password( 32, false )
			: bin2hex( random_bytes( 16 ) );
	}

	$settings = wp_parse_args( $settings, array(
		'submit_on_publish' => 1,
		'submit_on_update'   => 1,
		'submit_on_delete'   => 1,
	) );

	update_option( WIP_OPTION, $settings );
	wip_write_key_file( $settings['key'] );
}
register_activation_hook( __FILE__, 'wip_activate' );

/**
 * Write <key>.txt containing the key into the site root (ABSPATH).
 * Returns true on success, false when the root is not writable.
 */
function wip_write_key_file( $key ) {
	$path = ABSPATH . sanitize_file_name( $key ) . '.txt';
	if ( file_exists( $path ) && file_get_contents( $path ) === $key ) {
		return true;
	}
	$result = @file_put_contents( $path, $key ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	return false !== $result;
}

/**
 * Remove the key file on deactivation (the key itself is kept so a
 * re-activation does not invalidate previously submitted URLs).
 */
function wip_deactivate() {
	$settings = get_option( WIP_OPTION, array() );
	if ( ! empty( $settings['key'] ) ) {
		$path = ABSPATH . sanitize_file_name( $settings['key'] ) . '.txt';
		if ( file_exists( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}
}
register_deactivation_hook( __FILE__, 'wip_deactivate' );

/* ------------------------------------------------------------------ */
/* Submission                                                          */
/* ------------------------------------------------------------------ */

/**
 * Post types whose URLs are submitted. Defaults to all public post types.
 *
 * @return string[]
 */
function wip_post_types() {
	$types = get_post_types( array( 'public' => true ), 'names' );
	unset( $types['attachment'] );
	/**
	 * Filter the post types submitted to IndexNow.
	 *
	 * @param string[] $types Post type slugs.
	 */
	return apply_filters( 'wip_post_types', array_values( $types ) );
}

/**
 * Submit one or more URLs to the IndexNow endpoint.
 *
 * @param string[] $urls Absolute URLs to submit.
 * @return array{ok: bool, code: int, message: string} Result summary.
 */
function wip_submit_urls( $urls ) {
	$settings = get_option( WIP_OPTION, array() );
	$key      = isset( $settings['key'] ) ? $settings['key'] : '';

	if ( '' === $key ) {
		return array( 'ok' => false, 'code' => 0, 'message' => __( 'No IndexNow key configured.', 'wp-indexnow-ping' ) );
	}

	$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', (array) $urls ) ) ) );
	if ( empty( $urls ) ) {
		return array( 'ok' => false, 'code' => 0, 'message' => __( 'No valid URLs to submit.', 'wp-indexnow-ping' ) );
	}

	$host = wp_parse_url( home_url(), PHP_URL_HOST );

	$response = wp_remote_post( WIP_ENDPOINT, array(
		'timeout' => 15,
		'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
		'body'    => wp_json_encode( array(
			'host'        => $host,
			'key'         => $key,
			'keyLocation' => home_url( '/' . $key . '.txt' ),
			'urlList'     => $urls,
		) ),
	) );

	if ( is_wp_error( $response ) ) {
		$result = array( 'ok' => false, 'code' => 0, 'message' => $response->get_error_message() );
	} else {
		$code   = (int) wp_remote_retrieve_response_code( $response );
		$result = array(
			'ok'      => $code >= 200 && $code < 300,
			'code'    => $code,
			'message' => 202 === $code
				? __( 'Accepted — URLs queued for crawling.', 'wp-indexnow-ping' )
				: sprintf(
					/* translators: %d: HTTP status code. */
					__( 'IndexNow returned HTTP %d.', 'wp-indexnow-ping' ),
					$code
				),
		);
	}

	wip_log_submission( $urls, $result );
	return $result;
}

/**
 * Keep the last 10 submissions (newest first) for the settings page.
 */
function wip_log_submission( $urls, $result ) {
	$log   = get_option( WIP_LOG_OPTION, array() );
	$log[] = array(
		'time'    => current_time( 'mysql' ),
		'urls'    => $urls,
		'ok'      => $result['ok'],
		'code'    => $result['code'],
		'message' => $result['message'],
	);
	update_option( WIP_LOG_OPTION, array_slice( $log, -10 ) );
}

/* ------------------------------------------------------------------ */
/* Automatic submission on publish / update / delete                   */
/* ------------------------------------------------------------------ */

/**
 * Submit the permalink when a post enters (or stays in) the published state.
 */
function wip_on_transition_post_status( $new_status, $old_status, $post ) {
	if ( 'publish' !== $new_status ) {
		return;
	}
	if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
		return;
	}
	if ( ! in_array( $post->post_type, wip_post_types(), true ) ) {
		return;
	}
	if ( 'publish' !== get_post_status( $post->ID ) ) {
		return;
	}

	$settings  = get_option( WIP_OPTION, array() );
	$is_update = 'publish' === $old_status;
	$enabled   = $is_update
		? ! empty( $settings['submit_on_update'] )
		: ! empty( $settings['submit_on_publish'] );
	if ( ! $enabled ) {
		return;
	}

	/**
	 * Filter whether a given post should be submitted to IndexNow.
	 *
	 * @param bool    $submit   Whether to submit.
	 * @param WP_Post $post     The post object.
	 * @param bool    $is_update True when the post was already published.
	 */
	if ( ! apply_filters( 'wip_should_submit', true, $post, $is_update ) ) {
		return;
	}

	wip_submit_urls( array( get_permalink( $post->ID ) ) );
}
add_action( 'transition_post_status', 'wip_on_transition_post_status', 10, 3 );

/**
 * Submit the URL of a post that is about to be deleted, so search engines
 * drop it from the index instead of hitting a 404 on the next crawl.
 */
function wip_on_before_delete_post( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || 'publish' !== $post->post_status ) {
		return;
	}
	if ( ! in_array( $post->post_type, wip_post_types(), true ) ) {
		return;
	}
	$settings = get_option( WIP_OPTION, array() );
	if ( empty( $settings['submit_on_delete'] ) ) {
		return;
	}
	wip_submit_urls( array( get_permalink( $post_id ) ) );
}
add_action( 'before_delete_post', 'wip_on_before_delete_post' );

/* ------------------------------------------------------------------ */
/* Settings page                                                       */
/* ------------------------------------------------------------------ */

function wip_register_settings_page() {
	add_options_page(
		__( 'IndexNow Ping', 'wp-indexnow-ping' ),
		__( 'IndexNow Ping', 'wp-indexnow-ping' ),
		'manage_options',
		'wp-indexnow-ping',
		'wip_render_settings_page'
	);
}
add_action( 'admin_menu', 'wip_register_settings_page' );

function wip_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-indexnow-ping' ) );
	}

	$settings = get_option( WIP_OPTION, array() );
	$key      = isset( $settings['key'] ) ? $settings['key'] : '';
	$notice   = '';

	// Regenerate the key.
	if ( isset( $_POST['wip_regenerate'] ) && check_admin_referer( 'wip_settings' ) ) {
		$old_key = $key;
		$key     = function_exists( 'wp_generate_password' )
			? wp_generate_password( 32, false )
			: bin2hex( random_bytes( 16 ) );
		$settings['key'] = $key;
		update_option( WIP_OPTION, $settings );
		$old_path = ABSPATH . sanitize_file_name( $old_key ) . '.txt';
		if ( $old_key && file_exists( $old_path ) ) {
			@unlink( $old_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		wip_write_key_file( $key );
		$notice = __( 'New key generated and key file updated. Re-submit your URLs if search engines have the old key cached.', 'wp-indexnow-ping' );
	}

	// Manual bulk submission.
	if ( isset( $_POST['wip_manual_submit'] ) && check_admin_referer( 'wip_settings' ) ) {
		$raw  = isset( $_POST['wip_urls'] ) ? wp_unslash( $_POST['wip_urls'] ) : '';
		$urls = array_filter( array_map( 'trim', preg_split( "/\r\n|\r|\n/", $raw ) ) );
		$urls = array_slice( $urls, 0, 100 );
		$result = wip_submit_urls( $urls );
		$notice = $result['message'];
	}

	// Save toggles.
	if ( isset( $_POST['wip_save'] ) && check_admin_referer( 'wip_settings' ) ) {
		$settings['submit_on_publish'] = isset( $_POST['wip_submit_on_publish'] ) ? 1 : 0;
		$settings['submit_on_update']  = isset( $_POST['wip_submit_on_update'] ) ? 1 : 0;
		$settings['submit_on_delete']  = isset( $_POST['wip_submit_on_delete'] ) ? 1 : 0;
		update_option( WIP_OPTION, $settings );
		$notice = __( 'Settings saved.', 'wp-indexnow-ping' );
	}

	$key_file_path = ABSPATH . sanitize_file_name( $key ) . '.txt';
	$key_file_ok   = $key && file_exists( $key_file_path ) && file_get_contents( $key_file_path ) === $key;
	$log           = get_option( WIP_LOG_OPTION, array() );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'IndexNow Ping', 'wp-indexnow-ping' ); ?></h1>
		<p><?php esc_html_e( 'IndexNow tells Bing and Yandex about new, updated or deleted URLs the moment they change, instead of waiting for the next crawl. The API key below must be reachable as a text file at your site root.', 'wp-indexnow-ping' ); ?></p>

		<?php if ( $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'wip_settings' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'API key', 'wp-indexnow-ping' ); ?></th>
					<td>
						<code><?php echo esc_html( $key ); ?></code>
						<input type="submit" name="wip_regenerate" class="button" value="<?php esc_attr_e( 'Regenerate key', 'wp-indexnow-ping' ); ?>"
							onclick="return confirm('<?php esc_attr_e( 'Generate a new key? The old key file will be removed.', 'wp-indexnow-ping' ); ?>');">
						<p class="description">
							<?php esc_html_e( 'Key file:', 'wp-indexnow-ping' ); ?>
							<code><?php echo esc_html( home_url( '/' . $key . '.txt' ) ); ?></code>
							<?php if ( $key_file_ok ) : ?>
								<strong style="color:green;"><?php esc_html_e( 'OK — reachable', 'wp-indexnow-ping' ); ?></strong>
							<?php else : ?>
								<strong style="color:red;"><?php esc_html_e( 'Missing or unreadable — create the file manually with the key as its only content.', 'wp-indexnow-ping' ); ?></strong>
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Automatic submissions', 'wp-indexnow-ping' ); ?></th>
					<td>
						<label><input type="checkbox" name="wip_submit_on_publish" value="1" <?php checked( ! empty( $settings['submit_on_publish'] ) ); ?>> <?php esc_html_e( 'On publish', 'wp-indexnow-ping' ); ?></label><br>
						<label><input type="checkbox" name="wip_submit_on_update" value="1" <?php checked( ! empty( $settings['submit_on_update'] ) ); ?>> <?php esc_html_e( 'On update of a published post', 'wp-indexnow-ping' ); ?></label><br>
						<label><input type="checkbox" name="wip_submit_on_delete" value="1" <?php checked( ! empty( $settings['submit_on_delete'] ) ); ?>> <?php esc_html_e( 'On delete (asks engines to drop the URL)', 'wp-indexnow-ping' ); ?></label>
					</td>
				</tr>
			</table>
			<p><input type="submit" name="wip_save" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'wp-indexnow-ping' ); ?>"></p>

			<h2><?php esc_html_e( 'Manual submission', 'wp-indexnow-ping' ); ?></h2>
			<p><?php esc_html_e( 'One URL per line, up to 100 at a time.', 'wp-indexnow-ping' ); ?></p>
			<p><textarea name="wip_urls" rows="6" cols="80" class="large-text code" placeholder="https://example.com/my-post/"></textarea></p>
			<p><input type="submit" name="wip_manual_submit" class="button" value="<?php esc_attr_e( 'Submit URLs now', 'wp-indexnow-ping' ); ?>"></p>
		</form>

		<h2><?php esc_html_e( 'Recent submissions', 'wp-indexnow-ping' ); ?></h2>
		<?php if ( empty( $log ) ) : ?>
			<p><?php esc_html_e( 'No submissions yet.', 'wp-indexnow-ping' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Time', 'wp-indexnow-ping' ); ?></th><th><?php esc_html_e( 'URLs', 'wp-indexnow-ping' ); ?></th><th><?php esc_html_e( 'Result', 'wp-indexnow-ping' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( array_reverse( $log ) as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( $entry['time'] ); ?></td>
						<td><?php echo esc_html( implode( ', ', $entry['urls'] ) ); ?></td>
						<td><?php echo $entry['ok'] ? '<strong style="color:green;">' : '<strong style="color:red;">'; ?><?php echo esc_html( $entry['code'] ? 'HTTP ' . $entry['code'] . ' — ' : '' ); ?><?php echo esc_html( $entry['message'] ); ?></strong></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}
