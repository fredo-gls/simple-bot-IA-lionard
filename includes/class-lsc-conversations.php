<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LSC_Conversations {

	const DB_VERSION = '5';

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
			rdv_summary LONGTEXT NULL,
			rdv_summary_generated_at DATETIME NULL DEFAULT NULL,
			rdv_notified_at DATETIME NULL DEFAULT NULL,
			form_source VARCHAR(50) NOT NULL DEFAULT '',
			form_data LONGTEXT NULL,
			visitor_ip VARCHAR(64) NOT NULL DEFAULT '',
			visitor_email VARCHAR(255) NOT NULL DEFAULT '',
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

	public static function set_visitor_email( string $session_key, string $email ): void {
		global $wpdb;

		$session_id = self::ensure_session( $session_key );
		if ( $session_id <= 0 ) {
			return;
		}

		$wpdb->update(
			self::session_table_name(),
			array( 'visitor_email' => sanitize_email( $email ) ),
			array( 'id' => $session_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	public static function log_cta_event( string $session_key, array $data = array() ): void {
		global $wpdb;

		$cta_type = sanitize_key( (string) ( $data['cta_type'] ?? 'autre' ) );
		$cta_url  = esc_url_raw( (string) ( $data['cta_url']  ?? '' ) );
		$page_url = esc_url_raw( (string) ( $data['page_url'] ?? '' ) );

		$wpdb->insert(
			self::cta_events_table_name(),
			array(
				'session_key' => sanitize_text_field( $session_key ),
				'cta_type'    => $cta_type,
				'cta_url'     => $cta_url,
				'page_url'    => $page_url,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	public static function get_cta_stats( array $args = array() ): array {
		global $wpdb;

		$table  = self::cta_events_table_name();
		$where  = array( '1=1' );
		$params = array();

		$date_from = sanitize_text_field( (string) ( $args['date_from'] ?? '' ) );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $date_from . ' 00:00:00';
		}

		$date_to = sanitize_text_field( (string) ( $args['date_to'] ?? '' ) );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = $date_to . ' 23:59:59';
		}

		// Always include a typed sentinel so $wpdb->prepare() is always used,
		// preventing unprepared query paths even if $params is otherwise empty.
		$where[]  = '%d = 1';
		$params[] = 1;

		$sql = 'SELECT cta_type, COUNT(*) AS total, MAX(created_at) AS last_click'
			. " FROM {$table} WHERE " . implode( ' AND ', $where )
			. ' GROUP BY cta_type ORDER BY total DESC';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
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

		self::generate_rdv_summary( $session_key );
		self::send_rdv_notification( $session_key );
	}

	public static function generate_rdv_summary( string $session_key ): void {
		global $wpdb;

		$session = self::get_session_by_key( $session_key );
		if ( ! is_array( $session ) || empty( $session['rdv_submitted'] ) ) {
			return;
		}

		if ( ! class_exists( 'LSC_Plugin' ) ) {
			return;
		}

		$settings = LSC_Plugin::get_settings();
		$api_key  = trim( (string) ( $settings['openai_api_key'] ?? '' ) );
		if ( '' === $api_key ) {
			return;
		}

		$messages     = self::get_messages( $session_key );
		$summary_text = self::request_rdv_summary_from_openai( $session, $messages, $settings, $api_key );
		if ( '' === $summary_text ) {
			return;
		}

		$wpdb->update(
			self::session_table_name(),
			array(
				'rdv_summary'              => $summary_text,
				'rdv_summary_generated_at' => current_time( 'mysql' ),
				'updated_at'               => current_time( 'mysql' ),
			),
			array( 'id' => (int) $session['id'] ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	private static function request_rdv_summary_from_openai( array $session, array $messages, array $settings, string $api_key ): string {
		$conversation = self::build_summary_conversation_text( $messages );
		if ( '' === $conversation ) {
			return '';
		}

		$form_data = json_decode( (string) ( $session['form_data'] ?? '' ), true );
		$form_text = self::build_summary_form_text( is_array( $form_data ) ? $form_data : array() );

		$instructions = "Tu rediges un resume de qualification pour une conseillere de dettes.ca.\n"
			. "Objectif: resumer un rendez-vous soumis apres une conversation chatbot.\n"
			. "Le texte peut contenir des fautes d'orthographe: corrige-les mentalement et infere prudemment.\n"
			. "N'invente jamais une information absente.\n"
			. "Si une information n'est pas claire, ne la mentionne pas.\n"
			. "Format obligatoire:\n"
			. "- 5 a 8 puces maximum\n"
			. "- Chaque ligne commence par '- '\n"
			. "- Rubriques prioritaires: Situation, Dettes, Montants, Paiements, Pression/Stress, Coordonnees utiles, Prochaine etape\n"
			. "- Style sobre, professionnel, concis\n"
			. "- Pas de conclusion marketing\n"
			. "- Pas de JSON, pas de HTML";

		$input = "Session: " . sanitize_text_field( (string) ( $session['session_key'] ?? '' ) ) . "\n"
			. "Type RDV: " . sanitize_text_field( (string) ( $session['rdv_type'] ?? '' ) ) . "\n"
			. "Source formulaire: " . sanitize_text_field( (string) ( $session['form_source'] ?? '' ) ) . "\n"
			. "Page: " . esc_url_raw( (string) ( $session['last_page_url'] ?? '' ) ) . "\n";

		if ( '' !== $form_text ) {
			$input .= "\nDonnees formulaire:\n" . $form_text . "\n";
		}

		$input .= "\nConversation complete:\n" . $conversation;

		$payload = array(
			'model'             => sanitize_text_field( (string) ( $settings['model'] ?? 'gpt-4o-mini' ) ),
			'instructions'      => $instructions,
			'input'             => $input,
			'max_output_tokens' => 280,
			'temperature'       => 0.2,
			'store'             => false,
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/responses',
			array(
				'timeout' => 35,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			return '';
		}

		$text = trim( self::extract_output_text( $data ) );
		if ( '' === $text ) {
			return '';
		}

		return sanitize_textarea_field( $text );
	}

	private static function build_summary_conversation_text( array $messages ): string {
		$lines = array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$content = sanitize_textarea_field( (string) ( $message['content'] ?? '' ) );
			$content = trim( preg_replace( '/\s+/', ' ', $content ) );
			if ( '' === $content ) {
				continue;
			}

			$role    = 'assistant' === ( $message['role'] ?? '' ) ? 'Assistant' : 'User';
			$lines[] = $role . ': ' . $content;
		}

		return implode( "\n", $lines );
	}

	private static function build_summary_form_text( array $form_data ): string {
		$lines = array();

		foreach ( $form_data as $key => $value ) {
			$label = sanitize_text_field( (string) $key );
			if ( '' === $label ) {
				continue;
			}

			$rendered = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
			$rendered = sanitize_textarea_field( $rendered );
			$rendered = trim( preg_replace( '/\s+/', ' ', $rendered ) );
			if ( '' === $rendered ) {
				continue;
			}

			$lines[] = $label . ': ' . $rendered;
		}

		return implode( "\n", $lines );
	}

	private static function extract_output_text( array $data ): string {
		if ( isset( $data['output_text'] ) && is_string( $data['output_text'] ) ) {
			return $data['output_text'];
		}

		$text   = '';
		$output = $data['output'] ?? array();
		if ( ! is_array( $output ) ) {
			return '';
		}

		foreach ( $output as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['content'] ) || ! is_array( $item['content'] ) ) {
				continue;
			}

			foreach ( $item['content'] as $part ) {
				if ( is_array( $part ) && ( $part['type'] ?? '' ) === 'output_text' && isset( $part['text'] ) ) {
					$text .= (string) $part['text'];
				}
			}
		}

		return $text;
	}

	public static function send_rdv_notification( string $session_key ): void {
		$session = self::get_session_by_key( $session_key );
		if ( ! is_array( $session ) || empty( $session['rdv_submitted'] ) || ! empty( $session['rdv_notified_at'] ) ) {
			return;
		}

		if ( ! class_exists( 'LSC_Plugin' ) ) {
			return;
		}

		$settings = LSC_Plugin::get_settings();
		if ( empty( $settings['rdv_email_notifications'] ) || '1' !== (string) $settings['rdv_email_notifications'] ) {
			return;
		}

		$recipients = self::parse_notification_recipients( (string) ( $settings['rdv_notification_emails'] ?? '' ) );
		if ( empty( $recipients ) ) {
			return;
		}

		$subject = sprintf(
			'[%s] Nouveau rendez-vous %s',
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			! empty( $session['rdv_type'] ) ? sanitize_text_field( (string) $session['rdv_type'] ) : 'converti'
		);

		$message = self::build_rdv_notification_email_body( $session_key, $session );
		if ( '' === trim( $message ) ) {
			return;
		}

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$sent    = wp_mail( $recipients, $subject, $message, $headers );
		if ( ! $sent ) {
			return;
		}

		global $wpdb;
		$wpdb->update(
			self::session_table_name(),
			array(
				'rdv_notified_at' => current_time( 'mysql' ),
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'id' => (int) $session['id'] ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	private static function parse_notification_recipients( string $value ): array {
		$emails = preg_split( '/[\r\n,;]+/', $value );
		$emails = is_array( $emails ) ? $emails : array();
		$clean  = array();

		foreach ( $emails as $email ) {
			$email = sanitize_email( trim( (string) $email ) );
			if ( '' !== $email && is_email( $email ) ) {
				$clean[] = $email;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	private static function build_rdv_notification_email_body( string $session_key, array $session ): string {
		$form_data = json_decode( (string) ( $session['form_data'] ?? '' ), true );
		$form_data = is_array( $form_data ) ? $form_data : array();

		$lines   = array();
		$lines[] = 'Nouveau rendez-vous converti depuis Lionard';
		$lines[] = '';
		$lines[] = 'Session: ' . sanitize_text_field( (string) ( $session['session_key'] ?? '' ) );
		$lines[] = 'Type RDV: ' . sanitize_text_field( (string) ( $session['rdv_type'] ?? '' ) );
		$lines[] = 'Soumis le: ' . sanitize_text_field( (string) ( $session['rdv_submitted_at'] ?? '' ) );
		$lines[] = 'Page source: ' . esc_url_raw( (string) ( $session['last_page_url'] ?? '' ) );
		$lines[] = 'URL RDV: ' . esc_url_raw( (string) ( $session['rdv_url'] ?? '' ) );
		$lines[] = 'E-mail visiteur: ' . sanitize_email( (string) ( $session['visitor_email'] ?? '' ) );
		$lines[] = '';
		$lines[] = 'Coordonnees / donnees formulaire';
		$lines[] = '--------------------------------';

		if ( empty( $form_data ) ) {
			$lines[] = 'Aucune donnee formulaire disponible.';
		} else {
			foreach ( $form_data as $key => $value ) {
				$rendered = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
				$rendered = trim( sanitize_textarea_field( $rendered ) );
				if ( '' === $rendered ) {
					continue;
				}
				$lines[] = sanitize_text_field( (string) $key ) . ': ' . $rendered;
			}
		}

		$lines[] = '';
		$lines[] = 'Resume OpenAI';
		$lines[] = '-------------';
		$lines[] = '' !== trim( (string) ( $session['rdv_summary'] ?? '' ) )
			? trim( sanitize_textarea_field( (string) $session['rdv_summary'] ) )
			: 'Resume indisponible.';

		return implode( "\n", $lines );
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
