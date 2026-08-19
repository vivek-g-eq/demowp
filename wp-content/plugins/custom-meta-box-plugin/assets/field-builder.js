/**
 * Powers the "Add/Edit Field Group" admin screen: adding/removing field rows
 * (including nested repeater sub-fields), showing the options textarea only
 * for select/radio types, showing the sub-fields editor only for repeater
 * fields, and auto-generating a meta key from the label when the user hasn't
 * set one manually.
 */
(function ( $ ) {
	'use strict';

	function slugify( text ) {
		return text
			.toString()
			.toLowerCase()
			.trim()
			.replace( /[^a-z0-9]+/g, '_' )
			.replace( /^_+|_+$/g, '' );
	}

	function toggleOptionsCell( $row ) {
		var type = $row.find( '.cmbp-field-type' ).first().val();
		var $cell = $row.find( '.cmbp-options-cell' ).first();
		if ( 'select' === type || 'radio' === type ) {
			$cell.show();
		} else {
			$cell.hide();
		}
	}

	function toggleSubfieldsRow( $fieldRow ) {
		var type = $fieldRow.find( '.cmbp-field-type' ).first().val();
		var $subRow = $fieldRow.next( '.cmbp-subfields-row' );
		if ( 'repeater' === type ) {
			$subRow.show();
		} else {
			$subRow.hide();
		}
	}

	$( document ).ready( function () {
		var nextIndex = $( '#cmbp-fields-rows > .cmbp-field-row' ).length;

		// Initialize visibility for all existing top-level rows and their nested sub-field rows.
		$( '#cmbp-fields-rows > .cmbp-field-row' ).each( function () {
			toggleOptionsCell( $( this ) );
			toggleSubfieldsRow( $( this ) );
		} );
		$( '.cmbp-subfields-rows .cmbp-field-row' ).each( function () {
			toggleOptionsCell( $( this ) );
		} );

		// Add a new top-level field row (comes paired with its own hidden sub-fields editor row).
		$( '#cmbp-add-field' ).on( 'click', function () {
			var templateHtml = $( '#cmbp-row-template' ).html();
			templateHtml = templateHtml.split( '__INDEX__' ).join( nextIndex );
			var $newRows = $( templateHtml );
			$( '#cmbp-fields-rows' ).append( $newRows );
			nextIndex++;
		} );

		// Remove a top-level field row, and its paired sub-fields editor row.
		$( '#cmbp-fields-rows' ).on( 'click', '.cmbp-remove-field', function () {
			var $row = $( this ).closest( 'tr.cmbp-field-row' );
			var $subRow = $row.next( '.cmbp-subfields-row' );
			$row.remove();
			$subRow.remove();
		} );

		// Show/hide the options textarea based on selected type (top-level or nested).
		$( document ).on( 'change', '.cmbp-field-type', function () {
			var $row = $( this ).closest( 'tr.cmbp-field-row' );
			toggleOptionsCell( $row );
			// Only top-level rows (direct children of #cmbp-fields-rows) can be repeaters.
			if ( $row.parent().is( '#cmbp-fields-rows' ) ) {
				toggleSubfieldsRow( $row );
			}
		} );

		// Add a sub-field row inside a repeater's nested editor.
		$( document ).on( 'click', '.cmbp-add-subfield', function () {
			var $wrap = $( this ).closest( '.cmbp-subfields-wrap' );
			var parentIndex = $wrap.data( 'parent-index' );
			var $rowsBody = $wrap.find( '.cmbp-subfields-rows' );
			var subIndex = parseInt( $rowsBody.attr( 'data-next-index' ), 10 ) || 0;

			var templateHtml = $( '#cmbp-subfield-row-template' ).html();
			templateHtml = templateHtml.split( '__PARENT__' ).join( parentIndex ).split( '__SUBINDEX__' ).join( subIndex );
			$rowsBody.append( templateHtml );
			$rowsBody.attr( 'data-next-index', subIndex + 1 );
		} );

		// Remove a sub-field row.
		$( document ).on( 'click', '.cmbp-remove-subfield', function () {
			$( this ).closest( 'tr' ).remove();
		} );

		// Auto-generate the meta key from the label unless the user has typed their own key (works for both levels).
		$( document ).on( 'input', '.cmbp-field-label', function () {
			var $row = $( this ).closest( 'tr' );
			var $keyInput = $row.find( '.cmbp-field-key' );
			if ( ! $keyInput.data( 'user-edited' ) ) {
				$keyInput.val( slugify( $( this ).val() ) );
			}
		} );

		$( document ).on( 'input', '.cmbp-field-key', function () {
			$( this ).data( 'user-edited', true );
		} );
	} );
} )( jQuery );
