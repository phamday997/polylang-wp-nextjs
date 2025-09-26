<?php
/**
 * Plugin Name: CoCart – Item & Category Translate (Polylang)
 * Description: Adds product translate map per language with {name, slug} and category translations/fallback summary for CoCart responses.
 * Author: Pham Van Day
 * Version: 1.6.0
 * License: GPLv2 or later
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * If true, categories_translate returns objects {id,name,slug}; else just names.
 */
if ( ! defined('COCART_TRANSLATE_FULL_TERMS') ) {
  define('COCART_TRANSLATE_FULL_TERMS', false);
}

final class CoCart_Item_And_Category_Translate {
  public function __construct() {
    // Mutate the final REST response from CoCart after it has been formatted.
    add_filter('rest_post_dispatch', [ $this, 'inject_into_cocart_response' ], 50, 3);
  }

  /* ------------------------ PRODUCTS ------------------------ */

  private function get_base_product_id_from_item_id( int $product_id ) : int {
    $p = wc_get_product($product_id);
    if ( ! $p ) return 0;
    return $p->is_type('variation') ? (int) $p->get_parent_id() : (int) $p->get_id();
  }

  /**
   * Build product translate map per language:
   * { en: {name, slug}, fr: {name, slug}, ... }
   */
  private function build_product_translate_full_map_from_id( int $product_id ) : array {
    $out = [];
    if ( ! function_exists('pll_get_post_translations') ) return $out;

    $base_id = $this->get_base_product_id_from_item_id($product_id);
    if ( ! $base_id ) return $out;

    $map = pll_get_post_translations( $base_id ); // ['en'=>123, 'fr'=>456, ...]
    if ( ! is_array($map) || empty($map) ) return $out;

    static $p_cache = [];

    foreach ( $map as $lang => $post_id ) {
      $post_id = (int) $post_id;

      if ( ! isset($p_cache[$post_id]) ) {
        $p_cache[$post_id] = wc_get_product($post_id) ?: null;
      }

      $name = $p_cache[$post_id] instanceof WC_Product
        ? (string) $p_cache[$post_id]->get_name()
        : (string) get_the_title($post_id);

      $slug = (string) get_post_field('post_name', $post_id);

      $out[$lang] = [
        'name' => $name ?: '',
        'slug' => $slug
      ];
    }

    return $out;
  }

  /* ------------------------ CATEGORIES ------------------------ */

  private function build_term_translate_map( int $term_id, string $taxonomy = 'product_cat' ) : array {
    $out = [];
    if ( ! function_exists('pll_get_term_translations') ) return $out;

    $map = pll_get_term_translations($term_id); // ['en'=>11, 'fr'=>22, ...]
    if ( ! is_array($map) || empty($map) ) return $out;

    foreach ($map as $lang => $tid) {
      $tid  = (int) $tid;
      $name = get_term_field('name', $tid, $taxonomy);
      if (COCART_TRANSLATE_FULL_TERMS) {
        $out[$lang] = [
          'id'   => $tid,
          'name' => is_wp_error($name) ? '' : (string) $name,
          'slug' => (string) get_term_field('slug', $tid, $taxonomy),
        ];
      } else {
        $out[$lang] = is_wp_error($name) ? '' : (string) $name;
      }
    }
    return $out;
  }

  private function build_categories_summary_from_product_translations( int $product_id ) : array {
    $summary = [];
    if ( ! function_exists('pll_get_post_translations') ) return $summary;

    $base_id = $this->get_base_product_id_from_item_id($product_id);
    if ( ! $base_id ) return $summary;

    $pmap = pll_get_post_translations($base_id); // ['en'=>postIdEN, 'fr'=>postIdFR, ...]
    if ( ! is_array($pmap) || empty($pmap) ) return $summary;

    foreach ($pmap as $lang => $pid_lang) {
      $terms = get_the_terms((int) $pid_lang, 'product_cat');
      if ( empty($terms) || is_wp_error($terms) ) continue;

      foreach ($terms as $t) {
        if (!isset($summary[$lang])) $summary[$lang] = [];
        if (COCART_TRANSLATE_FULL_TERMS) {
          $summary[$lang][] = [
            'id'   => (int) $t->term_id,
            'name' => (string) $t->name,
            'slug' => (string) $t->slug,
          ];
        } else {
          $summary[$lang][] = (string) $t->name;
        }
      }
    }

    // Dedupe
    foreach ($summary as $lang => $arr) {
      if (COCART_TRANSLATE_FULL_TERMS) {
        $seen = []; $unique = [];
        foreach ($arr as $obj) {
          if (!isset($seen[$obj['id']])) { $seen[$obj['id']] = true; $unique[] = $obj; }
        }
        $summary[$lang] = $unique;
      } else {
        $summary[$lang] = array_values(array_unique($arr));
      }
    }

    return $summary;
  }

  /* ------------------------ Injector ------------------------ */

  public function inject_into_cocart_response( $response, $server, $request ) {
    $route = is_object($request) ? (string) $request->get_route() : '';
    if (strpos($route, '/cocart/') === false) return $response;
    if (stripos($route, '/cart') === false)    return $response;
    if (!is_a($response, 'WP_REST_Response'))  return $response;

    $data = $response->get_data();
    if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
      return $response;
    }

    foreach ($data['items'] as $idx => $item) {
      if (!is_array($item)) continue;

      // Product ID (CoCart may use "id" or "product_id")
      $product_id = 0;
      if (isset($item['id']))         $product_id = (int) $item['id'];
      if (!$product_id && isset($item['product_id'])) $product_id = (int) $item['product_id'];

      /* ------- product translate (now {name, slug} per lang) ------- */
      if ($product_id > 0) {
        $data['items'][$idx]['translate'] = $this->build_product_translate_full_map_from_id($product_id);
      }

      /* ------- category translate (unchanged) ------- */
      if (isset($item['categories']) && is_array($item['categories'])) {
        $any_term_map = false;

        foreach ($item['categories'] as $cidx => $cat) {
          if (!is_array($cat)) continue;

          $tid = 0;
          if (isset($cat['term_id'])) $tid = (int) $cat['term_id'];
          if (!$tid && isset($cat['id'])) $tid = (int) $cat['id'];

          if ($tid > 0) {
            $term_map = $this->build_term_translate_map($tid, 'product_cat');
            if (!empty($term_map)) {
              $any_term_map = true;
              $data['items'][$idx]['categories'][$cidx]['translate'] = $term_map;
            }
          }
        }

        $summary = $this->build_categories_summary_from_product_translations($product_id);
        if (!empty($summary)) {
          $data['items'][$idx]['categories_translate'] = $summary;
        } elseif (!$any_term_map) {
          $data['items'][$idx]['categories_translate'] = (object)[];
        }
      }
    }

    $response->set_data($data);
    return $response;
  }
}

add_action('plugins_loaded', function () {
  if (class_exists('WooCommerce')) {
    new CoCart_Item_And_Category_Translate();
  }
});
