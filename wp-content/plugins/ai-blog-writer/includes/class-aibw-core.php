<?php
/**
 * Core orchestrator class.
 *
 * @package AI_Blog_Writer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AIBW_Core
 *
 * Wires together settings, admin UI, the API handler, and the
 * post generator. Implemented as a singleton so the plugin is
 * only ever bootstrapped once per request.
 */
final class AIBW_Core {

	/**
	 * Singleton instance.
	 *
	 * @var AIBW_Core|null
	 */
	private static $instance = null;

	/**
	 * Admin page handler.
	 *
	 * @var AIBW_Admin_Page
	 */
	private $admin_page;

	/**
	 * Post generator handler.
	 *
	 * @var AIBW_Post_Generator
	 */
	private $post_generator;

	/**
	 * Get (or create) the singleton instance.
	 *
	 * @return AIBW_Core
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor. Use get_instance() instead.
	 */
	private function __construct() {
		$this->admin_page     = new AIBW_Admin_Page();
		$this->post_generator = new AIBW_Post_Generator();

		$this->init_hooks();
	}

	/**
	 * Prevent cloning of the singleton instance.
	 */
	private function __clone() {}

	/**
	 * Register all WordPress hooks used by the plugin.
	 */
	private function init_hooks() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		add_action( 'admin_menu', array( $this->admin_page, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_post_aibw_save_settings', array( $this->admin_page, 'handle_save_settings' ) );

		add_action( 'wp_ajax_aibw_generate_post', array( $this, 'handle_generate_post_ajax' ) );
	}

	/**
	 * Load the plugin's translation files.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'ai-blog-writer',
			false,
			dirname( AIBW_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Enqueue admin CSS/JS, but only on this plugin's own screens.
	 *
	 * @param string $hook The current admin page hook suffix.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( false === strpos( $hook, 'ai-blog-writer' ) ) {
			return;
		}

		wp_enqueue_style(
			'aibw-admin-style',
			AIBW_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			AIBW_VERSION
		);

		wp_enqueue_script(
			'aibw-admin-script',
			AIBW_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			AIBW_VERSION,
			true
		);

		wp_localize_script(
			'aibw-admin-script',
			'aibwAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'aibw_generate_post_nonce' ),
				'i18n'    => array(
					'generating' => esc_html__( 'Generating your post, please wait…', 'ai-blog-writer' ),
					'error'      => esc_html__( 'Something went wrong. Please try again.', 'ai-blog-writer' ),
					'editPost'   => esc_html__( 'Edit post', 'ai-blog-writer' ),
				),
			)
		);
	}

	/**
	 * AJAX callback: generate a blog post via the AI API and save it.
	 *
	 * Verifies the nonce and capability before doing any work, sanitizes
	 * all incoming request data, and returns a JSON response.
	 */
	public function handle_generate_post_ajax() {
		check_ajax_referer( 'aibw_generate_post_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'You do not have permission to do this.', 'ai-blog-writer' ) ),
				403
			);
		}

		$topic       = isset( $_POST['topic'] ) ? sanitize_text_field( wp_unslash( $_POST['topic'] ) ) : '';
		$keywords    = isset( $_POST['keywords'] ) ? sanitize_text_field( wp_unslash( $_POST['keywords'] ) ) : '';
		$tone        = isset( $_POST['tone'] ) ? sanitize_text_field( wp_unslash( $_POST['tone'] ) ) : 'professional';
		$length      = isset( $_POST['length'] ) ? sanitize_text_field( wp_unslash( $_POST['length'] ) ) : 'medium';
		$post_status = isset( $_POST['post_status'] ) ? sanitize_text_field( wp_unslash( $_POST['post_status'] ) ) : 'draft';

		if ( empty( $topic ) ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Please provide a topic for the blog post.', 'ai-blog-writer' ) ),
				400
			);
		}

		// Wrapped in try/catch so an unexpected fatal (e.g. a malformed API
		// response) always ends in a clean JSON error instead of breaking the
		// AJAX response and showing a generic client-side failure message.
		try {
			$api_handler = new AIBW_API_Handler();
			$result      = $api_handler->generate_content( $topic, $keywords, $tone, $length );
		} catch ( Throwable $e ) {
			wp_send_json_error(
				array(
					/* translators: %s: raw exception message for debugging. */
					'message' => sprintf( esc_html__( 'Unexpected error contacting the AI provider: %s', 'ai-blog-writer' ), $e->getMessage() ),
				),
				500
			);
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		$post_id = $this->post_generator->create_post( $result['title'], $result['content'], $post_status );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ), 500 );
		}

		wp_send_json_success(
			array(
				'message'  => esc_html__( 'Post generated successfully!', 'ai-blog-writer' ),
				'post_id'  => $post_id,
				'edit_url' => esc_url( get_edit_post_link( $post_id, 'raw' ) ),
			)
		);
	}
}
