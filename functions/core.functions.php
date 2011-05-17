<?php

/**
 * Setup the default options on plugin activation
 */
function hmbkp_activate() {
	hmbkp_setup_daily_schedule();
}

/**
 * Cleanup on plugin deactivation
 *
 * Removes options and clears all cron schedules
 */
function hmbkp_deactivate() {

	// Options to delete
	$options = array(
		'hmbkp_zip_path',
		'hmbkp_mysqldump_path',
		'hmbkp_path',
		'hmbkp_max_backups',
		'hmbkp_running',
		'hmbkp_status',
		'hmbkp_complete',
		'hmbkp_email_error'
	);

	foreach ( $options as $option )
		delete_option( $option );

	delete_transient( 'hmbkp_running' );
	delete_transient( 'hmbkp_estimated_filesize' );

	// Clear cron
	wp_clear_scheduled_hook( 'hmbkp_schedule_backup_hook' );
	wp_clear_scheduled_hook( 'hmbkp_schedule_single_backup_hook' );

	hmbkp_cleanup();

}

/**
 * Handles anything that needs to be
 * done when the plugin is updated
 */
function hmbkp_update() {

	// Every update
	if ( version_compare( HMBKP_VERSION, get_option( 'hmbkp_plugin_version' ), '>' ) ) :

		hmbkp_cleanup();

		delete_transient( 'hmbkp_estimated_filesize' );
		delete_option( 'hmbkp_running' );
		delete_option( 'hmbkp_complete' );
		delete_option( 'hmbkp_status' );
		delete_transient( 'hmbkp_running' );

		// Check whether we have a logs directory to delete
		if ( is_dir( hmbkp_path() . '/logs' ) )
			hmbkp_rmdirtree( hmbkp_path() . '/logs' );

	endif;

	// Pre 1.1
	if ( !get_option( 'hmbkp_plugin_version' ) ) :

		// Delete the obsolete max backups option
		delete_option( 'hmbkp_max_backups' );

	endif;

	// Update from backUpWordPress
	if ( get_option( 'bkpwp_max_backups' ) ) :

		// Carry over the custom path
		if ( $legacy_path = get_option( 'bkpwppath' ) )
			update_option( 'hmbkp_path', $legacy_path );

		// Options to remove
		$legacy_options = array(
			'bkpwp_archive_types',
			'bkpwp_automail_from',
			'bkpwp_domain',
			'bkpwp_domain_path',
			'bkpwp_easy_mode',
			'bkpwp_excludelists',
			'bkpwp_install_user',
			'bkpwp_listmax_backups',
			'bkpwp_max_backups',
			'bkpwp_presets',
			'bkpwp_reccurrences',
			'bkpwp_schedules',
			'bkpwp_calculation',
			'bkpwppath',
			'bkpwp_status_config',
			'bkpwp_status'
		);

		foreach ( $legacy_options as $option )
			delete_option( $option );

	    global $wp_roles;

		$wp_roles->remove_cap( 'administrator','manage_backups' );
		$wp_roles->remove_cap( 'administrator','download_backups' );

		wp_clear_scheduled_hook( 'bkpwp_schedule_bkpwp_hook' );

	endif;

	// Update the stored version
	if ( get_option( 'hmbkp_plugin_version' ) !== HMBKP_VERSION )
		update_option( 'hmbkp_plugin_version', HMBKP_VERSION );

}

/**
 * Simply wrapper function for creating timestamps
 *
 * @return timestamp
 */
function hmbkp_timestamp() {
	return date( get_option( 'date_format' ) ) . ' ' . date( 'H:i:s' );
}

/**
 * Sanitize a directory path
 *
 * @param string $dir
 * @param bool $rel. (default: false)
 * @return string $dir
 */
function hmbkp_conform_dir( $dir, $rel = false ) {

	// Normalise slashes
	$dir = str_replace( '\\', '/', $dir );
	$dir = str_replace( '//', '/', $dir );

	// Remove the trailingslash
	$dir = untrailingslashit( $dir );

	// If we're on Windows
	if ( strpos( ABSPATH, '\\' ) !== false )
		$dir = str_replace( '\\', '/', $dir );

	if ( $rel == true )
		$dir = str_replace( hmbkp_conform_dir( ABSPATH ), '', $dir );

	return $dir;
}
/**
 * Take a file size and return a human readable
 * version
 *
 * @param int $size
 * @param string $unit. (default: null)
 * @param string $retstring. (default: null)
 * @param bool $si. (default: true)
 * @return int
 */
function hmbkp_size_readable( $size, $unit = null, $retstring = '%01.2f %s', $si = true ) {

	// Units
	if ( $si === true ) :
		$sizes = array( 'B', 'kB', 'MB', 'GB', 'TB', 'PB' );
		$mod   = 1000;

	else :
		$sizes = array('B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB');
		$mod   = 1024;

	endif;

	$ii = count( $sizes ) - 1;

	// Max unit
	$unit = array_search( (string) $unit, $sizes );

	if ( is_null( $unit ) || $unit === false )
		$unit = $ii;

	// Loop
	$i = 0;

	while ( $unit != $i && $size >= 1024 && $i < $ii ) {
		$size /= $mod;
		$i++;
	}

	return sprintf( $retstring, $size, $sizes[$i] );
}

/**
 * Add daily as a cron schedule choice
 *
 * @param array $recc
 * @return array $recc
 */
function hmbkp_more_reccurences( $recc ) {

	$hmbkp_reccurrences = array(
	    'hmbkp_daily' => array( 'interval' => 86400, 'display' => 'every day' )
	);

	return array_merge( $recc, $hmbkp_reccurrences );
}

/**
 * Send a flie to the browser for download
 *
 * @param string $path
 */
function hmbkp_send_file( $path ) {

	session_write_close();

	ob_end_clean();

	if ( !is_file( $path ) || connection_status() != 0 )
		return false;

	// Overide max_execution_time
	@set_time_limit( 0 );

	$name = basename( $path );

	// Filenames in IE containing dots will screw up the filename unless we add this
	if ( strstr( $_SERVER['HTTP_USER_AGENT'], 'MSIE' ) )
		$name = preg_replace( '/\./', '%2e', $name, substr_count( $name, '.' ) - 1 );

	// Force
	header( 'Cache-Control: ' );
	header( 'Pragma: ' );
	header( 'Content-Type: application/octet-stream' );
	header( 'Content-Length: ' . (string) ( filesize( $path ) ) );
	header(	'Content-Disposition: attachment; filename=" ' . $name . '"' );
	header( 'Content-Transfer-Encoding: binary\n' );

	if ( $file = fopen( $path, 'rb' ) ) :

		while ( ( !feof( $file ) ) && ( connection_status() == 0) ) :

			print( fread( $file, 1024 * 8 ) );
			flush();

		endwhile;

		fclose( $file );

	endif;

	return ( connection_status() == 0 ) and !connection_aborted();
}

/**
 * Takes a directory and returns an array of files.
 * Does traverse sub-directories
 *
 * @param string $dir
 * @param array $files. (default: array())
 * @return arrat $files
 */
function hmbkp_ls( $dir, $files = array() ) {

	$d = opendir( $dir );

	while ( $file = readdir( $d ) ) :

		// Ignore current dir and containing dir as well the backups dir
		if ( $file == '.' || $file == '..' )
			continue;

		$file = hmbkp_conform_dir( trailingslashit( $dir ) . $file );

		if ( $file == hmbkp_path() )
			continue;

		$files[] = $file;

		if ( is_dir( $file ) )
			$files = hmbkp_ls( $file, $files );

	endwhile;

	return $files;
}

/**
 * Recursively delete a directory including
 * all the files and sub-directories.
 *
 * @param string $dir
 */
function hmbkp_rmdirtree( $dir ) {

	if ( is_file( $dir ) )
		unlink( $dir );

    if ( !is_dir( $dir ) )
    	return false;

    $result = array();

    $dir = trailingslashit( $dir );

    $handle = opendir( $dir );

    while ( false !== ( $file = readdir( $handle ) ) ) :

        // Ignore . and ..
        if ( $file != '.' && $file != '..' ) :

        	$path = $dir . $file;

        	// Recurse if subdir, Delete if file
        	if ( is_dir( $path ) ) :
        		$result = array_merge( $result, hmbkp_rmdirtree( $path ) );

        	else :
        		unlink( $path );
        		$result[] .= $path;

        	endif;

        endif;

    endwhile;

    closedir( $handle );

    rmdir( $dir );

    $result[] .= $dir;

    return $result;

}

/**
 * Calculate the size of the backup
 *
 * Doesn't currently take into account for
 * compression
 *
 * @return string
 */
function hmbkp_calculate() {

    @ini_set( 'memory_limit', apply_filters( 'admin_memory_limit', '256M' ) );

    // Check cache
	if ( $filesize = get_transient( 'hmbkp_estimated_filesize' ) )
		return hmbkp_size_readable( $filesize, null, '%01u %s' );

	$filesize = 0;

    // Don't include database if files only
	if ( !hmbkp_get_files_only() ) :

    	global $wpdb;

    	$res = $wpdb->get_results( 'SHOW TABLE STATUS FROM ' . DB_NAME, ARRAY_A );

    	foreach ( $res as $r )
    		$filesize += (float) $r['Data_length'];

    endif;

   	if ( !hmbkp_get_database_only() ) :

    	// Get rid of any cached filesizes
    	clearstatcache();

    	foreach ( hmbkp_ls( ABSPATH ) as $f )
			$filesize += (float) @filesize( $f );

	endif;

	// Account for compression
	$filesize /= 1.9;

    // Cache in a transient for a week
    set_transient( 'hmbkp_estimated_filesize', $filesize,  604800 );

    return hmbkp_size_readable( $filesize, null, '%01u %s' );

}

/**
 * Check whether shell_exec has been disabled.
 *
 * @return bool
 */
function hmbkp_shell_exec_available() {

	$disable_functions = ini_get( 'disable_functions' );

	// Is shell_exec disabled?
	if ( strpos( $disable_functions, 'shell_exec' ) !== false )
		return false;

	// Are we in Safe Mode
	if ( ini_get( 'safe_mode' ) )
		return false;

	return true;

}

/**
 * Calculate the total filesize of all backups
 *
 * @return string
 */
function hmbkp_total_filesize() {

	$files = hmbkp_get_backups();
	$filesize = 0;

	clearstatcache();

   	foreach ( $files as $f )
		$filesize += @filesize( $f );

	return hmbkp_size_readable( $filesize );

}

/**
 * Setup the daily backup schedule
 */
function hmbkp_setup_daily_schedule() {

	// Clear any old schedules
	wp_clear_scheduled_hook( 'hmbkp_schedule_backup_hook' );

	// Default to 11 in the evening
	$time = '23:00';

	// Allow it to be overridden
	if ( defined( 'HMBKP_DAILY_SCHEDULE_TIME' ) && HMBKP_DAILY_SCHEDULE_TIME )
		$time = HMBKP_DAILY_SCHEDULE_TIME;

	if ( time() > strtotime( $time ) )
		$time = 'tomorrow ' . $time;

	wp_schedule_event( strtotime( $time ), 'hmbkp_daily', 'hmbkp_schedule_backup_hook' );
}


/**
 * Get the path to the backups directory
 *
 * Will try to create it if it doesn't exist
 * and will fallback to default if a custom dir
 * isn't writable.
 */
function hmbkp_path() {

	$path = get_option( 'hmbkp_path' );

	// Allow the backups path to be defined
	if ( defined( 'HMBKP_PATH' ) && HMBKP_PATH )
		$path = HMBKP_PATH;

	// If the dir doesn't exist or isn't writable then use wp-content/backups instead
	if ( ( !$path || !is_writable( $path ) ) && hmbkp_conform_dir( $path ) != hmbkp_path_default() )
    	$path = hmbkp_path_default();

	// Create the backups directory if it doesn't exist
	if ( is_writable( dirname( $path ) ) && !is_dir( $path ) )
		mkdir( $path, 0755 );

	if ( get_option( 'hmbkp_path' ) != $path )
		update_option( 'hmbkp_path', $path );

	// Secure the directory with a .htaccess file
	$htaccess = $path . '/.htaccess';

	if ( !file_exists( $htaccess ) && is_writable( $path ) && require_once( ABSPATH . '/wp-admin/includes/misc.php' ) )
		insert_with_markers( $htaccess, 'BackUpWordPress', array( 'deny from all' ) );

    return hmbkp_conform_dir( $path );
}

function hmbkp_path_default() {
	return hmbkp_conform_dir( WP_CONTENT_DIR . '/backups' );
}

function hmbkp_path_move( $from, $to ) {

	// Create the custom backups directory if it doesn't exist
	if ( is_writable( dirname( $to ) ) && !is_dir( $to ) )
	    mkdir( $to, 0755 );

	if ( !is_dir( $to ) || !is_writable( $to ) || !is_dir( $from ) )
	    return false;

	hmbkp_cleanup();

	if ( $handle = opendir( $from ) ) :

	    while ( false !== ( $file = readdir( $handle ) ) )
	    	if ( $file != '.' && $file != '..' )
	    		rename( trailingslashit( $from ) . $file, trailingslashit( $to ) . $file );

	    closedir( $handle );

	endif;

	hmbkp_rmdirtree( $from );

}

/**
 * The maximum number of backups to keep
 * defaults to 10
 *
 * @return int
 */
function hmbkp_max_backups() {

	if ( defined( 'HMBKP_MAX_BACKUPS' ) && is_numeric( HMBKP_MAX_BACKUPS ) )
		return (int) HMBKP_MAX_BACKUPS;
	
	if( get_option( 'hmbkp_max_backups' ) )
		return (int) get_option( 'hmbkp_max_backups', 10 ); 

	return 10;

}

/**
 *	Returns true or false
 */
function hmbkp_get_files_only() {
	if( defined( 'HMBKP_FILES_ONLY' ) && HMBKP_FILES_ONLY ) 
		return true;
	elseif( get_option( 'hmbkp_files_only' ) )
		return true;
	else
		return false;
}

/**
 *	Returns true or false
 */
function hmbkp_get_database_only() {
	if( defined( 'HMBKP_DATABASE_ONLY' ) && HMBKP_DATABASE_ONLY ) 
		return true;
	elseif( get_option( 'hmbkp_database_only' ) )
		return true;
	else
		return false;
}

/**
 *	Returns defined email address or email address saved in options.
 *	If none set, return false.
 */

function hmbkp_get_email_address() {
	if( defined( 'HMBKP_EMAIL' ) && HMBKP_EMAIL )
		$r = HMBKP_EMAIL;
	elseif( get_option( 'hmbkp_email_address' ) )
		$r = get_option( 'hmbkp_email_address' );
	else
		return false;
		
	if( is_email( $r ) )
		return $r;
	else
		return false;
}

/**
 *	Returns true or false
 */
function hmbkp_get_disable_automatic_backup() {
	if( defined( 'HMBKP_DISABLE_AUTOMATIC_BACKUP' ) && HMBKP_DISABLE_AUTOMATIC_BACKUP )
		return true;
	elseif( get_option('hmbkp_disable_automatic_backup') )
		return true;
	else
		return false;
}

function hmbkp_get_excludes() {
	if( defined( 'HMBKP_EXCLUDES' ) && HMBKP_EXCLUDES )
		return HMBKP_EXCLUDES;
	elseif( get_option('hmbkp_excludes') )
		return get_option('hmbkp_excludes');
	else
		return false;
}

/**
 * Check if a backup is possible with regards to file
 * permissions etc.
 *
 * @return bool
 */
function hmbkp_possible() {

	if ( is_writable( hmbkp_path() ) || is_dir( hmbkp_path() ) || !ini_get( 'safe_mode' ) )
		return true;

	return false;
}

/**
 * Remove any non backup.zip files from the backups dir.
 *
 * @return void
 */
function hmbkp_cleanup() {

	$hmbkp_path = hmbkp_path();

	if ( $handle = opendir( $hmbkp_path ) ) :

    	while ( false !== ( $file = readdir( $handle ) ) )
    		if ( $file != '.' && $file != '..' && $file != '.htaccess' && strpos( $file, '.zip' ) === false )
				hmbkp_rmdirtree( trailingslashit( $hmbkp_path ) . $file );

    	closedir( $handle );

    endif;

}

/**
 * Handles changes in the defined Constants
 * that users can define to control advanced
 * settings
 *
 * @return void
 */
function hmbkp_constant_changes() {

	// Check whether we need to disable the cron
	if ( hmbkp_get_disable_automatic_backup() && wp_next_scheduled( 'hmbkp_schedule_backup_hook' ) ) {
		wp_clear_scheduled_hook( 'hmbkp_schedule_backup_hook' );
	}

	// Or whether we need to re-enable it
	if ( !hmbkp_get_disable_automatic_backup() && !wp_next_scheduled( 'hmbkp_schedule_backup_hook' ) )
		hmbkp_setup_daily_schedule();

	// Allow the time of the daily backup to be changed
	if ( defined( 'HMBKP_DAILY_SCHEDULE_TIME' ) && HMBKP_DAILY_SCHEDULE_TIME && wp_next_scheduled( 'hmbkp_schedule_backup_hook' ) != strtotime( HMBKP_DAILY_SCHEDULE_TIME ) && wp_next_scheduled( 'hmbkp_schedule_backup_hook' ) )
		hmbkp_setup_daily_schedule();

	// Reset if custom time is removed
	if ( ( ( defined( 'HMBKP_DAILY_SCHEDULE_TIME' ) && !HMBKP_DAILY_SCHEDULE_TIME ) || !defined( 'HMBKP_DAILY_SCHEDULE_TIME' ) ) && date( 'H:i', wp_next_scheduled( 'hmbkp_schedule_backup_hook' ) ) != '23:00' && !hmbkp_get_disable_automatic_backup() )
		hmbkp_setup_daily_schedule();

	// If a custom backup path has been set or changed
	if ( defined( 'HMBKP_PATH' ) && HMBKP_PATH && hmbkp_conform_dir( HMBKP_PATH ) != ( $from = hmbkp_conform_dir( get_option( 'hmbkp_path' ) ) ) )
		hmbkp_path_move( $from, HMBKP_PATH );

	// If a custom backup path has been removed
	if ( ( ( defined( 'HMBKP_PATH' ) && !HMBKP_PATH ) || !defined( 'HMBKP_PATH' ) && hmbkp_conform_dir( hmbkp_path_default() ) != ( $from = hmbkp_conform_dir( get_option( 'hmbkp_path' ) ) ) ) )
		hmbkp_path_move( $from, hmbkp_path_default() );

	// If the custom path has changed and the new directory isn't writable
	if ( defined( 'HMBKP_PATH' ) && HMBKP_PATH && hmbkp_conform_dir( HMBKP_PATH ) != ( $from = hmbkp_conform_dir( get_option( 'hmbkp_path' ) ) ) && $from != hmbkp_path_default() && !is_writable( HMBKP_PATH ) && is_dir( $from ) )
		hmbkp_path_move( $from, hmbkp_path_default() );

}

function hmbkp_invalid_custom_excludes() {

	$invalid_rules = array();

	if ( hmbkp_get_excludes() )
		foreach ( explode( ',', hmbkp_get_excludes() ) as $exclude )
			if ( ( $exclude = trim( $exclude ) ) && strpos( $exclude, '*' ) === false && !file_exists( $exclude ) && !file_exists( ABSPATH . $exclude ) && !file_exists( trailingslashit( ABSPATH ) . $exclude ) )
				$invalid_rules[] = $exclude;

	return $invalid_rules;

}

function hmbkp_valid_custom_excludes() {

	$valid_rules = array();

	if ( hmbkp_get_excludes() )
		foreach ( explode( ',', hmbkp_get_excludes() ) as $exclude )
			if ( ( $exclude = trim( $exclude ) ) && ( strpos( $exclude, '*' ) !== false || file_exists( $exclude ) || file_exists( ABSPATH . $exclude ) || file_exists( trailingslashit( ABSPATH ) . $exclude ) ) )
				$valid_rules[] = $exclude;

	return $valid_rules;

}