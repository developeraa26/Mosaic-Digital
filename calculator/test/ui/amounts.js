/*
 * Finds amounts in what the page gives a visitor (visible text, markup, attributes, the config JSON).
 * Used by test/ui/no-prices.test.js, test/ui/ui.test.js and test/e2e/e2e.test.js.
 *
 * An amount is:
 *   - the currency code "AED" (any case, as a word);
 *   - a non-zero price from LP_Calc_Pricing::default_prices() written as "1,234" or "1234" (not part
 *     of a longer number: "500" does not match in "5,000" or "1500");
 *   - a price word the page used to show: "Included" (capital I: the price label of the account
 *     manager; "Registration included" in a description is scope, not a price), "extra charge",
 *     "free of charge", "per report", "From AED".
 *
 * Allowed, because the visitor needs them and they are counts, not prices (each is replaced by "#"
 * before the search):
 *   1. "1,000 transactions a year" and the other transaction volume labels (step 2 select options,
 *      step 5 "Your package" line, the config's transaction labels);
 *   2. the numeric values of that list, 1000 to 50000: value="1000" on the step 2 options, and
 *      "value":1000 and "transactions":1000 (the default) in the config JSON.
 * Callers scanning a live DOM leave out the country-code plugin's list of dial codes (+500 is the
 * Falkland Islands); see pageDump() in ui.test.js.
 */
'use strict';

const path = require('path');
const { execFileSync } = require('child_process');

const ORACLE = path.join(__dirname, 'quote-oracle.php');
const DATA = JSON.parse(execFileSync('php', [ORACLE], { input: JSON.stringify({ mode: 'prices' }), cwd: __dirname }).toString('utf8'));
/** LP_Calc_Pricing::default_prices() */
const PRICES = DATA.prices;
/** LP_Calc_Pricing::TRANSACTIONS */
const TRANSACTIONS = DATA.transactions;

function fmt(n) {
	return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

const NEEDLES = (function () {
	const set = new Set();
	Object.keys(PRICES).forEach(function (k) {
		const p = Number(PRICES[k]);
		if (p > 0) {
			set.add(fmt(p));
			set.add(String(p));
		}
	});
	return Array.from(set);
}());

const WORDS = [/\bAED\b/i, /\bIncluded\b/, /extra charge/i, /free of charge/i, /\bper report\b/i, /\bFrom AED\b/i];

function escapeRe(s) {
	return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** The text with the allowed counts replaced by "#". */
function stripAllowed(text) {
	const isTx = function (n) {
		return TRANSACTIONS.indexOf(Number(n)) !== -1;
	};
	return String(text)
		.replace(/\b\d{1,3}(?:,\d{3})* transactions a year\b/g, function (m) {
			return isTx(m.replace(/[^\d]/g, '')) ? '# transactions a year' : m;
		})
		.replace(/(value=["']?)(\d+)(?=["'\s>])/g, function (m, a, n) {
			return isTx(n) ? a + '#' : m;
		})
		.replace(/("(?:value|transactions)":\s*)(\d+)/g, function (m, a, n) {
			return isTx(n) ? a + '#' : m;
		});
}

/**
 * @param {string} text Text or markup.
 * @return {Array<string>} Each amount found, with some context ("AED  ...<context>...").
 */
function findAmounts(text) {
	const s = stripAllowed(text);
	const hits = [];
	const add = function (re) {
		const g = new RegExp(re.source, re.flags.indexOf('g') === -1 ? re.flags + 'g' : re.flags);
		let m;
		while ((m = g.exec(s)) && hits.length < 20) {
			hits.push(m[0] + '  ...' + s.slice(Math.max(0, m.index - 40), m.index + m[0].length + 40).replace(/\s+/g, ' ') + '...');
		}
	};
	WORDS.forEach(add);
	NEEDLES.forEach(function (n) {
		add(new RegExp('(?<![\\d.,])' + escapeRe(n) + '(?![\\d]|[.,]\\d)'));
	});
	return hits;
}

module.exports = { findAmounts, stripAllowed, PRICES, TRANSACTIONS, NEEDLES };
