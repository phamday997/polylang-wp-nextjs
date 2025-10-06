<?php
/**
 * Plugin Name: WC REST – Polylang Lang Field + Strong REST Filters (+ Translations map)
 * Description: Adds "lang" to Woo REST responses (products, product_cat, product_tag, items in orders), enforces ?lang=xx / X-WP-Polylang-Lang (and optional ?locale=xx_XX) for products & categories, and exposes a "translations" map so the frontend can jump to the correct slug when switching locale.
 * Author: Pham Van Day (The Officience)
 * Version: 1.6.0
 * Author: You
 * License: GPLv2 or later
 */

if ( ! defined('ABSPATH') ) exit;

class WC_REST_PolyLang_Enforcer {
	public function __construct() {
		// Register lightweight REST fields (lang) for product/terms
		add_action('rest_api_init', [$this, 'register_fields']);

		// 0) Set Polylang current language early for the whole REST request
		add_filter('rest_pre_dispatch', [$this, 'switch_pll_language_early'], 1, 3);

		// 1) WooCommerce REST controllers (products, cats, tags) – inject lang into underlying queries
		add_filter('woocommerce_rest_product_object_query',     [$this, 'inject_lang_into_query'], 10, 2);
		add_filter('woocommerce_rest_product_categories_query', [$this, 'inject_lang_into_query'], 10, 2);
		add_filter('woocommerce_rest_product_tags_query',       [$this, 'inject_lang_into_query'], 10, 2);

		// 1b) Optional: map ?locale=fr_FR -> fr
		add_filter('woocommerce_rest_product_object_query',     [$this, 'map_locale_param'], 11, 2);
		add_filter('woocommerce_rest_product_categories_query', [$this, 'map_locale_param'], 11, 2);
		add_filter('woocommerce_rest_product_tags_query',       [$this, 'map_locale_param'], 11, 2);

		// 2) WP term query filters (catch-all for taxonomy reads)
		add_filter('get_terms_args', [$this, 'inject_lang_into_get_terms_args'], 10, 2);
		add_action('pre_get_terms',  [$this, 'inject_lang_via_pre_get_terms']);

		// 3) As a last resort, tell Polylang what the current language is during REST
		add_filter('pll_current_language', [$this, 'override_pll_current_language_during_rest']);

		/**
		 * ---------------------------------------------------------------------
		 * NEW: Enrich Woo REST responses with "translations" (+ echo back "lang")
		 * ---------------------------------------------------------------------
		 * Why? The frontend needs to know the target product/category slug when
		 * switching locale (especially when slugs differ per locale).
		 */
		add_filter('woocommerce_rest_prepare_product_object', [$this, 'add_translations_to_product_response'], 10, 3); // [ADDED]
		add_filter('woocommerce_rest_prepare_product_cat',    [$this, 'add_translations_to_product_cat_response'], 10, 3); // [ADDED]
		add_filter('woocommerce_rest_prepare_product_tag',    [$this, 'add_translations_to_product_tag_response'], 10, 3); // [ADDED]

		/**
		 * -------------------------------------------------------------
		 * NEW: Minimal custom endpoint to resolve slug by target locale
		 * GET /wp-json/store/v1/product-translation?source_slug=foo-en&to=fr
		 * -> { id, slug, lang }
		 * -------------------------------------------------------------
		 */
		add_action('rest_api_init', [$this, 'register_custom_routes']); // [ADDED]
		
		// [ADDED] Enrich order line_items with Polylang translations of the product
		add_filter('woocommerce_rest_prepare_shop_order_object', [$this, 'add_line_item_translations'], 10, 3);
	}

	/* ---------- Add "lang" field to responses (basic) ---------- */

	public function register_fields() {
		register_rest_field('product', 'lang', [
			'get_callback' => function($obj) {
				return function_exists('pll_get_post_language') ? pll_get_post_language((int)$obj['id']) : null;
			},
			'schema' => ['type' => 'string', 'context' => ['view','edit']],
		]);

		register_rest_field('product_cat', 'lang', [
			'get_callback' => function($obj) {
				return function_exists('pll_get_term_language') ? pll_get_term_language((int)$obj['id']) : null;
			},
			'schema' => ['type' => 'string', 'context' => ['view','edit']],
		]);

		register_rest_field('product_tag', 'lang', [
			'get_callback' => function($obj) {
				return function_exists('pll_get_term_language') ? pll_get_term_language((int)$obj['id']) : null;
			},
			'schema' => ['type' => 'string', 'context' => ['view','edit']],
		]);
	}

	/* ---------- Helpers to read lang/locale from request ---------- */

	private function read_lang_from_request($request) {
		$lang = $request->get_param('lang');
		if ( ! $lang ) {
			$header = is_object($request) ? $request->get_header('X-WP-Polylang-Lang') : '';
			if ($header) $lang = $header;
		}
		if ( ! $lang && function_exists('pll_languages_list') ) {
			$locale = $request->get_param('locale');
			if ($locale) {
				foreach ((array) pll_languages_list(['fields' => []]) as $l) {
					if (!empty($l->locale) && $l->locale === $locale) {
						$lang = $l->slug;
						break;
					}
				}
			}
		}
		return $lang ? sanitize_text_field($lang) : '';
	}

	private function read_lang_from_superglobals() {
		$lang = isset($_GET['lang']) ? sanitize_text_field($_GET['lang']) : '';
		if ( ! $lang && ! empty($_SERVER['HTTP_X_WP_POLYLANG_LANG']) ) {
			$lang = sanitize_text_field($_SERVER['HTTP_X_WP_POLYLANG_LANG']);
		}
		if ( ! $lang && function_exists('pll_languages_list') && isset($_GET['locale']) ) {
			$locale = sanitize_text_field($_GET['locale']);
			foreach ((array) pll_languages_list(['fields' => []]) as $l) {
				if (!empty($l->locale) && $l->locale === $locale) {
					$lang = $l->slug;
					break;
				}
			}
		}
		return $lang;
	}

	/* ---------- 0) Switch Polylang current language early ---------- */

	public function switch_pll_language_early($result, $server, $request) {
		if (!function_exists('pll_switch_language')) return $result;
		$lang = $this->read_lang_from_request($request);
		if ($lang) {
			pll_switch_language($lang); // sets Polylang global for the whole REST request
		}
		return $result;
	}

	/* ---------- 1) Inject into Woo controllers ---------- */

	public function inject_lang_into_query($args, $request) {
		$lang = $this->read_lang_from_request($request);
		if ($lang) {
			$args['lang'] = $lang; // Polylang respects this in WP_Query / term query vars
		}
		return $args;
	}

	public function map_locale_param($args, $request) {
		if (isset($args['lang']) || !function_exists('pll_languages_list')) return $args;
		$locale = $request->get_param('locale');
		if (!$locale) return $args;
		foreach ((array) pll_languages_list(['fields' => []]) as $l) {
			if (!empty($l->locale) && $l->locale === $locale) {
				$args['lang'] = $l->slug;
				break;
			}
		}
		return $args;
	}

	/* ---------- 2) Inject into general WP term queries ---------- */

	public function inject_lang_into_get_terms_args($args, $taxonomies) {
		if (!defined('REST_REQUEST') || !REST_REQUEST) return $args;
		$taxonomies = (array) $taxonomies;
		// limit to Woo taxonomies; extend if you have more
		if (empty(array_intersect($taxonomies, ['product_cat','product_tag']))) return $args;

		$lang = $this->read_lang_from_superglobals();
		if ($lang) $args['lang'] = $lang;
		return $args;
	}

	public function inject_lang_via_pre_get_terms($query) {
		if (!defined('REST_REQUEST') || !REST_REQUEST) return;
		if (empty($query->query_vars['taxonomy'])) return;

		$tax = (array) $query->query_vars['taxonomy'];
		if (empty(array_intersect($tax, ['product_cat','product_tag']))) return;

		$lang = $this->read_lang_from_superglobals();
		if ($lang) {
			$query->query_vars['lang'] = $lang;
		}
	}

	/* ---------- 3) Nudge Polylang's notion of current language ---------- */

	public function override_pll_current_language_during_rest($current) {
		if (!defined('REST_REQUEST') || !REST_REQUEST) return $current;
		$lang = $this->read_lang_from_superglobals();
		return $lang ?: $current;
	}

	/* ======================================================================
	 * [ADDED] 4) Enrich Woo REST product response with "lang" & "translations"
	 * ====================================================================== */

	/**
	 * Adds:
	 * - $data['lang']         : current Polylang language of this product
	 * - $data['translations'] : map like { en: { id, slug }, fr: { id, slug }, ... }
	 *   so the frontend can switch locale and navigate to the proper localized slug.
	 */
	public function add_translations_to_product_response($response, $object, $request) { // [ADDED]
		if (!is_a($response, 'WP_REST_Response')) return $response;
		if (!function_exists('pll_get_post_language') || !function_exists('pll_get_post_translations')) {
			return $response;
		}

		$pid  = (int) $object->get_id();
		$data = $response->get_data();

		// Echo back current post language
		$data['lang'] = pll_get_post_language($pid);

		// Build translations map
		$out = [];
		$map = pll_get_post_translations($pid); // [ 'en' => 123, 'fr' => 456, ... ]
		if (is_array($map)) {
			foreach ($map as $lang => $post_id) {
				$out[$lang] = [
					'id'   => (int) $post_id,
					'slug' => get_post_field('post_name', $post_id),
				];
			}
		}
		$data['translations'] = $out;

		$response->set_data($data);
		return $response;
	}

	/* =================================================================================
	 * [ADDED] 5) Enrich Woo REST product_cat response with "lang" & "translations"
	 * ================================================================================= */

	public function add_translations_to_product_cat_response($response, $term, $request) { // [ADDED]
		if (!is_a($response, 'WP_REST_Response')) return $response;
		if (!function_exists('pll_get_term_language') || !function_exists('pll_get_term_translations')) {
			return $response;
		}

		$data = $response->get_data();
		$tid  = (int) $term->term_id;

		$data['lang'] = pll_get_term_language($tid);

		$out = [];
		$map = pll_get_term_translations($tid); // [ 'en' => 11, 'fr' => 22, ... ]
		if (is_array($map)) {
			foreach ($map as $lang => $term_id) {
				$out[$lang] = [
					'id'   => (int) $term_id,
					'slug' => get_term_field('slug', $term_id, 'product_cat'),
				];
			}
		}
		$data['translations'] = $out;

		$response->set_data($data);
		return $response;
	}

	/* ================================================================================
	 * [ADDED] 6) Enrich Woo REST product_tag response with "lang" & "translations"
	 * ================================================================================ */

	public function add_translations_to_product_tag_response($response, $term, $request) { // [ADDED]
		if (!is_a($response, 'WP_REST_Response')) return $response;
		if (!function_exists('pll_get_term_language') || !function_exists('pll_get_term_translations')) {
			return $response;
		}

		$data = $response->get_data();
		$tid  = (int) $term->term_id;

		$data['lang'] = pll_get_term_language($tid);

		$out = [];
		$map = pll_get_term_translations($tid); // [ 'en' => 11, 'fr' => 22, ... ]
		if (is_array($map)) {
			foreach ($map as $lang => $term_id) {
				$out[$lang] = [
					'id'   => (int) $term_id,
					'slug' => get_term_field('slug', $term_id, 'product_tag'),
				];
			}
		}
		$data['translations'] = $out;

		$response->set_data($data);
		return $response;
	}

	/* =====================================================
	 * [ADDED] 7) Custom REST endpoint to resolve translations
	 * ===================================================== */

	public function register_custom_routes() { // [ADDED]
		register_rest_route('store/v1', '/product-translation', [
			'methods'  => 'GET',
			'callback' => [$this, 'resolve_product_translation'], // [ADDED]
			'permission_callback' => '__return_true',
			'args' => [
				'source_slug' => ['type' => 'string', 'required' => true],
				'to'          => ['type' => 'string', 'required' => true], // target lang slug e.g. 'fr'
			],
		]);
	}

	public function resolve_product_translation(\WP_REST_Request $req) { // [ADDED]
		if (!function_exists('pll_get_post_translations')) {
			return new \WP_Error('no_pll', 'Polylang not found', ['status' => 500]);
		}
		$source_slug = sanitize_text_field($req->get_param('source_slug'));
		$to          = sanitize_text_field($req->get_param('to'));

		if (!$source_slug || !$to) {
			return new \WP_Error('bad_request', 'Missing params', ['status' => 400]);
		}

		$post = get_page_by_path($source_slug, OBJECT, 'product');
		if (!$post) return new \WP_Error('not_found', 'Source not found', ['status' => 404]);

		$map = pll_get_post_translations($post->ID);
		$target_id = $map[$to] ?? 0;
		if (!$target_id) return new \WP_Error('no_target', 'No target translation', ['status' => 404]);

		return [
			'id'   => (int)$target_id,
			'slug' => get_post_field('post_name', $target_id),
			'lang' => $to,
		];
	}

	public function add_line_item_translations($response, $object, $request) {
		if (!is_a($response, 'WP_REST_Response')) return $response;
		
		if (!function_exists('pll_get_post_translations')) return $response;

		$data = $response->get_data();
		if (empty($data['line_items']) || !is_array($data['line_items'])) {
			return $response;
		}

		$langs = [];
		if (function_exists('pll_languages_list')) {
			$list = pll_languages_list(['fields' => []]);
			foreach ((array) $list as $l) {
				if (!empty($l->slug)) $langs[] = $l->slug;
			}
		}

		foreach ($data['line_items'] as $idx => $li) {
			$product_id = 0;
			if (!empty($li['variation_id'])) {
				$product_id = (int) $li['variation_id'];
			}
			if (!$product_id && !empty($li['product_id'])) {
				$product_id = (int) $li['product_id'];
			}
			if (!$product_id) {
				$data['line_items'][$idx]['translations'] = new stdClass(); // {} rỗng
				continue;
			}

			
			$map = pll_get_post_translations($product_id);
			if (!is_array($map) || empty($map)) {
				$data['line_items'][$idx]['translations'] = new stdClass(); // {}
				continue;
			}

			$out = [];
			
			$iter = !empty($langs) ? $langs : array_keys($map);

			foreach ($iter as $lang) {
				if (empty($map[$lang])) continue;
				$pid = (int) $map[$lang];

				$out[$lang] = [
					'id'    => $pid,
					'slug'  => (string) get_post_field('post_name', $pid),
					'title' => (string) get_the_title($pid),
				];
			}

			$data['line_items'][$idx]['translations'] = !empty($out) ? $out : new stdClass();
		}

		$response->set_data($data);
		return $response;
	}

}

add_action('plugins_loaded', function () {
	if (class_exists('WooCommerce')) {
		new WC_REST_PolyLang_Enforcer();
	}
});
