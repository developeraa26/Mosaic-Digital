/*!
 * Ledger Plus package calculator (front end).
 *
 * Reads the config printed by templates/calculator.php (choices and labels, no prices), runs the five
 * steps, lists the selected services in step 5 ("Your package"), moves the page's Elementor Pro form
 * (widget class "lp-calc-form") into step 5, writes the hidden lpc_* fields and shows the server's
 * quote after a successful submit.
 *
 * The browser prices nothing: the price shows only on the result screen, from the server's response
 * (lp_quote). In Node, require() returns the pure helper packageLines() for the tests.
 */
(function (root, factory) {
	'use strict';
	var api = factory(root);
	if (typeof module === 'object' && module.exports) {
		module.exports = api;
	}
	if (root && root.document) {
		root.LPCalc = api;
		api.boot();
	}
}(typeof window !== 'undefined' ? window : null, function (win) {
	'use strict';

	var doc = win && win.document;
	var STEPS = 5;
	var HIDDEN_KEYS = ['lpc_version', 'lpc_business', 'lpc_business_other', 'lpc_transactions', 'lpc_vat_status', 'lpc_ct_status', 'lpc_employees', 'lpc_reports', 'lpc_services'];
	/** Figure fields: the server writes them; the browser sends them empty. */
	var FIGURE_KEYS = ['quote_ref', 'quote_total', 'quote_summary'];
	var FORM_ACTION = 'elementor_pro_forms_send_form';
	/** Same rule as LP_Calc_Pricing::LINK_PATTERN: text a mail app or PDF viewer would turn into a link. */
	var LINK_RE = /:\/\/|\bwww\.|@|\bmailto:/i;

	var TEXT = {
		step: 'STEP',
		done: 'QUOTATION SENT',
		received: 'REQUEST RECEIVED',
		noServices: 'Choose at least one service in step 4.',
		annual: 'Annual service',
		vatIncluded: 'Includes VAT registration',
		vatAlready: 'Already VAT registered',
		ctIncluded: 'Includes Corporate Tax registration',
		ctAlready: 'Already registered for Corporate Tax',
		accountManager: 'One point of contact',
		errBusiness: 'Please choose your business activity.',
		errBusinessOther: 'Please tell us your business activity (up to 80 characters).',
		errBusinessOtherLink: 'Please describe your business activity without a web or email address.',
		errVat: 'Please tell us whether your business is registered for VAT.',
		errCt: 'Please tell us whether your business is registered for Corporate Tax.',
		errServices: 'Please choose at least one service.',
		errName: 'Please enter your full name (2 to 80 characters).',
		errNameLink: 'Please enter your name without a web or email address.',
		errCompany: 'Please enter your company name (2 to 120 characters).',
		errCompanyLink: 'Please enter your company name without a web or email address.',
		errEmail: 'Please enter a valid email address.',
		errPhone: 'Please enter a valid contact number (at least 6 digits).',
		errMessage: 'Please keep your notes to 1,000 characters or fewer.',
		errRequired: 'This field is required.',
		next: 'What happens next',
		nextText: 'A Ledger Plus advisor will review your selections and contact you within one business day to confirm scope and pricing.',
		startAgain: 'Start again',
		yourQuote: 'Your quotation',
		yourPackage: 'Your package'
	};

	/* ------------------------------------------------------------------ */
	/* Pure helpers (shared with the Node tests)                           */
	/* ------------------------------------------------------------------ */

	function fmtInt(n) {
		n = Math.round(Number(n) || 0);
		var s = String(Math.abs(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
		return (n < 0 ? '-' : '') + s;
	}

	function optionLabel(list, value, fallback) {
		var i, item;
		list = list || [];
		for (i = 0; i < list.length; i++) {
			item = list[i];
			if (item && typeof item === 'object' && String(item.value) === String(value)) {
				return String(item.label);
			}
		}
		return fallback;
	}

	function reportsOptions(cfg) {
		var list = (cfg && cfg.reports_options) || [1, 2, 3, 4];
		return list.map(function (o) {
			return parseInt(o && typeof o === 'object' ? o.value : o, 10);
		});
	}

	/**
	 * The "Your package" lines: one per selected service, in config order, with a short detail and no
	 * amount. Same lines as LP_Calc_Pricing::package_lines() on the server.
	 *
	 * @param {Object} cfg Config from LP_Calc_Pricing::browser_config().
	 * @param {Object} sel {services:[keys], transactions, vat_status, ct_status, employees, reports}
	 * @return {Array<{key, label, detail}>}
	 */
	function packageLines(cfg, sel) {
		var chosen = (sel && sel.services) || [];
		var lines = [];
		((cfg && cfg.services) || []).forEach(function (svc) {
			if (!svc || !svc.key || chosen.indexOf(svc.key) === -1) {
				return;
			}
			lines.push({ key: svc.key, label: String(svc.label || svc.key), detail: packageDetail(cfg, svc.key, sel) });
		});
		return lines;
	}

	function packageDetail(cfg, key, sel) {
		var n;
		switch (key) {
			case 'reports':
				n = parseInt(sel.reports, 10);
				if (reportsOptions(cfg).indexOf(n) === -1) {
					n = 4;
				}
				return n + (n === 1 ? ' report a year' : ' reports a year');
			case 'vat':
				return sel.vat_status === 'not_registered' ? TEXT.vatIncluded : TEXT.vatAlready;
			case 'ct':
				return sel.ct_status === 'registered' ? TEXT.ctAlready : TEXT.ctIncluded;
			case 'payroll':
				n = sel.employees === 'over_20' ? 'over_20' : 'up_to_20';
				return optionLabel(cfg.employees, n, n === 'over_20' ? 'More than 20 employees' : 'Up to 20 employees');
			case 'einvoicing':
				n = Math.max(0, parseInt(sel.transactions, 10) || 0);
				return optionLabel(cfg.transactions, n, fmtInt(n) + ' transactions a year');
			case 'account_manager':
				return TEXT.accountManager;
			default: // accounting, expense, invoicing, and any service a filter adds.
				return TEXT.annual;
		}
	}

	/* ------------------------------------------------------------------ */
	/* DOM helpers                                                         */
	/* ------------------------------------------------------------------ */

	function qs(el, sel) {
		return el.querySelector(sel);
	}

	function qsa(el, sel) {
		return Array.prototype.slice.call(el.querySelectorAll(sel));
	}

	function closest(el, sel) {
		while (el && el.nodeType === 1) {
			if (matches(el, sel)) {
				return el;
			}
			el = el.parentNode;
		}
		return null;
	}

	function matches(el, sel) {
		var fn = el.matches || el.msMatchesSelector || el.webkitMatchesSelector;
		return fn ? fn.call(el, sel) : false;
	}

	function make(tag, cls, text) {
		var el = doc.createElement(tag);
		if (cls) {
			el.className = cls;
		}
		if (text !== undefined && text !== null) {
			el.textContent = String(text);
		}
		return el;
	}

	function pad2(n) {
		return (n < 10 ? '0' : '') + n;
	}

	function reducedMotion() {
		return !!(win.matchMedia && win.matchMedia('(prefers-reduced-motion: reduce)').matches);
	}

	function isEditMode() {
		if (doc.body && doc.body.classList.contains('elementor-editor-active')) {
			return true;
		}
		try {
			return !!(win.elementorFrontend && win.elementorFrontend.isEditMode && win.elementorFrontend.isEditMode());
		} catch (e) {
			return false;
		}
	}

	/** Height of a fixed or sticky site header at the top of the viewport. */
	function headerOffset() {
		var max = 0;
		qsa(doc, '.elementor-location-header, .elementor-sticky--active, header.site-header, #wpadminbar').forEach(function (el) {
			var cs = win.getComputedStyle(el);
			if (cs.position !== 'fixed' && cs.position !== 'sticky') {
				return;
			}
			var r = el.getBoundingClientRect();
			if (r.top <= 1 && r.bottom > max && r.bottom < win.innerHeight / 2) {
				max = r.bottom;
			}
		});
		return max;
	}

	/** Length in characters (code points), like the server's mb_strlen(), not UTF-16 units. */
	function chars(s) {
		return String(s).replace(/[\uD800-\uDBFF][\uDC00-\uDFFF]/g, '_').length;
	}

	/** First word of the name, skipping a title such as "Dr" (same rule as LP_Calc_Mailer::first_name()). */
	function firstName(name) {
		var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
		var titles = ['mr', 'mrs', 'ms', 'miss', 'dr', 'eng', 'prof'];
		if (parts.length > 1 && titles.indexOf(parts[0].replace(/\.+$/, '').toLowerCase()) !== -1) {
			parts.shift();
		}
		return parts[0] || '';
	}

	/* ------------------------------------------------------------------ */
	/* Calculator                                                          */
	/* ------------------------------------------------------------------ */

	function Calculator(root) {
		this.root = root;
		this.cfg = this.readConfig();
		this.step = 1;
		this.form = null;
		this.widget = null;
		this.pending = null;
		this.resultShown = false;
		this.el = {
			card: qs(root, '[data-lp-calc-card]'),
			count: qs(root, '[data-lp-calc-count]'),
			progress: qs(root, '[data-lp-calc-progress]'),
			steps: qsa(root, '[data-lp-calc-step]'),
			panels: qsa(root, '[data-lp-calc-panel]'),
			back: qs(root, '[data-lp-calc-back]'),
			next: qs(root, '[data-lp-calc-next]'),
			actions: qs(root, '[data-lp-calc-actions]'),
			pkg: qs(root, '[data-lp-calc-package]'),
			slot: qs(root, '[data-lp-calc-form-slot]'),
			contact: qs(root, '[data-lp-calc-contact]'),
			result: qs(root, '[data-lp-calc-result]'),
			missing: qs(root, '[data-lp-calc-missing]'),
			editorNotice: qs(root, '[data-lp-calc-editor-notice]'),
			editMode: qs(root, '[data-lp-calc-editmode]'),
			mailto: qs(root, '[data-lp-calc-mailto]')
		};
		this.bind();
		this.sync();
		this.mountForm(false);
		root.setAttribute('data-lp-calc-ready', '1');
		root.classList.add('is-ready');
	}

	Calculator.prototype.readConfig = function () {
		var node = qs(this.root, 'script#lp-calc-config') || qs(this.root, 'script[type="application/json"]');
		try {
			return JSON.parse(node ? node.textContent : '{}') || {};
		} catch (e) {
			return {};
		}
	};

	/* ----- state (the controls are the source of truth) ----- */

	Calculator.prototype.inputs = function (name) {
		return qsa(this.root, '[data-lp-calc-input="' + name + '"]');
	};

	Calculator.prototype.checked = function (name) {
		var hit = this.inputs(name).filter(function (el) {
			return el.checked;
		});
		return hit.length ? hit[0].value : '';
	};

	Calculator.prototype.value = function (name) {
		var el = this.inputs(name)[0];
		return el ? el.value : '';
	};

	Calculator.prototype.state = function () {
		var services = this.inputs('services').filter(function (el) {
			return el.checked;
		}).map(function (el) {
			return el.value;
		});
		return {
			business: this.checked('business'),
			business_other: this.value('business_other'),
			transactions: parseInt(this.value('transactions'), 10) || 1000,
			vat_status: this.checked('vat_status'),
			ct_status: this.checked('ct_status'),
			employees: this.checked('employees') || 'up_to_20',
			reports: parseInt(this.value('reports'), 10) || 4,
			services: services
		};
	};

	/* ----- events ----- */

	Calculator.prototype.bind = function () {
		var self = this;
		this.root.addEventListener('change', function (e) {
			self.onInput(e);
		});
		this.root.addEventListener('input', function (e) {
			self.onInput(e);
		});
		this.root.addEventListener('keydown', function (e) {
			// Enter in a step control moves on, like a form would.
			if (e.key !== 'Enter' || !e.target.getAttribute || !e.target.getAttribute('data-lp-calc-input')) {
				return;
			}
			if (e.target.tagName === 'SELECT') {
				return;
			}
			e.preventDefault();
			self.next();
		});
		if (this.el.next) {
			this.el.next.addEventListener('click', function () {
				self.next();
			});
		}
		if (this.el.back) {
			this.el.back.addEventListener('click', function () {
				self.go(self.step - 1, true);
			});
		}
		this.root.addEventListener('click', function (e) {
			var again = closest(e.target, '[data-lp-calc-restart]');
			if (again) {
				e.preventDefault();
				self.restart();
			}
		});
	};

	Calculator.prototype.onInput = function (e) {
		var name = e.target && e.target.getAttribute ? e.target.getAttribute('data-lp-calc-input') : null;
		if (!name) {
			return;
		}
		this.clearError(name);
		if (name === 'business') {
			this.clearError('business_other');
		}
		this.sync();
	};

	/* ----- rendering ----- */

	Calculator.prototype.sync = function () {
		var s = this.state();
		this.reveal('business_other', s.business === 'Other');
		this.reveal('employees', s.services.indexOf('payroll') !== -1);
		this.reveal('reports', s.services.indexOf('reports') !== -1);
		qsa(this.root, '[data-lp-calc-service]').forEach(function (card) {
			card.classList.toggle('is-checked', s.services.indexOf(card.getAttribute('data-lp-calc-service')) !== -1);
		});
		var lines = packageLines(this.cfg, s);
		this.renderPackage(lines);
		this.writeHidden(s);
		this.root.classList.toggle('has-selection', lines.length > 0);
	};

	Calculator.prototype.reveal = function (name, show) {
		qsa(this.root, '[data-lp-calc-reveal="' + name + '"]').forEach(function (el) {
			el.hidden = !show;
		});
	};

	/** Step 5 "Your package": the selected services with a short detail each, no amounts. */
	Calculator.prototype.renderPackage = function (lines) {
		var ul = this.el.pkg;
		if (ul) {
			ul.textContent = '';
			if (!lines.length) {
				ul.appendChild(make('li', 'lp-calc__line lp-calc__line--empty', TEXT.noServices));
			}
			lines.forEach(function (line) {
				ul.appendChild(lineItem(line.label, line.detail, null));
			});
		}
		if (this.el.mailto) {
			var body = lines.map(function (l) {
				return '- ' + l.label + ': ' + l.detail;
			}).join('\n');
			this.el.mailto.href = 'mailto:info@ledgerplus.ae?subject=' + encodeURIComponent('Package quotation request') +
				'&body=' + encodeURIComponent('Hello Ledger Plus,\n\nPlease send me a quotation for:\n' + body + '\n');
		}
	};

	/**
	 * A line of a list: label, detail and, on the result screen only, the server's amount. A detail
	 * that only repeats the amount ("Included") is left out, as in LP_Calc_Quote_PDF::detail().
	 */
	function lineItem(label, detail, amount) {
		var hasAmount = amount !== null && amount !== undefined;
		if (hasAmount && detail && String(detail).toLowerCase() === String(amount).toLowerCase()) {
			detail = '';
		}
		var li = make('li', 'lp-calc__line');
		var text = make('span', 'lp-calc__line-text');
		text.appendChild(make('span', 'lp-calc__line-label', label));
		if (detail) {
			text.appendChild(make('span', 'lp-calc__line-detail', detail));
		}
		li.appendChild(text);
		if (hasAmount) {
			li.appendChild(make('span', 'lp-calc__line-amount', amount));
		}
		return li;
	}

	/* ----- hidden fields ----- */

	Calculator.prototype.hiddenValues = function (s) {
		s = s || this.state();
		return {
			lpc_version: '1',
			lpc_business: s.business,
			lpc_business_other: s.business === 'Other' ? String(s.business_other).trim() : '',
			lpc_transactions: String(s.transactions),
			lpc_vat_status: s.vat_status,
			lpc_ct_status: s.ct_status,
			lpc_employees: s.employees,
			lpc_reports: String(s.reports),
			lpc_services: s.services.join(',')
		};
	};

	/** Writes the selection into the hidden lpc_* fields and empties the figure fields. */
	Calculator.prototype.writeHidden = function (s) {
		if (!this.form) {
			return;
		}
		var values = this.hiddenValues(s);
		var form = this.form;
		HIDDEN_KEYS.forEach(function (key) {
			var input = form.querySelector('[name="form_fields[' + key + ']"]');
			if (input && input.value !== values[key]) {
				input.value = values[key];
			}
		});
		FIGURE_KEYS.forEach(function (key) {
			var input = form.querySelector('[name="form_fields[' + key + ']"]');
			if (input && input.value !== '') {
				input.value = '';
			}
		});
	};

	/* ----- navigation ----- */

	Calculator.prototype.next = function () {
		if (this.step >= STEPS) {
			return;
		}
		var errors = this.validateStep(this.step);
		if (errors.length) {
			this.showErrors(errors);
			return;
		}
		this.go(this.step + 1, true);
	};

	Calculator.prototype.go = function (step, userAction) {
		step = Math.max(1, Math.min(STEPS, step));
		var self = this;
		this.step = step;
		this.el.panels.forEach(function (panel) {
			var on = parseInt(panel.getAttribute('data-lp-calc-panel'), 10) === step;
			panel.hidden = !on;
			panel.classList.toggle('is-entering', on && userAction && !reducedMotion());
		});
		this.root.setAttribute('data-step', String(step));
		if (this.el.count) {
			this.el.count.textContent = TEXT.step + ' ' + pad2(step) + ' / ' + pad2(STEPS);
		}
		if (this.el.progress) {
			this.el.progress.style.width = (step / STEPS * 100) + '%';
		}
		this.el.steps.forEach(function (li) {
			var n = parseInt(li.getAttribute('data-lp-calc-step'), 10);
			li.classList.toggle('is-active', n === step);
			li.classList.toggle('is-done', n < step);
			if (n === step) {
				li.setAttribute('aria-current', 'step');
			} else {
				li.removeAttribute('aria-current');
			}
		});
		if (this.el.back) {
			this.el.back.hidden = step === 1;
		}
		if (this.el.next) {
			this.el.next.hidden = step === STEPS;
		}
		if (step === STEPS) {
			this.mountForm(true);
			this.writeHidden();
			this.fixTelPadding();
		}
		if (userAction) {
			var panel = this.panel(step);
			var heading = panel && qs(panel, '.lp-calc__title');
			if (heading) {
				try {
					heading.focus({ preventScroll: true });
				} catch (e) {
					heading.focus();
				}
			}
			this.scrollIntoView();
			win.setTimeout(function () {
				self.el.panels.forEach(function (p) {
					p.classList.remove('is-entering');
				});
			}, 400);
		}
	};

	Calculator.prototype.panel = function (step) {
		return qs(this.root, '[data-lp-calc-panel="' + step + '"]');
	};

	Calculator.prototype.scrollIntoView = function () {
		var target = this.root;
		var offset = headerOffset() + 16;
		var rect = target.getBoundingClientRect();
		if (rect.top >= offset - 1 && rect.top < win.innerHeight * 0.5) {
			return; // Already in view near the top.
		}
		var y = Math.max(0, (win.pageYOffset || doc.documentElement.scrollTop) + rect.top - offset);
		try {
			win.scrollTo({ top: y, behavior: reducedMotion() ? 'auto' : 'smooth' });
		} catch (e) {
			win.scrollTo(0, y);
		}
	};

	/* ----- validation (steps 1-4) ----- */

	Calculator.prototype.validateStep = function (step) {
		var s = this.state();
		var errors = [];
		if (step === 1) {
			if (!s.business) {
				errors.push({ name: 'business', message: TEXT.errBusiness });
			} else if (s.business === 'Other') {
				var other = String(s.business_other).trim();
				if (chars(other) < 1 || chars(other) > 80) {
					errors.push({ name: 'business_other', message: TEXT.errBusinessOther });
				} else if (LINK_RE.test(other)) {
					errors.push({ name: 'business_other', message: TEXT.errBusinessOtherLink });
				}
			}
		} else if (step === 3) {
			if (!s.vat_status) {
				errors.push({ name: 'vat_status', message: TEXT.errVat });
			}
			if (!s.ct_status) {
				errors.push({ name: 'ct_status', message: TEXT.errCt });
			}
		} else if (step === 4) {
			if (!s.services.length) {
				errors.push({ name: 'services', message: TEXT.errServices });
			}
		}
		return errors;
	};

	Calculator.prototype.showErrors = function (errors) {
		var self = this;
		errors.forEach(function (err) {
			var box = qs(self.root, '[data-lp-calc-error="' + err.name + '"]');
			var field = qs(self.root, '[data-lp-calc-field="' + err.name + '"]');
			if (box) {
				box.textContent = err.message;
				box.hidden = false;
			}
			if (field) {
				field.classList.add('is-invalid');
			}
			self.inputs(err.name).forEach(function (input) {
				input.setAttribute('aria-invalid', 'true');
			});
		});
		var first = errors[0] && this.inputs(errors[0].name)[0];
		var firstField = errors[0] && qs(this.root, '[data-lp-calc-field="' + errors[0].name + '"]');
		if (first) {
			try {
				first.focus({ preventScroll: true });
			} catch (e) {
				first.focus();
			}
		}
		if (firstField) {
			this.scrollToField(firstField);
		}
	};

	/**
	 * Scrolls so the top of a field (its legend or label, and the message under it) is in view,
	 * below a sticky site header.
	 */
	Calculator.prototype.scrollToField = function (el) {
		var top = headerOffset() + 16;
		var bottom = win.innerHeight - 16;
		var rect = el.getBoundingClientRect();
		if (rect.top >= top && rect.top + Math.min(rect.height, 140) <= bottom) {
			return;
		}
		var y = Math.max(0, (win.pageYOffset || doc.documentElement.scrollTop) + rect.top - top);
		try {
			win.scrollTo({ top: y, behavior: reducedMotion() ? 'auto' : 'smooth' });
		} catch (e) {
			win.scrollTo(0, y);
		}
	};

	Calculator.prototype.clearError = function (name) {
		var box = qs(this.root, '[data-lp-calc-error="' + name + '"]');
		var field = qs(this.root, '[data-lp-calc-field="' + name + '"]');
		if (box && !box.hidden) {
			box.hidden = true;
			box.textContent = '';
		}
		if (field) {
			field.classList.remove('is-invalid');
		}
		this.inputs(name).forEach(function (input) {
			input.removeAttribute('aria-invalid');
		});
	};

	/* ----- the Elementor form ----- */

	Calculator.prototype.mountForm = function (atStep5) {
		if (this.form) {
			return true;
		}
		if (isEditMode()) {
			if (this.el.editMode) {
				this.el.editMode.hidden = false;
			}
			return false;
		}
		var widget = doc.querySelector('.lp-calc-form');
		var form = widget ? (widget.tagName === 'FORM' ? widget : qs(widget, 'form')) : null;
		if (!widget || !form) {
			if (this.el.missing) {
				this.el.missing.hidden = false;
			}
			if (this.el.editorNotice) {
				this.el.editorNotice.hidden = false;
			}
			if (atStep5 === false) {
				this.retryMount();
			}
			return false;
		}
		if (this.el.missing) {
			this.el.missing.hidden = true;
		}
		if (this.el.editorNotice) {
			this.el.editorNotice.hidden = true;
		}
		this.widget = widget;
		this.form = form;
		if (this.el.slot && widget.parentNode !== this.el.slot) {
			this.el.slot.appendChild(widget);
		}
		widget.classList.add('lp-calc-form--mounted');
		// Our own inline messages replace the browser bubbles (the tel pattern also rejects spaces).
		form.noValidate = true;
		form.setAttribute('novalidate', 'novalidate');
		this.bindForm();
		this.writeHidden();
		return true;
	};

	Calculator.prototype.retryMount = function () {
		var self = this;
		if (this.retrying) {
			return;
		}
		this.retrying = true;
		win.addEventListener('load', function () {
			self.mountForm(true);
		});
	};

	/**
	 * The country-code plugin (intl-tel-input with a separate dial code) sets the phone input's
	 * left padding from the dial-code box width. Measured while step 5 is hidden, that width is
	 * wrong, so the number runs into the box. Re-measure once the form is visible.
	 */
	Calculator.prototype.fixTelPadding = function () {
		if (!this.form) {
			return;
		}
		qsa(this.form, '.iti').forEach(function (iti) {
			var box = qs(iti, '.iti__flag-container, .iti__country-container');
			var input = qs(iti, 'input[type="tel"]');
			if (!box || !input) {
				return;
			}
			var width = box.getBoundingClientRect().width;
			var current = parseFloat(win.getComputedStyle(input).paddingLeft) || 0;
			if (width > 40 && current < width + 8) {
				input.style.paddingLeft = Math.ceil(width + 12) + 'px';
			}
		});
	};

	Calculator.prototype.formField = function (id) {
		return this.form ? this.form.querySelector('[name="form_fields[' + id + ']"]') : null;
	};

	Calculator.prototype.bindForm = function () {
		var self = this;
		var form = this.form;
		var capture = this.el.slot || this.root;

		// Capture phase on an ancestor: runs before Elementor's own submit handler on the form.
		capture.addEventListener('submit', function (e) {
			if (e.target !== form) {
				return;
			}
			self.writeHidden();
			var bad = self.firstInvalidStep();
			if (bad) {
				e.preventDefault();
				e.stopPropagation();
				self.go(bad.step, true);
				self.showErrors(bad.errors);
				return;
			}
			var errors = self.validateContact();
			if (errors.length) {
				e.preventDefault();
				e.stopPropagation();
				self.showContactErrors(errors);
				return;
			}
			// Elementor Pro's Tel field checks the value on the server against [0-9()#&+*-=.]+,
			// which has no space: send "050 123 4567" as "0501234567".
			var phone = self.formField('phone');
			if (phone && /\s/.test(phone.value)) {
				phone.value = phone.value.replace(/\s+/g, '');
			}
			self.errorPending = false;
			var name = self.formField('name');
			var email = self.formField('email');
			self.pending = {
				name: name ? name.value : '',
				email: email ? email.value.trim() : ''
			};
		}, true);

		form.addEventListener('input', function (e) {
			self.clearContactError(e.target);
		});
		form.addEventListener('change', function (e) {
			self.clearContactError(e.target);
		});

		var $ = win.jQuery;
		if ($) {
			$(form).on('submit_success', function (event, data) {
				self.onSuccess(data);
			});
			// Elementor triggers "error" before it appends the form message; ajaxComplete (below)
			// runs right after and handles the response. This is the fallback if it does not.
			$(form).on('error', function () {
				self.errorPending = true;
				win.setTimeout(function () {
					if (self.errorPending) {
						self.errorPending = false;
						self.onServerError();
					}
				}, 0);
			});
			$(doc).ajaxComplete(function (event, xhr, settings) {
				if (!self.isOurRequest(settings)) {
					return;
				}
				self.errorPending = false;
				var json = xhr && xhr.responseJSON;
				if (!json && xhr && xhr.responseText) {
					try {
						json = JSON.parse(xhr.responseText);
					} catch (err) {
						json = null;
					}
				}
				if (json && json.success) {
					self.onSuccess(json.data);
					return;
				}
				var quote = json && json.data && json.data.data && json.data.data.lp_quote;
				if (quote && quote.email_sent) {
					// A later action (Zoho, the team email) failed after the client was emailed: a
					// retry would only send a second lead and email, so show the result.
					self.onEmailedDespiteError(json.data);
					return;
				}
				self.onServerError();
			});
		}
	};

	/**
	 * After Elementor rendered a failed response: put field errors where they can be read and
	 * bring the first one (or the form message) into view.
	 */
	Calculator.prototype.onServerError = function () {
		var form = this.form;
		if (!form || this.resultShown) {
			return;
		}
		// Elementor appends a field's error to the input's parent. For the phone that is the
		// intl-tel-input wrapper, where the flag box covers it: move it below the wrapper.
		qsa(form, '.iti').forEach(function (iti) {
			var group = closest(iti, '.elementor-field-group');
			Array.prototype.slice.call(iti.children).forEach(function (child) {
				if (child.classList && child.classList.contains('elementor-help-inline')) {
					iti.parentNode.insertBefore(child, iti.nextSibling);
				}
			});
			if (iti.classList.contains('elementor-error')) {
				iti.classList.remove('elementor-error');
				if (group) {
					group.classList.add('elementor-error');
				}
			}
		});
		var input = null;
		qsa(form, '.elementor-field-group.elementor-error').some(function (group) {
			var el = qs(group, 'input:not([type="hidden"]), textarea, select');
			if (el && el.getClientRects().length) {
				input = el;
			}
			return !!input;
		});
		var messages = qsa(form, '.elementor-message').filter(function (m) {
			return m.parentNode === form;
		});
		var target = input || messages[messages.length - 1];
		if (!target) {
			return;
		}
		if (!input) {
			target.setAttribute('tabindex', '-1');
		}
		try {
			target.focus({ preventScroll: true });
		} catch (e) {
			target.focus();
		}
		try {
			target.scrollIntoView({ block: 'center', behavior: reducedMotion() ? 'auto' : 'smooth' });
		} catch (e) {
			target.scrollIntoView(false);
		}
	};

	/**
	 * The response failed, but the plugin had already emailed the quotation (lp_quote.email_sent):
	 * drop Elementor's error and show the result like a success.
	 *
	 * @param {Object} data The response's data: {message, errors, data: {lp_quote}}.
	 */
	Calculator.prototype.onEmailedDespiteError = function (data) {
		var form = this.form;
		if (form) {
			qsa(form, '.elementor-message').forEach(function (m) {
				m.parentNode.removeChild(m);
			});
			qsa(form, '.elementor-error').forEach(function (g) {
				g.classList.remove('elementor-error');
			});
			qsa(form, '[aria-invalid="true"]').forEach(function (el) {
				el.removeAttribute('aria-invalid');
			});
		}
		this.onSuccess(data);
	};

	Calculator.prototype.isOurRequest = function (settings) {
		if (!settings || !this.form) {
			return false;
		}
		var data = settings.data;
		var idInput = this.form.querySelector('[name="form_id"]');
		var formId = idInput ? idInput.value : '';
		if (win.FormData && data instanceof win.FormData && data.get) {
			return data.get('action') === FORM_ACTION && (!formId || data.get('form_id') === formId);
		}
		if (typeof data === 'string') {
			return data.indexOf('action=' + FORM_ACTION) !== -1 && (!formId || data.indexOf('form_id=' + encodeURIComponent(formId)) !== -1);
		}
		return false;
	};

	Calculator.prototype.firstInvalidStep = function () {
		for (var step = 1; step < STEPS; step++) {
			var errors = this.validateStep(step);
			if (errors.length) {
				return { step: step, errors: errors };
			}
		}
		return null;
	};

	Calculator.prototype.validateContact = function () {
		var errors = [];
		var check = function (input, ok, message, required) {
			if (!input) {
				return;
			}
			var v = String(input.value || '').trim();
			if (v === '' && required) {
				errors.push({ input: input, message: message || TEXT.errRequired });
			} else if (v !== '' && !ok(v)) {
				errors.push({ input: input, message: message });
			}
		};
		var noLink = function (v) {
			return !LINK_RE.test(v);
		};
		// Same rules as LP_Calc_Elementor::check_contact(); name and company go into the PDF.
		var name = this.formField('name');
		var company = this.formField('company');
		var before = errors.length;
		check(name, function (v) {
			return chars(v) >= 2 && chars(v) <= 80;
		}, TEXT.errName, true);
		if (errors.length === before) {
			check(name, noLink, TEXT.errNameLink, false);
		}
		before = errors.length;
		check(company, function (v) {
			return chars(v) >= 2 && chars(v) <= 120;
		}, TEXT.errCompany, true);
		if (errors.length === before) {
			check(company, noLink, TEXT.errCompanyLink, false);
		}
		check(this.formField('email'), function (v) {
			return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v) && v.length <= 254;
		}, TEXT.errEmail, true);
		check(this.formField('phone'), function (v) {
			// Same rule as the server: 6-25 of 0-9 + ( ) - . and spaces, with at least 6 digits.
			return /^[0-9+()\-. ]{6,25}$/.test(v) && (v.match(/[0-9]/g) || []).length >= 6;
		}, TEXT.errPhone, true);
		check(this.formField('message'), function (v) {
			return chars(v) <= 1000;
		}, TEXT.errMessage, false);
		return errors;
	};

	Calculator.prototype.showContactErrors = function (errors) {
		var self = this;
		errors.forEach(function (err) {
			self.clearContactError(err.input);
			var group = closest(err.input, '.elementor-field-group') || err.input.parentNode;
			var msg = make('span', 'elementor-message elementor-message-danger elementor-help-inline elementor-form-help-inline lp-calc__help-inline', err.message);
			msg.setAttribute('role', 'alert');
			msg.id = (err.input.id || 'lp-calc-field') + '-lp-error';
			group.classList.add('elementor-error');
			group.appendChild(msg);
			err.input.setAttribute('aria-invalid', 'true');
			var described = (err.input.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
			if (described.indexOf(msg.id) === -1) {
				described.push(msg.id);
				err.input.setAttribute('aria-describedby', described.join(' '));
			}
		});
		if (errors[0]) {
			errors[0].input.focus();
		}
	};

	Calculator.prototype.clearContactError = function (input) {
		if (!input || !input.name || input.name.indexOf('form_fields[') !== 0) {
			return;
		}
		var group = closest(input, '.elementor-field-group');
		if (!group) {
			return;
		}
		qsa(group, '.elementor-help-inline').forEach(function (m) {
			m.parentNode.removeChild(m);
		});
		group.classList.remove('elementor-error');
		qsa(group, '.elementor-error').forEach(function (el) {
			el.classList.remove('elementor-error');
		});
		input.removeAttribute('aria-invalid');
		var id = (input.id || 'lp-calc-field') + '-lp-error';
		var described = (input.getAttribute('aria-describedby') || '').split(/\s+/).filter(function (x) {
			return x && x !== id;
		});
		if (described.length) {
			input.setAttribute('aria-describedby', described.join(' '));
		} else {
			input.removeAttribute('aria-describedby');
		}
	};

	/* ----- success ----- */

	Calculator.prototype.onSuccess = function (data) {
		if (this.resultShown) {
			return;
		}
		this.resultShown = true;
		var quote = data && data.data && data.data.lp_quote ? data.data.lp_quote : null;
		this.showResult(quote);
	};

	/**
	 * The result screen. The price shows here for the first time, from the server's lp_quote. Without
	 * server lines (an unexpected server path) it shows no figures: only the wording and the package.
	 *
	 * @param {Object|null} quote lp_quote: {ref, lines: [{key, label, detail, amount}], total, email, email_sent}.
	 */
	Calculator.prototype.showResult = function (quote) {
		var cfg = this.cfg;
		var pending = this.pending || {};
		var name = firstName(pending.name);
		var email = (quote && quote.email) || pending.email || '';
		var box = this.el.result;
		if (!box) {
			return;
		}
		box.textContent = '';

		var head = make('div', 'lp-calc__result-head');
		var icon = make('span', 'lp-calc__result-icon');
		icon.setAttribute('aria-hidden', 'true');
		icon.innerHTML = '<svg viewBox="0 0 24 24" width="26" height="26" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
		head.appendChild(icon);

		var title = make('h2', 'lp-calc__title lp-calc__result-title');
		title.setAttribute('tabindex', '-1');
		title.textContent = name ? 'Thank you, ' + name + '.' : 'Thank you.';
		head.appendChild(title);
		box.appendChild(head);

		var lead = make('p', 'lp-calc__result-lead');
		var ref = quote && quote.ref ? String(quote.ref) : '';
		if (quote && quote.email_sent !== false) {
			appendText(lead, 'Your quotation ');
			if (ref) {
				lead.appendChild(make('strong', 'lp-calc__ref', ref));
				appendText(lead, ' ');
			}
			appendText(lead, 'is on its way to ');
			lead.appendChild(make('strong', '', email || 'your inbox'));
			appendText(lead, '.');
		} else if (quote) {
			appendText(lead, 'We have received your request');
			if (ref) {
				appendText(lead, ' ');
				lead.appendChild(make('strong', 'lp-calc__ref', ref));
			}
			appendText(lead, '. We could not email your quotation just now, so a Ledger Plus advisor will send it to ');
			lead.appendChild(make('strong', '', email || 'you'));
			appendText(lead, ' shortly.');
		} else {
			appendText(lead, 'We have received your request. A Ledger Plus advisor will email your quotation to ');
			lead.appendChild(make('strong', '', email || 'you'));
			appendText(lead, ' shortly.');
		}
		box.appendChild(lead);

		var summary = make('div', 'lp-calc__summary lp-calc__summary--result');
		var ul;
		if (quote && quote.lines && quote.lines.length) {
			// The server's figures: the total first, then the lines.
			var heading = make('p', 'lp-calc__summary-title', TEXT.yourQuote);
			if (ref) {
				appendText(heading, ' ');
				heading.appendChild(make('span', 'lp-calc__ref', ref));
			}
			summary.appendChild(heading);
			var totalRow = make('div', 'lp-calc__summary-total lp-calc__summary-total--strong');
			totalRow.appendChild(make('span', 'lp-calc__summary-total-label', cfg.total_label || 'Estimated annual total (incl. 5% VAT)'));
			totalRow.appendChild(make('strong', 'lp-calc__summary-total-amount', String(quote.total || '')));
			summary.appendChild(totalRow);
			ul = make('ul', 'lp-calc__lines lp-calc__lines--summary');
			quote.lines.forEach(function (l) {
				ul.appendChild(lineItem(String(l.label), l.detail ? String(l.detail) : '', String(l.amount)));
			});
		} else {
			// No server figures: the selection only, never a price worked out in the browser.
			summary.appendChild(make('p', 'lp-calc__summary-title', TEXT.yourPackage));
			ul = make('ul', 'lp-calc__lines lp-calc__lines--package');
			packageLines(cfg, this.state()).forEach(function (l) {
				ul.appendChild(lineItem(l.label, l.detail, null));
			});
		}
		summary.appendChild(ul);
		box.appendChild(summary);

		var nextBox = make('div', 'lp-calc__next');
		nextBox.appendChild(make('p', 'lp-calc__next-title', TEXT.next));
		nextBox.appendChild(make('p', 'lp-calc__next-text', TEXT.nextText));
		box.appendChild(nextBox);

		var again = make('button', 'lp-calc__btn lp-calc__btn--secondary lp-calc__restart', TEXT.startAgain);
		again.type = 'button';
		again.setAttribute('data-lp-calc-restart', '');
		box.appendChild(again);

		if (this.el.contact) {
			this.el.contact.hidden = true;
		}
		box.hidden = false;
		this.root.classList.add('is-complete');
		if (this.el.count) {
			this.el.count.textContent = quote && quote.email_sent !== false ? TEXT.done : TEXT.received;
		}
		if (this.el.progress) {
			this.el.progress.style.width = '100%';
		}
		this.el.steps.forEach(function (li) {
			li.classList.remove('is-active');
			li.classList.add('is-done');
			li.removeAttribute('aria-current');
		});
		try {
			title.focus({ preventScroll: true });
		} catch (e) {
			title.focus();
		}
		this.scrollIntoView();
	};

	function appendText(el, text) {
		el.appendChild(doc.createTextNode(text));
	}

	Calculator.prototype.restart = function () {
		var self = this;
		var defaults = (this.cfg && this.cfg.defaults) || {};
		var defServices = defaults.services || ['einvoicing'];
		this.inputs('business').forEach(function (el) {
			el.checked = false;
		});
		this.inputs('business_other').forEach(function (el) {
			el.value = '';
		});
		this.inputs('vat_status').concat(this.inputs('ct_status')).forEach(function (el) {
			el.checked = false;
		});
		this.inputs('services').forEach(function (el) {
			el.checked = defServices.indexOf(el.value) !== -1;
		});
		this.inputs('employees').forEach(function (el) {
			el.checked = el.value === String(defaults.employees || 'up_to_20');
		});
		this.inputs('transactions').forEach(function (el) {
			el.value = String(defaults.transactions || 1000);
		});
		this.inputs('reports').forEach(function (el) {
			el.value = String(defaults.reports || 4);
		});
		['business', 'business_other', 'vat_status', 'ct_status', 'services'].forEach(function (n) {
			self.clearError(n);
		});
		if (this.form) {
			qsa(this.form, '.elementor-message').forEach(function (m) {
				m.parentNode.removeChild(m);
			});
			qsa(this.form, '.elementor-error').forEach(function (g) {
				g.classList.remove('elementor-error');
			});
		}
		if (this.el.result) {
			this.el.result.hidden = true;
			this.el.result.textContent = '';
		}
		if (this.el.contact) {
			this.el.contact.hidden = false;
		}
		this.root.classList.remove('is-complete');
		this.resultShown = false;
		this.pending = null;
		this.sync();
		this.go(1, true);
	};

	/* ------------------------------------------------------------------ */
	/* Boot                                                                */
	/* ------------------------------------------------------------------ */

	function init(scope) {
		qsa(scope || doc, '[data-lp-calc]').forEach(function (root) {
			if (!root.lpCalc) {
				root.lpCalc = new Calculator(root);
			}
		});
	}

	function boot() {
		if (doc.readyState === 'loading') {
			doc.addEventListener('DOMContentLoaded', function () {
				init();
			});
		} else {
			init();
		}
		// Elementor editor preview re-renders the shortcode widget without a page load.
		if (win.jQuery) {
			win.jQuery(win).on('elementor/frontend/init', function () {
				try {
					win.elementorFrontend.hooks.addAction('frontend/element_ready/shortcode.default', function ($scope) {
						init($scope && $scope[0] ? $scope[0] : doc);
					});
				} catch (e) { /* not Elementor */ }
			});
		}
	}

	return {
		packageLines: packageLines,
		fmtInt: fmtInt,
		boot: boot,
		init: init,
		Calculator: Calculator
	};
}));
