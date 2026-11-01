<?php
/**
 * Uninstall: removes data only when "delete data on uninstall" is enabled.
 *
 * @package Dev3\AiHelpdesk
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dev3hd_settings = get_option( 'dev3hd_settings', array() );
wp_unschedule_hook( 'dev3hd_run_job' );
wp_unschedule_hook( 'dev3hd_daily' );
wp_unschedule_hook( 'dev3hd_hourly' );
wp_unschedule_hook( 'dev3hd_mail_tick' );
if ( empty( $dev3hd_settings['delete_on_uninstall'] ) ) {
	return;
}
global $wpdb;
foreach ( array( 'dev3hd_usage', 'dev3hd_core_cache', 'dev3hd_core_jobs', 'dev3hd_core_embeddings', 'dev3hd_tickets', 'dev3hd_replies', 'dev3hd_canned' ) as $dev3hd_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$dev3hd_table}" );
}
// Help articles and their categories.
$dev3hd_posts = get_posts(
	array(
		'post_type'      => 'dev3hd_article',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $dev3hd_posts as $dev3hd_post ) {
	wp_delete_post( $dev3hd_post, true );
}
$dev3hd_terms = get_terms(
	array(
		'taxonomy'   => 'dev3hd_kb_cat',
		'hide_empty' => false,
		'fields'     => 'ids',
	)
);
if ( is_array( $dev3hd_terms ) ) {
	foreach ( $dev3hd_terms as $dev3hd_term ) {
		wp_delete_term( $dev3hd_term, 'dev3hd_kb_cat' );
	}
}
// Private attachments.
$dev3hd_uploads = wp_upload_dir( null, false );
$dev3hd_dir     = trailingslashit( $dev3hd_uploads['basedir'] ) . 'dev3hd-private';
if ( is_dir( $dev3hd_dir ) ) {
	$dev3hd_files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dev3hd_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $dev3hd_files as $dev3hd_file ) {
		if ( $dev3hd_file->isDir() ) {
			rmdir( $dev3hd_file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		} else {
			wp_delete_file( $dev3hd_file->getPathname() );
		}
	}
	rmdir( $dev3hd_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}
foreach ( array( 'dev3hd_settings', 'dev3hd_db_version', 'dev3hd_mail_secret', 'dev3hd_pages', 'dev3hd_last_poll', 'dev3hd_kb_gaps', 'dev3hd_round_robin' ) as $dev3hd_option ) {
	delete_option( $dev3hd_option );
}
remove_role( 'dev3hd_agent' );
delete_transient( 'dev3hd_models' );
