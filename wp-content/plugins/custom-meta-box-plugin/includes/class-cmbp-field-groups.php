<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles storage and retrieval of field group configuration.
 *
 * Data shape (stored as a single option, array keyed by group_id):
 *
 * array(
 *   'grp_abc123' => array(
 *     'title'      => 'Post Details',
 *     'post_types' => array( 'post', 'page' ),
 *     'context'    => 'side', // 'side' | 'normal'
 *     'fields'     => array(
 *       array(
 *         'key'      => 'subtitle',
 *         'label'    => 'Subtitle',
 *         'type'     => 'text',
 *         'options'  => '',    // raw options text for select/radio, one per line
 *         'required' => false,
 *       ),
 *       ...
 *     ),
 *   ),
 * )
 */
class CMBP_Field_Groups {

	/** Allowed field types. */
	public static function get_field_types() {
		return array(
			'text'     => __( 'Text', 'custom-meta-box-plugin' ),
			'textarea' => __( 'Textarea', 'custom-meta-box-plugin' ),
			'number'   => __( 'Number', 'custom-meta-box-plugin' ),
			'url'      => __( 'URL', 'custom-meta-box-plugin' ),
			'email'    => __( 'Email', 'custom-meta-box-plugin' ),
			'select'   => __( 'Select', 'custom-meta-box-plugin' ),
			'radio'    => __( 'Radio buttons', 'custom-meta-box-plugin' ),
			'checkbox' => __( 'Checkbox (yes/no)', 'custom-meta-box-plugin' ),
			'date'     => __( 'Date', 'custom-meta-box-plugin' ),
			'image'    => __( 'Image', 'custom-meta-box-plugin' ),
			'repeater' => __( 'Repeater Group', 'custom-meta-box-plugin' ),
		);
	}

	/** Field types allowed inside a repeater (no nested repeaters). */
	public static function get_sub_field_types() {
		$types = self::get_field_types();
		unset( $types['repeater'] );
		return $types;
	}

	/** Get every field group. */
	public static function get_all() {
		$groups = get_option( CMBP_OPTION_KEY, array() );
		return is_array( $groups ) ? $groups : array();
	}

	/** Get a single group by ID. */
	public static function get( $group_id ) {
		$groups = self::get_all();
		return isset( $groups[ $group_id ] ) ? $groups[ $group_id ] : null;
	}

	/** Get groups that apply to a given post type. */
	public static function get_for_post_type( $post_type ) {
		$groups   = self::get_all();
		$filtered = array();
		foreach ( $groups as $group_id => $group ) {
			if ( ! empty( $group['post_types'] ) && in_array( $post_type, $group['post_types'], true ) ) {
				$filtered[ $group_id ] = $group;
			}
		}
		return $filtered;
	}

	/**
	 * Save (create or update) a field group from sanitized admin form input.
	 *
	 * @param string $group_id Existing group ID, or empty string to create a new one.
	 * @param array  $raw      Raw $_POST data for the group.
	 * @return string The group ID that was saved.
	 */
	public static function save( $group_id, $raw ) {
		$groups = self::get_all();

		if ( empty( $group_id ) ) {
			$group_id = 'grp_' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 10 );
		}

		$title      = isset( $raw['title'] ) ? sanitize_text_field( wp_unslash( $raw['title'] ) ) : '';
		$post_types = array();
		if ( ! empty( $raw['post_types'] ) && is_array( $raw['post_types'] ) ) {
			foreach ( $raw['post_types'] as $pt ) {
				$pt = sanitize_key( $pt );
				if ( post_type_exists( $pt ) ) {
					$post_types[] = $pt;
				}
			}
		}
		$context = ( isset( $raw['context'] ) && 'normal' === $raw['context'] ) ? 'normal' : 'side';

		$fields     = array();
		$used_keys  = array();
		$raw_fields = isset( $raw['fields'] ) && is_array( $raw['fields'] ) ? $raw['fields'] : array();
		$valid_types = array_keys( self::get_field_types() );

		foreach ( $raw_fields as $f ) {
			$label = isset( $f['label'] ) ? sanitize_text_field( wp_unslash( $f['label'] ) ) : '';
			if ( '' === $label ) {
				continue; // Skip empty rows.
			}

			$type = isset( $f['type'] ) ? sanitize_key( $f['type'] ) : 'text';
			if ( ! in_array( $type, $valid_types, true ) ) {
				$type = 'text';
			}

			$key = isset( $f['key'] ) ? sanitize_key( $f['key'] ) : '';
			if ( '' === $key ) {
				$key = sanitize_key( $label );
			}
			// Ensure uniqueness within this group.
			$base_key = $key;
			$i        = 1;
			while ( in_array( $key, $used_keys, true ) ) {
				$key = $base_key . '_' . $i;
				$i++;
			}
			$used_keys[] = $key;

			$options = '';
			if ( in_array( $type, array( 'select', 'radio' ), true ) && isset( $f['options'] ) ) {
				$options = sanitize_textarea_field( wp_unslash( $f['options'] ) );
			}

			$required = ! empty( $f['required'] );

			$field_entry = array(
				'key'      => $key,
				'label'    => $label,
				'type'     => $type,
				'options'  => $options,
				'required' => $required,
			);

			if ( 'repeater' === $type ) {
				$field_entry['sub_fields'] = self::sanitize_sub_fields(
					isset( $f['sub_fields'] ) && is_array( $f['sub_fields'] ) ? $f['sub_fields'] : array()
				);
			}

			$fields[] = $field_entry;
		}

		$groups[ $group_id ] = array(
			'title'      => $title,
			'post_types' => $post_types,
			'context'    => $context,
			'fields'     => $fields,
		);

		update_option( CMBP_OPTION_KEY, $groups );

		return $group_id;
	}

	/** Sanitize a repeater field's sub_fields array (same rules as top-level fields, but no nested repeaters). */
	private static function sanitize_sub_fields( $raw_sub_fields ) {
		$sub_fields  = array();
		$used_keys   = array();
		$valid_types = array_keys( self::get_sub_field_types() );

		foreach ( $raw_sub_fields as $sf ) {
			$label = isset( $sf['label'] ) ? sanitize_text_field( wp_unslash( $sf['label'] ) ) : '';
			if ( '' === $label ) {
				continue;
			}

			$type = isset( $sf['type'] ) ? sanitize_key( $sf['type'] ) : 'text';
			if ( ! in_array( $type, $valid_types, true ) ) {
				$type = 'text';
			}

			$key = isset( $sf['key'] ) ? sanitize_key( $sf['key'] ) : '';
			if ( '' === $key ) {
				$key = sanitize_key( $label );
			}
			$base_key = $key;
			$i        = 1;
			while ( in_array( $key, $used_keys, true ) ) {
				$key = $base_key . '_' . $i;
				$i++;
			}
			$used_keys[] = $key;

			$options = '';
			if ( in_array( $type, array( 'select', 'radio' ), true ) && isset( $sf['options'] ) ) {
				$options = sanitize_textarea_field( wp_unslash( $sf['options'] ) );
			}

			$sub_fields[] = array(
				'key'      => $key,
				'label'    => $label,
				'type'     => $type,
				'options'  => $options,
				'required' => ! empty( $sf['required'] ),
			);
		}

		return $sub_fields;
	}

	/** Delete a field group. */
	public static function delete( $group_id ) {
		$groups = self::get_all();
		if ( isset( $groups[ $group_id ] ) ) {
			unset( $groups[ $group_id ] );
			update_option( CMBP_OPTION_KEY, $groups );
		}
	}

	/**
	 * Parse a raw "options" textarea into value/label pairs.
	 * Supports lines like "value : Label" or just "Label" (value = label).
	 */
	public static function parse_options( $raw_options ) {
		$pairs = array();
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw_options );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( strpos( $line, ':' ) !== false ) {
				list( $value, $label ) = array_map( 'trim', explode( ':', $line, 2 ) );
			} else {
				$value = $line;
				$label = $line;
			}
			$pairs[ $value ] = $label;
		}
		return $pairs;
	}
}
