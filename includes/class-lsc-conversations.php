<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LSC_Conversations {

	const DB_VERSION = '2';

	public static function session_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'lsc_sessions';
	}

	public static function message_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'lsc_messages';
	}

	public static function cta_events_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'lsc_cta_events';
	}

	public static function create_tables() {
		if ( get_option( 'lsc_conversations_db_version' ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		$sessions_table    = self::session_table_name();
		$messages_table    = self::message_table_name();
		$cta_events_table  = self::cta_events_table_name();
		$charset           = $wpdb->get_charset_collate();

		$sql_sessions = "CREATE TABLE {$sessions_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_key VARCHAR(64) NOT NULL,
			status VARCHAR(24) NOT NULL DEFAULT 'conversation',
			rdv_type VARCHAR(24) NOT NULL DEFAULT '',
			rdv_url VARCHAR(2083) NOT NULL DEFAULT '',
			rdv_submitted TINYINT(1) NOT NULL DEFAULT 0,
			rdv_clicked_at DATETIME NULL DEFAULT NULL,
			rdv_submitted_at DATETIME NULL DEFAULT NULL,
			form_source VARCHAR(50) NOT NULL DEFAULT '',
			form_data LONGTEXT NULL,
			visitor_ip VARCHAR(64) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			first_page_url VARCHAR(2083) NOT NULL DEFAULT '',
			last_page_url VARCHAR(2083) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_session_key (session_key),
			KEY idx_status (status),
			KEY idx_rdv_submitted (rdv_submitted),
			KEY idx_updated_at (updated_at)
		) {$charset};";

		$sql_messages = "CREATE TABLE {$messages_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id BIGINT UNSIGNED NOT NULL,
			role VARCHAR(20) NOT NULL DEFAULT '',
			content LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY idx_session_id (session_id),
			KEY idx_created_at (created_at)
		) {$charset};";

		$sql_cta_events = "CREATE TABLE {$cta_events_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_key VARCHAR(64) NOT NULL DEFAULT '',
			cta_type VARCHAR(32) NOT NULL DEFAULT 'autre',
			cta_url VARCHAR(2083) NOT NULL DEFAULT '',
			page_url VARCHAR(2083) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY idx_cta_type (cta_type),
			KEY idx_created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_sessions );
		dbDelta( $sql_messages );
		dbDelta( $sql_cta_events );
		update_option( 'lsc_conversations_db_version', self::DB_VERSION );
	}

	public static function ensure_session( string $session_key, array $context = array() ): int {
		global $wpdb;

		$session_key = sanitize_text_field( $session_key );
		if ( '' === $session_key ) {
			return 0;
		}

		$table   = self::session_table_name();
		$session = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE session_key = %s LIMIT 1",
				$session_key
			),
			ARRAY_A
		);

		$now = current_time( 'mysql' );

		if ( $session ) {
			$update = array(
				'updated_at'    => $now,
				'last_page_url' => esc_url_raw( (string) ( $context['page_url'] ?? $session['last_page_url'] ) ),
			);
			$formats = array( '%s', '%s' );

			if ( ! empty( $context['ip'] ) && empty( $session['visitor_ip'] ) ) {
				$update['visitor_ip'] = sanitize_text_field( (string) $context['ip'] );
				$formats[]            = '%s';
			}

			if ( ! empty( $context['user_agent'] ) && empty( $session['user_agent'] ) ) {
				$update['user_agent'] = sanitize_text_field( (string) $context['user_agent'] );
				$formats[]            = '%s';
			}

			$wpdb->update(
				$table,
				$update,
				array( 'id' => (int) $session['id'] ),
				$formats,
				array( '%d' )
			);

			return (int) $session['id'];
		}

		$wpdb->insert(
			$table,
			array(
				'session_key'    => $session_key,
				'status'         => 'conversation',
				'rdv_type'       => '',
				'rdv_url'        => '',
				'rdv_submitted'  => 0,
				'form_source'    => '',
				'form_data'      => '',
				'visitor_ip'     => sanitize_text_field( (string) ( $context['ip'] ?? '' ) ),
				'user_agent'     => sanitize_text_field( (string) ( $context['user_agent'] ?? '' ) ),
				'first_page_url' => esc_url_raw( (string) ( $context['page_url'] ?? '' ) ),
				'last_page_url'  => esc_url_raw( (string) ( $context['page_url'] ?? '' ) ),
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public static function log_message( string $session_key, string $role, string $content, array $context = array() ): void {
		global $wpdb;

		$session_id = self::ensure_session( $session_key, $context );
		if ( $session_id <= 0 ) {
			return;
		}

		$content = trim( $content );
		if ( '' === $content ) {
			return;
		}

		$wpdb->insert(
			self::message_table_name(),
			array(
				'session_id' => $session_id,
				'role'       => sanitize_text_field( $role ),
				'content'    => sanitize_textarea_field( $content ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}

	public static function mark_rdv_click( string $session_key, array $data = array() ): void {
		global $wpdb;

		$session_id = self::ensure_session( $session_key, $data );
		if ( $session_id <= 0 ) {
			return;
		}

		$rdv_url = esc_url_raw( (string) ( $data['rdv_url'] ?? '' ) );
		$status  = 'rdv_clicked';
		$type    = sanitize_text_field( (string) ( $data['rdv_type'] ?? '' ) );

		$wpdb->update(
			self::session_table_name(),
			array(
				'status'         => $status,
				'rdv_type'       => $type,
				'rdv_url'        => $rdv_url,
				'rdv_clicked_at' => current_time( 'mysql' ),
				'updated_at'     => current_time( 'mysql' ),
				'last_page_url'  => esc_url_raw( (string) ( $data['page_url'] ?? '' ) ),
			),
			array( 'id' => $session_id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	public static function mark_rdv_submission( string $session_key, array $payload = array() ): void {
		global $wpdb;

		$session_id = self::ensure_session( $session_key, $payload );
		if ( $session_id <= 0 ) {
			return;
		}

		$form_data = wp_json_encode( is_array( $payload['form_data'] ?? null ) ? $payload['form_data'] : array() );
		$wpdb->update(
			self::session_table_name(),
			array(
				'status'           => 'rdv_submitted',
				'rdv_submitted'    => 1,
				'rdv_type'         => sanitize_text_field( (string) ( $payload['rdv_type'] ?? '' ) ),
				'rdv_url'          => esc_url_raw( (string) ( $payload['rdv_url'] ?? '' ) ),
				'form_source'      => sanitize_text_field( (string) ( $payload['form_source'] ?? 'formlift' ) ),
				'form_data'        => $form_data ?: '',
				'rdv_submitted_at' => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
				'last_page_url'    => esc_url_raw( (string) ( $payload['page_url'] ?? '' ) ),
			),
			array( 'id' => $session_id ),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	public static function get_sessions( array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'type'      => 'conversations',
				'limit'     => 100,
				'search'    => '',
				'status'    => '',
				'rdv_type'  => '',
				'date_from' => '',
				'date_to'   => '',
			)
		);

		$limit          = max( 1, min( 500, absint( $args['limit'] ) ) );
		$table          = self::session_table_name();
		$messages_table = self::message_table_name();
		$where          = array( '1=1' );
		$params         = array();

		if ( 'rdv' === $args['type'] ) {
			$where[] = 'rdv_submitted = 1';
		} else {
			$where[] = 'rdv_submitted = 0';
		}

		$status = sanitize_text_field( (string) $args['status'] );
		if ( '' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$rdv_type = sanitize_text_field( (string) $args['rdv_type'] );
		if ( '' !== $rdv_type ) {
			$where[]  = 'rdv_type = %s';
			$params[] = $rdv_type;
		}

		$date_from = sanitize_text_field( (string) $args['date_from'] );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
			$where[]  = 'updated_at >= %s';
			$params[] = $date_from . ' 00:00:00';
		}

		$date_to = sanitize_text_field( (string) $args['date_to'] );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			$where[]  = 'updated_at <= %s';
			$params[] = $date_to . ' 23:59:59';
		}

		$search = trim( sanitize_text_field( (string) $args['search'] ) );
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = "(
				session_key LIKE %s
				OR status LIKE %s
				OR rdv_type LIKE %s
				OR first_page_url LIKE %s
				OR last_page_url LIKE %s
				OR rdv_url LIKE %s
				OR form_data LIKE %s
				OR EXISTS (
					SELECT 1
					FROM {$messages_table} m
					WHERE m.session_id = {$table}.id
					AND m.content LIKE %s
				)
			)";
			for ( $i = 0; $i < 8; $i++ ) {
				$params[] = $like;
			}
		}

		$sql      = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC LIMIT %d';
		$params[] = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$query = $wpdb->prepare( $sql, $params );
		$rows  = $wpdb->get_results( $query, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	public static function get_session_by_key( string $session_key ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . self::session_table_name() . " WHERE session_key = %s LIMIT 1",
				sanitize_text_field( $session_key )
			),
			ARRAY_A
		);
	}

	public static function get_messages( string $session_key ): array {
		global $wpdb;

		$session = self::get_session_by_key( $session_key );
		if ( ! $session ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . self::message_table_name() . " WHERE session_id = %d ORDER BY id ASC",
				(int) $session['id']
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
