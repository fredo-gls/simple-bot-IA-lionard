<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LSC_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
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

	public function sanitize_settings( $raw ) {
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

		// Allowed CTA hosts
		if ( array_key_exists( 'allowed_cta_hosts', $raw ) ) {
			$out['allowed_cta_hosts'] = $this->sanitize_hosts( (string) $raw['allowed_cta_hosts'] );
		} else {
			$out['allowed_cta_hosts'] = $previous['allowed_cta_hosts'] ?? $defaults['allowed_cta_hosts'];
		}

		return $out;
	}

	private function sanitize_hosts( $value ) {
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
	// 4. Connaissances
	// =========================================================================

	public function render_connaissances() {
		$this->check_access();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Connaissances', 'lionard-simple-chat' ); ?></h1>
			<p><?php esc_html_e( 'Ajoutez des FAQ, textes, PDF ou URLs pour enrichir les reponses de Lionard.', 'lionard-simple-chat' ); ?></p>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Fonctionnalite a venir.', 'lionard-simple-chat' ); ?></p></div>
		</div>
		<?php
	}

	// =========================================================================
	// 5. Conversations
	// =========================================================================

	public function render_conversations() {
		$this->check_access();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Conversations', 'lionard-simple-chat' ); ?></h1>
			<p><?php esc_html_e( 'Historique des echanges, type detecte, RDV propose, clique, refus et erreurs.', 'lionard-simple-chat' ); ?></p>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Fonctionnalite a venir.', 'lionard-simple-chat' ); ?></p></div>
		</div>
		<?php
	}

	// =========================================================================
	// 6. Rendez-vous
	// =========================================================================

	public function render_rdv() {
		$this->check_access();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Rendez-vous', 'lionard-simple-chat' ); ?></h1>
			<p><?php esc_html_e( 'Configurez les liens RDV personnel et entreprise, les textes des boutons et les regles de closing.', 'lionard-simple-chat' ); ?></p>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Fonctionnalite a venir.', 'lionard-simple-chat' ); ?></p></div>
		</div>
		<?php
	}

	// =========================================================================
	// 7. Apparence
	// =========================================================================

	public function render_apparence() {
		$this->check_access();
		$settings = LSC_Plugin::get_settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Apparence', 'lionard-simple-chat' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'lsc_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lsc_primary_color"><?php esc_html_e( 'Couleur principale', 'lionard-simple-chat' ); ?></label></th>
						<td>
							<input id="lsc_primary_color" type="color" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[primary_color]" value="<?php echo esc_attr( $settings['primary_color'] ); ?>" style="width:50px;height:34px;padding:2px;cursor:pointer;">
							<code style="margin-left:8px;vertical-align:middle;"><?php echo esc_html( $settings['primary_color'] ); ?></code>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lsc_accent_color"><?php esc_html_e( 'Couleur accent', 'lionard-simple-chat' ); ?></label></th>
						<td>
							<input id="lsc_accent_color" type="color" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[accent_color]" value="<?php echo esc_attr( $settings['accent_color'] ); ?>" style="width:50px;height:34px;padding:2px;cursor:pointer;">
							<code style="margin-left:8px;vertical-align:middle;"><?php echo esc_html( $settings['accent_color'] ); ?></code>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lsc_launcher_label"><?php esc_html_e( 'Bouton flottant', 'lionard-simple-chat' ); ?></label></th>
						<td><input id="lsc_launcher_label" class="regular-text" type="text" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[launcher_label]" value="<?php echo esc_attr( $settings['launcher_label'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="lsc_panel_title"><?php esc_html_e( 'Titre', 'lionard-simple-chat' ); ?></label></th>
						<td><input id="lsc_panel_title" class="regular-text" type="text" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[panel_title]" value="<?php echo esc_attr( $settings['panel_title'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="lsc_panel_subtitle"><?php esc_html_e( 'Sous-titre', 'lionard-simple-chat' ); ?></label></th>
						<td><input id="lsc_panel_subtitle" class="regular-text" type="text" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[panel_subtitle]" value="<?php echo esc_attr( $settings['panel_subtitle'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="lsc_greeting"><?php esc_html_e( 'Message d\'accueil', 'lionard-simple-chat' ); ?></label></th>
						<td><textarea id="lsc_greeting" class="large-text" rows="3" name="<?php echo esc_attr( LSC_Plugin::OPTION_KEY ); ?>[greeting]"><?php echo esc_textarea( $settings['greeting'] ); ?></textarea></td>
					</tr>
				</table>
				<?php submit_button( __( 'Enregistrer', 'lionard-simple-chat' ) ); ?>
			</form>
		</div>
		<?php
	}

	// =========================================================================
	// 8. Statistiques
	// =========================================================================

	public function render_statistiques() {
		$this->check_access();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Statistiques', 'lionard-simple-chat' ); ?></h1>
			<p><?php esc_html_e( 'Conversations, clics RDV, taux de closing, NovaPlan et Abondance360.', 'lionard-simple-chat' ); ?></p>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Fonctionnalite a venir.', 'lionard-simple-chat' ); ?></p></div>
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
