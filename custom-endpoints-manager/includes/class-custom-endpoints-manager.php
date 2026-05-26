<?php
/**
 * Core Plugin Class.
 *
 * @package Custom_Endpoints_Manager
 * @since 1.0.0
 */
class Custom_Endpoints_Manager {

    protected $loader;
    protected $plugin_name;
    protected $version;

    public function __construct() {
        $this->version     = CEM_VERSION;
        $this->plugin_name = 'custom-endpoints-manager';

        $this->load_dependencies();
        $this->set_locale();
        $this->define_admin_hooks();
        $this->define_public_hooks();
        $this->define_rest_api_hooks();
    }

    private function load_dependencies() {
        require_once CEM_PLUGIN_DIR . 'includes/class-custom-endpoints-manager-loader.php';
        require_once CEM_PLUGIN_DIR . 'includes/class-custom-endpoints-manager-i18n.php';
        require_once CEM_PLUGIN_DIR . 'includes/class-custom-endpoints-manager-admin.php';
        require_once CEM_PLUGIN_DIR . 'includes/class-custom-endpoints-manager-public.php';
        require_once CEM_PLUGIN_DIR . 'includes/class-custom-endpoints-manager-rest-controller.php';
        require_once CEM_PLUGIN_DIR . 'includes/functions/class-validator.php';
        require_once CEM_PLUGIN_DIR . 'includes/functions/class-executor.php';
        require_once CEM_PLUGIN_DIR . 'includes/functions/class-library.php';
        require_once CEM_PLUGIN_DIR . 'includes/class-cem-execution-logger.php';
        require_once CEM_PLUGIN_DIR . 'includes/class-cem-async-processor.php';

        CEM_Async_Processor::init();

        $this->loader = new Custom_Endpoints_Manager_Loader();
    }

    private function set_locale() {
        $i18n = new Custom_Endpoints_Manager_i18n();
        $this->loader->add_action( 'plugins_loaded', $i18n, 'load_plugin_textdomain' );
    }

    private function define_admin_hooks() {
        $admin = new Custom_Endpoints_Manager_Admin( $this->get_plugin_name(), $this->get_version() );
        $this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_styles' );
        $this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_scripts' );
        $this->loader->add_action( 'admin_menu', $admin, 'add_options_page' );
        $this->loader->add_action( 'admin_init', $admin, 'save_custom_endpoints' );
    }

    private function define_public_hooks() {
        $public = new Custom_Endpoints_Manager_Public( $this->get_plugin_name(), $this->get_version() );
        $this->loader->add_action( 'wp_enqueue_scripts', $public, 'enqueue_styles' );
        $this->loader->add_action( 'wp_enqueue_scripts', $public, 'enqueue_scripts' );
    }

    private function define_rest_api_hooks() {
        $rest_controller = new Custom_Endpoints_Manager_REST_Controller( $this->get_plugin_name(), $this->get_version() );
        $this->loader->add_action( 'rest_api_init', $rest_controller, 'register_routes' );
    }

    public function run() {
        $this->loader->run();
    }

    public function get_plugin_name() {
        return $this->plugin_name;
    }

    public function get_loader() {
        return $this->loader;
    }

    public function get_version() {
        return $this->version;
    }
}
