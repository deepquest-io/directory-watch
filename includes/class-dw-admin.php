<?php
/**
 * Admin screen, settings and manual checks.
 *
 * @package DirectoryWatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Directory Watch screen.
 */
class DW_Admin {

	/**
	 * Capability required for every action on this screen.
	 */
	const CAP = 'manage_options';

	/**
	 * Settings group name.
	 */
	const GROUP = 'dw_settings_group';

	/**
	 * Hook everything up.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_dw_check_now', array( $this, 'handle_check_now' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DW_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Add the menu entry.
	 */
	public function menu() {
		add_menu_page(
			__( 'Directory Watch', 'directory-watch' ),
			__( 'Directory Watch', 'directory-watch' ),
			self::CAP,
			'directory-watch',
			array( $this, 'render_page' ),
			'dashicons-visibility',
			81
		);
	}

	/**
	 * Add a link on the plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=directory-watch' );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Inventory', 'directory-watch' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Register the settings option.
	 */
	public function register_settings() {
		register_setting(
			self::GROUP,
			DW_SETTINGS,
			array(
				'type'              => 'array',
				'default'           => dw_defaults(),
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);
	}

	/**
	 * Sanitize the settings form.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();

		return array(
			'check_hours'    => dw_clamp( $input['check_hours'] ?? null, 1, 168, 24 ),
			'per_run'        => dw_clamp( $input['per_run'] ?? null, 1, 100, 25 ),
			'include_themes' => empty( $input['include_themes'] ) ? 0 : 1,
			'notify'         => empty( $input['notify'] ) ? 0 : 1,
		);
	}

	/**
	 * Load styles on our screen only.
	 *
	 * @param string $hook Current screen hook.
	 */
	public function assets( $hook ) {
		if ( 'toplevel_page_directory-watch' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'dw-admin', DW_URL . 'assets/dw-admin.css', array(), DW_VERSION );
	}

	/**
	 * Run a manual check of every installed item.
	 */
	public function handle_check_now() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to run this check.', 'directory-watch' ), 403 );
		}

		check_admin_referer( 'dw_check_now' );

		// One manual sweep per minute: repeated clicks would otherwise turn an
		// admin page into a request amplifier against api.wordpress.org.
		if ( get_transient( 'dw_manual_cooldown' ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'directory-watch', 'throttled' => 1 ), admin_url( 'admin.php' ) ) );
			exit;
		}

		set_transient( 'dw_manual_cooldown', 1, 15 );

		// Force a full sweep: ignore the "already checked recently" window but
		// keep the per-run cap so a large install cannot stall the request.
		$result = dw_check_batch( 100, true, 20 );
		$left   = max( 0, (int) $result['due'] - (int) $result['checked'] );

		$args = array(
			'page'    => 'directory-watch',
			'checked' => (int) $result['checked'],
			'changed' => count( $result['changed'] ),
		);

		if ( $left > 0 ) {
			$args['left'] = $left;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Human label for a status.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	private function status_label( $status ) {
		$labels = array(
			'ok'          => __( 'Listed', 'directory-watch' ),
			'listed'      => __( 'Listed again', 'directory-watch' ),
			'closed'      => __( 'Closed', 'directory-watch' ),
			'removed'     => __( 'Removed', 'directory-watch' ),
			'transferred' => __( 'New owner', 'directory-watch' ),
			'renamed'     => __( 'Renamed', 'directory-watch' ),
			'unlisted'    => __( 'Not in directory', 'directory-watch' ),
			'unknown'     => __( 'Unknown', 'directory-watch' ),
		);

		return $labels[ $status ] ?? __( 'Unknown', 'directory-watch' );
	}

	/**
	 * Is this status a problem the user should act on?
	 *
	 * @param string $status Status slug.
	 * @return bool
	 */
	private function is_alert( $status ) {
		return in_array( $status, array( 'closed', 'removed', 'transferred' ), true );
	}

	/**
	 * Render the screen.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$state      = dw_state();
		$settings   = dw_settings();
		$inventory  = dw_inventory();
		$records    = $state['items'];
		$checked    = isset( $_GET['checked'] ) ? absint( $_GET['checked'] ) : -1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$changed    = isset( $_GET['changed'] ) ? absint( $_GET['changed'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$events     = isset( $state['events'] ) && is_array( $state['events'] ) ? $state['events'] : array();
		$counts     = array(
			'ok'       => 0,
			'attention' => 0,
			'unlisted' => 0,
			'unknown'  => 0,
		);

		foreach ( $inventory as $key => $item ) {
			$status = $records[ $key ]['status'] ?? 'unknown';

			if ( 'ok' === $status || 'listed' === $status ) {
				++$counts['ok'];
			} elseif ( $this->is_alert( $status ) ) {
				++$counts['attention'];
			} elseif ( 'unlisted' === $status ) {
				++$counts['unlisted'];
			} else {
				++$counts['unknown'];
			}
		}
		?>
		<div class="wrap dw-wrap">
			<h1><?php esc_html_e( 'Directory Watch', 'directory-watch' ); ?></h1>

			<?php if ( isset( $_GET['throttled'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-info is-dismissible">
					<p><?php esc_html_e( 'A check ran less than a minute ago. Wait a moment before running another.', 'directory-watch' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['left'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-info is-dismissible">
					<p>
						<?php
						printf(
							/* translators: %d: number of items still queued. */
							esc_html__( '%d item(s) still queued. Press "Check now" again to continue — each pass stops before the server time limit.', 'directory-watch' ),
							absint( $_GET['left'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $checked >= 0 ) : ?>
				<div class="notice notice-<?php echo $changed > 0 ? 'warning' : 'success'; ?> is-dismissible">
					<p>
						<?php
						printf(
							/* translators: 1: number of items checked, 2: number of changes. */
							esc_html__( 'Checked %1$d item(s). %2$d change(s) detected.', 'directory-watch' ),
							(int) $checked,
							(int) $changed
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<p class="description dw-intro">
				<?php esc_html_e( 'Every installed plugin and theme is compared against the WordPress.org directory. A listing that closes, disappears, changes name or changes owner is reported here.', 'directory-watch' ); ?>
			</p>

			<div class="dw-stats">
				<div class="dw-stat dw-stat-ok">
					<span><?php echo esc_html( (string) $counts['ok'] ); ?></span>
					<small><?php esc_html_e( 'Listed and normal', 'directory-watch' ); ?></small>
				</div>
				<div class="dw-stat<?php echo $counts['attention'] ? ' dw-stat-bad' : ''; ?>">
					<span><?php echo esc_html( (string) $counts['attention'] ); ?></span>
					<small><?php esc_html_e( 'Needs attention', 'directory-watch' ); ?></small>
				</div>
				<div class="dw-stat">
					<span><?php echo esc_html( (string) $counts['unlisted'] ); ?></span>
					<small><?php esc_html_e( 'Not in the directory', 'directory-watch' ); ?></small>
				</div>
				<div class="dw-stat">
					<span><?php echo esc_html( (string) $counts['unknown'] ); ?></span>
					<small><?php esc_html_e( 'Not checked yet', 'directory-watch' ); ?></small>
				</div>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dw-actions">
				<input type="hidden" name="action" value="dw_check_now">
				<?php wp_nonce_field( 'dw_check_now' ); ?>
				<?php submit_button( __( 'Check now', 'directory-watch' ), 'primary', 'submit', false ); ?>
				<span class="description">
					<?php
					if ( ! empty( $state['last_run'] ) ) {
						printf(
							/* translators: %s: human readable time difference. */
							esc_html__( 'Last check: %s ago.', 'directory-watch' ),
							esc_html( human_time_diff( (int) $state['last_run'], time() ) )
						);
					} else {
						esc_html_e( 'Not checked yet — the first pass runs on the next cron tick, or press the button.', 'directory-watch' );
					}
					?>
				</span>
			</form>

			<table class="wp-list-table widefat fixed striped dw-table">
				<thead>
					<tr>
						<th class="dw-col-name"><?php esc_html_e( 'Plugin / theme', 'directory-watch' ); ?></th>
						<th class="dw-col-version"><?php esc_html_e( 'Version', 'directory-watch' ); ?></th>
						<th class="dw-col-status"><?php esc_html_e( 'Directory status', 'directory-watch' ); ?></th>
						<th><?php esc_html_e( 'Detail', 'directory-watch' ); ?></th>
						<th class="dw-col-checked"><?php esc_html_e( 'Checked', 'directory-watch' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $inventory ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No plugins or themes found.', 'directory-watch' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $inventory as $key => $item ) : ?>
						<?php
						$record = $records[ $key ] ?? array();
						$status = $record['status'] ?? 'unknown';
						$detail = $record['detail'] ?? '';
						$last   = (int) ( $record['last_checked'] ?? 0 );
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $item['name'] ); ?></strong>
								<?php if ( $item['active'] ) : ?>
									<span class="dw-badge dw-badge-active"><?php esc_html_e( 'active', 'directory-watch' ); ?></span>
								<?php endif; ?>
								<div class="dw-slug">
									<?php echo esc_html( $item['type'] . ':' . $item['slug'] ); ?>
									<?php if ( ! empty( $record['homepage'] ) ) : ?>
										&middot; <a href="<?php echo esc_url( $record['homepage'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'listing', 'directory-watch' ); ?></a>
									<?php endif; ?>
								</div>
							</td>
							<td><?php echo esc_html( '' !== $item['version'] ? $item['version'] : '—' ); ?></td>
							<td>
								<span class="dw-status dw-status-<?php echo esc_attr( $status ); ?>">
									<?php echo esc_html( $this->status_label( $status ) ); ?>
								</span>
							</td>
							<td class="dw-detail">
								<?php
								echo esc_html( $detail );

								if ( ! empty( $record['last_error'] ) ) {
									echo ' <em>' . esc_html( $record['last_error'] ) . '</em>';
								}
								?>
							</td>
							<td class="dw-checked">
								<?php
								echo $last > 0
									? esc_html( human_time_diff( $last, time() ) . ' ' . __( 'ago', 'directory-watch' ) )
									: esc_html__( 'never', 'directory-watch' );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $events ) : ?>
				<h2><?php esc_html_e( 'Change history', 'directory-watch' ); ?></h2>
				<ul class="dw-events">
					<?php foreach ( array_slice( $events, 0, 25 ) as $event ) : ?>
						<li class="<?php echo $this->is_alert( (string) $event['to'] ) ? 'dw-event-alert' : ''; ?>">
							<code><?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $event['time'] ) ); ?></code>
							<strong><?php echo esc_html( $event['name'] ); ?></strong>
							<?php
							printf(
								/* translators: 1: previous status, 2: new status. */
								esc_html__( '%1$s to %2$s', 'directory-watch' ),
								'<em>' . esc_html( $this->status_label( (string) $event['from'] ) ) . '</em>',
								'<em>' . esc_html( $this->status_label( (string) $event['to'] ) ) . '</em>'
							);
							?>
							<?php if ( ! empty( $event['detail'] ) ) : ?>
								— <?php echo esc_html( $event['detail'] ); ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<hr>

			<h2><?php esc_html_e( 'Settings', 'directory-watch' ); ?></h2>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="dw-check-hours"><?php esc_html_e( 'Re-check each item every', 'directory-watch' ); ?></label></th>
						<td>
							<input type="number" id="dw-check-hours" name="<?php echo esc_attr( DW_SETTINGS ); ?>[check_hours]" value="<?php echo esc_attr( $settings['check_hours'] ); ?>" min="1" max="168" class="small-text">
							<?php esc_html_e( 'hours', 'directory-watch' ); ?>
							<p class="description"><?php esc_html_e( 'The directory changes slowly; daily is plenty. Shorter intervals only cost API calls.', 'directory-watch' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dw-per-run"><?php esc_html_e( 'Items per run', 'directory-watch' ); ?></label></th>
						<td>
							<input type="number" id="dw-per-run" name="<?php echo esc_attr( DW_SETTINGS ); ?>[per_run]" value="<?php echo esc_attr( $settings['per_run'] ); ?>" min="1" max="100" class="small-text">
							<p class="description"><?php esc_html_e( 'How many items one cron pass may query. Larger sites are spread over several passes.', 'directory-watch' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Scope', 'directory-watch' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( DW_SETTINGS ); ?>[include_themes]" value="1" <?php checked( $settings['include_themes'] ); ?>> <?php esc_html_e( 'Also watch installed themes', 'directory-watch' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email alerts', 'directory-watch' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( DW_SETTINGS ); ?>[notify]" value="1" <?php checked( $settings['notify'] ); ?>> <?php esc_html_e( 'Email me when a listing is closed, removed or changes owner', 'directory-watch' ); ?></label>
							<p class="description">
								<?php
								printf(
									/* translators: %s: email address. */
									esc_html__( 'Sent to %s using wp_mail().', 'directory-watch' ),
									'<code>' . esc_html( (string) get_option( 'admin_email' ) ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

/**
 * Clamp an integer.
 *
 * @param mixed $value    Raw value.
 * @param int   $min      Minimum.
 * @param int   $max      Maximum.
 * @param int   $fallback Fallback for non-numeric input.
 * @return int
 */
function dw_clamp( $value, $min, $max, $fallback ) {
	if ( ! is_numeric( $value ) ) {
		return (int) $fallback;
	}

	return min( $max, max( $min, (int) $value ) );
}

new DW_Admin();
