<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class B2Sell_SEO_Meta {
    public function __construct() {
        add_action( 'wp_head', array( $this, 'render_meta' ), 1 );
        add_action( 'wp_ajax_b2sell_save_seo_meta', array( $this, 'ajax_save' ) );
    }

    public function render_meta() {
        if ( ! is_singular() ) {
            return;
        }
        $id    = get_queried_object_id();
        $title = get_post_meta( $id, '_b2sell_seo_title', true );
        $desc  = get_post_meta( $id, '_b2sell_seo_description', true );
        if ( $title ) {
            remove_action( 'wp_head', '_wp_render_title_tag', 1 );
            echo '<title>' . esc_html( $title ) . '</title>' . "\n";
        }
        if ( $desc ) {
            echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
        }
    }

    public function ajax_save() {
        check_ajax_referer( 'b2sell_seo_meta', 'nonce' );
        $post_id = intval( $_POST['post_id'] ?? 0 );
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error();
        }
        $title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
        $desc  = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
        update_post_meta( $post_id, '_b2sell_seo_title', $title );
        update_post_meta( $post_id, '_b2sell_seo_description', $desc );
        wp_send_json_success();
    }
}

new B2Sell_SEO_Meta();
