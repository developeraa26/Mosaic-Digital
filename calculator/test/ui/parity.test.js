/*
 * JS / PHP pricing parity without a browser: requires the real assets/calculator.js in Node and
 * compares lines (key, amount, detail) and totals with PHP for every price-relevant combination
 * (services x VAT x CT x employees x reports x transactions), for the plugin's LP_Calc_Pricing and
 * for the spec-shaped stub config (plain lists) against the independent oracle in ref-pricing.php.
 *
 *   node calculator/test/ui/parity.test.js
 */
'use strict';

const path = require('path');
const H = require('./harness');
const LPCalc = require(path.join(H.PLUGIN, 'assets', 'calculator.js'));

let failures = 0;
let passes = 0;
function check(cond, label, extra) {
	if (cond) {
		passes++;
	} else {
		failures++;
		console.log('  FAIL ' + label + (extra !== undefined ? ' :: ' + JSON.stringify(extra) : ''));
	}
}

// Money format.
check(LPCalc.money(7000, { currency: 'AED' }) === 'AED 7,000', 'money 7,000');
check(LPCalc.money(0, { currency: 'AED' }) === 'AED 0', 'money 0');
check(LPCalc.money(1234567, { currency: 'AED' }) === 'AED 1,234,567', 'money 1,234,567');
check(LPCalc.money(500) === 'AED 500', 'money default currency');

// The real LP_Calc_Pricing (config through the plugin) and, for the plain-list config shape the
// spec shows, the spec-shaped stub config with the independent PHP oracle.
const sources = ['stub', 'real'];

sources.forEach(function (mode) {
	const cfg = JSON.parse(H.php([path.join(H.UI, 'render.php'), '--config-json'], '', { LP_CALC_CONFIG: mode }));
	const data = JSON.parse(H.php([path.join(H.UI, 'quote-oracle.php')], JSON.stringify({ mode: 'combos' }), { LP_CALC_CONFIG: mode }));
	check(data.source === mode, mode + ': PHP oracle used the ' + mode + ' pricing', data.source);
	let bad = 0;
	let first = null;
	data.combos.forEach(function (c) {
		const q = LPCalc.compute(cfg, c.s);
		const lines = q.lines.map(function (l) {
			return [l.key, l.amount, l.detail];
		});
		if (q.total !== c.t || JSON.stringify(lines) !== JSON.stringify(c.l)) {
			bad++;
			first = first || { s: c.s, js: lines, php: c.l };
		}
	});
	console.log('parity (' + data.source + ' PHP): ' + data.combos.length + ' combinations, ' + bad + ' mismatches');
	check(data.combos.length === 76760, data.source + ': combination count', data.combos.length);
	check(bad === 0, data.source + ': JS lines and totals equal PHP', first);

	// Named cases from the spec.
	const excel = LPCalc.compute(cfg, { services: ['accounting', 'vat', 'ct', 'payroll'], vat_status: 'registered', ct_status: 'not_registered', employees: 'up_to_20', reports: 4, transactions: 1000 });
	check(excel.total === 7000, data.source + ': Excel example is AED 7,000', excel.total);
	const max = LPCalc.compute(cfg, { services: ['accounting', 'reports', 'vat', 'ct', 'payroll', 'expense', 'invoicing', 'einvoicing', 'account_manager'], vat_status: 'not_registered', ct_status: 'not_registered', employees: 'over_20', reports: 4, transactions: 50000 });
	check(max.total === 37000, data.source + ': maximum is AED 37,000', max.total);
	check(max.lines.map(function (l) {
		return l.key;
	}).join(',') === 'accounting,reports,vat,ct,payroll,expense,invoicing,einvoicing,account_manager', data.source + ': lines in table order');
});

console.log('parity: ' + passes + ' passed, ' + failures + ' failed');
process.exit(failures ? 1 : 0);
