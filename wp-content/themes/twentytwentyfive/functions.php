<?php
/**
 * Twenty Twenty-Five functions and definitions.
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package WordPress
 * @subpackage Twenty_Twenty_Five
 * @since Twenty Twenty-Five 1.0
 */

if ( ! function_exists( 'twentytwentyfive_post_format_setup' ) ) :
	/**
	 * Adds theme support for post formats.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_post_format_setup() {
		add_theme_support( 'post-formats', array( 'aside', 'audio', 'chat', 'gallery', 'image', 'link', 'quote', 'status', 'video' ) );
	}
endif;
add_action( 'after_setup_theme', 'twentytwentyfive_post_format_setup' );

if ( ! function_exists( 'twentytwentyfive_editor_style' ) ) :
	/**
	 * Enqueues editor-style.css in the editors.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_editor_style() {
		add_editor_style( 'assets/css/editor-style.css' );
	}
endif;
add_action( 'after_setup_theme', 'twentytwentyfive_editor_style' );

if ( ! function_exists( 'twentytwentyfive_enqueue_styles' ) ) :
	/**
	 * Enqueues the theme stylesheet on the front.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_enqueue_styles() {
		$suffix = SCRIPT_DEBUG ? '' : '.min';
		$src    = 'style' . $suffix . '.css';

		wp_enqueue_style(
			'twentytwentyfive-style',
			get_parent_theme_file_uri( $src ),
			array(),
			wp_get_theme()->get( 'Version' )
		);
		wp_style_add_data(
			'twentytwentyfive-style',
			'path',
			get_parent_theme_file_path( $src )
		);
	}
endif;
add_action( 'wp_enqueue_scripts', 'twentytwentyfive_enqueue_styles' );

if ( ! function_exists( 'twentytwentyfive_block_styles' ) ) :
	/**
	 * Registers custom block styles.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_block_styles() {
		register_block_style(
			'core/list',
			array(
				'name'         => 'checkmark-list',
				'label'        => __( 'Checkmark', 'twentytwentyfive' ),
				'inline_style' => '
				ul.is-style-checkmark-list {
					list-style-type: "\2713";
				}

				ul.is-style-checkmark-list li {
					padding-inline-start: 1ch;
				}',
			)
		);
	}
endif;
add_action( 'init', 'twentytwentyfive_block_styles' );

if ( ! function_exists( 'twentytwentyfive_pattern_categories' ) ) :
	/**
	 * Registers pattern categories.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_pattern_categories() {

		register_block_pattern_category(
			'twentytwentyfive_page',
			array(
				'label'       => __( 'Pages', 'twentytwentyfive' ),
				'description' => __( 'A collection of full page layouts.', 'twentytwentyfive' ),
			)
		);

		register_block_pattern_category(
			'twentytwentyfive_post-format',
			array(
				'label'       => __( 'Post formats', 'twentytwentyfive' ),
				'description' => __( 'A collection of post format patterns.', 'twentytwentyfive' ),
			)
		);
	}
endif;
add_action( 'init', 'twentytwentyfive_pattern_categories' );

if ( ! function_exists( 'twentytwentyfive_register_block_bindings' ) ) :
	/**
	 * Registers the post format block binding source.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_register_block_bindings() {
		register_block_bindings_source(
			'twentytwentyfive/format',
			array(
				'label'              => _x( 'Post format name', 'Label for the block binding placeholder in the editor', 'twentytwentyfive' ),
				'get_value_callback' => 'twentytwentyfive_format_binding',
			)
		);
	}
endif;
add_action( 'init', 'twentytwentyfive_register_block_bindings' );

if ( ! function_exists( 'twentytwentyfive_format_binding' ) ) :
	/**
	 * Callback function for the post format name block binding source.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return string|void Post format name, or nothing if the format is 'standard'.
	 */
	function twentytwentyfive_format_binding() {
		$post_format_slug = get_post_format();

		if ( $post_format_slug && 'standard' !== $post_format_slug ) {
			return get_post_format_string( $post_format_slug );
		}
	}
endif;


/**
 * Register Custom Post Type: News
 * Plus a related custom taxonomy (category-style) for News.
 *
 * Add this code to your theme's functions.php file,
 * or better, inside a small custom plugin file.
 */

if ( ! function_exists( 'register_news_post_type' ) ) {

    function register_news_post_type() {

        // Labels for the post type
        $labels = array(
            'name'                  => _x( 'News', 'Post type general name', 'textdomain' ),
            'singular_name'         => _x( 'News Item', 'Post type singular name', 'textdomain' ),
            'menu_name'             => _x( 'News', 'Admin Menu text', 'textdomain' ),
            'name_admin_bar'        => _x( 'News Item', 'Add New on Toolbar', 'textdomain' ),
            'add_new'               => __( 'Add New', 'textdomain' ),
            'add_new_item'          => __( 'Add New News Item', 'textdomain' ),
            'new_item'              => __( 'New News Item', 'textdomain' ),
            'edit_item'             => __( 'Edit News Item', 'textdomain' ),
            'view_item'             => __( 'View News Item', 'textdomain' ),
            'all_items'             => __( 'All News', 'textdomain' ),
            'search_items'          => __( 'Search News', 'textdomain' ),
            'not_found'             => __( 'No news found.', 'textdomain' ),
            'not_found_in_trash'    => __( 'No news found in Trash.', 'textdomain' ),
            'featured_image'        => _x( 'News Cover Image', 'Overrides the "Featured Image" phrase', 'textdomain' ),
            'set_featured_image'    => _x( 'Set cover image', 'Overrides the "Set featured image" phrase', 'textdomain' ),
            'remove_featured_image' => _x( 'Remove cover image', 'Overrides the "Remove featured image" phrase', 'textdomain' ),
            'use_featured_image'    => _x( 'Use as cover image', 'Overrides the "Use as featured image" phrase', 'textdomain' ),
            'archives'              => _x( 'News archives', 'The post type archive label', 'textdomain' ),
            'insert_into_item'      => _x( 'Insert into news item', 'Overrides the "Insert into post" phrase', 'textdomain' ),
            'uploaded_to_this_item' => _x( 'Uploaded to this news item', 'Overrides the "Uploaded to this post" phrase', 'textdomain' ),
        );

        // Arguments for the post type
        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_nav_menus'  => true,
            'show_in_admin_bar'  => true,
            'show_in_rest'       => true, // enables Gutenberg block editor + REST API support
            'query_var'          => true,
            'rewrite'            => array( 'slug' => 'news' ), // URL: /news/post-name
            'capability_type'    => 'post',
            'has_archive'        => true, // enables /news/ archive page
            'hierarchical'       => false,
            'menu_position'      => 5,
            'menu_icon'          => 'dashicons-media-document',
            'supports'           => array( 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'custom-fields', 'revisions' ),
        );

        register_post_type( 'news', $args );
    }
    add_action( 'init', 'register_news_post_type' );
}


/**
 * Register a custom taxonomy for News (like categories)
 */
if ( ! function_exists( 'register_news_taxonomy' ) ) {

    function register_news_taxonomy() {

        $labels = array(
            'name'              => _x( 'News Categories', 'taxonomy general name', 'textdomain' ),
            'singular_name'     => _x( 'News Category', 'taxonomy singular name', 'textdomain' ),
            'search_items'      => __( 'Search News Categories', 'textdomain' ),
            'all_items'         => __( 'All News Categories', 'textdomain' ),
            'parent_item'       => __( 'Parent News Category', 'textdomain' ),
            'parent_item_colon' => __( 'Parent News Category:', 'textdomain' ),
            'edit_item'         => __( 'Edit News Category', 'textdomain' ),
            'update_item'       => __( 'Update News Category', 'textdomain' ),
            'add_new_item'      => __( 'Add New News Category', 'textdomain' ),
            'new_item_name'     => __( 'New News Category Name', 'textdomain' ),
            'menu_name'         => __( 'News Categories', 'textdomain' ),
        );

        $args = array(
            'hierarchical'      => true, // true = behaves like categories, false = behaves like tags
            'labels'            => $labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'query_var'         => true,
            'rewrite'           => array( 'slug' => 'news-category' ),
        );

        register_taxonomy( 'news_category', array( 'news' ), $args );
    }
    add_action( 'init', 'register_news_taxonomy' );
}


/**
 * Optional: also register a non-hierarchical "News Tags" taxonomy
 */
if ( ! function_exists( 'register_news_tags_taxonomy' ) ) {

    function register_news_tags_taxonomy() {

        $args = array(
            'hierarchical'      => false, // false = tag-like (non-hierarchical)
            'labels'            => array(
                'name'          => _x( 'News Tags', 'taxonomy general name', 'textdomain' ),
                'singular_name' => _x( 'News Tag', 'taxonomy singular name', 'textdomain' ),
                'menu_name'     => __( 'News Tags', 'textdomain' ),
            ),
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'query_var'         => true,
            'rewrite'           => array( 'slug' => 'news-tag' ),
        );

        register_taxonomy( 'news_tag', array( 'news' ), $args );
    }
    add_action( 'init', 'register_news_tags_taxonomy' );
}



/* ==========================================================================
   1. Register Custom Post Type: property
   ========================================================================== */

/**
 * Register custom post type: property
 */
function myp_register_property_cpt() {
    $labels = array(
        'name'               => _x( 'Properties', 'post type general name', 'myp' ),
        'singular_name'      => _x( 'Property', 'post type singular name', 'myp' ),
        'menu_name'          => _x( 'Properties', 'admin menu', 'myp' ),
        'add_new'            => _x( 'Add New', 'property', 'myp' ),
        'add_new_item'       => __( 'Add New Property', 'myp' ),
        'edit_item'          => __( 'Edit Property', 'myp' ),
        'new_item'           => __( 'New Property', 'myp' ),
        'view_item'          => __( 'View Property', 'myp' ),
        'search_items'       => __( 'Search Properties', 'myp' ),
        'not_found'          => __( 'No properties found', 'myp' ),
        'not_found_in_trash' => __( 'No properties found in Trash', 'myp' ),
        'all_items'          => __( 'All Properties', 'myp' ),
        'archive'            => __( 'Property Archives', 'myp' ),
    );

    $args = array(
        'labels'             => $labels,
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'show_in_nav_menus'  => true,
        'show_in_admin_bar'  => true,
        'show_in_rest'       => true,
        'has_archive'        => true,
        'capability_type'    => 'post',
        'map_meta_cap'       => true,
        'hierarchical'       => false,
        'rewrite'            => array( 'slug' => 'property', 'with_front' => true ),
        'query_var'          => 'property',
        'supports'           => array(
            'title',
            'editor',
            'thumbnail',
            'excerpt',
            'comments',
            'revisions',
            'author',
        ),
        'menu_icon'          => 'dashicons-building',
    );

    register_post_type( 'property', $args );
}
add_action( 'init', 'myp_register_property_cpt' );


/* ==========================================================================
   2. Register Custom Taxonomies
   ========================================================================== */

/**
 * Register custom taxonomies for property
 */
function myp_register_property_taxonomies() {
    // Property Type (non-hierarchical)
    $labels_type = array(
        'name'              => _x( 'Property Types', 'taxonomy general name', 'myp' ),
        'singular_name'     => _x( 'Property Type', 'taxonomy singular name', 'myp' ),
        'search_items'      => __( 'Search Property Types', 'myp' ),
        'all_items'         => __( 'All Property Types', 'myp' ),
        'edit_item'         => __( 'Edit Property Type', 'myp' ),
        'update_item'       => __( 'Update Property Type', 'myp' ),
        'add_new_item'      => __( 'Add New Property Type', 'myp' ),
        'new_item_name'     => __( 'New Property Type Name', 'myp' ),
        'menu_name'         => __( 'Property Types', 'myp' ),
    );

    $args_type = array(
        'hierarchical'      => false,
        'labels'            => $labels_type,
        'show_ui'           => true,
        'show_admin_column' => true,
        'query_var'         => 'property_type',
        'rewrite'           => array( 'slug' => 'property-type' ),
        'show_in_rest'      => true,
    );

    register_taxonomy( 'property_type', array( 'property' ), $args_type );

    // Property Location (hierarchical)
    $labels_location = array(
        'name'              => _x( 'Locations', 'taxonomy general name', 'myp' ),
        'singular_name'     => _x( 'Location', 'taxonomy singular name', 'myp' ),
        'search_items'      => __( 'Search Locations', 'myp' ),
        'all_items'         => __( 'All Locations', 'myp' ),
        'parent_item'       => __( 'Parent Location', 'myp' ),
        'parent_item_colon' => __( 'Parent Location:', 'myp' ),
        'edit_item'         => __( 'Edit Location', 'myp' ),
        'update_item'       => __( 'Update Location', 'myp' ),
        'add_new_item'      => __( 'Add New Location', 'myp' ),
        'new_item_name'     => __( 'New Location Name', 'myp' ),
        'menu_name'         => __( 'Locations', 'myp' ),
    );

    $args_location = array(
        'hierarchical'      => true,
        'labels'            => $labels_location,
        'show_ui'           => true,
        'show_admin_column' => true,
        'query_var'         => 'property_location',
        'rewrite'           => array( 'slug' => 'property-location' ),
        'show_in_rest'      => true,
    );

    register_taxonomy( 'property_location', array( 'property' ), $args_location );

    // Property Features (non-hierarchical)
    $labels_feature = array(
        'name'              => _x( 'Features', 'taxonomy general name', 'myp' ),
        'singular_name'     => _x( 'Feature', 'taxonomy singular name', 'myp' ),
        'search_items'      => __( 'Search Features', 'myp' ),
        'all_items'         => __( 'All Features', 'myp' ),
        'edit_item'         => __( 'Edit Feature', 'myp' ),
        'update_item'       => __( 'Update Feature', 'myp' ),
        'add_new_item'      => __( 'Add New Feature', 'myp' ),
        'new_item_name'     => __( 'New Feature Name', 'myp' ),
        'menu_name'         => __( 'Features', 'myp' ),
    );

    $args_feature = array(
        'hierarchical'      => false,
        'labels'            => $labels_feature,
        'show_ui'           => true,
        'show_admin_column' => true,
        'query_var'         => 'property_feature',
        'rewrite'           => array( 'slug' => 'property-feature' ),
        'show_in_rest'      => true,
    );

    register_taxonomy( 'property_feature', array( 'property' ), $args_feature );
}
add_action( 'init', 'myp_register_property_taxonomies' );


/* ==========================================================================
   3. Meta Box: Property Details
   ========================================================================== */

/**
 * Add meta boxes for property details
 */
function myp_add_property_meta_boxes() {
    add_meta_box(
        'myp_property_details',
        __( 'Property Details', 'myp' ),
        'myp_property_details_callback',
        'property',
        'normal',
        'default'
    );
}
add_action( 'add_meta_boxes', 'myp_add_property_meta_boxes' );

/**
 * Meta box HTML callback
 */
function myp_property_details_callback( $post ) {
    // Nonce
    wp_nonce_field( 'myp_save_property_details', 'myp_property_details_nonce' );

    // Existing values
    $price     = get_post_meta( $post->ID, '_myp_price', true );
    $beds      = get_post_meta( $post->ID, '_myp_beds', true );
    $baths     = get_post_meta( $post->ID, '_myp_baths', true );
    $area      = get_post_meta( $post->ID, '_myp_area', true );
    $area_unit = get_post_meta( $post->ID, '_myp_area_unit', true );
    $address   = get_post_meta( $post->ID, '_myp_address', true );
    $status    = get_post_meta( $post->ID, '_myp_status', true );

    if ( ! $area_unit ) {
        $area_unit = 'sqft';
    }
    if ( ! $status ) {
        $status = 'for_sale';
    }
    ?>
    <table class="form-table" style="width:100%;">
        <tr>
            <th style="width: 200px;"><label for="myp_price"><?php esc_html_e( 'Price', 'myp' ); ?></label></th>
            <td>
                <input type="number" step="0.01" name="myp_price" id="myp_price" value="<?php echo esc_attr( $price ); ?>" class="regular-text" />
                <p class="description"><?php esc_html_e( 'Enter property price.', 'myp' ); ?></p>
            </td>
        </tr>
        <tr>
            <th><label for="myp_beds"><?php esc_html_e( 'Bedrooms', 'myp' ); ?></label></th>
            <td>
                <input type="number" name="myp_beds" id="myp_beds" value="<?php echo esc_attr( $beds ); ?>" class="small-text" />
            </td>
        </tr>
        <tr>
            <th><label for="myp_baths"><?php esc_html_e( 'Bathrooms', 'myp' ); ?></label></th>
            <td>
                <input type="number" step="0.5" name="myp_baths" id="myp_baths" value="<?php echo esc_attr( $baths ); ?>" class="small-text" />
            </td>
        </tr>
        <tr>
            <th><label for="myp_area"><?php esc_html_e( 'Area', 'myp' ); ?></label></th>
            <td>
                <input type="number" step="0.01" name="myp_area" id="myp_area" value="<?php echo esc_attr( $area ); ?>" class="small-text" />
                <select name="myp_area_unit" id="myp_area_unit" style="margin-left:5px;">
                    <option value="sqft" <?php selected( $area_unit, 'sqft' ); ?>><?php esc_html_e( 'sq ft', 'myp' ); ?></option>
                    <option value="sqm" <?php selected( $area_unit, 'sqm' ); ?>><?php esc_html_e( 'sq m', 'myp' ); ?></option>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="myp_address"><?php esc_html_e( 'Address', 'myp' ); ?></label></th>
            <td>
                <input type="text" name="myp_address" id="myp_address" value="<?php echo esc_attr( $address ); ?>" class="large-text" />
            </td>
        </tr>
        <tr>
            <th><label for="myp_status"><?php esc_html_e( 'Status', 'myp' ); ?></label></th>
            <td>
                <select name="myp_status" id="myp_status">
                    <option value="for_sale" <?php selected( $status, 'for_sale' ); ?>><?php esc_html_e( 'For Sale', 'myp' ); ?></option>
                    <option value="for_rent" <?php selected( $status, 'for_rent' ); ?>><?php esc_html_e( 'For Rent', 'myp' ); ?></option>
                    <option value="sold" <?php selected( $status, 'sold' ); ?>><?php esc_html_e( 'Sold', 'myp' ); ?></option>
                    <option value="rented" <?php selected( $status, 'rented' ); ?>><?php esc_html_e( 'Rented', 'myp' ); ?></option>
                </select>
            </td>
        </tr>
    </table>
    <?php
}


/* ==========================================================================
   4. Save Meta Box Data
   ========================================================================== */

/**
 * Save property meta box data
 */
function myp_save_property_details( $post_id, $post ) {
    // Nonce check
    if ( ! isset( $_POST['myp_property_details_nonce'] ) ||
         ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['myp_property_details_nonce'] ) ), 'myp_save_property_details' ) ) {
        return;
    }

    // Autosave check
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    // Capability check
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    // Post type check
    if ( 'property' !== $post->post_type ) {
        return;
    }

    // Sanitize and save
    if ( isset( $_POST['myp_price'] ) ) {
        update_post_meta( $post_id, '_myp_price', sanitize_text_field( wp_unslash( $_POST['myp_price'] ) ) );
    }

    if ( isset( $_POST['myp_beds'] ) ) {
        update_post_meta( $post_id, '_myp_beds', intval( $_POST['myp_beds'] ) );
    }

    if ( isset( $_POST['myp_baths'] ) ) {
        update_post_meta( $post_id, '_myp_baths', floatval( $_POST['myp_baths'] ) );
    }

    if ( isset( $_POST['myp_area'] ) ) {
        update_post_meta( $post_id, '_myp_area', floatval( $_POST['myp_area'] ) );
    }

    if ( isset( $_POST['myp_area_unit'] ) ) {
        update_post_meta( $post_id, '_myp_area_unit', sanitize_text_field( wp_unslash( $_POST['myp_area_unit'] ) ) );
    }

    if ( isset( $_POST['myp_address'] ) ) {
        update_post_meta( $post_id, '_myp_address', sanitize_text_field( wp_unslash( $_POST['myp_address'] ) ) );
    }

    if ( isset( $_POST['myp_status'] ) ) {
        update_post_meta( $post_id, '_myp_status', sanitize_text_field( wp_unslash( $_POST['myp_status'] ) ) );
    }
}
add_action( 'save_post_property', 'myp_save_property_details', 10, 2 );


/* ==========================================================================
   5. Admin List Columns
   ========================================================================== */

/**
 * Add custom columns to property list
 */
function myp_property_custom_columns( $columns ) {
    $new_columns = array();

    foreach ( $columns as $key => $value ) {
        $new_columns[ $key ] = $value;

        if ( 'title' === $key ) {
            $new_columns['myp_price']  = __( 'Price', 'myp' );
            $new_columns['myp_beds']   = __( 'Beds', 'myp' );
            $new_columns['myp_baths']  = __( 'Baths', 'myp' );
            $new_columns['myp_area']   = __( 'Area', 'myp' );
            $new_columns['myp_status'] = __( 'Status', 'myp' );
        }
    }

    return $new_columns;
}
add_filter( 'manage_property_posts_columns', 'myp_property_custom_columns' );

/**
 * Populate custom columns content
 */
function myp_property_custom_column_content( $column, $post_id ) {
    switch ( $column ) {
        case 'myp_price':
            $price = get_post_meta( $post_id, '_myp_price', true );
            echo $price ? esc_html( $price ) : '–';
            break;

        case 'myp_beds':
            $beds = get_post_meta( $post_id, '_myp_beds', true );
            echo $beds ? intval( $beds ) : '–';
            break;

        case 'myp_baths':
            $baths = get_post_meta( $post_id, '_myp_baths', true );
            echo $baths ? floatval( $baths ) : '–';
            break;

        case 'myp_area':
            $area      = get_post_meta( $post_id, '_myp_area', true );
            $area_unit = get_post_meta( $post_id, '_myp_area_unit', true );
            if ( $area ) {
                echo esc_html( $area ) . ' ' . esc_html( $area_unit ?: 'sqft' );
            } else {
                echo '–';
            }
            break;

        case 'myp_status':
            $status = get_post_meta( $post_id, '_myp_status', true );
            $labels = array(
                'for_sale' => __( 'For Sale', 'myp' ),
                'for_rent' => __( 'For Rent', 'myp' ),
                'sold'     => __( 'Sold', 'myp' ),
                'rented'   => __( 'Rented', 'myp' ),
            );
            echo isset( $labels[ $status ] ) ? esc_html( $labels[ $status ] ) : '–';
            break;
    }
}
add_action( 'manage_property_posts_custom_column', 'myp_property_custom_column_content', 10, 2 );