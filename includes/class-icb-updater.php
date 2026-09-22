<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Self-hosted updates from GitHub Releases.
 *
 * - Repo:  ICB_GITHUB_REPO  ("usuario/repo"), defined in the main plugin file.
 * - Token: ICB_GITHUB_TOKEN, defined in each site's wp-config.php (private repos only).
 *          Use a fine-grained personal access token scoped to this single repo with
 *          "Contents: Read-only". It is only ever sent to api.github.com.
 *
 * Flow: WordPress' normal update check → `update_plugins_github.com` (Update URI header)
 * → we read /releases/latest → if the tag is newer than ICB_VERSION, WordPress shows
 * the usual "Hay una nueva versión disponible" row and updates with one click (or via
 * auto-updates). The GitHub zipball extracts to "usuario-repo-sha/", so we rename it to
 * the plugin slug before WordPress installs it.
 */
class ICB_Updater {

	const SLUG      = 'inpulsia-consent-bridge';
	const CACHE_KEY = 'icb_gh_release';
	const ERROR_KEY = 'icb_gh_last_error';

	private $basename;

	public function __construct() {
		$this->basename = plugin_basename( ICB_PATH . 'inpulsia-consent-bridge.php' );
		if ( ! self::repo() ) {
			return;
		}
		add_filter( 'update_plugins_github.com', [ $this, 'check_update' ], 10, 4 );
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 20, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'fix_folder_name' ], 10, 4 );
		add_filter( 'http_request_args', [ $this, 'auth_headers' ], 10, 2 );
		add_filter( 'plugin_row_meta', [ $this, 'row_meta' ], 10, 2 );
		add_action( 'admin_init', [ $this, 'handle_manual_check' ] );
		add_action( 'admin_init', [ $this, 'maybe_force_check' ] );
		add_action( 'admin_notices', [ $this, 'manual_check_notice' ] );
		add_action( 'upgrader_process_complete', [ __CLASS__, 'flush_cache' ], 5 );
	}

	/* ---------------------------------------------------------------- config */

	public static function repo() {
		$repo = defined( 'ICB_GITHUB_REPO' ) ? trim( (string) ICB_GITHUB_REPO, "/ \t" ) : '';
		if ( false !== stripos( $repo, 'TU-USUARIO' ) ) {
			return ''; // placeholder not filled in yet
		}
		return preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ? $repo : '';
	}

	public static function has_token() {
		return defined( 'ICB_GITHUB_TOKEN' ) && '' !== trim( (string) ICB_GITHUB_TOKEN );
	}

	private static function api_headers() {
		$headers = [
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'Inpulsia-Consent/' . ICB_VERSION . '; ' . home_url( '/' ),
		];
		if ( self::has_token() ) {
			$headers['Authorization'] = 'Bearer ' . trim( (string) ICB_GITHUB_TOKEN );
		}
		return $headers;
	}

	/* --------------------------------------------------------------- release */

	/**
	 * Latest published (non-draft, non-prerelease) GitHub release, cached 6h.
	 * Failures are cached 30 min so a broken token doesn't hammer the API.
	 *
	 * @return array|null
	 */
	public static function get_release( $force = false ) {
		if ( ! self::repo() ) {
			return null;
		}
		if ( ! $force ) {
			$cached = get_site_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return empty( $cached['version'] ) ? null : $cached;
			}
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::repo() . '/releases/latest',
			[ 'timeout' => 10, 'headers' => self::api_headers() ]
		);

		if ( is_wp_error( $response ) ) {
			return self::fail( $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$hint = '';
			if ( 404 === $code ) {
				$hint = self::has_token()
					? ' — el repo no tiene releases publicadas o el token no tiene acceso a él.'
					: ' — repo privado sin ICB_GITHUB_TOKEN en wp-config.php, o sin releases publicadas.';
			} elseif ( 401 === $code ) {
				$hint = ' — token inválido o caducado.';
			} elseif ( 403 === $code ) {
				$hint = ' — límite de la API de GitHub alcanzado o token sin permisos.';
			}
			return self::fail( 'HTTP ' . $code . $hint );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			return self::fail( 'Respuesta de GitHub sin tag_name.' );
		}

		$release = [
			'version'   => ltrim( (string) $body['tag_name'], 'vV' ),
			'tag'       => (string) $body['tag_name'],
			'package'   => 'https://api.github.com/repos/' . self::repo() . '/zipball/' . rawurlencode( (string) $body['tag_name'] ),
			'url'       => (string) ( $body['html_url'] ?? '' ),
			'changelog' => (string) ( $body['body'] ?? '' ),
			'published' => (string) ( $body['published_at'] ?? '' ),
			'checked'   => time(),
		];
		set_site_transient( self::CACHE_KEY, $release, 6 * HOUR_IN_SECONDS );
		delete_site_transient( self::ERROR_KEY );
		return $release;
	}

	private static function fail( $message ) {
		set_site_transient( self::ERROR_KEY, [ 'message' => $message, 'time' => time() ], DAY_IN_SECONDS );
		set_site_transient( self::CACHE_KEY, [ 'version' => '' ], 30 * MINUTE_IN_SECONDS );
		return null;
	}

	public static function last_error() {
		$err = get_site_transient( self::ERROR_KEY );
		return is_array( $err ) ? $err : null;
	}

	public static function flush_cache() {
		delete_site_transient( self::CACHE_KEY );
	}

	/* ----------------------------------------------------------- WP filters */

	/** `update_plugins_github.com` — WordPress 5.8+ Update URI mechanism. */
	public function check_update( $update, $plugin_data, $plugin_file, $locales ) {
		if ( $plugin_file !== $this->basename ) {
			return $update;
		}
		$release = self::get_release();
		if ( ! $release ) {
			return $update;
		}
		return [
			'slug'         => self::SLUG,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => '5.8',
			'requires_php' => '7.4',
		];
	}

	/** "Ver detalles de la versión X" modal. */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = self::get_release();
		if ( ! $release ) {
			return $result;
		}
		return (object) [
			'name'          => 'Inpulsia Consent v2',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://inpulsia.es">Inpulsia</a>',
			'homepage'      => 'https://inpulsia.es',
			'requires'      => '5.8',
			'requires_php'  => '7.4',
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => [
				'description' => '<p>Puente de Google Consent Mode v2 entre Complianz y Google Site Kit / GTM.</p>',
				'changelog'   => self::markdown_to_html( $release['changelog'] ) ?: '<p>Sin notas para esta versión.</p>',
			],
		];
	}

	/** GitHub zipballs extract to "usuario-repo-sha/"; WordPress needs "inpulsia-consent-bridge/". */
	public function fix_folder_name( $source, $remote_source, $upgrader, $hook_extra = [] ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $source;
		}
		$desired = trailingslashit( $remote_source ) . self::SLUG . '/';
		if ( trailingslashit( $source ) === $desired ) {
			return $source;
		}
		if ( ! $wp_filesystem || ! $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $desired ), true ) ) {
			return new WP_Error( 'icb_rename_failed', __( 'No se pudo preparar la carpeta del plugin descargado de GitHub.', 'inpulsia-consent-bridge' ) );
		}
		return $desired;
	}

	/** Adds the token to the zipball download (download_url() doesn't let us pass headers). */
	public function auth_headers( $args, $url ) {
		if ( ! self::has_token() || 0 !== strpos( $url, 'https://api.github.com/repos/' . self::repo() . '/' ) ) {
			return $args;
		}
		$args['headers'] = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : [];
		$args['headers'] = array_merge( self::api_headers(), $args['headers'], [ 'Authorization' => 'Bearer ' . trim( (string) ICB_GITHUB_TOKEN ) ] );
		$args['headers']['Accept'] = 'application/vnd.github+json';
		return $args;
	}

	/* ------------------------------------------------------ manual check UX */

	public function row_meta( $links, $file ) {
		if ( $file !== $this->basename || ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}
		$url     = wp_nonce_url( admin_url( 'plugins.php?icb_check_update=1' ), 'icb_check_update' );
		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Buscar actualización', 'inpulsia-consent-bridge' ) . '</a>';
		return $links;
	}

	public function handle_manual_check() {
		if ( ! isset( $_GET['icb_check_update'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		check_admin_referer( 'icb_check_update' );
		self::flush_cache();
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		wp_safe_redirect( admin_url( 'plugins.php?icb_update_checked=1' ) );
		exit;
	}

	/** Dashboard → Actualizaciones → "Comprobar de nuevo" also bypasses our cache. */
	public function maybe_force_check() {
		global $pagenow;
		if ( 'update-core.php' === $pagenow && isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) {
			self::flush_cache();
		}
	}

	public function manual_check_notice() {
		if ( ! isset( $_GET['icb_update_checked'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$release = self::get_release();
		if ( ! $release ) {
			$err = self::last_error();
			printf(
				'<div class="notice notice-error is-dismissible"><p><strong>Inpulsia Consent v2:</strong> %s %s</p></div>',
				esc_html__( 'No se pudo consultar GitHub.', 'inpulsia-consent-bridge' ),
				esc_html( $err['message'] ?? '' )
			);
			return;
		}
		if ( version_compare( $release['version'], ICB_VERSION, '>' ) ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p><strong>Inpulsia Consent v2:</strong> %s</p></div>',
				esc_html( sprintf( __( 'Hay una nueva versión (%1$s). Pulsa "actualizar ahora" en la fila del plugin. Versión instalada: %2$s.', 'inpulsia-consent-bridge' ), $release['version'], ICB_VERSION ) )
			);
		} else {
			printf(
				'<div class="notice notice-success is-dismissible"><p><strong>Inpulsia Consent v2:</strong> %s</p></div>',
				esc_html( sprintf( __( 'Ya tienes la última versión (%s).', 'inpulsia-consent-bridge' ), ICB_VERSION ) )
			);
		}
	}

	/* --------------------------------------------------------------- helpers */

	/** Minimal Markdown → HTML for GitHub release notes (headings, bullets, paragraphs). */
	private static function markdown_to_html( $md ) {
		$md = trim( str_replace( "\r\n", "\n", (string) $md ) );
		if ( '' === $md ) {
			return '';
		}
		$html = '';
		$in_list = false;
		foreach ( explode( "\n", $md ) as $line ) {
			$line = rtrim( $line );
			if ( preg_match( '/^\s*[-*·]\s+(.*)$/u', $line, $m ) ) {
				if ( ! $in_list ) { $html .= '<ul>'; $in_list = true; }
				$html .= '<li>' . esc_html( $m[1] ) . '</li>';
				continue;
			}
			if ( $in_list ) { $html .= '</ul>'; $in_list = false; }
			if ( '' === trim( $line ) ) {
				continue;
			}
			if ( preg_match( '/^#{1,6}\s+(.*)$/', $line, $m ) ) {
				$html .= '<h4>' . esc_html( $m[1] ) . '</h4>';
			} else {
				$html .= '<p>' . esc_html( trim( $line ) ) . '</p>';
			}
		}
		if ( $in_list ) {
			$html .= '</ul>';
		}
		return $html;
	}
}
