/*
 * End-to-end test: the real plugin behind php -S, the calculator page assembled from the real
 * shortcode and the page.json form widget, Elementor Pro's submission flow emulated in
 * test/e2e/router.php, and Chromium driving the page like a visitor.
 *
 *   node calculator/test/e2e/e2e.test.js        (exits non-zero on any failure)
 *
 * Proves:
 *   1. the server quote (lp_quote) equals the quote the UI showed, and the success panel shows the
 *      server ref and lines;
 *   2. the fake Zoho action's Description / Lead_Form / Lead_Source and the team email carry the
 *      server values, also when the browser sends tampered lpc_* and quote_* values;
 *   3. the captured client email has the PDF quotation attached (and the temp file is gone);
 *   4. server validation errors render in the form for bad input;
 *   5. the rate limit (3 per email per 24 h, 5 per IP per hour) kicks in.
 * Screenshots: calculator/test/out/e2e-*.png
 */
'use strict';

const fs = require('fs');
const path = require('path');
const net = require('net');
const crypto = require('crypto');
const { spawn, execFileSync } = require('child_process');
const { chromium } = require('playwright');
const H = require('../ui/harness');

const OUT = path.join(H.TEST, 'out');
const STATE = path.join(OUT, 'e2e');
const ROUTER = path.join(__dirname, 'router.php');
const LIMIT_MESSAGE = 'You have requested several quotations already. Please try again later or email info@ledgerplus.ae.';
const UTM = { utm_source: 'e2e-source', utm_medium: 'e2e-medium', utm_campaign: 'e2e-campaign', utm_term: 'e2e-term', utm_keyword: 'e2e-keyword' };
const LABELS = {
	accounting: 'Accounting and bookkeeping',
	reports: 'Quarterly financial reports',
	vat: 'VAT returns filing and compliance',
	ct: 'Corporate Tax registration and filing',
	payroll: 'Payroll and WPS support',
	expense: 'Expense management',
	invoicing: 'Invoicing and payment tracking',
	einvoicing: 'UAE e-invoicing',
	account_manager: 'Dedicated account manager'
};
const ORDER = Object.keys(LABELS);

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
	check(actual === expected, label, { actual: actual, expected: expected });
}
function deq(actual, expected, label) {
	check(JSON.stringify(actual) === JSON.stringify(expected), label, { actual: actual, expected: expected });
}
function md5(s) {
	return crypto.createHash('md5').update(s).digest('hex');
}
function fmt(n) {
	return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

/* ---------------- server ---------------- */

function freePort() {
	return new Promise(function (resolve, reject) {
		const srv = net.createServer();
		srv.unref();
		srv.on('error', reject);
		srv.listen(0, '127.0.0.1', function () {
			const port = srv.address().port;
			srv.close(function () {
				resolve(port);
			});
		});
	});
}

async function startServer() {
	fs.rmSync(STATE, { recursive: true, force: true });
	fs.mkdirSync(STATE, { recursive: true });
	fs.writeFileSync(path.join(STATE, 'shell.html'), H.buildShell({}));
	const port = await freePort();
	const proc = spawn('php', ['-S', '127.0.0.1:' + port, ROUTER], {
		env: Object.assign({}, process.env, { LP_E2E_STATE: STATE }),
		stdio: ['ignore', 'pipe', 'pipe']
	});
	let serverLog = '';
	proc.stdout.on('data', function (d) {
		serverLog += d;
	});
	proc.stderr.on('data', function (d) {
		serverLog += d;
	});
	const base = 'http://127.0.0.1:' + port;
	for (let i = 0; i < 100; i++) {
		try {
			const r = await fetch(base + '/__e2e/state');
			if (r.ok) {
				return { proc, base, log: function () {
					return serverLog;
				} };
			}
		} catch (e) { /* not up yet */ }
		await new Promise(function (r) {
			setTimeout(r, 100);
		});
	}
	proc.kill();
	throw new Error('php -S did not start: ' + serverLog);
}

async function state(base) {
	return (await fetch(base + '/__e2e/state')).json();
}

async function resetState(base, opts) {
	await fetch(base + '/__e2e/reset', { method: 'POST', body: JSON.stringify(opts || {}) });
}

/* ---------------- page helpers ---------------- */

async function open(browser, base, opts) {
	opts = opts || {};
	const context = await browser.newContext({ viewport: { width: opts.width || 1440, height: 900 }, reducedMotion: 'reduce' });
	const page = await context.newPage();
	const errors = [];
	page.on('pageerror', function (e) {
		errors.push('pageerror: ' + e.message);
	});
	page.on('console', function (m) {
		if (m.type() === 'error') {
			errors.push('console: ' + m.text());
		}
	});
	// Offline: only the php -S origin.
	await page.route(function (url) {
		return url.origin !== base;
	}, function (route) {
		return route.abort();
	});
	const qs = new URLSearchParams(opts.query || UTM).toString();
	await page.goto(base + '/package-calculator/' + (qs ? '?' + qs : ''));
	await page.waitForFunction(function () {
		return window.__lpElementorReady && document.querySelector('[data-lp-calc-ready]');
	});
	return { context, page, errors, url: base + '/package-calculator/' + (qs ? '?' + qs : '') };
}

async function shot(page, name) {
	await page.screenshot({ path: path.join(OUT, 'e2e-' + name + '.png'), fullPage: false });
}

/**
 * Drives steps 1-4 like a visitor and lands on step 5.
 * plan: business, other, tx, vat, ct, services[], employees, reports
 */
async function toStep5(page, plan) {
	await page.locator('[data-lp-calc-panel="1"] .lp-calc__choice-label', { hasText: plan.business }).first().click();
	if (plan.other !== undefined) {
		await page.fill('[data-lp-calc-input="business_other"]', plan.other);
	}
	await page.click('[data-lp-calc-next]');
	await page.selectOption('[data-lp-calc-input="transactions"]', String(plan.tx));
	await page.click('[data-lp-calc-next]');
	await page.locator('label[for$="-vat_status-' + plan.vat + '"]').click();
	await page.locator('label[for$="-ct_status-' + plan.ct + '"]').click();
	await page.click('[data-lp-calc-next]');
	for (const key of ORDER) {
		const input = page.locator('[data-lp-calc-service="' + key + '"] .lp-calc__service-input');
		if ((await input.isChecked()) !== (plan.services.indexOf(key) !== -1)) {
			await page.locator('[data-lp-calc-service="' + key + '"] .lp-calc__service-label').click();
		}
	}
	if (plan.employees) {
		await page.locator('label[for$="-employees-' + plan.employees + '"]').click();
	}
	if (plan.reports) {
		await page.selectOption('[data-lp-calc-input="reports"]', String(plan.reports));
	}
	await page.click('[data-lp-calc-next]');
	await page.waitForSelector('[data-lp-calc-panel="5"]:not([hidden])');
}

async function fillContact(page, values) {
	for (const k of Object.keys(values)) {
		await page.fill('.lp-calc-form [name="form_fields[' + k + ']"]', values[k]);
	}
}

/** The estimate the UI shows on step 5 (summary list and total). */
async function uiQuote(page) {
	return page.evaluate(function () {
		const lines = Array.prototype.map.call(document.querySelectorAll('[data-lp-calc-lines="summary"] .lp-calc__line'), function (li) {
			const d = li.querySelector('.lp-calc__line-detail');
			return { label: li.querySelector('.lp-calc__line-label').textContent.trim(), detail: d ? d.textContent.trim() : '', amount: li.querySelector('.lp-calc__line-amount').textContent.trim() };
		});
		return { lines: lines, total: document.querySelector('[data-lp-calc-total-static]').textContent.trim(), rail: document.querySelector('.lp-calc__estimate-total').textContent.trim() };
	});
}

async function resultPanel(page) {
	return page.evaluate(function () {
		const box = document.querySelector('[data-lp-calc-result]');
		const lines = Array.prototype.map.call(box.querySelectorAll('.lp-calc__line'), function (li) {
			const d = li.querySelector('.lp-calc__line-detail');
			return { label: li.querySelector('.lp-calc__line-label').textContent.trim(), detail: d ? d.textContent.trim() : '', amount: li.querySelector('.lp-calc__line-amount').textContent.trim() };
		});
		return {
			title: box.querySelector('.lp-calc__result-title').textContent.trim(),
			lead: box.querySelector('.lp-calc__result-lead').textContent.replace(/\s+/g, ' ').trim(),
			heading: box.querySelector('.lp-calc__summary-title').textContent.trim(),
			lines: lines,
			total: box.querySelector('.lp-calc__summary-total strong').textContent.trim()
		};
	});
}

async function submitAndWait(page, base, before) {
	await page.click('.lp-calc-form .elementor-button[type="submit"]');
	for (let i = 0; i < 100; i++) {
		const s = await state(base);
		if (s.requests.length > before) {
			await page.waitForFunction(function () {
				return !document.querySelector('.lp-calc-form form').classList.contains('elementor-form-waiting');
			});
			return s;
		}
		await page.waitForTimeout(100);
	}
	throw new Error('no request reached the endpoint');
}

/** Rewrites text parts of the multipart body in flight (a tampering client). */
async function tamper(page, changes) {
	await page.route('**/wp-admin/admin-ajax.php', function (route) {
		const req = route.request();
		const ct = req.headers()['content-type'] || '';
		const boundary = '--' + /boundary=(?:"([^"]+)"|([^;]+))/i.exec(ct).slice(1).filter(Boolean)[0];
		const body = req.postDataBuffer().toString('utf8');
		const parts = body.split(boundary);
		const out = parts.map(function (part) {
			const m = /name="([^"]*)"\r\n\r\n/.exec(part);
			if (m && Object.prototype.hasOwnProperty.call(changes, m[1])) {
				return part.slice(0, m.index + m[0].length) + changes[m[1]] + '\r\n';
			}
			return part;
		}).join(boundary);
		return route.continue({ postData: Buffer.from(out, 'utf8') });
	});
}

/** Posts the page's form as Elementor's handler would, with some fields overridden. */
async function postForm(page, overrides) {
	return page.evaluate(async function (o) {
		const fd = new FormData(document.querySelector('.lp-calc-form form'));
		Object.keys(o).forEach(function (k) {
			fd.set(k, o[k]);
		});
		fd.append('action', 'elementor_pro_forms_send_form');
		fd.append('referrer', location.toString());
		const r = await fetch('/wp-admin/admin-ajax.php', { method: 'POST', body: fd });
		return r.json();
	}, overrides);
}

/** A quote line as the page shows it: no detail that only repeats the amount (like the PDF and email). */
function shown(l) {
	return { label: l.label, detail: String(l.detail).toLowerCase() === String(l.amount).toLowerCase() ? '' : l.detail, amount: l.amount };
}

/** Spec format of LP_Calc_Pricing::summary_text(), restated independently. */
function expectedSummary(ref, plan, businessText, lines, total) {
	const st = function (v) {
		return v === 'registered' ? 'Already registered' : 'Not registered';
	};
	const out = ['Quote ' + ref, 'Business: ' + businessText, 'Annual transactions: ' + fmt(plan.tx), 'VAT: ' + st(plan.vat), 'Corporate Tax: ' + st(plan.ct)];
	if (plan.services.indexOf('payroll') !== -1) {
		out.push('Employees: ' + (plan.employees === 'over_20' ? 'More than 20 employees' : 'Up to 20 employees'));
	}
	if (plan.services.indexOf('reports') !== -1) {
		out.push('Reports a year: ' + (plan.reports || 4));
	}
	out.push('', 'Services:');
	lines.forEach(function (l) {
		out.push('- ' + l.label + ': ' + l.amount);
	});
	out.push('', 'Estimated annual total (incl. 5% VAT): ' + total);
	return out.join('\n');
}

function pdfText(file) {
	return execFileSync('pdftotext', ['-layout', file, '-']).toString('utf8');
}

/* ---------------- scenarios ---------------- */

const CUSTOMER = { name: 'Test Customer', company: 'Test Trading LLC', email: 'test.customer@example.com', phone: '50 123 4567', message: 'Test message: please call after 10am.' };

async function happyPath(browser, base, formId) {
	console.log('1. full submission through the UI (server quote = UI quote, Zoho, team email, client email + PDF)');
	const plan = { business: 'Trading / Wholesale', tx: 10000, vat: 'registered', ct: 'not_registered', services: ['accounting', 'reports', 'vat', 'ct', 'payroll', 'einvoicing'], employees: 'over_20', reports: 2 };
	const s = await open(browser, base);
	const { page } = s;
	await toStep5(page, plan);
	const ui = await uiQuote(page);
	eq(ui.total, 'AED 14,000', 'UI total for the plan (2,000 + 1,000 + 2,000 + 2,000 + 2,000 + 5,000)');
	eq(ui.rail, ui.total, 'rail total = step 5 total');
	await fillContact(page, CUSTOMER);
	const st = await submitAndWait(page, base, 0);
	await page.waitForSelector('[data-lp-calc-result]:not([hidden])');
	const req = st.requests[0];
	const res = req.response;

	// What the browser posted (Elementor's FormData).
	eq(req.post.action, 'elementor_pro_forms_send_form', 'posted action');
	eq(req.post.form_id, formId, 'posted form_id = page.json widget id');
	eq(req.post.post_id, '9001', 'posted post_id');
	deq({
		v: req.post.form_fields.lpc_version, b: req.post.form_fields.lpc_business, t: req.post.form_fields.lpc_transactions,
		vat: req.post.form_fields.lpc_vat_status, ct: req.post.form_fields.lpc_ct_status, e: req.post.form_fields.lpc_employees,
		r: req.post.form_fields.lpc_reports, s: req.post.form_fields.lpc_services
	}, { v: '1', b: 'Trading / Wholesale', t: '10000', vat: 'registered', ct: 'not_registered', e: 'over_20', r: '2', s: 'accounting,reports,vat,ct,payroll,einvoicing' }, 'posted lpc_* = the UI selection (raw values)');
	eq(req.post.form_fields.utm_source, 'e2e-source', 'posted utm_source from the request-arg dynamic tag');
	eq(req.post.form_fields.phone, CUSTOMER.phone.replace(/\s+/g, ''), 'phone posted without spaces (Elementor Pro\'s Tel field rejects them)');

	// Server quote = UI quote.
	check(res && res.success === true, 'response success', res);
	const q = res.data.data.lp_quote;
	check(/^LPQ-\d{6}-[0-9A-F]{5}$/.test(q.ref), 'server ref format LPQ-YYMMDD-XXXXX', q.ref);
	const d = new Date();
	eq(q.ref.slice(4, 10), String(d.getUTCFullYear()).slice(2) + String(d.getUTCMonth() + 1).padStart(2, '0') + String(d.getUTCDate()).padStart(2, '0'), 'ref carries the UTC date');
	eq(q.total, ui.total, 'server total = UI total');
	deq(q.lines.map(shown), ui.lines, 'server lines (label, detail, amount) = UI lines');
	eq(q.email, CUSTOMER.email, 'lp_quote.email');
	eq(q.email_sent, true, 'lp_quote.email_sent');
	eq(res.data.message, 'Thank you. Your quotation is on its way to your inbox.', 'success message from page.json');

	// Success panel: server ref and lines.
	const panel = await resultPanel(page);
	eq(panel.title, 'Thank you, Test.', 'result title');
	eq(panel.lead, 'Your quotation ' + q.ref + ' is on its way to ' + CUSTOMER.email + '.', 'result lead names the server ref and email');
	eq(panel.heading, 'Your quotation ' + q.ref, 'result heading carries the server ref');
	deq(panel.lines, q.lines.map(shown), 'result lines = server lines');
	eq(panel.total, q.total, 'result total = server total');
	eq(await page.locator('.lp-calc__result a[href$=".pdf"], .lp-calc__result [download]').count(), 0, 'no PDF download on the page');
	await shot(page, '1440-result');

	// Zoho lead (fake action: replace_setting_shortcodes() of the page.json mapping).
	eq(req.zoho.length, 1, 'Zoho action ran once');
	const z = req.zoho[0] || {};
	const summary = expectedSummary(q.ref, plan, 'Trading / Wholesale', q.lines, q.total);
	eq(z.Description, 'Client Company Name: ' + CUSTOMER.company + '\n\nPackage calculator estimate:\n' + summary, 'Zoho Description = company + server summary (spec format)');
	eq(z.Lead_Form, 'Ledgerplus Package Calculator', 'Zoho Lead_Form');
	eq(z.Lead_Source, 'Website', 'Zoho Lead_Source');
	eq(z.Lead_Status, 'Prospecting', 'Zoho Lead_Status');
	eq(z.Owner, '794156085', 'Zoho Owner');
	eq(z.Name, CUSTOMER.name, 'Zoho Name');
	eq(z.Email, CUSTOMER.email, 'Zoho Email');
	eq(z.Mobile, CUSTOMER.phone.replace(/\s+/g, ''), 'Zoho Mobile');
	eq(z.Client_s_message, CUSTOMER.message, 'Zoho Client_s_message');
	eq(z.Page_Name, 'Build Your Package', 'Zoho Page_Name (post-title dynamic tag)');
	eq(z.Landing_page_URL, s.url, 'Zoho Landing_page_URL ([geturl] dynamic tag)');
	deq({ s: z.utm_source, m: z.utm_medium, c: z.utm_campaign, t: z.utm_term, k: z.utm_keyword }, { s: UTM.utm_source, m: UTM.utm_medium, c: UTM.utm_campaign, t: UTM.utm_term, k: UTM.utm_keyword }, 'Zoho utm_* from the landing URL');
	eq(Object.keys(z).length, 16, 'all 16 Zoho mappings resolved');

	// Team email (fake Email action on the page.json settings).
	const te = req.team_email[0] || {};
	eq(te.to, 'info@ledgerplus.ae', 'team email to');
	eq(te.subject, 'Package calculator lead: ' + CUSTOMER.company + ' (' + q.ref + ')', 'team email subject');
	eq(te.reply_to, CUSTOMER.email, 'team email reply-to = client email field');
	eq(te.from, 'no-reply@ledgerplus.ae', 'team email from');
	check(te.content.indexOf(summary) !== -1, 'team email content has the server summary', te.content.slice(0, 400));
	check(/white-space:\s*pre-wrap[^>]*>\s*Quote LPQ-/.test(te.content), 'summary sits in a pre-wrap cell');
	check(te.content.indexOf(q.total) !== -1 && te.content.indexOf('[field id=') === -1, 'team email: total present, every [field] replaced');

	// Saved submission (save-to-database) has the server fields.
	const saved = req.saved[0] || {};
	eq(saved.quote_ref, q.ref, 'saved quote_ref');
	eq(saved.quote_total, q.total, 'saved quote_total');
	eq(saved.lpc_services, 'Accounting and bookkeeping, Quarterly financial reports, VAT returns filing and compliance, Corporate Tax registration and filing, Payroll and WPS support, UAE e-invoicing', 'saved lpc_services as labels');
	deq({ e: saved.lpc_employees, r: saved.lpc_reports, b: saved.lpc_business, o: saved.lpc_business_other }, { e: 'More than 20 employees', r: '2', b: 'Trading / Wholesale', o: '' }, 'saved selection fields match the quote');

	// Client email with the PDF.
	eq(req.mails.length, 1, 'one client email');
	const m = req.mails[0] || { attachments: [] };
	eq(m.to, CUSTOMER.email, 'client email to');
	eq(m.subject, 'Your Ledger Plus package estimate (' + q.ref + ')', 'client email subject');
	deq(m.headers, ['Content-Type: text/html; charset=UTF-8', 'Reply-To: Ledger Plus UAE <info@ledgerplus.ae>'], 'client email headers (no From)');
	eq(m.attachments.length, 1, 'one attachment');
	const a = m.attachments[0] || {};
	eq(a.name, 'Ledger-Plus-Quotation-' + q.ref + '.pdf', 'attachment file name');
	eq(a.head, '%PDF-1.4', 'attachment is a PDF 1.4');
	check(a.existed === true && a.exists_after === false && a.dir_after === false, 'temp PDF existed for wp_mail and was deleted with its folder', a);
	deq(req.tmp_after, [], 'temp dir empty after the request');
	const html = fs.readFileSync(m.html_file, 'utf8');
	check(html.indexOf('Hi Test') !== -1 && html.indexOf(q.ref) !== -1 && html.indexOf(q.total) !== -1, 'client email body: greeting, ref, total');
	check(/^Hi Test,/.test(m.alt_body), 'plain-text part set');
	if (a.saved_as) {
		const txt = pdfText(a.saved_as).replace(/[ \t]+/g, ' ');
		['QUOTATION ESTIMATE', q.ref, CUSTOMER.name, CUSTOMER.company, CUSTOMER.email, 'Trading / Wholesale', '10,000', 'More than 20 employees', '14,000', 'QUOTATION ACCEPTANCE', 'Page 1 of 1'].concat(q.lines.map(function (l) {
			return l.label;
		})).forEach(function (needle) {
			check(txt.indexOf(needle) !== -1, 'PDF shows "' + needle + '"');
		});
		execFileSync('pdftoppm', ['-r', '110', '-png', '-f', '1', '-l', '1', a.saved_as, path.join(OUT, 'e2e-quote')]);
	}

	deq(req.notices, [], 'no PHP notices in the request');
	eq(req.error_log, '', 'nothing logged by the plugin');
	check(s.errors.length === 0, 'no console errors (happy path)', s.errors);
	await s.context.close();
	return q;
}

async function tampered(browser, base) {
	console.log('2. tampered lpc_* and quote_* values (server values win)');
	const plan = { business: 'Other', other: 'Marine services', tx: 3000, vat: 'not_registered', ct: 'registered', services: ['accounting', 'expense', 'einvoicing', 'account_manager'] };
	const s = await open(browser, base);
	const { page } = s;
	await toStep5(page, plan);
	const ui = await uiQuote(page);
	eq(ui.total, 'AED 4,000', 'UI total (2,000 + 500 + 1,500 + Included)');
	// In the DOM: fields the JS never writes.
	await page.evaluate(function () {
		const set = function (id, v) {
			document.querySelector('.lp-calc-form [name="form_fields[' + id + ']"]').value = v;
		};
		set('quote_ref', 'LPQ-000000-00000');
		set('quote_total', 'AED 1');
		set('quote_summary', 'Tampered summary: everything free');
		set('field_lead_form', 'Tampered lead form');
		set('lead_source', 'Tampered source');
	});
	// In flight: lpc_* the JS just wrote (a client that skips our script).
	await tamper(page, {
		'form_fields[lpc_business_other]': '<b>Yacht</b> charter [field id="email"]',
		'form_fields[lpc_employees]': 'over_20',
		'form_fields[lpc_reports]': '99',
		'form_fields[lpc_services]': 'ACCOUNTING, Expense management,einvoicing , account_manager',
		'form_fields[quote_total]': 'AED 2'
	});
	await fillContact(page, { name: 'Dr. Test Tamper', company: 'Test Marine LLC', email: 'tamper.test@example.com', phone: '+971 50 000 0000', message: '' });
	const before = (await state(base)).requests.length;
	const st = await submitAndWait(page, base, before);
	await page.waitForSelector('[data-lp-calc-result]:not([hidden])');
	const req = st.requests[before];
	eq(req.post.form_fields.quote_total, 'AED 2', 'the tampered quote_total reached the server');
	eq(req.post.form_fields.field_lead_form, 'Tampered lead form', 'the tampered field_lead_form reached the server');
	eq(req.post.form_fields.lpc_reports, '99', 'the tampered lpc_reports reached the server');
	const res = req.response;
	check(res.success === true, 'tampered submission still succeeds', res);
	const q = res.data.data.lp_quote;
	eq(q.total, ui.total, 'server total = UI total (tampering ignored)');
	deq(q.lines.map(shown), ui.lines, 'server lines = UI lines (as displayed: no detail that repeats the amount)');
	eq((q.lines.filter(function (l) {
		return l.key === 'account_manager';
	})[0] || {}).detail, 'Included', 'lp_quote keeps the account manager detail; the page shows "Included" once');
	check(q.ref !== 'LPQ-000000-00000', 'server made its own ref');
	const z = req.zoho[0] || {};
	eq(z.Lead_Form, 'Ledgerplus Package Calculator', 'Zoho Lead_Form forced to the server value');
	eq(z.Lead_Source, 'Website', 'Zoho Lead_Source forced to the server value');
	const summary = expectedSummary(q.ref, plan, 'Other (Yacht charter (field id="email"))', q.lines, q.total);
	eq(z.Description, 'Client Company Name: Test Marine LLC\n\nPackage calculator estimate:\n' + summary, 'Zoho Description = server summary (no Employees/Reports lines, Other text cleaned, no shortcode)');
	check(z.Description.indexOf('Tampered') === -1 && !/AED [12](?![,\d])/.test(z.Description) && z.Description.indexOf('tamper.test@example.com') === -1 && z.Description.indexOf('<b>') === -1, 'no tampered value, tag or expanded [field] in the Description', z.Description);
	const saved = req.saved[0] || {};
	deq({ r: saved.quote_ref, t: saved.quote_total, f: saved.field_lead_form, l: saved.lead_source, s: saved.lpc_services }, { r: q.ref, t: q.total, f: 'Ledgerplus Package Calculator', l: 'Website', s: 'Accounting and bookkeeping, Expense management, UAE e-invoicing, Dedicated account manager' }, 'saved fields overwritten with the server values');
	const te = req.team_email[0] || {};
	check(te.subject === 'Package calculator lead: Test Marine LLC (' + q.ref + ')' && te.content.indexOf('Tampered') === -1 && te.content.indexOf(summary) !== -1, 'team email carries the server values', te.subject);
	const m = req.mails[0] || { attachments: [] };
	eq(m.subject, 'Your Ledger Plus package estimate (' + q.ref + ')', 'client email uses the server ref');
	if (m.attachments[0]) {
		const txt = pdfText(m.attachments[0].saved_as).replace(/[ \t]+/g, ' ');
		check(txt.indexOf('Yacht charter') !== -1 && txt.indexOf('<b>') === -1 && txt.indexOf('4,000') !== -1 && txt.indexOf('Tampered') === -1, 'PDF shows the server values', txt.slice(0, 1200));
	}
	const panel = await resultPanel(page);
	check(panel.lead.indexOf(q.ref) !== -1 && panel.total === q.total, 'result panel shows the server ref and total', panel);
	eq(panel.title, 'Thank you, Test.', 'page skips the title "Dr." like the email');
	check(/^Hi Test,/.test(m.alt_body || ''), 'email greets "Hi Test" for "Dr. Test Tamper"', (m.alt_body || '').slice(0, 40));
	deq(req.notices, [], 'no PHP notices (tampered)');
	await s.context.close();
}

async function validation(browser, base) {
	console.log('3. server validation errors render in the form');
	const plan = { business: 'Retail', tx: 1000, vat: 'registered', ct: 'registered', services: ['accounting'] };
	const s = await open(browser, base);
	const { page } = s;
	await toStep5(page, plan);

	// Inline checks mirror the server rules: a phone without 6 digits is stopped in the browser.
	await fillContact(page, { name: 'Test Customer', company: 'Test Trading LLC', email: 'test.customer@example.com', phone: '+(((-)))' });
	let before = (await state(base)).requests.length;
	await page.click('.lp-calc-form .elementor-button[type="submit"]');
	await page.waitForSelector('.lp-calc-form .elementor-field-group-phone .elementor-help-inline');
	eq((await state(base)).requests.length, before, 'phone with no digits: blocked in the browser (nothing posted)');
	await page.fill('.lp-calc-form [name="form_fields[name]"]', '\u{1F600}');
	await page.click('.lp-calc-form .elementor-button[type="submit"]');
	eq(await page.locator('.lp-calc-form .elementor-field-group-name .elementor-help-inline').count(), 1, 'one-character (emoji) name: blocked in the browser, as mb_strlen counts it');

	// Link-like company: stopped in the browser with the server's message.
	await fillContact(page, { name: 'Test Customer', company: 'Pay at https://evil.example/x', email: 'test.customer@example.com', phone: '50 123 4567' });
	before = (await state(base)).requests.length;
	await page.click('.lp-calc-form .elementor-button[type="submit"]');
	eq(await page.locator('.lp-calc-form .elementor-field-group-company .elementor-help-inline').textContent(), 'Please enter your company name without a web or email address.', 'link in the company: blocked in the browser');
	eq((await state(base)).requests.length, before, 'link in the company: nothing posted');

	// a) An address our inline check lets through but is_email() rejects.
	await fillContact(page, { name: 'Test Customer', company: 'Test Trading LLC', email: 'test.customer@example..com', phone: '50 123 4567' });
	before = (await state(base)).requests.length;
	let st = await submitAndWait(page, base, before);
	let req = st.requests[before];
	eq(req.response.success, false, 'bad email: error response');
	deq(req.response.data.errors, { email: 'Please enter a valid email address.' }, 'bad email: field error from the plugin');
	eq(req.response.data.message, 'Please check the highlighted fields and try again.', 'bad email: form message = invalid_message from page.json');
	eq(await page.locator('.lp-calc-form .elementor-field-group-email .elementor-help-inline').textContent(), 'Please enter a valid email address.', 'bad email: error rendered under the email field');
	eq((await page.locator('.lp-calc-form form > .elementor-message-danger').textContent()).trim(), 'Please check the highlighted fields and try again.', 'bad email: form message rendered');
	check(!(await page.locator('[data-lp-calc-result]').isVisible()), 'bad email: no result panel');
	check(req.zoho.length === 0 && req.team_email.length === 0 && req.mails.length === 0 && req.saved.length === 0, 'bad email: no action ran, no client email');
	await page.evaluate(function () {
		document.querySelector('.lp-calc-form form > .elementor-message-danger').scrollIntoView({ block: 'center' });
	});
	await shot(page, '1440-server-field-error');

	// b) A selection the server rejects (a client that bypasses our script): form-level message.
	await tamper(page, { 'form_fields[lpc_services]': 'accounting,free_gift' });
	await page.fill('.lp-calc-form [name="form_fields[email]"]', 'test.customer@example.com');
	before = (await state(base)).requests.length;
	st = await submitAndWait(page, base, before);
	req = st.requests[before];
	eq(req.response.success, false, 'unknown service: error response');
	const svcMsg = 'One of the selected services is not available. Please refresh the page and choose again.';
	eq(req.response.data.message, svcMsg, 'unknown service: form message (add_error_message)');
	eq(req.response.data.errors.lpc_services, svcMsg, 'unknown service: field error on lpc_services (stops the actions)');
	eq((await page.locator('.lp-calc-form form > .elementor-message-danger').textContent()).trim(), svcMsg, 'unknown service: message rendered in the form');
	check(req.zoho.length === 0 && req.mails.length === 0, 'unknown service: no Zoho lead, no client email');
	await page.evaluate(function () {
		document.querySelector('.lp-calc-form form > .elementor-message-danger').scrollIntoView({ block: 'center' });
	});
	await shot(page, '1440-server-selection-error');
	await page.unroute('**/wp-admin/admin-ajax.php');

	// b2) A phone error from the server (Elementor's Tel field): readable below the flag box, and
	// the red border goes once the visitor edits the number.
	await tamper(page, { 'form_fields[phone]': '50 123 4567' });
	before = (await state(base)).requests.length;
	st = await submitAndWait(page, base, before);
	req = st.requests[before];
	eq(req.response.data.errors.phone, 'The field accepts only numbers and phone characters (#, -, *, etc).', 'phone with spaces: Elementor Pro\'s Tel field error');
	const ph = await page.evaluate(function () {
		const group = document.querySelector('.lp-calc-form .elementor-field-group-phone');
		const msg = group.querySelector('.elementor-help-inline');
		const flag = group.querySelector('.iti__flag-container, .iti__country-container');
		return {
			insideIti: !!(msg && msg.closest('.iti')),
			msgTop: msg ? Math.round(msg.getBoundingClientRect().top) : 0,
			flagBottom: Math.round(flag.getBoundingClientRect().bottom),
			groupError: group.classList.contains('elementor-error'),
			focused: document.activeElement && document.activeElement.name
		};
	});
	check(!ph.insideIti && ph.msgTop >= ph.flagBottom && ph.groupError, 'server phone error sits below the flag box, on the field group', ph);
	eq(ph.focused, 'form_fields[phone]', 'focus moves to the field with the server error');
	await page.fill('.lp-calc-form [name="form_fields[phone]"]', '501234567');
	eq(await page.evaluate(function () {
		return document.querySelectorAll('.lp-calc-form .elementor-field-group-phone .elementor-error, .lp-calc-form .elementor-field-group-phone.elementor-error').length;
	}), 0, 'editing the phone clears the red border');
	await page.unroute('**/wp-admin/admin-ajax.php');

	// c) Raw POST with several bad values.
	const json = await postForm(page, {
		'form_fields[name]': 'T',
		'form_fields[company]': '',
		'form_fields[email]': 'test.customer@example.com',
		'form_fields[phone]': 'call me',
		'form_fields[message]': 'x'.repeat(1001),
		'form_fields[lpc_transactions]': '1234',
		'form_fields[lpc_vat_status]': 'maybe'
	});
	eq(json.success, false, 'raw bad POST: error response');
	deq(Object.keys(json.data.errors).sort(), ['company', 'lpc_transactions', 'lpc_vat_status', 'message', 'name', 'phone'], 'raw bad POST: every bad field reported');
	eq(json.data.errors.company, 'This field is required.', 'empty required field keeps Elementor\'s required message');
	eq(json.data.errors.name, 'Please enter your full name (2 to 80 characters).', 'name length message');
	check(json.data.message.indexOf('Choose your annual number of transactions.') !== -1 && json.data.message.indexOf('Tell us whether your business is registered for VAT.') !== -1, 'selection messages in the form message', json.data.message);
	st = await state(base);
	req = st.requests[st.requests.length - 1];
	check(req.zoho.length === 0 && req.mails.length === 0, 'raw bad POST: nothing sent');

	// c2) Raw POSTs with link-like text (a client that bypasses our script).
	let raw = await postForm(page, { 'form_fields[company]': 'Overdue: pay at https://evil.example/ledgerplus', 'form_fields[name]': 'Accounts www.evil.example', 'form_fields[phone]': '501234567', 'form_fields[email]': 'test.customer@example.com' });
	deq(raw.data && raw.data.errors, { name: 'Please enter your name without a web or email address.', company: 'Please enter your company name without a web or email address.' }, 'raw POST with links in name and company: rejected');
	raw = await postForm(page, { 'form_fields[company]': 'Test Trading LLC', 'form_fields[name]': 'Test Customer', 'form_fields[phone]': '501234567', 'form_fields[email]': 'test.customer@example.com', 'form_fields[lpc_business]': 'Other', 'form_fields[lpc_business_other]': 'Refund form at https://evil.example/r' });
	eq(raw.data && raw.data.errors && raw.data.errors.lpc_business_other, 'Please describe your business activity without a web or email address.', 'raw POST with a link in the Other activity: rejected');
	st = await state(base);
	check(st.requests.slice(-2).every(function (x) {
		return x.mails.length === 0 && x.zoho.length === 0;
	}), 'link-like text: no client email, no lead');

	// d) A form that is not the calculator is left alone.
	const other = await postForm(page, { form_id: 'deadbee' });
	eq(other.success, false, 'unknown form id: Elementor error, plugin not involved');
	st = await state(base);
	deq(st.requests[st.requests.length - 1].mails, [], 'unknown form id: no client email');
	check(s.errors.filter(function (e) {
		return e.indexOf('400') === -1;
	}).length === 0, 'no console errors (validation)', s.errors);
	await s.context.close();
}

async function rateLimit(browser, base, firstEmail) {
	console.log('4. rate limit (3 per email per 24 hours, 5 per IP per hour)');
	let st = await state(base);
	const counted = function (st, key) {
		const t = st.transients['lp_calc_rl_' + md5(key)];
		return t ? t.value.count : 0;
	};
	// So far: happy path (test.customer@), tampered (tamper.test@); failures are not counted.
	eq(counted(st, '127.0.0.1'), 2, 'IP count = successful submissions only (failed validation not counted)');
	eq(counted(st, firstEmail), 1, 'email count for the first customer');

	const plan = { business: 'Retail', tx: 1000, vat: 'registered', ct: 'registered', services: ['accounting'] };
	const s = await open(browser, base);
	const { page } = s;
	await toStep5(page, plan);
	const base4 = { 'form_fields[name]': 'Test Customer', 'form_fields[company]': 'Test Trading LLC', 'form_fields[phone]': '501234567' };

	let r = await postForm(page, Object.assign({}, base4, { 'form_fields[email]': firstEmail }));
	eq(r.success, true, 'email #2 allowed');
	r = await postForm(page, Object.assign({}, base4, { 'form_fields[email]': firstEmail.toUpperCase() }));
	eq(r.success, true, 'email #3 allowed (counted case-insensitively)');
	st = await state(base);
	eq(counted(st, firstEmail), 3, 'email count 3 (lowercased key)');
	const ttlEmail = st.transients['lp_calc_rl_' + md5(firstEmail)].ttl;
	const ttlIp = st.transients['lp_calc_rl_' + md5('127.0.0.1')].ttl;
	check(ttlEmail > 86000 && ttlEmail <= 86400, 'email window 24 hours (transient expiry)', ttlEmail);
	check(ttlIp > 3000 && ttlIp <= 3600, 'IP window 1 hour (transient expiry)', ttlIp);

	// 4th for the same email, through the UI: blocked, message rendered.
	await fillContact(page, { name: 'Test Customer', company: 'Test Trading LLC', email: firstEmail, phone: '50 123 4567' });
	const before = st.requests.length;
	st = await submitAndWait(page, base, before);
	let req = st.requests[before];
	eq(req.response.success, false, 'email #4 blocked');
	eq(req.response.data.message, LIMIT_MESSAGE, 'limit message');
	eq((await page.locator('.lp-calc-form form > .elementor-message-danger').textContent()).trim(), LIMIT_MESSAGE, 'limit message rendered in the form');
	check(!(await page.locator('[data-lp-calc-result]').isVisible()), 'no result panel when limited');
	check(req.zoho.length === 0 && req.mails.length === 0 && req.saved.length === 0, 'limited: no Zoho lead, no email, nothing saved');
	await page.evaluate(function () {
		document.querySelector('.lp-calc-form form > .elementor-message-danger').scrollIntoView({ block: 'center' });
	});
	await shot(page, '1440-rate-limited');
	eq(counted(st, '127.0.0.1'), 4, 'a blocked submission is not counted against the IP');
	const plus = firstEmail.replace('@', '+quote2@');
	r = await postForm(page, Object.assign({}, base4, { 'form_fields[email]': plus }));
	eq(r.success, false, 'plus-addressed variant of the same mailbox blocked (' + plus + ')');
	eq(r.data.message, LIMIT_MESSAGE, 'plus-addressed variant: limit message');
	st = await state(base);
	eq(counted(st, '127.0.0.1'), 4, 'the blocked variant is not counted against the IP');

	r = await postForm(page, Object.assign({}, base4, { 'form_fields[email]': 'third.test@example.com' }));
	eq(r.success, true, 'IP #5 allowed (new email)');
	r = await postForm(page, Object.assign({}, base4, { 'form_fields[email]': 'fourth.test@example.com' }));
	eq(r.success, false, 'IP #6 blocked');
	eq(r.data.message, LIMIT_MESSAGE, 'IP limit message');
	st = await state(base);
	req = st.requests[st.requests.length - 1];
	check(req.zoho.length === 0 && req.mails.length === 0, 'IP limited: nothing sent');
	const log = st.requests.map(function (x) {
		return x.error_log;
	}).join('');
	check(log.indexOf('@') === -1 && log.indexOf('Test Customer') === -1, 'no personal data in the error log', log);
	await s.context.close();
}

async function zohoFailure(browser, base) {
	console.log('5. Zoho action throws (Elementor error response; plugin still answers and cleans up)');
	// New state (the IP limit is used up by now); keep the earlier mail-* files for review.
	await resetState(base, { zoho_throws: true, keep_files: true });
	const s = await open(browser, base, { width: 390 });
	const { page } = s;
	await toStep5(page, { business: 'Retail', tx: 1000, vat: 'registered', ct: 'registered', services: ['accounting'] });
	await fillContact(page, { name: 'Test Customer', company: 'Test Trading LLC', email: 'zoho.fail@example.com', phone: '50 123 4567' });
	const st = await submitAndWait(page, base, 0);
	const req = st.requests[0];
	eq(req.response.success, false, 'Elementor reports the failed action');
	eq(req.response.data.message, 'Something went wrong. Please try again, or email info@ledgerplus.ae.', 'error_message from page.json');
	check(req.response.data.data && req.response.data.data.lp_quote && req.response.data.data.lp_quote.email_sent === true, 'new_record still ran: lp_quote in the error response, client emailed', req.response.data.data);
	deq(req.tmp_after, [], 'temp dir empty after a failed action');
	await page.waitForSelector('[data-lp-calc-result]:not([hidden])');
	eq(await page.locator('.lp-calc-form .elementor-message-danger').count(), 0, 'the client was emailed: no "try again" message (a retry would send a second lead and email)');
	const panel = await resultPanel(page);
	eq(panel.lead, 'Your quotation ' + req.response.data.data.lp_quote.ref + ' is on its way to zoho.fail@example.com.', 'result panel shown at 390px');
	eq((await page.locator('[data-lp-calc-count]').textContent()).trim(), 'QUOTATION SENT', 'header: quotation sent');
	await shot(page, '390-zoho-failed');
	await s.context.close();
}

(async function main() {
	fs.mkdirSync(OUT, { recursive: true });
	const pageJson = JSON.parse(fs.readFileSync(path.join(H.CALC, 'page', 'out', 'page.json'), 'utf8'));
	let formId = null;
	(function walk(list) {
		list.forEach(function (el) {
			if (el.widgetType === 'form' && /(^|\s)lp-calc-form(\s|$)/.test(el.settings._css_classes || '')) {
				formId = el.id;
			}
			walk(el.elements || []);
		});
	}(typeof pageJson.data === 'string' ? JSON.parse(pageJson.data) : pageJson.data));

	const server = await startServer();
	const browser = await chromium.launch();
	try {
		await resetState(server.base);
		await happyPath(browser, server.base, formId);
		await tampered(browser, server.base);
		await validation(browser, server.base);
		await rateLimit(browser, server.base, CUSTOMER.email);
		await zohoFailure(browser, server.base);
	} catch (e) {
		failures++;
		console.log('  ERROR ' + (e && e.stack ? e.stack : e));
		console.log(server.log().slice(-3000));
	} finally {
		await browser.close();
		server.proc.kill();
	}
	console.log('E2E: ' + passes + ' passed, ' + failures + ' failed');
	process.exit(failures ? 1 : 0);
}());
