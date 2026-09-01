<?php
/**
 * Plugin Name: WooSync Pro
 * Plugin URI: https://github.com/shekhraies/woosync-pro
 * Description: Connect to Shopify or WordPress/WooCommerce APIs, preview remote catalog items, and sync them directly into your WooCommerce store with intelligent create/update (upsert) support.
 * Version: 1.2.0
 * Author: Shekh Raies
 * Text Domain: woosync-pro
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin Constants
define( 'WOOSYNC_VERSION', '1.2.0' );
define( 'WOOSYNC_FILE', __FILE__ );
define( 'WOOSYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'WOOSYNC_URL', plugin_dir_url( __FILE__ ) );

/**
 * Main WooSync_Pro Plugin Bootstrap Class
 */
class WooSync_Pro {

    /**
     * Singleton instance.
     *
     * @var WooSync_Pro|null
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return WooSync_Pro
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        register_activation_hook( WOOSYNC_FILE, array( $this, 'activate_plugin' ) );
        add_action( 'plugins_loaded', array( $this, 'init_plugin' ) );
    }

    /**
     * Plugin activation hook.
     */
    public function activate_plugin() {
        if ( ! class_exists( 'WooCommerce' ) && ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins', array() ) ) ) ) {
            deactivate_plugins( plugin_basename( WOOSYNC_FILE ) );
            wp_die(
                esc_html__( 'WooSync Pro requires WooCommerce to be installed and active.', 'woosync-pro' ),
                esc_html__( 'Plugin Dependency Error', 'woosync-pro' ),
                array( 'back_link' => true )
            );
        }
    }

    /**
     * Initialize plugin components once all plugins are loaded.
     */
    public function init_plugin() {
        // Verify WooCommerce is active
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array( $this, 'render_wc_missing_notice' ) );
            return;
        }

        $this->load_dependencies();

        // Frontend hooks: Hide User Type on product details page
        add_action( 'wp_head', array( $this, 'hide_user_type_frontend_css' ) );
        add_action( 'wp_footer', array( $this, 'hide_user_type_frontend_js' ) );

        // Initialize Admin
        if ( is_admin() ) {
            new WooSync_Admin();
        }
    }

    /**
     * Load required class files.
     */
    private function load_dependencies() {
        require_once WOOSYNC_PATH . 'includes/class-woosync-fetcher.php';
        require_once WOOSYNC_PATH . 'includes/class-woosync-importer.php';
        require_once WOOSYNC_PATH . 'includes/class-woosync-admin.php';
    }

    /**
     * Render WooCommerce missing notice.
     */
    public function render_wc_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><strong><?php esc_html_e( 'WooSync Pro:', 'woosync-pro' ); ?></strong> <?php esc_html_e( 'WooCommerce must be installed and activated for WooSync Pro to function.', 'woosync-pro' ); ?></p>
        </div>
        <?php
    }

    /**
     * Hide User Type attribute and variation row from product details page.
     */
    public function hide_user_type_frontend_css() {
        if ( is_singular( 'product' ) ) {
            ?>
            <style id="woosync-hide-user-type-css">
                /* Hide User Type from Additional Information specifications tab */
                .woocommerce-product-attributes-item--attribute_pa_user-type,
                .woocommerce-product-attributes-item--attribute_user-type,
                .woocommerce-product-attributes-item--attribute_pa_user_type,
                .woocommerce-product-attributes-item--attribute_user_type {
                    display: none !important;
                }

                /* Hide User Type variation form row on product page */
                .variations tr:has(select[name*="user-type"]),
                .variations tr:has(select[name*="user_type"]),
                .variations tr:has(select[name*="usertype"]),
                .variations tr:has([data-attribute_name*="user-type"]),
                .variations tr:has([data-attribute_name*="user_type"]),
                .variations div:has(select[name*="user-type"]),
                .variations div:has(select[name*="user_type"]) {
                    display: none !important;
                }
            </style>
            <?php
        }
    }

    /**
     * Fallback script to ensure User Type variation row is hidden across themes.
     */
    public function hide_user_type_frontend_js() {
        if ( is_singular( 'product' ) ) {
            ?>
            <script id="woosync-hide-user-type-js">
                (function() {
                    function hideUserTypeRow() {
                        var selects = document.querySelectorAll('select[name*="user-type"], select[name*="user_type"], select[name*="usertype"]');
                        for (var i = 0; i < selects.length; i++) {
                            var row = selects[i].closest('tr, .form-row, .variations_row, .wc-variation-row');
                            if (row) {
                                row.style.display = 'none';
                            }
                        }
                    }
                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', hideUserTypeRow);
                    } else {
                        hideUserTypeRow();
                    }
                })();
            </script>
            <?php
        }
    }
}

// Bootstrap Plugin
WooSync_Pro::get_instance();