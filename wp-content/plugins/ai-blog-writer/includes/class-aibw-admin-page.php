<?php
/**
 * Renders admin pages and handles related form submissions.
 *
 * @package AI_Blog_Writer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AIBW_Admin_Page
 */
class AIBW_Admin_Page {

	/**
	 * Register the top-level menu and its submenu pages.
	 */
	public function register_menu() {
		add_menu_page(
			esc_html__( 'AI Blog Writer', 'ai-blog-writer' ),
			esc_html__( 'AI Blog Writer', 'ai-blog-writer' ),
			'edit_posts',
			'ai-blog-writer',
			array( $this, 'render_generator_page' ),
			'dashicons-edit-large',
			30
		);

		add_submenu_page(
			'ai-blog-writer',
			esc_html__( 'Generate Post', 'ai-blog-writer' ),
			esc_html__( 'Generate Post', 'ai-blog-writer' ),
			'edit_posts',
			'ai-blog-writer',
			array( $this, 'render_generator_page' )
		);

		add_submenu_page(
			'ai-blog-writer',
			esc_html__( 'Settings', 'ai-blog-writer' ),
			esc_html__( 'Settings', 'ai-blog-writer' ),
			'manage_options',
			'ai-blog-writer-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Render the "Generate Post" page with the AI generation form.
	 */
	public function render_generator_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ai-blog-writer' ) );
		}

		$api_key_missing = empty( AIBW_Settings::get( 'api_key' ) );
		$default_tone    = AIBW_Settings::get( 'default_tone', 'professional' );
		$tones           = array( 'professional', 'casual', 'friendly', 'persuasive', 'informative' );
		?>
		<div class="wrap aibw-wrap">
			<h1><?php esc_html_e( 'AI Blog Writer', 'ai-blog-writer' ); ?></h1>

			<?php if ( $api_key_missing ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s: link to the settings page */
							wp_kses(
								/* translators: %s: link to the settings page */
								__( 'Please configure your AI API key on the %s before generating posts.', 'ai-blog-writer' ),
								array( 'a' => array( 'href' => array() ) )
							),
							'<a href="' . esc_url( admin_url( 'admin.php?page=ai-blog-writer-settings' ) ) . '">' . esc_html__( 'settings page', 'ai-blog-writer' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<div id="aibw-notice-area" aria-live="polite"></div>

			<form id="aibw-generate-form" class="aibw-form">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="aibw-topic"><?php esc_html_e( 'Topic', 'ai-blog-writer' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="aibw-topic"
								name="topic"
								class="regular-text"
								required
								placeholder="<?php echo esc_attr__( 'e.g. Benefits of morning yoga for beginners', 'ai-blog-writer' ); ?>"
							/>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="aibw-keywords"><?php esc_html_e( 'Focus Keywords', 'ai-blog-writer' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="aibw-keywords"
								name="keywords"
								class="regular-text"
								placeholder="<?php echo esc_attr__( 'comma, separated, keywords', 'ai-blog-writer' ); ?>"
							/>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="aibw-tone"><?php esc_html_e( 'Tone', 'ai-blog-writer' ); ?></label>
						</th>
						<td>
							<select id="aibw-tone" name="tone">
								<?php foreach ( $tones as $tone ) : ?>
									<option value="<?php echo esc_attr( $tone ); ?>" <?php selected( $default_tone, $tone ); ?>>
										<?php echo esc_html( ucfirst( $tone ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="aibw-length"><?php esc_html_e( 'Length', 'ai-blog-writer' ); ?></label>
						</th>
						<td>
							<select id="aibw-length" name="length">
								<option value="short"><?php esc_html_e( 'Short (300-500 words)', 'ai-blog-writer' ); ?></option>
								<option value="medium" selected="selected"><?php esc_html_e( 'Medium (600-900 words)', 'ai-blog-writer' ); ?></option>
								<option value="long"><?php esc_html_e( 'Long (1200-1600 words)', 'ai-blog-writer' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="aibw-post-status"><?php esc_html_e( 'Save As', 'ai-blog-writer' ); ?></label>
						</th>
						<td>
							<select id="aibw-post-status" name="post_status">
								<option value="draft"><?php esc_html_e( 'Draft', 'ai-blog-writer' ); ?></option>
								<option value="pending"><?php esc_html_e( 'Pending Review', 'ai-blog-writer' ); ?></option>
								<option value="publish"><?php esc_html_e( 'Publish Immediately', 'ai-blog-writer' ); ?></option>
							</select>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button
						type="submit"
						class="button button-primary"
						id="aibw-generate-btn"
						<?php disabled( $api_key_missing ); ?>
					>
						<?php esc_html_e( 'Generate Blog Post', 'ai-blog-writer' ); ?>
					</button>
					<span class="spinner" id="aibw-spinner"></span>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the plugin settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ai-blog-writer' ) );
		}

		$settings = AIBW_Settings::get_all();

		// This is a read-only notice flag from the redirect URL, not a form action, so no nonce is required here.
		$notice = isset( $_GET['aibw_notice'] ) ? sanitize_key( wp_unslash( $_GET['aibw_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap aibw-wrap">
			<h1><?php esc_html_e( 'AI Blog Writer Settings', 'ai-blog-writer' ); ?></h1>

			<?php if ( 'saved' === $notice ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved successfully.', 'ai-blog-writer' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="aibw_save_settings" />
				<?php wp_nonce_field( 'aibw_save_settings_action', 'aibw_settings_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="api_key"><?php esc_html_e( 'AI API Key', 'ai-blog-writer' ); ?></label>
						</th>
						<td>
							<input
								type="password"
								id="api_key"
								name="api_key"
								class="regular-text"
								autocomplete="off"
								value="<?php echo esc_attr( $settings['api_key'] ); ?>"
							/>
							<p class="description">
								<?php esc_html_e( 'Your OpenAI (or compatible) API key. Stored in the WordPress database.', 'ai-blog-writer' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="api_endpoint"><?php esc_html_e( 'API Endpoint', 'ai-blog-writer' ); ?></label>
						</th>
						<td>
							<input
								type="url"
								id="api_endpoint"
								name="api_endpoint"
								class="regular-text"
								value="<?php echo esc_attr( $settings['api_endpoint'] ); ?>"
							/>
							<p class="description">
								<?php esc_html_e( 'Any OpenAI-compatible chat completions endpoint. Default is OpenAI itself; change this to use Groq, OpenRouter, Together AI, etc.', 'ai-blog-writer' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="api_model"><?php esc_html_e( 'Model', 'ai-blog-writer' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="api_model"
								name="api_model"
								class="regular-text"
								value="<?php echo esc_attr( $settings['api_model'] ); ?>"
							/>
							<p class="description">
								<?php esc_html_e( 'e.g. gpt-3.5-turbo (works on a free OpenAI key with no billing), gpt-4o-mini, gpt-4o (require billing set up).', 'ai-blog-writer' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="default_tone"><?php esc_html_e( 'Default Tone', 'ai-blog-writer' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="default_tone"
								name="default_tone"
								class="regular-text"
								value="<?php echo esc_attr( $settings['default_tone'] ); ?>"
							/>
						</td>
					</tr>
				</table>

				<?php submit_button( esc_html__( 'Save Settings', 'ai-blog-writer' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the settings form submission posted to admin-post.php.
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'ai-blog-writer' ) );
		}

		$nonce_value = isset( $_POST['aibw_settings_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['aibw_settings_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce_value, 'aibw_save_settings_action' ) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'ai-blog-writer' ) );
		}

		$input = array(
			'api_key'      => isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '',
			'api_endpoint' => isset( $_POST['api_endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['api_endpoint'] ) ) : '',
			'api_model'    => isset( $_POST['api_model'] ) ? sanitize_text_field( wp_unslash( $_POST['api_model'] ) ) : '',
			'default_tone' => isset( $_POST['default_tone'] ) ? sanitize_text_field( wp_unslash( $_POST['default_tone'] ) ) : '',
		);

		AIBW_Settings::save( $input );

		wp_safe_redirect( admin_url( 'admin.php?page=ai-blog-writer-settings&aibw_notice=saved' ) );
		exit;
	}
}
