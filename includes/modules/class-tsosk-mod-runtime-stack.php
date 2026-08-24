<?php
/**
 * TSO Swiss Knife – Module: Server and runtime stack (read-only).
 *
 * Drop-ins, must-use plugins, object cache, and PHP limits. No file writes.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Runtime_Stack
 */
class TSOSK_Mod_Runtime_Stack {

	/** @var TSOSK_Mod_Runtime_Stack|null */
	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Convert a PHP ini size string to bytes.
	 *
	 * @param string $value e.g. 128M, 1G, 512.
	 * @return int
	 */
	public static function parse_ini_bytes( string $value ): int {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}
		if ( ctype_digit( $value ) ) {
			return (int) $value;
		}
		$len    = strlen( $value );
		$number = (float) substr( $value, 0, $len - 1 );
		$unit   = strtolower( substr( $value, -1 ) );
		$mult   = 1;
		if ( 'k' === $unit ) {
			$mult = 1024;
		} elseif ( 'm' === $unit ) {
			$mult = 1024 * 1024;
		} elseif ( 'g' === $unit ) {
			$mult = 1024 * 1024 * 1024;
		} else {
			return (int) $value;
		}
		return (int) round( $number * $mult );
	}

	/**
	 * Whether PHP and WordPress timezones disagree in a meaningful way.
	 *
	 * @param string $php_tz PHP default timezone.
	 * @param string $wp_tz  WordPress timezone string (may be empty or UTC offset).
	 * @return bool
	 */
	public static function timezone_mismatch( string $php_tz, string $wp_tz ): bool {
		$php_tz = trim( $php_tz );
		$wp_tz  = trim( $wp_tz );
		if ( '' === $php_tz || '' === $wp_tz ) {
			return false;
		}
		if ( 0 === strpos( $wp_tz, '+' ) || 0 === strpos( $wp_tz, '-' ) || is_numeric( $wp_tz ) ) {
			return false;
		}
		return strtolower( $php_tz ) !== strtolower( $wp_tz ) && 'UTC' !== strtoupper( $php_tz );
	}

	/**
	 * @return array<int, array{file:string,label:string,exists:bool,size:int,modified:int}>
	 */
	private function get_dropins(): array {
		tsosk_require_wp_admin( 'includes/plugin.php' );
		$list = function_exists( '_get_dropins' ) ? _get_dropins() : array();
		if ( ! is_array( $list ) ) {
			$list = array();
		}

		$content = trailingslashit( wp_normalize_path( (string) WP_CONTENT_DIR ) );
		$rows    = array();
		foreach ( $list as $file => $meta ) {
			$file = sanitize_file_name( (string) $file );
			if ( '' === $file ) {
				continue;
			}
			$path   = $content . $file;
			$exists = file_exists( $path );
			$label  = '';
			if ( is_array( $meta ) && isset( $meta[0] ) ) {
				$label = (string) $meta[0];
			}
			$rows[] = array(
				'file'     => $file,
				'label'    => $label,
				'exists'   => $exists,
				'size'     => $exists ? (int) filesize( $path ) : 0,
				'modified' => $exists ? (int) filemtime( $path ) : 0,
			);
		}
		return $rows;
	}

	/**
	 * @return array<int, array{file:string,name:string,size:int,modified:int}>
	 */
	private function get_mu_plugins(): array {
		tsosk_require_wp_admin( 'includes/plugin.php' );
		$list = function_exists( 'get_mu_plugins' ) ? get_mu_plugins() : array();
		if ( ! is_array( $list ) ) {
			$list = array();
		}

		$base = defined( 'WPMU_PLUGIN_DIR' ) ? trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) ) : '';
		$rows = array();
		foreach ( $list as $file => $data ) {
			$file = ltrim( str_replace( '\\', '/', (string) $file ), '/' );
			if ( '' === $file || false !== strpos( $file, '..' ) ) {
				continue;
			}
			$path = '' !== $base ? $base . $file : '';
			$name = is_array( $data ) && ! empty( $data['Name'] ) ? (string) $data['Name'] : $file;
			$rows[] = array(
				'file'     => $file,
				'name'     => $name,
				'size'     => ( '' !== $path && file_exists( $path ) ) ? (int) filesize( $path ) : 0,
				'modified' => ( '' !== $path && file_exists( $path ) ) ? (int) filemtime( $path ) : 0,
			);
		}
		return $rows;
	}

	/**
	 * @return array<string, string>
	 */
	private function get_php_limits(): array {
		$memory      = (string) ini_get( 'memory_limit' );
		$upload      = (string) ini_get( 'upload_max_filesize' );
		$post        = (string) ini_get( 'post_max_size' );
		$max_time    = (string) ini_get( 'max_execution_time' );
		$max_input   = (string) ini_get( 'max_input_vars' );
		$disabled    = (string) ini_get( 'disable_functions' );
		$temp        = function_exists( 'sys_get_temp_dir' ) ? sys_get_temp_dir() : '';
		$temp_ok     = ( '' !== $temp && wp_is_writable( $temp ) );
		$opcache     = function_exists( 'opcache_get_status' );
		$opcache_on  = false;
		if ( $opcache ) {
			$status = opcache_get_status( false );
			$opcache_on = is_array( $status ) && ! empty( $status['opcache_enabled'] );
		}

		$wp_tz  = function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : '';
		$php_tz = (string) date_default_timezone_get();

		$disabled_short = $disabled;
		if ( strlen( $disabled_short ) > 180 ) {
			$disabled_short = substr( $disabled_short, 0, 180 ) . '…';
		}

		return array(
			'php'          => PHP_VERSION,
			'memory'       => $memory,
			'upload'       => $upload,
			'post'         => $post,
			'max_time'     => $max_time,
			'max_input'    => $max_input,
			'opcache'      => $opcache_on ? '1' : '0',
			'temp'         => $temp,
			'temp_ok'      => $temp_ok ? '1' : '0',
			'php_tz'       => $php_tz,
			'wp_tz'        => $wp_tz,
			'tz_mismatch'  => self::timezone_mismatch( $php_tz, $wp_tz ) ? '1' : '0',
			'disabled'     => $disabled_short,
			'wp_memory'    => defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : '',
			'wp_max_mem'   => defined( 'WP_MAX_MEMORY_LIMIT' ) ? (string) WP_MAX_MEMORY_LIMIT : '',
		);
	}

	public function render(): void {
		$dropins  = $this->get_dropins();
		$mu       = $this->get_mu_plugins();
		$limits   = $this->get_php_limits();
		$external = wp_using_ext_object_cache();
		$oc_file  = trailingslashit( wp_normalize_path( (string) WP_CONTENT_DIR ) ) . 'object-cache.php';
		$oc_exists = file_exists( $oc_file );
		global $wp_object_cache;
		$driver = is_object( $wp_object_cache ) ? get_class( $wp_object_cache ) : '';
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'A snapshot of what sits under WordPress on this server: extra cache files, must-use plugins that always load, and PHP limits that explain failed uploads or timeouts. Nothing here is changed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'PHP and hosting limits', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<table class="tsosk-kv-table">
				<tr>
					<th><?php esc_html_e( 'PHP version', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td><code><?php echo esc_html( $limits['php'] ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Memory PHP may use', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<code><?php echo esc_html( $limits['memory'] ); ?></code>
						<?php if ( '' !== $limits['wp_memory'] ) : ?>
							<span class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: WP_MEMORY_LIMIT value */
										__( 'WordPress asks for %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
										$limits['wp_memory']
									)
								);
								?>
							</span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Largest file you can upload', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td><code><?php echo esc_html( $limits['upload'] ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Largest form WordPress can receive', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td><code><?php echo esc_html( $limits['post'] ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Seconds a request may run', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td><code><?php echo esc_html( $limits['max_time'] ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Form fields allowed in one request', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td><code><?php echo esc_html( $limits['max_input'] ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'PHP accelerator (OPcache)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<?php if ( '1' === $limits['opcache'] ) : ?>
							<span class="tsosk-badge tsosk-badge-ok"><?php esc_html_e( 'On', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
						<?php else : ?>
							<span class="tsosk-badge tsosk-badge-info"><?php esc_html_e( 'Off or unknown', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Temporary folder', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<code><?php echo esc_html( $limits['temp'] ); ?></code>
						<?php if ( '1' === $limits['temp_ok'] ) : ?>
							<span class="tsosk-badge tsosk-badge-ok"><?php esc_html_e( 'Writable', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
						<?php else : ?>
							<span class="tsosk-badge tsosk-badge-warn"><?php esc_html_e( 'Not writable', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Clock (PHP vs WordPress)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: PHP timezone, 2: WordPress timezone */
								__( 'PHP: %1$s. WordPress: %2$s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
								$limits['php_tz'],
								'' !== $limits['wp_tz'] ? $limits['wp_tz'] : '—'
							)
						);
						?>
						<?php if ( '1' === $limits['tz_mismatch'] ) : ?>
							<br><span class="tsosk-badge tsosk-badge-warn"><?php esc_html_e( 'They differ. Scheduled posts and cron times can look wrong.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( '' !== $limits['disabled'] ) : ?>
				<tr>
					<th><?php esc_html_e( 'PHP functions turned off by hosting', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td><code><?php echo esc_html( $limits['disabled'] ); ?></code></td>
				</tr>
				<?php endif; ?>
			</table>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Object cache (fast database memory)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<table class="tsosk-kv-table">
				<tr>
					<th><?php esc_html_e( 'Persistent cache (Redis / Memcached / similar)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<?php if ( $external ) : ?>
							<span class="tsosk-badge tsosk-badge-ok"><?php esc_html_e( 'Yes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
						<?php else : ?>
							<span class="tsosk-badge tsosk-badge-info"><?php esc_html_e( 'No — WordPress is using its default in-memory cache for this request.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Driver class', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td><code><?php echo esc_html( $driver ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'object-cache.php drop-in', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<?php if ( $oc_exists ) : ?>
							<span class="tsosk-badge tsosk-badge-ok"><?php esc_html_e( 'Present', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
							<code><?php echo esc_html( $oc_file ); ?></code>
						<?php else : ?>
							<span class="tsosk-badge tsosk-badge-info"><?php esc_html_e( 'Not present', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Drop-in files in wp-content', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'These special files replace or extend core behaviour (cache, database, error pages). Hosting or a cache plugin often places them. They do not appear in the normal Plugins list.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<table class="widefat tsosk-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'File', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Purpose', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'On disk?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Last changed', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $dropins as $row ) : ?>
						<tr>
							<td><code><?php echo esc_html( $row['file'] ); ?></code></td>
							<td><?php echo esc_html( $row['label'] ); ?></td>
							<td>
								<?php if ( $row['exists'] ) : ?>
									<span class="tsosk-badge tsosk-badge-ok"><?php esc_html_e( 'Yes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
									<?php echo esc_html( size_format( $row['size'] ) ); ?>
								<?php else : ?>
									<span class="tsosk-badge"><?php esc_html_e( 'No', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo $row['modified'] ? esc_html( wp_date( 'Y-m-d H:i', $row['modified'] ) ) : '—'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Must-use plugins (always on)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Files in mu-plugins load for everyone and cannot be deactivated from the Plugins screen. Hosting panels and this plugin’s Sandbox loader may appear here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<?php if ( empty( $mu ) ) : ?>
				<p><?php esc_html_e( 'No must-use plugins found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
				<table class="widefat tsosk-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'File', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Size', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Last changed', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $mu as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['name'] ); ?></td>
								<td><code><?php echo esc_html( $row['file'] ); ?></code></td>
								<td><?php echo esc_html( size_format( $row['size'] ) ); ?></td>
								<td><?php echo $row['modified'] ? esc_html( wp_date( 'Y-m-d H:i', $row['modified'] ) ) : '—'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
