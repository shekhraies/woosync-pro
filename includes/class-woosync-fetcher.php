<?php
/**
 * WooSync API Fetcher
 *
 * Handles API connections to Shopify and WordPress / WooCommerce,
 * and normalizes product data (including multi-option variants) into a standardized structure.
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
    /**
     * Fetch products from Shopify Public Storefront JSON (catalog or single product).
     *
     * @param array $config
     * @return array|WP_Error
     */
    private static function fetch_shopify_json( $config ) {
        $url = isset( $config['shopify_url'] ) ? esc_url_raw( trim( $config['shopify_url'] ) ) : '';
        if ( empty( $url ) ) {
            return new WP_Error( 'missing_url', __( 'Please provide a valid Shopify Store URL, products.json, or single product URL.', 'woosync-pro' ) );
        }

        // Clean query strings for pattern checking
        $url_parts = explode( '?', $url );
        $base_path = rtrim( $url_parts[0], '/' );
        $query_str = isset( $url_parts[1] ) ? '?' . $url_parts[1] : '';

        // Case 1: Single product URL like .../products/product-handle.json or .../products/product-handle
        if ( preg_match( '#/products/([a-zA-Z0-9\-_]+)(\.json)?$#i', $base_path, $matches ) ) {
            if ( strtolower( substr( $base_path, -5 ) ) !== '.json' ) {
                $base_path .= '.json';
            }
            $url = $base_path . $query_str;
        }
        // Case 2: Products collection / catalog like .../products.json or .../collections/.../products.json
        elseif ( preg_match( '#/products\.json$#i', $base_path ) ) {
            $url = $base_path . ( empty( $query_str ) ? '?limit=50' : $query_str );
        }
        // Case 3: Base store URL (e.g. https://mystore.myshopify.com)
        else {
            $url = $base_path . '/products.json' . ( empty( $query_str ) ? '?limit=50' : $query_str );
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

        if ( empty( $data ) ) {
            return new WP_Error( 'no_products', __( 'No data returned from Shopify endpoint.', 'woosync-pro' ) );
        }

        // Handle multiple products: {"products": [...]}
        if ( isset( $data['products'] ) && is_array( $data['products'] ) ) {
            return self::normalize_shopify_products( $data['products'] );
        }

        // Handle single product: {"product": {...}}
        if ( isset( $data['product'] ) && is_array( $data['product'] ) ) {
            return self::normalize_shopify_products( array( $data['product'] ) );
        }

        // Handle direct array of products: [ {...}, {...} ]
        if ( is_array( $data ) && isset( $data[0]['id'] ) ) {
            return self::normalize_shopify_products( $data );
        }

        return new WP_Error( 'no_products', __( 'No products found at the specified Shopify endpoint.', 'woosync-pro' ) );
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

        // Clean store URL
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

        if ( isset( $data['products'] ) && is_array( $data['products'] ) ) {
            return self::normalize_shopify_products( $data['products'] );
        }

        if ( isset( $data['product'] ) && is_array( $data['product'] ) ) {
            return self::normalize_shopify_products( array( $data['product'] ) );
        }

        return new WP_Error( 'no_products', __( 'No products found in this Shopify store.', 'woosync-pro' ) );
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

        $cleaned_url = rtrim( $store_url, '/' );
        if ( strpos( $cleaned_url, '/wp-json/wc/' ) !== false ) {
            $endpoint = $cleaned_url;
        } else {
            $endpoint = $cleaned_url . '/wp-json/wc/v3/products?per_page=50';
        }

        $args = array(
            'timeout'    => 30,
            'user-agent' => 'WooSync Pro Importer/' . WOOSYNC_VERSION,
            'headers'    => array(
                'Accept' => 'application/json',
            ),
        );

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

        if ( empty( $data ) ) {
            return new WP_Error( 'no_products', __( 'No products found on the remote WooCommerce store.', 'woosync-pro' ) );
        }

        if ( isset( $data['id'] ) && ! empty( $data['id'] ) ) {
            return self::normalize_woocommerce_products( array( $data ) );
        }

        if ( is_array( $data ) && ! empty( $data ) ) {
            return self::normalize_woocommerce_products( $data );
        }

        return new WP_Error( 'no_products', __( 'No products found on the remote WooCommerce store.', 'woosync-pro' ) );
    }

    /**
     * Normalize Shopify product data (with options and variants) to uniform structure.
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

            // Extract Options
            $options = array();
            if ( ! empty( $item['options'] ) && is_array( $item['options'] ) ) {
                foreach ( $item['options'] as $opt ) {
                    $opt_name = isset( $opt['name'] ) ? sanitize_text_field( $opt['name'] ) : '';
                    $opt_values = isset( $opt['values'] ) && is_array( $opt['values'] ) ? array_map( 'sanitize_text_field', $opt['values'] ) : array();
                    if ( ! empty( $opt_name ) ) {
                        $options[] = array(
                            'name'     => $opt_name,
                            'position' => isset( $opt['position'] ) ? (int) $opt['position'] : 1,
                            'values'   => $opt_values,
                        );
                    }
                }
            }

            // Extract Variants
            $variants     = array();
            $prices       = array();
            $has_variants = false;

            if ( ! empty( $item['variants'] ) && is_array( $item['variants'] ) ) {
                foreach ( $item['variants'] as $v ) {
                    $v_id        = isset( $v['id'] ) ? (string) $v['id'] : '';
                    $v_title     = isset( $v['title'] ) ? sanitize_text_field( $v['title'] ) : '';
                    $v_sku       = isset( $v['sku'] ) ? sanitize_text_field( $v['sku'] ) : '';
                    $v_price     = isset( $v['price'] ) ? (string) $v['price'] : '0.00';
                    $v_compare   = isset( $v['compare_at_price'] ) && ! empty( $v['compare_at_price'] ) ? (string) $v['compare_at_price'] : '';
                    $v_stock     = isset( $v['inventory_quantity'] ) ? (int) $v['inventory_quantity'] : null;
                    $v_available = isset( $v['available'] ) ? (bool) $v['available'] : true;

                    if ( ! empty( $v_compare ) && (float) $v_compare > (float) $v_price ) {
                        $v_reg_price  = $v_compare;
                        $v_sale_price = $v_price;
                    } else {
                        $v_reg_price  = $v_price;
                        $v_sale_price = '';
                    }

                    if ( is_numeric( $v_price ) ) {
                        $prices[] = (float) $v_price;
                    }

                    // Variant image resolution
                    $v_img = '';
                    if ( ! empty( $v['featured_image']['src'] ) ) {
                        $v_img = $v['featured_image']['src'];
                    } elseif ( ! empty( $v['image_id'] ) && ! empty( $item['images'] ) ) {
                        foreach ( $item['images'] as $img_obj ) {
                            if ( isset( $img_obj['id'] ) && (string) $img_obj['id'] === (string) $v['image_id'] && ! empty( $img_obj['src'] ) ) {
                                $v_img = $img_obj['src'];
                                break;
                            }
                        }
                    }

                    $variants[] = array(
                        'id'             => $v_id,
                        'title'          => $v_title,
                        'option1'        => isset( $v['option1'] ) ? sanitize_text_field( $v['option1'] ) : '',
                        'option2'        => isset( $v['option2'] ) ? sanitize_text_field( $v['option2'] ) : '',
                        'option3'        => isset( $v['option3'] ) ? sanitize_text_field( $v['option3'] ) : '',
                        'sku'            => $v_sku,
                        'price'          => $v_price,
                        'regular_price'  => $v_reg_price,
                        'sale_price'     => $v_sale_price,
                        'stock_quantity' => $v_stock,
                        'available'      => $v_available,
                        'image'          => $v_img,
                    );
                }
            }

            // In Shopify, multiple variants or non-default single variant means Variable product
            if ( count( $variants ) > 1 ) {
                $has_variants = true;
            } elseif ( count( $variants ) === 1 && $variants[0]['title'] !== 'Default Title' && ! empty( $options ) && count( $options ) > 0 && strtolower( $options[0]['name'] ) !== 'title' ) {
                $has_variants = true;
            }

            // Pricing range & base price calculation
            $first_variant  = ! empty( $variants[0] ) ? $variants[0] : array();
            $main_sku       = ! empty( $first_variant['sku'] ) ? $first_variant['sku'] : '';
            $regular_price  = ! empty( $first_variant['regular_price'] ) ? $first_variant['regular_price'] : '0.00';
            $sale_price     = ! empty( $first_variant['sale_price'] ) ? $first_variant['sale_price'] : '';
            $price          = ! empty( $first_variant['price'] ) ? $first_variant['price'] : $regular_price;
            $stock_quantity = isset( $first_variant['stock_quantity'] ) ? $first_variant['stock_quantity'] : null;

            $price_display = '$' . $price;
            if ( ! empty( $prices ) ) {
                $min_p = min( $prices );
                $max_p = max( $prices );
                if ( $min_p < $max_p ) {
                    $price_display = '$' . number_format( $min_p, 2 ) . ' - $' . number_format( $max_p, 2 );
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
                            'alt' => isset( $img['alt'] ) ? $img['alt'] : $title,
                        );
                    }
                }
            }

            // Categories
            $categories = array();
            if ( ! empty( $type ) ) {
                $categories[] = $type;
            }

            // Check if product already exists locally
            $existing_id = WooSync_Importer::find_existing_product_id( $source_id, 'shopify', $main_sku );

            $normalized[] = array(
                'source_id'         => $source_id,
                'source_type'       => 'shopify',
                'title'             => $title,
                'handle'            => $handle,
                'description'       => $body_html,
                'short_description' => '',
                'sku'               => $main_sku,
                'price'             => $price,
                'price_display'     => $price_display,
                'regular_price'     => $regular_price,
                'sale_price'        => $sale_price,
                'stock_quantity'    => $stock_quantity,
                'vendor'            => $vendor,
                'images'            => $images,
                'categories'        => $categories,
                'tags'              => $tags,
                'options'           => $options,
                'variants'          => $variants,
                'has_variants'      => $has_variants,
                'variant_count'     => count( $variants ),
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
            $type              = isset( $item['type'] ) ? $item['type'] : 'simple';

            // Options / Attributes
            $options = array();
            if ( ! empty( $item['attributes'] ) && is_array( $item['attributes'] ) ) {
                foreach ( $item['attributes'] as $attr ) {
                    $options[] = array(
                        'name'     => isset( $attr['name'] ) ? $attr['name'] : '',
                        'position' => isset( $attr['position'] ) ? (int) $attr['position'] : 0,
                        'values'   => isset( $attr['options'] ) && is_array( $attr['options'] ) ? $attr['options'] : array(),
                    );
                }
            }

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

            $has_variants = ( $type === 'variable' || ! empty( $item['variations'] ) );
            $variant_count = ! empty( $item['variations'] ) && is_array( $item['variations'] ) ? count( $item['variations'] ) : ( $has_variants ? count( $options ) : 0 );

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
                'price_display'     => '$' . $price,
                'regular_price'     => $regular_price,
                'sale_price'        => $sale_price,
                'stock_quantity'    => $stock_quantity,
                'vendor'            => '',
                'images'            => $images,
                'categories'        => $categories,
                'tags'              => $tags,
                'options'           => $options,
                'variants'          => array(),
                'has_variants'      => $has_variants,
                'variant_count'     => $variant_count,
                'is_synced'         => (bool) $existing_id,
                'local_product_id'  => $existing_id,
                'local_edit_url'    => $existing_id ? get_edit_post_link( $existing_id, 'raw' ) : '',
            );
        }

        return $normalized;
    }
}
