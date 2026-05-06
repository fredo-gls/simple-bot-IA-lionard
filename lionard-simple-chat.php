<?php
/**
 * Plugin Name: Lionard Simple Chat
 * Description: Chatbot WordPress autonome pour dettes.ca, sans backend Laravel. Appelle OpenAI cote serveur avec un widget inspire du style Abondance360.
 * Version: 1.0.0
 * Author: dettes.ca
 * Text Domain: lionard-simple-chat
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LSC_VERSION', '1.0.0' );
define( 'LSC_FILE', __FILE__ );
define( 'LSC_PATH', plugin_dir_path( __FILE__ ) );
define( 'LSC_URL', plugin_dir_url( __FILE__ ) );

require_once LSC_PATH . 'includes/class-lsc-plugin.php';

register_activation_hook( __FILE__, array( 'LSC_Plugin', 'activate' ) );

LSC_Plugin::instance();

