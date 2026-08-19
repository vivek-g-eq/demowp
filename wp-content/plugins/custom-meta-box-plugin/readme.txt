=== Custom Meta Box Plugin (ACF-style field builder) ===

Build your own custom fields from the WordPress admin — no code editing needed.

== Installation ==
1. Copy the "custom-meta-box-plugin" folder into wp-content/plugins/
2. Activate "Custom Meta Box Plugin" from the Plugins screen.
3. A new "Custom Fields" menu appears in the left admin sidebar.

== Creating fields ==
1. Go to Custom Fields > Add New Field Group.
2. Give the group a title (e.g. "Product Details") — this becomes the meta box heading.
3. Check which post types it should appear on (Post, Page, or any custom post type).
4. Choose the box position: Sidebar or Below Content.
5. Click "+ Add Field" for each field you want:
   - Label: what the editor sees (e.g. "Subtitle")
   - Name: the meta key, auto-generated from the label (editable)
   - Type: Text, Textarea, Number, URL, Email, Select, Radio buttons, Checkbox, Date, Image, Repeater Group
   - Options: for Select/Radio only — one option per line, formatted as `value : Label`
     (or just `Label` on its own, which uses the same text as both value and label)
   - Required: cosmetic marker (*) shown next to the label — enforce however you like in your theme

   Repeater Group fields let editors add multiple rows of sub-fields (e.g. a list of
   team members, gallery items, or FAQ entries). After choosing "Repeater Group" as
   the type, a "Sub Fields" editor appears below that row — add as many columns as
   you like (any type except another Repeater Group), and editors will get an
   "+ Add Row" button on the post edit screen to add as many repeated entries as needed.
6. Click "Save Field Group".

Now edit any post of the post type(s) you selected — your fields appear automatically
in a meta box, no extra code required. You can create as many field groups as you like,
each with its own post types and fields.

== Using the saved values in your theme ==
Every field's value is stored as post meta with the key: cmbp_{field_name}

	$subtitle  = get_post_meta( get_the_ID(), 'cmbp_subtitle', true );
	$image_id  = get_post_meta( get_the_ID(), 'cmbp_hero_image', true );
	$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';
	$featured  = get_post_meta( get_the_ID(), 'cmbp_is_featured', true ); // '1' or '0'

Repeater Group fields are stored as an array of rows, each row an associative array
keyed by its sub-field names:

	$team = get_post_meta( get_the_ID(), 'cmbp_team_members', true ); // array or '' if empty
	if ( is_array( $team ) ) {
		foreach ( $team as $member ) {
			echo esc_html( $member['name'] ) . ' — ' . esc_html( $member['role'] );
			if ( ! empty( $member['photo'] ) ) {
				echo wp_get_attachment_image( $member['photo'], 'thumbnail' );
			}
		}
	}

Tip: on the field group list page, hover the field's Name column value (visible on the
edit screen) to see the exact key you'll need in your template.

== Security ==
* Field-group builder requires the manage_options capability (admins).
* Every save action (group builder and post meta) is nonce-protected.
* Post meta saving requires edit_post capability for that specific post.
* All values are sanitized according to their field type (text, textarea, number,
  url, email, date-format check, whitelisted select/radio values, integer image ID).
* All output is escaped (esc_attr / esc_html / esc_textarea / esc_url).

== Extending ==
* Add more field types: extend get_field_types() in includes/class-cmbp-field-groups.php,
  then add matching cases in render_input() and save_single_field() in
  includes/class-cmbp-meta-box.php.
* Field group data is stored in a single option: cmbp_field_groups.
