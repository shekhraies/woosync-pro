<?php
/**
 * WooSync API Fetcher
 *
 * Handles API connections to Shopify and WordPress / WooCommerce,
 * and normalizes product data into a standardized structure.
 *
 * @package WooSync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WooSync_Fetcher {

    /**
     * Fetch products based on API configuration.
     *
     * @param array $config Configuration parameters.
     * @return array|WP_Error Normalized array of products or WP_Error.
     */
    public static function fetch_products( $config ) {
        $api_type = isset( $config['api_type'] ) ? sanitize_text_field( $config['api_type'] ) : 'shopify_json';

        switch ( $api_type ) {
            case 'shopify_json':
                return self::fetch_shopify_json( $config );

            case 'shopify_admin':
                return self::fetch_shopify_admin( $config );

            case 'wordpress_wc':
                return self::fetch_wordpress_wc( $config );

            default:
                return new WP_Error( 'invalid_api_type', __( 'Invalid API type specified.', 'woosync-pro' ) );
        }
    }

    /**
     * Fetch products from Shopify Public Storefront JSON.
     *
     * @param array $config
     * @return array|WP_Error
     */
    private static function fetch_shopify_json( $config ) {
        $url = isset( $config['shopify_url'] ) ? esc_url_raw( trim( $config['shopify_url'] ) ) : '';
        if ( empty( $url ) ) {
            return new WP_Error( 'missing_url', __( 'Please provide a valid Shopify Store URL or products.json URL.', 'woosync-pro' ) );
        }

        // Ensure URL points to products.json
        if ( ! preg_match( '/products\.json(\?.*)?$/i', $url ) ) {
            $url = rtrim( $url, '/' ) . '/products.json?limit=50';
        }

        $response = wp_remote_get( $url, array(
            'timeout'    => 30,
            'user-agent' => 'WooSync Pro Importer/' . WOOSYNC_VERSION,
            'headers'    => array(
                'Accept' => 'application/json',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return new WP_Error( 'http_error', sprintf( __( 'Shopify returned HTTP status %d', 'woosync-pro' ), $code ) );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( empty( $data ) || ! isset( $data['products'] ) || ! is_array( $data['products'] ) ) {
            return new WP_Error( 'no_products', __( 'No products found at the specified Shopify endpoint.', 'woosync-pro' ) );
        }

        return self::normalize_shopify_products( $data['products'] );
    }

    /**
     * Fetch products from Shopify Admin REST API.
     *
     * @param array $config
     * @return array|WP_Error
     */
    private static function fetch_shopify_admin( $config ) {
        $store_url    = isset( $config['admin_store_url'] ) ? sanitize_text_field( trim( $config['admin_store_url'] ) ) : '';
        $access_token = isset( $config['admin_access_token'] ) ? sanitize_text_field( trim( $config['admin_access_token'] ) ) : '';
        $api_version  = isset( $config['admin_api_version'] ) && ! empty( $config['admin_api_version'] ) ? sanitize_text_field( $config['admin_api_version'] ) : '2024-01';

        if ( empty( $store_url ) || empty( $access_token ) ) {
            return new WP_Error( 'missing_credentials', __( 'Store URL and Admin API Access Token are required.', 'woosync-pro' ) );
        }

        // Clean store URL (remove protocol if user included it)
        $store_domain = preg_replace( '#^https?://#', '', rtrim( $store_url, '/' ) );
        $endpoint     = 'https://' . $store_domain . '/admin/api/' . $api_version . '/products.json?limit=50';

        $response = wp_remote_get( $endpoint, array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type'           => 'application/json',
                'X-Shopify-Access-Token' => $access_token,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            $body = wp_remote_retrieve_body( $response );
            $err  = json_decode( $body, true );
            $msg  = isset( $err['errors'] ) ? ( is_array( $err['errors'] ) ? json_encode( $err['errors'] ) : $err['errors'] ) : "HTTP Status: $code";
            return new WP_Error( 'shopify_api_error', sprintf( __( 'Shopify Admin API error: %s', 'woosync-pro' ), $msg ) );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( empty( $data['products'] ) || ! is_array( $data['products'] ) ) {
            return new WP_Error( 'no_products', __( 'No products found in this Shopify store.', 'woosync-pro' ) );
        }

        return self::normalize_shopify_products( $data['products'] );
    }

    /**
     * Fetch products from remote WordPress / WooCommerce REST API.
     *
     * @param array $config
     * @return array|WP_Error
     */
    private static function fetch_wordpress_wc( $config ) {
        $store_url       = isset( $config['wp_store_url'] ) ? esc_url_raw( trim( $config['wp_store_url'] ) ) : '';
        $consumer_key    = isset( $config['wp_consumer_key'] ) ? sanitize_text_field( trim( $config['wp_consumer_key'] ) ) : '';
        $consumer_secret = isset( $config['wp_consumer_secret'] ) ? sanitize_text_field( trim( $config['wp_consumer_secret'] ) ) : '';

        if ( empty( $store_url ) ) {
            return new WP_Error( 'missing_url', __( 'Please provide the remote WordPress / WooCommerce site URL.', 'woosync-pro' ) );
        }

        $endpoint = rtrim( $store_url, '/' ) . '/wp-json/wc/v3/products?per_page=50';

        $args = array(
            'timeout'    => 30,
            'user-agent' => 'WooSync Pro Importer/' . WOOSYNC_VERSION,
            'headers'    => array(
                'Accept' => 'application/json',
            ),
        );

        // Add credentials if provided
        if ( ! empty( $consumer_key ) && ! empty( $consumer_secret ) ) {
            $args['headers']['Authorization'] = 'Basic ' . base64_encode( $consumer_key . ':' . $consumer_secret );
        }

        $response = wp_remote_get( $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            $body = wp_remote_retrieve_body( $response );
            $err  = json_decode( $body, true );
            $msg  = isset( $err['message'] ) ? $err['message'] : "HTTP Status: $code";
            return new WP_Error( 'wc_api_error', sprintf( __( 'WooCommerce API Error: %s', 'woosync-pro' ), $msg ) );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( empty( $data ) || ! is_array( $data ) ) {
            return new WP_Error( 'no_products', __( 'No products found on the remote WooCommerce store.', 'woosync-pro' ) );
        }

        return self::normalize_woocommerce_products( $data );
    }

    /**
     * Normalize Shopify product data to uniform structure.
     *
     * @param array $products Raw products from Shopify.
     * @return array
     */
    private static function normalize_shopify_products( $products ) {
        $normalized = array();

        foreach ( $products as $item ) {
            $source_id = isset( $item['id'] ) ? (string) $item['id'] : '';
            $title     = isset( $item['title'] ) ? $item['title'] : '';
            $handle    = isset( $item['handle'] ) ? $item['handle'] : '';
            $body_html = isset( $item['body_html'] ) ? $item['body_html'] : '';
            $vendor    = isset( $item['vendor'] ) ? $item['vendor'] : '';
            $type      = isset( $item['product_type'] ) ? $item['product_type'] : '';
            $tags      = isset( $item['tags'] ) ? ( is_array( $item['tags'] ) ? $item['tags'] : array_map( 'trim', explode( ',', (string) $item['tags'] ) ) ) : array();

            // Variants
            $first_variant  = ! empty( $item['variants'][0] ) ? $item['variants'][0] : array();
            $sku            = isset( $first_variant['sku'] ) ? $first_variant['sku'] : '';
            $price          = isset( $first_variant['price'] ) ? (string) $first_variant['price'] : '0.00';
            $compare_price  = isset( $first_variant['compare_at_price'] ) && ! empty( $first_variant['compare_at_price'] ) ? (string) $first_variant['compare_at_price'] : '';
            $stock_quantity = isset( $first_variant['inventory_quantity'] ) ? (int) $first_variant['inventory_quantity'] : null;

            // In Shopify: compare_at_price is the original regular price, price is the current/sale price
            if ( ! empty( $compare_price ) && (float) $compare_price > (float) $price ) {
                $regular_price = $compare_price;
                $sale_price    = $price;
            } else {
                $regular_price = $price;
                $sale_price    = '';
            }

            // Images
            $images = array();
            if ( ! empty( $item['images'] ) && is_array( $item['images'] ) ) {
                foreach ( $item['images'] as $img ) {
                    $src = isset( $img['src'] ) ? $img['src'] : '';
                    if ( ! empty( $src ) ) {
                        $images[] = array(
                            'src' => $src,
                            'alt' => isset( $img['alt'] ) ? $img['alt'] : $title,
                        );
                    }
                }
            }

            // Categories / Tags
            $categories = array();
            if ( ! empty( $type ) ) {
                $categories[] = $type;
            }

            // Check if product already exists locally
            $existing_id = WooSync_Importer::find_existing_product_id( $source_id, 'shopify', $sku );

            $normalized[] = array(
                'source_id'         => $source_id,
                'source_type'       => 'shopify',
                'title'             => $title,
                'handle'            => $handle,
                'description'       => $body_html,
                'short_description' => '',
                'sku'               => $sku,
                'price'             => $price,
                'regular_price'     => $regular_price,
                'sale_price'        => $sale_price,
                'stock_quantity'    => $stock_quantity,
                'vendor'            => $vendor,
                'images'            => $images,
                'categories'        => $categories,
                'tags'              => $tags,
                'is_synced'         => (bool) $existing_id,
                'local_product_id'  => $existing_id,
                'local_edit_url'    => $existing_id ? get_edit_post_link( $existing_id, 'raw' ) : '',
            );
        }

        return $normalized;
    }

    /**
     * Normalize WooCommerce REST API products to uniform structure.
     *
     * @param array $products Raw products from WooCommerce API.
     * @return array
     */
    private static function normalize_woocommerce_products( $products ) {
        $normalized = array();

        foreach ( $products as $item ) {
            $source_id         = isset( $item['id'] ) ? (string) $item['id'] : '';
            $title             = isset( $item['name'] ) ? $item['name'] : '';
            $slug              = isset( $item['slug'] ) ? $item['slug'] : '';
            $description       = isset( $item['description'] ) ? $item['description'] : '';
            $short_description = isset( $item['short_description'] ) ? $item['short_description'] : '';
            $sku               = isset( $item['sku'] ) ? $item['sku'] : '';
            $regular_price     = isset( $item['regular_price'] ) ? (string) $item['regular_price'] : '';
            $sale_price        = isset( $item['sale_price'] ) ? (string) $item['sale_price'] : '';
            $price             = isset( $item['price'] ) ? (string) $item['price'] : $regular_price;
            $stock_quantity    = isset( $item['stock_quantity'] ) ? (int) $item['stock_quantity'] : null;

            // Categories
            $categories = array();
            if ( ! empty( $item['categories'] ) && is_array( $item['categories'] ) ) {
                foreach ( $item['categories'] as $cat ) {
                    if ( isset( $cat['name'] ) ) {
                        $categories[] = $cat['name'];
                    }
                }
            }

            // Tags
            $tags = array();
            if ( ! empty( $item['tags'] ) && is_array( $item['tags'] ) ) {
                foreach ( $item['tags'] as $tg ) {
                    if ( isset( $tg['name'] ) ) {
                        $tags[] = $tg['name'];
                    }
                }
            }

            // Images
            $images = array();
            if ( ! empty( $item['images'] ) && is_array( $item['images'] ) ) {
                foreach ( $item['images'] as $img ) {
                    $src = isset( $img['src'] ) ? $img['src'] : '';
                    if ( ! empty( $src ) ) {
                        $images[] = array(
                            'src' => $src,
                            'alt' => isset( $img['alt'] ) ? $img['alt'] : ( isset( $img['name'] ) ? $img['name'] : $title ),
                        );
                    }
                }
            }

            // Check if product already exists locally
            $existing_id = WooSync_Importer::find_existing_product_id( $source_id, 'wordpress', $sku );

            $normalized[] = array(
                'source_id'         => $source_id,
                'source_type'       => 'wordpress',
                'title'             => $title,
                'handle'            => $slug,
                'description'       => $description,
                'short_description' => $short_description,
                'sku'               => $sku,
                'price'             => $price,
                'regular_price'     => $regular_price,
                'sale_price'        => $sale_price,
                'stock_quantity'    => $stock_quantity,
                'vendor'            => '',
                'images'            => $images,
                'categories'        => $categories,
                'tags'              => $tags,
                'is_synced'         => (bool) $existing_id,
                'local_product_id'  => $existing_id,
                'local_edit_url'    => $existing_id ? get_edit_post_link( $existing_id, 'raw' ) : '',
            );
        }

        return $normalized;
    }
}
