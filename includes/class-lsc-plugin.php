<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once LSC_PATH . 'includes/class-lsc-knowledge.php';
require_once LSC_PATH . 'includes/class-lsc-admin.php';
require_once LSC_PATH . 'includes/class-lsc-rest.php';

class LSC_Plugin {

	const OPTION_KEY = 'lsc_settings';

	/** @var LSC_Plugin|null */
	private static $instance = null;

	/** @var bool */
	private $rendered = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function activate() {
		if ( false === get_option( self::OPTION_KEY ) ) {
			add_option( self::OPTION_KEY, self::defaults() );
		}
		LSC_Knowledge::create_table();
	}

	public static function defaults() {
		return array(
			'enabled'            => '0',
			'openai_api_key'     => '',
			'model'              => 'gpt-4o-mini',
			'temperature'        => '0.4',
			'max_output_tokens'  => '550',
			'rate_limit_count'   => '12',
			'rate_limit_window'  => '600',
			'primary_color'      => '#1652f0',
			'accent_color'       => '#f59e0b',
			'launcher_label'     => 'Parler a Lionard',
			'panel_title'        => 'Lionard',
			'panel_subtitle'     => 'Assistant virtuel dettes.ca',
			'greeting'           => 'Bonjour, je suis Lionard. Je peux vous aider a faire le point sur votre situation et vous orienter vers la bonne prochaine etape.',
			'prompt'             => self::default_prompt(),
			'allowed_cta_hosts'  => "www.stag.dettes.net\nstag.dettes.net\nwww.dettes.ca\ndettes.ca\nabondance360.com",
		);
	}

	public static function get_settings() {
		$settings = get_option( self::OPTION_KEY, array() );
		$settings = is_array( $settings ) ? $settings : array();
		return wp_parse_args( $settings, self::defaults() );
	}

	public static function default_prompt() {
		return <<<'PROMPT'
Tu es Lionard, l'assistant virtuel de dettes.ca.

dettes.ca est une plateforme de Groupe Leblanc Syndic qui accompagne les particuliers et les entreprises au Quebec dans la comprehension de leurs difficultes financieres et de leurs dettes, afin de les orienter vers une conseillere humaine.

Role principal: prequalification et orientation vers un rendez-vous pertinent.

Regles essentielles:
- Tu ne remplaces jamais une conseillere.
- Tu ne donnes jamais de diagnostic, de solution personnalisee, de calcul financier personnalise, de plan budgetaire ou de plan de remboursement.
- Tu parles toujours au vouvoiement.
- Tu reponds en francais naturel, professionnel, direct et empathique.
- Tes reponses font 3 a 4 phrases maximum.
- Tu poses une seule question uniquement si une information utile manque vraiment.
- Tu ne repetes jamais une question dont la reponse est deja claire.
- Tu consideres toutes les informations deja donnees, meme dans le premier message.

Rendez-vous:
- Avant d'afficher un bouton, determine si la dette est personnelle ou liee a une entreprise.
- Dette personnelle: cartes de credit, marge personnelle, pret personnel, pret etudiant, pret auto personnel, loyer, impot personnel, recouvrement personnel.
- Dette entreprise: fournisseur, taxes, DAS, salaires, employes, bail commercial, caution personnelle liee a l'entreprise, dette commerciale, entreprise active ou fermee.
- Si le type n'est pas clair, demande seulement: "Est-ce que cette dette est liee a vous personnellement ou a une entreprise ?"
- Si au moins 5 informations utiles sont deja presentes, ne pose pas une autre question: passe au rendez-vous.

Format bouton obligatoire:
[[button:Libelle|URL]]

Bouton dette personnelle:
[[button:Prendre rendez-vous|https://www.stag.dettes.net/formulaire-particulier/]]

Bouton dette entreprise:
[[button:Prendre rendez-vous entreprise|https://www.stag.dettes.net/formulaire-entreprise/]]

Si la personne demande un calcul, un budget, une simulation ou un plan de remboursement, ne calcule pas. Oriente vers:
[[button:Ouvrir NovaPlan|https://www.dettes.ca/novaplan/]]

Si la personne veut de l'education financiere ou du contenu pour avancer a son rythme, propose:
[[button:Ouvrir Abondance360|https://abondance360.com]]

Crise:
Si la personne exprime une intention suicidaire, une detresse grave ou un danger immediat, arrete la qualification et reponds uniquement:
"Votre securite passe avant tout. Si vous etes au Canada, appelez ou textez le 988 maintenant. Si vous etes en danger immediat, appelez le 911."
PROMPT;
	}

	private function __construct() {
		LSC_Knowledge::create_table();
		new LSC_Admin();
		new LSC_REST();

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_widget' ) );
		add_shortcode( 'lionard_simple_chat', array( $this, 'render_shortcode' ) );
	}

	public function enqueue_front_assets() {
		$settings = self::get_settings();
		if ( is_admin() || empty( $settings['enabled'] ) ) {
			return;
		}

		wp_enqueue_style(
			'lionard-simple-chat',
			LSC_URL . 'assets/css/front.css',
			array(),
			LSC_VERSION
		);

		wp_enqueue_script(
			'lionard-simple-chat',
			LSC_URL . 'assets/js/front.js',
			array(),
			LSC_VERSION,
			true
		);

		wp_localize_script(
			'lionard-simple-chat',
			'LionardSimpleChat',
			array(
				'restUrl'      => esc_url_raw( rest_url( 'lionard-simple/v1' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'primaryColor' => sanitize_hex_color( $settings['primary_color'] ),
				'accentColor'  => sanitize_hex_color( $settings['accent_color'] ),
				'greeting'     => sanitize_textarea_field( $settings['greeting'] ),
				'allowedHosts' => self::allowed_hosts( $settings ),
				'strings'      => array(
					'input'   => __( 'Ecrire un message...', 'lionard-simple-chat' ),
					'send'    => __( 'Envoyer', 'lionard-simple-chat' ),
					'restart' => __( 'Recommencer', 'lionard-simple-chat' ),
					'error'   => __( 'Le service est momentanément indisponible, revenez plus tard.', 'lionard-simple-chat' ),
				),
			)
		);
	}

	public static function allowed_hosts( array $settings ) {
		$raw = (string) ( $settings['allowed_cta_hosts'] ?? '' );
		$hosts = preg_split( '/[\r\n,]+/', $raw );
		$hosts = is_array( $hosts ) ? $hosts : array();
		$hosts = array_map(
			static function ( $host ) {
				$host = strtolower( trim( (string) $host ) );
				$host = preg_replace( '/^https?:\/\//', '', $host );
				$host = preg_replace( '/\/.*$/', '', $host );
				return sanitize_text_field( $host );
			},
			$hosts
		);
		$hosts = array_values( array_filter( array_unique( $hosts ) ) );
		return $hosts;
	}

	public function render_widget() {
		$settings = self::get_settings();
		if ( is_admin() || empty( $settings['enabled'] ) || $this->rendered ) {
			return;
		}

		$this->rendered = true;
		$this->widget_markup( $settings );
	}

	public function render_shortcode() {
		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return '';
		}

		ob_start();
		if ( ! $this->rendered ) {
			$this->rendered = true;
			$this->widget_markup( $settings );
		}
		return (string) ob_get_clean();
	}

	private function widget_markup( array $settings ) {
		$shell_style = sprintf(
			'--lsc-primary:%s;--lsc-accent:%s;',
			esc_attr( sanitize_hex_color( $settings['primary_color'] ) ?: '#1652f0' ),
			esc_attr( sanitize_hex_color( $settings['accent_color'] ) ?: '#f59e0b' )
		);
		?>
		<div class="lsc-shell" style="<?php echo esc_attr( $shell_style ); ?>">
			<button type="button" class="lsc-launcher" aria-expanded="false" aria-controls="lsc-panel">
				<span class="lsc-launcher__pulse" aria-hidden="true"></span>
				<span class="lsc-launcher__label"><?php echo esc_html( $settings['launcher_label'] ); ?></span>
			</button>

			<section id="lsc-panel" class="lsc-panel" hidden>
				<button type="button" class="lsc-close" aria-label="<?php esc_attr_e( 'Fermer', 'lionard-simple-chat' ); ?>">&times;</button>
				<header class="lsc-header">
					<div class="lsc-avatar" aria-hidden="true">L</div>
					<div class="lsc-header__copy">
						<strong><?php echo esc_html( $settings['panel_title'] ); ?></strong>
						<span><?php echo esc_html( $settings['panel_subtitle'] ); ?></span>
					</div>
				</header>
				<div class="lsc-messages" role="log" aria-live="polite"></div>
				<form class="lsc-form">
					<input class="lsc-input" type="text" maxlength="1200" autocomplete="off" placeholder="<?php esc_attr_e( 'Ecrire un message...', 'lionard-simple-chat' ); ?>" />
					<button class="lsc-send" type="submit" aria-label="<?php esc_attr_e( 'Envoyer', 'lionard-simple-chat' ); ?>">
						<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
							<path d="M3 11.5 21 3l-6.8 18-2.9-6.6L3 11.5z"></path>
						</svg>
					</button>
				</form>
				<div class="lsc-footer">
					<button type="button" class="lsc-restart"><?php esc_html_e( 'Recommencer', 'lionard-simple-chat' ); ?></button>
				</div>
			</section>
		</div>
		<?php
	}
}

