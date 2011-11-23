<?php

add_action( 'hmbkp_backup_started', 'hmbkp_set_status', 10, 0 );

function hmbkp_set_status_dumping_database() {
    hmbkp_set_status( __( 'Dumping database', 'hmbkp' ) );
}
add_action( 'hmbkp_mysqldump_started', 'hmbkp_set_status_dumping_database' );

function hmbkp_set_status_archiving() {
    hmbkp_set_status( __( 'Creating zip archive', 'hmbkp' ) );
}
add_action( 'hmbkp_archive_started', 'hmbkp_set_status_archiving' );