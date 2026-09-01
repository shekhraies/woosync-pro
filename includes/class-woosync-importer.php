<?php
/**
 * WooSync Product Importer & Upsert Engine
 *
 * Handles creating and updating WooCommerce Simple and Variable products,
 * creating child variations, importing remote images, managing categories,
 * and persisting source tracking metadata.
 *
 * @package WooSync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WooSync_Importer {

    /**
     * Find existing WooCommerce product ID by Source ID + Type or SKU.
     *
     * @param string $source_id   Remote product ID.
     * @param string $source_type 'shopify' or 'wordpress'.
     * @param string $sku         Product SKU.
     * @return int Product ID if found, 0 otherwise.
     */
    public static function find_existing_product_id( $source_id, $source_type = '', $sku = '' ) {
        global $wpdb;

        // 1. Match by Source ID and Source Type
        if ( ! empty( $source_id ) ) {
            if ( ! empty( $source_type ) ) {
                $product_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                     WHERE pm.meta_key = '_woosync_source_id' 
                       AND pm.meta_value = %s 
                       AND p.post_type IN ('product', 'product_variation')
                       AND p.post_status != 'trash'
                       AND EXISTS (
                           SELECT 1 FROM {$wpdb->postmeta} pm2 
                           WHERE pm2.post_id = pm.post_id 
                             AND pm2.meta_key = '_woosync_source_type' 
                             AND pm2.meta_value = %s
                       )
                     LIMIT 1",
                    (string) $source_id,
                    $source_type
                ) );
            } else {
                $product_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                     WHERE pm.meta_key = '_woosync_source_id' 
                       AND pm.meta_value = %s 
                       AND p.post_type IN ('product', 'product_variation')
                       AND p.post_status != 'trash'
                     LIMIT 1",
                    (string) $source_id
                ) );
            }

            if ( ! empty( $product_id ) ) {
                return (int) $product_id;
            }
        }

        // 2. Fallback: Match by SKU if provided
        if ( ! empty( $sku ) && function_exists( 'wc_get_product_id_by_sku' ) ) {
            $product_id = wc_get_product_id_by_sku( $sku );
            if ( ! empty( $product_id ) ) {
                return (int) $product_id;
            }
        }

        return 0;
    }

    /**
     * Find existing child variation ID by Parent ID and Source Variation ID or SKU.
     *
     * @param int    $parent_id
     * @param string $source_id
     * @param string $sku
     * @return int
     */
    public static function find_existing_variation_id( $parent_id, $source_id, $sku = '' ) {
        global $wpdb;

        if ( ! empty( $source_id ) ) {
            $var_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                 WHERE p.post_parent = %d
                   AND p.post_type = 'product_variation'
                   AND p.post_status != 'trash'
                   AND pm.meta_key = '_woosync_source_id'
                   AND pm.meta_value = %s
                 LIMIT 1",
                $parent_id,
                (string) $source_id
            ) );

            if ( ! empty( $var_id ) ) {
                return (int) $var_id;
            }
        }

        if ( ! empty( $sku ) && function_exists( 'wc_get_product_id_by_sku' ) ) {
            $sku_id = wc_get_product_id_by_sku( $sku );
            if ( ! empty( $sku_id ) ) {
                $post_parent = wp_get_post_parent_id( $sku_id );
                if ( (int) $post_parent === (int) $parent_id ) {
                    return (int) $sku_id;
                }
            }
        }

        return 0;
    }

    /**
     * Import or update a product (Simple or Variable) in WooCommerce.
     *
     * @param array $product_data Normalized product data array.
     * @return array|WP_Error
     */
    public static function sync_product( $product_data ) {
        if ( empty( $product_data ) || ! is_array( $product_data ) ) {
            return new WP_Error( 'invalid_data', __( 'Invalid product data provided for sync.', 'woosync-pro' ) );
        }

        $source_id         = isset( $product_data['source_id'] ) ? sanitize_text_field( (string) $product_data['source_id'] ) : '';
        $source_type       = isset( $product_data['source_type'] ) ? sanitize_text_field( $product_data['source_type'] ) : 'general';
        $title             = isset( $product_data['title'] ) ? sanitize_text_field( $product_data['title'] ) : '';
        $description       = isset( $product_data['description'] ) ? wp_kses_post( $product_data['description'] ) : '';
        $short_description = isset( $product_data['short_description'] ) ? wp_kses_post( $product_data['short_description'] ) : '';
        $sku               = isset( $product_data['sku'] ) ? sanitize_text_field( $product_data['sku'] ) : '';
        $regular_price     = isset( $product_data['regular_price'] ) ? sanitize_text_field( (string) $product_data['regular_price'] ) : '';
        $sale_price        = isset( $product_data['sale_price'] ) ? sanitize_text_field( (string) $product_data['sale_price'] ) : '';
        $price             = isset( $product_data['price'] ) ? sanitize_text_field( (string) $product_data['price'] ) : '';
        $stock_quantity    = isset( $product_data['stock_quantity'] ) && $product_data['stock_quantity'] !== '' && $product_data['stock_quantity'] !== null ? (int) $product_data['stock_quantity'] : null;
        $categories        = isset( $product_data['categories'] ) && is_array( $product_data['categories'] ) ? $product_data['categories'] : array();
        $tags              = isset( $product_data['tags'] ) && is_array( $product_data['tags'] ) ? $product_data['tags'] : array();
        $images            = isset( $product_data['images'] ) && is_array( $product_data['images'] ) ? $product_data['images'] : array();
        $options           = isset( $product_data['options'] ) && is_array( $product_data['options'] ) ? $product_data['options'] : array();
        $variants          = isset( $product_data['variants'] ) && is_array( $product_data['variants'] ) ? $product_data['variants'] : array();
        $has_variants      = ! empty( $product_data['has_variants'] ) && ! empty( $variants );

        if ( empty( $title ) ) {
            return new WP_Error( 'missing_title', __( 'Product title is required.', 'woosync-pro' ) );
        }

        // Check if main product already exists
        $existing_id = self::find_existing_product_id( $source_id, $source_type, $sku );

        if ( $has_variants ) {
            // Variable Product
            if ( $existing_id ) {
                $product = wc_get_product( $existing_id );
                if ( ! $product || ! $product->is_type( 'variable' ) ) {
                    wp_set_object_terms( $existing_id, 'variable', 'product_type' );
                    $product = new WC_Product_Variable( $existing_id );
                }
                $action = 'updated';
            } else {
                $product = new WC_Product_Variable();
                $action  = 'created';
            }
        } else {
            // Simple Product
            if ( $existing_id ) {
                $product = wc_get_product( $existing_id );
                if ( ! $product ) {
                    $product = new WC_Product_Simple();
                    $action  = 'created';
                } else {
                    $action = 'updated';
                }
            } else {
                $product = new WC_Product_Simple();
                $action  = 'created';
            }
        }

        // Set core product details
        $product->set_name( $title );
        $product->set_description( $description );
        if ( ! empty( $short_description ) ) {
            $product->set_short_description( $short_description );
        }
        $product->set_status( 'publish' );

        // SKU for parent
        if ( ! empty( $sku ) ) {
            try {
                $product->set_sku( $sku );
            } catch ( Exception $e ) {
                // Ignore SKU collision on parent
            }
        }

        // Configure attributes if Variable Product
        if ( $has_variants && ! empty( $options ) ) {
            $wc_attributes = array();
            foreach ( $options as $idx => $opt ) {
                $opt_name = isset( $opt['name'] ) ? sanitize_text_field( $opt['name'] ) : '';
                if ( empty( $opt_name ) ) continue;
                if ( strtolower( $opt_name ) === 'title' && count( $opt['values'] ) <= 1 && ( empty( $opt['values'] ) || $opt['values'][0] === 'Default Title' ) ) {
                    continue;
                }

                $is_user_type = in_array( strtolower( trim( $opt_name ) ), array( 'user type', 'user_type', 'usertype' ) );

                $attribute = new WC_Product_Attribute();
                $attribute->set_id( 0 );
                $attribute->set_name( $opt_name );
                $attribute->set_options( $opt['values'] );
                $attribute->set_position( isset( $opt['position'] ) ? (int) $opt['position'] : $idx );
                $attribute->set_visible( ! $is_user_type );
                $attribute->set_variation( true );
                $wc_attributes[ sanitize_title( $opt_name ) ] = $attribute;
            }
            $product->set_attributes( $wc_attributes );
        } elseif ( ! $has_variants ) {
            // Pricing & stock for Simple Product
            if ( $regular_price !== '' && is_numeric( $regular_price ) ) {
                $product->set_regular_price( wc_format_decimal( $regular_price ) );
            } elseif ( $price !== '' && is_numeric( $price ) ) {
                $product->set_regular_price( wc_format_decimal( $price ) );
            }

            if ( $sale_price !== '' && is_numeric( $sale_price ) && (float) $sale_price < (float) $product->get_regular_price() ) {
                $product->set_sale_price( wc_format_decimal( $sale_price ) );
            } else {
                $product->set_sale_price( '' );
            }

            if ( $stock_quantity !== null ) {
                $product->set_manage_stock( true );
                $product->set_stock_quantity( $stock_quantity );
                $product->set_stock_status( $stock_quantity > 0 ? 'instock' : 'outofstock' );
            } else {
                $product->set_manage_stock( false );
                $product->set_stock_status( 'instock' );
            }
        }

        // Save parent product
        $product_id = $product->save();

        if ( ! $product_id ) {
            return new WP_Error( 'save_failed', __( 'Could not save product in WooCommerce.', 'woosync-pro' ) );
        }

        // Persist Source Tracking Meta on Parent
        update_post_meta( $product_id, '_woosync_source_id', $source_id );
        update_post_meta( $product_id, '_woosync_source_type', $source_type );
        update_post_meta( $product_id, '_woosync_last_synced', current_time( 'mysql' ) );

        // Handle Categories
        if ( ! empty( $categories ) ) {
            $cat_ids = array();
            foreach ( $categories as $cat_name ) {
                $cat_name = sanitize_text_field( trim( $cat_name ) );
                if ( empty( $cat_name ) ) continue;

                $term = term_exists( $cat_name, 'product_cat' );
                if ( ! $term ) {
                    $term = wp_insert_term( $cat_name, 'product_cat' );
                }
                if ( ! is_wp_error( $term ) && isset( $term['term_id'] ) ) {
                    $cat_ids[] = (int) $term['term_id'];
                }
            }
            if ( ! empty( $cat_ids ) ) {
                wp_set_object_terms( $product_id, $cat_ids, 'product_cat' );
            }
        }

        // Handle Tags
        if ( ! empty( $tags ) ) {
            $clean_tags = array();
            foreach ( $tags as $tag_name ) {
                $tag_name = sanitize_text_field( trim( $tag_name ) );
                if ( ! empty( $tag_name ) ) {
                    $clean_tags[] = $tag_name;
                }
            }
            if ( ! empty( $clean_tags ) ) {
                wp_set_object_terms( $product_id, $clean_tags, 'product_tag' );
            }
        }

        // Handle Images (Featured & Gallery)
        if ( ! empty( $images ) ) {
            self::process_product_images( $product_id, $images );
        }

        // Process Variations for Variable Products
        $synced_variations = 0;
        if ( $has_variants && ! empty( $variants ) ) {
            $synced_variations = self::process_variations( $product_id, $variants, $options, $source_type );
        }

        return array(
            'success'           => true,
            'action'            => $action,
            'product_id'        => $product_id,
            'title'             => $title,
            'source_id'         => $source_id,
            'is_variable'       => $has_variants,
            'variations_synced' => $synced_variations,
            'edit_url'          => get_edit_post_link( $product_id, 'raw' ),
            'view_url'          => get_permalink( $product_id ),
        );
    }

    /**
     * Process and upsert child variations for a variable product.
     *
     * @param int    $parent_id
     * @param array  $variants
     * @param array  $options
     * @param string $source_type
     * @return int Count of synced variations.
     */
    private static function process_variations( $parent_id, $variants, $options, $source_type ) {
        // Map option position (1, 2, 3) to option name
        $option_map = array();
        foreach ( $options as $idx => $opt ) {
            $option_map[ $idx + 1 ] = $opt['name'];
        }

        $count = 0;

        foreach ( $variants as $v ) {
            $var_source_id = isset( $v['id'] ) ? (string) $v['id'] : '';
            $var_sku       = isset( $v['sku'] ) ? sanitize_text_field( $v['sku'] ) : '';

            $existing_var_id = self::find_existing_variation_id( $parent_id, $var_source_id, $var_sku );

            if ( $existing_var_id ) {
                $variation = new WC_Product_Variation( $existing_var_id );
            } else {
                $variation = new WC_Product_Variation();
                $variation->set_parent_id( $parent_id );
            }

            // Map attributes to variation
            $var_attributes = array();
            for ( $i = 1; $i <= 3; $i++ ) {
                $opt_key = 'option' . $i;
                if ( ! empty( $v[ $opt_key ] ) && isset( $option_map[ $i ] ) ) {
                    $attr_slug = sanitize_title( $option_map[ $i ] );
                    $var_attributes[ $attr_slug ] = sanitize_text_field( $v[ $opt_key ] );
                }
            }

            // Fallback attribute resolution if title contains delimiter like "US:6 / VIP Price"
            if ( empty( $var_attributes ) && ! empty( $v['title'] ) && $v['title'] !== 'Default Title' ) {
                $parts = array_map( 'trim', explode( '/', $v['title'] ) );
                foreach ( $parts as $p_idx => $val ) {
                    if ( isset( $options[ $p_idx ]['name'] ) ) {
                        $attr_slug = sanitize_title( $options[ $p_idx ]['name'] );
                        $var_attributes[ $attr_slug ] = sanitize_text_field( $val );
                    }
                }
            }

            $variation->set_attributes( $var_attributes );

            // SKU
            if ( ! empty( $var_sku ) ) {
                try {
                    $variation->set_sku( $var_sku );
                } catch ( Exception $e ) {}
            }

            // Pricing
            $reg_price  = isset( $v['regular_price'] ) ? (string) $v['regular_price'] : '';
            $sale_price = isset( $v['sale_price'] ) ? (string) $v['sale_price'] : '';
            $cur_price  = isset( $v['price'] ) ? (string) $v['price'] : '';

            if ( $reg_price !== '' && is_numeric( $reg_price ) ) {
                $variation->set_regular_price( wc_format_decimal( $reg_price ) );
            } elseif ( $cur_price !== '' && is_numeric( $cur_price ) ) {
                $variation->set_regular_price( wc_format_decimal( $cur_price ) );
            }

            if ( $sale_price !== '' && is_numeric( $sale_price ) && (float) $sale_price < (float) $variation->get_regular_price() ) {
                $variation->set_sale_price( wc_format_decimal( $sale_price ) );
            } else {
                $variation->set_sale_price( '' );
            }

            // Stock
            if ( isset( $v['stock_quantity'] ) && $v['stock_quantity'] !== null && $v['stock_quantity'] !== '' ) {
                $variation->set_manage_stock( true );
                $variation->set_stock_quantity( (int) $v['stock_quantity'] );
                $variation->set_stock_status( (int) $v['stock_quantity'] > 0 ? 'instock' : 'outofstock' );
            } else {
                $variation->set_manage_stock( false );
                $variation->set_stock_status( ! empty( $v['available'] ) ? 'instock' : 'outofstock' );
            }

            $variation->set_status( 'publish' );

            // Variant Image
            if ( ! empty( $v['image'] ) ) {
                $v_img_id = self::get_or_download_attachment_id( $v['image'], $parent_id );
                if ( $v_img_id ) {
                    $variation->set_image_id( $v_img_id );
                }
            }

            $saved_var_id = $variation->save();

            if ( $saved_var_id ) {
                update_post_meta( $saved_var_id, '_woosync_source_id', $var_source_id );
                update_post_meta( $saved_var_id, '_woosync_source_type', $source_type );
                update_post_meta( $saved_var_id, '_woosync_last_synced', current_time( 'mysql' ) );
                $count++;
            }
        }

        // Set Default Form Values (Default Variation Attributes) prioritizing first VIP Price variant
        $default_attributes = self::determine_default_attributes( $variants, $options );
        if ( ! empty( $default_attributes ) ) {
            $parent_product = wc_get_product( $parent_id );
            if ( $parent_product && $parent_product->is_type( 'variable' ) ) {
                $parent_product->set_default_attributes( $default_attributes );
                $parent_product->save();
            }
        }

        // Sync parent variable product data & cache
        WC_Product_Variable::sync( $parent_id );
        wc_delete_product_transients( $parent_id );

        return $count;
    }

    /**
     * Determine default attributes for variable product, prioritizing first VIP Price variant.
     *
     * @param array $variants
     * @param array $options
     * @return array
     */
    private static function determine_default_attributes( $variants, $options ) {
        if ( empty( $variants ) || empty( $options ) ) {
            return array();
        }

        $selected_variant = null;

        // 1. Prioritize first variant that has VIP in title or option values
        foreach ( $variants as $v ) {
            $is_vip = false;
            if ( ! empty( $v['title'] ) && stripos( $v['title'], 'VIP' ) !== false ) {
                $is_vip = true;
            }
            for ( $i = 1; $i <= 3; $i++ ) {
                if ( ! empty( $v[ 'option' . $i ] ) && stripos( $v[ 'option' . $i ], 'VIP' ) !== false ) {
                    $is_vip = true;
                }
            }
            if ( $is_vip ) {
                $selected_variant = $v;
                break;
            }
        }

        // 2. Fallback to the very first variant
        if ( ! $selected_variant && ! empty( $variants[0] ) ) {
            $selected_variant = $variants[0];
        }

        if ( ! $selected_variant ) {
            return array();
        }

        $option_map = array();
        foreach ( $options as $idx => $opt ) {
            $option_map[ $idx + 1 ] = $opt['name'];
        }

        $default_attributes = array();

        for ( $i = 1; $i <= 3; $i++ ) {
            $opt_val = isset( $selected_variant[ 'option' . $i ] ) ? $selected_variant[ 'option' . $i ] : '';
            if ( ! empty( $opt_val ) && isset( $option_map[ $i ] ) ) {
                $attr_slug = sanitize_title( $option_map[ $i ] );
                $default_attributes[ $attr_slug ] = sanitize_text_field( $opt_val );
            }
        }

        // Fallback: parse from title if individual options weren't set
        if ( empty( $default_attributes ) && ! empty( $selected_variant['title'] ) && $selected_variant['title'] !== 'Default Title' ) {
            $parts = array_map( 'trim', explode( '/', $selected_variant['title'] ) );
            foreach ( $parts as $p_idx => $val ) {
                if ( isset( $options[ $p_idx ]['name'] ) ) {
                    $attr_slug = sanitize_title( $options[ $p_idx ]['name'] );
                    $default_attributes[ $attr_slug ] = sanitize_text_field( $val );
                }
            }
        }

        return $default_attributes;
    }

    /**
     * Download and attach remote images to WooCommerce product.
     *
     * @param int   $product_id
     * @param array $images
     */
    private static function process_product_images( $product_id, $images ) {
        $attachment_ids = array();

        foreach ( $images as $img ) {
            $img_url = is_array( $img ) && isset( $img['src'] ) ? esc_url_raw( $img['src'] ) : ( is_string( $img ) ? esc_url_raw( $img ) : '' );
            if ( empty( $img_url ) ) continue;

            $attachment_id = self::get_or_download_attachment_id( $img_url, $product_id );
            if ( $attachment_id ) {
                $attachment_ids[] = (int) $attachment_id;
            }
        }

        if ( ! empty( $attachment_ids ) ) {
            // First image is Featured Image
            $featured_id = array_shift( $attachment_ids );
            set_post_thumbnail( $product_id, $featured_id );

            // Remaining images are Gallery
            if ( ! empty( $attachment_ids ) ) {
                update_post_meta( $product_id, '_product_image_gallery', implode( ',', $attachment_ids ) );
            }
        }
    }

    /**
     * Look up existing media attachment by original URL or download and attach.
     *
     * @param string $img_url
     * @param int    $post_id
     * @return int Attachment ID or 0.
     */
    public static function get_or_download_attachment_id( $img_url, $post_id = 0 ) {
        global $wpdb;

        if ( empty( $img_url ) ) return 0;

        $attachment_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_woosync_source_image_url' AND meta_value = %s LIMIT 1",
            $img_url
        ) );

        if ( $attachment_id ) {
            return (int) $attachment_id;
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $new_attachment_id = media_sideload_image( $img_url, $post_id, null, 'id' );

        if ( ! is_wp_error( $new_attachment_id ) && $new_attachment_id > 0 ) {
            update_post_meta( $new_attachment_id, '_woosync_source_image_url', $img_url );
            return (int) $new_attachment_id;
        }

        return 0;
    }
}
