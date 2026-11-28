/*
 * Finds amounts in what the page gives a visitor (visible text, markup, attributes, the config JSON).
 * Used by test/ui/no-prices.test.js, test/ui/ui.test.js and test/e2e/e2e.test.js.
 *
 * An amount is:
 *   - a currency: "AED" (any case, also "AED2,150"), "dirham", "Dh" or "Dhs", the sign "د.إ";
 *   - a non-zero price from LP_Calc_Pricing::default_prices() written as "1,234" or "1234" (not part
 *     of a longer number: "500" does not match in "5,000" or "1500");
 *   - a price word the page used to show: "Included" (capital I: the price label of the account
 *     manager; "Registration included" in a description is scope, not a price), "extra charge",
 *     "free of charge", "per report", "From AED".
 *
 * Allowed, because the visitor needs them and they are counts, not prices (each is replaced by "#"
 * before the search). Nothing else is allowed: a value="2000" or data-*-value="2000" anywhere else
 * is an amount.
 *   1. "1,000 transactions a year" and the other transaction volume labels (step 2 select options,
 *      step 5 "Your package" line, the config's transaction labels);
 *   2. the numeric values of that list, 1000 to 50000, in three places only:
 *      - value="1000" on the <option>s of the step 2 select (data-lp-calc-input="transactions");
 *      - value="1000" on the form's hidden field form_fields[lpc_transactions], which calculator.js
 *        fills with the selected count (on a hidden input the value property is the attribute);
 *      - "value":1000 and "transactions":1000 (the default) in the config JSON.
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

const WORDS = [/\bAED(?![a-z])/i, /dirham/i, /\bdhs?\b/i, /\u062f\.?\s*\u0625/, /\bIncluded\b/, /extra charge/i, /free of charge/i, /\bper report\b/i, /\bFrom AED\b/i];

function escapeRe(s) {
	return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** The text with the allowed counts replaced by "#". */
function stripAllowed(text) {
	const isTx = function (n) {
		return TRANSACTIONS.indexOf(Number(n)) !== -1;
	};
	// value="N" (N a transaction count) inside one tag, as a whole attribute.
	const countValue = function (tag) {
		return tag.replace(/(\svalue=["']?)(\d+)(?=["'\s>])/g, function (m, a, n) {
			return isTx(n) ? a + '#' : m;
		});
	};
	return String(text)
		.replace(/\b\d{1,3}(?:,\d{3})* transactions a year\b/g, function (m) {
			return isTx(m.replace(/[^\d]/g, '')) ? '# transactions a year' : m;
		})
		// The step 2 select: its <option> tags only.
		.replace(/<select\b[^>]*\sdata-lp-calc-input="transactions"[^>]*>[\s\S]*?<\/select>/g, function (block) {
			return block.replace(/<option\b[^>]*>/g, countValue);
		})
		// The form's hidden lpc_transactions field.
		.replace(/<input\b[^>]*\sname="form_fields\[lpc_transactions\]"[^>]*>/g, countValue)
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
