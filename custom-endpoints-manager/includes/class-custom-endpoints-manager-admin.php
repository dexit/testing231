<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @package    Custom_Endpoints_Manager
 * @subpackage Custom_Endpoints_Manager/includes
 */
class Custom_Endpoints_Manager_Admin {

    private $plugin_name;
    private $version;

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version     = $version;
    }

    public function enqueue_styles() {
        wp_enqueue_style(
            $this->plugin_name,
            CEM_PLUGIN_URL . 'admin/css/custom-endpoints-manager-admin.css',
            array(),
            $this->version,
            'all'
        );
    }

    public function enqueue_scripts() {
        wp_enqueue_script(
            $this->plugin_name,
            CEM_PLUGIN_URL . 'admin/js/custom-endpoints-manager-admin.js',
            array( 'jquery' ),
            $this->version,
            true
        );
        wp_localize_script( $this->plugin_name, 'cem_ajax_object', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'cem_nonce' ),
        ) );
        wp_localize_script( $this->plugin_name, 'cemI18n', array(
            'selectMicroplugin' => __( '— Select Microplugin —', 'custom-endpoints-manager' ),
            'remove'            => __( 'Remove', 'custom-endpoints-manager' ),
        ) );
    }

    public function add_options_page() {
        add_options_page(
            __( 'Custom Endpoints Manager', 'custom-endpoints-manager' ),
            __( 'Custom Endpoints', 'custom-endpoints-manager' ),
            'manage_options',
            'custom-endpoints-manager',
            array( $this, 'display_options_page' )
        );
    }

    public function display_options_page() {
        include_once CEM_PLUGIN_DIR . 'admin/partials/custom-endpoints-manager-admin-display.php';
    }

    public function save_custom_endpoints() {
        if ( ! isset( $_POST['cem_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( $_POST['cem_nonce'], 'cem_nonce' ) ) {
            wp_die( 'Nonce verification failed' );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized user' );
        }

        $endpoints = array();
        if ( isset( $_POST['cem_endpoints'] ) && is_array( $_POST['cem_endpoints'] ) ) {
            foreach ( $_POST['cem_endpoints'] as $endpoint ) {
                $sanitized = array(
                    'slug'           => sanitize_title( $endpoint['slug'] ),
                    'methods'        => sanitize_text_field( $endpoint['methods'] ),
                    'capability'     => sanitize_text_field( $endpoint['capability'] ),
                    'microplugin_id' => isset( $endpoint['microplugin_id'] ) ? intval( $endpoint['microplugin_id'] ) : 0,
                    'args'           => isset( $endpoint['args'] ) ? sanitize_text_field( $endpoint['args'] ) : '',
                );
                if ( ! empty( $sanitized['slug'] ) ) {
                    $endpoints[] = $sanitized;
                }
            }
        }

        update_option( 'cem_custom_endpoints', $endpoints );

        wp_redirect( admin_url( 'options-general.php?page=custom-endpoints-manager&message=saved' ) );
        exit;
    }
}
