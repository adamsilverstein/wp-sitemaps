<?php
/**
 * Class file for the Core_Sitemaps_Provider class.
 * This class is a base class for other sitemap providers to extend and contains shared functionality.
 *
 * @package Core_Sitemaps
 */

/**
 * Class Core_Sitemaps_Provider
 */
class Core_Sitemaps_Provider {
	/**
	 * Post type name.
	 *
	 * @var string
	 */
	protected $object_type = '';

	/**
	 * Sub type name.
	 *
	 * @var string
	 */
	protected $sub_type = '';

	/**
	 * Sitemap route
	 *
	 * Regex pattern used when building the route for a sitemap.
	 *
	 * @var string
	 */
	public $route = '';

	/**
	 * Sitemap slug
	 *
	 * Used for building sitemap URLs.
	 *
	 * @var string
	 */
	public $slug = '';

	/**
	 * Set up relevant rewrite rules, actions, and filters.
	 */
	public function setup() {
		add_rewrite_rule( $this->route, $this->rewrite_query(), 'top' );
		add_action( 'template_redirect', array( $this, 'render_sitemap' ) );
		add_action( 'core_sitemaps_calculate_lastmod', array( $this, 'calculate_sitemap_lastmod' ), 10, 3 );
	}

	/**
	 * Print the XML to output for a sitemap.
	 */
	public function render_sitemap() {
		global $wp_query;

		$sitemap  = sanitize_text_field( get_query_var( 'sitemap' ) );
		$sub_type = sanitize_text_field( get_query_var( 'sub_type' ) );
		$paged    = absint( get_query_var( 'paged' ) );

		if ( $this->slug === $sitemap ) {
			if ( empty( $paged ) ) {
				$paged = 1;
			}

			$sub_types = $this->get_object_sub_types();

			// Only set the current object sub-type if it's supported.
			if ( isset( $sub_types[ $sub_type ] ) ) {
				$this->sub_type = $sub_types[ $sub_type ]->name;
			}

			$url_list = $this->get_url_list( $paged );

			// Force a 404 and bail early if no URLs are present.
			if ( empty( $url_list ) ) {
				$wp_query->set_404();
				return;
			}

			$renderer = new Core_Sitemaps_Renderer();
			$renderer->render_sitemap( $url_list );
			exit;
		}
	}

	/**
	 * Get a URL list for a post type sitemap.
	 *
	 * @param int $page_num Page of results.
	 * @return array $url_list List of URLs for a sitemap.
	 */
	public function get_url_list( $page_num, $type = null ) {
		if ( ! $type ) {
			$type = $this->get_queried_type();
		}

		$query = new WP_Query(
			array(
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'post_type'              => $type,
				'posts_per_page'         => core_sitemaps_get_max_urls( $this->slug ),
				'paged'                  => $page_num,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
			)
		);

		$posts = $query->get_posts();

		$url_list = array();

		foreach ( $posts as $post ) {
			$url_list[] = array(
				'loc'     => get_permalink( $post ),
				'lastmod' => mysql2date( DATE_W3C, $post->post_modified_gmt, false ),
			);
		}

		/**
		 * Filter the list of URLs for a sitemap before rendering.
		 *
		 * @since 0.1.0
		 *
		 * @param array  $url_list List of URLs for a sitemap.
		 * @param string $type     Name of the post_type.
		 * @param int    $page_num Page of results.
		 */
		return apply_filters( 'core_sitemaps_post_url_list', $url_list, $type, $page_num );
	}

	/**
	 * Query for the add_rewrite_rule. Must match the number of Capturing Groups in the route regex.
	 *
	 * @return string Valid add_rewrite_rule query.
	 */
	public function rewrite_query() {
		return 'index.php?sitemap=' . $this->slug . '&paged=$matches[1]';
	}

	/**
	 * Return object type being queried.
	 *
	 * @return string Name of the object type.
	 */
	public function get_queried_type() {
		$type = $this->sub_type;

		if ( empty( $type ) ) {
			$type = $this->object_type;
		}

		return $type;
	}

	/**
	 * Query for determining the number of pages.
	 *
	 * @param string $type Optional. Object type. Default is null.
	 * @return int Total number of pages.
	 */
	public function max_num_pages( $type = null ) {
		if ( empty( $type ) ) {
			$type = $this->get_queried_type();
		}

		$query = new WP_Query(
			array(
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'post_type'              => $type,
				'posts_per_page'         => core_sitemaps_get_max_urls( $this->slug ),
				'paged'                  => 1,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
			)
		);

		return isset( $query->max_num_pages ) ? $query->max_num_pages : 1;
	}

	/**
	 * List of sitemap pages exposed by this provider.
	 *
	 * The returned data is used to populate the sitemap entries of the index.
	 *
	 * @return array List of sitemaps.
	 */
	public function get_sitemap_entries() {
		$sitemaps = array();

		$sitemap_types = $this->get_object_sub_types();

		foreach ( $sitemap_types as $type ) {
			// Handle object names as strings.
			$name = $type;

			// Handle lists of post-objects.
			if ( isset( $type->name ) ) {
				$name = $type->name;
			}

			$total = $this->max_num_pages( $name );

			for ( $page = 1; $page <= $total; $page ++ ) {
				$loc        = $this->get_sitemap_url( $name, $page );
				$lastmod    = $this->get_sitemap_lastmod( $name, $page );
				$sitemaps[] = array(
					'loc'     => $loc,
					'lastmod' => $lastmod,
				);
			}
		}

		return $sitemaps;
	}

	/**
	 * Get the URL of a sitemap entry.
	 *
	 * @param string $name The name of the sitemap.
	 * @param int    $page The page of the sitemap.
	 * @return string The composed URL for a sitemap entry.
	 */
	public function get_sitemap_url( $name, $page ) {
		global $wp_rewrite;

		$basename = sprintf(
			'/sitemap-%1$s.xml',
			// Accounts for cases where name is not included, ex: sitemaps-users-1.xml.
			implode( '-', array_filter( array( $this->slug, $name, (string) $page ) ) )
		);

		$url = home_url( $basename );

		if ( ! $wp_rewrite->using_permalinks() ) {
			$url = add_query_arg(
				array(
					'sitemap'  => $this->slug,
					'sub_type' => $name,
					'paged'    => $page,
				),
				home_url( '/' )
			);
		}

		return $url;
	}

	/**
	 * Get the last modified date for a sitemap page.
	 *
	 * This will be overridden in provider subclasses.
	 *
	 * @param string $name The name of the sitemap.
	 * @param int    $page The page of the sitemap being returned.
	 * @return string The GMT date of the most recently changed date.
	 */
	public function get_sitemap_lastmod( $name, $page ) {
		$type = implode( '_', array_filter( array( $this->slug, $name, (string) $page ) ) );

		// Check for an option.
		$lastmod = get_option( "core_sitemaps_lasmod_$type", '' );

		// If blank, schedule a job.
		if ( empty( $lastmod ) && ! wp_doing_cron() ) {
			wp_schedule_single_event( time() + 500, 'core_sitemaps_calculate_lastmod', array( $this->slug, $name, $page ) );
		}

		return $lastmod;
	}

	/**
	 * Calculate lastmod date for a sitemap page.
	 *
	 * Calculated value is saved to the database as an option.
	 *
	 * @param string $type    The object type of the page: posts, taxonomies, users, etc.
	 * @param string $subtype The object subtype if applicable, e.g., post type, taxonomy type.
	 * @param int    $page    The page number.
	 */
	public function calculate_sitemap_lastmod( $type, $subtype, $page ) {
		// @todo: clean up the verbiage around type/subtype/slug/object/etc.
		if ( $type !== $this->slug ) {
			return;
		}

		$list = $this->get_url_list( $page, $subtype );

		$times = wp_list_pluck( $list, 'lastmod' );

		usort( $times, function( $a, $b ) {
			return strtotime( $b ) - strtotime( $a );
		} );

		$suffix = implode( '_', array_filter( array( $type, $subtype, (string) $page ) ) );

		update_option( "core_sitemaps_lasmod_$suffix", $times[0] );
	}

	/**
	 * Return the list of supported object sub-types exposed by the provider.
	 *
	 * By default this is the sub_type as specified in the class property.
	 *
	 * @return array List: containing object types or false if there are no subtypes.
	 */
	public function get_object_sub_types() {
		if ( ! empty( $this->sub_type ) ) {
			return array( $this->sub_type );
		}

		/**
		 * To prevent complexity in code calling this function, such as `get_sitemaps()` in this class,
		 * an iterable type is returned. The value false was chosen as it passes empty() checks and
		 * as semantically this provider does not provide sub-types.
		 *
		 * @link https://github.com/GoogleChromeLabs/wp-sitemaps/pull/72#discussion_r347496750
		 */
		return array( false );
	}
}
