<?php
/**
 * Plugin Name:       Mugiwara LMS – Formations Google Drive
 * Description:       Transforme la boutique WooCommerce en plateforme de formation : chaque achat crée un compte et ouvre l'accès à un espace élève (lecteur de leçons, ressources, téléchargements), avec des contenus hébergés sur Google Drive.
 * Version:           1.2.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Mugiwara Academy
 * Text Domain:       mugiwara-lms
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'MLA_VERSION', '1.2.1' );
define( 'MLA_FILE', __FILE__ );
define( 'MLA_DIR', plugin_dir_path( __FILE__ ) );
define( 'MLA_URL', plugin_dir_url( __FILE__ ) );

require_once MLA_DIR . 'includes/class-mla-drive.php';
require_once MLA_DIR . 'includes/class-mla-courses.php';
require_once MLA_DIR . 'includes/class-mla-access.php';
require_once MLA_DIR . 'includes/class-mla-frontend.php';
require_once MLA_DIR . 'includes/class-mla-admin.php';
require_once MLA_DIR . 'includes/class-mla-rest.php';

/**
 * Lit un réglage du plugin.
 *
 * @param string $key     Nom du réglage.
 * @param mixed  $default Valeur par défaut.
 * @return mixed
 */
function mla_option( $key, $default = '' ) {
	$options = get_option( 'mla_settings', array() );
	return isset( $options[ $key ] ) && '' !== $options[ $key ] ? $options[ $key ] : $default;
}

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MLA_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		MLA_Courses::init();
		MLA_Admin::init();
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Mugiwara LMS nécessite WooCommerce.', 'mugiwara-lms' ) . '</p></div>';
				}
			);
			return;
		}
		MLA_Access::init();
		MLA_Frontend::init();
		MLA_Rest::init();
	}
);

register_activation_hook(
	__FILE__,
	function () {
		MLA_Courses::register_post_type();
		MLA_Frontend::add_endpoints();
		flush_rewrite_rules();

		// Un achat doit toujours créer un compte : pas de commande invité.
		update_option( 'woocommerce_enable_guest_checkout', 'no' );
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'yes' );
		update_option( 'woocommerce_registration_generate_password', 'yes' );
		update_option( 'woocommerce_registration_generate_username', 'yes' );

		if ( false === get_option( 'mla_settings' ) ) {
			add_option(
				'mla_settings',
				array(
					'accent'            => '#1E4DF0',
					'grant_processing'  => 'yes',
					'redirect_dashboard' => 'yes',
					'block_repurchase'  => 'yes',
				)
			);
		}
	}
);

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
