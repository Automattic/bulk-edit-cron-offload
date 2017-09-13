<?php

namespace Automattic\WP\Bulk_Edit_Cron_Offload;

class Move_To_Trash {
	/**
	 * Class constants
	 */
	const CRON_EVENT = 'a8c_bulk_edit_move_to_trash';

	/**
	 * Register this bulk process' hooks
	 */
	public static function register_hooks() {
		add_action( Main::build_hook( 'trash' ), array( __CLASS__, 'process' ) );
	}

	/**
	 * Handle a request to delete all trashed items for a given post type
	 */
	public static function process( $vars ) {
		error_log( var_export( $vars, true ) );
	}
}

Move_To_Trash::register_hooks();