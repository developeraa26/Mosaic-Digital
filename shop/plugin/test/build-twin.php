<?php
/**
 * Regenerates ledgerplus-shop-wpcode.php from the shared core of ledgerplus-shop/ledgerplus-shop.php
 * (everything from the "Ledger Plus Shop - shared core." comment on), with the snippet header.
 * Usage: php build-twin.php [/path/to/shop/plugin]   (default: the folder above test/)
 */
$dir    = rtrim( $argv[1] ?? dirname( __DIR__ ), '/' );
$plugin = file_get_contents( $dir . '/ledgerplus-shop/ledgerplus-shop.php' );
$start  = strpos( $plugin, "/*\n * Ledger Plus Shop - shared core." );
if ( false === $start ) {
	fwrite( STDERR, "core start not found\n" );
	exit( 1 );
}
$body = substr( $plugin, $start );
if ( ! preg_match( "/const VERSION = '([0-9.]+)';/", $body, $m ) ) {
	fwrite( STDERR, "VERSION not found\n" );
	exit( 1 );
}
if ( ! preg_match( '/^ \* Version:\s+' . preg_quote( $m[1], '/' ) . '$/m', $plugin ) ) {
	fwrite( STDERR, "plugin header Version does not match LP_Shop::VERSION {$m[1]}\n" );
	exit( 1 );
}
foreach ( token_get_all( '<?php ' . $body ) as $token ) {
	if ( is_array( $token ) && in_array( $token[0], array( T_FILE, T_DIR, T_NAMESPACE, T_USE ), true ) ) {
		fwrite( STDERR, 'Core contains forbidden token ' . token_name( $token[0] ) . ' on line ' . $token[2] . "\n" );
		exit( 1 );
	}
}
$header = "/*\n * Ledger Plus Shop - WPCode snippet variant (v{$m[1]}).\n *\n * WPCode: Code type \"PHP Snippet\", Insert Method \"Auto Insert\", Location\n * \"Run Everywhere\". Paste everything below (there is no opening PHP tag on\n * purpose). Same code as the ledgerplus-shop plugin, minus the plugin header\n * and the HPOS compatibility declaration (that one needs a plugin file).\n * If the plugin is also active, this snippet does nothing (class_exists guard).\n */\n";
file_put_contents( $dir . '/ledgerplus-shop-wpcode.php', $header . $body );
echo "Built WPCode snippet v{$m[1]} (" . strlen( $header . $body ) . " bytes)\n";
