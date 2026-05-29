<?php
/**
 * Register all actions and filters for the plugin.
 *
 * @package    Custom_Endpoints_Manager
 * @subpackage Custom_Endpoints_Manager/includes
 */

/**
 * Action and filter hook loader.
 *
 * @since 1.0.0
 */
class Custom_Endpoints_Manager_Loader {

	/**
	 * Registered actions.
	 *
	 * @var array
	 */
	protected $actions;

	/**
	 * Registered filters.
	 *
	 * @var array
	 */
	protected $filters;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->actions = array();
		$this->filters = array();
	}

	/**
	 * Register a WP action hook.
	 *
	 * @since 1.0.0
	 * @param string $hook          Hook name.
	 * @param object $component     Object containing the callback.
	 * @param string $callback      Method name.
	 * @param int    $priority      Hook priority.
	 * @param int    $accepted_args Number of arguments passed to the callback.
	 */
	public function add_action( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->actions = $this->add( $this->actions, $hook, $component, $callback, $priority, $accepted_args );
	}

	/**
	 * Register a WP filter hook.
	 *
	 * @since 1.0.0
	 * @param string $hook          Hook name.
	 * @param object $component     Object containing the callback.
	 * @param string $callback      Method name.
	 * @param int    $priority      Hook priority.
	 * @param int    $accepted_args Number of arguments passed to the callback.
	 */
	public function add_filter( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->filters = $this->add( $this->filters, $hook, $component, $callback, $priority, $accepted_args );
	}

	/**
	 * Append a hook definition to a collection.
	 *
	 * @since 1.0.0
	 * @param array  $hooks         Existing hooks collection.
	 * @param string $hook          Hook name.
	 * @param object $component     Object containing the callback.
	 * @param string $callback      Method name.
	 * @param int    $priority      Hook priority.
	 * @param int    $accepted_args Number of arguments.
	 * @return array Updated hooks collection.
	 */
	private function add( $hooks, $hook, $component, $callback, $priority, $accepted_args ) {
		$hooks[] = array(
			'hook'          => $hook,
			'component'     => $component,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
		return $hooks;
	}

	/**
	 * Execute all registered hooks.
	 *
	 * @since 1.0.0
	 */
	public function run() {
		foreach ( $this->filters as $hook ) {
			add_filter( $hook['hook'], array( $hook['component'], $hook['callback'] ), $hook['priority'], $hook['accepted_args'] );
		}
		foreach ( $this->actions as $hook ) {
			add_action( $hook['hook'], array( $hook['component'], $hook['callback'] ), $hook['priority'], $hook['accepted_args'] );
		}
	}
}
