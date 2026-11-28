/*
 * No prices before submission (Node, no browser). Since 1.1.0 the browser prices nothing: the price
 * shows only on the result screen, from the server's response. This checks:
 *   1. the amount scanner (amounts.js) itself;
 *   2. the page source: the real [lp_package_calculator] shortcode output (template + config JSON),
 *      the page.json form widget and the asset tags carry no amount (see amounts.js for the counts
 *      that are allowed), also when an lp_calc_config filter adds prices to the config;
 *   3. the config the page gets (LP_Calc_Pricing::browser_config()): no prices, currency, VAT rate or
 *      price hints, and no amount in any string;
 *   4. the real assets/calculator.js and calculator.css, and the plugin's readme.txt (a public file on
 *      most sites): no pricing code, no price keys, no amount;
 *   5. "Your package" parity: for every selection (services x VAT x CT x employees x reports x
 *      transactions), calculator.js packageLines() equals LP_Calc_Pricing::package_lines(), lists the
 *      same services in the same order as the server's quote, and has no amount.
 *
 *   node calculator/test/ui/no-prices.test.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const H = require('./harness');
const A = require('./amounts');
const JS_FILE = path.join(H.PLUGIN, 'assets', 'calculator.js');
const CSS_FILE = path.join(H.PLUGIN, 'assets', 'calculator.css');
const LPCalc = require(JS_FILE);

let failures = 0;
let passes = 0;
function check(cond, label, extra) {
	if (cond) {
		passes++;
	} else {
		failures++;
		console.log('  FAIL ' + label + (extra !== undefined ? ' :: ' + JSON.stringify(extra).slice(0, 1500) : ''));
	}
}
function eq(actual, expected, label) {
	check(JSON.stringify(actual) === JSON.stringify(expected), label, { actual: actual, expected: expected });
}

/* 1. The scanner. */
console.log('1. amount scanner');
const must = ['AED 7,000', 'Total: 2,000', 'from 1500 a year', 'AED 500 per 1,000 transactions', 'Dedicated account manager Included', 'at no extra charge', '500', 'aed'];
const mustNot = ['1,000 transactions a year', '50,000 transactions a year', '<option value="10000">', '{"value":1000,"label":"1,000 transactions a year"}', '"transactions":1000', '+971 50 342 0832', 'tel:+971503420832', 'Registration included if you are not registered.', 'Includes VAT registration', '4 reports a year', 'Up to 20 employees', 'font-weight:500;', 'width:20%', '5,000 transactions a year', 'lp-calc-1-svc-accounting'];
must.forEach(function (t) {
	check(A.findAmounts(t).length > 0, 'scanner finds an amount in "' + t + '"');
});
// font-weight:500 is CSS, not page text: it is never in the scanned calculator markup, but the
// scanner treats it as an amount, which errs on the safe side.
mustNot.filter(function (t) {
	return t !== 'font-weight:500;';
}).forEach(function (t) {
	eq(A.findAmounts(t), [], 'scanner allows "' + t + '"');
});
eq(A.NEEDLES.slice().sort(), ['1,000', '1,500', '1000', '1500', '2,000', '2000', '500'], 'scanner looks for every non-zero default price as "1,234" and "1234"');

/* 2 and 3. The page source and the config. */
function source(args) {
	const parts = JSON.parse(H.php([path.join(H.UI, 'render.php'), '--json'].concat(args || []), '', {}));
	check(!parts.notices || !parts.notices.length, 'no PHP notices while rendering ' + (args || []).join(' '), parts.notices);
	return parts;
}
function configOf(html) {
	const m = /<script type="application\/json" id="lp-calc-config">([\s\S]*?)<\/script>/.exec(html);
	return m ? JSON.parse(m[1]) : null;
}
function strings(v, out) {
	out = out || [];
	if (typeof v === 'string') {
		out.push(v);
	} else if (v && typeof v === 'object') {
		Object.keys(v).forEach(function (k) {
			out.push(k);
			strings(v[k], out);
		});
	}
	return out;
}

console.log('2. page source (real shortcode + page.json form + asset tags)');
const parts = source();
const pageSource = [parts.head, parts.shortcode, parts.form, parts.footer].join('\n');
eq(A.findAmounts(pageSource), [], 'page source: no amount');
check(A.stripAllowed(parts.shortcode) !== parts.shortcode, 'page source: the allowed transaction counts are there (and only those were skipped)');
check(parts.shortcode.indexOf('aria-live') === -1, 'page source: no aria-live total');
['data-lp-calc-total', 'data-lp-calc-bar', 'lp-calc__estimate', 'lp-calc__service-price', 'lp-calc__bar', 'data-lp-calc-lines'].forEach(function (needle) {
	check(parts.shortcode.indexOf(needle) === -1, 'page source: no ' + needle);
});
eq(parts.shortcode.match(/<li class="lp-calc__line">.*?<\/li>/g), ['<li class="lp-calc__line"><span class="lp-calc__line-text"><span class="lp-calc__line-label">UAE e-invoicing</span><span class="lp-calc__line-detail">1,000 transactions a year</span></span></li>'], 'page source: step 5 "Your package" lists the default selection, without an amount');
['quote_ref', 'quote_total', 'quote_summary'].forEach(function (id) {
	const m = new RegExp('<input[^>]*name="form_fields\\[' + id + '\\]"[^>]*>').exec(parts.form);
	check(m && !/ value="/.test(m[0]), 'form: hidden ' + id + ' has no value', m && m[0]);
});

console.log('3. config the page gets (LP_Calc_Pricing::browser_config())');
const cfg = configOf(parts.shortcode);
check(!!cfg, 'config JSON printed by the template');
eq(Object.keys(cfg).sort(), ['business_types', 'defaults', 'employees', 'reports_options', 'services', 'statuses', 'total_label', 'transactions'], 'config keys: choices, labels, defaults and the total label only');
eq(JSON.parse(H.php([path.join(H.UI, 'render.php'), '--config-json'], '', {})), cfg, 'printed config = LP_Calc_Pricing::browser_config()');
const keys = strings(cfg).filter(function (s, i, all) {
	return all.indexOf(s) === i;
});
['prices', 'currency', 'vat_rate', 'vat_included', 'hint'].forEach(function (k) {
	check(keys.indexOf(k) === -1, 'config has no "' + k + '"');
});
eq(A.findAmounts(JSON.stringify(cfg)), [], 'config: no amount in any key or string');
eq(cfg.services.map(function (s) {
	return Object.keys(s).join(',');
}), Array(9).fill('key,label,description'), 'services: key, label, description (no hint)');
eq(cfg.services[8].description, 'One point of contact for your account.', 'account manager description has no price statement');
eq(cfg.defaults, { transactions: 1000, employees: 'up_to_20', reports: 4, services: ['einvoicing'] }, 'defaults');

console.log('   with an lp_calc_config filter that adds prices');
const filtered = source(['--price-filter']);
const fsrc = [filtered.head, filtered.shortcode, filtered.form, filtered.footer].join('\n');
eq(A.findAmounts(fsrc), [], 'filtered: page source has no amount');
['2,150', '2150', '650'].forEach(function (n) {
	check(fsrc.indexOf(n) === -1, 'filtered: the filtered price ' + n + ' is not in the page');
});
const fcfg = configOf(filtered.shortcode);
check(fcfg && !('price_list' in fcfg) && !('note' in fcfg.defaults), 'filtered: unknown keys are not copied', fcfg && Object.keys(fcfg));
check(fcfg && !('description' in fcfg.services[0]) && !('description' in fcfg.services[8]), 'filtered: descriptions that state a price are left out', fcfg && fcfg.services);

/* 4. The assets. */
console.log('4. calculator.js, calculator.css and readme.txt');
const js = fs.readFileSync(JS_FILE, 'utf8');
// The code without comments. Counts that are not prices are allowed: the 1,000-character limit of
// the notes field (message and check) and the default of 1,000 transactions a year.
const jsCode = js.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
const jsScan = jsCode
	.replace('notes to 1,000 characters', 'notes to # characters')
	.replace('chars(v) <= 1000', 'chars(v) <= #')
	.replace("parseInt(this.value('transactions'), 10) || 1000", "parseInt(this.value('transactions'), 10) || #")
	.replace('String(defaults.transactions || 1000)', 'String(defaults.transactions || #)');
eq(A.findAmounts(jsScan), [], 'calculator.js code: no amount');
['prices', 'report_each', 'einvoicing_per_1000', 'vat_registration', 'payroll_over_20', 'ct_registered', 'currency', 'aria-live', 'compute(', 'money(', 'data-lp-calc-bar', 'data-lp-calc-total'].forEach(function (needle) {
	check(jsCode.indexOf(needle) === -1, 'calculator.js code: no "' + needle + '"');
});
eq(Object.keys(LPCalc).sort(), ['Calculator', 'boot', 'fmtInt', 'init', 'packageLines'], 'calculator.js exports no pricing helper');
// readme.txt ships in the zip and is a public file on most sites (/wp-content/plugins/...).
const readme = fs.readFileSync(path.join(H.PLUGIN, 'readme.txt'), 'utf8');
eq(A.findAmounts(readme), [], 'readme.txt (public in the plugin folder): no amount');
check(/^Stable tag: 1\.1\.0$/m.test(readme), 'readme.txt: Stable tag 1.1.0');
const css = fs.readFileSync(CSS_FILE, 'utf8');
const contents = (css.match(/content:\s*("[^"]*"|'[^']*'|[^;]+);/g) || []);
check(contents.length > 0 && contents.every(function (c) {
	return A.findAmounts(c).length === 0 && !/\d/.test(c.replace(/^content:\s*/, ''));
}), 'calculator.css: no amount in any content value', contents);
['lp-calc__bar', 'lp-calc__estimate', 'lp-calc__service-price', 'is-bar-open'].forEach(function (needle) {
	check(css.indexOf(needle) === -1, 'calculator.css: no ' + needle + ' rules');
});

/* 5. "Your package" parity. */
console.log('5. "Your package": calculator.js packageLines() = LP_Calc_Pricing::package_lines() for every selection');
const data = JSON.parse(H.php([path.join(H.UI, 'quote-oracle.php')], JSON.stringify({ mode: 'package' }), {}));
let bad = 0;
let badKeys = 0;
let withAmount = 0;
let first = null;
data.combos.forEach(function (c) {
	const lines = LPCalc.packageLines(cfg, c.s);
	if (JSON.stringify(lines) !== JSON.stringify(c.p)) {
		bad++;
		first = first || { s: c.s, js: lines, php: c.p };
	}
	if (JSON.stringify(lines.map(function (l) {
		return l.key;
	})) !== JSON.stringify(c.k)) {
		badKeys++;
	}
	if (A.findAmounts(lines.map(function (l) {
		return l.label + ' ' + l.detail;
	}).join('\n')).length) {
		withAmount++;
	}
});
console.log('   ' + data.combos.length + ' selections, ' + bad + ' mismatches');
eq(data.combos.length, 76760, 'selection count');
check(bad === 0, 'JS package lines equal PHP for every selection', first);
eq(badKeys, 0, 'package lists the services of the server quote, in the same order');
eq(withAmount, 0, 'no package line has an amount');
const excel = LPCalc.packageLines(cfg, { services: ['accounting', 'vat', 'ct', 'payroll'], vat_status: 'registered', ct_status: 'not_registered', employees: 'up_to_20', reports: 4, transactions: 1000 });
eq(excel, [
	{ key: 'accounting', label: 'Accounting and bookkeeping', detail: 'Annual service' },
	{ key: 'vat', label: 'VAT returns filing and compliance', detail: 'Already VAT registered' },
	{ key: 'ct', label: 'Corporate Tax registration and filing', detail: 'Includes Corporate Tax registration' },
	{ key: 'payroll', label: 'Payroll and WPS support', detail: 'Up to 20 employees' }
], 'Excel example: package lines');
eq(LPCalc.packageLines(cfg, { services: ['reports', 'einvoicing', 'account_manager'], reports: 1, transactions: 20000, vat_status: 'not_registered' }).map(function (l) {
	return l.detail;
}), ['1 report a year', '20,000 transactions a year', 'One point of contact'], 'details: 1 report, a transaction count, account manager without a price');

console.log('no-prices: ' + passes + ' passed, ' + failures + ' failed');
process.exit(failures ? 1 : 0);
