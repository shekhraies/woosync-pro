<?php
/**
 * WooSync Product Importer & Upsert Engine
 *
 * Handles creating and updating WooCommerce products, importing remote images,
 * managing categories, and persisting source tracking metadata.
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
     * Import or update a single product in WooCommerce.
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

        if ( empty( $title ) ) {
            return new WP_Error( 'missing_title', __( 'Product title is required.', 'woosync-pro' ) );
        }

        // Check if product already exists
        $existing_id = self::find_existing_product_id( $source_id, $source_type, $sku );

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

        // Set core product details
        $product->set_name( $title );
        $product->set_description( $description );
        if ( ! empty( $short_description ) ) {
            $product->set_short_description( $short_description );
        }
        $product->set_status( 'publish' );

        // SKU
        if ( ! empty( $sku ) ) {
            try {
                $product->set_sku( $sku );
            } catch ( Exception $e ) {
                // If SKU is duplicate on another product, catch exception and proceed without blocking
            }
        }

        // Pricing
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

        // Inventory / Stock
        if ( $stock_quantity !== null ) {
            $product->set_manage_stock( true );
            $product->set_stock_quantity( $stock_quantity );
            $product->set_stock_status( $stock_quantity > 0 ? 'instock' : 'outofstock' );
        } else {
            $product->set_manage_stock( false );
            $product->set_stock_status( 'instock' );
        }

        // Save initial product to get ID
        $product_id = $product->save();

        if ( ! $product_id ) {
            return new WP_Error( 'save_failed', __( 'Could not save product in WooCommerce.', 'woosync-pro' ) );
        }

        // Persist Source Tracking Meta
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

        return array(
            'success'          => true,
            'action'           => $action,
            'product_id'       => $product_id,
            'title'            => $title,
            'source_id'        => $source_id,
            'edit_url'         => get_edit_post_link( $product_id, 'raw' ),
            'view_url'         => get_permalink( $product_id ),
        );
    }

    /**
     * Download and attach remote images to WooCommerce product.
     *
     * @param int   $product_id
     * @param array $images
     */
    private static function process_product_images( $product_id, $images ) {
        // Required for media_sideload_image and attachment functions
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_ids = array();

        foreach ( $images as $index => $img ) {
            $img_url = is_array( $img ) && isset( $img['src'] ) ? esc_url_raw( $img['src'] ) : ( is_string( $img ) ? esc_url_raw( $img ) : '' );
            if ( empty( $img_url ) ) continue;

            // Check if we already downloaded this image previously
            $attachment_id = self::get_existing_attachment_id( $img_url );

            if ( ! $attachment_id ) {
                // Sideload new image
                $attachment_id = media_sideload_image( $img_url, $product_id, null, 'id' );

                if ( ! is_wp_error( $attachment_id ) && $attachment_id > 0 ) {
                    update_post_meta( $attachment_id, '_woosync_source_image_url', $img_url );
                } else {
                    continue;
                }
            }

            if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
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
     * Look up attachment by original source image URL.
     *
     * @param string $img_url
     * @return int
     */
    private static function get_existing_attachment_id( $img_url ) {
        global $wpdb;
        $attachment_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_woosync_source_image_url' AND meta_value = %s LIMIT 1",
            $img_url
        ) );

        return $attachment_id ? (int) $attachment_id : 0;
    }
}
