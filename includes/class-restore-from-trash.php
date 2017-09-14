<?php
/**
 * Offload "Restore from Trash"
 *
 * @package Bulk_Edit_Cron_Offload
 */

namespace Automattic\WP\Bulk_Edit_Cron_Offload;

/**
 * Class Restore_From_Trash
 */
class Restore_From_Trash {
	/**
	 * Class constants
	 */
	const ACTION = 'untrash';

	const ADMIN_NOTICE_KEY = 'bulk_edit_cron_offload_restore_from_trash';

	/**
	 * Register this bulk process' hooks
	 */
	public static function register_hooks() {
		add_action( Main::build_hook( self::ACTION ), array( __CLASS__, 'process' ) );
		add_action( Main::build_cron_hook( self::ACTION ), array( __CLASS__, 'process_via_cron' ) );

		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_filter( 'posts_where', array( __CLASS__, 'hide_posts_pending_restore' ), 999, 2 );
	}

	/**
	 * Handle a request to restore some posts from the trash
	 *
	 * @param object $vars Bulk-request variables.
	 */
	public static function process( $vars ) {
		$action_scheduled = Main::next_scheduled( $vars );

		if ( empty( $action_scheduled ) ) {
			Main::schedule_processing( $vars );
			Main::do_admin_redirect( self::ADMIN_NOTICE_KEY, true );
		} else {
			Main::do_admin_redirect( self::ADMIN_NOTICE_KEY, false );
		}
	}

	/**
	 * Cron callback to restore requested items from trash
	 *
	 * @param object $vars Bulk-request variables.
	 */
	public static function process_via_cron( $vars ) {
		$count = 0;

		if ( is_array( $vars->posts ) && ! empty( $vars->posts ) ) {
			require_once ABSPATH . '/wp-admin/includes/post.php';

			$restored   = array();
			$locked     = array();
			$auth_error = array();
			$error      = array();

			foreach ( $vars->posts as $post_id ) {
				// Can the user restore this post?
				if ( ! user_can( $vars->user_id, 'delete_post', $post_id ) ) {
					$auth_error[] = $post_id;
					continue;
				}

				// Post is locked by someone, so leave it alone.
				if ( false !== wp_check_post_lock( $post_id ) ) {
					$locked[] = $post_id;
					continue;
				}

				// Try restoring.
				$post_restored = wp_untrash_post( $post_id );
				if ( $post_restored ) {
					$restored[] = $post_id;
				} else {
					$error[] = $post_id;
				}

				// Take a break periodically.
				if ( 0 === $count++ % 50 ) {
					stop_the_insanity();
					sleep( 3 );
				}
			}

			$results = compact( 'restored', 'locked', 'auth_error', 'error' );
			do_action( 'bulk_edit_cron_offload_restore_from_trash_request_completed', $results, $vars );
		} else {
			do_action( 'bulk_edit_cron_offload_restore_from_trash_request_no_posts', $vars->posts, $vars );
		}
	}

	/**
	 * Let the user know what's going on
	 */
	public static function admin_notices() {
		$screen = get_current_screen();

		$type   = '';
		$message = '';

		if ( isset( $_REQUEST[ self::ADMIN_NOTICE_KEY ] ) ) {
			if ( 1 === (int) $_REQUEST[ self::ADMIN_NOTICE_KEY ] ) {
				$type    = 'success';
				$message = __( 'Success! The selected posts will be restored shortly.', 'bulk-edit-cron-offload' );
			} else {
				$type    = 'error';
				$message = __( 'The selected posts are already scheduled to be restored.', 'bulk-edit-cron-offload' );
			}
		} elseif ( 'edit' === $screen->base && isset( $_REQUEST['post_status'] ) && 'trash' === $_REQUEST['post_status'] ) {
			if ( Main::get_post_ids_for_pending_events( self::ACTION, $screen->post_type, 'trash' ) ) {
				$type    = 'warning';
				$message = __( 'Some items that would normally be shown here are waiting to be restored from the trash. These items are hidden until they are restored.', 'bulk-edit-cron-offload' );
			}
		}

		Main::render_admin_notice( $type, $message );
	}

	/**
	 * When a restore is pending for a given post type, hide those posts in the admin
	 *
	 * @param string $where Posts' WHERE clause.
	 * @param object $q WP_Query object.
	 * @return string
	 */
	public static function hide_posts_pending_restore( $where, $q ) {
		if ( ! is_admin() || ! $q->is_main_query() ) {
			return $where;
		}

		if ( 'edit' !== get_current_screen()->base ) {
			return $where;
		}

		if ( 'trash' !== $q->get( 'post_status' ) ) {
			return $where;
		}

		$post__not_in = Main::get_post_ids_for_pending_events( self::ACTION, $q->get( 'post_type' ), $q->get( 'post_status' ) );

		if ( ! empty( $post__not_in ) ) {
			$post__not_in = implode( ',', $post__not_in );
			$where       .= ' AND ID NOT IN(' . $post__not_in . ')';
		}

		return $where;
	}
}

Restore_From_Trash::register_hooks();