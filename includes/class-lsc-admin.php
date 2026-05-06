<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LSC_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_knowledge_actions' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'Lionard Chat', 'lionard-simple-chat' ),
			__( 'Lionard Chat', 'lionard-simple-chat' ),
			'manage_options',
			'lionard-chat',
			array( $this, 'render_dashboard' ),
			'dashicons-format-chat',
			58
		);

		add_submenu_page( 'lionard-chat', __( 'Tableau de bord', 'lionard-simple-chat' ), __( 'Tableau de bord', 'lionard-simple-chat' ), 'manage_options', 'lionard-chat', array( $this, 'render_dashboard' ) );
		add_submenu_page( 'lionard-chat', __( 'Reglages', 'lionard-simple-chat' ), __( 'Reglages', 'lionard-simple-chat' ), 'manage_options', 'lionard-chat-reglages', array( $this, 'render_reglages' ) );
		add_submenu_page( 'lionard-chat', __( 'Prompts', 'lionard-simple-chat' ), __( 'Prompts', 'lionard-simple-chat' ), 'manage_options', 'lionard-chat-prompts', array( $this, 'render_prompts' ) );
		add_submenu_page( 'lionard-chat', __( 'Connaissances', 'lionard-simple-chat' ), __( 'Connaissances', 'lionard-simple-chat' ), 'manage_options', 'lionard-chat-connaissances', array( $this, 'render_connaissances' ) );
		add_submenu_page( 'lionard-chat', __( 'Conversations', 'lionard-simple-chat' ), __( 'Conversations', 'lionard-simple-chat' ), 'manage_options', 'lionard-chat-conversations', array( $this, 'render_conversations' ) );
		add_submenu_page( 'lionard-chat', __( 'Rendez-vous', 'lionard-simple-chat' ), __( 'Rendez-vous', 'lionard-simple-chat' ), 'manage_options', 'lionard-chat-rdv', array( $this, 'render_rdv' ) );
		add_submenu_page( 'lionard-chat', __( 'Apparence', 'lionard-simple-chat' ), __( 'Apparence', 'lionard-simple-chat' ), 'manage_options', 'lionard-chat-apparence', array( $this, 'render_apparence' ) );
		add_submenu_page( 'lionard-chat', __( 'Statistiques', 'lionard-simple-chat' ), __( 'Statistiques', 'lionard-simple-chat' ), 'manage_options', 'lionard-chat-statistiques', array( $this, 'render_statistiques' ) );
		add_submenu_page( 'lionard-chat', __( 'Outils', 'lionard-simple-chat' ), __( 'Outils', 'lionard-simple-chat' ), 'manage_options', 'lionard-chat-outils', array( $this, 'render_outils' ) );
	}

	public function register_settings() {
		register_setting(
			'lsc_settings_group',
			LSC_Plugin::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => LSC_Plugin::defaults(),
			)
		);
	}

	public function sanitize_settings( $raw ): array {
		$raw      = is_array( $raw ) ? $raw : array();
		$previous = LSC_Plugin::get_settings();
		$defaults = LSC_Plugin::defaults();

		$out = array();

		// Enabled — checkbox absent == not submitted, not unchecked
		if ( array_key_exists( 'enabled', $raw ) ) {
			$out['enabled'] = ! empty( $raw['enabled'] ) ? '1' : '0';
		} else {
			$out['enabled'] = $previous['enabled'] ?? $defaults['enabled'];
		}

		// API key
		if ( ! empty( $raw['clear_openai_api_key'] ) ) {
			$out['openai_api_key'] = '';
		} elseif ( array_key_exists( 'openai_api_key', $raw ) && '' !== sanitize_text_field( wp_unslash( (string) $raw['openai_api_key'] ) ) ) {
			$out['openai_api_key'] = sanitize_text_field( wp_unslash( (string) $raw['openai_api_key'] ) );
		} else {
			$out['openai_api_key'] = (string) ( $previous['openai_api_key'] ?? '' );
		}

		// Scalar fields — preserve previous when not submitted
		$scalar_fields = array(
			'model'             => 'text',
			'temperature'       => 'float',
			'max_output_tokens' => 'int',
			'rate_limit_count'  => 'int',
			'rate_limit_window' => 'int',
			'launcher_label'    => 'text',
			'panel_title'       => 'text',
			'panel_subtitle'    => 'text',
		);

		foreach ( $scalar_fields as $field => $type ) {
			if ( ! array_key_exists( $field, $raw ) ) {
				$out[ $field ] = $previous[ $field ] ?? $defaults[ $field ];
				continue;
			}
			switch ( $type ) {
				case 'float':
					$val = str_replace( ',', '.', (string) $raw[ $field ] );
					$out[ $field ] = (string) max( 0, min( 2, (float) $val ) );
					break;
				case 'int':
					$limits = array(
						'max_output_tokens' => array( 150, 1500 ),
						'rate_limit_count'  => array( 1, 100 ),
						'rate_limit_window' => array( 60, 86400 ),
					);
					$min = $limits[ $field ][0] ?? 1;
					$max = $limits[ $field ][1] ?? 9999;
					$out[ $field ] = (string) max( $min, min( $max, absint( $raw[ $field ] ) ) );
					break;
				default:
					$out[ $field ] = sanitize_text_field( wp_unslash( (string) $raw[ $field ] ) ) ?: ( $defaults[ $field ] ?? '' );
			}
		}

		// Colors
		foreach ( array( 'primary_color', 'accent_color' ) as $key_name ) {
			if ( array_key_exists( $key_name, $raw ) ) {
				$out[ $key_name ] = sanitize_hex_color( $raw[ $key_name ] ) ?: $defaults[ $key_name ];
			} else {
				$out[ $key_name ] = $previous[ $key_name ] ?? $defaults[ $key_name ];
			}
		}

		// Greeting
		if ( array_key_exists( 'greeting', $raw ) ) {
			$out['greeting'] = sanitize_textarea_field( wp_unslash( (string) $raw['greeting'] ) );
		} else {
			$out['greeting'] = $previous['greeting'] ?? $defaults['greeting'];
		}

		// Prompt
		if ( array_key_exists( 'prompt', $raw ) ) {
			$out['prompt'] = sanitize_textarea_field( wp_unslash( (string) $raw['prompt'] ) );
			if ( '' === trim( $out['prompt'] ) ) {
				$out['prompt'] = $defaults['prompt'];
			}
		} else {
			$out['prompt'] = $previous['prompt'] ?? $defaults['prompt'];
		}

		// RDV URLs
		foreach ( array( 'rdv_particulier_url', 'rdv_entreprise_url' ) as $key_name ) {
			if ( array_key_exists( $key_name, $raw ) ) {
				$out[ $key_name ] = esc_url_raw( wp_unslash( (string) $raw[ $key_name ] ) );
			} else {
				$out[ $key_name ] = $previous[ $key_name ] ?? $defaults[ $key_name ];
			}
		}

		// RDV behavior
		foreach ( array( 'rdv_close_chat', 'rdv_keep_closed' ) as $key_name ) {
			if ( array_key_exists( $key_name, $raw ) ) {
				$out[ $key_name ] = ! empty( $raw[ $key_name ] ) ? '1' : '0';
			} else {
				$out[ $key_name ] = $previous[ $key_name ] ?? $defaults[ $key_name ];
			}
		}

		// Site search
		if ( array_key_exists( 'site_search', $raw ) ) {
			$out['site_search'] = ! empty( $raw['site_search'] ) ? '1' : '0';
		} else {
			$out['site_search'] = $previous['site_search'] ?? $defaults['site_search'];
		}

		// Allowed CTA hosts
		if ( array_key_exists( 'allowed_cta_hosts', $raw ) ) {
			$out['allowed_cta_hosts'] = $this->sanitize_hosts( (string) $raw['allowed_cta_hosts'] );
		} else {
			$out['allowed_cta_hosts'] = $previous['allowed_cta_hosts'] ?? $defaults['allowed_cta_hosts'];
		}

		// Email gate
		if ( array_key_exists( 'email_gate', $raw ) ) {
			$out['email_gate'] = ! empty( $raw['email_gate'] ) ? '1' : '0';
		} else {
			$out['email_gate'] = $previous['email_gate'] ?? $defaults['email_gate'];
		}

		if ( array_key_exists( 'email_gate_title', $raw ) ) {
			$out['email_gate_title'] = sanitize_textarea_field( wp_unslash( (string) $raw['email_gate_title'] ) );
			if ( '' === trim( $out['email_gate_title'] ) ) {
				$out['email_gate_title'] = $defaults['email_gate_title'];
			}
		} else {
			$out['email_gate_title'] = $previous['email_gate_title'] ?? $defaults['email_gate_title'];
		}

		if ( array_key_exists( 'rdv_email_notifications', $raw ) ) {
			$out['rdv_email_notifications'] = ! empty( $raw['rdv_email_notifications'] ) ? '1' : '0';
		} else {
			$out['rdv_email_notifications'] = $previous['rdv_email_notifications'] ?? $defaults['rdv_email_notifications'];
		}

		if ( array_key_exists( 'rdv_notification_emails', $raw ) ) {
			$out['rdv_notification_emails'] = $this->sanitize_emails( (string) $raw['rdv_notification_emails'] );
		} else {
			$out['rdv_notification_emails'] = $previous['rdv_notification_emails'] ?? $defaults['rdv_notification_emails'];
		}

		// Avatar attachment ID
		if ( array_key_exists( 'avatar_attachment_id', $raw ) ) {
			$out['avatar_attachment_id'] = (string) absint( $raw['avatar_attachment_id'] );
		} else {
			$out['avatar_attachment_id'] = $previous['avatar_attachment_id'] ?? $defaults['avatar_attachment_id'];
		}

		// Position desktop
		if ( array_key_exists( 'pos_desktop_side', $raw ) ) {
			$out['pos_desktop_side'] = in_array( $raw['pos_desktop_side'], array( 'left', 'right' ), true )
				? (string) $raw['pos_desktop_side'] : 'right';
		} else {
			$out['pos_desktop_side'] = $previous['pos_desktop_side'] ?? $defaults['pos_desktop_side'];
		}

		if ( array_key_exists( 'pos_desktop_bottom', $raw ) ) {
			$out['pos_desktop_bottom'] = (string) max( 0, min( 200, absint( $raw['pos_desktop_bottom'] ) ) );
		} else {
			$out['pos_desktop_bottom'] = $previous['pos_desktop_bottom'] ?? $defaults['pos_desktop_bottom'];
		}

		// Position mobile
		if ( array_key_exists( 'pos_mobile_side', $raw ) ) {
			$out['pos_mobile_side'] = in_array( $raw['pos_mobile_side'], array( 'left', 'center', 'right' ), true )
				? (string) $raw['pos_mobile_side'] : 'right';
		} else {
			$out['pos_mobile_side'] = $previous['pos_mobile_side'] ?? $defaults['pos_mobile_side'];
		}

		if ( array_key_exists( 'pos_mobile_bottom', $raw ) ) {
			$out['pos_mobile_bottom'] = (string) max( 0, min( 200, absint( $raw['pos_mobile_bottom'] ) ) );
		} else {
			$out['pos_mobile_bottom'] = $previous['pos_mobile_bottom'] ?? $defaults['pos_mobile_bottom'];
		}

		return $out;
	}

	private function sanitize_hosts( string $value ): string {
		$hosts = preg_split( '/[\r\n,]+/', wp_unslash( $value ) );
		$hosts = is_array( $hosts ) ? $hosts : array();
		$clean = array();
		foreach ( $hosts as $host ) {
			$host = strtolower( trim( (string) $host ) );
			$host = preg_replace( '/^https?:\/\//', '', $host );
			$host = preg_replace( '/\/.*$/', '', $host );
			$host = sanitize_text_field( $host );
			if ( '' !== $host ) {
				$clean[] = $host;
			}
		}
		return implode( "\n", array_values( array_unique( $clean ) ) );
	}

	private function sanitize_emails( string $value ): string {
		$emails = preg_split( '/[\r\n,;]+/', wp_unslash( $value ) );
		$emails = is_array( $emails ) ? $emails : array();
		$clean  = array();

		foreach ( $emails as $email ) {
			$email = sanitize_email( trim( (string) $email ) );
			if ( '' !== $email && is_email( $email ) ) {
				$clean[] = $email;
			}
		}

		return implode( "\n", array_values( array_unique( $clean ) ) );
	}

	private function get_filter_value( string $key ): string {
		return sanitize_text_field( wp_unslash( (string) ( $_GET[ $key ] ?? '' ) ) );
	}

	private function render_reset_filters_button( string $page ): void {
		?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page ) ); ?>" class="button">
			<?php esc_html_e( 'Reinitialiser', 'lionard-simple-chat' ); ?>
		</a>
		<?php
	}

	private function normalize_summary_text( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = preg_replace( '/\s+/', ' ', $text );
		return trim( (string) $text );
	}

	private function find_first_user_message( array $messages ): string {
		foreach ( $messages as $message ) {
			if ( 'user' !== ( $message['role'] ?? '' ) ) {
				continue;
			}
			$content = $this->normalize_summary_text( (string) ( $message['content'] ?? '' ) );
			if ( '' !== $content ) {
				return $content;
			}
		}

		return '';
	}

	private function extract_amounts_from_messages( array $messages ): array {
		$amounts = array();

		foreach ( $messages as $message ) {
			$content = (string) ( $message['content'] ?? '' );
			if ( preg_match_all( '/\b\d[\d\s.,]{0,12}\s*\$|\$\s*\d[\d\s.,]{0,12}\b/u', $content, $matches ) ) {
				foreach ( $matches[0] as $amount ) {
					$amount = trim( preg_replace( '/\s+/', ' ', (string) $amount ) );
					if ( '' !== $amount ) {
						$amounts[] = $amount;
					}
				}
			}
		}

		return array_values( array_unique( $amounts ) );
	}

	private function detect_debt_types( string $text ): array {
		$map = array(
			'cartes de credit' => array( 'carte', 'cartes', 'credit card', 'carte de credit' ),
			'marge de credit'  => array( 'marge de credit', 'marge' ),
			'pret auto'        => array( 'auto', 'vehicule', 'voiture' ),
			'impots'           => array( 'impot', 'revenu quebec', 'arc', 'cra' ),
			'pret etudiant'    => array( 'pret etudiant', 'etudiant' ),
			'dettes entreprise'=> array( 'entreprise', 'compagnie', 'commerce' ),
		);

		$found = array();
		foreach ( $map as $label => $keywords ) {
			foreach ( $keywords as $keyword ) {
				if ( false !== strpos( $text, $keyword ) ) {
					$found[] = $label;
					break;
				}
			}
		}

		return array_values( array_unique( $found ) );
	}

	private function extract_form_summary( array $form_data ): array {
		$summary = array();
		$field_map = array(
			'prenom'            => 'Prenom',
			'first_name'        => 'Prenom',
			'name_first'        => 'Prenom',
			'nom'               => 'Nom',
			'last_name'         => 'Nom',
			'name_last'         => 'Nom',
			'telephone'         => 'Telephone',
			'phone'             => 'Telephone',
			'tel'               => 'Telephone',
			'courriel'          => 'Courriel',
			'email'             => 'Courriel',
			'email_address'     => 'Courriel',
			'ville'             => 'Ville',
			'city'              => 'Ville',
		);

		foreach ( $form_data as $key => $value ) {
			$key_norm = strtolower( sanitize_key( (string) $key ) );
			if ( ! isset( $field_map[ $key_norm ] ) ) {
				continue;
			}

			$rendered = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
			$rendered = $this->normalize_summary_text( $rendered );
			if ( '' !== $rendered ) {
				$summary[ $field_map[ $key_norm ] ] = $rendered;
			}
		}

		return $summary;
	}

	private function build_conversation_summary( ?array $session, array $messages ): array {
		$summary      = array();
		$user_texts   = array();
		$assistant_cta = '';

		foreach ( $messages as $message ) {
			$content = $this->normalize_summary_text( (string) ( $message['content'] ?? '' ) );
			if ( '' === $content ) {
				continue;
			}

			if ( 'user' === ( $message['role'] ?? '' ) ) {
				$user_texts[] = $content;
			} elseif ( '' === $assistant_cta && false !== stripos( $content, 'rendez-vous' ) ) {
				$assistant_cta = $content;
			}
		}

		$user_blob_lower = strtolower( implode( ' ', $user_texts ) );
		$first_message   = $this->find_first_user_message( $messages );
		$amounts         = $this->extract_amounts_from_messages( $messages );
		$debt_types      = $this->detect_debt_types( $user_blob_lower );
		$form_data       = is_array( json_decode( (string) ( $session['form_data'] ?? '' ), true ) ) ? json_decode( (string) $session['form_data'], true ) : array();
		$form_summary    = $this->extract_form_summary( $form_data );

		if ( '' !== $first_message ) {
			$summary['Demande initiale'] = $first_message;
		}

		if ( ! empty( $debt_types ) ) {
			$summary['Type de dettes mentionne'] = implode( ', ', $debt_types );
		}

		if ( ! empty( $amounts ) ) {
			$summary['Montants mentionnes'] = implode( ', ', array_slice( $amounts, 0, 4 ) );
		}

		if ( false !== strpos( $user_blob_lower, 'retard' ) || false !== strpos( $user_blob_lower, 'en retard' ) ) {
			$summary['Paiements'] = 'Retard de paiement mentionne.';
		} elseif ( false !== strpos( $user_blob_lower, 'minimum' ) ) {
			$summary['Paiements'] = 'Paiement minimum mentionne.';
		} elseif ( false !== strpos( $user_blob_lower, 'a jour' ) ) {
			$summary['Paiements'] = 'Paiements a jour mentionnes.';
		}

		if ( false !== strpos( $user_blob_lower, 'creancier' ) || false !== strpos( $user_blob_lower, 'appel' ) || false !== strpos( $user_blob_lower, 'recouvrement' ) ) {
			$summary['Pression des creanciers'] = 'Des appels, creanciers ou recouvrement sont mentionnes.';
		}

		if ( false !== strpos( $user_blob_lower, 'stress' ) || false !== strpos( $user_blob_lower, 'anx' ) || false !== strpos( $user_blob_lower, 'angoiss' ) || false !== strpos( $user_blob_lower, 'peur' ) ) {
			$summary['Etat emotionnel'] = 'Du stress ou de l\'anxiete est mentionne.';
		}

		if ( ! empty( $form_summary ) ) {
			$identity = array();
			foreach ( array( 'Prenom', 'Nom', 'Telephone', 'Courriel', 'Ville' ) as $label ) {
				if ( ! empty( $form_summary[ $label ] ) ) {
					$identity[] = $label . ': ' . $form_summary[ $label ];
				}
			}
			if ( ! empty( $identity ) ) {
				$summary['Coordonnees formulaire'] = implode( ' | ', $identity );
			}
		}

		if ( ! empty( $session['rdv_type'] ) ) {
			$summary['Orientation'] = 'Rendez-vous ' . sanitize_text_field( (string) $session['rdv_type'] ) . '.';
		}

		if ( ! empty( $session['rdv_submitted'] ) ) {
			$summary['Prochaine etape'] = 'Le rendez-vous a ete soumis depuis le formulaire.';
		} elseif ( 'rdv_clicked' === ( $session['status'] ?? '' ) ) {
			$summary['Prochaine etape'] = 'Le lien de rendez-vous a ete clique, sans soumission confirmee.';
		} elseif ( '' !== $assistant_cta ) {
			$summary['Prochaine etape'] = 'Le bot a oriente vers une prise de rendez-vous.';
		}

		return $summary;
	}

	private function render_conversation_summary_block( ?array $session, array $messages ): void {
		unset( $messages );

		if ( ! is_array( $session ) || empty( $session['rdv_submitted'] ) ) {
			return;
		}

		$summary_text = trim( (string) ( $session['rdv_summary'] ?? '' ) );
		if ( '' === $summary_text ) {
			return;
		}

		?>
		<div class="card" style="max-width:1200px;padding:16px 20px;margin-top:20px;border-left:4px solid #1d4ed8;">
			<h3 style="margin-top:0;"><?php esc_html_e( 'Resume conseiller', 'lionard-simple-chat' ); ?></h3>
			<p style="margin-top:0;color:#555;"><?php esc_html_e( 'Synthese OpenAI generee a la soumission du rendez-vous.', 'lionard-simple-chat' ); ?></p>
			<?php if ( ! empty( $session['rdv_summary_generated_at'] ) ) : ?>
				<p style="margin-top:0;color:#555;"><strong><?php esc_html_e( 'Genere le', 'lionard-simple-chat' ); ?> :</strong> <?php echo esc_html( $session['rdv_summary_generated_at'] ); ?></p>
			<?php endif; ?>
			<div style="white-space:pre-wrap;line-height:1.6;"><?php echo esc_html( $summary_text ); ?></div>
		</div>
		<?php
	}

	private function check_access() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acces refuse.', 'lionard-simple-chat' ) );
		}
	}

	// =========================================================================
	// 1. Tableau de bord
	// =========================================================================

	public function render_dashboard() {
		$this->check_access();
		$settings = LSC_Plugin::get_settings();
		$key_set  = '' !== (string) ( $settings['openai_api_key'] ?? '' );
		$active   = '1' === $settings['enabled'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tableau de bord', 'lionard-simple-chat' ); ?></h1>
			<div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:16px;">
				<div class="card" style="min-width:180px;padding:16px 20px;">
					<h3 style="margin-top:0"><?php esc_html_e( 'Widget', 'lionard-simple-chat' ); ?></h3>
					<?php if ( $active ) : ?>
						<span style="color:#00a32a;">&#9679; <?php esc_html_e( 'Actif', 'lionard-simple-chat' ); ?></span>
					<?php else : ?>
						<span style="color:#d63638;">&#9679; <?php esc_html_e( 'Inactif', 'lionard-simple-chat' ); ?></span>
					<?php endif; ?>
				</div>
				<div class="card" style="min-width:180px;padding:16px 20px;">
					<h3 style="margin-top:0"><?php esc_html_e( 'OpenAI', 'lionard-simple-chat' ); ?></h3>
					<?php if ( $key_set ) : ?>
						<span style="color:#00a32a;">&#9679; <?php esc_html_e( 'Cle configuree', 'lionard-simple-chat' ); ?></span>
					<?php else : ?>
						<span style="color:#d63638;">&#9679; <?php esc_html_e( 'Cle manquante', 'lionard-simple-chat' ); ?></span>
					<?php endif; ?>
				</div>
				<div class="card" style="min-width:180px;padding:16px 20px;">
					<h3 style="margin-top:0"><?php esc_html_e( 'Modele', 'lionard-simple-chat' ); ?></h3>
					<code><?php echo esc_html( $settings['model'] ); ?></code>
				</div>
			</div>
		</div>
		<?php
	}

	// =========================================================================
	// 2. Reglages
	// =========================================================================

	public function render_reglages() {
		$this->check_access();
		$settings = LSC_Plugin::get_settings();
		$key_set  = '' !== (string) ( $settings['openai_api_key'] ?? '' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Reglages', 'lionard-simple-chat' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'lsc_settings_group' ); ?>

				<h2><?php esc_html_e( 'Activation', 'lionard-simple-chat' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Widget', 'lionard-simple-chat' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[enabled]" value="0">
							<label>
								<input type="checkbox" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( $settings['enabled'], '1' ); ?>>
								<?php esc_html_e( 'Afficher le widget public', 'lionard-simple-chat' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Cle API OpenAI', 'lionard-simple-chat' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lsc_openai_api_key"><?php esc_html_e( 'Cle API', 'lionard-simple-chat' ); ?></label></th>
						<td>
							<input id="lsc_openai_api_key" class="regular-text" type="password" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[openai_api_key]" value="" autocomplete="off" placeholder="<?php echo esc_attr( $key_set ? 'Cle deja enregistree' : 'sk-...' ); ?>">
							<p class="description"><?php esc_html_e( 'La cle n\'est jamais envoyee au navigateur.', 'lionard-simple-chat' ); ?></p>
							<?php if ( $key_set ) : ?>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[clear_openai_api_key]" value="1">
									<?php esc_html_e( 'Supprimer la cle enregistree', 'lionard-simple-chat' ); ?>
								</label>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Modele OpenAI', 'lionard-simple-chat' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lsc_model"><?php esc_html_e( 'Modele', 'lionard-simple-chat' ); ?></label></th>
						<td>
							<input id="lsc_model" class="regular-text" type="text" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[model]" value="<?php echo esc_attr( $settings['model'] ); ?>">
							<p class="description"><?php esc_html_e( 'Par defaut: gpt-4o-mini. Vous pouvez le changer selon votre compte OpenAI.', 'lionard-simple-chat' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lsc_temperature"><?php esc_html_e( 'Temperature', 'lionard-simple-chat' ); ?></label></th>
						<td><input id="lsc_temperature" type="number" step="0.1" min="0" max="2" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[temperature]" value="<?php echo esc_attr( $settings['temperature'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="lsc_max_output_tokens"><?php esc_html_e( 'Tokens reponse max', 'lionard-simple-chat' ); ?></label></th>
						<td><input id="lsc_max_output_tokens" type="number" min="150" max="1500" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[max_output_tokens]" value="<?php echo esc_attr( $settings['max_output_tokens'] ); ?>"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Securite', 'lionard-simple-chat' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Rate limit', 'lionard-simple-chat' ); ?></th>
						<td>
							<input type="number" min="1" max="100" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[rate_limit_count]" value="<?php echo esc_attr( $settings['rate_limit_count'] ); ?>" style="width:90px;">
							<?php esc_html_e( 'requete(s) par', 'lionard-simple-chat' ); ?>
							<input type="number" min="60" max="86400" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[rate_limit_window]" value="<?php echo esc_attr( $settings['rate_limit_window'] ); ?>" style="width:110px;">
							<?php esc_html_e( 'secondes par IP.', 'lionard-simple-chat' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lsc_allowed_hosts"><?php esc_html_e( 'Domaines de boutons autorises', 'lionard-simple-chat' ); ?></label></th>
						<td>
							<textarea id="lsc_allowed_hosts" class="large-text code" rows="5" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[allowed_cta_hosts]"><?php echo esc_textarea( $settings['allowed_cta_hosts'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Un domaine par ligne. Les boutons vers d\'autres domaines seront ignores par le widget.', 'lionard-simple-chat' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Capture e-mail', 'lionard-simple-chat' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Activer', 'lionard-simple-chat' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[email_gate]" value="0">
							<label>
								<input type="checkbox" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[email_gate]" value="1" <?php checked( $settings['email_gate'] ?? '1', '1' ); ?>>
								<?php esc_html_e( 'Demander l\'adresse e-mail avant de demarrer la conversation', 'lionard-simple-chat' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lsc_email_gate_title"><?php esc_html_e( 'Texte de la invite', 'lionard-simple-chat' ); ?></label></th>
						<td>
							<textarea id="lsc_email_gate_title" class="large-text" rows="3" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[email_gate_title]"><?php echo esc_textarea( $settings['email_gate_title'] ?? '' ); ?></textarea>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Recherche site', 'lionard-simple-chat' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Recherche native WordPress', 'lionard-simple-chat' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[site_search]" value="0">
							<label>
								<input type="checkbox" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[site_search]" value="1" <?php checked( $settings['site_search'] ?? '1', '1' ); ?>>
								<?php esc_html_e( 'Injecter les pages/articles WordPress pertinents dans le contexte du bot', 'lionard-simple-chat' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Le bot peut proposer des boutons vers les pages trouvees. Seuls les domaines autorises ci-dessus sont permis.', 'lionard-simple-chat' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Rendez-vous', 'lionard-simple-chat' ); ?></h2>
				<p class="description" style="margin-bottom:12px;"><?php esc_html_e( 'Ces URL doivent aussi figurer dans la liste des domaines autorises ci-dessus. Le prompt doit utiliser exactement ces URL pour que le modal se declenche.', 'lionard-simple-chat' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lsc_rdv_particulier_url"><?php esc_html_e( 'RDV particulier', 'lionard-simple-chat' ); ?></label></th>
						<td>
							<input id="lsc_rdv_particulier_url" class="large-text" type="url" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[rdv_particulier_url]" value="<?php echo esc_attr( $settings['rdv_particulier_url'] ?? '' ); ?>">
							<p class="description"><?php esc_html_e( 'Ex. : https://www.dettes.ca/formulaire-particulier/', 'lionard-simple-chat' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lsc_rdv_entreprise_url"><?php esc_html_e( 'RDV entreprise', 'lionard-simple-chat' ); ?></label></th>
						<td>
							<input id="lsc_rdv_entreprise_url" class="large-text" type="url" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[rdv_entreprise_url]" value="<?php echo esc_attr( $settings['rdv_entreprise_url'] ?? '' ); ?>">
							<p class="description"><?php esc_html_e( 'Ex. : https://www.dettes.ca/formulaire-entreprise/', 'lionard-simple-chat' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Comportement au clic RDV', 'lionard-simple-chat' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Mode d\'ouverture', 'lionard-simple-chat' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[rdv_close_chat]" value="0">
							<label>
								<input type="checkbox" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[rdv_close_chat]" value="1" <?php checked( $settings['rdv_close_chat'] ?? '1', '1' ); ?>>
								<?php esc_html_e( 'Fermer le chat et ouvrir une fenetre modale (iframe) au clic sur un bouton RDV', 'lionard-simple-chat' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Desactive : le bouton RDV s\'ouvre dans un nouvel onglet comme NovaPlan.', 'lionard-simple-chat' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Apres le modal', 'lionard-simple-chat' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[rdv_keep_closed]" value="0">
							<label>
								<input type="checkbox" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[rdv_keep_closed]" value="1" <?php checked( $settings['rdv_keep_closed'] ?? '1', '1' ); ?>>
								<?php esc_html_e( 'Garder le chat ferme apres la fermeture du modal (session courante)', 'lionard-simple-chat' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'L\'utilisateur a pris rendez-vous : le bouton flottant reste visible mais ne reouvre pas le chat.', 'lionard-simple-chat' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Notifications RDV', 'lionard-simple-chat' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Activer', 'lionard-simple-chat' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[rdv_email_notifications]" value="0">
							<label>
								<input type="checkbox" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[rdv_email_notifications]" value="1" <?php checked( $settings['rdv_email_notifications'] ?? '0', '1' ); ?>>
								<?php esc_html_e( 'Envoyer un e-mail a chaque rendez-vous converti', 'lionard-simple-chat' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lsc_rdv_notification_emails"><?php esc_html_e( 'Destinataires', 'lionard-simple-chat' ); ?></label></th>
						<td>
							<textarea id="lsc_rdv_notification_emails" class="large-text code" rows="4" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[rdv_notification_emails]"><?php echo esc_textarea( $settings['rdv_notification_emails'] ?? '' ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Un e-mail par ligne. Le message inclura le resume OpenAI et les informations de contact remplies dans FormLift.', 'lionard-simple-chat' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Enregistrer', 'lionard-simple-chat' ) ); ?>
			</form>
		</div>
		<?php
	}

	// =========================================================================
	// 3. Prompts
	// =========================================================================

	public function render_prompts() {
		$this->check_access();
		$settings = LSC_Plugin::get_settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Prompts', 'lionard-simple-chat' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'lsc_settings_group' ); ?>

				<h2><?php esc_html_e( 'Prompt systeme', 'lionard-simple-chat' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Instruction principale envoyee a OpenAI au debut de chaque conversation.', 'lionard-simple-chat' ); ?></p>
				<textarea class="large-text code" rows="30" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[prompt]"><?php echo esc_textarea( $settings['prompt'] ); ?></textarea>

				<?php submit_button( __( 'Enregistrer', 'lionard-simple-chat' ) ); ?>
			</form>
		</div>
		<?php
	}

	// =========================================================================
	// 4. Connaissances — actions CRUD (admin_init, avant tout output)
	// =========================================================================

	public function handle_knowledge_actions() {
		if ( ! isset( $_GET['page'] ) || 'lionard-chat-connaissances' !== $_GET['page'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$base_url = admin_url( 'admin.php?page=lionard-chat-connaissances' );

		// ---- POST : ajouter ou mettre a jour --------------------------------
		if ( ! empty( $_POST['lsc_kb_action'] ) ) {
			$action = sanitize_key( wp_unslash( (string) $_POST['lsc_kb_action'] ) );

			if ( 'add' === $action ) {
				check_admin_referer( 'lsc_kb_add' );

				$type    = sanitize_key( wp_unslash( (string) ( $_POST['kb_type'] ?? 'text' ) ) );
				$title   = sanitize_text_field( wp_unslash( (string) ( $_POST['kb_title'] ?? '' ) ) );
				$content = sanitize_textarea_field( wp_unslash( (string) ( $_POST['kb_content'] ?? '' ) ) );
				$url     = esc_url_raw( wp_unslash( (string) ( $_POST['kb_url'] ?? '' ) ) );

				if ( 'url' === $type && '' !== $url && '' === trim( $content ) ) {
					$fetched = LSC_Knowledge::fetch_url_content( $url );
					if ( '' === $fetched ) {
						wp_safe_redirect( add_query_arg( 'kb_notice', 'url_error', $base_url ) );
						exit;
					}
					$content = $fetched;
				}

				if ( '' === trim( $content ) ) {
					wp_safe_redirect( add_query_arg( 'kb_notice', 'empty', $base_url ) );
					exit;
				}

				$result = LSC_Knowledge::insert( array(
					'type'       => $type,
					'title'      => $title,
					'content'    => $content,
					'source_url' => $url,
				) );

				wp_safe_redirect( add_query_arg( 'kb_notice', $result ? 'added' : 'error', $base_url ) );
				exit;
			}

			if ( 'update' === $action ) {
				check_admin_referer( 'lsc_kb_update' );

				$id      = absint( $_POST['kb_id'] ?? 0 );
				$type    = sanitize_key( wp_unslash( (string) ( $_POST['kb_type'] ?? 'text' ) ) );
				$title   = sanitize_text_field( wp_unslash( (string) ( $_POST['kb_title'] ?? '' ) ) );
				$content = sanitize_textarea_field( wp_unslash( (string) ( $_POST['kb_content'] ?? '' ) ) );

				if ( 0 === $id || '' === trim( $content ) ) {
					wp_safe_redirect( add_query_arg( 'kb_notice', 'empty', $base_url ) );
					exit;
				}

				$result = LSC_Knowledge::update( $id, array(
					'type'    => $type,
					'title'   => $title,
					'content' => $content,
				) );

				wp_safe_redirect( add_query_arg( 'kb_notice', false !== $result ? 'updated' : 'error', $base_url ) );
				exit;
			}

			if ( 'json_import' === $action ) {
				check_admin_referer( 'lsc_kb_json_import' );

				$file = $_FILES['kb_json_file'] ?? null;
				if ( ! is_array( $file ) || ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
					wp_safe_redirect( add_query_arg( 'kb_notice', 'upload_error', $base_url ) );
					exit;
				}

				$ext = strtolower( (string) pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
				if ( 'json' !== $ext ) {
					wp_safe_redirect( add_query_arg( 'kb_notice', 'json_invalid', $base_url ) );
					exit;
				}

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$raw = file_get_contents( (string) $file['tmp_name'] );
				if ( false === $raw ) {
					wp_safe_redirect( add_query_arg( 'kb_notice', 'upload_error', $base_url ) );
					exit;
				}

				$data = json_decode( $raw, true );
				if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) {
					wp_safe_redirect( add_query_arg( 'kb_notice', 'json_invalid', $base_url ) );
					exit;
				}

				$entries = $this->parse_json_entries( $data );
				if ( empty( $entries ) ) {
					wp_safe_redirect( add_query_arg( 'kb_notice', 'json_empty', $base_url ) );
					exit;
				}

				$imported = 0;
				foreach ( $entries as $entry ) {
					if ( LSC_Knowledge::insert( $entry ) ) {
						$imported++;
					}
				}

				wp_safe_redirect( add_query_arg( array( 'kb_notice' => 'imported', 'kb_count' => $imported ), $base_url ) );
				exit;
			}
		}

		// ---- GET : supprimer ou basculer ------------------------------------
		if ( ! empty( $_GET['kb_action'] ) ) {
			$action = sanitize_key( wp_unslash( (string) $_GET['kb_action'] ) );
			$id     = absint( $_GET['kb_id'] ?? 0 );

			if ( 0 === $id && 'delete_all' !== $action ) {
				return;
			}

			if ( 'delete' === $action ) {
				check_admin_referer( 'lsc_kb_delete_' . $id );
				LSC_Knowledge::delete( $id );
				wp_safe_redirect( add_query_arg( 'kb_notice', 'deleted', $base_url ) );
				exit;
			}

			if ( 'toggle' === $action ) {
				check_admin_referer( 'lsc_kb_toggle_' . $id );
				LSC_Knowledge::toggle_active( $id );
				wp_safe_redirect( add_query_arg( 'kb_notice', 'toggled', $base_url ) );
				exit;
			}

			if ( 'delete_all' === $action ) {
				check_admin_referer( 'lsc_kb_delete_all' );
				LSC_Knowledge::delete_all();
				wp_safe_redirect( add_query_arg( 'kb_notice', 'deleted_all', $base_url ) );
				exit;
			}
		}
	}

	/**
	 * Parse a decoded JSON array/object into normalized KB entries.
	 *
	 * Formats accepted:
	 *   0. Envelope     : {"entries":[...]}, {"data":[...]}, {"items":[...]}, etc.
	 *   1. Standard     : [{"type":"faq","title":"Q?","content":"R."},...]
	 *   2. question/answer: [{"question":"Q?","answer":"R."},...]
	 *   3. q/a court    : [{"q":"Q?","a":"R."},...]
	 *   4. Objet map    : {"Question":"Reponse",...}
	 *
	 * Corrige automatiquement le mojibake UTF-8 (ex. genere par PowerShell).
	 */
	private function parse_json_entries( array $data ): array {
		$entries     = array();
		$valid_types = array( 'faq', 'text', 'url' );

		$keys   = array_keys( $data );
		$is_map = ! empty( $keys ) && ! is_int( $keys[0] );

		// Format 0 : objet enveloppant {"entries": [...], "data": [...], ...}
		// Detecte si une valeur est un tableau indexe -> c'est le vrai tableau d'entrees
		if ( $is_map ) {
			foreach ( $data as $v ) {
				if ( is_array( $v ) && isset( $v[0] ) && is_array( $v[0] ) ) {
					return $this->parse_json_entries( $v );
				}
			}

			// Format 4 : objet cle => valeur simple {"Question":"Reponse",...}
			foreach ( $data as $title => $content ) {
				if ( is_string( $title ) && is_string( $content ) && '' !== trim( $content ) ) {
					$entries[] = array(
						'type'    => 'faq',
						'title'   => sanitize_text_field( $this->fix_encoding( $title ) ),
						'content' => sanitize_textarea_field( $this->fix_encoding( $content ) ),
					);
				}
			}
			return $entries;
		}

		// Formats 1, 2, 3 : tableau d'objets
		foreach ( $data as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$title   = '';
			$content = '';
			$type    = 'faq';

			// Format 1 : champs standards (title + content)
			if ( isset( $item['content'] ) ) {
				$content = (string) $item['content'];
				$title   = (string) ( $item['title'] ?? $item['question'] ?? '' );
				$type    = in_array( $item['type'] ?? '', $valid_types, true ) ? (string) $item['type'] : 'faq';

			// Format 2 : question / answer
			} elseif ( isset( $item['question'], $item['answer'] ) ) {
				$title   = (string) $item['question'];
				$content = (string) $item['answer'];

			// Format 3 : q / a
			} elseif ( isset( $item['q'], $item['a'] ) ) {
				$title   = (string) $item['q'];
				$content = (string) $item['a'];
			}

			if ( '' !== trim( $content ) ) {
				$entries[] = array(
					'type'    => $type,
					'title'   => sanitize_text_field( $this->fix_encoding( $title ) ),
					'content' => sanitize_textarea_field( $this->fix_encoding( $content ) ),
				);
			}
		}

		return $entries;
	}

	/**
	 * Corrige le mojibake UTF-8 genere par PowerShell ConvertTo-Json ou Excel.
	 * Ex. "procÃ©dures" -> "procédures"
	 * Technique : si la chaine contient des sequences UTF-8 invalides ou des
	 * patterns de double-encodage (C3 suivi d'octets Latin), on reconvertit.
	 */
	private function fix_encoding( string $text ): string {
		// Detecte le pattern typique du double-encodage : "Ã" (U+00C3) suivi
		// d'un caractere dans la plage Latin-1 etendue (U+0082..U+00BF).
		if ( ! preg_match( '/\xC3[\x82-\xBF]/u', $text ) ) {
			return $text;
		}
		// Encode la chaine UTF-8 vers ISO-8859-1 pour recuperer les octets d'origine.
		$candidate = mb_convert_encoding( $text, 'ISO-8859-1', 'UTF-8' );
		// Verifie que le resultat est du UTF-8 valide avant de l'utiliser.
		if ( false !== $candidate && mb_check_encoding( $candidate, 'UTF-8' ) ) {
			return $candidate;
		}
		return $text;
	}

	// =========================================================================
	// 4. Connaissances — page d'affichage
	// =========================================================================

	public function render_connaissances() {
		$this->check_access();

		$edit_id    = absint( $_GET['kb_edit'] ?? 0 );
		$edit_entry = $edit_id > 0 ? LSC_Knowledge::get_by_id( $edit_id ) : null;
		$entries    = LSC_Knowledge::get_entries();
		$count      = LSC_Knowledge::count();
		$active_cnt = LSC_Knowledge::count( true );
		$base_url   = admin_url( 'admin.php?page=lionard-chat-connaissances' );
		$filters    = array(
			'search' => $this->get_filter_value( 'kb_search' ),
			'type'   => $this->get_filter_value( 'kb_type_filter' ),
			'status' => $this->get_filter_value( 'kb_status' ),
		);
		$filtered_count = count( $entries );

		$notice    = sanitize_key( wp_unslash( (string) ( $_GET['kb_notice'] ?? '' ) ) );
		$imported_count = absint( $_GET['kb_count'] ?? 0 );
		$notices = array(
			'added'        => array( 'success', 'Entree ajoutee.' ),
			'updated'      => array( 'success', 'Entree mise a jour.' ),
			'deleted'      => array( 'success', 'Entree supprimee.' ),
			'deleted_all'  => array( 'success', 'Toutes les entrees ont ete supprimees.' ),
			'toggled'      => array( 'success', 'Statut modifie.' ),
			'imported'     => array( 'success', $imported_count . ' entree(s) importee(s).' ),
			'empty'        => array( 'error', 'Le contenu est requis.' ),
			'url_error'    => array( 'error', 'Impossible de recuperer le contenu de l\'URL.' ),
			'upload_error' => array( 'error', 'Erreur lors du chargement du fichier.' ),
			'json_invalid' => array( 'error', 'Fichier JSON invalide ou mal forme.' ),
			'json_empty'   => array( 'error', 'Aucune entree valide trouvee dans le fichier.' ),
			'error'        => array( 'error', 'Une erreur est survenue.' ),
		);

		$type_labels = array( 'faq' => 'FAQ', 'text' => 'Texte', 'url' => 'URL' );
		$current_type = $edit_entry ? (string) $edit_entry['type'] : 'text';

		if ( ! empty( $entries ) ) {
			$entries = array_values(
				array_filter(
					$entries,
					function ( array $entry ) use ( $filters ): bool {
						if ( '' !== $filters['type'] && (string) $entry['type'] !== $filters['type'] ) {
							return false;
						}

						if ( 'active' === $filters['status'] && empty( $entry['active'] ) ) {
							return false;
						}

						if ( 'inactive' === $filters['status'] && ! empty( $entry['active'] ) ) {
							return false;
						}

						if ( '' !== $filters['search'] ) {
							$haystack = strtolower(
								trim(
									(string) ( $entry['title'] ?? '' ) . ' ' . (string) ( $entry['content'] ?? '' )
								)
							);
							if ( false === strpos( $haystack, strtolower( $filters['search'] ) ) ) {
								return false;
							}
						}

						return true;
					}
				)
			);
			$filtered_count = count( $entries );
		}
		?>
		<div class="wrap">
			<h1>
				<?php esc_html_e( 'Connaissances', 'lionard-simple-chat' ); ?>
				<span style="font-size:13px;font-weight:400;color:#777;margin-left:8px;">
					<?php echo esc_html( $active_cnt . ' / ' . $count ); ?> <?php esc_html_e( 'actives', 'lionard-simple-chat' ); ?>
				</span>
			</h1>

			<?php if ( '' !== $notice && isset( $notices[ $notice ] ) ) :
				[ $type_cls, $msg ] = $notices[ $notice ]; ?>
				<div class="notice notice-<?php echo esc_attr( $type_cls ); ?> is-dismissible">
					<p><?php echo esc_html( $msg ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Formulaire ajouter / modifier -->
			<div class="card" style="max-width:800px;padding:16px 20px;margin-bottom:24px;">
				<h2 style="margin-top:0;">
					<?php echo $edit_entry
						? esc_html__( 'Modifier l\'entree', 'lionard-simple-chat' )
						: esc_html__( 'Ajouter une entree', 'lionard-simple-chat' ); ?>
				</h2>
				<form method="post">
					<?php if ( $edit_entry ) :
						wp_nonce_field( 'lsc_kb_update' ); ?>
						<input type="hidden" name="lsc_kb_action" value="update">
						<input type="hidden" name="kb_id" value="<?php echo esc_attr( $edit_entry['id'] ); ?>">
					<?php else :
						wp_nonce_field( 'lsc_kb_add' ); ?>
						<input type="hidden" name="lsc_kb_action" value="add">
					<?php endif; ?>

					<table class="form-table" style="margin-top:0;" role="presentation">
						<tr>
							<th scope="row" style="width:130px;"><?php esc_html_e( 'Type', 'lionard-simple-chat' ); ?></th>
							<td>
								<select name="kb_type" id="lsc_kb_type">
									<?php foreach ( $type_labels as $val => $label ) : ?>
										<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current_type, $val ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Titre / Question', 'lionard-simple-chat' ); ?></th>
							<td>
								<input class="large-text" type="text" name="kb_title"
									value="<?php echo esc_attr( (string) ( $edit_entry['title'] ?? '' ) ); ?>"
									placeholder="<?php esc_attr_e( 'Titre ou question (optionnel pour Texte)', 'lionard-simple-chat' ); ?>">
							</td>
						</tr>
						<tr id="lsc_kb_url_row" style="display:<?php echo 'url' === $current_type ? 'table-row' : 'none'; ?>;">
							<th scope="row"><?php esc_html_e( 'URL a importer', 'lionard-simple-chat' ); ?></th>
							<td>
								<input class="large-text" type="url" name="kb_url"
									value="<?php echo esc_attr( (string) ( $edit_entry['source_url'] ?? '' ) ); ?>"
									placeholder="https://...">
								<p class="description"><?php esc_html_e( 'Le contenu de la page sera importe et nettoye. Laissez le champ Contenu vide pour l\'importer automatiquement.', 'lionard-simple-chat' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Contenu / Reponse', 'lionard-simple-chat' ); ?></th>
							<td>
								<textarea class="large-text code" name="kb_content" rows="6"
									placeholder="<?php esc_attr_e( 'Texte de la reponse ou information...', 'lionard-simple-chat' ); ?>"><?php echo esc_textarea( (string) ( $edit_entry['content'] ?? '' ) ); ?></textarea>
							</td>
						</tr>
					</table>

					<?php submit_button(
						$edit_entry ? __( 'Mettre a jour', 'lionard-simple-chat' ) : __( 'Ajouter', 'lionard-simple-chat' ),
						'primary', 'submit', false
					); ?>
					<?php if ( $edit_entry ) : ?>
						<a href="<?php echo esc_url( $base_url ); ?>" class="button" style="margin-left:8px;">
							<?php esc_html_e( 'Annuler', 'lionard-simple-chat' ); ?>
						</a>
					<?php endif; ?>
				</form>
			</div>

			<script>
			(function () {
				var sel = document.getElementById('lsc_kb_type');
				var row = document.getElementById('lsc_kb_url_row');
				if (!sel || !row) return;
				sel.addEventListener('change', function () {
					row.style.display = (this.value === 'url') ? 'table-row' : 'none';
				});
			}());
			</script>

			<!-- Import JSON -->
			<div class="card" style="max-width:800px;padding:16px 20px;margin-bottom:24px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Importer un fichier JSON', 'lionard-simple-chat' ); ?></h2>
				<form method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'lsc_kb_json_import' ); ?>
					<input type="hidden" name="lsc_kb_action" value="json_import">
					<table class="form-table" style="margin-top:0;" role="presentation">
						<tr>
							<th scope="row" style="width:130px;"><label for="lsc_kb_json_file"><?php esc_html_e( 'Fichier .json', 'lionard-simple-chat' ); ?></label></th>
							<td>
								<input id="lsc_kb_json_file" type="file" name="kb_json_file" accept=".json,application/json">
								<p class="description">
									<?php esc_html_e( 'Formats acceptes :', 'lionard-simple-chat' ); ?>
									<br><strong><?php esc_html_e( '1. Standard', 'lionard-simple-chat' ); ?></strong>
									<code>[{"type":"faq","title":"Q?","content":"R."},...]</code>
									<br><strong><?php esc_html_e( '2. question/answer', 'lionard-simple-chat' ); ?></strong>
									<code>[{"question":"Q?","answer":"R."},...]</code>
									<br><strong><?php esc_html_e( '3. q/a court', 'lionard-simple-chat' ); ?></strong>
									<code>[{"q":"Q?","a":"R."},...]</code>
									<br><strong><?php esc_html_e( '4. Objet cle/valeur', 'lionard-simple-chat' ); ?></strong>
									<code>{"Titre ou question":"Contenu ou reponse",...}</code>
								</p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Importer', 'lionard-simple-chat' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<!-- Liste des entrees -->
			<?php if ( 0 === $count ) : ?>
				<p><?php esc_html_e( 'Aucune entree. Ajoutez votre premiere entree ci-dessus.', 'lionard-simple-chat' ); ?></p>
			<?php else : ?>
				<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
					<strong><?php echo esc_html( $filtered_count . ' / ' . $count ); ?> <?php esc_html_e( 'entree(s)', 'lionard-simple-chat' ); ?></strong>
					<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'kb_action', 'delete_all', $base_url ), 'lsc_kb_delete_all' ) ); ?>"
					   class="button button-secondary"
					   style="color:#d63638;border-color:#d63638;"
					   onclick="return confirm('<?php esc_attr_e( 'Supprimer TOUTES les entrees ? Cette action est irreversible.', 'lionard-simple-chat' ); ?>')">
						<?php esc_html_e( 'Vider tout', 'lionard-simple-chat' ); ?>
					</a>
				</div>
				<form method="get" style="margin:0 0 12px;display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
					<input type="hidden" name="page" value="lionard-chat-connaissances">
					<div>
						<label for="lsc_kb_search" style="display:block;margin-bottom:4px;"><?php esc_html_e( 'Recherche', 'lionard-simple-chat' ); ?></label>
						<input id="lsc_kb_search" class="regular-text" type="search" name="kb_search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Titre ou contenu', 'lionard-simple-chat' ); ?>">
					</div>
					<div>
						<label for="lsc_kb_type_filter" style="display:block;margin-bottom:4px;"><?php esc_html_e( 'Type', 'lionard-simple-chat' ); ?></label>
						<select id="lsc_kb_type_filter" name="kb_type_filter">
							<option value=""><?php esc_html_e( 'Tous', 'lionard-simple-chat' ); ?></option>
							<?php foreach ( $type_labels as $type_value => $type_label ) : ?>
								<option value="<?php echo esc_attr( $type_value ); ?>" <?php selected( $filters['type'], $type_value ); ?>><?php echo esc_html( $type_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label for="lsc_kb_status" style="display:block;margin-bottom:4px;"><?php esc_html_e( 'Statut', 'lionard-simple-chat' ); ?></label>
						<select id="lsc_kb_status" name="kb_status">
							<option value=""><?php esc_html_e( 'Tous', 'lionard-simple-chat' ); ?></option>
							<option value="active" <?php selected( $filters['status'], 'active' ); ?>><?php esc_html_e( 'Actif', 'lionard-simple-chat' ); ?></option>
							<option value="inactive" <?php selected( $filters['status'], 'inactive' ); ?>><?php esc_html_e( 'Inactif', 'lionard-simple-chat' ); ?></option>
						</select>
					</div>
					<?php submit_button( __( 'Filtrer', 'lionard-simple-chat' ), 'secondary', '', false ); ?>
					<?php $this->render_reset_filters_button( 'lionard-chat-connaissances' ); ?>
				</form>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width:44px;">ID</th>
							<th style="width:60px;"><?php esc_html_e( 'Type', 'lionard-simple-chat' ); ?></th>
							<th style="width:30%;"><?php esc_html_e( 'Titre', 'lionard-simple-chat' ); ?></th>
							<th><?php esc_html_e( 'Contenu (apercu)', 'lionard-simple-chat' ); ?></th>
							<th style="width:72px;"><?php esc_html_e( 'Statut', 'lionard-simple-chat' ); ?></th>
							<th style="width:200px;"><?php esc_html_e( 'Actions', 'lionard-simple-chat' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php if ( empty( $entries ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'Aucun resultat pour ces filtres.', 'lionard-simple-chat' ); ?></td></tr>
					<?php else : ?>
					<?php foreach ( $entries as $entry ) :
						$eid     = (int) $entry['id'];
						$active  = (bool) $entry['active'];
						$preview = mb_substr( (string) $entry['content'], 0, 110, 'UTF-8' );
						if ( mb_strlen( (string) $entry['content'], 'UTF-8' ) > 110 ) {
							$preview .= '…';
						}
						$toggle_url = wp_nonce_url(
							add_query_arg( array( 'kb_action' => 'toggle', 'kb_id' => $eid ), $base_url ),
							'lsc_kb_toggle_' . $eid
						);
						$delete_url = wp_nonce_url(
							add_query_arg( array( 'kb_action' => 'delete', 'kb_id' => $eid ), $base_url ),
							'lsc_kb_delete_' . $eid
						);
						$edit_url = add_query_arg( 'kb_edit', $eid, $base_url );
					?>
						<tr>
							<td><?php echo esc_html( $eid ); ?></td>
							<td><?php echo esc_html( $type_labels[ $entry['type'] ] ?? $entry['type'] ); ?></td>
							<td><?php echo esc_html( '' !== $entry['title'] ? $entry['title'] : '—' ); ?></td>
							<td style="color:#666;font-size:12px;"><?php echo esc_html( $preview ); ?></td>
							<td>
								<?php if ( $active ) : ?>
									<span style="color:#00a32a;">&#9679; <?php esc_html_e( 'Actif', 'lionard-simple-chat' ); ?></span>
								<?php else : ?>
									<span style="color:#aaa;">&#9675; <?php esc_html_e( 'Inactif', 'lionard-simple-chat' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Modifier', 'lionard-simple-chat' ); ?></a>
								&nbsp;|&nbsp;
								<a href="<?php echo esc_url( $toggle_url ); ?>">
									<?php echo esc_html( $active ? __( 'Desactiver', 'lionard-simple-chat' ) : __( 'Activer', 'lionard-simple-chat' ) ); ?>
								</a>
								&nbsp;|&nbsp;
								<a href="<?php echo esc_url( $delete_url ); ?>"
									style="color:#d63638;"
									onclick="return confirm('<?php esc_attr_e( 'Supprimer cette entree ?', 'lionard-simple-chat' ); ?>')">
									<?php esc_html_e( 'Supprimer', 'lionard-simple-chat' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// =========================================================================
	// 5. Conversations
	// =========================================================================

	public function render_conversations() {
		$this->check_access();
		$session_key = sanitize_text_field( wp_unslash( (string) ( $_GET['session_key'] ?? '' ) ) );
		$session     = '' !== $session_key ? LSC_Conversations::get_session_by_key( $session_key ) : null;
		$messages    = $session ? LSC_Conversations::get_messages( $session_key ) : array();
		$filters     = array(
			'search'    => $this->get_filter_value( 'lsc_search' ),
			'status'    => $this->get_filter_value( 'lsc_status' ),
			'rdv_type'  => $this->get_filter_value( 'lsc_rdv_type' ),
			'date_from' => $this->get_filter_value( 'lsc_date_from' ),
			'date_to'   => $this->get_filter_value( 'lsc_date_to' ),
		);
		$sessions    = LSC_Conversations::get_sessions(
			array(
				'type'      => 'conversations',
				'limit'     => 200,
				'search'    => $filters['search'],
				'status'    => $filters['status'],
				'rdv_type'  => $filters['rdv_type'],
				'date_from' => $filters['date_from'],
				'date_to'   => $filters['date_to'],
			)
		);
		$status_options = array(
			'conversation' => 'conversation',
			'rdv_clicked'  => 'rdv_clicked',
		);
		$rdv_type_options = array(
			'particulier' => 'particulier',
			'entreprise'  => 'entreprise',
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Conversations', 'lionard-simple-chat' ); ?></h1>

			<?php if ( $session ) : ?>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=lionard-chat-conversations' ) ); ?>">&larr; <?php esc_html_e( 'Retour a la liste', 'lionard-simple-chat' ); ?></a></p>
				<div class="card" style="max-width:1200px;padding:16px 20px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Detail conversation', 'lionard-simple-chat' ); ?></h2>
					<p><strong>Session:</strong> <code><?php echo esc_html( $session['session_key'] ); ?></code></p>
					<p><strong>Statut:</strong> <?php echo esc_html( $session['status'] ); ?></p>
					<p><strong>Page:</strong> <code><?php echo esc_html( $session['last_page_url'] ); ?></code></p>
					<p><strong>Derniere activite:</strong> <?php echo esc_html( $session['updated_at'] ); ?></p>
					<hr>
					<?php if ( empty( $messages ) ) : ?>
						<p><?php esc_html_e( 'Aucun message enregistre.', 'lionard-simple-chat' ); ?></p>
					<?php else : ?>
						<?php foreach ( $messages as $message ) : ?>
							<div style="margin:0 0 14px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:14px;background:<?php echo 'user' === $message['role'] ? '#eff6ff' : '#ffffff'; ?>;">
								<p style="margin:0 0 8px;"><strong><?php echo esc_html( ucfirst( $message['role'] ) ); ?></strong> <span style="color:#666;">• <?php echo esc_html( $message['created_at'] ); ?></span></p>
								<div style="white-space:pre-wrap;"><?php echo esc_html( $message['content'] ); ?></div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<p><?php esc_html_e( 'Toutes les conversations non converties en rendez-vous soumis apparaissent ici.', 'lionard-simple-chat' ); ?></p>
				<form method="get" style="margin:0 0 12px;display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
					<input type="hidden" name="page" value="lionard-chat-conversations">
					<div>
						<label for="lsc_conv_search" style="display:block;margin-bottom:4px;"><?php esc_html_e( 'Recherche', 'lionard-simple-chat' ); ?></label>
						<input id="lsc_conv_search" class="regular-text" type="search" name="lsc_search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Session, page, message...', 'lionard-simple-chat' ); ?>">
					</div>
					<div>
						<label for="lsc_conv_status" style="display:block;margin-bottom:4px;"><?php esc_html_e( 'Statut', 'lionard-simple-chat' ); ?></label>
						<select id="lsc_conv_status" name="lsc_status">
							<option value=""><?php esc_html_e( 'Tous', 'lionard-simple-chat' ); ?></option>
							<?php foreach ( $status_options as $status_value => $status_label ) : ?>
								<option value="<?php echo esc_attr( $status_value ); ?>" <?php selected( $filters['status'], $status_value ); ?>><?php echo esc_html( $status_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label for="lsc_conv_rdv_type" style="display:block;margin-bottom:4px;"><?php esc_html_e( 'Type RDV', 'lionard-simple-chat' ); ?></label>
						<select id="lsc_conv_rdv_type" name="lsc_rdv_type">
							<option value=""><?php esc_html_e( 'Tous', 'lionard-simple-chat' ); ?></option>
							<?php foreach ( $rdv_type_options as $type_value => $type_label ) : ?>
								<option value="<?php echo esc_attr( $type_value ); ?>" <?php selected( $filters['rdv_type'], $type_value ); ?>><?php echo esc_html( ucfirst( $type_label ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label for="lsc_conv_date_from" style="display:block;margin-bottom:4px;"><?php esc_html_e( 'Du', 'lionard-simple-chat' ); ?></label>
						<input id="lsc_conv_date_from" type="date" name="lsc_date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>">
					</div>
					<div>
						<label for="lsc_conv_date_to" style="display:block;margin-bottom:4px;"><?php esc_html_e( 'Au', 'lionard-simple-chat' ); ?></label>
						<input id="lsc_conv_date_to" type="date" name="lsc_date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>">
					</div>
					<?php submit_button( __( 'Filtrer', 'lionard-simple-chat' ), 'secondary', '', false ); ?>
					<?php $this->render_reset_filters_button( 'lionard-chat-conversations' ); ?>
				</form>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Session', 'lionard-simple-chat' ); ?></th>
							<th><?php esc_html_e( 'Statut', 'lionard-simple-chat' ); ?></th>
							<th><?php esc_html_e( 'Type RDV', 'lionard-simple-chat' ); ?></th>
							<th><?php esc_html_e( 'Page', 'lionard-simple-chat' ); ?></th>
							<th><?php esc_html_e( 'Mise a jour', 'lionard-simple-chat' ); ?></th>
							<th><?php esc_html_e( 'Action', 'lionard-simple-chat' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $sessions ) ) : ?>
							<tr><td colspan="6"><?php esc_html_e( 'Aucune conversation enregistree.', 'lionard-simple-chat' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $sessions as $row ) : ?>
								<tr>
									<td><code><?php echo esc_html( $row['session_key'] ); ?></code></td>
									<td><?php echo esc_html( $row['status'] ); ?></td>
									<td><?php echo esc_html( $row['rdv_type'] ? $row['rdv_type'] : '—' ); ?></td>
									<td style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><code><?php echo esc_html( $row['last_page_url'] ); ?></code></td>
									<td><?php echo esc_html( $row['updated_at'] ); ?></td>
									<td><a href="<?php echo esc_url( add_query_arg( 'session_key', rawurlencode( $row['session_key'] ), admin_url( 'admin.php?page=lionard-chat-conversations' ) ) ); ?>"><?php esc_html_e( 'Voir', 'lionard-simple-chat' ); ?></a></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// =========================================================================
	// 6. Rendez-vous
	// =========================================================================

	public function render_rdv() {
		$this->check_access();
		$settings = LSC_Plugin::get_settings();
		$session_key = sanitize_text_field( wp_unslash( (string) ( $_GET['session_key'] ?? '' ) ) );
		$session     = '' !== $session_key ? LSC_Conversations::get_session_by_key( $session_key ) : null;
		$messages    = $session ? LSC_Conversations::get_messages( $session_key ) : array();
		$filters     = array(
			'search'    => $this->get_filter_value( 'lsc_search' ),
			'status'    => $this->get_filter_value( 'lsc_status' ),
			'rdv_type'  => $this->get_filter_value( 'lsc_rdv_type' ),
			'date_from' => $this->get_filter_value( 'lsc_date_from' ),
			'date_to'   => $this->get_filter_value( 'lsc_date_to' ),
		);
		$sessions    = LSC_Conversations::get_sessions(
			array(
				'type'      => 'rdv',
				'limit'     => 200,
				'search'    => $filters['search'],
				'status'    => $filters['status'],
				'rdv_type'  => $filters['rdv_type'],
				'date_from' => $filters['date_from'],
				'date_to'   => $filters['date_to'],
			)
		);
		$rdv_type_options = array(
			'particulier' => 'particulier',
			'entreprise'  => 'entreprise',
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Rendez-vous', 'lionard-simple-chat' ); ?></h1>

			<?php if ( $session ) : ?>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=lionard-chat-rdv' ) ); ?>">&larr; <?php esc_html_e( 'Retour a la liste', 'lionard-simple-chat' ); ?></a></p>
				<div class="card" style="max-width:1200px;padding:16px 20px;margin-bottom:20px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Detail rendez-vous', 'lionard-simple-chat' ); ?></h2>
					<p><strong>Session:</strong> <code><?php echo esc_html( $session['session_key'] ); ?></code></p>
					<p><strong>Type:</strong> <?php echo esc_html( $session['rdv_type'] ? $session['rdv_type'] : '—' ); ?></p>
					<p><strong>URL RDV:</strong> <code><?php echo esc_html( $session['rdv_url'] ); ?></code></p>
					<p><strong>Soumis le:</strong> <?php echo esc_html( $session['rdv_submitted_at'] ? $session['rdv_submitted_at'] : '—' ); ?></p>
					<p><strong>Source:</strong> <?php echo esc_html( $session['form_source'] ? $session['form_source'] : '—' ); ?></p>
					<?php
					$form_data = json_decode( (string) $session['form_data'], true );
					if ( is_array( $form_data ) && ! empty( $form_data ) ) :
					?>
						<h3><?php esc_html_e( 'Donnees formulaire', 'lionard-simple-chat' ); ?></h3>
						<table class="widefat striped" style="max-width:800px;">
							<tbody>
							<?php foreach ( $form_data as $key => $value ) : ?>
								<tr>
									<td style="width:220px;"><strong><?php echo esc_html( $key ); ?></strong></td>
									<td><?php echo esc_html( is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
					<h3><?php esc_html_e( 'Historique complet', 'lionard-simple-chat' ); ?></h3>
					<?php if ( empty( $messages ) ) : ?>
						<p><?php esc_html_e( 'Aucun message enregistre.', 'lionard-simple-chat' ); ?></p>
					<?php else : ?>
						<?php foreach ( $messages as $message ) : ?>
							<div style="margin:0 0 14px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:14px;background:<?php echo 'user' === $message['role'] ? '#eff6ff' : '#ffffff'; ?>;">
								<p style="margin:0 0 8px;"><strong><?php echo esc_html( ucfirst( $message['role'] ) ); ?></strong> <span style="color:#666;">• <?php echo esc_html( $message['created_at'] ); ?></span></p>
								<div style="white-space:pre-wrap;"><?php echo esc_html( $message['content'] ); ?></div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
				<?php $this->render_conversation_summary_block( $session, $messages ); ?>
			<?php else : ?>
				<div class="card" style="max-width:1200px;padding:16px 20px;margin-bottom:20px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Rendez-vous soumis', 'lionard-simple-chat' ); ?></h2>
					<p><?php esc_html_e( 'Les formulaires Formlift remontes au plugin apparaissent ici avec l’historique complet de l’echange.', 'lionard-simple-chat' ); ?></p>
					<form method="get" style="margin:0 0 12px;display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
						<input type="hidden" name="page" value="lionard-chat-rdv">
						<div>
							<label for="lsc_rdv_search" style="display:block;margin-bottom:4px;"><?php esc_html_e( 'Recherche', 'lionard-simple-chat' ); ?></label>
							<input id="lsc_rdv_search" class="regular-text" type="search" name="lsc_search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Session, URL, message, formulaire...', 'lionard-simple-chat' ); ?>">
						</div>
						<div>
							<label for="lsc_rdv_status" style="display:block;margin-bottom:4px;"><?php esc_html_e( 'Statut', 'lionard-simple-chat' ); ?></label>
							<select id="lsc_rdv_status" name="lsc_status">
								<option value=""><?php esc_html_e( 'Tous', 'lionard-simple-chat' ); ?></option>
								<option value="rdv_submitted" <?php selected( $filters['status'], 'rdv_submitted' ); ?>><?php esc_html_e( 'rdv_submitted', 'lionard-simple-chat' ); ?></option>
							</select>
						</div>
						<div>
							<label for="lsc_rdv_type_filter" style="display:block;margin-bottom:4px;"><?php esc_html_e( 'Type RDV', 'lionard-simple-chat' ); ?></label>
							<select id="lsc_rdv_type_filter" name="lsc_rdv_type">
								<option value=""><?php esc_html_e( 'Tous', 'lionard-simple-chat' ); ?></option>
								<?php foreach ( $rdv_type_options as $type_value => $type_label ) : ?>
									<option value="<?php echo esc_attr( $type_value ); ?>" <?php selected( $filters['rdv_type'], $type_value ); ?>><?php echo esc_html( ucfirst( $type_label ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div>
							<label for="lsc_rdv_date_from" style="display:block;margin-bottom:4px;"><?php esc_html_e( 'Du', 'lionard-simple-chat' ); ?></label>
							<input id="lsc_rdv_date_from" type="date" name="lsc_date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>">
						</div>
						<div>
							<label for="lsc_rdv_date_to" style="display:block;margin-bottom:4px;"><?php esc_html_e( 'Au', 'lionard-simple-chat' ); ?></label>
							<input id="lsc_rdv_date_to" type="date" name="lsc_date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>">
						</div>
						<?php submit_button( __( 'Filtrer', 'lionard-simple-chat' ), 'secondary', '', false ); ?>
						<?php $this->render_reset_filters_button( 'lionard-chat-rdv' ); ?>
					</form>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Session', 'lionard-simple-chat' ); ?></th>
								<th><?php esc_html_e( 'Type', 'lionard-simple-chat' ); ?></th>
								<th><?php esc_html_e( 'URL RDV', 'lionard-simple-chat' ); ?></th>
								<th><?php esc_html_e( 'Soumis le', 'lionard-simple-chat' ); ?></th>
								<th><?php esc_html_e( 'Action', 'lionard-simple-chat' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php if ( empty( $sessions ) ) : ?>
							<tr><td colspan="5"><?php esc_html_e( 'Aucun rendez-vous soumis pour le moment.', 'lionard-simple-chat' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $sessions as $row ) : ?>
								<tr>
									<td><code><?php echo esc_html( $row['session_key'] ); ?></code></td>
									<td><?php echo esc_html( $row['rdv_type'] ? $row['rdv_type'] : '—' ); ?></td>
									<td style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><code><?php echo esc_html( $row['rdv_url'] ); ?></code></td>
									<td><?php echo esc_html( $row['rdv_submitted_at'] ? $row['rdv_submitted_at'] : '—' ); ?></td>
									<td><a href="<?php echo esc_url( add_query_arg( 'session_key', rawurlencode( $row['session_key'] ), admin_url( 'admin.php?page=lionard-chat-rdv' ) ) ); ?>"><?php esc_html_e( 'Voir', 'lionard-simple-chat' ); ?></a></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// =========================================================================
	// 7. Apparence
	// =========================================================================

	public function render_apparence() {
		$this->check_access();
		wp_enqueue_media();
		$settings      = LSC_Plugin::get_settings();
		$avatar_id     = absint( $settings['avatar_attachment_id'] ?? 0 );
		$avatar_src    = $avatar_id > 0 ? wp_get_attachment_image_src( $avatar_id, array( 84, 84 ) ) : false;
		$option_key    = esc_attr( LSC_Plugin::OPTION_KEY );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Apparence', 'lionard-simple-chat' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'lsc_settings_group' ); ?>

				<h2><?php esc_html_e( 'Couleurs', 'lionard-simple-chat' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lsc_primary_color"><?php esc_html_e( 'Couleur principale', 'lionard-simple-chat' ); ?></label></th>
						<td>
							<input id="lsc_primary_color" type="color" name="<?php echo $option_key; ?>[primary_color]" value="<?php echo esc_attr( $settings['primary_color'] ); ?>" style="width:50px;height:34px;padding:2px;cursor:pointer;">
							<code style="margin-left:8px;vertical-align:middle;"><?php echo esc_html( $settings['primary_color'] ); ?></code>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lsc_accent_color"><?php esc_html_e( 'Couleur accent', 'lionard-simple-chat' ); ?></label></th>
						<td>
							<input id="lsc_accent_color" type="color" name="<?php echo $option_key; ?>[accent_color]" value="<?php echo esc_attr( $settings['accent_color'] ); ?>" style="width:50px;height:34px;padding:2px;cursor:pointer;">
							<code style="margin-left:8px;vertical-align:middle;"><?php echo esc_html( $settings['accent_color'] ); ?></code>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Textes', 'lionard-simple-chat' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lsc_launcher_label"><?php esc_html_e( 'Bouton flottant', 'lionard-simple-chat' ); ?></label></th>
						<td><input id="lsc_launcher_label" class="regular-text" type="text" name="<?php echo $option_key; ?>[launcher_label]" value="<?php echo esc_attr( $settings['launcher_label'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="lsc_panel_title"><?php esc_html_e( 'Titre', 'lionard-simple-chat' ); ?></label></th>
						<td><input id="lsc_panel_title" class="regular-text" type="text" name="<?php echo $option_key; ?>[panel_title]" value="<?php echo esc_attr( $settings['panel_title'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="lsc_panel_subtitle"><?php esc_html_e( 'Sous-titre', 'lionard-simple-chat' ); ?></label></th>
						<td><input id="lsc_panel_subtitle" class="regular-text" type="text" name="<?php echo $option_key; ?>[panel_subtitle]" value="<?php echo esc_attr( $settings['panel_subtitle'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="lsc_greeting"><?php esc_html_e( 'Message d\'accueil', 'lionard-simple-chat' ); ?></label></th>
						<td><textarea id="lsc_greeting" class="large-text" rows="3" name="<?php echo $option_key; ?>[greeting]"><?php echo esc_textarea( $settings['greeting'] ); ?></textarea></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Avatar', 'lionard-simple-chat' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Image', 'lionard-simple-chat' ); ?></th>
						<td>
							<input type="hidden" id="lsc_avatar_id" name="<?php echo $option_key; ?>[avatar_attachment_id]" value="<?php echo esc_attr( $avatar_id ); ?>">
							<div id="lsc_avatar_preview" style="margin-bottom:10px;">
								<?php if ( $avatar_src ) : ?>
									<img src="<?php echo esc_url( $avatar_src[0] ); ?>" width="84" height="84" style="border-radius:50%;object-fit:cover;display:block;border:3px solid #ddd;">
								<?php else : ?>
									<div style="width:84px;height:84px;border-radius:50%;background:linear-gradient(135deg,#fde68a,#f59e0b);display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:800;color:#0f172a;border:3px solid #ddd;">L</div>
								<?php endif; ?>
							</div>
							<button type="button" id="lsc_avatar_select" class="button"><?php esc_html_e( 'Choisir une image', 'lionard-simple-chat' ); ?></button>
							<?php if ( $avatar_id > 0 ) : ?>
								<button type="button" id="lsc_avatar_remove" class="button" style="margin-left:6px;"><?php esc_html_e( 'Supprimer', 'lionard-simple-chat' ); ?></button>
							<?php else : ?>
								<button type="button" id="lsc_avatar_remove" class="button" style="margin-left:6px;display:none;"><?php esc_html_e( 'Supprimer', 'lionard-simple-chat' ); ?></button>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Image carree recommandee. Si vide, la lettre L est affichee.', 'lionard-simple-chat' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Position du widget', 'lionard-simple-chat' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Bureau — cote', 'lionard-simple-chat' ); ?></th>
						<td>
							<select name="<?php echo $option_key; ?>[pos_desktop_side]">
								<option value="right" <?php selected( $settings['pos_desktop_side'] ?? 'right', 'right' ); ?>><?php esc_html_e( 'Droite', 'lionard-simple-chat' ); ?></option>
								<option value="left"  <?php selected( $settings['pos_desktop_side'] ?? 'right', 'left' ); ?>><?php esc_html_e( 'Gauche', 'lionard-simple-chat' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lsc_pos_desktop_bottom"><?php esc_html_e( 'Bureau — distance du bas (px)', 'lionard-simple-chat' ); ?></label></th>
						<td>
							<input id="lsc_pos_desktop_bottom" type="number" min="0" max="200" style="width:80px;" name="<?php echo $option_key; ?>[pos_desktop_bottom]" value="<?php echo esc_attr( $settings['pos_desktop_bottom'] ?? '18' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Mobile — position', 'lionard-simple-chat' ); ?></th>
						<td>
							<select name="<?php echo $option_key; ?>[pos_mobile_side]">
								<option value="right"  <?php selected( $settings['pos_mobile_side'] ?? 'right', 'right' ); ?>><?php esc_html_e( 'Droite', 'lionard-simple-chat' ); ?></option>
								<option value="center" <?php selected( $settings['pos_mobile_side'] ?? 'right', 'center' ); ?>><?php esc_html_e( 'Centre', 'lionard-simple-chat' ); ?></option>
								<option value="left"   <?php selected( $settings['pos_mobile_side'] ?? 'right', 'left' ); ?>><?php esc_html_e( 'Gauche', 'lionard-simple-chat' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lsc_pos_mobile_bottom"><?php esc_html_e( 'Mobile — distance du bas (px)', 'lionard-simple-chat' ); ?></label></th>
						<td>
							<input id="lsc_pos_mobile_bottom" type="number" min="0" max="200" style="width:80px;" name="<?php echo $option_key; ?>[pos_mobile_bottom]" value="<?php echo esc_attr( $settings['pos_mobile_bottom'] ?? '10' ); ?>">
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Enregistrer', 'lionard-simple-chat' ) ); ?>
			</form>
		</div>

		<script>
		(function () {
			var selectBtn  = document.getElementById('lsc_avatar_select');
			var removeBtn  = document.getElementById('lsc_avatar_remove');
			var idInput    = document.getElementById('lsc_avatar_id');
			var preview    = document.getElementById('lsc_avatar_preview');
			if (!selectBtn || !idInput || !preview) return;

			var frame;

			selectBtn.addEventListener('click', function (e) {
				e.preventDefault();
				if (frame) { frame.open(); return; }
				frame = wp.media({
					title:    '<?php esc_html_e( 'Choisir l\'avatar', 'lionard-simple-chat' ); ?>',
					button:   { text: '<?php esc_html_e( 'Utiliser cette image', 'lionard-simple-chat' ); ?>' },
					multiple: false,
					library:  { type: 'image' }
				});
				frame.on('select', function () {
					var attachment = frame.state().get('selection').first().toJSON();
					var src = (attachment.sizes && attachment.sizes.thumbnail)
						? attachment.sizes.thumbnail.url
						: attachment.url;
					idInput.value = attachment.id;
					preview.innerHTML = '<img src="' + src + '" width="84" height="84" style="border-radius:50%;object-fit:cover;display:block;border:3px solid #ddd;">';
					if (removeBtn) removeBtn.style.display = '';
				});
				frame.open();
			});

			if (removeBtn) {
				removeBtn.addEventListener('click', function (e) {
					e.preventDefault();
					idInput.value = '0';
					preview.innerHTML = '<div style="width:84px;height:84px;border-radius:50%;background:linear-gradient(135deg,#fde68a,#f59e0b);display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:800;color:#0f172a;border:3px solid #ddd;">L</div>';
					removeBtn.style.display = 'none';
				});
			}
		}());
		</script>
		<?php
	}

	// =========================================================================
	// 8. Statistiques
	// =========================================================================

	public function render_statistiques() {
		$this->check_access();

		$date_from = $this->get_filter_value( 'lsc_date_from' );
		$date_to   = $this->get_filter_value( 'lsc_date_to' );

		$rows = class_exists( 'LSC_Conversations' )
			? LSC_Conversations::get_cta_stats( array( 'date_from' => $date_from, 'date_to' => $date_to ) )
			: array();

		$labels = array(
			'rdv_particulier' => 'RDV Particulier',
			'rdv_entreprise'  => 'RDV Entreprise',
			'novaplan'        => 'NovaPlan',
			'abondance360'    => 'Abondance360',
			'autre'           => 'Autre',
		);

		// Index par type pour les cartes
		$by_type = array();
		$grand_total = 0;
		foreach ( $rows as $row ) {
			$by_type[ (string) $row['cta_type'] ] = (int) $row['total'];
			$grand_total += (int) $row['total'];
		}

		$card_types = array( 'rdv_particulier', 'rdv_entreprise', 'novaplan', 'abondance360' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Statistiques', 'lionard-simple-chat' ); ?></h1>

			<!-- Filtre periode -->
			<form method="get" style="margin:16px 0;display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
				<input type="hidden" name="page" value="lionard-chat-statistiques">
				<div>
					<label style="display:block;margin-bottom:4px;font-weight:600;"><?php esc_html_e( 'Du', 'lionard-simple-chat' ); ?></label>
					<input type="date" name="lsc_date_from" value="<?php echo esc_attr( $date_from ); ?>">
				</div>
				<div>
					<label style="display:block;margin-bottom:4px;font-weight:600;"><?php esc_html_e( 'Au', 'lionard-simple-chat' ); ?></label>
					<input type="date" name="lsc_date_to" value="<?php echo esc_attr( $date_to ); ?>">
				</div>
				<?php submit_button( __( 'Filtrer', 'lionard-simple-chat' ), 'secondary', '', false ); ?>
				<?php if ( '' !== $date_from || '' !== $date_to ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=lionard-chat-statistiques' ) ); ?>" class="button">
						<?php esc_html_e( 'Reinitialiser', 'lionard-simple-chat' ); ?>
					</a>
				<?php endif; ?>
			</form>

			<!-- Cartes totaux -->
			<div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:28px;">
				<?php foreach ( $card_types as $type ) :
					$count = $by_type[ $type ] ?? 0;
					$colors = array(
						'rdv_particulier' => array( '#1652f0', '#eff4ff' ),
						'rdv_entreprise'  => array( '#0b2f9e', '#eef2ff' ),
						'novaplan'        => array( '#059669', '#ecfdf5' ),
						'abondance360'    => array( '#d97706', '#fffbeb' ),
					);
					[$fg, $bg] = $colors[ $type ] ?? array( '#374151', '#f9fafb' );
				?>
				<div class="card" style="min-width:160px;padding:16px 20px;background:<?php echo esc_attr( $bg ); ?>;border-left:4px solid <?php echo esc_attr( $fg ); ?>;">
					<p style="margin:0 0 6px;font-size:12px;font-weight:600;color:<?php echo esc_attr( $fg ); ?>;text-transform:uppercase;letter-spacing:.04em;">
						<?php echo esc_html( $labels[ $type ] ); ?>
					</p>
					<p style="margin:0;font-size:32px;font-weight:800;color:<?php echo esc_attr( $fg ); ?>;line-height:1;">
						<?php echo esc_html( number_format_i18n( $count ) ); ?>
					</p>
					<p style="margin:4px 0 0;font-size:11px;color:#6b7280;"><?php esc_html_e( 'clics', 'lionard-simple-chat' ); ?></p>
				</div>
				<?php endforeach; ?>

				<div class="card" style="min-width:160px;padding:16px 20px;">
					<p style="margin:0 0 6px;font-size:12px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:.04em;">
						<?php esc_html_e( 'Total CTA', 'lionard-simple-chat' ); ?>
					</p>
					<p style="margin:0;font-size:32px;font-weight:800;color:#101828;line-height:1;">
						<?php echo esc_html( number_format_i18n( $grand_total ) ); ?>
					</p>
					<p style="margin:4px 0 0;font-size:11px;color:#6b7280;"><?php esc_html_e( 'clics', 'lionard-simple-chat' ); ?></p>
				</div>
			</div>

			<!-- Tableau detail -->
			<h2 style="margin-bottom:10px;"><?php esc_html_e( 'Detail par bouton', 'lionard-simple-chat' ); ?></h2>
			<?php if ( empty( $rows ) ) : ?>
				<p><?php esc_html_e( 'Aucun clic enregistre pour cette periode.', 'lionard-simple-chat' ); ?></p>
			<?php else : ?>
			<table class="wp-list-table widefat fixed striped" style="max-width:680px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Bouton', 'lionard-simple-chat' ); ?></th>
						<th style="width:120px;text-align:right;"><?php esc_html_e( 'Clics', 'lionard-simple-chat' ); ?></th>
						<th style="width:180px;"><?php esc_html_e( 'Dernier clic', 'lionard-simple-chat' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $row ) :
					$type  = (string) $row['cta_type'];
					$label = $labels[ $type ] ?? esc_html( $type );
				?>
					<tr>
						<td><strong><?php echo esc_html( $label ); ?></strong></td>
						<td style="text-align:right;font-size:16px;font-weight:700;"><?php echo esc_html( number_format_i18n( (int) $row['total'] ) ); ?></td>
						<td style="color:#667085;font-size:12px;"><?php echo esc_html( $row['last_click'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr style="border-top:2px solid #e5e7eb;">
						<th><?php esc_html_e( 'Total', 'lionard-simple-chat' ); ?></th>
						<th style="text-align:right;font-size:16px;"><?php echo esc_html( number_format_i18n( $grand_total ) ); ?></th>
						<th></th>
					</tr>
				</tfoot>
			</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// =========================================================================
	// 9. Outils
	// =========================================================================

	public function render_outils() {
		$this->check_access();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Outils', 'lionard-simple-chat' ); ?></h1>
			<p><?php esc_html_e( 'Tester OpenAI, exporter parametres, vider conversations, voir logs.', 'lionard-simple-chat' ); ?></p>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Fonctionnalite a venir.', 'lionard-simple-chat' ); ?></p></div>
		</div>
		<?php
	}
}
