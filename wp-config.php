<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'Dolibarr-WordPress-WooCommerce' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', 'asd123123' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         'xc^x5dl]P(x16{z^_<)i!69;JApVDO}yk#A@7-d)Hu&+UdNp;;C~e]rVq8yK6TW[' );
define( 'SECURE_AUTH_KEY',  'u m,R@Bf!DK/4dQbTMmBQ(:f[;Er0a|Wo#n0XwKPI1]:2J),GpnAJG21/t)OX)w5' );
define( 'LOGGED_IN_KEY',    '*4b/KY@f{=+)3k!b[>ZIC)1sT2%SYb~am7hRH9l04/}STUu:9iLVTeCmEKkgWo|I' );
define( 'NONCE_KEY',        'MmW5<w#3>2jWVRmbn^EG(5ZE^z<1SfkGvR?Yhcfno$$S?Xo,FH.4iu-$i2O@[#MV' );
define( 'AUTH_SALT',        '$qx3_EO(6?4Kdh_C,rAww4dd{,V.}A/~CV6P4UV?}r*+OZy(PfUF|+SG|EK1KCXo' );
define( 'SECURE_AUTH_SALT', 'nn}(Q#vvQf%O`t(mZVx^Qi&$C0z!esL@;V![8e,K/{M{cY/9_x8BiQYd=7!G>P>S' );
define( 'LOGGED_IN_SALT',   'yRjtv)BdZ*[[KP2hg6^/5Vr-Z%A/$,:FpB)QceXl}1HeB=2i{c|Y|klQJqjp3h,M' );
define( 'NONCE_SALT',       'Ik!:/kR]~@20+$C$o2UXa@$</;8[F^.7S9M*S}M{zVepnO.}dDuv(|Aa8jE#@#?#' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_LOG', false );
define( 'WP_DEBUG_DISPLAY', false );

define('WP_MEMORY_LIMIT', '1024M');
set_time_limit(1000); 
@ini_set('upload_max_filesize', '1024M');
@ini_set('post_max_size', '1024M');
@ini_set('max_execution_time', '1000');

define('ALLOW_UNFILTERED_UPLOADS', true);

define( 'FS_METHOD', 'direct' );


/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
