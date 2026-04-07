<?php
/**
 * Rudel db.php drop-in.
 *
 * Maps the active environment onto its isolated users and usermeta tables.
 */

if (
	defined( 'RUDEL_USERS_TABLE' )
	&& is_string( RUDEL_USERS_TABLE )
	&& '' !== RUDEL_USERS_TABLE
	&& ! defined( 'CUSTOM_USER_TABLE' )
) {
	define( 'CUSTOM_USER_TABLE', RUDEL_USERS_TABLE );
}

if (
	defined( 'RUDEL_USERMETA_TABLE' )
	&& is_string( RUDEL_USERMETA_TABLE )
	&& '' !== RUDEL_USERMETA_TABLE
	&& ! defined( 'CUSTOM_USER_META_TABLE' )
) {
	define( 'CUSTOM_USER_META_TABLE', RUDEL_USERMETA_TABLE );
}
