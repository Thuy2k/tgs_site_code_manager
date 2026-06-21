<?php
/**
 * Plugin Name: TGS Site Code Manager
 * Description: Adds a required unique website code to multisite site creation and supports checked Excel imports for bulk site creation.
 * Version: 1.0.0
 * Author: TGS
 * Network: true
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TGS_SCM_VERSION', '1.0.0' );
define( 'TGS_SCM_PLUGIN_FILE', __FILE__ );
define( 'TGS_SCM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TGS_SCM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

final class TGS_Site_Code_Manager {
	const COLUMN_SITE_CODE = 'tgs_site_code';
	const OPTION_SITE_CODE = 'tgs_website_code';
	const NETWORK_TIME_SETTINGS_OPTION = 'tgs_scm_network_time_settings';
	const TRANSIENT_PREFIX = 'tgs_scm_import_';
	const IMPORT_TTL       = 3600;

	private static $pending_site_code = '';

	public static function init() {
		add_action( 'network_admin_menu', array( __CLASS__, 'register_network_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_ensure_schema' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'network_site_new_form', array( __CLASS__, 'render_site_code_field' ) );
		add_action( 'admin_init', array( __CLASS__, 'validate_site_new_submission' ), 1 );
		add_filter( 'wp_initialize_site_args', array( __CLASS__, 'inject_pending_site_options' ), 10, 3 );
		add_action( 'wp_initialize_site', array( __CLASS__, 'store_pending_code_for_new_site' ), 20, 2 );
		add_filter( 'manage_sites-network_columns', array( __CLASS__, 'add_sites_column' ) );
		add_action( 'manage_sites_custom_column', array( __CLASS__, 'render_sites_column' ), 10, 2 );

		add_action( 'wp_ajax_tgs_scm_check_code', array( __CLASS__, 'ajax_check_code' ) );
		add_action( 'wp_ajax_tgs_scm_upload_excel', array( __CLASS__, 'ajax_upload_excel' ) );
		add_action( 'wp_ajax_tgs_scm_preview_import', array( __CLASS__, 'ajax_preview_import' ) );
		add_action( 'wp_ajax_tgs_scm_import_sites', array( __CLASS__, 'ajax_import_sites' ) );
		add_action( 'wp_ajax_tgs_scm_preview_user_import', array( __CLASS__, 'ajax_preview_user_import' ) );
		add_action( 'wp_ajax_tgs_scm_import_users', array( __CLASS__, 'ajax_import_users' ) );
		add_action( 'wp_ajax_tgs_scm_apply_time_settings', array( __CLASS__, 'ajax_apply_time_settings' ) );
	}

	public static function activate( $network_wide ) {
		if ( is_multisite() && ! $network_wide ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( esc_html__( 'TGS Site Code Manager must be network activated.', 'tgs-site-code-manager' ) );
		}

		self::ensure_schema();
	}

	public static function maybe_ensure_schema() {
		if ( is_multisite() && is_network_admin() && current_user_can( 'manage_network_options' ) ) {
			self::ensure_schema();
		}
	}

	private static function ensure_schema() {
		global $wpdb;

		if ( ! is_multisite() ) {
			return;
		}

		$column_exists = $wpdb->get_var(
			$wpdb->prepare( "SHOW COLUMNS FROM `{$wpdb->blogs}` LIKE %s", self::COLUMN_SITE_CODE )
		);

		if ( ! $column_exists ) {
			$wpdb->query( "ALTER TABLE `{$wpdb->blogs}` ADD `" . self::COLUMN_SITE_CODE . "` varchar(32) NULL DEFAULT NULL AFTER `lang_id`" );
		} else {
			$wpdb->query( "UPDATE `{$wpdb->blogs}` SET `" . self::COLUMN_SITE_CODE . "` = NULL WHERE `" . self::COLUMN_SITE_CODE . "` = ''" );
			$wpdb->query( "ALTER TABLE `{$wpdb->blogs}` MODIFY `" . self::COLUMN_SITE_CODE . "` varchar(32) NULL DEFAULT NULL" );
		}

		$index_name = self::COLUMN_SITE_CODE;
		$index_row  = $wpdb->get_row(
			$wpdb->prepare( "SHOW INDEX FROM `{$wpdb->blogs}` WHERE Key_name = %s", $index_name )
		);

		if ( $index_row && ! empty( $index_row->Non_unique ) ) {
			$duplicate_code = $wpdb->get_var(
				"SELECT `" . self::COLUMN_SITE_CODE . "` FROM `{$wpdb->blogs}` WHERE `" . self::COLUMN_SITE_CODE . "` IS NOT NULL AND `" . self::COLUMN_SITE_CODE . "` <> '' GROUP BY `" . self::COLUMN_SITE_CODE . "` HAVING COUNT(*) > 1 LIMIT 1"
			);
			if ( ! $duplicate_code ) {
				$wpdb->query( "ALTER TABLE `{$wpdb->blogs}` DROP INDEX `" . self::COLUMN_SITE_CODE . "`" );
				$index_row = null;
			}
		}

		if ( ! $index_row ) {
			$wpdb->query( "ALTER TABLE `{$wpdb->blogs}` ADD UNIQUE KEY `" . self::COLUMN_SITE_CODE . "` (`" . self::COLUMN_SITE_CODE . "`)" );
		}
	}

	public static function register_network_page() {
		add_submenu_page(
			'sites.php',
			'Import website TGS',
			'Import website TGS',
			'create_sites',
			'tgs-site-code-import',
			array( __CLASS__, 'render_import_page' )
		);

		add_submenu_page(
			'sites.php',
			'Import user website TGS',
			'Import user website TGS',
			'manage_network_users',
			'tgs-site-user-import',
			array( __CLASS__, 'render_user_import_page' )
		);

		add_submenu_page(
			'settings.php',
			'Gio Viet Nam TGS',
			'Gio Viet Nam TGS',
			'manage_network_options',
			'tgs-network-time-settings',
			array( __CLASS__, 'render_time_settings_page' )
		);
	}

	public static function enqueue_assets( $hook_suffix ) {
		if ( ! is_network_admin() ) {
			return;
		}

		$pagenow = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '';
		$is_site_new = 'site-new.php' === $pagenow;
		$is_import   = false !== strpos( (string) $hook_suffix, 'tgs-site-code-import' );
		$is_user_import = false !== strpos( (string) $hook_suffix, 'tgs-site-user-import' );
		$is_time_settings = false !== strpos( (string) $hook_suffix, 'tgs-network-time-settings' );

		if ( ! $is_site_new && ! $is_import && ! $is_user_import && ! $is_time_settings ) {
			return;
		}

		wp_enqueue_style(
			'tgs-scm-admin',
			TGS_SCM_PLUGIN_URL . 'assets/tgs-scm-admin.css',
			array(),
			self::asset_version( 'assets/tgs-scm-admin.css' )
		);

		wp_enqueue_script(
			'tgs-scm-admin',
			TGS_SCM_PLUGIN_URL . 'assets/tgs-scm-admin.js',
			array( 'jquery' ),
			self::asset_version( 'assets/tgs-scm-admin.js' ),
			true
		);

		wp_localize_script(
			'tgs-scm-admin',
			'TgsScmAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'tgs_scm_admin' ),
				'isSiteNew'   => $is_site_new,
				'isImport'    => $is_import,
				'isUserImport' => $is_user_import,
				'isTimeSettings' => $is_time_settings,
				'importKind'   => $is_user_import ? 'users' : 'sites',
				'networkHome' => network_home_url(),
				'i18n'        => array(
					'checking'       => 'Dang kiem tra...',
					'codeRequired'   => 'Ma website la bat buoc.',
					'codeInvalid'    => 'Ma chi duoc gom chu, so, dau gach ngang hoac gach duoi.',
					'codeAvailable'  => 'Ma website co the su dung.',
					'codeTaken'      => 'Ma website da ton tai.',
					'uploading'      => 'Dang doc file Excel...',
					'previewing'     => 'Dang kiem tra du lieu...',
					'importing'      => 'Dang import site...',
					'chooseFile'     => 'Vui long chon file Excel .xlsx.',
					'chooseSheet'    => 'Vui long chon sheet Excel.',
					'previewFirst'   => 'Can kiem tra truoc du lieu truoc khi import.',
					'importBlocked'  => 'Con loi trong du lieu, chua the import.',
				),
			)
		);
	}

	private static function asset_version( $relative_path ) {
		$path = TGS_SCM_PLUGIN_DIR . $relative_path;
		return file_exists( $path ) ? TGS_SCM_VERSION . '.' . filemtime( $path ) : TGS_SCM_VERSION;
	}

	public static function render_site_code_field() {
		$value = isset( $_POST['blog']['tgs_site_code'] ) ? self::normalize_code( wp_unslash( $_POST['blog']['tgs_site_code'] ) ) : '';
		?>
		<table class="form-table tgs-scm-site-code-table" role="presentation">
			<tr class="form-field form-required">
				<th scope="row">
					<label for="tgs-site-code">
						<?php echo esc_html( 'Ma website' ); ?>
						<span class="required" aria-hidden="true">*</span>
					</label>
				</th>
				<td>
					<input
						name="blog[tgs_site_code]"
						type="text"
						class="regular-text"
						id="tgs-site-code"
						value="<?php echo esc_attr( $value ); ?>"
						required
						autocomplete="off"
						inputmode="latin"
						aria-describedby="tgs-site-code-desc tgs-site-code-status"
					/>
					<p class="description" id="tgs-site-code-desc">Nhap ma duy nhat cua website, vi du 2001.</p>
					<p class="tgs-scm-field-status" id="tgs-site-code-status" role="status"></p>
				</td>
			</tr>
		</table>
		<div class="tgs-scm-site-new-import">
			<button type="button" class="button" id="tgs-scm-toggle-import">Nhap Excel</button>
			<div id="tgs-scm-inline-import" class="tgs-scm-inline-import" hidden>
				<?php self::render_import_controls(); ?>
			</div>
		</div>
		<?php
	}

	public static function validate_site_new_submission() {
		if ( ! is_network_admin() || ! current_user_can( 'create_sites' ) ) {
			return;
		}

		$pagenow = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '';
		if ( 'site-new.php' !== $pagenow ) {
			return;
		}

		if ( ! isset( $_REQUEST['action'] ) || 'add-site' !== $_REQUEST['action'] ) {
			return;
		}

		if ( ! isset( $_POST['blog'] ) || ! is_array( $_POST['blog'] ) ) {
			return;
		}

		$code = isset( $_POST['blog']['tgs_site_code'] ) ? self::normalize_code( wp_unslash( $_POST['blog']['tgs_site_code'] ) ) : '';
		$validation = self::validate_code( $code );
		if ( is_wp_error( $validation ) ) {
			wp_die( esc_html( $validation->get_error_message() ) );
		}

		self::$pending_site_code = $code;
	}

	public static function inject_pending_site_options( $args, $site, $network ) {
		$code = self::$pending_site_code;
		if ( '' === $code ) {
			$code = self::get_request_import_code_for_site( $site );
		}

		if ( empty( $args['options'] ) || ! is_array( $args['options'] ) ) {
			$args['options'] = array();
		}

		if ( '' !== $code ) {
			$args['options'][ self::OPTION_SITE_CODE ] = $code;
			$args['options']['tgs_site_code']          = $code;
		}

		$args['options'] = self::merge_time_settings_into_options( $args['options'] );

		return $args;
	}

	public static function store_pending_code_for_new_site( $new_site, $args ) {
		$code = self::$pending_site_code;
		if ( '' === $code && ! empty( $args['options'][ self::OPTION_SITE_CODE ] ) ) {
			$code = self::normalize_code( $args['options'][ self::OPTION_SITE_CODE ] );
		}

		if ( '' === $code ) {
			return;
		}

		self::$pending_site_code = '';
		$assigned = self::assign_code_to_site( (int) $new_site->id, $code );
		if ( is_wp_error( $assigned ) ) {
			wp_die( esc_html( $assigned->get_error_message() ) );
		}
	}

	public static function add_sites_column( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( 'blogname' === $key ) {
				$new_columns['tgs_site_code'] = 'Ma website';
			}
		}

		if ( ! isset( $new_columns['tgs_site_code'] ) ) {
			$new_columns['tgs_site_code'] = 'Ma website';
		}

		return $new_columns;
	}

	public static function render_sites_column( $column_name, $blog_id ) {
		if ( 'tgs_site_code' !== $column_name ) {
			return;
		}

		$code = self::get_site_code_by_blog_id( (int) $blog_id );
		echo '' !== $code ? esc_html( $code ) : '<span aria-hidden="true">-</span>';
	}

	public static function ajax_check_code() {
		self::check_ajax_permission();

		$code = isset( $_POST['code'] ) ? self::normalize_code( wp_unslash( $_POST['code'] ) ) : '';
		$validation = self::validate_code( $code );
		if ( is_wp_error( $validation ) ) {
			wp_send_json_success(
				array(
					'ok'      => false,
					'message' => $validation->get_error_message(),
				)
			);
		}

		wp_send_json_success(
			array(
				'ok'      => true,
				'code'    => $code,
				'message' => 'Ma website co the su dung.',
			)
		);
	}

	public static function ajax_upload_excel() {
		self::check_ajax_permission( array( 'create_sites', 'manage_network_users' ) );

		if ( empty( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) {
			wp_send_json_error( array( 'message' => 'Chua chon file Excel.' ) );
		}

		$file = $_FILES['file'];
		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : 'sites';
		if ( ! in_array( $kind, array( 'sites', 'users' ), true ) ) {
			$kind = 'sites';
		}

		if ( ! empty( $file['error'] ) ) {
			wp_send_json_error( array( 'message' => self::upload_error_message( (int) $file['error'] ) ) );
		}

		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( 'xlsx' !== $ext ) {
			wp_send_json_error( array( 'message' => 'Hien tai chi ho tro file .xlsx.' ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_send_json_error( array( 'message' => 'May chu chua bat ZipArchive nen chua doc duoc file .xlsx.' ) );
		}

		$upload = self::store_uploaded_xlsx( $file );
		if ( is_wp_error( $upload ) ) {
			wp_send_json_error( array( 'message' => $upload->get_error_message() ) );
		}

		$reader = new TGS_SCM_Xlsx_Reader( $upload['file'] );
		$sheets = $reader->get_sheets();
		if ( is_wp_error( $sheets ) ) {
			@unlink( $upload['file'] );
			wp_send_json_error( array( 'message' => $sheets->get_error_message() ) );
		}

		$token = wp_generate_uuid4();
		set_transient(
			self::TRANSIENT_PREFIX . $token,
			array(
				'file'      => $upload['file'],
				'name'      => sanitize_file_name( $file['name'] ),
				'kind'      => $kind,
				'created'   => time(),
				'previewed' => false,
			),
			self::IMPORT_TTL
		);

		wp_send_json_success(
			array(
				'token'  => $token,
				'name'   => sanitize_file_name( $file['name'] ),
				'sheets' => $sheets,
			)
		);
	}

	public static function ajax_preview_import() {
		self::check_ajax_permission( 'create_sites' );

		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$sheet = isset( $_POST['sheet'] ) ? sanitize_text_field( wp_unslash( $_POST['sheet'] ) ) : '';
		$state = self::get_import_state( $token );

		if ( is_wp_error( $state ) ) {
			wp_send_json_error( array( 'message' => $state->get_error_message() ) );
		}

		if ( ! empty( $state['kind'] ) && 'sites' !== $state['kind'] ) {
			wp_send_json_error( array( 'message' => 'Phien import khong dung loai du lieu.' ) );
		}

		if ( '' === $sheet ) {
			wp_send_json_error( array( 'message' => 'Chua chon sheet Excel.' ) );
		}

		$reader = new TGS_SCM_Xlsx_Reader( $state['file'] );
		$rows = $reader->get_rows( $sheet );
		if ( is_wp_error( $rows ) ) {
			wp_send_json_error( array( 'message' => $rows->get_error_message() ) );
		}

		$preview = self::build_import_preview( $rows );
		$state['sheet']      = $sheet;
		$state['previewed']  = true;
		$state['has_errors'] = $preview['has_errors'];
		$state['valid_rows'] = $preview['valid_rows'];
		set_transient( self::TRANSIENT_PREFIX . $token, $state, self::IMPORT_TTL );

		unset( $preview['valid_rows'] );
		wp_send_json_success( $preview );
	}

	public static function ajax_import_sites() {
		self::check_ajax_permission( 'create_sites' );

		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$state = self::get_import_state( $token );
		if ( is_wp_error( $state ) ) {
			wp_send_json_error( array( 'message' => $state->get_error_message() ) );
		}

		if ( ! empty( $state['kind'] ) && 'sites' !== $state['kind'] ) {
			wp_send_json_error( array( 'message' => 'Phien import khong dung loai du lieu.' ) );
		}

		if ( empty( $state['previewed'] ) ) {
			wp_send_json_error( array( 'message' => 'Can kiem tra truoc du lieu truoc khi import.' ) );
		}

		$valid_rows = ! empty( $state['valid_rows'] ) && is_array( $state['valid_rows'] ) ? $state['valid_rows'] : array();
		if ( empty( $valid_rows ) ) {
			wp_send_json_error( array( 'message' => 'Khong co dong hop le de import.' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/translation-install.php';

		$offset = isset( $_POST['offset'] ) ? max( 0, absint( $_POST['offset'] ) ) : 0;
		$limit  = isset( $_POST['limit'] ) ? max( 1, min( 10, absint( $_POST['limit'] ) ) ) : 5;
		$total  = count( $valid_rows );

		if ( $offset >= $total ) {
			self::finish_import_state( $token, $state );
			wp_send_json_success(
				array(
					'message'     => 'Import da hoan tat.',
					'created'     => array(),
					'skipped'     => array(),
					'offset'      => $offset,
					'next_offset' => $offset,
					'total'       => $total,
					'done'        => true,
				)
			);
		}

		$created    = array();
		$errors     = array();
		$batch_rows = array_slice( $valid_rows, $offset, $limit );

		foreach ( $batch_rows as $row ) {
			$result = self::create_site_from_import_row( $row );
			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'row'     => isset( $row['row_number'] ) ? (int) $row['row_number'] : 0,
					'website' => isset( $row['website'] ) ? $row['website'] : '',
					'code'    => isset( $row['code'] ) ? $row['code'] : '',
					'message' => $result->get_error_message(),
				);
				continue;
			}

			$created[] = $result;
		}

		$next_offset = $offset + count( $batch_rows );
		$done        = $next_offset >= $total;

		if ( $done ) {
			self::finish_import_state( $token, $state );
		}

		wp_send_json_success(
			array(
				'message'     => $done ? 'Import da xu ly xong ' . $total . ' dong hop le.' : 'Da xu ly ' . $next_offset . '/' . $total . ' dong hop le.',
				'created'     => $created,
				'skipped'     => array(),
				'errors'      => $errors,
				'offset'      => $offset,
				'next_offset' => $next_offset,
				'total'       => $total,
				'done'        => $done,
			)
		);
	}

	public static function ajax_preview_user_import() {
		self::check_ajax_permission( 'manage_network_users' );

		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$sheet = isset( $_POST['sheet'] ) ? sanitize_text_field( wp_unslash( $_POST['sheet'] ) ) : '';
		$state = self::get_import_state( $token );

		if ( is_wp_error( $state ) ) {
			wp_send_json_error( array( 'message' => $state->get_error_message() ) );
		}

		if ( ! empty( $state['kind'] ) && 'users' !== $state['kind'] ) {
			wp_send_json_error( array( 'message' => 'Phien import khong dung loai du lieu.' ) );
		}

		if ( '' === $sheet ) {
			wp_send_json_error( array( 'message' => 'Chua chon sheet Excel.' ) );
		}

		$reader = new TGS_SCM_Xlsx_Reader( $state['file'] );
		$rows = $reader->get_rows( $sheet );
		if ( is_wp_error( $rows ) ) {
			wp_send_json_error( array( 'message' => $rows->get_error_message() ) );
		}

		$preview = self::build_user_import_preview( $rows );
		$state['sheet']      = $sheet;
		$state['previewed']  = true;
		$state['has_errors'] = $preview['has_errors'];
		$state['valid_rows'] = $preview['valid_rows'];
		set_transient( self::TRANSIENT_PREFIX . $token, $state, self::IMPORT_TTL );

		unset( $preview['valid_rows'] );
		wp_send_json_success( $preview );
	}

	public static function ajax_import_users() {
		self::check_ajax_permission( 'manage_network_users' );

		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$state = self::get_import_state( $token );
		if ( is_wp_error( $state ) ) {
			wp_send_json_error( array( 'message' => $state->get_error_message() ) );
		}

		if ( ! empty( $state['kind'] ) && 'users' !== $state['kind'] ) {
			wp_send_json_error( array( 'message' => 'Phien import khong dung loai du lieu.' ) );
		}

		if ( empty( $state['previewed'] ) ) {
			wp_send_json_error( array( 'message' => 'Can kiem tra truoc du lieu truoc khi import.' ) );
		}

		$valid_rows = ! empty( $state['valid_rows'] ) && is_array( $state['valid_rows'] ) ? $state['valid_rows'] : array();
		if ( empty( $valid_rows ) ) {
			wp_send_json_error( array( 'message' => 'Khong co dong hop le de import.' ) );
		}

		$offset = isset( $_POST['offset'] ) ? max( 0, absint( $_POST['offset'] ) ) : 0;
		$limit  = isset( $_POST['limit'] ) ? max( 1, min( 20, absint( $_POST['limit'] ) ) ) : 10;
		$total  = count( $valid_rows );

		if ( $offset >= $total ) {
			self::finish_import_state( $token, $state );
			wp_send_json_success(
				array(
					'message'     => 'Import user da hoan tat.',
					'created'     => array(),
					'skipped'     => array(),
					'errors'      => array(),
					'offset'      => $offset,
					'next_offset' => $offset,
					'total'       => $total,
					'done'        => true,
				)
			);
		}

		$created    = array();
		$skipped    = array();
		$errors     = array();
		$batch_rows = array_slice( $valid_rows, $offset, $limit );

		foreach ( $batch_rows as $row ) {
			$result = self::create_user_from_import_row( $row );
			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'row'      => isset( $row['row_number'] ) ? (int) $row['row_number'] : 0,
					'website'  => isset( $row['website'] ) ? $row['website'] : '',
					'code'     => isset( $row['code'] ) ? $row['code'] : '',
					'username' => isset( $row['username'] ) ? $row['username'] : '',
					'message'  => $result->get_error_message(),
				);
				continue;
			}

			if ( ! empty( $result['skipped'] ) ) {
				$skipped[] = $result;
				continue;
			}

			$created[] = $result;
		}

		$next_offset = $offset + count( $batch_rows );
		$done        = $next_offset >= $total;

		if ( $done ) {
			self::finish_import_state( $token, $state );
		}

		wp_send_json_success(
			array(
				'message'     => $done ? 'Import da xu ly xong ' . $total . ' dong hop le.' : 'Da xu ly ' . $next_offset . '/' . $total . ' dong hop le.',
				'created'     => $created,
				'skipped'     => $skipped,
				'errors'      => $errors,
				'offset'      => $offset,
				'next_offset' => $next_offset,
				'total'       => $total,
				'done'        => $done,
			)
		);
	}

	public static function ajax_apply_time_settings() {
		self::check_ajax_permission( 'manage_network_options' );

		$settings = self::get_network_time_settings();
		if ( isset( $_POST['timezone_string'], $_POST['date_format'], $_POST['time_format'], $_POST['start_of_week'] ) ) {
			$posted = self::sanitize_time_settings(
				array(
					'timezone_string' => wp_unslash( $_POST['timezone_string'] ),
					'date_format'     => wp_unslash( $_POST['date_format'] ),
					'time_format'     => wp_unslash( $_POST['time_format'] ),
					'start_of_week'   => wp_unslash( $_POST['start_of_week'] ),
				)
			);

			if ( is_wp_error( $posted ) ) {
				wp_send_json_error( array( 'message' => $posted->get_error_message() ) );
			}

			update_site_option( self::NETWORK_TIME_SETTINGS_OPTION, $posted );
			$settings = $posted;
		}

		$offset   = isset( $_POST['offset'] ) ? max( 0, absint( $_POST['offset'] ) ) : 0;
		$limit    = isset( $_POST['limit'] ) ? max( 1, min( 100, absint( $_POST['limit'] ) ) ) : 50;
		$total    = (int) get_sites(
			array(
				'count'    => true,
				'number'   => 0,
				'archived' => 0,
				'spam'     => 0,
				'deleted'  => 0,
			)
		);

		if ( $offset >= $total ) {
			wp_send_json_success(
				array(
					'message'     => 'Da ap dung cau hinh gio cho toan bo website.',
					'updated'     => 0,
					'offset'      => $offset,
					'next_offset' => $offset,
					'total'       => $total,
					'done'        => true,
				)
			);
		}

		$blog_ids = get_sites(
			array(
				'fields'   => 'ids',
				'number'   => $limit,
				'offset'   => $offset,
				'orderby'  => 'id',
				'order'    => 'ASC',
				'archived' => 0,
				'spam'     => 0,
				'deleted'  => 0,
			)
		);

		$updated = 0;
		foreach ( $blog_ids as $blog_id ) {
			self::apply_time_settings_to_site( (int) $blog_id, $settings );
			$updated++;
		}

		$next_offset = $offset + count( $blog_ids );
		$done        = $next_offset >= $total;

		wp_send_json_success(
			array(
				'message'     => $done ? 'Da ap dung cau hinh gio cho toan bo website.' : 'Da ap dung ' . $next_offset . '/' . $total . ' website.',
				'updated'     => $updated,
				'offset'      => $offset,
				'next_offset' => $next_offset,
				'total'       => $total,
				'done'        => $done,
			)
		);
	}

	public static function render_import_page() {
		if ( ! current_user_can( 'create_sites' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to add sites to this network.', 'tgs-site-code-manager' ) );
		}
		?>
		<div class="wrap tgs-scm-import-page">
			<h1>Import website TGS</h1>
			<p class="description">File .xlsx can co cac cot: website, ma, ten/tieu de website, email website. Import se tao tung site lan luot theo luong tao site chuan cua WordPress.</p>

			<?php self::render_import_controls( 'sites' ); ?>
		</div>
		<?php
	}

	public static function render_time_settings_page() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage network options.', 'tgs-site-code-manager' ) );
		}

		$settings = self::get_network_time_settings();
		$message  = '';
		$error    = '';

		if ( isset( $_POST['tgs_scm_save_time_settings'] ) ) {
			check_admin_referer( 'tgs_scm_save_time_settings' );

			$posted = self::sanitize_time_settings(
				array(
					'timezone_string' => isset( $_POST['timezone_string'] ) ? wp_unslash( $_POST['timezone_string'] ) : '',
					'date_format'     => isset( $_POST['date_format'] ) ? wp_unslash( $_POST['date_format'] ) : '',
					'time_format'     => isset( $_POST['time_format'] ) ? wp_unslash( $_POST['time_format'] ) : '',
					'start_of_week'   => isset( $_POST['start_of_week'] ) ? wp_unslash( $_POST['start_of_week'] ) : 1,
				)
			);

			if ( is_wp_error( $posted ) ) {
				$error = $posted->get_error_message();
			} else {
				update_site_option( self::NETWORK_TIME_SETTINGS_OPTION, $posted );
				$settings = $posted;
				$message  = 'Da luu cau hinh. Bam nut ap dung de cap nhat toan bo website hien co.';
			}
		}

		$selected_timezone = self::timezone_choice_value( $settings );
		$date_preview      = self::format_preview_date( $settings['date_format'], $settings );
		$time_preview      = self::format_preview_date( $settings['time_format'], $settings );
		?>
		<div class="wrap tgs-scm-time-page">
			<h1>Gio Viet Nam TGS</h1>
			<p class="description">Cau hinh nay luu o Network Admin va ap dung xuong option cua tung website trong multisite.</p>

			<?php if ( '' !== $message ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>

			<?php if ( '' !== $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<form method="post" id="tgs-scm-time-settings-form">
				<?php wp_nonce_field( 'tgs_scm_save_time_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="timezone_string">Timezone</label></th>
						<td>
							<select id="timezone_string" name="timezone_string">
								<?php echo wp_timezone_choice( $selected_timezone, get_user_locale() ); ?>
							</select>
							<p class="description">Mac dinh he thong TGS dang dung UTC+7.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="date_format">Date Format</label></th>
						<td>
							<input type="text" class="regular-text" id="date_format" name="date_format" value="<?php echo esc_attr( $settings['date_format'] ); ?>" />
							<p class="description">Gia tri de xuat: <code>d/m/Y</code>. Preview: <strong><?php echo esc_html( $date_preview ); ?></strong></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="time_format">Time Format</label></th>
						<td>
							<input type="text" class="regular-text" id="time_format" name="time_format" value="<?php echo esc_attr( $settings['time_format'] ); ?>" />
							<p class="description">Gia tri de xuat: <code>g:i a</code>. Preview: <strong><?php echo esc_html( $time_preview ); ?></strong></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="start_of_week">Week Starts On</label></th>
						<td>
							<select id="start_of_week" name="start_of_week">
								<?php self::render_weekday_options( (int) $settings['start_of_week'] ); ?>
							</select>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary" name="tgs_scm_save_time_settings" value="1">Luu cau hinh</button>
					<button type="button" class="button" id="tgs-scm-apply-time-settings">Ap dung cho toan bo website</button>
				</p>
			</form>

			<div id="tgs-scm-time-apply-status" class="tgs-scm-import-status" role="status"></div>
			<div id="tgs-scm-time-apply-progress" class="tgs-scm-import-progress" hidden>
				<div class="tgs-scm-progress-track">
					<span id="tgs-scm-time-apply-fill" class="tgs-scm-progress-fill" style="width: 0%;"></span>
				</div>
				<div id="tgs-scm-time-apply-meta" class="tgs-scm-progress-meta">Chua ap dung.</div>
			</div>
		</div>
		<?php
	}

	public static function render_user_import_page() {
		if ( ! current_user_can( 'manage_network_users' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage network users.', 'tgs-site-code-manager' ) );
		}
		?>
		<div class="wrap tgs-scm-import-page">
			<h1>Import user website TGS</h1>
			<p class="description">File .xlsx can co 4 cot: website, ma website, ten dang nhap, mat khau. Ma website dung de tim blog_id trong bang blogs, user se duoc gan role administrator rieng cho website do.</p>

			<?php self::render_import_controls( 'users' ); ?>
		</div>
		<?php
	}

	private static function render_import_controls( $kind = 'sites' ) {
		$is_user_import = 'users' === $kind;
		$import_label   = $is_user_import ? 'Import user' : 'Import website';
		?>
		<div class="tgs-scm-import-panel" data-import-kind="<?php echo esc_attr( $is_user_import ? 'users' : 'sites' ); ?>">
			<div class="tgs-scm-import-controls">
				<label class="tgs-scm-file-picker" for="tgs-scm-excel-file">
					<span>Chon file Excel</span>
					<input type="file" id="tgs-scm-excel-file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" />
				</label>

				<label class="tgs-scm-sheet-label" for="tgs-scm-sheet-select">
					Sheet
					<select id="tgs-scm-sheet-select" disabled>
						<option value="">Chua co file</option>
					</select>
				</label>

				<button type="button" class="button" id="tgs-scm-preview-button" disabled>Kiem tra truoc du lieu</button>
				<button type="button" class="button button-primary" id="tgs-scm-import-button" disabled><?php echo esc_html( $import_label ); ?></button>
				<button type="button" class="button tgs-scm-stop-button" id="tgs-scm-stop-button" disabled hidden>Dung import</button>
			</div>

			<div id="tgs-scm-import-status" class="tgs-scm-import-status" role="status"></div>
			<div id="tgs-scm-import-progress" class="tgs-scm-import-progress" hidden>
				<div class="tgs-scm-progress-track">
					<span id="tgs-scm-progress-fill" class="tgs-scm-progress-fill" style="width: 0%;"></span>
				</div>
				<div id="tgs-scm-progress-meta" class="tgs-scm-progress-meta">Chua import.</div>
				<div id="tgs-scm-progress-errors" class="tgs-scm-progress-errors" hidden></div>
			</div>
		</div>

		<div id="tgs-scm-preview-summary" class="tgs-scm-preview-summary" hidden></div>
		<div class="tgs-scm-preview-wrap">
			<table class="widefat striped tgs-scm-preview-table" id="tgs-scm-preview-table" hidden>
				<thead>
					<tr>
						<?php if ( $is_user_import ) : ?>
							<th data-field="row_number">Dong</th>
							<th data-field="website">Website</th>
							<th data-field="code">Ma</th>
							<th data-field="blog_id">Blog ID</th>
							<th data-field="username">Ten dang nhap</th>
							<th data-field="email">Email</th>
							<th data-field="message">Trang thai</th>
						<?php else : ?>
							<th data-field="row_number">Dong</th>
							<th data-field="website">Website</th>
							<th data-field="code">Ma</th>
							<th data-field="title">Tieu de</th>
							<th data-field="email">Email</th>
							<th data-field="message">Trang thai</th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
		</div>
		<?php
	}

	private static function check_ajax_permission( $capabilities = 'create_sites' ) {
		check_ajax_referer( 'tgs_scm_admin', 'nonce' );

		$capabilities = (array) $capabilities;
		$allowed      = false;
		foreach ( $capabilities as $capability ) {
			if ( current_user_can( $capability ) ) {
				$allowed = true;
				break;
			}
		}

		if ( ! is_multisite() || ! $allowed ) {
			wp_send_json_error( array( 'message' => 'Ban khong co quyen thuc hien thao tac nay.' ), 403 );
		}

		self::ensure_schema();
	}

	private static function default_time_settings() {
		return array(
			'timezone_string' => '',
			'gmt_offset'      => '7',
			'date_format'     => 'd/m/Y',
			'time_format'     => 'g:i a',
			'start_of_week'   => 1,
		);
	}

	private static function get_network_time_settings() {
		$settings = get_site_option( self::NETWORK_TIME_SETTINGS_OPTION, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings = array_merge( self::default_time_settings(), $settings );
		$settings = self::sanitize_time_settings( $settings );

		return is_wp_error( $settings ) ? self::default_time_settings() : $settings;
	}

	private static function sanitize_time_settings( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$timezone = isset( $settings['timezone_string'] ) ? trim( (string) $settings['timezone_string'] ) : '';
		$offset   = isset( $settings['gmt_offset'] ) ? (string) $settings['gmt_offset'] : '7';

		if ( '' !== $timezone && preg_match( '/^UTC[+-]/', $timezone ) ) {
			$offset   = preg_replace( '/UTC\+?/', '', $timezone );
			$timezone = '';
		} elseif ( '' !== $timezone && ! in_array( $timezone, timezone_identifiers_list( DateTimeZone::ALL_WITH_BC ), true ) ) {
			return new WP_Error( 'invalid_timezone', 'Timezone khong hop le.' );
		} elseif ( '' === $timezone ) {
			$offset = '' !== $offset ? $offset : '7';
		}

		if ( '' === $timezone && ! is_numeric( $offset ) ) {
			return new WP_Error( 'invalid_gmt_offset', 'GMT offset khong hop le.' );
		}

		$date_format   = isset( $settings['date_format'] ) ? sanitize_option( 'date_format', $settings['date_format'] ) : 'd/m/Y';
		$time_format   = isset( $settings['time_format'] ) ? sanitize_option( 'time_format', $settings['time_format'] ) : 'g:i a';
		$start_of_week = isset( $settings['start_of_week'] ) ? (int) $settings['start_of_week'] : 1;

		if ( '' === $date_format ) {
			$date_format = 'd/m/Y';
		}

		if ( '' === $time_format ) {
			$time_format = 'g:i a';
		}

		if ( $start_of_week < 0 || $start_of_week > 6 ) {
			$start_of_week = 1;
		}

		return array(
			'timezone_string' => $timezone,
			'gmt_offset'      => (string) $offset,
			'date_format'     => $date_format,
			'time_format'     => $time_format,
			'start_of_week'   => $start_of_week,
		);
	}

	private static function timezone_choice_value( $settings ) {
		$timezone = isset( $settings['timezone_string'] ) ? (string) $settings['timezone_string'] : '';
		if ( '' !== $timezone ) {
			return $timezone;
		}

		$offset = isset( $settings['gmt_offset'] ) ? (float) $settings['gmt_offset'] : 7;
		if ( 0.0 === $offset ) {
			return 'UTC+0';
		}

		return $offset > 0 ? 'UTC+' . $offset : 'UTC' . $offset;
	}

	private static function merge_time_settings_into_options( $options ) {
		$settings = self::get_network_time_settings();
		return array_merge( $options, self::time_settings_to_blog_options( $settings ) );
	}

	private static function time_settings_to_blog_options( $settings ) {
		return array(
			'timezone_string' => $settings['timezone_string'],
			'gmt_offset'      => $settings['gmt_offset'],
			'date_format'     => $settings['date_format'],
			'time_format'     => $settings['time_format'],
			'start_of_week'   => (int) $settings['start_of_week'],
		);
	}

	private static function apply_time_settings_to_site( $blog_id, $settings = null ) {
		$blog_id = (int) $blog_id;
		if ( $blog_id <= 0 ) {
			return false;
		}

		$settings = is_array( $settings ) ? $settings : self::get_network_time_settings();
		foreach ( self::time_settings_to_blog_options( $settings ) as $option => $value ) {
			update_blog_option( $blog_id, $option, $value );
		}

		clean_blog_cache( $blog_id );
		return true;
	}

	private static function format_preview_date( $format, $settings ) {
		$timestamp = time();
		$timezone  = isset( $settings['timezone_string'] ) && '' !== $settings['timezone_string'] ? $settings['timezone_string'] : self::timezone_choice_value( $settings );

		if ( 0 === strpos( $timezone, 'UTC' ) ) {
			$offset = isset( $settings['gmt_offset'] ) ? (float) $settings['gmt_offset'] : 0;
			return gmdate( $format, $timestamp + (int) ( $offset * HOUR_IN_SECONDS ) );
		}

		try {
			$date = new DateTime( '@' . $timestamp );
			$date->setTimezone( new DateTimeZone( $timezone ) );
			return $date->format( $format );
		} catch ( Exception $e ) {
			return gmdate( $format, $timestamp );
		}
	}

	private static function render_weekday_options( $selected ) {
		global $wp_locale;

		for ( $day_index = 0; $day_index <= 6; $day_index++ ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $day_index,
				selected( $selected, $day_index, false ),
				esc_html( $wp_locale->get_weekday( $day_index ) )
			);
		}
	}

	private static function normalize_code( $code ) {
		$code = is_scalar( $code ) ? (string) $code : '';
		$code = trim( $code );
		$code = preg_replace( '/\s+/', '', $code );
		return strtoupper( $code );
	}

	private static function validate_code( $code, $ignore_blog_id = 0 ) {
		$format_validation = self::validate_code_format( $code );
		if ( is_wp_error( $format_validation ) ) {
			return $format_validation;
		}

		$code = self::normalize_code( $code );
		$existing = self::get_blog_id_by_code( $code );
		if ( $existing && (int) $existing !== (int) $ignore_blog_id ) {
			return new WP_Error( 'site_code_exists', 'Ma website da ton tai.' );
		}

		return true;
	}

	private static function validate_code_format( $code ) {
		$code = self::normalize_code( $code );

		if ( '' === $code ) {
			return new WP_Error( 'site_code_required', 'Ma website la bat buoc.' );
		}

		if ( strlen( $code ) > 32 ) {
			return new WP_Error( 'site_code_too_long', 'Ma website khong duoc vuot qua 32 ky tu.' );
		}

		if ( ! preg_match( '/^[A-Z0-9_-]+$/', $code ) ) {
			return new WP_Error( 'site_code_invalid', 'Ma chi duoc gom chu, so, dau gach ngang hoac gach duoi.' );
		}

		return true;
	}

	private static function get_blog_id_by_code( $code ) {
		global $wpdb;

		$code = self::normalize_code( $code );
		if ( '' === $code ) {
			return 0;
		}

		$column_exists = $wpdb->get_var(
			$wpdb->prepare( "SHOW COLUMNS FROM `{$wpdb->blogs}` LIKE %s", self::COLUMN_SITE_CODE )
		);
		if ( ! $column_exists ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT blog_id FROM `{$wpdb->blogs}` WHERE `" . self::COLUMN_SITE_CODE . "` = %s LIMIT 1",
				$code
			)
		);
	}

	private static function get_site_code_by_blog_id( $blog_id ) {
		global $wpdb;

		$blog_id = (int) $blog_id;
		if ( $blog_id <= 0 ) {
			return '';
		}

		$column_exists = $wpdb->get_var(
			$wpdb->prepare( "SHOW COLUMNS FROM `{$wpdb->blogs}` LIKE %s", self::COLUMN_SITE_CODE )
		);
		if ( ! $column_exists ) {
			return '';
		}

		$code = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT `" . self::COLUMN_SITE_CODE . "` FROM `{$wpdb->blogs}` WHERE blog_id = %d LIMIT 1",
				$blog_id
			)
		);

		return self::normalize_code( $code );
	}

	private static function assign_code_to_site( $blog_id, $code ) {
		global $wpdb;

		$blog_id = (int) $blog_id;
		$code    = self::normalize_code( $code );

		if ( $blog_id <= 0 || '' === $code ) {
			return false;
		}

		self::ensure_schema();

		$updated = $wpdb->update(
			$wpdb->blogs,
			array( self::COLUMN_SITE_CODE => $code ),
			array( 'blog_id' => $blog_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'site_code_update_failed', 'Khong luu duoc ma website vao bang blogs: ' . $wpdb->last_error );
		}

		update_blog_option( $blog_id, self::OPTION_SITE_CODE, $code );
		update_blog_option( $blog_id, 'tgs_site_code', $code );
		clean_blog_cache( $blog_id );
		self::bootstrap_new_site_plugins( $blog_id );

		return true;
	}

	private static function bootstrap_new_site_plugins( $blog_id ) {
		$blog_id = (int) $blog_id;
		if ( $blog_id <= 0 ) {
			return;
		}

		$switched = false;
		if ( get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		if ( ! defined( 'TGS_TABLE_LOCAL_LEDGER' ) && ! class_exists( 'TGS_Shop_Constants' ) ) {
			$constants_file = WP_PLUGIN_DIR . '/tgs_shop_management/includes/class-tgs-constants.php';
			if ( file_exists( $constants_file ) ) {
				require_once $constants_file;
			}
		}

		if ( class_exists( 'TGS_Shop_Constants' ) && ! defined( 'TGS_TABLE_LOCAL_LEDGER' ) ) {
			TGS_Shop_Constants::init();
		}

		$can_run_shop_database = defined( 'TGS_TABLE_LOCAL_LEDGER' ) && TGS_TABLE_LOCAL_LEDGER === $GLOBALS['wpdb']->prefix . 'local_ledger';

		if ( $can_run_shop_database && ! class_exists( 'TGS_Shop_Database' ) ) {
			$database_file = defined( 'TGS_SHOP_PLUGIN_DIR' )
				? TGS_SHOP_PLUGIN_DIR . 'database/class-tgs-database.php'
				: WP_PLUGIN_DIR . '/tgs_shop_management/database/class-tgs-database.php';

			if ( file_exists( $database_file ) ) {
				require_once $database_file;
			}
		}

		if ( $can_run_shop_database && class_exists( 'TGS_Shop_Database' ) && method_exists( 'TGS_Shop_Database', 'activate' ) ) {
			TGS_Shop_Database::activate();
		}

		do_action( 'tgs_scm_after_new_site_bootstrap', $blog_id );

		if ( $switched ) {
			restore_current_blog();
		}
	}

	private static function get_request_import_code_for_site( $site ) {
		if ( empty( $GLOBALS['tgs_scm_import_codes'] ) || ! is_array( $GLOBALS['tgs_scm_import_codes'] ) ) {
			return '';
		}

		$key = strtolower( $site->domain . '|' . trailingslashit( $site->path ) );
		return isset( $GLOBALS['tgs_scm_import_codes'][ $key ] ) ? self::normalize_code( $GLOBALS['tgs_scm_import_codes'][ $key ] ) : '';
	}

	private static function get_import_state( $token ) {
		if ( '' === $token ) {
			return new WP_Error( 'missing_token', 'Phien import khong hop le.' );
		}

		$state = get_transient( self::TRANSIENT_PREFIX . $token );
		if ( empty( $state ) || ! is_array( $state ) || empty( $state['file'] ) || ! file_exists( $state['file'] ) ) {
			return new WP_Error( 'expired_token', 'Phien import da het han, vui long upload lai file.' );
		}

		return $state;
	}

	private static function finish_import_state( $token, $state ) {
		delete_transient( self::TRANSIENT_PREFIX . $token );
		if ( ! empty( $state['file'] ) && file_exists( $state['file'] ) ) {
			@unlink( $state['file'] );
		}
	}

	private static function store_uploaded_xlsx( $file ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'invalid_upload', 'File upload khong hop le.' );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $file['tmp_name'] ) ) {
			return new WP_Error( 'invalid_xlsx_zip', 'File khong phai dinh dang .xlsx hop le.' );
		}

		$has_workbook = false !== $zip->locateName( 'xl/workbook.xml' );
		$zip->close();

		if ( ! $has_workbook ) {
			return new WP_Error( 'invalid_xlsx_workbook', 'File .xlsx thieu workbook metadata.' );
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'upload_dir_error', $uploads['error'] );
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'tgs-site-code-imports';
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'upload_dir_create_failed', 'Khong tao duoc thu muc upload import.' );
		}

		$filename = wp_unique_filename( $dir, sanitize_file_name( $file['name'] ) );
		$target   = trailingslashit( $dir ) . $filename;

		if ( ! move_uploaded_file( $file['tmp_name'], $target ) ) {
			return new WP_Error( 'upload_move_failed', 'Khong luu duoc file Excel len server.' );
		}

		return array(
			'file' => $target,
			'name' => $filename,
		);
	}

	private static function build_import_preview( $rows ) {
		$header_index = self::find_header_index( $rows );
		if ( -1 === $header_index ) {
			return array(
				'has_errors' => true,
				'summary'    => array(
					'total'   => 0,
					'valid'   => 0,
					'skipped' => 0,
					'errors'  => 1,
				),
				'rows'       => array(
					array(
						'row_number' => 0,
						'website'    => '',
						'code'       => '',
						'title'      => '',
						'email'      => '',
						'status'     => 'error',
						'message'    => 'Khong thay cot website trong file Excel.',
					),
				),
				'valid_rows' => array(),
			);
		}

		$headers = self::map_headers( $rows[ $header_index ] );
		$required_error = '';
		if ( ! isset( $headers['website'] ) ) {
			$required_error = 'Khong thay cot website.';
		} elseif ( ! isset( $headers['code'] ) ) {
			$required_error = 'Khong thay cot ma.';
		} elseif ( ! isset( $headers['title'] ) ) {
			$required_error = 'Khong thay cot ten/tieu de website.';
		} elseif ( ! isset( $headers['email'] ) ) {
			$required_error = 'Khong thay cot email website.';
		}

		if ( '' !== $required_error ) {
			return array(
				'has_errors' => true,
				'summary'    => array(
					'total'   => 0,
					'valid'   => 0,
					'skipped' => 0,
					'errors'  => 1,
				),
				'rows'       => array(
					array(
						'row_number' => $header_index + 1,
						'website'    => '',
						'code'       => '',
						'title'      => '',
						'email'      => '',
						'status'     => 'error',
						'message'    => $required_error,
					),
				),
				'valid_rows' => array(),
			);
		}

		$seen_websites = array();
		$seen_codes    = array();
		$display_rows  = array();
		$valid_rows    = array();
		$total         = 0;
		$valid         = 0;
		$skipped       = 0;
		$errors        = 0;

		for ( $i = $header_index + 1; $i < count( $rows ); $i++ ) {
			$row = $rows[ $i ];
			if ( self::row_is_empty( $row ) ) {
				continue;
			}

			$total++;

			$website = self::normalize_website_slug( self::cell_value( $row, $headers['website'] ) );
			$code    = self::normalize_code( self::cell_value( $row, $headers['code'] ) );
			$title   = trim( (string) self::cell_value( $row, $headers['title'] ) );
			$email   = sanitize_email( self::cell_value( $row, $headers['email'] ) );
			$message = '';
			$status  = 'valid';

			if ( '' === $website ) {
				$status = 'skip';
				$message = 'Dong khong co website, bo qua.';
			} elseif ( isset( $seen_websites[ $website ] ) ) {
				$status = 'error';
				$message = 'Website bi trung trong file voi dong ' . $seen_websites[ $website ] . '.';
			} else {
				$site_validation = self::validate_site_slug_for_import( $website );
				if ( is_wp_error( $site_validation ) ) {
					if ( 'site_exists' === $site_validation->get_error_code() ) {
						$status = 'skip';
						$message = 'Website da ton tai trong DB, bo qua.';
					} else {
						$status = 'error';
						$message = $site_validation->get_error_message();
					}
				} elseif ( '' === $code ) {
					$status = 'error';
					$message = 'Thieu ma website.';
				} elseif ( isset( $seen_codes[ $code ] ) ) {
					$status = 'error';
					$message = 'Ma bi trung trong file voi dong ' . $seen_codes[ $code ] . '.';
				} else {
					$code_validation = self::validate_code_format( $code );
					if ( is_wp_error( $code_validation ) ) {
						$status = 'error';
						$message = $code_validation->get_error_message();
					} elseif ( self::get_blog_id_by_code( $code ) ) {
						$status = 'skip';
						$message = 'Ma website da ton tai trong DB, bo qua.';
					} elseif ( '' === $title ) {
						$status = 'error';
						$message = 'Thieu ten/tieu de website.';
					} elseif ( '' === $email ) {
						$status = 'error';
						$message = 'Thieu email website.';
					} elseif ( ! is_email( $email ) ) {
						$status = 'error';
						$message = 'Email website khong hop le.';
					} elseif ( ! email_exists( $email ) && username_exists( $website ) ) {
						$status = 'error';
						$message = 'Website trung voi username dang co, khong tao duoc user quan tri moi.';
					}
				}
			}

			if ( 'valid' === $status ) {
				$seen_websites[ $website ] = $i + 1;
				$seen_codes[ $code ]       = $i + 1;
				$valid++;
				$message = 'San sang import.';
				$valid_rows[] = array(
					'row_number' => $i + 1,
					'website'    => $website,
					'code'       => $code,
					'title'      => $title,
					'email'      => $email,
				);
			} elseif ( 'skip' === $status ) {
				$skipped++;
			} else {
				$errors++;
				if ( '' !== $website ) {
					$seen_websites[ $website ] = $i + 1;
				}
				if ( '' !== $code ) {
					$seen_codes[ $code ] = $i + 1;
				}
			}

			$display_rows[] = array(
				'row_number' => $i + 1,
				'website'    => $website,
				'code'       => $code,
				'title'      => $title,
				'email'      => $email,
				'status'     => $status,
				'message'    => $message,
			);
		}

		return array(
			'has_errors' => $errors > 0,
			'summary'    => array(
				'total'   => $total,
				'valid'   => $valid,
				'skipped' => $skipped,
				'errors'  => $errors,
			),
			'rows'       => $display_rows,
			'valid_rows' => $valid_rows,
		);
	}

	private static function build_user_import_preview( $rows ) {
		$header_index = self::find_user_header_index( $rows );
		$headers      = array(
			'website'  => 0,
			'code'     => 1,
			'username' => 2,
			'password' => 3,
		);
		$start_index  = 0;

		if ( -1 !== $header_index ) {
			$headers = self::map_user_headers( $rows[ $header_index ] );
			$start_index = $header_index + 1;
		}

		$required_error = '';
		if ( ! isset( $headers['website'] ) ) {
			$required_error = 'Khong thay cot website.';
		} elseif ( ! isset( $headers['code'] ) ) {
			$required_error = 'Khong thay cot ma website.';
		} elseif ( ! isset( $headers['username'] ) ) {
			$required_error = 'Khong thay cot ten dang nhap.';
		} elseif ( ! isset( $headers['password'] ) ) {
			$required_error = 'Khong thay cot mat khau.';
		}

		if ( '' !== $required_error ) {
			return array(
				'has_errors' => true,
				'summary'    => array(
					'total'   => 0,
					'valid'   => 0,
					'skipped' => 0,
					'errors'  => 1,
				),
				'rows'       => array(
					array(
						'row_number' => max( 1, $header_index + 1 ),
						'website'    => '',
						'code'       => '',
						'blog_id'    => '',
						'username'   => '',
						'email'      => '',
						'status'     => 'error',
						'message'    => $required_error,
					),
				),
				'valid_rows' => array(),
			);
		}

		$seen_pairs   = array();
		$display_rows = array();
		$valid_rows   = array();
		$total        = 0;
		$valid        = 0;
		$skipped      = 0;
		$errors       = 0;

		for ( $i = $start_index; $i < count( $rows ); $i++ ) {
			$row = $rows[ $i ];
			if ( self::row_is_empty( $row ) ) {
				continue;
			}

			$total++;

			$website = self::normalize_website_slug( self::cell_value( $row, $headers['website'] ) );
			$code    = self::normalize_code( self::cell_value( $row, $headers['code'] ) );
			$username = self::normalize_username( self::cell_value( $row, $headers['username'] ) );
			$password = trim( (string) self::cell_value( $row, $headers['password'] ) );
			$email    = self::default_email_for_website_or_username( $website, $username );
			$blog_id  = 0;
			$message  = '';
			$valid_message = 'San sang tao user.';
			$status   = 'valid';

			if ( '' === $website ) {
				$status = 'skip';
				$message = 'Dong khong co website, bo qua.';
			} elseif ( '' === $code ) {
				$status = 'error';
				$message = 'Thieu ma website.';
			} elseif ( '' === $username ) {
				$status = 'error';
				$message = 'Thieu ten dang nhap.';
			} elseif ( '' === $password ) {
				$status = 'error';
				$message = 'Thieu mat khau.';
			} else {
				$code_validation = self::validate_code_format( $code );
				if ( is_wp_error( $code_validation ) ) {
					$status = 'error';
					$message = $code_validation->get_error_message();
				} elseif ( ! validate_username( $username ) ) {
					$status = 'error';
					$message = 'Ten dang nhap khong hop le.';
				} elseif ( strlen( $password ) < 4 ) {
					$status = 'error';
					$message = 'Mat khau qua ngan.';
				} else {
					$blog_id = self::get_blog_id_by_code( $code );
					if ( ! $blog_id ) {
						$status = 'error';
						$message = 'Khong tim thay website theo ma trong DB.';
					} elseif ( ! self::site_matches_website_slug( $blog_id, $website ) ) {
						$status = 'error';
						$message = 'Website khong khop voi ma website trong DB.';
					} else {
						$pair_key = $blog_id . '|' . strtolower( $username );
						if ( isset( $seen_pairs[ $pair_key ] ) ) {
							$status = 'error';
							$message = 'User bi trung trong file voi dong ' . $seen_pairs[ $pair_key ] . '.';
						} else {
							$user_id = username_exists( $username );
							if ( $user_id && is_user_member_of_blog( (int) $user_id, (int) $blog_id ) ) {
								$status = 'skip';
								$message = 'User da ton tai trong website nay, bo qua.';
							} elseif ( $user_id ) {
								$valid_message = 'User da ton tai global, se them vao website va cap nhat mat khau.';
							}
						}
					}
				}
			}

			if ( 'valid' === $status ) {
				$seen_pairs[ $blog_id . '|' . strtolower( $username ) ] = $i + 1;
				$valid++;
				$message = $valid_message;
				$valid_rows[] = array(
					'row_number' => $i + 1,
					'website'    => $website,
					'code'       => $code,
					'blog_id'    => (int) $blog_id,
					'username'   => $username,
					'password'   => $password,
					'email'      => $email,
				);
			} elseif ( 'skip' === $status ) {
				$skipped++;
			} else {
				$errors++;
				if ( $blog_id && '' !== $username ) {
					$seen_pairs[ $blog_id . '|' . strtolower( $username ) ] = $i + 1;
				}
			}

			$display_rows[] = array(
				'row_number' => $i + 1,
				'website'    => $website,
				'code'       => $code,
				'blog_id'    => $blog_id ? (int) $blog_id : '',
				'username'   => $username,
				'email'      => $email,
				'status'     => $status,
				'message'    => $message,
			);
		}

		return array(
			'has_errors' => $errors > 0,
			'summary'    => array(
				'total'   => $total,
				'valid'   => $valid,
				'skipped' => $skipped,
				'errors'  => $errors,
			),
			'rows'       => $display_rows,
			'valid_rows' => $valid_rows,
		);
	}

	private static function find_header_index( $rows ) {
		foreach ( $rows as $index => $row ) {
			$headers = self::map_headers( $row );
			if ( isset( $headers['website'] ) ) {
				return (int) $index;
			}
		}

		return -1;
	}

	private static function find_user_header_index( $rows ) {
		foreach ( $rows as $index => $row ) {
			$headers = self::map_user_headers( $row );
			if ( isset( $headers['website'] ) && isset( $headers['code'] ) && isset( $headers['username'] ) ) {
				return (int) $index;
			}
		}

		return -1;
	}

	private static function map_headers( $row ) {
		$map = array();
		foreach ( $row as $index => $value ) {
			$key = self::normalize_header( $value );
			if ( '' === $key ) {
				continue;
			}

			if ( in_array( $key, array( 'website', 'site', 'siteurl', 'url', 'duongdan', 'duongdanwebsite', 'tenthumuc', 'prefix', 'tiento' ), true ) ) {
				$map['website'] = $index;
			} elseif ( in_array( $key, array( 'ma', 'mawebsite', 'macuahang', 'code', 'sitecode', 'websitecode' ), true ) ) {
				$map['code'] = $index;
			} elseif ( in_array( $key, array( 'ten', 'tieude', 'tenwebsite', 'tieudewebsite', 'tentieudewebsite', 'title', 'sitetitle', 'websitetitle' ), true ) ) {
				$map['title'] = $index;
			} elseif ( in_array( $key, array( 'email', 'emailwebsite', 'adminemail', 'mail' ), true ) ) {
				$map['email'] = $index;
			}
		}

		return $map;
	}

	private static function map_user_headers( $row ) {
		$map = array();
		foreach ( $row as $index => $value ) {
			$key = self::normalize_header( $value );
			if ( '' === $key ) {
				continue;
			}

			if ( in_array( $key, array( 'website', 'site', 'siteurl', 'url', 'duongdan', 'duongdanwebsite', 'tenthumuc', 'prefix', 'tiento' ), true ) ) {
				$map['website'] = $index;
			} elseif ( in_array( $key, array( 'ma', 'mawebsite', 'macuahang', 'code', 'sitecode', 'websitecode' ), true ) ) {
				$map['code'] = $index;
			} elseif ( in_array( $key, array( 'tendangnhap', 'username', 'userlogin', 'login', 'taikhoan', 'account' ), true ) ) {
				$map['username'] = $index;
			} elseif ( in_array( $key, array( 'matkhau', 'password', 'pass', 'pwd' ), true ) ) {
				$map['password'] = $index;
			}
		}

		return $map;
	}

	private static function normalize_header( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( function_exists( 'remove_accents' ) ) {
			$value = remove_accents( $value );
		}
		return preg_replace( '/[^a-z0-9]+/', '', $value );
	}

	private static function normalize_website_slug( $value ) {
		$value = trim( (string) $value );
		$value = preg_replace( '#^https?://#i', '', $value );
		$value = trim( $value, " \t\n\r\0\x0B/" );

		if ( false !== strpos( $value, '/' ) ) {
			$parts = explode( '/', $value );
			$value = end( $parts );
		}

		$value = strtolower( $value );
		return $value;
	}

	private static function normalize_username( $value ) {
		$value = trim( (string) $value );
		return sanitize_user( $value, true );
	}

	private static function default_email_for_website_or_username( $website, $username = '' ) {
		$website = self::normalize_website_slug( $website );
		$username = self::normalize_username( $username );
		$local_part = '' !== $website ? $website : $username;
		if ( '' === $local_part ) {
			return '';
		}

		return sanitize_email( $local_part . '@gmail.com' );
	}

	private static function site_matches_website_slug( $blog_id, $website ) {
		$blog_id = (int) $blog_id;
		$website = self::normalize_website_slug( $website );

		if ( $blog_id <= 0 || '' === $website ) {
			return false;
		}

		$details = get_blog_details( $blog_id, false );
		if ( ! $details ) {
			return false;
		}

		$domain = strtolower( (string) $details->domain );
		$path   = trim( (string) $details->path, '/' );
		$last_path = '';
		if ( '' !== $path ) {
			$parts = explode( '/', $path );
			$last_path = strtolower( end( $parts ) );
		}

		if ( $last_path === $website ) {
			return true;
		}

		$domain_parts = explode( '.', preg_replace( '|^www\.|', '', $domain ) );
		return isset( $domain_parts[0] ) && strtolower( $domain_parts[0] ) === $website;
	}

	private static function validate_site_slug_for_import( $website ) {
		global $wpdb;

		$website = self::normalize_website_slug( $website );

		if ( '' === $website ) {
			return new WP_Error( 'missing_website', 'Thieu website.' );
		}

		if ( preg_match( '/[^a-z0-9-]+/', $website ) ) {
			return new WP_Error( 'invalid_website', 'Website chi duoc gom chu thuong a-z, so va dau gach ngang.' );
		}

		if ( ! is_subdomain_install() ) {
			$subdirectory_reserved_names = get_subdirectory_reserved_names();
			if ( in_array( $website, $subdirectory_reserved_names, true ) ) {
				return new WP_Error( 'reserved_website', 'Website nam trong danh sach ten bi cam.' );
			}
		}

		$current_network = get_network();
		$network_domain  = $current_network ? $current_network->domain : '';
		if ( is_subdomain_install() ) {
			$newdomain = $website . '.' . preg_replace( '|^www\.|', '', $network_domain );
			$path      = $current_network->path;
		} else {
			$newdomain = $network_domain;
			$path      = $current_network->path . $website . '/';
		}

		if ( domain_exists( $newdomain, $path, $current_network->id ) ) {
			return new WP_Error( 'site_exists', 'Website da ton tai trong he thong.' );
		}

		$signup = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->signups WHERE domain = %s AND path = %s", $newdomain, trailingslashit( $path ) ) );
		if ( $signup instanceof stdClass ) {
			return new WP_Error( 'signup_reserved', 'Website dang duoc giu trong bang signup.' );
		}

		return true;
	}

	private static function create_site_from_import_row( $row ) {
		global $wpdb;

		$website = self::normalize_website_slug( $row['website'] );
		$code    = self::normalize_code( $row['code'] );
		$title   = trim( (string) $row['title'] );
		$email   = sanitize_email( $row['email'] );

		$site_validation = self::validate_site_slug_for_import( $website );
		if ( is_wp_error( $site_validation ) ) {
			return $site_validation;
		}

		$code_validation = self::validate_code( $code );
		if ( is_wp_error( $code_validation ) ) {
			return $code_validation;
		}

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', 'Email website khong hop le.' );
		}

		$current_network = get_network();
		$network_domain  = $current_network ? $current_network->domain : '';
		if ( is_subdomain_install() ) {
			$newdomain = $website . '.' . preg_replace( '|^www\.|', '', $network_domain );
			$path      = $current_network->path;
		} else {
			$newdomain = $network_domain;
			$path      = $current_network->path . $website . '/';
		}

		$password = 'N/A';
		$user_id  = email_exists( $email );
		if ( ! $user_id ) {
			do_action( 'pre_network_site_new_created_user', $email );

			$user_id = username_exists( $website );
			if ( $user_id ) {
				return new WP_Error( 'username_conflict', 'Website trung voi username dang co.' );
			}

			$password = wp_generate_password( 12, false );
			$user_id  = wpmu_create_user( $website, $password, $email );
			if ( false === $user_id ) {
				return new WP_Error( 'user_create_failed', 'Co loi khi tao user quan tri site.' );
			}

			do_action( 'network_site_new_created_user', $user_id );
		}

		$meta = array(
			'public'                 => 1,
			self::OPTION_SITE_CODE   => $code,
			'tgs_site_code'          => $code,
			'admin_email'            => $email,
		);
		$meta = self::merge_time_settings_into_options( $meta );

		self::$pending_site_code = $code;
		$key = strtolower( $newdomain . '|' . trailingslashit( $path ) );
		if ( empty( $GLOBALS['tgs_scm_import_codes'] ) || ! is_array( $GLOBALS['tgs_scm_import_codes'] ) ) {
			$GLOBALS['tgs_scm_import_codes'] = array();
		}
		$GLOBALS['tgs_scm_import_codes'][ $key ] = $code;

		$wpdb->hide_errors();
		$blog_id = wpmu_create_blog( $newdomain, $path, $title, $user_id, $meta, $current_network->id );
		$wpdb->show_errors();

		unset( $GLOBALS['tgs_scm_import_codes'][ $key ] );
		self::$pending_site_code = '';

		if ( is_wp_error( $blog_id ) ) {
			return $blog_id;
		}

		if ( self::get_site_code_by_blog_id( (int) $blog_id ) !== $code ) {
			$assigned = self::assign_code_to_site( (int) $blog_id, $code );
			if ( is_wp_error( $assigned ) ) {
				return $assigned;
			}
		}

		if ( ! is_super_admin( $user_id ) && ! get_user_option( 'primary_blog', $user_id ) ) {
			update_user_option( $user_id, 'primary_blog', (int) $blog_id, true );
		}

		wpmu_new_site_admin_notification( (int) $blog_id, $user_id );
		wpmu_welcome_notification( (int) $blog_id, $user_id, $password, $title, array( 'public' => 1 ) );

		return array(
			'blog_id' => (int) $blog_id,
			'website' => $website,
			'code'    => $code,
			'title'   => $title,
			'email'   => $email,
			'url'     => get_site_url( (int) $blog_id ),
		);
	}

	private static function create_user_from_import_row( $row ) {
		$blog_id  = isset( $row['blog_id'] ) ? (int) $row['blog_id'] : 0;
		$website  = isset( $row['website'] ) ? self::normalize_website_slug( $row['website'] ) : '';
		$code     = isset( $row['code'] ) ? self::normalize_code( $row['code'] ) : '';
		$username = isset( $row['username'] ) ? self::normalize_username( $row['username'] ) : '';
		$password = isset( $row['password'] ) ? trim( (string) $row['password'] ) : '';
		$email    = isset( $row['email'] ) ? sanitize_email( $row['email'] ) : self::default_email_for_website_or_username( $website, $username );

		if ( $blog_id <= 0 ) {
			$blog_id = self::get_blog_id_by_code( $code );
		}

		if ( $blog_id <= 0 ) {
			return new WP_Error( 'missing_blog', 'Khong tim thay website theo ma trong DB.' );
		}

		if ( ! self::site_matches_website_slug( $blog_id, $website ) ) {
			return new WP_Error( 'site_code_mismatch', 'Website khong khop voi ma website trong DB.' );
		}

		if ( '' === $username || ! validate_username( $username ) ) {
			return new WP_Error( 'invalid_username', 'Ten dang nhap khong hop le.' );
		}

		if ( '' === $password ) {
			return new WP_Error( 'missing_password', 'Thieu mat khau.' );
		}

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', 'Email user khong hop le.' );
		}

		$user_id = username_exists( $username );
		if ( $user_id && is_user_member_of_blog( (int) $user_id, $blog_id ) ) {
			return array(
				'skipped'  => true,
				'user_id'  => (int) $user_id,
				'blog_id'  => $blog_id,
				'website'  => $website,
				'code'     => $code,
				'username' => $username,
				'email'    => $email,
				'message'  => 'User da ton tai trong website nay, bo qua.',
			);
		}

		if ( ! $user_id ) {
			$existing_email_user = email_exists( $email );
			if ( $existing_email_user ) {
				$email = self::unique_email_for_username( '' !== $website ? $website : $username );
			}

			$user_id = wpmu_create_user( $username, $password, $email );
			if ( false === $user_id ) {
				return new WP_Error( 'user_create_failed', 'Co loi khi tao user.' );
			}
		} else {
			wp_set_password( $password, (int) $user_id );
		}

		$added = add_user_to_blog( $blog_id, (int) $user_id, 'administrator' );
		if ( is_wp_error( $added ) ) {
			return $added;
		}

		if ( ! get_user_option( 'primary_blog', (int) $user_id ) ) {
			update_user_option( (int) $user_id, 'primary_blog', $blog_id, true );
		}

		return array(
			'user_id'  => (int) $user_id,
			'blog_id'  => $blog_id,
			'website'  => $website,
			'code'     => $code,
			'username' => $username,
			'email'    => $email,
			'role'     => 'administrator',
			'url'      => get_admin_url( $blog_id ),
		);
	}

	private static function unique_email_for_username( $username ) {
		$username = self::normalize_username( $username );
		$base     = '' !== $username ? $username : 'tgs-user';
		$email    = sanitize_email( $base . '@gmail.com' );

		if ( ! email_exists( $email ) ) {
			return $email;
		}

		for ( $i = 1; $i <= 9999; $i++ ) {
			$email = sanitize_email( $base . '+' . $i . '@gmail.com' );
			if ( ! email_exists( $email ) ) {
				return $email;
			}
		}

		return sanitize_email( $base . '+' . time() . '@gmail.com' );
	}

	private static function cell_value( $row, $index ) {
		return isset( $row[ $index ] ) ? $row[ $index ] : '';
	}

	private static function row_is_empty( $row ) {
		foreach ( $row as $value ) {
			if ( '' !== trim( (string) $value ) ) {
				return false;
			}
		}

		return true;
	}

	private static function upload_error_message( $code ) {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return 'File Excel vuot qua dung luong cho phep.';
			case UPLOAD_ERR_PARTIAL:
				return 'File Excel chi upload duoc mot phan.';
			case UPLOAD_ERR_NO_FILE:
				return 'Chua chon file Excel.';
			default:
				return 'Khong upload duoc file Excel.';
		}
	}
}

final class TGS_SCM_Xlsx_Reader {
	private $file;
	private $zip;
	private $shared_strings;
	private $workbook;

	public function __construct( $file ) {
		$this->file = $file;
	}

	public function get_sheets() {
		$open = $this->open();
		if ( is_wp_error( $open ) ) {
			return $open;
		}

		$workbook = $this->load_workbook();
		if ( is_wp_error( $workbook ) ) {
			return $workbook;
		}

		return array_values(
			array_map(
				function ( $sheet ) {
					return array(
						'name' => $sheet['name'],
						'id'   => $sheet['id'],
					);
				},
				$workbook['sheets']
			)
		);
	}

	public function get_rows( $sheet_name ) {
		$open = $this->open();
		if ( is_wp_error( $open ) ) {
			return $open;
		}

		$workbook = $this->load_workbook();
		if ( is_wp_error( $workbook ) ) {
			return $workbook;
		}

		$target = null;
		foreach ( $workbook['sheets'] as $sheet ) {
			if ( $sheet['name'] === $sheet_name || $sheet['id'] === $sheet_name ) {
				$target = $sheet;
				break;
			}
		}

		if ( ! $target ) {
			return new WP_Error( 'missing_sheet', 'Khong tim thay sheet Excel da chon.' );
		}

		$xml = $this->get_entry( $target['path'] );
		if ( '' === $xml ) {
			return new WP_Error( 'missing_sheet_xml', 'Khong doc duoc noi dung sheet Excel.' );
		}

		$shared_strings = $this->load_shared_strings();
		if ( is_wp_error( $shared_strings ) ) {
			return $shared_strings;
		}

		return $this->parse_sheet_rows( $xml, $shared_strings );
	}

	private function open() {
		if ( $this->zip instanceof ZipArchive ) {
			return true;
		}

		if ( ! file_exists( $this->file ) ) {
			return new WP_Error( 'missing_file', 'Khong tim thay file Excel.' );
		}

		$this->zip = new ZipArchive();
		if ( true !== $this->zip->open( $this->file ) ) {
			return new WP_Error( 'invalid_xlsx', 'Khong mo duoc file Excel. Vui long kiem tra lai file .xlsx.' );
		}

		return true;
	}

	private function load_workbook() {
		if ( null !== $this->workbook ) {
			return $this->workbook;
		}

		$workbook_xml = $this->get_entry( 'xl/workbook.xml' );
		$rels_xml     = $this->get_entry( 'xl/_rels/workbook.xml.rels' );

		if ( '' === $workbook_xml || '' === $rels_xml ) {
			return new WP_Error( 'invalid_workbook', 'File Excel thieu workbook metadata.' );
		}

		$rels = array();
		$rels_doc = $this->load_xml( $rels_xml );
		if ( is_wp_error( $rels_doc ) ) {
			return $rels_doc;
		}

		foreach ( $rels_doc->getElementsByTagName( 'Relationship' ) as $rel ) {
			$rels[ $rel->getAttribute( 'Id' ) ] = $rel->getAttribute( 'Target' );
		}

		$doc = $this->load_xml( $workbook_xml );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}

		$sheets = array();
		foreach ( $doc->getElementsByTagName( 'sheet' ) as $sheet ) {
			$rid = $sheet->getAttribute( 'r:id' );
			if ( '' === $rid ) {
				$rid = $sheet->getAttributeNS( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id' );
			}

			if ( empty( $rels[ $rid ] ) ) {
				continue;
			}

			$path = ltrim( $rels[ $rid ], '/' );
			if ( 0 !== strpos( $path, 'xl/' ) ) {
				$path = 'xl/' . ltrim( $path, '/' );
			}

			$sheets[] = array(
				'name' => $sheet->getAttribute( 'name' ),
				'id'   => $sheet->getAttribute( 'sheetId' ),
				'path' => $path,
			);
		}

		if ( empty( $sheets ) ) {
			return new WP_Error( 'no_sheets', 'File Excel khong co sheet nao.' );
		}

		$this->workbook = array( 'sheets' => $sheets );
		return $this->workbook;
	}

	private function load_shared_strings() {
		if ( null !== $this->shared_strings ) {
			return $this->shared_strings;
		}

		$xml = $this->get_entry( 'xl/sharedStrings.xml' );
		if ( '' === $xml ) {
			$this->shared_strings = array();
			return $this->shared_strings;
		}

		$doc = $this->load_xml( $xml );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}

		$strings = array();
		foreach ( $doc->getElementsByTagName( 'si' ) as $si ) {
			$text = '';
			foreach ( $si->getElementsByTagName( 't' ) as $t ) {
				$text .= $t->nodeValue;
			}
			$strings[] = $text;
		}

		$this->shared_strings = $strings;
		return $this->shared_strings;
	}

	private function parse_sheet_rows( $xml, $shared_strings ) {
		$doc = $this->load_xml( $xml );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}

		$rows = array();
		foreach ( $doc->getElementsByTagName( 'row' ) as $row_node ) {
			$row_index = max( 1, (int) $row_node->getAttribute( 'r' ) );
			$values = array();

			foreach ( $row_node->getElementsByTagName( 'c' ) as $cell_node ) {
				$ref = $cell_node->getAttribute( 'r' );
				$column_index = $this->column_index_from_ref( $ref );
				$type = $cell_node->getAttribute( 't' );
				$value = $this->extract_cell_value( $cell_node, $type, $shared_strings );
				$values[ $column_index ] = $value;
			}

			if ( ! empty( $values ) ) {
				ksort( $values );
				$max = max( array_keys( $values ) );
				$normalized = array();
				for ( $i = 0; $i <= $max; $i++ ) {
					$normalized[ $i ] = isset( $values[ $i ] ) ? $values[ $i ] : '';
				}
				$rows[ $row_index - 1 ] = $normalized;
			}
		}

		if ( empty( $rows ) ) {
			return array();
		}

		ksort( $rows );
		$max_row = max( array_keys( $rows ) );
		$normalized_rows = array();
		for ( $i = 0; $i <= $max_row; $i++ ) {
			$normalized_rows[ $i ] = isset( $rows[ $i ] ) ? $rows[ $i ] : array();
		}

		return $normalized_rows;
	}

	private function extract_cell_value( DOMElement $cell_node, $type, $shared_strings ) {
		if ( 'inlineStr' === $type ) {
			$text = '';
			foreach ( $cell_node->getElementsByTagName( 't' ) as $t ) {
				$text .= $t->nodeValue;
			}
			return trim( $text );
		}

		$value_node = null;
		foreach ( $cell_node->childNodes as $child ) {
			if ( $child instanceof DOMElement && 'v' === $child->localName ) {
				$value_node = $child;
				break;
			}
		}

		if ( ! $value_node ) {
			return '';
		}

		$value = $value_node->nodeValue;

		if ( 's' === $type ) {
			$index = (int) $value;
			return isset( $shared_strings[ $index ] ) ? trim( $shared_strings[ $index ] ) : '';
		}

		if ( 'b' === $type ) {
			return '1' === $value ? '1' : '0';
		}

		return trim( (string) $value );
	}

	private function column_index_from_ref( $ref ) {
		if ( ! preg_match( '/^([A-Z]+)/i', $ref, $matches ) ) {
			return 0;
		}

		$letters = strtoupper( $matches[1] );
		$index = 0;
		for ( $i = 0; $i < strlen( $letters ); $i++ ) {
			$index = $index * 26 + ( ord( $letters[ $i ] ) - 64 );
		}

		return max( 0, $index - 1 );
	}

	private function get_entry( $path ) {
		$path = str_replace( '\\', '/', $path );
		$content = $this->zip->getFromName( $path );
		return false === $content ? '' : $content;
	}

	private function load_xml( $xml ) {
		$previous = libxml_use_internal_errors( true );
		$doc = new DOMDocument();
		$loaded = $doc->loadXML( $xml, LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return new WP_Error( 'invalid_xml', 'File Excel co cau truc XML khong hop le.' );
		}

		return $doc;
	}
}

register_activation_hook( __FILE__, array( 'TGS_Site_Code_Manager', 'activate' ) );
TGS_Site_Code_Manager::init();
