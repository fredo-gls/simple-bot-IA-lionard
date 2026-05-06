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

		// Allowed CTA hosts
		if ( array_key_exists( 'allowed_cta_hosts', $raw ) ) {
			$out['allowed_cta_hosts'] = $this->sanitize_hosts( (string) $raw['allowed_cta_hosts'] );
		} else {
			$out['allowed_cta_hosts'] = $previous['allowed_cta_hosts'] ?? $defaults['allowed_cta_hosts'];
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

			if ( 0 === $id ) {
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
		}
	}

	/**
	 * Parse a decoded JSON array/object into normalized KB entries.
	 *
	 * Formats accepted:
	 *   1. Standard  : [{"type":"faq","title":"Q?","content":"R."},...]
	 *   2. question/answer: [{"question":"Q?","answer":"R."},...]
	 *   3. q/a court : [{"q":"Q?","a":"R."},...]
	 *   4. Objet clé/valeur : {"Q?":"R.","Titre":"Texte",...}
	 */
	private function parse_json_entries( array $data ): array {
		$entries      = array();
		$valid_types  = array( 'faq', 'text', 'url' );

		// Format 4 : objet clé => valeur (les clés ne sont pas des indices numériques)
		$keys = array_keys( $data );
		$is_map = ! empty( $keys ) && ! is_int( $keys[0] );
		if ( $is_map ) {
			foreach ( $data as $title => $content ) {
				if ( is_string( $title ) && is_string( $content ) && '' !== trim( $content ) ) {
					$entries[] = array( 'type' => 'faq', 'title' => $title, 'content' => $content );
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

			// Format 1 : champs standards
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
					'title'   => sanitize_text_field( $title ),
					'content' => sanitize_textarea_field( $content ),
				);
			}
		}

		return $entries;
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

		$notice    = sanitize_key( wp_unslash( (string) ( $_GET['kb_notice'] ?? '' ) ) );
		$imported_count = absint( $_GET['kb_count'] ?? 0 );
		$notices = array(
			'added'        => array( 'success', 'Entree ajoutee.' ),
			'updated'      => array( 'success', 'Entree mise a jour.' ),
			'deleted'      => array( 'success', 'Entree supprimee.' ),
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
			<?php if ( empty( $entries ) ) : ?>
				<p><?php esc_html_e( 'Aucune entree. Ajoutez votre premiere entree ci-dessus.', 'lionard-simple-chat' ); ?></p>
			<?php else : ?>
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
