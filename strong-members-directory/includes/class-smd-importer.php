<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SMD_Importer {
	const PREVIEW_TRANSIENT_PREFIX = 'smd_import_preview_';

	/**
	 * Register hooks.
	 */
	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
		add_action( 'admin_post_smd_import_members', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_smd_commit_member_import', array( __CLASS__, 'handle_commit_import' ) );
		add_action( 'admin_post_smd_export_members', array( __CLASS__, 'handle_export' ) );
	}

	/**
	 * Add import submenu.
	 */
	public static function register_admin_page() {
		add_submenu_page(
			'edit.php?post_type=' . SMD_Member_Post_Type::POST_TYPE,
			__( 'Import Members', 'strong-members-directory' ),
			__( 'Import Members', 'strong-members-directory' ),
			'manage_options',
			'smd-import-members',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Render import page.
	 */
	public static function render_admin_page() {
		$report        = self::get_report_from_request();
		$preview_token = isset( $_GET['smd_preview_token'] ) ? sanitize_key( wp_unslash( $_GET['smd_preview_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$preview       = $preview_token ? get_transient( self::PREVIEW_TRANSIENT_PREFIX . $preview_token ) : false;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import Members', 'strong-members-directory' ); ?></h1>
			<p><?php esc_html_e( 'Upload a CSV or Excel .xlsx file from Excel, Google Sheets, or Numbers. The importer now analyzes the file first, detects duplicates, and gives you a dry-run report before anything is created.', 'strong-members-directory' ); ?></p>
			<p><?php esc_html_e( 'When a row includes a valid email address, the import also creates or links that member’s WordPress login automatically.', 'strong-members-directory' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( 'smd_import_members', 'smd_import_nonce' ); ?>
				<input type="hidden" name="action" value="smd_import_members">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="smd_import_file"><?php esc_html_e( 'Spreadsheet File', 'strong-members-directory' ); ?></label></th>
						<td>
							<input type="file" id="smd_import_file" name="smd_import_file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
							<p class="description"><?php esc_html_e( 'Supported formats: .csv and .xlsx', 'strong-members-directory' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Analyze Import File', 'strong-members-directory' ) ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
				<?php wp_nonce_field( 'smd_export_members', 'smd_export_nonce' ); ?>
				<input type="hidden" name="action" value="smd_export_members">
				<?php submit_button( __( 'Export Members CSV', 'strong-members-directory' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Expected Columns', 'strong-members-directory' ); ?></h2>
			<pre>first_name,last_name,email,occupation,member_type,phone,website,linkedin,bio,profile_image_url</pre>

			<?php if ( $preview && is_array( $preview ) ) : ?>
				<?php self::render_preview( $preview_token, $preview ); ?>
			<?php elseif ( $preview_token ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'That import preview expired. Please upload the file again to generate a fresh dry-run report.', 'strong-members-directory' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! empty( $report ) ) : ?>
				<?php self::render_report( $report ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Process uploaded file into a dry-run preview.
	 */
	public static function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to import members.', 'strong-members-directory' ) );
		}

		check_admin_referer( 'smd_import_members', 'smd_import_nonce' );

		if ( empty( $_FILES['smd_import_file']['tmp_name'] ) ) {
			wp_safe_redirect( self::report_url( self::empty_report( __( 'No file was uploaded.', 'strong-members-directory' ) ) ) );
			exit;
		}

		$rows = self::parse_uploaded_file( $_FILES['smd_import_file'] );

		if ( is_wp_error( $rows ) ) {
			wp_safe_redirect( self::report_url( self::empty_report( $rows->get_error_message() ) ) );
			exit;
		}

		$preview = self::build_preview( $rows );
		$token   = wp_generate_password( 20, false, false );

		set_transient( self::PREVIEW_TRANSIENT_PREFIX . $token, $preview, HOUR_IN_SECONDS );

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'          => SMD_Member_Post_Type::POST_TYPE,
					'page'               => 'smd-import-members',
					'smd_preview_token'  => $token,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Apply a previously reviewed import preview.
	 */
	public static function handle_commit_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to import members.', 'strong-members-directory' ) );
		}

		check_admin_referer( 'smd_commit_member_import', 'smd_commit_import_nonce' );

		$token   = isset( $_POST['smd_preview_token'] ) ? sanitize_key( wp_unslash( $_POST['smd_preview_token'] ) ) : '';
		$preview = $token ? get_transient( self::PREVIEW_TRANSIENT_PREFIX . $token ) : false;

		if ( ! $token || ! is_array( $preview ) ) {
			wp_safe_redirect( self::report_url( self::empty_report( __( 'The import preview expired. Please analyze the file again before importing.', 'strong-members-directory' ) ) ) );
			exit;
		}

		$report = array(
			'created'      => 0,
			'updated'      => 0,
			'skipped'      => 0,
			'rows'         => array(),
			'errors'       => array(),
			'duplicates'   => $preview['summary']['duplicate_rows'],
			'total_rows'   => $preview['summary']['total_rows'],
			'preview_only' => false,
		);

		foreach ( $preview['rows'] as $row ) {
			$report['rows'][] = array(
				'row_number' => $row['row_number'],
				'name'       => $row['name'],
				'email'      => $row['email'],
				'action'     => $row['action_label'],
				'match'      => $row['match_label'],
				'notes'      => $row['notes'],
			);

			if ( 'skip' === $row['action'] ) {
				++$report['skipped'];
				if ( $row['notes'] ) {
					$report['errors'][] = sprintf(
						/* translators: 1: row number, 2: reason */
						__( 'Row %1$d skipped: %2$s', 'strong-members-directory' ),
						(int) $row['row_number'],
						$row['notes']
					);
				}
				continue;
			}

			$result = self::upsert_member( $row['data'] );

			if ( is_wp_error( $result ) ) {
				++$report['skipped'];
				$report['errors'][] = sprintf(
					/* translators: 1: row number, 2: error */
					__( 'Row %1$d failed: %2$s', 'strong-members-directory' ),
					(int) $row['row_number'],
					$result->get_error_message()
				);
				continue;
			}

			++$report[ $result ];
		}

		delete_transient( self::PREVIEW_TRANSIENT_PREFIX . $token );

		wp_safe_redirect( self::report_url( $report ) );
		exit;
	}

	/**
	 * Export all members to CSV.
	 */
	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export members.', 'strong-members-directory' ) );
		}

		check_admin_referer( 'smd_export_members', 'smd_export_nonce' );

		$members = get_posts(
			array(
				'post_type'      => SMD_Member_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=members-export.csv' );

		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $output, array( 'first_name', 'last_name', 'email', 'occupation', 'member_type', 'phone', 'website', 'linkedin', 'bio' ) );

		foreach ( $members as $member ) {
			$fields = SMD_Member_Post_Type::get_member_data( $member->ID );
			fputcsv(
				$output,
				array(
					$fields['first_name'],
					$fields['last_name'],
					$fields['email'],
					$fields['occupation'],
					$fields['member_type'],
					$fields['phone'],
					$fields['website'],
					$fields['linkedin'],
					wp_strip_all_tags( $member->post_content ),
				)
			);
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Render dry-run preview.
	 *
	 * @param string $preview_token Preview token.
	 * @param array  $preview Preview payload.
	 */
	private static function render_preview( $preview_token, $preview ) {
		$summary = $preview['summary'];
		?>
		<h2><?php esc_html_e( 'Dry-Run Import Preview', 'strong-members-directory' ); ?></h2>
		<p><?php echo esc_html( sprintf( __( 'Source file: %s', 'strong-members-directory' ), (string) $preview['file_name'] ) ); ?></p>
		<ul>
			<li><?php echo esc_html( sprintf( __( 'Rows analyzed: %d', 'strong-members-directory' ), (int) $summary['total_rows'] ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'Would create: %d', 'strong-members-directory' ), (int) $summary['created'] ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'Would update: %d', 'strong-members-directory' ), (int) $summary['updated'] ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'Would skip: %d', 'strong-members-directory' ), (int) $summary['skipped'] ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'Duplicate rows detected in file: %d', 'strong-members-directory' ), (int) $summary['duplicate_rows'] ) ); ?></li>
		</ul>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:16px 0 22px;">
			<?php wp_nonce_field( 'smd_commit_member_import', 'smd_commit_import_nonce' ); ?>
			<input type="hidden" name="action" value="smd_commit_member_import">
			<input type="hidden" name="smd_preview_token" value="<?php echo esc_attr( $preview_token ); ?>">
			<?php submit_button( __( 'Apply Import', 'strong-members-directory' ), 'primary', 'submit', false ); ?>
		</form>

		<?php if ( ! empty( $preview['errors'] ) ) : ?>
			<h3><?php esc_html_e( 'Issues Found During Analysis', 'strong-members-directory' ); ?></h3>
			<ul>
				<?php foreach ( $preview['errors'] as $error ) : ?>
					<li><?php echo esc_html( $error ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Row-by-Row Preview', 'strong-members-directory' ); ?></h3>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Row', 'strong-members-directory' ); ?></th>
					<th><?php esc_html_e( 'Name', 'strong-members-directory' ); ?></th>
					<th><?php esc_html_e( 'Email', 'strong-members-directory' ); ?></th>
					<th><?php esc_html_e( 'Action', 'strong-members-directory' ); ?></th>
					<th><?php esc_html_e( 'Match Type', 'strong-members-directory' ); ?></th>
					<th><?php esc_html_e( 'Notes', 'strong-members-directory' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $preview['rows'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $row['row_number'] ); ?></td>
						<td><?php echo esc_html( $row['name'] ); ?></td>
						<td><?php echo esc_html( $row['email'] ); ?></td>
						<td><?php echo esc_html( $row['action_label'] ); ?></td>
						<td><?php echo esc_html( $row['match_label'] ); ?></td>
						<td><?php echo esc_html( $row['notes'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render final import report.
	 *
	 * @param array $report Report payload.
	 */
	private static function render_report( $report ) {
		?>
		<h2><?php esc_html_e( 'Last Import Summary', 'strong-members-directory' ); ?></h2>
		<ul>
			<li><?php echo esc_html( sprintf( __( 'Created: %d', 'strong-members-directory' ), (int) $report['created'] ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'Updated: %d', 'strong-members-directory' ), (int) $report['updated'] ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'Skipped: %d', 'strong-members-directory' ), (int) $report['skipped'] ) ); ?></li>
			<?php if ( isset( $report['duplicates'] ) ) : ?>
				<li><?php echo esc_html( sprintf( __( 'Duplicate rows detected in file: %d', 'strong-members-directory' ), (int) $report['duplicates'] ) ); ?></li>
			<?php endif; ?>
		</ul>
		<?php if ( ! empty( $report['rows'] ) ) : ?>
			<h3><?php esc_html_e( 'Processed Rows', 'strong-members-directory' ); ?></h3>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Row', 'strong-members-directory' ); ?></th>
						<th><?php esc_html_e( 'Name', 'strong-members-directory' ); ?></th>
						<th><?php esc_html_e( 'Email', 'strong-members-directory' ); ?></th>
						<th><?php esc_html_e( 'Action', 'strong-members-directory' ); ?></th>
						<th><?php esc_html_e( 'Match Type', 'strong-members-directory' ); ?></th>
						<th><?php esc_html_e( 'Notes', 'strong-members-directory' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $report['rows'] as $row ) : ?>
						<tr>
							<td><?php echo esc_html( isset( $row['row_number'] ) ? (string) $row['row_number'] : ''); ?></td>
							<td><?php echo esc_html( $row['name'] ); ?></td>
							<td><?php echo esc_html( $row['email'] ); ?></td>
							<td><?php echo esc_html( $row['action'] ); ?></td>
							<td><?php echo esc_html( isset( $row['match'] ) ? $row['match'] : '' ); ?></td>
							<td><?php echo esc_html( isset( $row['notes'] ) ? $row['notes'] : '' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php if ( ! empty( $report['errors'] ) ) : ?>
			<h3><?php esc_html_e( 'Errors', 'strong-members-directory' ); ?></h3>
			<ul>
				<?php foreach ( $report['errors'] as $error ) : ?>
					<li><?php echo esc_html( $error ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php
	}

	/**
	 * Parse uploaded CSV or XLSX into normalized rows.
	 *
	 * @param array $file Uploaded file array.
	 * @return array<int, array<string, string>>|WP_Error
	 */
	private static function parse_uploaded_file( $file ) {
		$filename = isset( $file['name'] ) ? (string) $file['name'] : '';
		$tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';

		if ( '' === $tmp_name ) {
			return new WP_Error( 'smd_import_missing_file', __( 'The uploaded file could not be read.', 'strong-members-directory' ) );
		}

		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( 'csv' === $extension ) {
			return self::parse_csv_file( $tmp_name );
		}

		if ( 'xlsx' === $extension ) {
			return self::parse_xlsx_file( $tmp_name );
		}

		return new WP_Error( 'smd_import_invalid_type', __( 'Please upload a CSV or XLSX spreadsheet.', 'strong-members-directory' ) );
	}

	/**
	 * Parse CSV rows.
	 *
	 * @param string $file_path CSV file path.
	 * @return array<int, array<string, string>>|WP_Error
	 */
	private static function parse_csv_file( $file_path ) {
		$file = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $file ) {
			return new WP_Error( 'smd_import_csv_unreadable', __( 'The CSV file could not be read.', 'strong-members-directory' ) );
		}

		$headers = fgetcsv( $file );

		if ( empty( $headers ) ) {
			fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'smd_import_csv_empty', __( 'The CSV file is empty.', 'strong-members-directory' ) );
		}

		$headers = array_map( array( __CLASS__, 'normalize_header' ), $headers );
		$rows    = array();
		$row_num = 1;

		while ( ( $row = fgetcsv( $file ) ) !== false ) {
			++$row_num;

			if ( 0 === count( array_filter( $row, 'strlen' ) ) ) {
				continue;
			}

			$rows[] = self::map_row( $headers, $row, $row_num );
		}

		fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $rows;
	}

	/**
	 * Parse XLSX rows from the first worksheet.
	 *
	 * @param string $file_path XLSX file path.
	 * @return array<int, array<string, string>>|WP_Error
	 */
	private static function parse_xlsx_file( $file_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'smd_import_missing_ziparchive', __( 'This server cannot read XLSX files because ZipArchive is not available.', 'strong-members-directory' ) );
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $file_path ) ) {
			return new WP_Error( 'smd_import_xlsx_unreadable', __( 'The XLSX file could not be opened.', 'strong-members-directory' ) );
		}

		$shared_strings = self::parse_xlsx_shared_strings( $zip );
		$workbook_xml   = $zip->getFromName( 'xl/workbook.xml' );
		$rels_xml       = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );

		if ( false === $workbook_xml || false === $rels_xml ) {
			$zip->close();
			return new WP_Error( 'smd_import_xlsx_invalid', __( 'The XLSX file is missing workbook data.', 'strong-members-directory' ) );
		}

		$workbook = simplexml_load_string( $workbook_xml );
		$rels     = simplexml_load_string( $rels_xml );

		if ( ! $workbook || ! $rels ) {
			$zip->close();
			return new WP_Error( 'smd_import_xlsx_parse_failed', __( 'The XLSX file could not be parsed.', 'strong-members-directory' ) );
		}

		$sheet_relationship_id = '';
		foreach ( $workbook->sheets->sheet as $sheet ) {
			$attributes = $sheet->attributes( 'r', true );
			$sheet_relationship_id = isset( $attributes['id'] ) ? (string) $attributes['id'] : '';
			if ( '' !== $sheet_relationship_id ) {
				break;
			}
		}

		if ( '' === $sheet_relationship_id ) {
			$zip->close();
			return new WP_Error( 'smd_import_xlsx_no_sheet', __( 'The XLSX file did not contain any worksheets.', 'strong-members-directory' ) );
		}

		$sheet_target = '';
		foreach ( $rels->Relationship as $relationship ) {
			$attributes = $relationship->attributes();
			if ( isset( $attributes['Id'] ) && (string) $attributes['Id'] === $sheet_relationship_id ) {
				$sheet_target = 'xl/' . ltrim( (string) $attributes['Target'], '/' );
				break;
			}
		}

		if ( '' === $sheet_target ) {
			$zip->close();
			return new WP_Error( 'smd_import_xlsx_sheet_missing', __( 'The first worksheet could not be located inside the XLSX file.', 'strong-members-directory' ) );
		}

		$sheet_xml = $zip->getFromName( $sheet_target );
		$zip->close();

		if ( false === $sheet_xml ) {
			return new WP_Error( 'smd_import_xlsx_sheet_unreadable', __( 'The first worksheet could not be read from the XLSX file.', 'strong-members-directory' ) );
		}

		$sheet_data = simplexml_load_string( $sheet_xml );

		if ( ! $sheet_data || ! isset( $sheet_data->sheetData ) ) {
			return new WP_Error( 'smd_import_xlsx_sheet_parse_failed', __( 'The first worksheet could not be parsed.', 'strong-members-directory' ) );
		}

		$parsed_rows = array();
		foreach ( $sheet_data->sheetData->row as $row ) {
			$current = array();
			foreach ( $row->c as $cell ) {
				$reference = isset( $cell['r'] ) ? (string) $cell['r'] : '';
				$column    = preg_replace( '/\d+/', '', $reference );
				$type      = isset( $cell['t'] ) ? (string) $cell['t'] : '';
				$value     = '';

				if ( 'inlineStr' === $type && isset( $cell->is->t ) ) {
					$value = (string) $cell->is->t;
				} elseif ( 's' === $type && isset( $cell->v ) ) {
					$string_index = (int) $cell->v;
					$value        = isset( $shared_strings[ $string_index ] ) ? $shared_strings[ $string_index ] : '';
				} elseif ( isset( $cell->v ) ) {
					$value = (string) $cell->v;
				}

				if ( '' !== $column ) {
					$current[ $column ] = $value;
				}
			}

			if ( ! empty( $current ) ) {
				$parsed_rows[] = $current;
			}
		}

		if ( empty( $parsed_rows ) ) {
			return new WP_Error( 'smd_import_xlsx_empty', __( 'The XLSX worksheet was empty.', 'strong-members-directory' ) );
		}

		$header_row = array_shift( $parsed_rows );
		ksort( $header_row );
		$headers = array_map( array( __CLASS__, 'normalize_header' ), array_values( $header_row ) );
		$rows    = array();

		foreach ( $parsed_rows as $index => $row ) {
			ksort( $row );
			$values = array_values( $row );

			if ( 0 === count( array_filter( $values, 'strlen' ) ) ) {
				continue;
			}

			$rows[] = self::map_row( $headers, $values, $index + 2 );
		}

		return $rows;
	}

	/**
	 * Parse shared strings from XLSX.
	 *
	 * @param ZipArchive $zip Open zip archive.
	 * @return string[]
	 */
	private static function parse_xlsx_shared_strings( $zip ) {
		$xml = $zip->getFromName( 'xl/sharedStrings.xml' );

		if ( false === $xml ) {
			return array();
		}

		$strings = simplexml_load_string( $xml );

		if ( ! $strings ) {
			return array();
		}

		$values = array();
		foreach ( $strings->si as $item ) {
			if ( isset( $item->t ) ) {
				$values[] = (string) $item->t;
				continue;
			}

			$text = '';
			foreach ( $item->r as $run ) {
				$text .= isset( $run->t ) ? (string) $run->t : '';
			}
			$values[] = $text;
		}

		return $values;
	}

	/**
	 * Build a dry-run preview.
	 *
	 * @param array<int, array<string, string>> $rows Parsed rows.
	 * @return array<string, mixed>
	 */
	private static function build_preview( $rows ) {
		$preview = array(
			'file_name' => isset( $_FILES['smd_import_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['smd_import_file']['name'] ) ) : '',
			'summary'   => array(
				'total_rows'     => count( $rows ),
				'created'        => 0,
				'updated'        => 0,
				'skipped'        => 0,
				'duplicate_rows' => 0,
			),
			'rows'      => array(),
			'errors'    => array(),
		);

		$seen_keys = array();

		foreach ( $rows as $row ) {
			$match = self::detect_existing_member( $row );
			$keys  = self::row_duplicate_keys( $row );
			$notes = array();
			$action = 'create';
			$match_label = __( 'New member', 'strong-members-directory' );

			if ( '' === $row['first_name'] || '' === $row['last_name'] ) {
				$action = 'skip';
				$notes[] = __( 'Missing first or last name.', 'strong-members-directory' );
			}

			foreach ( $keys as $key ) {
				if ( isset( $seen_keys[ $key ] ) ) {
					$action = 'skip';
					$notes[] = sprintf(
						/* translators: %d: row number */
						__( 'Duplicate of row %d in this file.', 'strong-members-directory' ),
						(int) $seen_keys[ $key ]
					);
					++$preview['summary']['duplicate_rows'];
					break;
				}
			}

			if ( 'skip' !== $action && $match['id'] ) {
				$action      = 'update';
				$match_label = $match['label'];
			} elseif ( 'skip' !== $action ) {
				$match_label = __( 'No existing match', 'strong-members-directory' );
			}

			foreach ( $keys as $key ) {
				if ( ! isset( $seen_keys[ $key ] ) ) {
					$seen_keys[ $key ] = (int) $row['row_number'];
				}
			}

			if ( 'create' === $action ) {
				++$preview['summary']['created'];
			} elseif ( 'update' === $action ) {
				++$preview['summary']['updated'];
			} else {
				++$preview['summary']['skipped'];
			}

			$preview['rows'][] = array(
				'row_number'   => (int) $row['row_number'],
				'name'         => trim( $row['first_name'] . ' ' . $row['last_name'] ),
				'email'        => $row['email'],
				'action'       => $action,
				'action_label' => self::action_label( $action ),
				'match_label'  => $match_label,
				'notes'        => implode( ' ', array_unique( $notes ) ),
				'data'         => $row,
			);
		}

		foreach ( $preview['rows'] as $preview_row ) {
			if ( 'skip' === $preview_row['action'] && '' !== $preview_row['notes'] ) {
				$preview['errors'][] = sprintf(
					/* translators: 1: row number, 2: reason */
					__( 'Row %1$d: %2$s', 'strong-members-directory' ),
					(int) $preview_row['row_number'],
					$preview_row['notes']
				);
			}
		}

		return $preview;
	}

	/**
	 * Detect existing member match details.
	 *
	 * @param array<string, string> $row Row data.
	 * @return array{id:int,label:string}
	 */
	private static function detect_existing_member( $row ) {
		if ( '' !== $row['email'] ) {
			$existing_id = SMD_Member_Post_Type::find_existing_member( $row['email'], '', '' );
			if ( $existing_id ) {
				return array(
					'id'    => (int) $existing_id,
					'label' => __( 'Matched by email', 'strong-members-directory' ),
				);
			}
		}

		$existing_id = SMD_Member_Post_Type::find_existing_member( '', $row['first_name'], $row['last_name'] );

		if ( $existing_id ) {
			return array(
				'id'    => (int) $existing_id,
				'label' => __( 'Matched by full name', 'strong-members-directory' ),
			);
		}

		return array(
			'id'    => 0,
			'label' => '',
		);
	}

	/**
	 * Build duplicate detection keys for a row.
	 *
	 * @param array<string, string> $row Row data.
	 * @return string[]
	 */
	private static function row_duplicate_keys( $row ) {
		$keys = array();

		if ( '' !== $row['email'] ) {
			$keys[] = 'email:' . strtolower( $row['email'] );
		}

		$name = trim( strtolower( $row['first_name'] . ' ' . $row['last_name'] ) );
		if ( '' !== $name ) {
			$keys[] = 'name:' . $name;
		}

		return $keys;
	}

	/**
	 * Map a parsed row to normalized data.
	 *
	 * @param array $headers Headers.
	 * @param array $row Row values.
	 * @param int   $row_number Source row number.
	 * @return array<string, string>
	 */
	private static function map_row( $headers, $row, $row_number ) {
		$mapped = array();

		foreach ( $headers as $index => $header ) {
			$mapped[ $header ] = isset( $row[ $index ] ) ? trim( (string) $row[ $index ] ) : '';
		}

		return array(
			'row_number'        => (string) $row_number,
			'first_name'        => sanitize_text_field( $mapped['first_name'] ?? '' ),
			'last_name'         => sanitize_text_field( $mapped['last_name'] ?? '' ),
			'email'             => sanitize_email( $mapped['email'] ?? '' ),
			'occupation'        => sanitize_text_field( $mapped['occupation'] ?? '' ),
			'member_type'       => sanitize_text_field( $mapped['member_type'] ?? '' ),
			'phone'             => sanitize_text_field( $mapped['phone'] ?? '' ),
			'website'           => esc_url_raw( $mapped['website'] ?? '' ),
			'linkedin'          => esc_url_raw( $mapped['linkedin'] ?? '' ),
			'bio'               => wp_kses_post( $mapped['bio'] ?? '' ),
			'profile_image_url' => esc_url_raw( $mapped['profile_image_url'] ?? '' ),
		);
	}

	/**
	 * Normalize header names.
	 *
	 * @param string $header Raw header.
	 * @return string
	 */
	private static function normalize_header( $header ) {
		$header = str_replace( "\xEF\xBB\xBF", '', (string) $header );
		return strtolower( trim( preg_replace( '/[^a-z0-9]+/', '_', trim( $header ) ), '_' ) );
	}

	/**
	 * Create or update a member record.
	 *
	 * @param array<string, string> $data Member data.
	 * @return string|WP_Error
	 */
	private static function upsert_member( $data ) {
		$existing_id = SMD_Member_Post_Type::find_existing_member( $data['email'], $data['first_name'], $data['last_name'] );
		$postarr     = array(
			'post_type'    => SMD_Member_Post_Type::POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => trim( $data['first_name'] . ' ' . $data['last_name'] ),
			'post_content' => $data['bio'],
		);

		if ( $existing_id ) {
			$postarr['ID'] = $existing_id;
			$post_id       = wp_update_post( $postarr, true );
			$action        = 'updated';
		} else {
			$post_id = wp_insert_post( $postarr, true );
			$action  = 'created';
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		SMD_Member_Post_Type::update_member_meta(
			$post_id,
			$data['first_name'],
			$data['last_name'],
			$data['email'],
			$data['occupation'],
			array(
				'member_type' => $data['member_type'],
				'phone'       => $data['phone'],
				'website'     => $data['website'],
				'linkedin'    => $data['linkedin'],
			)
		);

		if ( ! empty( $data['profile_image_url'] ) ) {
			self::maybe_attach_remote_image( $post_id, $data['profile_image_url'] );
		}

		return $action;
	}

	/**
	 * Set featured image from remote URL.
	 *
	 * @param int    $post_id Member post ID.
	 * @param string $url Image URL.
	 */
	private static function maybe_attach_remote_image( $post_id, $url ) {
		if ( has_post_thumbnail( $post_id ) ) {
			return;
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_sideload_image( $url, $post_id, null, 'id' );

		if ( ! is_wp_error( $attachment_id ) ) {
			set_post_thumbnail( $post_id, (int) $attachment_id );
		}
	}

	/**
	 * Create an empty report payload with one error.
	 *
	 * @param string $error Error message.
	 * @return array<string, mixed>
	 */
	private static function empty_report( $error ) {
		return array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 1,
			'rows'    => array(),
			'errors'  => array( $error ),
		);
	}

	/**
	 * Get a report from the request.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_report_from_request() {
		$raw = isset( $_GET['smd_report'] ) ? json_decode( rawurldecode( wp_unslash( $_GET['smd_report'] ) ), true ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Human-readable label for row action.
	 *
	 * @param string $action Action key.
	 * @return string
	 */
	private static function action_label( $action ) {
		if ( 'update' === $action ) {
			return __( 'Update existing', 'strong-members-directory' );
		}

		if ( 'skip' === $action ) {
			return __( 'Skip', 'strong-members-directory' );
		}

		return __( 'Create', 'strong-members-directory' );
	}

	/**
	 * Build redirect URL with report.
	 *
	 * @param array $report Report data.
	 * @return string
	 */
	private static function report_url( $report ) {
		return add_query_arg(
			array(
				'post_type'  => SMD_Member_Post_Type::POST_TYPE,
				'page'       => 'smd-import-members',
				'smd_report' => rawurlencode( wp_json_encode( $report ) ),
			),
			admin_url( 'edit.php' )
		);
	}
}
