<?php
/**
 * Converts AI-generated content into a WordPress post.
 *
 * @package AI_Blog_Writer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AIBW_Post_Generator
 */
class AIBW_Post_Generator {

	/**
	 * Allowed post statuses that this plugin is permitted to set.
	 *
	 * @var string[]
	 */
	private $allowed_statuses = array( 'draft', 'publish', 'pending' );

	/**
	 * Create a WordPress post from a generated title/content pair.
	 *
	 * @param string $title       Post title.
	 * @param string $content     Post content (HTML).
	 * @param string $post_status Requested post status.
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	public function create_post( $title, $content, $post_status = 'draft' ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'aibw_permission_denied',
				esc_html__( 'You do not have permission to create posts.', 'ai-blog-writer' )
			);
		}

		if ( ! in_array( $post_status, $this->allowed_statuses, true ) ) {
			$post_status = 'draft';
		}

		$post_data = array(
			'post_title'   => sanitize_text_field( $title ),
			'post_content' => wp_kses_post( $content ),
			'post_status'  => $post_status,
			'post_type'    => 'post',
			'post_author'  => get_current_user_id(),
		);

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Flag this post as AI-generated for future reference/reporting.
		update_post_meta( $post_id, '_aibw_generated', 1 );

		return $post_id;
	}
}
