<?php
/**
 * Runtime db.php drop-in.
 *
 * Maps the active environment onto its isolated users and usermeta tables.
 */

if (
	defined( '{{constant_users_table}}' )
	&& is_string( {{constant_users_table}} )
	&& '' !== {{constant_users_table}}
	&& ! defined( 'CUSTOM_USER_TABLE' )
) {
	define( 'CUSTOM_USER_TABLE', {{constant_users_table}} );
}

if (
	defined( '{{constant_usermeta_table}}' )
	&& is_string( {{constant_usermeta_table}} )
	&& '' !== {{constant_usermeta_table}}
	&& ! defined( 'CUSTOM_USER_META_TABLE' )
) {
	define( 'CUSTOM_USER_META_TABLE', {{constant_usermeta_table}} );
}
