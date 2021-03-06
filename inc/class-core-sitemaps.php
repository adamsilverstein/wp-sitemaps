<?php
/**
 * Sitemaps: Core_Sitemaps class
 *
 * This is the main class integrating all other classes.
 *
 * @package WordPress
 * @subpackage Sitemaps
 * @since x.x.x
 */

/**
 * Class Core_Sitemaps
 */
class Core_Sitemaps {
	/**
	 * The main index of supported sitemaps.
	 *
	 * @var Core_Sitemaps_Index
	 */
	public $index;

	/**
	 * The main registry of supported sitemaps.
	 *
	 * @var Core_Sitemaps_Registry
	 */
	public $registry;

	/**
	 * An instance of the renderer class.
	 *
	 * @var Core_Sitemaps_Renderer
	 */
	public $renderer;

	/**
	 * Core_Sitemaps constructor.
	 */
	public function __construct() {
		$this->index    = new Core_Sitemaps_Index();
		$this->registry = new Core_Sitemaps_Registry();
		$this->renderer = new Core_Sitemaps_Renderer();
	}

	/**
	 * Initiate all sitemap functionality.
	 *
	 * @return void
	 */
	public function init() {
		// These will all fire on the init hook.
		$this->setup_sitemaps_index();
		$this->register_sitemaps();

		// Add additional action callbacks.
		add_action( 'core_sitemaps_init', array( $this, 'register_rewrites' ) );
		add_action( 'template_redirect', array( $this, 'render_sitemaps' ) );
		add_action( 'wp_loaded', array( $this, 'maybe_flush_rewrites' ) );
		add_filter( 'pre_handle_404', array( $this, 'redirect_sitemapxml' ), 10, 2 );
	}

	/**
	 * Set up the main sitemap index.
	 */
	public function setup_sitemaps_index() {
		$this->index->setup_sitemap();
	}

	/**
	 * Register and set up the functionality for all supported sitemaps.
	 */
	public function register_sitemaps() {
		/**
		 * Filters the list of registered sitemap providers.
		 *
		 * @since 0.1.0
		 *
		 * @param array $providers Array of Core_Sitemap_Provider objects.
		 */
		$providers = apply_filters(
			'core_sitemaps_register_providers',
			array(
				'posts'      => new Core_Sitemaps_Posts(),
				'taxonomies' => new Core_Sitemaps_Taxonomies(),
				'users'      => new Core_Sitemaps_Users(),
			)
		);

		// Register each supported provider.
		/* @var Core_Sitemaps_Provider $provider */
		foreach ( $providers as $name => $provider ) {
			$this->registry->add_sitemap( $name, $provider );
		}
	}

	/**
	 * Register sitemap rewrite tags and routing rules.
	 */
	public function register_rewrites() {
		// Add rewrite tags.
		add_rewrite_tag( '%sitemap%', '([^?]+)' );
		add_rewrite_tag( '%sitemap-sub-type%', '([^?]+)' );

		// Register index route.
		add_rewrite_rule( '^wp-sitemap\.xml$', 'index.php?sitemap=index', 'top' );

		// Register rewrites for the XSL stylesheet.
		add_rewrite_tag( '%sitemap-stylesheet%', '([^?]+)' );
		add_rewrite_rule( '^wp-sitemap\.xsl$', 'index.php?sitemap-stylesheet=xsl', 'top' );
		add_rewrite_rule( '^wp-sitemap-index\.xsl$', 'index.php?sitemap-stylesheet=index', 'top' );

		// Register routes for providers.
		add_rewrite_rule(
			'^wp-sitemap-([a-z]+?)-([a-z\d_-]+?)-(\d+?)\.xml$',
			'index.php?sitemap=$matches[1]&sitemap-sub-type=$matches[2]&paged=$matches[3]',
			'top'
		);
		add_rewrite_rule(
			'^wp-sitemap-([a-z]+?)-(\d+?)\.xml$',
			'index.php?sitemap=$matches[1]&paged=$matches[2]',
			'top'
		);
	}

	/**
	 * Unregister sitemap rewrite tags and routing rules.
	 */
	public function unregister_rewrites() {
		/* @var WP_Rewrite $wp_rewrite */
		global $wp_rewrite;

		// Unregister index route.
		unset( $wp_rewrite->extra_rules_top['^wp-sitemap\.xml$'] );

		// Unregister rewrites for the XSL stylesheet.
		unset( $wp_rewrite->extra_rules_top['^wp-sitemap\.xsl$'] );
		unset( $wp_rewrite->extra_rules_top['^wp-sitemap-index\.xsl$'] );

		// Unregister routes for providers.
		unset( $wp_rewrite->extra_rules_top['^wp-sitemap-([a-z]+?)-([a-z\d-]+?)-(\d+?)\.xml$'] );
		unset( $wp_rewrite->extra_rules_top['^wp-sitemap-([a-z]+?)-(\d+?)\.xml$'] );
	}

	/**
	 * Flush rewrite rules if developers updated them.
	 */
	public function maybe_flush_rewrites() {
		if ( update_option( 'core_sitemaps_rewrite_version', CORE_SITEMAPS_REWRITE_VERSION ) ) {
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Render sitemap templates based on rewrite rules.
	 */
	public function render_sitemaps() {
		global $wp_query;

		$sitemap    = sanitize_text_field( get_query_var( 'sitemap' ) );
		$sub_type   = sanitize_text_field( get_query_var( 'sitemap-sub-type' ) );
		$stylesheet = sanitize_text_field( get_query_var( 'sitemap-stylesheet' ) );
		$paged      = absint( get_query_var( 'paged' ) );

		// Bail early if this isn't a sitemap or stylesheet route.
		if ( ! ( $sitemap || $stylesheet ) ) {
			return;
		}

		// Render stylesheet if this is stylesheet route.
		if ( $stylesheet ) {
			$stylesheet = new Core_Sitemaps_Stylesheet();

			$stylesheet->render_stylesheet();
			exit;
		}

		// Render the index.
		if ( 'index' === $sitemap ) {
			$sitemaps = array();

			$providers = $this->registry->get_sitemaps();
			/* @var Core_Sitemaps_Provider $provider */
			foreach ( $providers as $provider ) {
				// Using array_push is more efficient than array_merge in a loop.
				array_push( $sitemaps, ...$provider->get_sitemap_entries() );
			}

			$this->renderer->render_index( $sitemaps );
			exit;
		}

		$provider = $this->registry->get_provider( $sitemap );

		if ( ! $provider ) {
			return;
		}

		if ( empty( $paged ) ) {
			$paged = 1;
		}

		$sub_types = $provider->get_object_sub_types();

		// Only set the current object sub-type if it's supported.
		if ( isset( $sub_types[ $sub_type ] ) ) {
			$provider->set_sub_type( $sub_types[ $sub_type ]->name );
		}

		$url_list = $provider->get_url_list( $paged, $sub_type );

		// Force a 404 and bail early if no URLs are present.
		if ( empty( $url_list ) ) {
			$wp_query->set_404();
			return;
		}

		$this->renderer->render_sitemap( $url_list );
		exit;
	}

	/**
	 * Redirect an URL to the wp-sitemap.xml
	 *
	 * @param bool     $bypass Pass-through of the pre_handle_404 filter value.
	 * @param WP_Query $query The WP_Query object.
	 *
	 * @return bool bypass value.
	 */
	public function redirect_sitemapxml( $bypass, $query ) {
		// If a plugin has already utilized the pre_handle_404 function, return without action to avoid conflicts.
		if ( $bypass ) {
			return $bypass;
		}

		// 'pagename' is for most permalink types, name is for when the %postname% is used as a top-level field.
		if ( 'sitemap-xml' === $query->get( 'pagename' ) ||
			 'sitemap-xml' === $query->get( 'name' ) ) {
			wp_safe_redirect( $this->index->get_index_url() );
			exit();
		}

		return $bypass;
	}
}
