// Runs the inline script produced by the plugin (out-inline.js) against a fake
// wp.hooks and checks the label mapping. Usage: node test-inline.js
'use strict';
const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );

const src = fs.readFileSync( path.join( __dirname, 'out-inline.js' ), 'utf8' );
const filters = {};
const window = {
	wp: {
		hooks: {
			addFilter( name, ns, fn ) {
				( filters[ name ] = filters[ name ] || [] ).push( { ns, fn } );
			},
		},
	},
};
vm.runInNewContext( src, { window } );

// Same order as @wordpress/i18n create-i18n.ts: i18n.gettext, then i18n.gettext_<domain>.
function __( text, domain ) {
	let t = text;
	for ( const f of filters[ 'i18n.gettext' ] || [] ) t = f.fn( t, text, domain );
	for ( const f of filters[ 'i18n.gettext_' + ( domain || 'default' ) ] || [] ) t = f.fn( t, text, domain );
	return t;
}

let pass = 0;
let fail = 0;
function check( name, cond, detail ) {
	if ( cond ) {
		pass++;
		console.log( '  PASS  ' + name );
	} else {
		fail++;
		console.log( '  FAIL  ' + name + ( detail ? '  -- ' + detail : '' ) );
	}
}

console.log( '\n== F4 inline script (node, fake wp.hooks) ==' );
check( 'one filter on i18n.gettext_woocommerce', ( filters[ 'i18n.gettext_woocommerce' ] || [] ).length === 1 );
check( 'namespace ledgerplus-shop/referral-labels', filters[ 'i18n.gettext_woocommerce' ][ 0 ].ns === 'ledgerplus-shop/referral-labels' );
const cases = [
	[ 'Add coupons', 'Have a referral code?' ],
	[ 'Enter code', 'Referral code' ],
	[ 'Applying coupon…', 'Applying referral code…' ],
	[ 'Removing coupon…', 'Removing referral code…' ],
	[ 'Coupon: %s', 'Referral code: %s' ],
	[ 'Remove coupon "%s"', 'Remove referral code "%s"' ],
	[ 'Coupons', 'Referral code' ],
	[ 'Coupon code "%s" has been applied to your cart.', 'Referral code accepted. Your price stays AED 1,999.' ],
	[ 'Coupon code "%s" has been removed from your cart.', 'Referral code "%s" has been removed.' ],
	[ 'Apply', 'Apply' ],
	[ 'Discount', 'Discount' ],
	[ 'Place order', 'Place order' ],
	[ 'toString', 'toString' ],
	[ '__proto__', '__proto__' ],
];
for ( const [ msgid, want ] of cases ) {
	const got = __( msgid, 'woocommerce' );
	check( JSON.stringify( msgid ) + ' -> ' + JSON.stringify( want ), got === want, 'got ' + JSON.stringify( got ) );
}
check( 'other text domains untouched', __( 'Add coupons', 'other-plugin' ) === 'Add coupons' );
check( 'no "</script" in the script', ! /<\/script/i.test( src ) );
check( 'script is guarded when wp.hooks is missing', ( () => {
	try {
		vm.runInNewContext( src, { window: {} } );
		return true;
	} catch ( e ) {
		return false;
	}
} )() );

console.log( '\nResult: ' + pass + ' passed, ' + fail + ' failed' );
process.exit( fail ? 1 : 0 );
