/*
 * UI harness: the real site chrome (faithful header + site CSS, DM Sans), an approximation of the
 * draft page (hero + calculator container), the real [lp_package_calculator] shortcode rendered by
 * PHP CLI through the plugin (LP_Calc_Shortcode, LP_Calc_Pricing::config(), templates/calculator.php),
 * the assets the plugin enqueues, the Elementor Pro form widget rendered from page/out/page.json (the
 * structure of the live hero form), jQuery, the country-code plugin's intl-tel-input and a test double
 * of Elementor's form handler.
 *
 * Everything is served by Playwright routes on https://ledgerplus.test/ (no network, no server).
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const UI = __dirname;
const TEST = path.dirname(UI);
const CALC = path.dirname(TEST);
const PLUGIN = path.join(CALC, 'plugin', 'ledgerplus-calculator');
const ORIGIN = 'https://ledgerplus.test';
/** Where the page loads the plugin's enqueued assets from (render.php --json rewrites the plugin URL to this). */
const PLUGIN_URL_PATH = '/wp-content/plugins/ledgerplus-calculator/';

function php(args, input, env) {
	return execFileSync('php', args, {
		cwd: UI,
		input: input || '',
		env: Object.assign({}, process.env, env || {}),
		maxBuffer: 64 * 1024 * 1024,
		stdio: ['pipe', 'pipe', 'pipe']
	}).toString('utf8');
}

/**
 * The page parts from the REAL plugin (render.php --json): the [lp_package_calculator] shortcode output
 * through LP_Calc_Shortcode (real LP_Calc_Pricing::config() and template), the enqueued assets, and the
 * Elementor Pro form widget built from page/out/page.json.
 */
function renderParts(opts) {
	opts = opts || {};
	const args = [path.join(UI, 'render.php'), '--json'];
	if (opts.editor) {
		args.push('--editor');
	}
	if (opts.query) {
		args.push('--query=' + opts.query);
	}
	const parts = JSON.parse(php(args, '', {}));
	if (parts.notices && parts.notices.length) {
		throw new Error('PHP notices while rendering the page: ' + parts.notices.join('; '));
	}
	parts.assets.scripts.forEach(function (s) {
		s.deps.forEach(function (d) {
			if (d !== 'jquery') {
				throw new Error('script ' + s.handle + ' depends on ' + d + ', which the harness page does not load');
			}
		});
	});
	return parts;
}

/** The form widget's id in page/out/page.json (Elementor prints per-widget CSS with it). */
function formWidgetId() {
	const page = JSON.parse(fs.readFileSync(path.join(CALC, 'page', 'out', 'page.json'), 'utf8'));
	const data = typeof page.data === 'string' ? JSON.parse(page.data) : page.data;
	let id = '';
	(function walk(els) {
		(els || []).forEach(function (el) {
			if (!id && el.widgetType === 'form') {
				id = String(el.id);
			}
			walk(el.elements);
		});
	}(data));
	if (!id) {
		throw new Error('no form widget in page/out/page.json');
	}
	return id;
}

/** The per-widget defaults Elementor prints for a form with untouched style controls (post CSS). */
function formWidgetCss(id) {
	const w = '.elementor-9001 .elementor-element.elementor-element-' + id;
	return [
		w + ' .elementor-field-group{padding-right:calc( 10px/2 );padding-left:calc( 10px/2 );margin-bottom:10px;}',
		w + ' .elementor-form-fields-wrapper{margin-left:calc( -10px/2 );margin-right:calc( -10px/2 );margin-bottom:-10px;}',
		'body ' + w + ' .elementor-labels-above .elementor-field-group > label{padding-bottom:0px;}',
		w + ' .elementor-field-type-html{padding-bottom:0px;}',
		w + ' .elementor-field-group:not(.elementor-field-type-upload) .elementor-field:not(.elementor-select-wrapper){background-color:#ffffff;}',
		w + ' .elementor-field-group .elementor-select-wrapper select{background-color:#ffffff;}'
	].join('\n');
}

/** Slots in the page shell that the PHP side fills (render.php for the UI suite, router.php for e2e). */
const SLOTS = { head: '<!--lp:head-->', shortcode: '<!--lp:shortcode-->', form: '<!--lp:form-->', footer: '<!--lp:footer-->' };

function hero() {
	return '\t\t<div class="elementor-element elementor-element-a1c0de1 e-flex e-con-boxed e-con e-parent" data-id="a1c0de1" data-element_type="container" data-e-type="container" data-settings="{&quot;background_background&quot;:&quot;gradient&quot;}">\n' +
		'\t\t\t\t\t<div class="e-con-inner">\n' +
		'\t\t\t\t<div class="elementor-element elementor-element-b10e001 elementor-widget elementor-widget-heading" data-id="b10e001" data-element_type="widget" data-e-type="widget" data-widget_type="heading.default"><p class="elementor-heading-title elementor-size-default lp-hero-eyebrow">LEDGER PLUS UAE · PACKAGE CALCULATOR</p></div>\n' +
		'\t\t\t\t<div class="elementor-element elementor-element-b10e002 elementor-widget elementor-widget-heading" data-id="b10e002" data-element_type="widget" data-e-type="widget" data-widget_type="heading.default"><h1 class="elementor-heading-title elementor-size-default">Build a package that works for your business.</h1></div>\n' +
		'\t\t\t\t<div class="elementor-element elementor-element-b10e003 elementor-widget elementor-widget-text-editor" data-id="b10e003" data-element_type="widget" data-e-type="widget" data-widget_type="text-editor.default"><p class="lp-hero-sub">Choose your transaction volumes and the services you need. We\'ll prepare a clear estimate around the way your business operates.</p></div>\n' +
		'\t\t\t\t<div class="elementor-element elementor-element-b10e004 elementor-widget elementor-widget-html" data-id="b10e004" data-element_type="widget" data-e-type="widget" data-widget_type="html.default"><div class="lp-hero-tags"><span>AI-powered accounting</span><span>VAT &amp; Corporate Tax</span><span>E-invoicing</span></div></div>\n' +
		'\t\t\t\t\t</div>\n\t\t\t\t</div>\n';
}

/**
 * The page around the calculator: site header and CSS, DM Sans, the hero, the shortcode widget and the
 * form widget containers, jQuery, intl-tel-input and the Elementor form handler double. The plugin's
 * markup and assets go in the slots.
 */
function buildShell(opts) {
	opts = opts || {};
	const header = fs.readFileSync(path.join(UI, 'fixtures', 'site', 'header.html'), 'utf8');
	const fonts = [400, 500, 600, 700].map(function (w) {
		return "@font-face{font-family:'DM Sans';font-style:normal;font-weight:" + w + ';font-display:block;src:url(/fixtures/site/fonts/dm-sans-latin-' + w + "-normal.woff2) format('woff2')}";
	}).join('\n');
	const css = ['reset', 'frontend-part1', 'frontend-part2', 'post-4', 'nav-menu-subset', 'widget-heading', 'widget-form', 'post-32-subset']
		.map(function (c) {
			return '<link rel="stylesheet" href="/fixtures/site/css/' + c + '.css">';
		}).join('\n');
	const bodyClass = 'wp-singular page-template page-template-elementor_header_footer page page-id-9001 wp-embed-responsive wp-theme-hello-elementor theme-hello-elementor hello-elementor-default elementor-default elementor-template-full-width elementor-kit-4 elementor-page elementor-page-9001' +
		(opts.editMode ? ' elementor-editor-active' : '');
	return '<!doctype html>\n<html lang="en-US">\n<head>\n<meta charset="UTF-8">\n<meta name="viewport" content="width=device-width, initial-scale=1">\n' +
		'<title>Build Your Package - Ledger Plus (UI harness)</title>\n<style>\n' + fonts + '\n</style>\n' + css + '\n' +
		'<link rel="stylesheet" href="/fixtures/harness-extra.css">\n' +
		'<style id="elementor-post-9001-form">\n' + formWidgetCss(formWidgetId()) + '\n</style>\n' +
		'<link rel="stylesheet" href="/node_modules/intl-tel-input/build/css/intlTelInput.css">\n' +
		'<style>.iti__flag{background-image:url(/node_modules/intl-tel-input/build/img/flags.png)}@media (min-resolution:2x){.iti__flag{background-image:url(/node_modules/intl-tel-input/build/img/flags@2x.png)}}</style>\n' +
		SLOTS.head + '\n' +
		'</head>\n<body class="' + bodyClass + '">\n\n<a class="skip-link screen-reader-text" href="#content">Skip to content</a>\n\n' + header + '\n' +
		'\t\t<div data-elementor-type="wp-page" data-elementor-id="9001" class="elementor elementor-9001" data-elementor-post-type="page">\n' +
		hero() +
		'\t\t<div class="elementor-element elementor-element-c2a1c00 e-flex e-con-boxed e-con e-parent" data-id="c2a1c00" data-element_type="container" data-e-type="container">\n' +
		'\t\t\t\t\t<div class="e-con-inner">\n' +
		'\t\t\t\t<div class="elementor-element elementor-element-d5c0de1 elementor-widget elementor-widget-shortcode" data-id="d5c0de1" data-element_type="widget" data-e-type="widget" data-widget_type="shortcode.default">\n' +
		'\t\t\t\t\t\t<div class="elementor-shortcode">' + SLOTS.shortcode + '</div>\n\t\t\t\t\t\t</div>\n' +
		(opts.noForm ? '' : SLOTS.form + '\n') +
		'\t\t\t\t\t</div>\n\t\t\t\t</div>\n\t\t\t\t</div>\n' +
		'<script src="/node_modules/jquery/dist/jquery.min.js"></script>\n' +
		'<script src="/node_modules/intl-tel-input/build/js/intlTelInput.min.js"></script>\n' +
		'<script src="/fixtures/fake-elementor-forms.js"></script>\n' +
		SLOTS.footer + '\n' +
		'</body>\n</html>\n';
}

function fillShell(shell, parts) {
	return shell
		.replace(SLOTS.head, function () {
			return parts.head;
		})
		.replace(SLOTS.shortcode, function () {
			return parts.shortcode;
		})
		.replace(SLOTS.form, function () {
			return parts.form;
		})
		.replace(SLOTS.footer, function () {
			return parts.footer;
		});
}

function buildPage(opts, parts) {
	return fillShell(buildShell(opts), parts || renderParts(opts));
}

/** Minimal multipart/form-data parser (text fields only). */
function parseMultipart(buf, contentType) {
	const m = /boundary=(?:"([^"]+)"|([^;]+))/i.exec(contentType || '');
	const out = {};
	if (!m) {
		return out;
	}
	const boundary = '--' + (m[1] || m[2]);
	buf.toString('utf8').split(boundary).forEach(function (part) {
		const idx = part.indexOf('\r\n\r\n');
		if (idx === -1) {
			return;
		}
		const head = part.slice(0, idx);
		const nm = /name="([^"]*)"/.exec(head);
		if (!nm) {
			return;
		}
		out[nm[1]] = part.slice(idx + 4).replace(/\r\n$/, '');
	});
	return out;
}

const TYPES = { '.css': 'text/css', '.js': 'application/javascript', '.woff2': 'font/woff2', '.png': 'image/png', '.html': 'text/html' };

/**
 * Serve the harness on the page. Returns a control object for the fake admin-ajax endpoint.
 * ctl.mode: 'ok' | 'server-error' | 'no-quote' | 'email-failed' | 'emailed-error' (a later action such as
 * Zoho failed after the client email went out) | 'rate-limited' (form-level message only); ctl.posts: posted bodies.
 */
async function serve(page, opts) {
	opts = opts || {};
	const parts = renderParts(opts);
	const html = buildPage(opts, parts);
	const ctl = { mode: 'ok', posts: [], quotes: [], delay: 0, formId: parts.form_id, assets: parts.assets, earlyEnqueue: parts.early };
	await page.route(ORIGIN + '/**', async function (route) {
		const req = route.request();
		const url = new URL(req.url());
		const p = decodeURIComponent(url.pathname);
		if (p === '/' || p === '/package-calculator/') {
			return route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: html });
		}
		if (p === '/wp-admin/admin-ajax.php') {
			const fields = parseMultipart(req.postDataBuffer() || Buffer.alloc(0), req.headers()['content-type']);
			ctl.posts.push(fields);
			const ff = {};
			Object.keys(fields).forEach(function (k) {
				const mm = /^form_fields\[(.+)\]$/.exec(k);
				if (mm) {
					ff[mm[1]] = fields[k];
				}
			});
			let body;
			if (ctl.mode === 'server-error') {
				body = { success: false, data: { message: 'You have requested several quotations already. Please try again later or email info@ledgerplus.ae.', errors: { email: 'Please use a business email address.' }, data: [] } };
			} else if (ctl.mode === 'rate-limited') {
				const limit = 'You have requested several quotations already. Please try again later or email info@ledgerplus.ae.';
				body = { success: false, data: { message: limit, errors: { lpc_version: limit }, data: [] } };
			} else {
				const oracle = JSON.parse(php([path.join(UI, 'quote-oracle.php')], JSON.stringify({ mode: 'quote', fields: ff }), { LP_CALC_CONFIG: 'real' }));
				ctl.quotes.push(oracle);
				if (!oracle.success) {
					body = { success: false, data: { message: Object.values(oracle.errors).join(' '), errors: [], data: [] } };
				} else {
					const q = oracle.lp_quote;
					if (ctl.mode === 'email-failed') {
						q.email_sent = false;
					}
					if (ctl.mode === 'emailed-error') {
						body = { success: false, data: { message: 'Something went wrong. Please try again, or email info@ledgerplus.ae.', errors: [], data: { lp_quote: q } } };
					} else {
						body = { success: true, data: { message: 'Thank you. Your quotation is on its way to your inbox.', data: ctl.mode === 'no-quote' ? {} : { lp_quote: q } } };
					}
				}
			}
			if (ctl.delay) {
				await new Promise(function (r) {
					setTimeout(r, ctl.delay);
				});
			}
			return route.fulfill({ status: 200, contentType: 'application/json; charset=UTF-8', body: JSON.stringify(body) });
		}
		let file = null;
		if (p.indexOf('/fixtures/') === 0) {
			file = path.join(UI, p);
		} else if (p.indexOf('/node_modules/') === 0) {
			file = path.join(TEST, p);
		} else if (p.indexOf(PLUGIN_URL_PATH) === 0) {
			file = path.join(PLUGIN, p.slice(PLUGIN_URL_PATH.length));
		}
		if (file && fs.existsSync(file) && fs.statSync(file).isFile()) {
			return route.fulfill({ status: 200, contentType: TYPES[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file) });
		}
		return route.fulfill({ status: 404, body: 'not found: ' + p });
	});
	// Anything else (fonts.googleapis etc.) is blocked: tests run offline.
	await page.route(/^(?!https:\/\/ledgerplus\.test\/).*/, function (route) {
		return route.abort();
	});
	return ctl;
}

module.exports = { buildPage, buildShell, fillShell, renderParts, formWidgetId, parseMultipart, serve, php, SLOTS, ORIGIN, PLUGIN_URL_PATH, UI, TEST, CALC, PLUGIN };

if (require.main === module) {
	// node harness.js > page.html  (static copy for a quick look; asset paths are root-relative)
	process.stdout.write(buildPage({}));
}
