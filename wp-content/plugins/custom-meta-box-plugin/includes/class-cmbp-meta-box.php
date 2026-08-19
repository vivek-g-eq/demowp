<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMBP_Meta_Box {

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script(
			'cmbp-metabox-js',
			CMBP_PLUGIN_URL . 'assets/admin.js',
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

	/** Register one meta box per field group that applies to the current post type. */
	public function add_meta_boxes() {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}
		$post_type = $screen->post_type;
		$groups    = CMBP_Field_Groups::get_for_post_type( $post_type );

		foreach ( $groups as $group_id => $group ) {
			add_meta_box(
				'cmbp_group_' . $group_id,
				$group['title'] ? $group['title'] : __( 'Custom Fields', 'custom-meta-box-plugin' ),
				function ( $post ) use ( $group_id, $group ) {
					$this->render( $post, $group_id, $group );
				},
				$post_type,
				! empty( $group['context'] ) ? $group['context'] : 'side',
				'default'
			);
		}
	}

	/** Render all fields for one group. */
	private function render( $post, $group_id, $group ) {
		wp_nonce_field( 'cmbp_save_' . $group_id, 'cmbp_nonce_' . $group_id );
		echo '<div class="cmbp-field-wrap">';

		foreach ( $group['fields'] as $field ) {
			$meta_key = 'cmbp_' . $field['key'];
			$is_repeater = ( 'repeater' === $field['type'] );

			echo '<div class="cmbp-field' . ( $is_repeater ? ' cmbp-repeater-field' : '' ) . '">';
			echo '<label><strong>' . esc_html( $field['label'] );
			if ( ! empty( $field['required'] ) ) {
				echo ' <span style="color:#b32d2e;">*</span>';
			}
			echo '</strong></label><br/>';

			if ( $is_repeater ) {
				$rows = get_post_meta( $post->ID, $meta_key, true );
				$rows = is_array( $rows ) ? $rows : array();
				$this->render_repeater_field( $group_id, $field, $rows );
			} else {
				$value      = get_post_meta( $post->ID, $meta_key, true );
				$input_name = 'cmbp_field__' . $group_id . '__' . $field['key'];
				$this->render_input( $field, esc_attr( $input_name ), $input_name, $value );
			}

			echo '</div>';
		}

		echo '</div>';
	}

	/** Render a repeater field: existing rows, an "add row" button, and a hidden row template. */
	private function render_repeater_field( $group_id, $field, $rows ) {
		?>
		<table class="widefat cmbp-repeater-table">
			<thead>
				<tr>
					<?php foreach ( $field['sub_fields'] as $sub_field ) : ?>
						<th><?php echo esc_html( $sub_field['label'] ); ?></th>
					<?php endforeach; ?>
					<th style="width:40px;"></th>
				</tr>
			</thead>
			<tbody class="cmbp-repeater-rows" data-next-index="<?php echo esc_attr( count( $rows ) ); ?>">
				<?php foreach ( $rows as $row_index => $row_data ) : ?>
					<?php $this->render_repeater_row( $group_id, $field, $row_index, $row_data ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<button type="button" class="button cmbp-add-repeater-row"><?php esc_html_e( '+ Add Row', 'custom-meta-box-plugin' ); ?></button>
		</p>

		<table style="display:none;"><tbody class="cmbp-repeater-row-template">
			<?php $this->render_repeater_row( $group_id, $field, '__ROWINDEX__', array() ); ?>
		</tbody></table>
		<?php
	}

	/** Render a single repeater row (one <tr> with one <td> per sub-field). */
	private function render_repeater_row( $group_id, $field, $row_index, $row_data ) {
		?>
		<tr class="cmbp-repeater-row">
			<?php foreach ( $field['sub_fields'] as $sub_field ) : ?>
				<td>
					<?php
					$sub_value = isset( $row_data[ $sub_field['key'] ] ) ? $row_data[ $sub_field['key'] ] : '';
					$name      = 'cmbp_repeater__' . $group_id . '__' . $field['key'] . '[' . $row_index . '][' . $sub_field['key'] . ']';
					$this->render_input( $sub_field, esc_attr( $name ), $name, $sub_value );
					?>
				</td>
			<?php endforeach; ?>
			<td><button type="button" class="button cmbp-remove-repeater-row">&times;</button></td>
		</tr>
		<?php
	}

	/** Render the correct input markup for a single field, based on its type. Used for both plain fields and repeater sub-fields. */
	private function render_input( $field, $id, $name, $value ) {
		switch ( $field['type'] ) {

			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%2$s" class="widefat" rows="4">%3$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_textarea( $value )
				);
				break;

			case 'number':
				printf(
					'<input type="number" id="%1$s" name="%2$s" value="%3$s" class="widefat" />',
					esc_attr( $id ), esc_attr( $name ), esc_attr( $value )
				);
				break;

			case 'url':
				printf(
					'<input type="url" id="%1$s" name="%2$s" value="%3$s" class="widefat" />',
					esc_attr( $id ), esc_attr( $name ), esc_url( $value )
				);
				break;

			case 'email':
				printf(
					'<input type="email" id="%1$s" name="%2$s" value="%3$s" class="widefat" />',
					esc_attr( $id ), esc_attr( $name ), esc_attr( $value )
				);
				break;

			case 'date':
				printf(
					'<input type="date" id="%1$s" name="%2$s" value="%3$s" class="widefat" />',
					esc_attr( $id ), esc_attr( $name ), esc_attr( $value )
				);
				break;

			case 'select':
				$options = CMBP_Field_Groups::parse_options( $field['options'] );
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="widefat">';
				echo '<option value="">' . esc_html__( '&mdash; Select &mdash;', 'custom-meta-box-plugin' ) . '</option>';
				foreach ( $options as $opt_value => $opt_label ) {
					printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						esc_attr( $opt_value ),
						selected( $value, $opt_value, false ),
						esc_html( $opt_label )
					);
				}
				echo '</select>';
				break;

			case 'radio':
				$options = CMBP_Field_Groups::parse_options( $field['options'] );
				foreach ( $options as $opt_value => $opt_label ) {
					printf(
						'<label style="display:block;margin-bottom:4px;"><input type="radio" name="%1$s" value="%2$s" %3$s /> %4$s</label>',
						esc_attr( $name ),
						esc_attr( $opt_value ),
						checked( $value, $opt_value, false ),
						esc_html( $opt_label )
					);
				}
				break;

			case 'checkbox':
				printf(
					'<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>',
					esc_attr( $id ), esc_attr( $name ), checked( $value, '1', false ), esc_html__( 'Yes', 'custom-meta-box-plugin' )
				);
				break;

			case 'image':
				$image_id  = absint( $value );
				$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
				echo '<span class="cmbp-image-field-wrap">';
				printf(
					'<img class="cmbp-image-preview" src="%1$s" style="max-width:150px;height:auto;display:%2$s;margin-bottom:6px;" /><br/>',
					esc_url( $image_url ),
					$image_url ? 'block' : 'none'
				);
				printf(
					'<input type="hidden" class="cmbp-image-input" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ), esc_attr( $name ), esc_attr( $image_id )
				);
				echo '<button type="button" class="button cmbp-upload-image-btn">' . esc_html__( 'Select Image', 'custom-meta-box-plugin' ) . '</button> ';
				echo '<button type="button" class="button cmbp-remove-image-btn" style="' . ( $image_url ? '' : 'display:none;' ) . '">' . esc_html__( 'Remove', 'custom-meta-box-plugin' ) . '</button>';
				echo '</span>';
				break;

			case 'text':
			default:
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="widefat" />',
					esc_attr( $id ), esc_attr( $name ), esc_attr( $value )
				);
				break;
		}
	}

	/** Save all field groups that apply to this post on update. */
	public function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$groups = CMBP_Field_Groups::get_for_post_type( $post->post_type );
		if ( empty( $groups ) ) {
			return;
		}

		foreach ( $groups as $group_id => $group ) {
			$nonce_field = 'cmbp_nonce_' . $group_id;
			if ( ! isset( $_POST[ $nonce_field ] ) ||
				! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) ), 'cmbp_save_' . $group_id )
			) {
				continue; // This group's box wasn't on the screen (or failed verification) — skip it.
			}

			foreach ( $group['fields'] as $field ) {
				if ( 'repeater' === $field['type'] ) {
					$this->save_repeater_field( $post_id, $group_id, $field );
				} else {
					$input_name = 'cmbp_field__' . $group_id . '__' . $field['key'];
					$meta_key   = 'cmbp_' . $field['key'];
					$this->save_single_field( $post_id, $field, $input_name, $meta_key );
				}
			}
		}
	}

	/** Sanitize and persist one plain (non-repeater) field's value, based on its declared type. */
	private function save_single_field( $post_id, $field, $input_name, $meta_key ) {
		$raw        = isset( $_POST[ $input_name ] ) ? wp_unslash( $_POST[ $input_name ] ) : null;
		$is_present = isset( $_POST[ $input_name ] );
		$value      = $this->sanitize_field_value( $field, $raw, $is_present );

		if ( 'image' === $field['type'] ) {
			if ( $value ) {
				update_post_meta( $post_id, $meta_key, $value );
			} else {
				delete_post_meta( $post_id, $meta_key );
			}
		} else {
			update_post_meta( $post_id, $meta_key, $value );
		}
	}

	/**
	 * Sanitize and persist a repeater field: reads the nested POST array
	 * (cmbp_repeater__{group}__{field}[row][sub_key]), sanitizes each cell by
	 * its sub-field type, and stores the whole set of rows as one meta value.
	 * Rows where every sub-field is empty are dropped (leftover blank rows).
	 */
	private function save_repeater_field( $post_id, $group_id, $field ) {
		$meta_key      = 'cmbp_' . $field['key'];
		$container_key = 'cmbp_repeater__' . $group_id . '__' . $field['key'];

		$posted_rows = ( isset( $_POST[ $container_key ] ) && is_array( $_POST[ $container_key ] ) )
			? wp_unslash( $_POST[ $container_key ] )
			: array();

		$rows_out = array();

		foreach ( $posted_rows as $row_raw ) {
			if ( ! is_array( $row_raw ) ) {
				continue;
			}

			$row_out   = array();
			$has_value = false;

			foreach ( $field['sub_fields'] as $sub_field ) {
				$sub_key    = $sub_field['key'];
				$raw        = array_key_exists( $sub_key, $row_raw ) ? $row_raw[ $sub_key ] : null;
				$is_present = array_key_exists( $sub_key, $row_raw );
				$value      = $this->sanitize_field_value( $sub_field, $raw, $is_present );

				$row_out[ $sub_key ] = $value;

				if ( '' !== $value && null !== $value && '0' !== (string) $value ) {
					$has_value = true;
				}
				// Checkboxes default to '0' which shouldn't by itself count as "has a value";
				// but any explicitly checked checkbox ('1') or non-empty text/number/etc. does.
				if ( 'checkbox' === $sub_field['type'] && '1' === $value ) {
					$has_value = true;
				}
			}

			if ( $has_value ) {
				$rows_out[] = $row_out;
			}
		}

		if ( ! empty( $rows_out ) ) {
			update_post_meta( $post_id, $meta_key, $rows_out );
		} else {
			delete_post_meta( $post_id, $meta_key );
		}
	}

	/** Sanitize a single value according to a field/sub-field's declared type. Returns the sanitized value (does not save anything). */
	private function sanitize_field_value( $field, $raw, $is_present ) {
		switch ( $field['type'] ) {

			case 'checkbox':
				return $is_present ? '1' : '0';

			case 'textarea':
				return null !== $raw ? sanitize_textarea_field( $raw ) : '';

			case 'number':
				return ( null !== $raw && '' !== $raw ) ? floatval( $raw ) : '';

			case 'url':
				return null !== $raw ? esc_url_raw( $raw ) : '';

			case 'email':
				return null !== $raw ? sanitize_email( $raw ) : '';

			case 'date':
				return ( null !== $raw && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) ? $raw : '';

			case 'select':
			case 'radio':
				$options = CMBP_Field_Groups::parse_options( $field['options'] );
				$allowed = array_keys( $options );
				return ( null !== $raw && in_array( $raw, $allowed, true ) ) ? sanitize_text_field( $raw ) : '';

			case 'image':
				$image_id = null !== $raw ? absint( $raw ) : 0;
				return $image_id > 0 ? $image_id : '';

			case 'text':
			default:
				return null !== $raw ? sanitize_text_field( $raw ) : '';
		}
	}
}
