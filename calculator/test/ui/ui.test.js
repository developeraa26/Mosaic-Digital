/*
 * UI tests for the calculator front end (Playwright + the harness in harness.js).
 *
 *   node calculator/test/ui/ui.test.js            all checks + screenshots in calculator/test/out/ui-*.png
 *   LP_UI_SHOTS=0 node ...                         skip the screenshot matrix
 *
 * Exits non-zero when any check fails.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const H = require('./harness');

const OUT = path.join(H.TEST, 'out');
// The page always comes from the real plugin (shortcode, LP_Calc_Pricing::config(), template) and the
// form widget from page/out/page.json; the PHP oracle uses the real LP_Calc_Pricing too.
const CONFIG_MODE = 'real';
const SHOTS = process.env.LP_UI_SHOTS !== '0';
const WIDTHS = [1440, 1024, 768, 390, 360];

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

function eq(actual, expected, label) {
	check(actual === expected, label, { actual: actual, expected: expected });
}

async function open(browser, opts) {
	opts = opts || {};
	const context = await browser.newContext({
		viewport: { width: opts.width || 1440, height: opts.height || 900 },
		deviceScaleFactor: opts.dpr || 1,
		reducedMotion: opts.reducedMotion || 'reduce',
		hasTouch: (opts.width || 1440) < 800,
		isMobile: false
	});
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
	const ctl = await H.serve(page, Object.assign({ configMode: CONFIG_MODE }, opts));
	await page.goto(H.ORIGIN + '/package-calculator/');
	await page.waitForFunction(function () {
		return window.__lpElementorReady && document.querySelector('[data-lp-calc-ready]');
	});
	await page.evaluate(function () {
		return document.fonts.ready;
	});
	return { context, page, ctl, errors };
}

/* ---------------- helpers that drive the UI like a visitor ---------------- */

const sel = {
	next: '[data-lp-calc-next]',
	back: '[data-lp-calc-back]',
	count: '[data-lp-calc-count]',
	railTotal: '.lp-calc__estimate-total',
	barTotal: '.lp-calc__bar-total',
	form: '.lp-calc [data-lp-calc-form-slot] .lp-calc-form form'
};

async function chooseBusiness(page, label) {
	await page.locator('[data-lp-calc-panel="1"] .lp-calc__choice-label', { hasText: label }).first().click();
}

async function chooseStatus(page, key, value) {
	await page.locator('label[for$="-' + key + '-' + value + '"]').click();
}

async function setService(page, key, on) {
	const input = page.locator('[data-lp-calc-service="' + key + '"] .lp-calc__service-input');
	if ((await input.isChecked()) !== on) {
		await page.locator('[data-lp-calc-service="' + key + '"] .lp-calc__service-label').click();
	}
}

async function next(page) {
	await page.click(sel.next);
}

async function step(page) {
	return page.evaluate(function () {
		return parseInt(document.querySelector('[data-lp-calc]').getAttribute('data-step') || '1', 10);
	});
}

async function hiddenFields(page) {
	return page.evaluate(function () {
		const out = {};
		document.querySelectorAll('.lp-calc-form input[type="hidden"][name^="form_fields[lpc_"]').forEach(function (i) {
			out[i.name.replace(/^form_fields\[|\]$/g, '')] = i.value;
		});
		return out;
	});
}

async function text(page, selector) {
	return (await page.locator(selector).first().textContent()).replace(/\s+/g, ' ').trim();
}

/** Drives steps 1-4 with the Excel example and lands on step 5. */
async function toStep5(page, plan) {
	plan = Object.assign({ business: 'Retail', tx: '1000', vat: 'registered', ct: 'not_registered', services: ['accounting', 'vat', 'ct', 'payroll'] }, plan || {});
	await chooseBusiness(page, plan.business);
	if (plan.other) {
		await page.fill('[data-lp-calc-input="business_other"]', plan.other);
	}
	await next(page);
	await page.selectOption('[data-lp-calc-input="transactions"]', plan.tx);
	await next(page);
	await chooseStatus(page, 'vat_status', plan.vat);
	await chooseStatus(page, 'ct_status', plan.ct);
	await next(page);
	for (const key of ['accounting', 'reports', 'vat', 'ct', 'payroll', 'expense', 'invoicing', 'einvoicing', 'account_manager']) {
		await setService(page, key, plan.services.indexOf(key) !== -1);
	}
	await next(page);
}

async function fillContact(page, values) {
	values = Object.assign({ name: 'Test Customer', company: 'Test Trading LLC', email: 'test.customer@example.com', phone: '50 123 4567', message: 'Test message: we also need help with year-end accounts.' }, values || {});
	for (const k of Object.keys(values)) {
		await page.fill('.lp-calc-form [name="form_fields[' + k + ']"]', values[k]);
	}
}

/* ---------------- layout checks ---------------- */

async function layoutChecks(page, label) {
	const res = await page.evaluate(function () {
		function visible(el) {
			const r = el.getBoundingClientRect();
			const cs = getComputedStyle(el);
			return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none' && !el.closest('[hidden]');
		}
		const root = document.querySelector('.lp-calc');
		const small = [];
		const targets = root.querySelectorAll('button, select, .lp-calc__choice-label, .lp-calc__service-label, .lp-calc__pill-label, .lp-calc__input, .lp-calc__help-link, .lp-calc-form .elementor-field-textual:not([type="hidden"]), .lp-calc-form .elementor-button, .lp-calc-form .iti__selected-flag, .lp-calc__bar-row');
		targets.forEach(function (el) {
			if (!visible(el) || el.style.display === 'none') {
				return;
			}
			const r = el.getBoundingClientRect();
			if (r.height < 43.5 || r.width < 43.5) {
				small.push((el.className || el.tagName) + ' ' + Math.round(r.width) + 'x' + Math.round(r.height));
			}
		});
		// Horizontal overflow: the page, and anything inside the calculator poking out of it.
		const overflow = [];
		const rr = root.getBoundingClientRect();
		root.querySelectorAll('*').forEach(function (el) {
			if (!visible(el)) {
				return;
			}
			const r = el.getBoundingClientRect();
			if (r.right > Math.max(rr.right, window.innerWidth) + 1 || r.left < Math.min(rr.left, 0) - 1) {
				if (!el.closest('.iti__country-list') && !el.closest('.lp-calc__sr') && !el.matches('.lp-calc__sr, input[type="radio"], input[type="checkbox"]')) {
					overflow.push((el.className && el.className.baseVal === undefined ? el.className : el.tagName) + ' ' + Math.round(r.left) + '..' + Math.round(r.right));
				}
			}
		});
		return {
			scrollW: document.documentElement.scrollWidth,
			innerW: window.innerWidth,
			small: small.slice(0, 8),
			overflow: overflow.slice(0, 8)
		};
	});
	check(res.scrollW <= res.innerW, label + ': no horizontal page scroll', res);
	check(res.overflow.length === 0, label + ': nothing overflows the calculator', res.overflow);
	check(res.small.length === 0, label + ': tap targets >= 44px', res.small);
}

/** WCAG contrast of every visible text node in the calculator against its effective background. */
async function contrastChecks(page, label) {
	const bad = await page.evaluate(function () {
		function parse(c) {
			const m = /rgba?\(([^)]+)\)/.exec(c || '');
			if (!m) {
				return null;
			}
			const p = m[1].split(/[ ,/]+/).filter(Boolean).map(Number);
			return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
		}
		function blend(fg, bg) {
			return { r: fg.r * fg.a + bg.r * (1 - fg.a), g: fg.g * fg.a + bg.g * (1 - fg.a), b: fg.b * fg.a + bg.b * (1 - fg.a), a: 1 };
		}
		function lum(c) {
			const f = function (v) {
				v /= 255;
				return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
			};
			return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b);
		}
		function background(el) {
			const stack = [];
			for (let e = el; e; e = e.parentElement) {
				const cs = getComputedStyle(e);
				const img = cs.backgroundImage;
				if (img && img.indexOf('gradient') !== -1) {
					// Use the lighter end of the gradient (the worse case for light text).
					const cols = (img.match(/rgba?\([^)]+\)/g) || []).map(parse);
					if (cols.length) {
						cols.sort(function (a, b) {
							return lum(b) - lum(a);
						});
						stack.push(cols[0]);
						break;
					}
				}
				const bg = parse(cs.backgroundColor);
				if (bg && bg.a > 0) {
					stack.push(bg);
					if (bg.a >= 1) {
						break;
					}
				}
			}
			let out = { r: 255, g: 255, b: 255, a: 1 };
			for (let i = stack.length - 1; i >= 0; i--) {
				out = blend(stack[i], out);
			}
			return out;
		}
		const out = [];
		const walker = document.createTreeWalker(document.querySelector('.lp-calc'), NodeFilter.SHOW_TEXT);
		const seen = new Set();
		let n;
		while ((n = walker.nextNode())) {
			if (!n.textContent.trim()) {
				continue;
			}
			const el = n.parentElement;
			if (seen.has(el) || el.closest('[hidden], .lp-calc__sr, script, style') || el.closest('.elementor-field-type-hidden')) {
				continue;
			}
			seen.add(el);
			const r = el.getBoundingClientRect();
			const cs = getComputedStyle(el);
			if (!r.width || !r.height || cs.visibility === 'hidden' || parseFloat(cs.opacity) === 0) {
				continue;
			}
			const bg = background(el);
			const fg = blend(parse(cs.color), bg);
			const L1 = lum(fg);
			const L2 = lum(bg);
			const ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
			if (ratio < 4.5) {
				out.push(n.textContent.trim().slice(0, 30) + ' ' + ratio.toFixed(2));
			}
		}
		return out;
	});
	check(bad.length === 0, label + ': text contrast >= 4.5:1', bad.slice(0, 10));
}

async function shot(page, name, opts) {
	if (!SHOTS) {
		return;
	}
	opts = opts || {};
	const file = path.join(OUT, 'ui-' + name + '.png');
	if (opts.viewportOnly) {
		await page.screenshot({ path: file });
		return;
	}
	// Full page with the viewport stretched to the document, so sticky parts sit where they rest.
	const vp = page.viewportSize();
	const h = await page.evaluate(function () {
		return Math.ceil(document.documentElement.scrollHeight);
	});
	await page.setViewportSize({ width: vp.width, height: h });
	await page.screenshot({ path: file, fullPage: true });
	await page.setViewportSize(vp);
}

/* ---------------- test groups ---------------- */

async function testAssembly(browser) {
	console.log('page assembled from the real plugin (shortcode, enqueued assets) and page.json (form widget)');
	const pageJson = JSON.parse(fs.readFileSync(path.join(H.CALC, 'page', 'out', 'page.json'), 'utf8'));
	const elements = typeof pageJson.data === 'string' ? JSON.parse(pageJson.data) : pageJson.data;
	let widget = null;
	(function walk(list) {
		list.forEach(function (el) {
			if (el.widgetType === 'form' && /(^|\s)lp-calc-form(\s|$)/.test(el.settings._css_classes || '')) {
				widget = el;
			}
			walk(el.elements || []);
		});
	}(elements));
	check(!!widget, 'page.json has the lp-calc-form widget');
	const { page, ctl, errors, context } = await open(browser, { width: 1440 });
	eq(ctl.formId, widget.id, 'form widget id from page.json');
	const css = ctl.assets.styles.map(function (x) {
		return x.handle + ' ' + x.src;
	});
	const js = ctl.assets.scripts.map(function (x) {
		return x.handle + ' ' + x.src + ' deps=' + x.deps.join(',') + ' footer=' + x.footer;
	});
	eq(css.join(' | '), 'lp-calc /wp-content/plugins/ledgerplus-calculator/assets/calculator.css?ver=1.0.0', 'stylesheet enqueued by the plugin (version LP_CALC_VERSION)');
	eq(js.join(' | '), 'lp-calc /wp-content/plugins/ledgerplus-calculator/assets/calculator.js?ver=1.0.0 deps=jquery footer=true', 'script enqueued by the plugin (jquery, footer)');
	eq(ctl.earlyEnqueue, true, 'assets enqueued on wp_enqueue_scripts (shortcode found in the page.json _elementor_data)');
	const dom = await page.evaluate(function () {
		const names = Array.prototype.map.call(document.querySelectorAll('.lp-calc-form [name^="form_fields["]'), function (i) {
			return i.name.replace(/^form_fields\[|\]$/g, '');
		});
		return {
			configs: document.querySelectorAll('script#lp-calc-config').length,
			css: !!document.querySelector('link#lp-calc-css[href*="calculator.css?ver=1.0.0"]'),
			js: !!document.querySelector('script#lp-calc-js[src*="calculator.js?ver=1.0.0"]'),
			names: names,
			formName: document.querySelector('.lp-calc-form form').getAttribute('name'),
			button: document.querySelector('.lp-calc-form .elementor-button-text').textContent.trim(),
			pageTitle: document.querySelector('[name="form_fields[field_page_title]"]').value,
			pageUrl: document.querySelector('[name="form_fields[page_url]"]').value,
			leadForm: document.querySelector('[name="form_fields[field_lead_form]"]').value
		};
	});
	eq(dom.configs, 1, 'config printed once (template, not again by the shortcode)');
	check(dom.css && dom.js, 'page loads the enqueued calculator.css and calculator.js', dom);
	eq(dom.names.join(','), widget.settings.form_fields.map(function (f) {
		return f.custom_id;
	}).join(','), 'form fields in the page = page.json form_fields (custom_id order)');
	eq(dom.formName, 'Ledger Plus package calculator', 'form name from page.json');
	eq(dom.button, 'Email me my quotation', 'button text from page.json');
	eq(dom.pageTitle, 'Build Your Package', 'page title hidden field (dynamic post-title)');
	eq(dom.pageUrl, 'https://ledgerplus.ae/package-calculator/', 'page URL hidden field (dynamic [geturl])');
	eq(dom.leadForm, 'Ledgerplus Package Calculator', 'lead form hidden field value');
	check(errors.length === 0, 'no console errors (assembly)', errors);
	await context.close();
}

async function testFlow(browser) {
	console.log('flow at 1440 (keyboard focus, validation, hidden fields, submit, start again)');
	const { page, ctl, errors, context } = await open(browser, { width: 1440 });

	// Form moved into the slot, hidden until step 5, novalidate set, initial hidden values written.
	eq(await page.locator(sel.form).count(), 1, 'form widget moved into the slot');
	eq(await page.locator('.lp-calc-form').count(), 1, 'exactly one form widget');
	check(!(await page.locator(sel.form).isVisible()), 'form hidden before step 5');
	eq(await page.locator(sel.form).getAttribute('novalidate'), 'novalidate', 'form set to novalidate');
	let hf = await hiddenFields(page);
	eq(hf.lpc_version, '1', 'lpc_version');
	eq(hf.lpc_services, 'einvoicing', 'default services');
	eq(hf.lpc_transactions, '1000', 'default transactions');
	eq(hf.lpc_employees, 'up_to_20', 'default employees');
	eq(hf.lpc_reports, '4', 'default reports');
	eq(hf.lpc_business, '', 'no business yet');
	eq(await text(page, sel.railTotal), 'AED 500', 'initial total (e-invoicing, 1,000)');
	eq(await page.locator(sel.railTotal).getAttribute('aria-live'), 'polite', 'live total is aria-live polite');
	eq(await text(page, sel.count), 'STEP 01 / 05', 'step count 1');
	check(!(await page.locator(sel.back).isVisible()), 'no Back on step 1');
	eq(await page.locator('fieldset[data-lp-calc-field="business"] > legend').count(), 1, 'business group is a fieldset with legend');

	// Step 1 validation.
	await next(page);
	eq(await step(page), 1, 'stays on step 1 without a choice');
	eq(await text(page, '[data-lp-calc-error="business"]'), 'Please choose your business activity.', 'business error text');
	eq(await page.evaluate(function () {
		return document.activeElement && document.activeElement.getAttribute('data-lp-calc-input');
	}), 'business', 'focus moved to the first business radio');
	eq(await page.locator('[data-lp-calc-input="business"]').first().getAttribute('aria-invalid'), 'true', 'radios marked aria-invalid');
	await shot(page, '1440-1-business-error');
	await chooseBusiness(page, 'Other');
	check(!(await page.locator('[data-lp-calc-error="business"]').isVisible()), 'business error cleared on change');
	check(await page.locator('[data-lp-calc-input="business_other"]').isVisible(), 'Other reveals the text input');
	await next(page);
	eq(await step(page), 1, 'Other needs text');
	check(await page.locator('[data-lp-calc-error="business_other"]').isVisible(), 'Other text error shown');
	await shot(page, '1440-1-other-error');
	await page.fill('[data-lp-calc-input="business_other"]', 'Yacht charter');
	hf = await hiddenFields(page);
	eq(hf.lpc_business, 'Other', 'hidden business Other');
	eq(hf.lpc_business_other, 'Yacht charter', 'hidden business_other');
	await page.press('[data-lp-calc-input="business_other"]', 'Enter');
	eq(await step(page), 2, 'Enter in the text input continues to step 2');
	eq(await text(page, sel.count), 'STEP 02 / 05', 'step count 2');
	eq(await page.evaluate(function () {
		return document.activeElement && document.activeElement.className;
	}), 'lp-calc__title', 'focus on the step 2 heading');
	check(await page.locator('[data-lp-calc-step="1"].is-done').count() === 1, 'rail step 1 done');
	eq(await page.locator('[data-lp-calc-step="2"]').getAttribute('aria-current'), 'step', 'rail step 2 aria-current');
	eq(await page.evaluate(function () {
		return document.querySelector('[data-lp-calc-progress]').style.width;
	}), '40%', 'progress 40%');

	// Step 2.
	await page.selectOption('[data-lp-calc-input="transactions"]', '10000');
	eq(await text(page, sel.railTotal), 'AED 5,000', 'e-invoicing 10,000 transactions = AED 5,000');
	eq((await hiddenFields(page)).lpc_transactions, '10000', 'hidden transactions');
	await page.click(sel.back);
	eq(await step(page), 1, 'Back to step 1');
	check(await page.locator('[data-lp-calc-input="business_other"]').isVisible(), 'Other text still shown after Back');
	await next(page);
	await next(page);

	// Step 3.
	eq(await step(page), 3, 'on step 3');
	await next(page);
	eq(await step(page), 3, 'statuses required');
	check(await page.locator('[data-lp-calc-error="vat_status"]').isVisible(), 'VAT error');
	check(await page.locator('[data-lp-calc-error="ct_status"]').isVisible(), 'CT error');
	await shot(page, '1440-3-tax-error');
	await chooseStatus(page, 'vat_status', 'registered');
	await chooseStatus(page, 'ct_status', 'not_registered');
	hf = await hiddenFields(page);
	eq(hf.lpc_vat_status, 'registered', 'Yes maps to registered');
	eq(hf.lpc_ct_status, 'not_registered', 'No maps to not_registered');
	await next(page);

	// Step 4: the Excel example.
	eq(await step(page), 4, 'on step 4');
	check(await page.locator('[data-lp-calc-service="einvoicing"] .lp-calc__service-input').isChecked(), 'e-invoicing ticked by default');
	await setService(page, 'einvoicing', false);
	eq(await text(page, sel.railTotal), 'AED 0', 'no services = AED 0');
	await next(page);
	eq(await step(page), 4, 'at least one service required');
	eq(await text(page, '[data-lp-calc-error="services"]'), 'Please choose at least one service.', 'services error');
	await shot(page, '1440-4-services-error');
	for (const k of ['accounting', 'vat', 'ct', 'payroll']) {
		await setService(page, k, true);
	}
	check(!(await page.locator('[data-lp-calc-error="services"]').isVisible()), 'services error cleared');
	eq(await text(page, sel.railTotal), 'AED 7,000', 'Excel example = AED 7,000');
	check(await page.locator('[data-lp-calc-reveal="employees"]').isVisible(), 'payroll reveals employees');
	check(!(await page.locator('[data-lp-calc-reveal="reports"]').isVisible()), 'reports select hidden');
	await page.locator('label[for$="-employees-over_20"]').click();
	eq(await text(page, sel.railTotal), 'AED 8,000', 'over 20 employees adds AED 1,000');
	eq((await hiddenFields(page)).lpc_employees, 'over_20', 'hidden employees over_20');
	await page.locator('label[for$="-employees-up_to_20"]').click();
	await setService(page, 'reports', true);
	check(await page.locator('[data-lp-calc-reveal="reports"]').isVisible(), 'reports reveals the select');
	eq(await page.locator('[data-lp-calc-input="reports"]').inputValue(), '4', 'reports default 4');
	eq(await text(page, sel.railTotal), 'AED 9,000', '4 reports add AED 2,000');
	await page.selectOption('[data-lp-calc-input="reports"]', '2');
	eq(await text(page, sel.railTotal), 'AED 8,000', '2 reports add AED 1,000');
	eq((await hiddenFields(page)).lpc_reports, '2', 'hidden reports');
	await setService(page, 'reports', false);
	eq(await text(page, sel.railTotal), 'AED 7,000', 'back to AED 7,000');
	const railLines = await page.locator('[data-lp-calc-lines="rail"] .lp-calc__line').count();
	eq(railLines, 4, 'rail lists the 4 selected lines');
	hf = await hiddenFields(page);
	eq(hf.lpc_services, 'accounting,vat,ct,payroll', 'hidden services in table order');
	await next(page);

	// Step 5.
	eq(await step(page), 5, 'on step 5');
	check(!(await page.locator(sel.next).isVisible()), 'Continue hidden on step 5');
	check(await page.locator(sel.back).isVisible(), 'Back shown on step 5');
	check(await page.locator(sel.form).isVisible(), 'form visible in the slot');
	eq(await page.locator('[data-lp-calc-lines="summary"] .lp-calc__line').count(), 4, 'summary has 4 lines');
	eq(await text(page, '[data-lp-calc-total-static]'), 'AED 7,000', 'summary total');
	check((await text(page, '.lp-calc__pdf-note')).indexOf("We'll email this quotation to you as a PDF.") !== -1, 'PDF note');
	const labelStyle = await page.evaluate(function () {
		const l = document.querySelector('.lp-calc-form .elementor-field-group-name > label');
		const cs = getComputedStyle(l);
		return { pos: cs.position, color: cs.color, font: cs.fontFamily, h: l.getBoundingClientRect().height };
	});
	check(labelStyle.pos !== 'absolute' && labelStyle.h > 10, 'form labels visible', labelStyle);
	check(/DM Sans/.test(labelStyle.font), 'form labels in DM Sans', labelStyle);
	eq(labelStyle.color, 'rgb(3, 9, 34)', 'form label colour beats the kit rule');
	const btn = await page.evaluate(function () {
		const b = document.querySelector('.lp-calc-form .elementor-button[type="submit"]');
		const r = b.getBoundingClientRect();
		const f = b.closest('form').getBoundingClientRect();
		return { bg: getComputedStyle(b).backgroundColor, h: r.height, w: r.width, fw: f.width };
	});
	eq(btn.bg, 'rgb(15, 92, 216)', 'submit is brand blue (beats the kit accent)');
	check(btn.h >= 48, 'submit at least 48px tall', btn);
	check(Math.abs(btn.w - btn.fw) < 2, 'submit full width', btn);

	const tel = await page.evaluate(function () {
		const box = document.querySelector('.lp-calc-form .iti__flag-container');
		const input = document.querySelector('.lp-calc-form [name="form_fields[phone]"]');
		return { box: box ? Math.round(box.getBoundingClientRect().width) : 0, pad: parseFloat(getComputedStyle(input).paddingLeft) };
	});
	check(tel.box > 40 && tel.pad >= tel.box + 8, 'phone number clears the dial-code box', tel);

	// Empty submit: inline errors, nothing posted.
	await page.click('.lp-calc-form .elementor-button[type="submit"]');
	eq(ctl.posts.length, 0, 'invalid form is not posted');
	eq(await page.evaluate(function () {
		return window.__lpFakeSubmits || 0;
	}), 0, 'Elementor handler not reached');
	eq(await page.locator('.lp-calc-form .elementor-help-inline').count(), 4, '4 inline errors (name, company, email, phone)');
	eq(await page.evaluate(function () {
		return document.activeElement && document.activeElement.name;
	}), 'form_fields[name]', 'focus on the first invalid field');
	await shot(page, '1440-5-contact-errors');
	await fillContact(page, { email: 'not-an-email' });
	eq(await page.locator('.lp-calc-form .elementor-help-inline').count(), 0, 'errors clear while typing');
	await page.click('.lp-calc-form .elementor-button[type="submit"]');
	eq(await page.locator('.lp-calc-form .elementor-help-inline').count(), 1, 'only the email error remains');
	eq(ctl.posts.length, 0, 'still not posted');
	await page.fill('.lp-calc-form [name="form_fields[email]"]', 'test.customer@example.com');

	// Valid submit.
	await page.click('.lp-calc-form .elementor-button[type="submit"]');
	await page.waitForSelector('[data-lp-calc-result]:not([hidden])');
	eq(ctl.posts.length, 1, 'posted once');
	const post = ctl.posts[0] || {};
	eq(post.action, 'elementor_pro_forms_send_form', 'Elementor action posted');
	eq(post.form_id, ctl.formId, 'form_id posted (the page.json form widget id)');
	eq(post.post_id, '9001', 'post_id posted');
	eq(post['form_fields[lpc_version]'], '1', 'posted lpc_version');
	eq(post['form_fields[lpc_business]'], 'Other', 'posted lpc_business');
	eq(post['form_fields[lpc_business_other]'], 'Yacht charter', 'posted lpc_business_other');
	eq(post['form_fields[lpc_transactions]'], '10000', 'posted lpc_transactions');
	eq(post['form_fields[lpc_vat_status]'], 'registered', 'posted lpc_vat_status');
	eq(post['form_fields[lpc_ct_status]'], 'not_registered', 'posted lpc_ct_status');
	eq(post['form_fields[lpc_employees]'], 'up_to_20', 'posted lpc_employees');
	eq(post['form_fields[lpc_reports]'], '2', 'posted lpc_reports (raw value, server ignores it without reports)');
	eq(post['form_fields[lpc_services]'], 'accounting,vat,ct,payroll', 'posted lpc_services');
	eq(post['form_fields[name]'], 'Test Customer', 'posted name');
	eq(post['form_fields[field_lead_form]'], 'Ledgerplus Package Calculator', 'lead form field untouched');
	const serverQuote = (ctl.quotes[0] || {}).lp_quote || {};
	eq(serverQuote.total, 'AED 7,000', 'server (PHP) total for the posted fields = AED 7,000');

	// Result panel.
	const lead = await text(page, '.lp-calc__result-lead');
	eq(await text(page, '.lp-calc__result-title'), 'Thank you, Test.', 'result title uses the first name');
	eq(lead, 'Your quotation LPQ-261127-4F2A9 is on its way to test.customer@example.com.', 'result lead');
	eq(await page.locator('.lp-calc__result .lp-calc__line').count(), 4, 'server lines shown');
	eq(await text(page, '.lp-calc__result .lp-calc__summary-total strong'), 'AED 7,000', 'server total shown');
	check(await page.locator('[data-lp-calc-restart]').isVisible(), 'Start again shown');
	eq(await page.locator('.lp-calc__result a[href$=".pdf"], .lp-calc__result [download]').count(), 0, 'no PDF download');
	check(!(await page.locator('[data-lp-calc-contact]').isVisible()), 'contact section replaced');
	check(!(await page.locator('[data-lp-calc-actions]').isVisible()), 'actions hidden');
	eq(await text(page, sel.count), 'QUOTATION SENT', 'count shows completion');
	eq(await page.evaluate(function () {
		return document.activeElement && document.activeElement.classList.contains('lp-calc__result-title');
	}), true, 'focus on the result heading');
	await shot(page, '1440-6-result');

	// Start again.
	await page.click('[data-lp-calc-restart]');
	eq(await step(page), 1, 'Start again goes to step 1');
	check(!(await page.locator('[data-lp-calc-result]').isVisible()), 'result hidden after Start again');
	eq(await text(page, sel.railTotal), 'AED 500', 'total reset');
	hf = await hiddenFields(page);
	eq(hf.lpc_services, 'einvoicing', 'services reset');
	eq(hf.lpc_business, '', 'business reset');
	eq(hf.lpc_transactions, '1000', 'transactions reset');
	eq(hf.lpc_vat_status, '', 'VAT status reset');
	eq(await page.locator('[data-lp-calc-input="business"]:checked').count(), 0, 'no business ticked');
	eq(await page.locator('.lp-calc-form .elementor-message').count(), 0, 'Elementor messages removed');
	eq(await text(page, sel.count), 'STEP 01 / 05', 'count reset');

	// Second run, to check the form submits again after a reset.
	await toStep5(page, { business: 'Professional Services', services: ['einvoicing', 'account_manager'], tx: '50000' });
	await fillContact(page, { name: 'Test Second', email: 'second.test@example.com' });
	await page.click('.lp-calc-form .elementor-button[type="submit"]');
	await page.waitForSelector('[data-lp-calc-result]:not([hidden])');
	eq(ctl.posts.length, 2, 'second submission posted');
	eq(await text(page, '.lp-calc__result .lp-calc__summary-total strong'), 'AED 25,000', 'second result total');
	eq(await text(page, '.lp-calc__result .lp-calc__line:last-child .lp-calc__line-amount'), 'Included', 'account manager shows Included');

	check(errors.length === 0, 'no console or page errors', errors);
	await context.close();
}

async function testVariants(browser) {
	console.log('server error, email failed, no lp_quote, ajaxComplete fallback');

	// Server-side validation error rendered by Elementor's handler, styled by our CSS.
	let s = await open(browser, { width: 1440 });
	s.ctl.mode = 'server-error';
	await toStep5(s.page);
	await fillContact(s.page);
	await s.page.click('.lp-calc-form .elementor-button[type="submit"]');
	await s.page.waitForSelector('.lp-calc-form form > .elementor-message-danger');
	check(!(await s.page.locator('[data-lp-calc-result]').isVisible()), 'no result on a server error');
	eq(await s.page.locator('.lp-calc-form .elementor-field-group-email .elementor-help-inline').count(), 1, 'server field error under the email field');
	const dangerColor = await s.page.evaluate(function () {
		return getComputedStyle(document.querySelector('.lp-calc-form form > .elementor-message-danger')).color;
	});
	eq(dangerColor, 'rgb(156, 32, 21)', 'form message styled by the plugin');
	const align = await s.page.evaluate(function () {
		const e = document.querySelector('.lp-calc-form [name="form_fields[email]"]').getBoundingClientRect();
		const t = document.querySelector('.lp-calc-form [name="form_fields[phone]"]').getBoundingClientRect();
		return { email: Math.round(e.top), phone: Math.round(t.top) };
	});
	eq(align.phone, align.email, 'phone stays level with the email field when the email shows an error');
	await shot(s.page, '1440-5-server-error');
	await s.context.close();

	// Email could not be sent.
	s = await open(browser, { width: 1440 });
	s.ctl.mode = 'email-failed';
	await toStep5(s.page);
	await fillContact(s.page);
	await s.page.click('.lp-calc-form .elementor-button[type="submit"]');
	await s.page.waitForSelector('[data-lp-calc-result]:not([hidden])');
	check((await text(s.page, '.lp-calc__result-lead')).indexOf('could not email your quotation just now') !== -1, 'email_sent false message');
	eq(await text(s.page, sel.count), 'REQUEST RECEIVED', 'header does not claim the quotation was sent');
	await s.context.close();

	// Success without lp_quote (plugin failed): local estimate shown.
	s = await open(browser, { width: 1440 });
	s.ctl.mode = 'no-quote';
	await toStep5(s.page);
	await fillContact(s.page);
	await s.page.click('.lp-calc-form .elementor-button[type="submit"]');
	await s.page.waitForSelector('[data-lp-calc-result]:not([hidden])');
	eq(await text(s.page, '.lp-calc__result .lp-calc__summary-title'), 'Your estimate', 'falls back to the local estimate');
	eq(await text(s.page, '.lp-calc__result .lp-calc__summary-total strong'), 'AED 7,000', 'local total in the fallback');
	await s.context.close();

	// ajaxComplete fallback: drop our submit_success listener.
	s = await open(browser, { width: 1440 });
	await toStep5(s.page);
	await fillContact(s.page);
	await s.page.evaluate(function () {
		jQuery('.lp-calc-form form').off('submit_success');
	});
	await s.page.click('.lp-calc-form .elementor-button[type="submit"]');
	await s.page.waitForSelector('[data-lp-calc-result]:not([hidden])');
	eq(await text(s.page, '.lp-calc__result .lp-calc__summary-total strong'), 'AED 7,000', 'ajaxComplete fallback shows the server quote');
	check(s.errors.length === 0, 'no console errors in variants', s.errors);
	await s.context.close();
}

async function testNotices(browser) {
	console.log('missing form (editor and visitor), Elementor edit mode');
	let s = await open(browser, { width: 1440, noForm: true, editor: true });
	check(await s.page.locator('[data-lp-calc-editor-notice]').isVisible(), 'editors see the missing-form notice at the top');
	await toStep5(s.page);
	check(await s.page.locator('[data-lp-calc-missing]').isVisible(), 'editor notice in step 5');
	check((await text(s.page, '[data-lp-calc-missing]')).indexOf('"Ledger Plus package calculator" form widget') !== -1, 'editor notice names the form');
	await shot(s.page, '1440-5-editor-missing-form');
	await s.context.close();

	s = await open(browser, { width: 1440, noForm: true });
	eq(await s.page.locator('[data-lp-calc-editor-notice]').count(), 0, 'visitors get no editor notice');
	await toStep5(s.page);
	eq(await text(s.page, '[data-lp-calc-missing]'), 'Email info@ledgerplus.ae for a quotation.', 'visitor fallback text');
	const href = await s.page.locator('[data-lp-calc-mailto]').getAttribute('href');
	check(href.indexOf('mailto:info@ledgerplus.ae?subject=') === 0 && href.indexOf('AED%207%2C000') !== -1, 'mailto carries the estimate', href);
	await s.context.close();

	s = await open(browser, { width: 1440, editMode: true, editor: true });
	await toStep5(s.page);
	eq(await s.page.locator('[data-lp-calc-form-slot] .lp-calc-form').count(), 0, 'edit mode: form not moved');
	check(await s.page.locator('[data-lp-calc-editmode]').isVisible(), 'edit mode note shown');
	check(await s.page.locator('.elementor-widget.lp-calc-form').isVisible(), 'edit mode: form visible in its own container');
	await s.context.close();
}

async function testParityInBrowser(browser) {
	console.log('JS totals for every price-relevant combination (in the browser, against PHP)');
	const data = JSON.parse(H.php([path.join(H.UI, 'quote-oracle.php')], JSON.stringify({ mode: 'combos' }), { LP_CALC_CONFIG: CONFIG_MODE }));
	const { page, context } = await open(browser, { width: 1024 });
	const res = await page.evaluate(function (combos) {
		const cfg = JSON.parse(document.getElementById('lp-calc-config').textContent);
		let bad = [];
		combos.forEach(function (c) {
			const q = window.LPCalc.compute(cfg, c.s);
			const lines = q.lines.map(function (l) {
				return [l.key, l.amount, l.detail];
			});
			if (q.total !== c.t || JSON.stringify(lines) !== JSON.stringify(c.l)) {
				bad.push({ s: c.s, js: q.total, php: c.t, jsl: lines, phpl: c.l });
			}
		});
		return { n: combos.length, bad: bad.slice(0, 3), nbad: bad.length };
	}, data.combos);
	console.log('  ' + res.n + ' combinations against ' + data.source + ' PHP pricing');
	check(res.n > 70000, 'all combinations generated', res.n);
	eq(res.nbad, 0, 'JS lines and totals equal PHP for every combination');
	if (res.nbad) {
		console.log(JSON.stringify(res.bad, null, 1));
	}

	// The same through the UI controls for a seeded sample, checking the visible totals.
	let seed = 7;
	function rnd(n) {
		seed = (seed * 1103515245 + 12345) % 2147483648;
		return seed % n;
	}
	let uiBad = 0;
	for (let i = 0; i < 25; i++) {
		const c = data.combos[rnd(data.combos.length)];
		const shown = await page.evaluate(function (s) {
			const root = document.querySelector('[data-lp-calc]');
			function set(name, value) {
				root.querySelectorAll('[data-lp-calc-input="' + name + '"]').forEach(function (el) {
					if (el.type === 'radio') {
						el.checked = el.value === String(value);
					} else if (el.type === 'checkbox') {
						el.checked = s.services.indexOf(el.value) !== -1;
					} else {
						el.value = String(value);
					}
					el.dispatchEvent(new Event('change', { bubbles: true }));
				});
			}
			set('services');
			set('transactions', s.transactions);
			set('vat_status', s.vat_status);
			set('ct_status', s.ct_status);
			set('employees', s.employees);
			set('reports', s.reports);
			const hf = {};
			document.querySelectorAll('.lp-calc-form input[name^="form_fields[lpc_"]').forEach(function (inp) {
				hf[inp.name] = inp.value;
			});
			return { rail: document.querySelector('.lp-calc__estimate-total').textContent, bar: document.querySelector('.lp-calc__bar-total').textContent, hf: hf };
		}, c.s);
		const expected = 'AED ' + c.t.toLocaleString('en-US');
		if (shown.rail !== expected || shown.bar !== expected || shown.hf['form_fields[lpc_services]'] !== c.s.services.join(',') ||
			shown.hf['form_fields[lpc_transactions]'] !== String(c.s.transactions) || shown.hf['form_fields[lpc_vat_status]'] !== c.s.vat_status) {
			uiBad++;
			console.log('  UI mismatch', JSON.stringify({ s: c.s, expected: expected, shown: shown }));
		}
	}
	eq(uiBad, 0, 'visible totals and hidden fields match PHP for 25 sampled combinations');
	await context.close();
}

async function testExtremes(browser) {
	console.log('Excel example and maximum through the UI');
	const { page, context, ctl } = await open(browser, { width: 1440 });
	await toStep5(page, { services: ['accounting', 'vat', 'ct', 'payroll'], vat: 'registered', ct: 'not_registered' });
	eq(await text(page, '[data-lp-calc-total-static]'), 'AED 7,000', 'Excel example on step 5');
	await page.click(sel.back);
	for (const k of ['accounting', 'reports', 'vat', 'ct', 'payroll', 'expense', 'invoicing', 'einvoicing', 'account_manager']) {
		await setService(page, k, true);
	}
	await page.locator('label[for$="-employees-over_20"]').click();
	await page.click(sel.back);
	await chooseStatus(page, 'vat_status', 'not_registered');
	await page.click(sel.back);
	await page.selectOption('[data-lp-calc-input="transactions"]', '50000');
	await next(page);
	await next(page);
	await next(page);
	const expectedMax = JSON.parse(H.php([path.join(H.UI, 'quote-oracle.php')], JSON.stringify({ mode: 'quote', fields: { lpc_business: 'Retail', lpc_transactions: '50000', lpc_vat_status: 'not_registered', lpc_ct_status: 'not_registered', lpc_employees: 'over_20', lpc_reports: '4', lpc_services: 'accounting,reports,vat,ct,payroll,expense,invoicing,einvoicing,account_manager', email: 'x@example.com' } }), { LP_CALC_CONFIG: CONFIG_MODE })).lp_quote.total;
	eq(await text(page, '[data-lp-calc-total-static]'), expectedMax, 'maximum package total equals PHP (' + expectedMax + ')');
	eq(await page.locator('[data-lp-calc-lines="summary"] .lp-calc__line').count(), 9, 'nine lines at the maximum');
	await fillContact(page);
	await page.click('.lp-calc-form .elementor-button[type="submit"]');
	await page.waitForSelector('[data-lp-calc-result]:not([hidden])');
	eq(ctl.posts[0]['form_fields[lpc_services]'], 'accounting,reports,vat,ct,payroll,expense,invoicing,einvoicing,account_manager', 'all nine services posted');
	await shot(page, '1440-6-result-max');
	await context.close();
}

async function testResponsive(browser) {
	console.log('screenshots and layout checks at ' + WIDTHS.concat([320]).join(', '));
	for (const width of WIDTHS.concat([320])) {
		const s = await open(browser, { width: width, height: width < 800 ? 780 : 900 });
		const page = s.page;
		const L = 'w' + width;
		const save = WIDTHS.indexOf(width) !== -1;

		const bar = await page.locator('[data-lp-calc-bar]').isVisible();
		eq(bar, width <= 850, L + ': mini bar only at 850px and below');
		const railEstimate = await page.locator('.lp-calc__estimate').isVisible();
		eq(railEstimate, width > 850, L + ': rail estimate only above 850px');

		await layoutChecks(page, L + ' step 1');
		await contrastChecks(page, L + ' step 1');
		if (save) {
			await shot(page, width + '-1-business');
		}
		await chooseBusiness(page, 'Other');
		await page.fill('[data-lp-calc-input="business_other"]', 'Marine services and yacht charter');
		await next(page);
		await layoutChecks(page, L + ' step 2');
		if (save) {
			await shot(page, width + '-2-volumes');
		}
		await page.selectOption('[data-lp-calc-input="transactions"]', '20000');
		await next(page);
		await chooseStatus(page, 'vat_status', 'not_registered');
		await chooseStatus(page, 'ct_status', 'registered');
		await layoutChecks(page, L + ' step 3');
		await contrastChecks(page, L + ' step 3');
		if (save) {
			await shot(page, width + '-3-tax');
		}
		await next(page);
		for (const k of ['accounting', 'reports', 'vat', 'payroll', 'einvoicing', 'account_manager']) {
			await setService(page, k, true);
		}
		await layoutChecks(page, L + ' step 4');
		await contrastChecks(page, L + ' step 4');
		if (save) {
			await shot(page, width + '-4-services');
		}
		if (width <= 850) {
			// Sticky bar: visible at the bottom of the viewport while the card scrolls, opens the breakdown.
			await page.evaluate(function () {
				const card = document.querySelector('[data-lp-calc-card]');
				window.scrollTo(0, card.getBoundingClientRect().top + window.pageYOffset + 200);
			});
			const pos = await page.evaluate(function () {
				const r = document.querySelector('[data-lp-calc-bar]').getBoundingClientRect();
				return { bottom: Math.round(r.bottom), vh: window.innerHeight };
			});
			check(Math.abs(pos.bottom - pos.vh) <= 1, L + ': bar sticks to the viewport bottom', pos);
			await page.click('[data-lp-calc-bar-toggle]');
			eq(await page.locator('[data-lp-calc-bar-toggle]').getAttribute('aria-expanded'), 'true', L + ': breakdown opens');
			check(await page.locator('[data-lp-calc-lines="bar"] .lp-calc__line').count() === 6, L + ': breakdown lists the lines');
			await layoutChecks(page, L + ' breakdown open');
			await contrastChecks(page, L + ' breakdown open');
			if (save) {
				await shot(page, width + '-4-breakdown-open', { viewportOnly: true });
			}
			await page.click('[data-lp-calc-bar-row]');
			eq(await page.locator('[data-lp-calc-bar-toggle]').getAttribute('aria-expanded'), 'false', L + ': tapping the bar closes it');
		}
		await next(page);
		await layoutChecks(page, L + ' step 5');
		await contrastChecks(page, L + ' step 5');
		if (save) {
			await shot(page, width + '-5-details');
		}
		await page.click('.lp-calc-form .elementor-button[type="submit"]');
		await layoutChecks(page, L + ' step 5 errors');
		await contrastChecks(page, L + ' step 5 errors');
		if (save) {
			await shot(page, width + '-5-details-errors');
		}
		await fillContact(page);
		await page.click('.lp-calc-form .elementor-button[type="submit"]');
		await page.waitForSelector('[data-lp-calc-result]:not([hidden])');
		await layoutChecks(page, L + ' result');
		await contrastChecks(page, L + ' result');
		check(!(await page.locator('[data-lp-calc-bar]').isVisible()), L + ': bar hidden on the result');
		if (save) {
			await shot(page, width + '-6-result');
		}
		check(s.errors.length === 0, L + ': no console errors', s.errors);
		await s.context.close();
	}
}

/** Is the element inside the visible area: below the sticky site header, above the sticky estimate bar? */
async function inView(page, selector) {
	return page.evaluate(function (s) {
		const el = typeof s === 'string' ? document.querySelector(s) : document.activeElement;
		if (!el) {
			return { ok: false, why: 'missing' };
		}
		const r = el.getBoundingClientRect();
		const header = document.querySelector('.elementor-location-header');
		const top = header ? Math.max(0, header.getBoundingClientRect().bottom) : 0;
		const bar = document.querySelector('[data-lp-calc-bar]');
		const barTop = bar && getComputedStyle(bar).display !== 'none' ? bar.getBoundingClientRect().top : window.innerHeight;
		return { ok: r.height > 0 && r.top >= top - 1 && r.bottom <= Math.min(barTop, window.innerHeight) + 1, top: Math.round(r.top), bottom: Math.round(r.bottom), header: Math.round(top), barTop: Math.round(barTop), vh: window.innerHeight };
	}, selector || null);
}

async function toStep4(page) {
	await chooseBusiness(page, 'Retail');
	await next(page);
	await next(page);
	await chooseStatus(page, 'vat_status', 'registered');
	await chooseStatus(page, 'ct_status', 'registered');
	await next(page);
}

async function testReviewFixes(browser) {
	console.log('review fixes: pre-boot estimate, errors in view, link text, phone, failed actions, help box, focus, layout');

	// Before calculator.js runs, the server already prints the default estimate (e-invoicing, AED 500).
	const lists = '[data-lp-calc-lines="rail"], [data-lp-calc-lines="summary"], [data-lp-calc-lines="bar"]';
	const grab = function (page) {
		return page.evaluate(function (q) {
			return {
				totals: Array.prototype.map.call(document.querySelectorAll('[data-lp-calc-total], [data-lp-calc-total-static]'), function (el) {
					return el.textContent.trim();
				}),
				lists: Array.prototype.map.call(document.querySelectorAll(q), function (ul) {
					return ul.innerHTML.trim();
				})
			};
		}, lists);
	};
	let context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
	let page = await context.newPage();
	await H.serve(page, { configMode: CONFIG_MODE });
	await page.route('**/ledgerplus-calculator/assets/calculator.js*', function (r) {
		return r.abort();
	});
	await page.goto(H.ORIGIN + '/package-calculator/');
	const pre = await grab(page);
	await context.close();
	const booted = await open(browser, { width: 1440 });
	const post = await grab(booted.page);
	await booted.context.close();
	check(pre.totals.length === 3 && pre.totals.every(function (t) {
		return t === 'AED 500';
	}), 'before the script runs: every total shows the default AED 500', pre.totals);
	check(pre.lists.length === 3 && pre.lists.every(function (h) {
		return h.indexOf('UAE e-invoicing') !== -1;
	}), 'before the script runs: the line lists show e-invoicing', pre.lists);
	check(JSON.stringify(pre) === JSON.stringify(post), 'server markup = the script\'s first render (no jump, no aria-live announcement)', { pre: pre, post: post });

	// Group errors come into view under their legend (phone and desktop), and the cards turn red.
	for (const vp of [{ width: 390, height: 780 }, { width: 1440, height: 900 }]) {
		const s = await open(browser, vp);
		await toStep4(s.page);
		await setService(s.page, 'einvoicing', false);
		await next(s.page);
		eq(await step(s.page), 4, vp.width + ': stays on step 4 without a service');
		const v = await inView(s.page, '[data-lp-calc-error="services"]');
		check(v.ok, vp.width + ': "choose at least one service" is in view', v);
		eq(await s.page.evaluate(function () {
			return getComputedStyle(document.querySelector('.lp-calc__service')).borderTopColor;
		}), 'rgb(210, 122, 112)', vp.width + ': service cards show the error state');
		await shot(s.page, 'review-' + vp.width + '-4-services-error', { viewportOnly: true });
		await s.context.close();
	}
	let s = await open(browser, { width: 360, height: 640 });
	await next(s.page);
	const v1 = await inView(s.page, '[data-lp-calc-error="business"]');
	check(v1.ok, '360x640: step 1 error is in view', v1);
	await shot(s.page, 'review-360-1-business-error', { viewportOnly: true });
	await s.context.close();

	// Link-like text is stopped in the browser with the server's messages; the phone is sent without spaces.
	s = await open(browser, { width: 1440 });
	page = s.page;
	await chooseBusiness(page, 'Other');
	await page.fill('[data-lp-calc-input="business_other"]', 'Refund form at https://example.test/r');
	await next(page);
	eq(await step(page), 1, 'Other text with a link: stays on step 1');
	eq(await text(page, '[data-lp-calc-error="business_other"]'), 'Please describe your business activity without a web or email address.', 'Other text with a link: message');
	await page.fill('[data-lp-calc-input="business_other"]', 'Marine services');
	await next(page);
	await next(page);
	await chooseStatus(page, 'vat_status', 'registered');
	await chooseStatus(page, 'ct_status', 'registered');
	await next(page);
	await setService(page, 'payroll', true);
	await setService(page, 'reports', true);
	await setService(page, 'account_manager', true);
	const extras = await page.evaluate(function () {
		const fs = document.querySelector('fieldset.lp-calc__extra');
		const lg = fs.querySelector('legend');
		const rep = document.querySelector('div.lp-calc__extra');
		const lb = rep.querySelector('.lp-calc__extra-label');
		return {
			payroll: Math.round(lg.getBoundingClientRect().top - fs.getBoundingClientRect().top),
			reports: Math.round(lb.getBoundingClientRect().top - rep.getBoundingClientRect().top),
			pills: Array.prototype.map.call(fs.querySelectorAll('.lp-calc__pill-label'), function (l) {
				return Math.round(l.getBoundingClientRect().height);
			})
		};
	});
	check(Math.abs(extras.payroll - extras.reports) <= 2 && extras.payroll >= 10, 'payroll label sits under its divider like the reports label', extras);
	check(extras.pills.every(function (h) {
		return h <= 46;
	}), 'employee pills on one line', extras.pills);
	await page.locator('[data-lp-calc-service="payroll"]').scrollIntoViewIfNeeded();
	await shot(page, 'review-1440-4-extras', { viewportOnly: true });
	await next(page);
	const am = await page.evaluate(function () {
		const li = Array.prototype.filter.call(document.querySelectorAll('[data-lp-calc-lines="summary"] .lp-calc__line'), function (l) {
			return l.querySelector('.lp-calc__line-label').textContent === 'Dedicated account manager';
		})[0];
		return { detail: li.querySelectorAll('.lp-calc__line-detail').length, amount: li.querySelector('.lp-calc__line-amount').textContent };
	});
	eq(JSON.stringify(am), JSON.stringify({ detail: 0, amount: 'Included' }), 'account manager shows "Included" once');
	eq(await text(page, '[data-lp-calc-panel="5"] .lp-calc__intro'), 'Leave your details and we will send your quotation.', 'step 5 intro does not repeat the PDF note');
	check(await page.evaluate(function (id) {
		return document.querySelectorAll('.elementor-9001 .elementor-element.elementor-element-' + id + ' .elementor-field-group').length > 0;
	}, H.formWidgetId()), 'harness per-widget form CSS targets the page.json form widget');

	await fillContact(page, { name: 'Accounts www.example.test', phone: '050 123 4567' });
	await page.click('.lp-calc-form .elementor-button[type="submit"]');
	eq(await text(page, '.lp-calc-form .elementor-field-group-name .elementor-help-inline'), 'Please enter your name without a web or email address.', 'name with www.: message');
	await page.fill('.lp-calc-form [name="form_fields[company]"]', 'pay@example.test');
	await page.click('.lp-calc-form .elementor-button[type="submit"]');
	eq(await text(page, '.lp-calc-form .elementor-field-group-company .elementor-help-inline'), 'Please enter your company name without a web or email address.', 'company with @: message');
	eq(s.ctl.posts.length, 0, 'link-like name or company: nothing posted');

	// Country list: full width, one line per country; the dial-code button gets the brand focus ring.
	await page.click('.lp-calc-form .iti__selected-flag');
	const iti = await page.evaluate(function () {
		const list = document.querySelector('.lp-calc-form .iti__country-list');
		const rows = Array.prototype.slice.call(list.querySelectorAll('.iti__country')).slice(0, 12).map(function (li) {
			return Math.round(li.getBoundingClientRect().height);
		});
		return { width: Math.round(list.getBoundingClientRect().width), rows: rows, overflow: document.documentElement.scrollWidth - window.innerWidth };
	});
	check(iti.width >= 300 && iti.rows.every(function (h) {
		return h <= 44;
	}) && iti.overflow <= 0, 'country list is wide enough for one line per country', iti);
	await shot(page, 'review-1440-5-iti-open', { viewportOnly: true });
	await page.keyboard.press('Escape');
	await page.focus('.lp-calc-form [name="form_fields[email]"]');
	await page.keyboard.press('Tab');
	const ring = await page.evaluate(function () {
		const el = document.activeElement;
		const cs = getComputedStyle(el);
		return { cls: el.className, style: cs.outlineStyle, color: cs.outlineColor, width: cs.outlineWidth, shadow: cs.boxShadow };
	});
	check(/iti__selected-flag/.test(ring.cls) && ring.style === 'solid' && ring.color === 'rgb(15, 92, 216)' && ring.width === '3px' && ring.shadow === 'none', 'dial-code button: solid brand focus ring', ring);

	await page.fill('.lp-calc-form [name="form_fields[name]"]', 'Test Customer');
	await page.fill('.lp-calc-form [name="form_fields[company]"]', 'Test Marine LLC');
	await page.click('.lp-calc-form .elementor-button[type="submit"]');
	await page.waitForSelector('[data-lp-calc-result]:not([hidden])');
	eq(s.ctl.posts[0]['form_fields[phone]'], '0501234567', 'phone posted without spaces (Elementor Pro\'s Tel field rejects them)');
	eq((await page.locator('.lp-calc__result .lp-calc__line:last-child .lp-calc__line-detail').count()), 0, 'result: account manager shows "Included" once');
	check(s.errors.length === 0, 'no console errors (review fixes flow)', s.errors);
	await s.context.close();

	// A later action (Zoho) failed after the client was emailed: result, not "try again".
	s = await open(browser, { width: 390, height: 780 });
	s.ctl.mode = 'emailed-error';
	await toStep5(s.page);
	await fillContact(s.page);
	await s.page.click('.lp-calc-form .elementor-button[type="submit"]');
	await s.page.waitForSelector('[data-lp-calc-result]:not([hidden])');
	eq(await s.page.locator('.lp-calc-form .elementor-message-danger').count(), 0, 'emailed despite an action error: no danger message');
	eq(await text(s.page, sel.count), 'QUOTATION SENT', 'emailed despite an action error: quotation sent');
	await s.context.close();

	// Form-level error on a phone: brought into view above the sticky bar and focused.
	s = await open(browser, { width: 390, height: 780 });
	s.ctl.mode = 'rate-limited';
	await toStep5(s.page);
	await fillContact(s.page);
	await s.page.click('.lp-calc-form .elementor-button[type="submit"]');
	await s.page.waitForSelector('.lp-calc-form form > .elementor-message-danger');
	await s.page.waitForTimeout(50);
	const lim = await inView(s.page, null);
	const limFocus = await s.page.evaluate(function () {
		return document.activeElement.className;
	});
	check(/elementor-message-danger/.test(limFocus) && lim.ok, '390: form-level error focused and clear of the sticky bar', { focus: limFocus, view: lim });
	await shot(s.page, 'review-390-5-form-error', { viewportOnly: true });
	await s.context.close();

	// Field error from the server on a phone: focus moves to that field, in view.
	s = await open(browser, { width: 390, height: 780 });
	s.ctl.mode = 'server-error';
	await toStep5(s.page);
	await fillContact(s.page);
	await s.page.click('.lp-calc-form .elementor-button[type="submit"]');
	await s.page.waitForSelector('.lp-calc-form form > .elementor-message-danger');
	await s.page.waitForTimeout(50);
	const fe = await inView(s.page, null);
	eq(await s.page.evaluate(function () {
		return document.activeElement.name;
	}), 'form_fields[email]', '390: focus on the field the server rejected');
	check(fe.ok, '390: that field is clear of the sticky bar', fe);
	await s.context.close();

	// Keyboard focus at tablet width stays clear of the sticky bar.
	s = await open(browser, { width: 768, height: 1024 });
	await chooseBusiness(s.page, 'Retail');
	await next(s.page);
	await s.page.focus('[data-lp-calc-input="transactions"]');
	await s.page.keyboard.press('Tab');
	await s.page.keyboard.press('Tab');
	const kf = await inView(s.page, null);
	check(kf.ok, '768: tabbing to Back/Continue keeps them above the sticky bar', kf);
	await s.context.close();

	// Help box: below the card at 850px and narrower, in the rail above that.
	for (const w of [390, 1440]) {
		s = await open(browser, { width: w });
		const help = await s.page.evaluate(function () {
			const vis = function (el) {
				return !!el && el.getClientRects().length > 0 && getComputedStyle(el).display !== 'none';
			};
			const below = document.querySelector('.lp-calc__help--below');
			const rail = document.querySelector('.lp-calc__rail-sticky .lp-calc__help');
			const card = document.querySelector('[data-lp-calc-card]').getBoundingClientRect();
			return { below: vis(below), rail: vis(rail), tel: vis(below) ? !!below.querySelector('a[href="tel:+971503420832"]') : null, afterCard: vis(below) ? below.getBoundingClientRect().top >= card.bottom : null };
		});
		if (w <= 850) {
			check(help.below && !help.rail && help.tel && help.afterCard, w + ': "Need a hand?" call and email links below the card', help);
		} else {
			check(!help.below && help.rail, w + ': help box in the rail only', help);
		}
		await s.context.close();
	}
}

async function testMotion(browser) {
	console.log('reduced motion and smooth scroll');
	let s = await open(browser, { width: 390, height: 700, reducedMotion: 'no-preference' });
	await chooseBusiness(s.page, 'Retail');
	await s.page.evaluate(function () {
		window.scrollTo(0, document.body.scrollHeight);
	});
	await next(s.page);
	check(await s.page.locator('[data-lp-calc-panel="2"].is-entering').count() === 1, 'entry animation without reduced motion');
	await s.page.waitForTimeout(900);
	const top = await s.page.evaluate(function () {
		return Math.round(document.querySelector('[data-lp-calc]').getBoundingClientRect().top);
	});
	const header = await s.page.evaluate(function () {
		return Math.round(document.querySelector('.elementor-location-header').getBoundingClientRect().bottom);
	});
	check(top >= header && top <= header + 40, 'scrolls the calculator below the sticky header', { top: top, header: header });
	await s.context.close();

	s = await open(browser, { width: 390, height: 700, reducedMotion: 'reduce' });
	await chooseBusiness(s.page, 'Retail');
	await next(s.page);
	eq(await s.page.locator('.lp-calc__panel.is-entering').count(), 0, 'no entry animation with reduced motion');
	const tr = await s.page.evaluate(function () {
		return getComputedStyle(document.querySelector('.lp-calc__progress-fill')).transitionDuration;
	});
	eq(tr, '0s', 'transitions off with reduced motion');
	await s.context.close();
}

(async function main() {
	fs.mkdirSync(OUT, { recursive: true });
	const browser = await chromium.launch();
	try {
		await testAssembly(browser);
		await testFlow(browser);
		await testVariants(browser);
		await testReviewFixes(browser);
		await testNotices(browser);
		await testParityInBrowser(browser);
		await testExtremes(browser);
		await testMotion(browser);
		await testResponsive(browser);
	} catch (e) {
		failures++;
		console.log('  ERROR ' + (e && e.stack ? e.stack : e));
	} finally {
		await browser.close();
	}
	console.log('UI: ' + passes + ' passed, ' + failures + ' failed (config: ' + CONFIG_MODE + ')');
	process.exit(failures ? 1 : 0);
}());
