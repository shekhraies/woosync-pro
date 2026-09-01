<?php
/**
 * WooSync Admin Interface & AJAX Dispatcher
 *
 * @package WooSync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WooSync_Admin {

    /**
     * Initialize admin hooks.
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // AJAX endpoints
        add_action( 'wp_ajax_woosync_fetch_products', array( $this, 'ajax_fetch_products' ) );
        add_action( 'wp_ajax_woosync_sync_single', array( $this, 'ajax_sync_single' ) );
    }

    /**
     * Register Admin Menu.
     */
    public function add_menu() {
        add_menu_page(
            __( 'WooSync Pro', 'woosync-pro' ),
            __( 'WooSync Pro', 'woosync-pro' ),
            'manage_woocommerce',
            'woosync-pro',
            array( $this, 'render_page' ),
            'dashicons-update-alt',
            56
        );
    }

    /**
     * Enqueue Admin Styles and Scripts.
     *
     * @param string $hook
     */
    public function enqueue_assets( $hook ) {
        if ( $hook !== 'toplevel_page_woosync-pro' ) {
            return;
        }

        wp_enqueue_style(
            'woosync-admin-css',
            WOOSYNC_URL . 'assets/css/admin.css',
            array(),
            WOOSYNC_VERSION
        );

        wp_enqueue_script(
            'woosync-admin-js',
            WOOSYNC_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            WOOSYNC_VERSION,
            true
        );

        wp_localize_script( 'woosync-admin-js', 'woosync_vars', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'woosync_ajax_nonce' ),
            'i18n'     => array(
                'fetching'         => __( 'Fetching products...', 'woosync-pro' ),
                'fetch_btn'        => __( 'Fetch Products', 'woosync-pro' ),
                'select_at_least'  => __( 'Please select at least one product to sync.', 'woosync-pro' ),
                'syncing'          => __( 'Syncing product %1$d of %2$d...', 'woosync-pro' ),
                'sync_complete'    => __( 'Sync Completed! Processed %1$d products (%2$d created, %3$d updated, %4$d failed).', 'woosync-pro' ),
                'server_error'     => __( 'Server communication error occurred.', 'woosync-pro' ),
                'status_new'       => __( 'New', 'woosync-pro' ),
                'status_synced'    => __( 'Synced', 'woosync-pro' ),
                'btn_sync_now'     => __( 'Sync', 'woosync-pro' ),
                'btn_resync'       => __( 'Update', 'woosync-pro' ),
                'confirm_bulk'     => __( 'Are you ready to sync the selected products to WooCommerce?', 'woosync-pro' ),
            ),
        ) );
    }

    /**
     * Render Admin Dashboard Page.
     */
    public function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'woosync-pro' ) );
        }
        ?>
        <div class="wrap woosync-wrap">
            <div class="woosync-header">
                <h1><span class="dashicons dashicons-update-alt"></span> WooSync Pro</h1>
                <p class="woosync-subtitle"><?php esc_html_e( 'Connect to Shopify or WordPress / WooCommerce APIs, inspect catalog items, and sync them seamlessly into your WooCommerce store.', 'woosync-pro' ); ?></p>
            </div>

            <!-- STEP 1: API Configuration -->
            <div class="woosync-card" id="card-api-config">
                <div class="woosync-card-header">
                    <h2><span class="step-badge">1</span> <?php esc_html_e( 'Select API Source & Connect', 'woosync-pro' ); ?></h2>
                </div>
                <div class="woosync-card-body">
                    <!-- Source Type Selector Tabs -->
                    <div class="woosync-source-selector">
                        <label class="source-option active" data-target="panel-shopify-json">
                            <input type="radio" name="api_type" value="shopify_json" checked>
                            <span class="source-title"><strong>Shopify</strong> (Public Storefront)</span>
                            <span class="source-desc"><?php esc_html_e( 'Direct products.json or single product URL', 'woosync-pro' ); ?></span>
                        </label>
                        <label class="source-option" data-target="panel-woocommerce-store-api">
                            <input type="radio" name="api_type" value="woocommerce_store_api">
                            <span class="source-title"><strong>WooCommerce</strong> (Public Store API)</span>
                            <span class="source-desc"><?php esc_html_e( 'Public /wp-json/wc/store/v1/products URL', 'woosync-pro' ); ?></span>
                        </label>
                    </div>

                    <!-- Panel: Shopify Public JSON -->
                    <div class="source-panel active" id="panel-shopify-json">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="shopify_url"><?php esc_html_e( 'Shopify Store or products.json URL', 'woosync-pro' ); ?> <span class="required">*</span></label></th>
                                <td>
                                    <input type="url" id="shopify_url" class="regular-text" placeholder="https://shopatpulse.com/products/ultimate-hands-free-dog-leash-with-shock-absorbing-bungee-zipper-pouch.json" style="width: 100%; max-width: 650px;">
                                    <p class="description"><?php esc_html_e( 'Enter full catalog URL (e.g. https://shopatpulse.com/products.json) or a single product URL (e.g. https://shopatpulse.com/products/handle.json or https://store.myshopify.com).', 'woosync-pro' ); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Panel: WooCommerce Public Store API -->
                    <div class="source-panel" id="panel-woocommerce-store-api" style="display:none;">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="wc_store_api_url"><?php esc_html_e( 'WooCommerce Store API Endpoint', 'woosync-pro' ); ?> <span class="required">*</span></label></th>
                                <td>
                                    <input type="url" id="wc_store_api_url" class="regular-text" placeholder="https://flattered-ensure-amendment.ngrok-free.dev/wp-json/wc/store/v1/products" style="width: 100%; max-width: 650px;">
                                    <p class="description"><?php esc_html_e( 'Enter the public Store API products endpoint (e.g. https://example.com/wp-json/wc/store/v1/products) or single product endpoint.', 'woosync-pro' ); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="woosync-fetch-actions">
                        <button type="button" class="button button-primary button-hero" id="btn-fetch-products">
                            <span class="dashicons dashicons-download" style="margin-top:4px;"></span> <?php esc_html_e( 'Fetch Products', 'woosync-pro' ); ?>
                        </button>
                        <span class="spinner" id="fetch-spinner"></span>
                        <div id="fetch-alert" class="woosync-alert" style="display:none;"></div>
                    </div>
                </div>
            </div>

            <!-- STEP 2: Products Table & Selective Sync -->
            <div class="woosync-card" id="card-product-list" style="display:none;">
                <div class="woosync-card-header flex-header">
                    <h2><span class="step-badge">2</span> <?php esc_html_e( 'Select Products to Sync', 'woosync-pro' ); ?></h2>
                    <div class="woosync-search-wrap">
                        <input type="text" id="product-search" placeholder="<?php esc_attr_e( 'Search by title or SKU...', 'woosync-pro' ); ?>" class="regular-text">
                    </div>
                </div>

                <div class="woosync-card-body">
                    <!-- Top Action Bar -->
                    <div class="woosync-action-bar">
                        <div class="bulk-controls">
                            <button type="button" class="button button-primary" id="btn-sync-selected">
                                <span class="dashicons dashicons-update" style="margin-top:3px;"></span> <?php esc_html_e( 'Sync Selected Products', 'woosync-pro' ); ?>
                            </button>
                            <button type="button" class="button" id="btn-select-unsynced"><?php esc_html_e( 'Select All New (Unsynced)', 'woosync-pro' ); ?></button>
                            <button type="button" class="button" id="btn-deselect-all"><?php esc_html_e( 'Deselect All', 'woosync-pro' ); ?></button>
                        </div>
                        <div class="stats-summary" id="stats-summary">
                            <!-- Populated dynamically -->
                        </div>
                    </div>

                    <!-- Progress Section -->
                    <div id="sync-progress-wrap" class="sync-progress-box" style="display:none;">
                        <div class="progress-bar-container">
                            <div class="progress-bar-inner" id="progress-bar-inner" style="width: 0%;"></div>
                        </div>
                        <div class="progress-text-line">
                            <span id="progress-status-text"><?php esc_html_e( 'Preparing sync...', 'woosync-pro' ); ?></span>
                            <span id="progress-percentage-text">0%</span>
                        </div>
                    </div>

                    <!-- Products Table -->
                    <div class="woosync-table-responsive">
                        <table class="wp-list-table widefat fixed striped" id="woosync-table">
                            <thead>
                                <tr>
                                    <td class="col-cb check-column"><input type="checkbox" id="select-all-cb"></td>
                                    <th class="col-thumb"><?php esc_html_e( 'Image', 'woosync-pro' ); ?></th>
                                    <th class="col-title"><?php esc_html_e( 'Product Details', 'woosync-pro' ); ?></th>
                                    <th class="col-source"><?php esc_html_e( 'Source ID', 'woosync-pro' ); ?></th>
                                    <th class="col-sku"><?php esc_html_e( 'SKU', 'woosync-pro' ); ?></th>
                                    <th class="col-price"><?php esc_html_e( 'Price', 'woosync-pro' ); ?></th>
                                    <th class="col-status"><?php esc_html_e( 'Status in WooCommerce', 'woosync-pro' ); ?></th>
                                    <th class="col-action"><?php esc_html_e( 'Action', 'woosync-pro' ); ?></th>
                                </tr>
                            </thead>
                            <tbody id="woosync-table-body">
                                <!-- Populated dynamically by JS -->
                            </tbody>
                        </table>
                    </div>

                    <!-- Activity Log -->
                    <div class="woosync-log-box" id="woosync-log-box" style="display:none;">
                        <h4><?php esc_html_e( 'Sync Activity Log', 'woosync-pro' ); ?></h4>
                        <ul id="woosync-log-list"></ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX Handler: Fetch remote products.
     */
    public function ajax_fetch_products() {
        check_ajax_referer( 'woosync_ajax_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'woosync-pro' ) );
        }

        $config = isset( $_POST['config'] ) ? (array) $_POST['config'] : array();

        $products = WooSync_Fetcher::fetch_products( $config );

        if ( is_wp_error( $products ) ) {
            wp_send_json_error( $products->get_error_message() );
        }

        wp_send_json_success( $products );
    }

    /**
     * AJAX Handler: Sync a single product.
     */
    public function ajax_sync_single() {
        check_ajax_referer( 'woosync_ajax_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'woosync-pro' ) );
        }

        $product_raw = isset( $_POST['product'] ) ? wp_unslash( $_POST['product'] ) : '';
        $product_data = json_decode( $product_raw, true );

        if ( ! $product_data || ! is_array( $product_data ) ) {
            wp_send_json_error( __( 'Invalid product data format.', 'woosync-pro' ) );
        }

        $result = WooSync_Importer::sync_product( $product_data );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }
}
