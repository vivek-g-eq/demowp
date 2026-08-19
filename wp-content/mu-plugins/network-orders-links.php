<?php
/**
 * Plugin Name: Network Orders Quick Links
 * Description: Adds a direct "Orders" link under each site in the My Sites admin-bar menu.
 * Network: true
 */

add_action( 'admin_bar_menu', function ( $wp_admin_bar ) {
    if ( ! is_multisite() || ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }

    foreach ( $wp_admin_bar->get_nodes() as $node ) {
        // My Sites entries have IDs like "blog-2", "blog-5", etc.
        if ( ! preg_match( '/^blog-(\d+)$/', $node->id, $m ) ) {
            continue;
        }
        $blog_id = (int) $m[1];

        switch_to_blog( $blog_id );
        // Adjust the URL if you're on HPOS (wc-orders) vs legacy (shop_order)
        $orders_url = admin_url( 'edit.php?post_type=shop_order' );
        restore_current_blog();

        $wp_admin_bar->add_node( [
            'parent' => $node->id,
            'id'     => 'orders-' . $blog_id,
            'title'  => 'Orders',
            'href'   => $orders_url,
        ] );
    }
}, 100 );