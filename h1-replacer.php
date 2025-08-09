<?php
/**
 * Plugin Name: H1 Replacer
 * Plugin URI: https://github.com/Webkinetiq/h1-replacer
 * Description: Replaces only the first <h1> on posts, pages, and taxonomy archives with a custom value. Uses core filters first; optional HTML buffer fallback.
 * Version: 1.0.0
 * Author: Webkinetiq
 * Author URI: https://github.com/Webkinetiq
 * License: GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: h1-replacer
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class H1R_Plugin {
    const META_KEY = '_h1r_custom_h1';
    const TERM_KEY = '_h1r_custom_h1_term';

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'plugins_loaded', [ $this, 'i18n' ] );

        // Admin fields
        add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post', [ $this, 'save_post' ] );
        add_action( 'init', [ $this, 'register_taxonomy_fields' ] );

        // Frontend (safe filters first)
        add_filter( 'the_title', [ $this, 'filter_singular_title' ], 10, 2 );
        add_filter( 'get_the_archive_title', [ $this, 'filter_archive_title' ] );

        // Fallback output buffer (optional; ON by default)
        if ( apply_filters( 'h1r_buffer_enabled', true ) ) {
            add_action( 'template_redirect', [ $this, 'maybe_start_buffer' ], 1 );
        }
    }

    public function i18n() {
        load_plugin_textdomain( 'h1-replacer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
    }

    /* ---------- Admin: Post types ---------- */
    public function add_meta_boxes() {
        $post_types = get_post_types( [ 'public' => true ], 'names' );
        foreach ( $post_types as $pt ) {
            add_meta_box( 'h1r_box', __( 'Custom H1', 'h1-replacer' ), [ $this, 'render_meta_box' ], $pt, 'normal', 'high' );
        }
    }

    public function render_meta_box( $post ) {
        $value = get_post_meta( $post->ID, self::META_KEY, true );
        wp_nonce_field( 'h1r_save', 'h1r_nonce' );
        echo '<p><input type="text" style="width:100%" name="h1r_field" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr__( 'Enter custom H1', 'h1-replacer' ) . '"></p>';
        echo '<p class="description">' . esc_html__( 'Leave empty to use the theme default title.', 'h1-replacer' ) . '</p>';
    }

    public function save_post( $post_id ) {
        if ( ! isset( $_POST['h1r_nonce'] ) || ! wp_verify_nonce( $_POST['h1r_nonce'], 'h1r_save' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        $val = isset( $_POST['h1r_field'] ) ? sanitize_text_field( wp_unslash( $_POST['h1r_field'] ) ) : '';
        update_post_meta( $post_id, self::META_KEY, $val );
    }

    /* ---------- Admin: Taxonomies ---------- */
    public function register_taxonomy_fields() {
        $taxes = get_taxonomies( [ 'public' => true ], 'names' );
        foreach ( $taxes as $tax ) {
            add_action( "{$tax}_add_form_fields", function() {
                wp_nonce_field( 'h1r_term_save', 'h1r_term_nonce' );
                echo '<div class="form-field term-h1-wrap">
                    <label for="h1r_term_field">'. esc_html__( 'Custom H1', 'h1-replacer' ) .'</label>
                    <input type="text" name="h1r_term_field" id="h1r_term_field" value="" placeholder="'. esc_attr__( 'Enter custom H1', 'h1-replacer' ) .'">
                </div>';
            } );
            add_action( "{$tax}_edit_form_fields", function( $term ) {
                $value = get_term_meta( $term->term_id, self::TERM_KEY, true );
                echo '<tr class="form-field term-h1-wrap">
                    <th scope="row"><label for="h1r_term_field">'. esc_html__( 'Custom H1', 'h1-replacer' ) .'</label></th>
                    <td>
                        <input type="text" class="regular-text" name="h1r_term_field" id="h1r_term_field" value="'. esc_attr( $value ) .'" placeholder="'. esc_attr__( 'Enter custom H1', 'h1-replacer' ) .'">
                        '. wp_nonce_field( 'h1r_term_save', 'h1r_term_nonce', true, false ) .'
                    </td>
                </tr>';
            }, 10, 1 );
            $save_cb = function( $term_id ) {
                if ( ! isset( $_POST['h1r_term_nonce'] ) || ! wp_verify_nonce( $_POST['h1r_term_nonce'], 'h1r_term_save' ) ) return;
                if ( isset( $_POST['h1r_term_field'] ) ) {
                    update_term_meta( $term_id, self::TERM_KEY, sanitize_text_field( wp_unslash( $_POST['h1r_term_field'] ) ) );
                }
            };
            add_action( "edited_{$tax}", $save_cb, 10, 1 );
            add_action( "create_{$tax}", $save_cb, 10, 1 );
        }
    }

    /* ---------- Helpers ---------- */
    private function get_custom_for_context() {
        if ( is_singular() ) {
            $pid = get_queried_object_id();
            if ( $pid ) {
                $val = get_post_meta( $pid, self::META_KEY, true );
                if ( ! empty( $val ) ) return $val;
            }
        }
        if ( is_category() || is_tag() || is_tax() ) {
            $term = get_queried_object();
            if ( $term && ! is_wp_error( $term ) ) {
                $val = get_term_meta( $term->term_id, self::TERM_KEY, true );
                if ( ! empty( $val ) ) return $val;
            }
        }
        return '';
    }

    /* ---------- Filters (safe path) ---------- */
    public function filter_singular_title( $title, $post_id ) {
        if ( is_admin() ) return $title;
        if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) return $title;
        $val = get_post_meta( $post_id, self::META_KEY, true );
        if ( ! empty( $val ) ) {
            return esc_html( $val );
        }
        return $title;
    }

    public function filter_archive_title( $title ) {
        if ( is_category() || is_tag() || is_tax() ) {
            $term = get_queried_object();
            if ( $term && ! is_wp_error( $term ) ) {
                $val = get_term_meta( $term->term_id, self::TERM_KEY, true );
                if ( ! empty( $val ) ) {
                    return esc_html( $val );
                }
            }
        }
        return $title;
    }

    /* ---------- Fallback: buffer replace first <h1> ---------- */
    public function maybe_start_buffer() {
        if ( is_admin() || is_feed() || is_preview() || is_embed() ) return;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;
        if ( function_exists('wp_is_json_request') && wp_is_json_request() ) return;

        $custom = $this->get_custom_for_context();
        if ( empty( $custom ) ) return;

        ob_start( function( $html ) use ( $custom ) {
            if ( function_exists( 'headers_list' ) ) {
                foreach ( headers_list() as $h ) {
                    if ( stripos( $h, 'Content-Type:' ) === 0 && stripos( $h, 'text/html' ) === false ) {
                        return $html;
                    }
                }
            }
            $pattern     = '#<h1([^>]*)>(.*?)</h1>#is';
            $replacement = '<h1$1>' . esc_html( $custom ) . '</h1>';
            $new = preg_replace( $pattern, $replacement, $html, 1 );
            return is_string( $new ) ? $new : $html;
        } );
    }
}

H1R_Plugin::instance();
