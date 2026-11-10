<?php
/**
 * Plugin Name:       Directory Watch
 * Description:       Alerts you when a plugin or theme you run is closed, removed, renamed or transferred in the WordPress.org directory.
 * Version:           1.0.1
 * Plugin URI:        https://github.com/deepquest-io/directory-watch
 * Author URI:        https://github.com/deepquest-io
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            deepquest
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       directory-watch
 * Domain Path:       /languages
 *
 * @package DirectoryWatch
 */

defined( 'ABSPATH' ) || exit;

define( 'DW_VERSION', '1.0.1' );
define( 'DW_FILE', __FILE__ );
define( 'DW_DIR', plugin_dir_path( __FILE__ ) );
define( 'DW_URL', plugin_dir_url( __FILE__ ) );
define( 'DW_STATE', 'dw_state' );
define( 'DW_SETTINGS', 'dw_settings' );
define( 'DW_LOCK', 'dw_lock' );
define( 'DW_EVENT_CAP', 200 );

/** Maximum bytes we will accept from the directory API. */
define( 'DW_MAX_RESPONSE', 262144 );

require_once DW_DIR . 'includes/class-dw-admin.php';

/* -------------------------------------------------------------------------
 * Settings and state
 * ---------------------------------------------------------------------- */

/**
 * Default settings.
 *
 * @return array
 */
function dw_defaults() {
	return array(
		'check_hours'    => 24,
		'per_run'        => 25,
		'include_themes' => 1,
		'notify'         => 0,
	);
}

/**
 * Current settings.
 *
 * @return array
 */
function dw_settings() {
	$saved = get_option( DW_SETTINGS, array() );

	if ( ! is_array( $saved ) ) {
		$saved = array();
	}

	/**
	 * Filter the runtime settings.
	 *
	 * @param array $settings Settings array.
	 */
	return apply_filters( 'dw_settings', wp_parse_args( $saved, dw_defaults() ) );
}

/**
 * Single setting value.
 *
 * @param string $key Setting key.
 * @return mixed
 */
function dw_setting( $key ) {
	$settings = dw_settings();

	return $settings[ $key ] ?? null;
}

/**
 * Stored inventory and run metadata.
 *
 * @return array{items:array,last_run:int,last_full:int}
 */
function dw_state() {
	$state = get_option( DW_STATE, array() );

	if ( ! is_array( $state ) ) {
		$state = array();
	}

	return wp_parse_args(
		$state,
		array(
			'items'     => array(),
			'last_run'  => 0,
			'last_full' => 0,
		)
	);
}

/**
 * Persist state without autoloading it.
 *
 * @param array $state State array.
 */
function dw_save_state( $state ) {
	update_option( DW_STATE, $state, false );
}

/* -------------------------------------------------------------------------
 * Lifecycle
 * ---------------------------------------------------------------------- */

register_activation_hook( __FILE__, 'dw_activate' );
register_deactivation_hook( __FILE__, 'dw_deactivate' );

/**
 * Schedule the watcher.
 */
function dw_activate() {
	if ( ! wp_next_scheduled( 'dw_check_cron' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'dw_check_cron' );
	}

	update_option( 'dw_version', DW_VERSION, false );
}

/**
 * Clear the scheduled event.
 */
function dw_deactivate() {
	wp_clear_scheduled_hook( 'dw_check_cron' );
	delete_transient( DW_LOCK );
}

/**
 * Run schema/version upgrades.
 */
function dw_maybe_upgrade() {
	if ( get_option( 'dw_version' ) === DW_VERSION ) {
		return;
	}

	update_option( 'dw_version', DW_VERSION, false );

	if ( ! wp_next_scheduled( 'dw_check_cron' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'dw_check_cron' );
	}
}
add_action( 'plugins_loaded', 'dw_maybe_upgrade' );

/* -------------------------------------------------------------------------
 * Inventory
 * ---------------------------------------------------------------------- */

/**
 * Build the list of installed plugins and themes to watch.
 *
 * @return array<string,array{type:string,slug:string,name:string,version:string,active:int,file:string}>
 */
function dw_inventory() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$active  = (array) get_option( 'active_plugins', array() );
	$network = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();
	$items   = array();

	foreach ( get_plugins() as $file => $data ) {
		$slug = dw_slug_from_file( $file );

		if ( '' === $slug ) {
			continue;
		}

		$items[ 'plugin:' . $slug ] = array(
			'type'    => 'plugin',
			'slug'    => $slug,
			'name'    => (string) ( $data['Name'] ?? $slug ),
			'version' => (string) ( $data['Version'] ?? '' ),
			'active'  => ( in_array( $file, $active, true ) || isset( $network[ $file ] ) ) ? 1 : 0,
			'file'    => (string) $file,
		);
	}

	if ( ! dw_setting( 'include_themes' ) ) {
		return $items;
	}

	foreach ( wp_get_themes() as $slug => $theme ) {
		$slug = sanitize_key( (string) $slug );

		if ( '' === $slug ) {
			continue;
		}

		$items[ 'theme:' . $slug ] = array(
			'type'    => 'theme',
			'slug'    => $slug,
			'name'    => (string) $theme->get( 'Name' ),
			'version' => (string) $theme->get( 'Version' ),
			'active'  => ( get_stylesheet() === $slug ) ? 1 : 0,
			'file'    => $slug,
		);
	}

	return $items;
}

/**
 * Derive the directory slug from a plugin file path.
 *
 * "akismet/akismet.php" -> "akismet"; "hello.php" -> "hello".
 *
 * @param string $file Plugin file path.
 * @return string
 */
function dw_slug_from_file( $file ) {
	$file = (string) $file;

	if ( '' === $file ) {
		return '';
	}

	if ( false !== strpos( $file, '/' ) ) {
		$parts = explode( '/', $file );
		$slug  = $parts[0];
	} else {
		$slug = preg_replace( '/\.php$/i', '', basename( $file ) );
	}

	return sanitize_key( (string) $slug );
}

/* -------------------------------------------------------------------------
 * Directory API
 * ---------------------------------------------------------------------- */

/**
 * Coerce an author value to a plain string.
 *
 * The plugin API returns a string; the theme API returns an object with
 * user_nicename / display_name. Both end up as stripped text.
 *
 * @param mixed $author Raw author value.
 * @return string
 */
function dw_author_to_string( $author ) {
	if ( is_array( $author ) ) {
		foreach ( array( 'display_name', 'user_nicename', 'name' ) as $key ) {
			if ( ! empty( $author[ $key ] ) && is_scalar( $author[ $key ] ) ) {
				$author = (string) $author[ $key ];
				break;
			}
		}

		if ( is_array( $author ) ) {
			return '';
		}
	}

	if ( ! is_scalar( $author ) ) {
		return '';
	}

	return sanitize_text_field( wp_strip_all_tags( (string) $author ) );
}

/**
 * Normalize an author string for comparison.
 *
 * The directory returns markup such as "<a href="...">Name</a>".
 *
 * @param string $author Raw author value.
 * @return string
 */
function dw_normalize_author( $author ) {
	$author = wp_strip_all_tags( (string) $author );
	$author = html_entity_decode( $author, ENT_QUOTES, 'UTF-8' );
	$author = preg_replace( '/\s+/', ' ', $author );

	return trim( strtolower( (string) $author ) );
}

/**
 * Normalize a display name for comparison.
 *
 * @param string $name Raw name.
 * @return string
 */
function dw_normalize_name( $name ) {
	$name = html_entity_decode( wp_strip_all_tags( (string) $name ), ENT_QUOTES, 'UTF-8' );
	$name = preg_replace( '/\s+/', ' ', $name );

	return trim( strtolower( (string) $name ) );
}

/**
 * Query the directory for one item.
 *
 * Only api.wordpress.org is ever contacted; the URL is built here and there is
 * no user-supplied host. The body is size-capped before decoding.
 *
 * @param string $type "plugin" or "theme".
 * @param string $slug Sanitized slug.
 * @return array|WP_Error Normalized record or an error.
 */
function dw_query_directory( $type, $slug ) {
	$slug = sanitize_key( $slug );

	if ( '' === $slug ) {
		return new WP_Error( 'dw_bad_slug', __( 'Empty slug.', 'directory-watch' ) );
	}

	if ( 'theme' === $type ) {
		$url = add_query_arg(
			array(
				'action'                     => 'theme_information',
				'request[slug]'              => $slug,
				'request[fields][sections]'  => '0',
				'request[fields][screenshots]' => '0',
				'request[fields][description]' => '0',
			),
			'https://api.wordpress.org/themes/info/1.2/'
		);
	} else {
		$url = add_query_arg(
			array(
				'action'                       => 'plugin_information',
				'request[slug]'                => $slug,
				'request[fields][sections]'    => '0',
				'request[fields][screenshots]' => '0',
				'request[fields][description]' => '0',
				'request[fields][versions]'    => '0',
				'request[fields][icons]'       => '0',
				'request[fields][banners]'     => '0',
			),
			'https://api.wordpress.org/plugins/info/1.2/'
		);
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 8,
			'redirection' => 1,
			'user-agent'  => 'DirectoryWatch/' . DW_VERSION . '; ' . home_url(),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	/*
	 * The directory answers 404 for "not found" AND for "closed", both with a
	 * JSON body. Treating 404 as a transport failure would silently hide the
	 * two states this plugin exists to report.
	 */
	if ( 200 !== $code && 404 !== $code ) {
		return new WP_Error( 'dw_http', sprintf( 'HTTP %d', $code ) );
	}

	$body = (string) wp_remote_retrieve_body( $response );

	if ( '' === $body || strlen( $body ) > DW_MAX_RESPONSE ) {
		return new WP_Error( 'dw_body', __( 'Unexpected response size.', 'directory-watch' ) );
	}

	$data = json_decode( $body, true, 8 );

	if ( ! is_array( $data ) ) {
		return new WP_Error( 'dw_json', __( 'Response was not valid JSON.', 'directory-watch' ) );
	}

	return array(
		'slug'     => $slug,
		'type'     => ( 'theme' === $type ) ? 'theme' : 'plugin',
		'error'    => ( isset( $data['error'] ) && is_scalar( $data['error'] ) ) ? sanitize_key( (string) $data['error'] ) : '',
		'name'     => ( isset( $data['name'] ) && is_scalar( $data['name'] ) ) ? sanitize_text_field( (string) $data['name'] ) : '',
		'author'   => dw_author_to_string( $data['author'] ?? '' ),
		'homepage' => ( isset( $data['homepage'] ) && is_scalar( $data['homepage'] ) ) ? esc_url_raw( (string) $data['homepage'] ) : '',
		'version'  => ( isset( $data['version'] ) && is_scalar( $data['version'] ) ) ? sanitize_text_field( (string) $data['version'] ) : '',
		'closed'   => ( isset( $data['closed_date'] ) && is_scalar( $data['closed_date'] ) ) ? sanitize_text_field( (string) $data['closed_date'] ) : '',
		'reason'   => ( isset( $data['reason_text'] ) && is_scalar( $data['reason_text'] ) ) ? sanitize_text_field( (string) $data['reason_text'] ) : '',
	);
}

/* -------------------------------------------------------------------------
 * Comparison and status model
 * ---------------------------------------------------------------------- */

/**
 * Compare a fresh directory record against the stored baseline.
 *
 * Returns the new status plus a human readable detail string. A slug that was
 * never in the directory is recorded as "unlisted" and never alerts — private
 * and custom plugins would otherwise look like removals on the first run.
 *
 * @param array|null $previous Previous record, or null on first sight.
 * @param array      $fresh    Fresh record from dw_query_directory().
 * @return array{status:string,detail:string,in_directory:int}
 */
function dw_evaluate( $previous, $fresh ) {
	$error = (string) ( $fresh['error'] ?? '' );

	// "closed" means the directory has disabled downloads for this slug.
	if ( 'closed' === $error ) {
		$detail = __( 'Closed in the directory.', 'directory-watch' );

		if ( '' !== (string) $fresh['closed'] ) {
			$detail = sprintf(
				/* translators: %s: date the plugin was closed. */
				__( 'Closed in the directory on %s.', 'directory-watch' ),
				$fresh['closed']
			);
		}

		if ( '' !== (string) ( $fresh['reason'] ?? '' ) ) {
			$detail .= ' ' . sprintf(
				/* translators: %s: reason given by the directory. */
				__( 'Reason: %s.', 'directory-watch' ),
				$fresh['reason']
			);
		}

		return array(
			'status'       => 'closed',
			'detail'       => $detail,
			'in_directory' => 1,
		);
	}

	// Any other error for a known slug means it is no longer listed.
	if ( '' !== $error ) {
		if ( $previous && ! empty( $previous['in_directory'] ) ) {
			return array(
				'status'       => 'removed',
				'detail'       => __( 'No longer listed in the directory.', 'directory-watch' ),
				'in_directory' => 0,
			);
		}

		return array(
			'status'       => 'unlisted',
			'detail'       => __( 'Not hosted in the WordPress.org directory.', 'directory-watch' ),
			'in_directory' => 0,
		);
	}

	if ( ! $previous ) {
		return array(
			'status'       => 'ok',
			'detail'       => '',
			'in_directory' => 1,
		);
	}

	// Ownership change is the highest-signal drift: a transfer can ship new code
	// under a name users already trust.
	$prev_author = dw_normalize_author( $previous['author'] ?? '' );
	$new_author  = dw_normalize_author( $fresh['author'] );

	if ( '' !== $prev_author && '' !== $new_author && $prev_author !== $new_author ) {
		return array(
			'status'       => 'transferred',
			'detail'       => sprintf(
				/* translators: 1: previous author, 2: current author. */
				__( 'Owner changed from %1$s to %2$s.', 'directory-watch' ),
				$previous['author'],
				wp_strip_all_tags( (string) $fresh['author'] )
			),
			'in_directory' => 1,
		);
	}

	$prev_name = dw_normalize_name( $previous['name'] ?? '' );
	$new_name  = dw_normalize_name( $fresh['name'] );

	if ( '' !== $prev_name && '' !== $new_name && $prev_name !== $new_name ) {
		return array(
			'status'       => 'renamed',
			'detail'       => sprintf(
				/* translators: 1: previous name, 2: current name. */
				__( 'Renamed from %1$s to %2$s.', 'directory-watch' ),
				$previous['name'],
				$fresh['name']
			),
			'in_directory' => 1,
		);
	}

	if ( empty( $previous['in_directory'] ) ) {
		return array(
			'status'       => 'listed',
			'detail'       => __( 'Listed in the directory again.', 'directory-watch' ),
			'in_directory' => 1,
		);
	}

	return array(
		'status'       => 'ok',
		'detail'       => '',
		'in_directory' => 1,
	);
}

/* -------------------------------------------------------------------------
 * Checking
 * ---------------------------------------------------------------------- */

/**
 * Check a batch of items whose data is stale.
 *
 * @param int  $limit           Maximum items to check.
 * @param bool $ignore_schedule Check items regardless of age.
 * @param int  $budget          Soft wall-clock budget in seconds. A shared host
 *                              will kill a 60-item sweep before it finishes and
 *                              leave both the lock and the run half-done, so the
 *                              loop stops cleanly instead of being cut off.
 * @return array{checked:int,due:int,changed:array}
 */
function dw_check_batch( $limit = 0, $ignore_schedule = false, $budget = 20 ) {
	if ( get_transient( DW_LOCK ) ) {
		return array(
			'checked' => 0,
			'due'     => 0,
			'changed' => array(),
		);
	}

	// Lock for five minutes so overlapping cron runs and a manual "Check now"
	// cannot hammer the directory API at the same time.
	set_transient( DW_LOCK, 1, 5 * MINUTE_IN_SECONDS );

	$limit     = $limit > 0 ? (int) $limit : (int) dw_setting( 'per_run' );
	$limit     = min( 100, max( 1, $limit ) );
	$window    = max( 1, (int) dw_setting( 'check_hours' ) ) * HOUR_IN_SECONDS;
	$state     = dw_state();
	$existing  = $state['items'];
	$inventory = dw_inventory();
	$now       = time();
	$started   = microtime( true );
	$checked  = 0;
	$due      = 0;
	$changed  = array();

	foreach ( $inventory as $key => $item ) {
		$previous = isset( $existing[ $key ] ) ? $existing[ $key ] : null;
		$last     = $previous ? (int) ( $previous['last_checked'] ?? 0 ) : 0;

		if ( ! $ignore_schedule && $last > 0 && ( $now - $last ) < $window ) {
			continue;
		}

		++$due;

		// Stop cleanly on the time budget rather than being killed mid-request.
		if ( $checked >= $limit || ( microtime( true ) - $started ) > $budget ) {
			continue;
		}

		$fresh = dw_query_directory( $item['type'], $item['slug'] );

		if ( is_wp_error( $fresh ) ) {
			// Transient failure: keep the last known verdict, only note the attempt.
			$state['items'][ $key ] = array(
				'type'         => $item['type'],
				'slug'         => $item['slug'],
				'name'         => $item['name'],
				'version'      => $item['version'],
				'active'       => $item['active'],
				'status'       => $previous['status'] ?? 'unknown',
				'detail'       => $previous['detail'] ?? '',
				'in_directory' => (int) ( $previous['in_directory'] ?? 0 ),
				'author'       => $previous['author'] ?? '',
				'homepage'     => $previous['homepage'] ?? '',
				'last_checked' => $last,
				'last_error'   => $fresh->get_error_message(),
			);

			++$checked;
			continue;
		}

		$verdict = dw_evaluate( $previous, $fresh );

		$state['items'][ $key ] = array(
			'type'         => $item['type'],
			'slug'         => $item['slug'],
			'name'         => ( '' !== $fresh['name'] ) ? $fresh['name'] : $item['name'],
			'version'      => $item['version'],
			'active'       => $item['active'],
			'status'       => $verdict['status'],
			'detail'       => $verdict['detail'],
			'in_directory' => $verdict['in_directory'],
			'author'       => wp_strip_all_tags( (string) $fresh['author'] ),
			'homepage'     => $fresh['homepage'],
			'last_checked' => $now,
			'last_error'   => '',
		);

		if ( $previous && ( $previous['status'] ?? '' ) !== $verdict['status'] ) {
			$event = dw_record_event( $item, $previous['status'] ?? '', $verdict );

			if ( $event ) {
				$changed[] = $event;
			}
		}

		++$checked;
	}

	// Drop records for things that are no longer installed.
	foreach ( array_keys( $state['items'] ) as $key ) {
		if ( ! isset( $inventory[ $key ] ) ) {
			unset( $state['items'][ $key ] );
		}
	}

	$state['last_run'] = $now;

	if ( $due <= $checked ) {
		$state['last_full'] = $now;
	}

	dw_save_state( $state );
	delete_transient( DW_LOCK );

	if ( $changed ) {
		dw_maybe_notify( $changed );
	}

	return array(
		'checked' => $checked,
		'due'     => $due,
		'changed' => $changed,
	);
}

/**
 * Append an event for a status change.
 *
 * @param array  $item    Inventory item.
 * @param string $from    Previous status.
 * @param array  $verdict New verdict.
 * @return array|null
 */
function dw_record_event( $item, $from, $verdict ) {
	$state  = dw_state();
	$events = isset( $state['events'] ) && is_array( $state['events'] ) ? $state['events'] : array();

	$event = array(
		'time'   => time(),
		'type'   => $item['type'],
		'slug'   => $item['slug'],
		'name'   => $item['name'],
		'from'   => (string) $from,
		'to'     => $verdict['status'],
		'detail' => $verdict['detail'],
		'seen'   => 0,
	);

	array_unshift( $events, $event );
	$events = array_slice( $events, 0, DW_EVENT_CAP );

	$state['events'] = $events;
	dw_save_state( $state );

	return $event;
}

/**
 * Cron entry point.
 */
function dw_cron_check() {
	dw_check_batch( (int) dw_setting( 'per_run' ) );
}
add_action( 'dw_check_cron', 'dw_cron_check' );

/* -------------------------------------------------------------------------
 * Notifications
 * ---------------------------------------------------------------------- */

/**
 * Which statuses are considered worth an email.
 *
 * @return array
 */
function dw_alert_statuses() {
	/**
	 * Filter which status changes trigger an email.
	 *
	 * @param array $statuses Status slugs.
	 */
	return apply_filters( 'dw_alert_statuses', array( 'closed', 'removed', 'transferred' ) );
}

/**
 * Email the site admin about new high-signal changes.
 *
 * @param array $changed Changed events from this run.
 */
function dw_maybe_notify( $changed ) {
	if ( ! dw_setting( 'notify' ) ) {
		return;
	}

	$alerts = array();

	foreach ( (array) $changed as $event ) {
		if ( in_array( $event['to'], dw_alert_statuses(), true ) ) {
			$alerts[] = $event;
		}
	}

	if ( ! $alerts ) {
		return;
	}

	$to      = get_option( 'admin_email' );
	$subject = sprintf(
		/* translators: %s: site name. */
		__( '[%s] A plugin or theme you run changed in the WordPress.org directory', 'directory-watch' ),
		wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
	);

	// Header injection guard: no CR/LF may survive into the subject line.
	$subject = str_replace( array( "\r", "\n" ), ' ', $subject );

	$lines = array( __( 'Directory Watch detected the following changes:', 'directory-watch' ), '' );

	foreach ( $alerts as $event ) {
		$lines[] = sprintf(
			'- %s (%s/%s): %s -> %s. %s',
			$event['name'],
			$event['type'],
			$event['slug'],
			$event['from'],
			$event['to'],
			$event['detail']
		);
	}

	$lines[] = '';
	$lines[] = sprintf(
		/* translators: %s: admin URL. */
		__( 'Review the inventory: %s', 'directory-watch' ),
		admin_url( 'admin.php?page=directory-watch' )
	);

	wp_mail( $to, $subject, implode( "\n", $lines ) );
}
