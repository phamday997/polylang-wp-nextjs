<?php
/**
 * Plugin Name: Polylang Woo – Copy Product Meta on Translate
 * Description: When creating a WooCommerce product translation via Polylang (“+” button), auto-copy price and key WooCommerce settings from the source product.
 * Author: Pham Van day
 * Version: 1.0.0
 * License: GPLv2 or later
 */

if ( ! defined('ABSPATH') ) exit;

class PLL_Woo_Copy_Product_Meta {
    const COPIED_FLAG = '_pll_woo_copied';

    public function __construct() {
        // Fire when Polylang saves a post (runs on creation & updates).
        add_action('pll_save_post', [$this, 'maybe_copy_meta_on_translate'], 10, 2);
    }

    /**
     * Copy WooCommerce product meta/terms on first save of a translated product.
     *
     * @param int  $post_id
     * @param bool $is_translated  (post has a language set)
     */
    public function maybe_copy_meta_on_translate( $post_id, $is_translated ) {
        // Only products
        if ( get_post_type($post_id) !== 'product' ) return;

        // Must be “in a language”
        if ( ! $is_translated ) return;

        // Do not run more than once per product
        if ( get_post_meta($post_id, self::COPIED_FLAG, true) ) return;

        // Find source product (= any translation that is NOT $post_id)
        if ( ! function_exists('pll_get_post_translations') ) return;

        $translations = pll_get_post_translations( $post_id ); // [lang => id]
        if ( ! is_array($translations) || count($translations) < 2 ) {
            // No other language exists to copy from
            return;
        }

        // Choose a different language product as source (commonly your EN base)
        $source_id = $this->pick_source_product_id( $translations, $post_id );
        if ( ! $source_id ) return;

        // Copy core Woo meta
        $this->copy_core_meta( $source_id, $post_id );

        // Copy product attributes (incl. custom product-level attributes)
        $this->copy_product_attributes( $source_id, $post_id );

        // Copy featured image & gallery
        $this->copy_media( $source_id, $post_id );

        // Copy taxonomies (cats/tags) with Polylang mapping for translated terms
        $this->copy_taxonomies_with_polylang( $source_id, $post_id );

        // Optional: copy product type taxonomy (simple/variable/grouped/external)
        $this->copy_product_type( $source_id, $post_id );

        // Mark as copied to prevent overriding later manual edits
        update_post_meta( $post_id, self::COPIED_FLAG, 1 );
    }

    private function pick_source_product_id( array $translations, int $current_id ): ?int {
        foreach ( $translations as $lang => $id ) {
            if ( intval($id) !== intval($current_id) ) {
                return intval($id);
            }
        }
        return null;
    }

    private function copy_core_meta( int $source_id, int $target_id ): void {
        // Common Woo product meta keys to copy; extend as needed
        $meta_keys = [
            // Pricing
            '_regular_price', '_sale_price', '_price',
            '_sale_price_dates_from', '_sale_price_dates_to',

            // Inventory
            '_sku', '_manage_stock', '_stock', '_stock_status', '_backorders', '_low_stock_amount', '_sold_individually',

            // Tax
            '_tax_status', '_tax_class',

            // Shipping/Dimensions
            '_weight', '_length', '_width', '_height',

            // Virtual/Downloadable
            '_virtual', '_downloadable', '_download_limit', '_download_expiry', '_downloadable_files',

            // Catalog/Visibility
            '_visibility', '_featured', '_wc_review_count', '_wc_average_rating',

            // Misc
            '_purchase_note',
        ];

        foreach ( $meta_keys as $key ) {
            $val = get_post_meta( $source_id, $key, true );
            if ( $val !== '' && $val !== null ) {
                update_post_meta( $target_id, $key, maybe_unserialize( $val ) );
            }
        }
    }

    private function copy_product_attributes( int $source_id, int $target_id ): void {
        // Product-level attributes (includes custom/non-taxonomy attributes)
        $product_attributes = get_post_meta( $source_id, '_product_attributes', true );
        if ( ! empty( $product_attributes ) ) {
            update_post_meta( $target_id, '_product_attributes', $product_attributes );
        }
    }

    private function copy_media( int $source_id, int $target_id ): void {
        // Featured image
        $thumb_id = get_post_thumbnail_id( $source_id );
        if ( $thumb_id ) {
            set_post_thumbnail( $target_id, $thumb_id );
        }

        // Gallery (comma-separated attachment IDs)
        $gallery = get_post_meta( $source_id, '_product_image_gallery', true );
        if ( ! empty( $gallery ) ) {
            update_post_meta( $target_id, '_product_image_gallery', $gallery );
        }
    }

    private function copy_taxonomies_with_polylang( int $source_id, int $target_id ): void {
        // Map these taxonomies: product categories & tags (extend if needed)
        $taxonomies = [ 'product_cat', 'product_tag' ];

        // Determine target language
        if ( ! function_exists('pll_get_post_language') || ! function_exists('pll_get_term') ) {
            // Fallback: just copy raw terms
            foreach ( $taxonomies as $tax ) {
                $terms = wp_get_object_terms( $source_id, $tax, [ 'fields' => 'ids' ] );
                if ( ! is_wp_error($terms) ) {
                    wp_set_object_terms( $target_id, $terms, $tax, false );
                }
            }
            return;
        }

        $target_lang = pll_get_post_language( $target_id ); // e.g., 'fr'

        foreach ( $taxonomies as $tax ) {
            $source_terms = wp_get_object_terms( $source_id, $tax, [ 'fields' => 'ids' ] );
            if ( is_wp_error($source_terms) || empty($source_terms) ) {
                // Clear terms if none
                wp_set_object_terms( $target_id, [], $tax, false );
                continue;
            }

            $mapped_target_terms = [];
            foreach ( $source_terms as $source_term_id ) {
                $translated_term_id = pll_get_term( $source_term_id, $target_lang );
                if ( $translated_term_id ) {
                    $mapped_target_terms[] = intval($translated_term_id);
                } else {
                    // If no translation exists for this term, optionally: use the source term
                    // $mapped_target_terms[] = intval($source_term_id);
                }
            }

            // Set only translated terms (keeps language-pure taxonomy)
            wp_set_object_terms( $target_id, $mapped_target_terms, $tax, false );
        }
    }

    private function copy_product_type( int $source_id, int $target_id ): void {
        // product_type is a non-translatable taxonomy. Copy it as-is.
        $types = wp_get_object_terms( $source_id, 'product_type', [ 'fields' => 'ids' ] );
        if ( ! is_wp_error($types) ) {
            wp_set_object_terms( $target_id, $types, 'product_type', false );
        }
    }
}

add_action('plugins_loaded', function() {
    if ( class_exists('WooCommerce') && function_exists('pll_get_post_translations') ) {
        new PLL_Woo_Copy_Product_Meta();
    }
});
