<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMBP_Admin {

	const CAP = 'manage_options';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_cmbp_save_field_group', array( $this, 'handle_save' ) );
		add_action( 'admin_post_cmbp_delete_field_group', array( $this, 'handle_delete' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );
	}

	public function add_menu() {
		add_menu_page(
			__( 'Custom Fields', 'custom-meta-box-plugin' ),
			__( 'Custom Fields', 'custom-meta-box-plugin' ),
			self::CAP,
			'cmbp_field_groups',
			array( $this, 'render_list_page' ),
			'dashicons-list-view',
			25
		);
	}

	public function enqueue_assets( $hook ) {
		if ( strpos( (string) $hook, 'cmbp_field_groups' ) === false ) {
			return;
		}
		wp_enqueue_script(
			'cmbp-builder-js',
			CMBP_PLUGIN_URL . 'assets/field-builder.js',
			array( 'jquery' ),
			CMBP_VERSION,
			true
		);
		wp_enqueue_style(
			'cmbp-admin-css',
			CMBP_PLUGIN_URL . 'assets/admin.css',
			array(),
			CMBP_VERSION
		);
	}

	public function maybe_show_notice() {
		if ( ! isset( $_GET['cmbp_notice'] ) ) {
			return;
		}
		$notice = sanitize_key( wp_unslash( $_GET['cmbp_notice'] ) );
		$messages = array(
			'saved'   => __( 'Field group saved.', 'custom-meta-box-plugin' ),
			'deleted' => __( 'Field group deleted.', 'custom-meta-box-plugin' ),
		);
		if ( isset( $messages[ $notice ] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( $messages[ $notice ] )
			);
		}
	}

	/**
	 * Router: list page vs edit page, based on $_GET params.
	 */
	public function render_list_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'custom-meta-box-plugin' ) );
		}

		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list';

		if ( 'edit' === $view ) {
			$group_id = isset( $_GET['group_id'] ) ? sanitize_text_field( wp_unslash( $_GET['group_id'] ) ) : '';
			$this->render_edit_page( $group_id );
			return;
		}

		$this->render_groups_list();
	}

	/** The main list table of field groups. */
	private function render_groups_list() {
		$groups   = CMBP_Field_Groups::get_all();
		$add_url  = add_query_arg( array( 'page' => 'cmbp_field_groups', 'view' => 'edit' ), admin_url( 'admin.php' ) );
		?>
		<div class="wrap">
			<h1>
				<?php esc_html_e( 'Custom Fields', 'custom-meta-box-plugin' ); ?>
				<a href="<?php echo esc_url( $add_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New Field Group', 'custom-meta-box-plugin' ); ?></a>
			</h1>

			<?php if ( empty( $groups ) ) : ?>
				<p><?php esc_html_e( 'No field groups yet. Click "Add New Field Group" to create your first set of custom fields.', 'custom-meta-box-plugin' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Title', 'custom-meta-box-plugin' ); ?></th>
							<th><?php esc_html_e( 'Post Types', 'custom-meta-box-plugin' ); ?></th>
							<th><?php esc_html_e( 'Fields', 'custom-meta-box-plugin' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'custom-meta-box-plugin' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $groups as $group_id => $group ) : ?>
							<?php
							$edit_url = add_query_arg(
								array( 'page' => 'cmbp_field_groups', 'view' => 'edit', 'group_id' => $group_id ),
								admin_url( 'admin.php' )
							);
							$delete_url = wp_nonce_url(
								add_query_arg(
									array( 'action' => 'cmbp_delete_field_group', 'group_id' => $group_id ),
									admin_url( 'admin-post.php' )
								),
								'cmbp_delete_field_group_' . $group_id
							);
							?>
							<tr>
								<td><strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $group['title'] ? $group['title'] : __( '(untitled)', 'custom-meta-box-plugin' ) ); ?></a></strong></td>
								<td><?php echo esc_html( implode( ', ', $group['post_types'] ) ); ?></td>
								<td><?php echo esc_html( count( $group['fields'] ) ); ?></td>
								<td>
									<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'custom-meta-box-plugin' ); ?></a>
									|
									<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this field group? This cannot be undone.', 'custom-meta-box-plugin' ) ); ?>');" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'custom-meta-box-plugin' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Add/Edit field group screen. */
	private function render_edit_page( $group_id ) {
		$group = $group_id ? CMBP_Field_Groups::get( $group_id ) : null;
		$is_new = empty( $group );

		$title      = $group ? $group['title'] : '';
		$post_types = $group ? $group['post_types'] : array( 'post' );
		$context    = $group ? $group['context'] : 'side';
		$fields     = $group ? $group['fields'] : array(
			array( 'key' => '', 'label' => '', 'type' => 'text', 'options' => '', 'required' => false ),
		);

		$all_post_types = get_post_types( array( 'public' => true ), 'objects' );
		$field_types    = CMBP_Field_Groups::get_field_types();
		$list_url       = add_query_arg( array( 'page' => 'cmbp_field_groups' ), admin_url( 'admin.php' ) );
		?>
		<div class="wrap">
			<h1><?php echo $is_new ? esc_html__( 'Add New Field Group', 'custom-meta-box-plugin' ) : esc_html__( 'Edit Field Group', 'custom-meta-box-plugin' ); ?></h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cmbp_save_field_group" />
				<input type="hidden" name="group_id" value="<?php echo esc_attr( $group_id ); ?>" />
				<?php wp_nonce_field( 'cmbp_save_field_group', 'cmbp_nonce' ); ?>

				<table class="form-table">
					<tr>
						<th><label for="cmbp_group_title"><?php esc_html_e( 'Group Title', 'custom-meta-box-plugin' ); ?></label></th>
						<td><input type="text" id="cmbp_group_title" name="title" value="<?php echo esc_attr( $title ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Post Details', 'custom-meta-box-plugin' ); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Show on Post Types', 'custom-meta-box-plugin' ); ?></th>
						<td>
							<?php foreach ( $all_post_types as $pt ) : ?>
								<label style="margin-right:14px;display:inline-block;">
									<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $post_types, true ) ); ?> />
									<?php echo esc_html( $pt->labels->singular_name ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th><label for="cmbp_context"><?php esc_html_e( 'Box Position', 'custom-meta-box-plugin' ); ?></label></th>
						<td>
							<select id="cmbp_context" name="context">
								<option value="side" <?php selected( $context, 'side' ); ?>><?php esc_html_e( 'Sidebar', 'custom-meta-box-plugin' ); ?></option>
								<option value="normal" <?php selected( $context, 'normal' ); ?>><?php esc_html_e( 'Below Content (Main Column)', 'custom-meta-box-plugin' ); ?></option>
							</select>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Fields', 'custom-meta-box-plugin' ); ?></h2>
				<table class="wp-list-table widefat fixed striped" id="cmbp-fields-table">
					<thead>
						<tr>
							<th style="width:20%;"><?php esc_html_e( 'Label', 'custom-meta-box-plugin' ); ?></th>
							<th style="width:15%;"><?php esc_html_e( 'Name (meta key)', 'custom-meta-box-plugin' ); ?></th>
							<th style="width:15%;"><?php esc_html_e( 'Type', 'custom-meta-box-plugin' ); ?></th>
							<th style="width:30%;"><?php esc_html_e( 'Options (for Select / Radio, one per line: value : Label)', 'custom-meta-box-plugin' ); ?></th>
							<th style="width:10%;"><?php esc_html_e( 'Required', 'custom-meta-box-plugin' ); ?></th>
							<th style="width:10%;"></th>
						</tr>
					</thead>
					<tbody id="cmbp-fields-rows">
						<?php foreach ( $fields as $i => $field ) : ?>
							<?php $this->render_field_row( $i, $field, $field_types ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p>
					<button type="button" class="button" id="cmbp-add-field"><?php esc_html_e( '+ Add Field', 'custom-meta-box-plugin' ); ?></button>
				</p>

				<!-- Hidden template rows used by JS when adding new fields / sub-fields -->
				<table style="display:none;"><tbody id="cmbp-row-template">
					<?php $this->render_field_row( '__INDEX__', array( 'key' => '', 'label' => '', 'type' => 'text', 'options' => '', 'required' => false ), $field_types ); ?>
				</tbody></table>

				<table style="display:none;"><tbody id="cmbp-subfield-row-template">
					<?php $this->render_subfield_row( '__PARENT__', '__SUBINDEX__', array( 'key' => '', 'label' => '', 'type' => 'text', 'options' => '', 'required' => false ), CMBP_Field_Groups::get_sub_field_types() ); ?>
				</tbody></table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Field Group', 'custom-meta-box-plugin' ); ?></button>
					<a href="<?php echo esc_url( $list_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'custom-meta-box-plugin' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	/** Render one field row (used both for existing fields and the JS template), plus a paired sub-fields editor row for repeaters. */
	private function render_field_row( $index, $field, $field_types ) {
		$is_select_type = in_array( $field['type'], array( 'select', 'radio' ), true ) ? '' : 'display:none;';
		$is_repeater    = ( 'repeater' === $field['type'] );
		$sub_fields     = ! empty( $field['sub_fields'] ) ? $field['sub_fields'] : array(
			array( 'key' => '', 'label' => '', 'type' => 'text', 'options' => '', 'required' => false ),
		);
		?>
		<tr class="cmbp-field-row">
			<td>
				<input type="text" name="fields[<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $field['label'] ); ?>" class="cmbp-field-label widefat" placeholder="<?php esc_attr_e( 'e.g. Subtitle', 'custom-meta-box-plugin' ); ?>" />
			</td>
			<td>
				<input type="text" name="fields[<?php echo esc_attr( $index ); ?>][key]" value="<?php echo esc_attr( $field['key'] ); ?>" class="cmbp-field-key widefat" placeholder="<?php esc_attr_e( 'auto', 'custom-meta-box-plugin' ); ?>" />
			</td>
			<td>
				<select name="fields[<?php echo esc_attr( $index ); ?>][type]" class="cmbp-field-type widefat">
					<?php foreach ( $field_types as $type_key => $type_label ) : ?>
						<option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $field['type'], $type_key ); ?>><?php echo esc_html( $type_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td class="cmbp-options-cell" style="<?php echo esc_attr( $is_select_type ); ?>">
				<textarea name="fields[<?php echo esc_attr( $index ); ?>][options]" class="widefat" rows="2" placeholder="red : Red&#10;green : Green"><?php echo esc_textarea( $field['options'] ); ?></textarea>
			</td>
			<td style="text-align:center;">
				<input type="checkbox" name="fields[<?php echo esc_attr( $index ); ?>][required]" value="1" <?php checked( ! empty( $field['required'] ) ); ?> />
			</td>
			<td>
				<button type="button" class="button cmbp-remove-field">&times;</button>
			</td>
		</tr>
		<tr class="cmbp-subfields-row" style="<?php echo $is_repeater ? '' : 'display:none;'; ?>">
			<td colspan="6">
				<div class="cmbp-subfields-wrap" data-parent-index="<?php echo esc_attr( $index ); ?>">
					<strong><?php esc_html_e( 'Sub Fields (columns inside each repeater row)', 'custom-meta-box-plugin' ); ?></strong>
					<table class="wp-list-table widefat fixed striped cmbp-subfields-table">
						<thead>
							<tr>
								<th style="width:20%;"><?php esc_html_e( 'Label', 'custom-meta-box-plugin' ); ?></th>
								<th style="width:15%;"><?php esc_html_e( 'Name', 'custom-meta-box-plugin' ); ?></th>
								<th style="width:15%;"><?php esc_html_e( 'Type', 'custom-meta-box-plugin' ); ?></th>
								<th style="width:30%;"><?php esc_html_e( 'Options', 'custom-meta-box-plugin' ); ?></th>
								<th style="width:10%;"><?php esc_html_e( 'Required', 'custom-meta-box-plugin' ); ?></th>
								<th style="width:10%;"></th>
							</tr>
						</thead>
						<tbody class="cmbp-subfields-rows" data-next-index="<?php echo esc_attr( count( $sub_fields ) ); ?>">
							<?php foreach ( $sub_fields as $sub_index => $sub_field ) : ?>
								<?php $this->render_subfield_row( $index, $sub_index, $sub_field, CMBP_Field_Groups::get_sub_field_types() ); ?>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p>
						<button type="button" class="button cmbp-add-subfield"><?php esc_html_e( '+ Add Sub Field', 'custom-meta-box-plugin' ); ?></button>
					</p>
				</div>
			</td>
		</tr>
		<?php
	}

	/** Render one sub-field row inside a repeater's nested editor. */
	private function render_subfield_row( $parent_index, $sub_index, $field, $sub_field_types ) {
		$is_select_type = in_array( $field['type'], array( 'select', 'radio' ), true ) ? '' : 'display:none;';
		$name_prefix    = 'fields[' . $parent_index . '][sub_fields][' . $sub_index . ']';
		?>
		<tr class="cmbp-field-row">
			<td>
				<input type="text" name="<?php echo esc_attr( $name_prefix ); ?>[label]" value="<?php echo esc_attr( $field['label'] ); ?>" class="cmbp-field-label widefat" placeholder="<?php esc_attr_e( 'e.g. Name', 'custom-meta-box-plugin' ); ?>" />
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $name_prefix ); ?>[key]" value="<?php echo esc_attr( $field['key'] ); ?>" class="cmbp-field-key widefat" placeholder="<?php esc_attr_e( 'auto', 'custom-meta-box-plugin' ); ?>" />
			</td>
			<td>
				<select name="<?php echo esc_attr( $name_prefix ); ?>[type]" class="cmbp-field-type widefat">
					<?php foreach ( $sub_field_types as $type_key => $type_label ) : ?>
						<option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $field['type'], $type_key ); ?>><?php echo esc_html( $type_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td class="cmbp-options-cell" style="<?php echo esc_attr( $is_select_type ); ?>">
				<textarea name="<?php echo esc_attr( $name_prefix ); ?>[options]" class="widefat" rows="2" placeholder="red : Red&#10;green : Green"><?php echo esc_textarea( $field['options'] ); ?></textarea>
			</td>
			<td style="text-align:center;">
				<input type="checkbox" name="<?php echo esc_attr( $name_prefix ); ?>[required]" value="1" <?php checked( ! empty( $field['required'] ) ); ?> />
			</td>
			<td>
				<button type="button" class="button cmbp-remove-subfield">&times;</button>
			</td>
		</tr>
		<?php
	}

	/** Handle save form submission. */
	public function handle_save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'custom-meta-box-plugin' ) );
		}
		if ( ! isset( $_POST['cmbp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cmbp_nonce'] ) ), 'cmbp_save_field_group' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'custom-meta-box-plugin' ) );
		}

		$group_id = isset( $_POST['group_id'] ) ? sanitize_text_field( wp_unslash( $_POST['group_id'] ) ) : '';

		// Pass raw POST array through to the model layer, which sanitizes every field itself.
		$raw = wp_unslash( $_POST );
		CMBP_Field_Groups::save( $group_id, $raw );

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'cmbp_field_groups', 'cmbp_notice' => 'saved' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** Handle delete request. */
	public function handle_delete() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'custom-meta-box-plugin' ) );
		}
		$group_id = isset( $_GET['group_id'] ) ? sanitize_text_field( wp_unslash( $_GET['group_id'] ) ) : '';
		check_admin_referer( 'cmbp_delete_field_group_' . $group_id );

		CMBP_Field_Groups::delete( $group_id );

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'cmbp_field_groups', 'cmbp_notice' => 'deleted' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
