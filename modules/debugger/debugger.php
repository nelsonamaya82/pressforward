<?php
/**
 * Test of module base class.
 *
 * @package PressForward
 */

/**
 * PF_Debugger class.
 */
class PF_Debugger extends PF_Module {
	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::start();
		add_filter( 'pf_setup_admin_rights', array( $this, 'control_menu_access' ) );
		add_action( 'pf_tools', array( $this, 'debug_the_slurp' ) );
	}

	/**
	 * Register the admin menu items.
	 *
	 * The parent class will take care of registering them.
	 *
	 * @param array $admin_menus Admin menus.
	 */
	public function setup_admin_menus( $admin_menus ) {
		$admin_menus = array();

		$admin_menus[] = array(
			'page_title' => __( 'View Log', 'pf' ),
			'menu_title' => __( 'View Log', 'pf' ),
			'cap'        => get_option( 'pf_menu_log_access', pf_get_defining_capability_by_role( 'administrator' ) ),
			'slug'       => 'pf-debugger',
			'callback'   => array( $this, 'admin_menu_callback' ),
		);

		parent::setup_admin_menus( $admin_menus );
	}

	/**
	 * Sets up module.
	 */
	public function setup_module() {
		$enabled = 'no' === get_option( 'pf_debugger_enable' ) ? 'no' : 'yes';

		$mod_settings = array(
			'name'    => __( 'Debugger Log Viewing Module', 'pf' ),
			'slug'    => 'debugger',
			'options' => '',
		);
	}

	/**
	 * More module setup.
	 */
	public function module_setup() {
		$mod_settings = array(
			'name'        => __( 'Debugger Log Viewing Module', 'pf' ),
			'slug'        => 'debugger',
			'description' => __( 'This module provides a way to view the feed retrieval log within the dashboard.', 'pf' ),
			'thumbnail'   => '',
			'options'     => '',
		);

		update_option( PF_SLUG . '_' . $this->id . '_settings', $mod_settings );
	}

	/**
	 * Counts the post in a post type.
	 *
	 * @param string $post_type Post type.
	 * @param int    $date_less Number of months.
	 * @return int
	 */
	public function count_the_posts( $post_type, $date_less = false ) {
		if ( ! $date_less ) {
			$y = gmdate( 'Y' );
			$m = gmdate( 'm' );
		} elseif ( ! empty( $date_less ) && $date_less < 12 ) {
			$y = gmdate( 'Y' );
			$m = gmdate( 'm' );
			$m = (int) $m + (int) $date_less;
		} elseif ( ! empty( $date_less ) && $date_less >= 12 ) {
			$y = gmdate( 'Y' );
			$y = $y - floor( $date_less / 12 );
			$m = gmdate( 'm' );
			$m = $m - ( abs( $date_less ) - ( 12 * floor( $date_less / 12 ) ) );
		}

		$posts_per_page = 200;

		$query_arg = array(
			'post_type'      => $post_type,
			'year'           => (int) $y,
			'monthnum'       => (int) $m,
			'posts_per_page' => $posts_per_page,
			'offset'         => 0,
			'cache_results'  => false,
			'no_found_rows'  => true,
			'fields'         => 'ids',
		);

		$total  = 0;
		$last_q = $posts_per_page;

		while ( $last_q >= $posts_per_page ) {
			$count  = $this->query_counter( $query_arg );
			$last_q = $count;

			$total += $count;

			$query_arg['offset'] = $query_arg['offset'] + $posts_per_page;
		}

		return $total;
	}

	/**
	 * Query counter.
	 *
	 * @param array $query_arg      Query args.
	 * @param int   $posts_per_page Posts per page.
	 * @return int
	 */
	public function query_counter( $query_arg, $posts_per_page = 200 ) {
		if ( empty( $query_arg['offset'] ) ) {
			$query_arg['offset'] = 0;
		}

		if ( empty( $query_arg['posts_per_page'] ) ) {
			$query_arg['posts_per_page'] = $posts_per_page;
		}

		$query = new WP_Query( $query_arg );
		$total = 0;

		$pc = $query->post_count;

		wp_reset_postdata();

		$total += $pc;

		return $total;
	}

	/**
	 * Admin menu callback.
	 */
	public function admin_menu_callback() {
		global $wpdb;

		// Default log location is in the uploads directory.
		if ( ! defined( 'PF_DEBUG_LOG' ) ) {
			$upload_dir = wp_upload_dir();
			$log_path   = $upload_dir['basedir'] . '/pressforward.log';
		} else {
			$log_path = PF_DEBUG_LOG;
		}

		// phpcs:ignore WordPress.DB
		$action_count = $wpdb->get_results( "SELECT COUNT(*) FROM {$wpdb->prefix}pf_relationships", ARRAY_A );

		$ntp_args = array(
			'posts_per_page' => -1,
			'meta_key'       => 'item_link',
		);

		$nominated_to_posts = get_posts( $ntp_args );
		if ( is_array( $nominated_to_posts ) ) {
			$nomed_posts = count( $nominated_to_posts );
		} else {
			$nomed_posts = 0;
		}
		?>
		<div class="wrap">
			<h2><?php esc_html_e( 'Current Log', 'pf' ); ?></h2>
			<p><?php esc_html_e( 'Does not update in real time.', 'pf' ); ?></p>
			<p><?php esc_html_e( 'Total Current Feed Items', 'pf' ); ?>:
			<?php
				$feed_item = 'pf_feed_item';
				echo esc_html( wp_count_posts( $feed_item )->publish );
			?>
			<br />
			<?php
				$feed_item = 'pf_feed_item';
				echo 'Month to date Feed Items: ' . esc_html( $this->count_the_posts( $feed_item ) );
				echo '<br />Last month Feed Items: ' . esc_html( $this->count_the_posts( $feed_item, -1 ) );
			?>
			</p>
			<p><?php esc_html_e( 'Total Current Nominations:', 'pf' ); ?>
			<?php
				echo esc_html( wp_count_posts( 'nomination' )->draft );
				echo '<br />Month to date Nominations: ' . esc_html( $this->count_the_posts( 'nomination' ) );
				echo '<br />Last month Nominations: ' . esc_html( $this->count_the_posts( 'nomination', -1 ) );

			?>
			</p>
			<p><?php esc_html_e( 'Total Actions Taken:', 'pf' ); ?>
			<?php
				echo esc_html( current( $action_count[0] ) );
			?>
			</p>
			<p><?php esc_html_e( 'Total Nominations Published:', 'pf' ); ?>
			<?php
				echo esc_html( $nomed_posts );
			?>
			</p>
			<p><?php esc_html_e( 'Total Retrieval Chunks Begun This:', 'pf' ); ?>
			<?php
				pf_iterate_cycle_state( 'retrieval_chunks_begun', false, true );
			?>
			</p>
			<p><?php esc_html_e( 'Total Retrieval Cycles Begun This:', 'pf' ); ?>
			<?php
				pf_iterate_cycle_state( 'retrieval_cycles_begun', false, true );
			?>
			</p>
			<p><?php esc_html_e( 'Total Retrieval Cycles Ended This:', 'pf' ); ?>
			<?php
				pf_iterate_cycle_state( 'retrieval_cycles_ended', false, true );
			?>
			</p>
			<br /><br />
			<?php
			if ( file_exists( $log_path ) ) {
				echo '<pre>';
				// phpcs:ignore
				echo file_get_contents( $log_path );
				echo '</pre>';
			} else {
				esc_html_e( 'The log does not exist.', 'pf' );
			}
			?>
		</div>
		<?php
	}

	/**
	 * If this module has any styles to enqueue, do it in a method.
	 *
	 * If you have no styles, etc, just ignore this
	 */
	public function admin_enqueue_styles() {
		wp_register_style( PF_SLUG . '-debug-style', PF_URL . 'includes/debugger/css/style.css', [], $GLOBALS['wp_version'] );
	}

	/**
	 * Enqueues scripts for the admin.
	 */
	public function admin_enqueue_scripts() {
		global $pagenow;

		$hook = 0 !== func_num_args() ? func_get_arg( 0 ) : '';

		if ( ! in_array( $pagenow, array( 'admin.php' ), true ) ) {
			return;
		}

		if ( ! in_array( $hook, array( 'pressforward_page_pf-tools' ), true ) ) {
			return;
		}

		wp_enqueue_script( 'feed_control_script', PF_URL . '/assets/js/feeds_control.js', array( 'jquery', PF_SLUG . '-twitter-bootstrap' ), PF_VERSION, true );
	}

	/**
	 * Registers admin rights for this module.
	 *
	 * @param array $admin_rights Admin rights.
	 * @return array
	 */
	public function control_menu_access( $admin_rights ) {
		$admin_rights['pf_menu_log_access'] = array(
			'default' => 'administrator',
			'title'   => __( 'Debugging Log', 'pf' ),
			'details' => __( 'Debugging sets the user role required to access the Debugging page from the PressForward menu when it is turned on.', 'pf' ),
		);

		return $admin_rights;
	}

	/**
	 * Debugging tool for the retrieval process.
	 */
	public function debug_the_slurp() {
		if ( current_user_can( get_option( 'pf_menu_log_access', 'administrator' ) ) ) {
			?>
			<p>
			<button type="button" class="resetFeedOps btn btn-warning" id="resetFeedOps" value="<?php esc_attr_e( 'Reset all Feed Retrieval Options', 'pf' ); ?>"><?php esc_html_e( 'Reset all Feed Retrieval Options', 'pf' ); ?></button>    <br />
			<?php
				$feed_go         = get_option( PF_SLUG . '_feeds_go_switch', 0 );
				$feed_iteration  = get_option( PF_SLUG . '_feeds_iteration', 0 );
				$retrieval_state = get_option( PF_SLUG . '_iterate_going_switch', 0 );
				$chunk_state     = get_option( PF_SLUG . '_ready_to_chunk', 1 );

				// translators: 1. "Feeds Go" status; 2. "Feeds iteration" status; 3. "Going switch" status; 4. "Ready to chunk" status.
				$retrieval_state = sprintf( __( 'Feeds Go? %1$d  Feeds iteration? %2$d  Going switch? %3$d  Ready to chunk? %4$d', 'pf' ), $feed_go, $feed_iteration, $retrieval_state, $chunk_state );
				echo esc_html( $retrieval_state );

			?>
			<br />
			<button type="button" class="redoFeeds btn btn-warning" id="resetFeedOps" value="<?php esc_attr_e( 'Switch feeds to new retrieval setup', 'pf' ); ?>"><?php esc_html_e( 'Switch feeds to new retrieval setup', 'pf' ); ?></button>    <br />
			</p>
			<?php
		}
	}
}
