<?php
/**
 * WooSync API Fetcher
 *
 * Handles API connections to Shopify Public Storefront and WooCommerce Public Store API,
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
            case 'shopify':
                return self::fetch_shopify_json( $config );

            case 'woocommerce_store_api':
            case 'wordpress_wc':
            case 'woocommerce':
                return self::fetch_woocommerce_store_api( $config );

            default:
                return new WP_Error( 'invalid_api_type', __( 'Invalid API type specified.', 'woosync-pro' ) );
        }
    }

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
     * Fetch products from WooCommerce Public Store API (/wp-json/wc/store/v1/products).
     *
     * Automatically paginates through the entire remote catalog (not just the first
     * page) by following the Store API's X-WP-TotalPages response header, so stores
     * with more products than a single page (default/max 100 per request) are fully
     * synced instead of silently truncated to the first page.
     *
     * @param array $config
     * @return array|WP_Error
     */
    private static function fetch_woocommerce_store_api( $config ) {
        $url = isset( $config['wc_store_api_url'] ) ? esc_url_raw( trim( $config['wc_store_api_url'] ) ) : ( isset( $config['wp_store_url'] ) ? esc_url_raw( trim( $config['wp_store_url'] ) ) : '' );
        if ( empty( $url ) ) {
            return new WP_Error( 'missing_url', __( 'Please provide a valid WooCommerce Store API URL.', 'woosync-pro' ) );
        }

        $cleaned_url = rtrim( $url, '/' );
        $url_parts   = explode( '?', $cleaned_url );
        $base_path   = $url_parts[0];
        $query_str   = isset( $url_parts[1] ) ? $url_parts[1] : '';

        // If user provided a base store URL (e.g. https://example.com), normalize it
        // to the Store API products endpoint.
        if ( strpos( $base_path, '/wp-json/' ) === false ) {
            $base_path .= '/wp-json/wc/store/v1/products';
        }

        // Preserve any query args the user supplied (e.g. a category filter), and
        // treat an explicit page/per_page as the caller pinning a single page.
        $query_args = array();
        if ( ! empty( $query_str ) ) {
            parse_str( $query_str, $query_args );
        }

        $explicit_page = isset( $query_args['page'] );
        $per_page      = isset( $query_args['per_page'] ) ? max( 1, min( 100, (int) $query_args['per_page'] ) ) : 100;

        $query_args['per_page'] = $per_page;
        $page                   = $explicit_page ? max( 1, (int) $query_args['page'] ) : 1;

        // Safety cap so a misbehaving/huge endpoint can't loop forever.
        $max_pages = (int) apply_filters( 'woosync_pro_max_fetch_pages', 500 );

        // Allow this admin-initiated sync to run long enough to pull large catalogs
        // across many HTTP requests without hitting the default PHP execution limit.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 );
        }

        $all_products = array();

        do {
            $query_args['page'] = $page;
            $endpoint            = $base_path . '?' . http_build_query( $query_args );

            $response = wp_remote_get( $endpoint, array(
                'timeout'    => 30,
                'user-agent' => 'WooSync Pro Importer/' . WOOSYNC_VERSION,
                'headers'    => array(
                    'Accept' => 'application/json',
                ),
            ) );

            if ( is_wp_error( $response ) ) {
                // Keep whatever earlier pages already succeeded rather than losing
                // an entire multi-page sync to one flaky request.
                if ( ! empty( $all_products ) ) {
                    break;
                }
                return $response;
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( $code !== 200 ) {
                if ( ! empty( $all_products ) ) {
                    break;
                }
                $body = wp_remote_retrieve_body( $response );
                $err  = json_decode( $body, true );
                $msg  = isset( $err['message'] ) ? $err['message'] : "HTTP Status: $code";
                return new WP_Error( 'wc_api_error', sprintf( __( 'WooCommerce Store API Error: %s', 'woosync-pro' ), $msg ) );
            }

            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );

            if ( empty( $data ) || ! is_array( $data ) ) {
                break;
            }

            // Single product object response (a direct single-product endpoint).
            if ( isset( $data['id'] ) && ! empty( $data['id'] ) && ! isset( $data[0] ) ) {
                $all_products[] = $data;
                break;
            }

            $all_products = array_merge( $all_products, $data );

            if ( $explicit_page ) {
                break; // Caller asked for exactly one specific page.
            }

            $total_pages = (int) wp_remote_retrieve_header( $response, 'x-wp-totalpages' );

            if ( $total_pages > 0 ) {
                $has_more = ( $page < $total_pages );
            } else {
                // No pagination header available; fall back to a short-page heuristic.
                $has_more = ( count( $data ) >= $per_page );
            }

            $page++;
        } while ( $has_more && $page <= $max_pages );

        if ( empty( $all_products ) ) {
            return new WP_Error( 'no_products', __( 'No products found on the remote WooCommerce store.', 'woosync-pro' ) );
        }

        return self::normalize_woocommerce_store_products( $all_products );
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
                    $opt_name   = isset( $opt['name'] ) ? sanitize_text_field( $opt['name'] ) : '';
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
     * Normalize WooCommerce Public Store API products (/wp-json/wc/store/v1/products).
     *
     * @param array $products Raw products from WooCommerce Store API.
     * @return array
     */
    private static function normalize_woocommerce_store_products( $products ) {
        $normalized = array();

        foreach ( $products as $item ) {
            $source_id         = isset( $item['id'] ) ? (string) $item['id'] : '';
            $title             = isset( $item['name'] ) ? $item['name'] : '';
            $slug              = isset( $item['slug'] ) ? $item['slug'] : '';
            $description       = isset( $item['description'] ) ? $item['description'] : '';
            $short_description = isset( $item['short_description'] ) ? $item['short_description'] : '';
            $sku               = isset( $item['sku'] ) ? $item['sku'] : '';
            $type              = isset( $item['type'] ) ? $item['type'] : 'simple';

            // Prices calculation with minor_unit support (e.g. 2799 -> 27.99)
            $minor_unit = isset( $item['prices']['currency_minor_unit'] ) ? (int) $item['prices']['currency_minor_unit'] : 2;
            $divisor    = pow( 10, $minor_unit );

            $regular_price = '';
            if ( isset( $item['prices']['regular_price'] ) && is_numeric( $item['prices']['regular_price'] ) ) {
                $regular_price = number_format( (float) $item['prices']['regular_price'] / $divisor, $minor_unit, '.', '' );
            }

            $sale_price = '';
            if ( isset( $item['prices']['sale_price'] ) && is_numeric( $item['prices']['sale_price'] ) && ! empty( $item['on_sale'] ) ) {
                $sale_price = number_format( (float) $item['prices']['sale_price'] / $divisor, $minor_unit, '.', '' );
            }

            $price = '';
            if ( isset( $item['prices']['price'] ) && is_numeric( $item['prices']['price'] ) ) {
                $price = number_format( (float) $item['prices']['price'] / $divisor, $minor_unit, '.', '' );
            } else {
                $price = $regular_price;
            }

            $price_display = '$' . ( ! empty( $price ) ? $price : $regular_price );
            if ( isset( $item['prices']['price_range']['min_amount'], $item['prices']['price_range']['max_amount'] ) ) {
                $min_p = (float) $item['prices']['price_range']['min_amount'] / $divisor;
                $max_p = (float) $item['prices']['price_range']['max_amount'] / $divisor;
                if ( $min_p < $max_p ) {
                    $price_display = '$' . number_format( $min_p, 2 ) . ' - $' . number_format( $max_p, 2 );
                }
            }

            // Options / Attributes
            $options = array();
            if ( ! empty( $item['attributes'] ) && is_array( $item['attributes'] ) ) {
                foreach ( $item['attributes'] as $idx => $attr ) {
                    $attr_name = isset( $attr['name'] ) ? sanitize_text_field( $attr['name'] ) : '';
                    $terms     = array();
                    if ( ! empty( $attr['terms'] ) && is_array( $attr['terms'] ) ) {
                        foreach ( $attr['terms'] as $t ) {
                            if ( isset( $t['name'] ) ) {
                                $terms[] = sanitize_text_field( $t['name'] );
                            }
                        }
                    } elseif ( ! empty( $attr['options'] ) && is_array( $attr['options'] ) ) {
                        $terms = array_map( 'sanitize_text_field', $attr['options'] );
                    }

                    if ( ! empty( $attr_name ) ) {
                        $options[] = array(
                            'name'     => $attr_name,
                            'position' => $idx + 1,
                            'values'   => $terms,
                        );
                    }
                }
            }

            // Child Variations
            $variants     = array();
            $has_variants = ( $type === 'variable' || ! empty( $item['variations'] ) );

            if ( ! empty( $item['variations'] ) && is_array( $item['variations'] ) ) {
                foreach ( $item['variations'] as $v_idx => $v ) {
                    $v_id = isset( $v['id'] ) ? (string) $v['id'] : '';
                    
                    // Map variation attributes to option1, option2, option3
                    $v_attrs = array();
                    if ( ! empty( $v['attributes'] ) && is_array( $v['attributes'] ) ) {
                        foreach ( $v['attributes'] as $v_attr ) {
                            if ( isset( $v_attr['name'], $v_attr['value'] ) ) {
                                $v_attrs[ $v_attr['name'] ] = $v_attr['value'];
                            }
                        }
                    }

                    $opt1 = isset( $options[0]['name'] ) && isset( $v_attrs[ $options[0]['name'] ] ) ? $v_attrs[ $options[0]['name'] ] : '';
                    $opt2 = isset( $options[1]['name'] ) && isset( $v_attrs[ $options[1]['name'] ] ) ? $v_attrs[ $options[1]['name'] ] : '';
                    $opt3 = isset( $options[2]['name'] ) && isset( $v_attrs[ $options[2]['name'] ] ) ? $v_attrs[ $options[2]['name'] ] : '';

                    $title_parts = array_filter( array( $opt1, $opt2, $opt3 ) );
                    $v_title     = ! empty( $title_parts ) ? implode( ' / ', $title_parts ) : "Variation #$v_id";

                    $variants[] = array(
                        'id'             => $v_id,
                        'title'          => $v_title,
                        'option1'        => $opt1,
                        'option2'        => $opt2,
                        'option3'        => $opt3,
                        'sku'            => isset( $v['sku'] ) ? $v['sku'] : '',
                        'price'          => $price,
                        'regular_price'  => $regular_price,
                        'sale_price'     => $sale_price,
                        'stock_quantity' => null,
                        'available'      => true,
                        'image'          => '',
                    );
                }
            }

            // Categories
            $categories = array();
            if ( ! empty( $item['categories'] ) && is_array( $item['categories'] ) ) {
                foreach ( $item['categories'] as $cat ) {
                    if ( isset( $cat['name'] ) ) {
                        $categories[] = sanitize_text_field( $cat['name'] );
                    }
                }
            }

            // Tags
            $tags = array();
            if ( ! empty( $item['tags'] ) && is_array( $item['tags'] ) ) {
                foreach ( $item['tags'] as $tg ) {
                    if ( isset( $tg['name'] ) ) {
                        $tags[] = sanitize_text_field( $tg['name'] );
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
                'price_display'     => $price_display,
                'regular_price'     => $regular_price,
                'sale_price'        => $sale_price,
                'stock_quantity'    => null,
                'vendor'            => '',
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
}
