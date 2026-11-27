/**
 * Screenshots of the client email HTML (test/out/email-*.html) and layout checks:
 * - at 600, 375 and 320 px wide: no horizontal overflow, 180px logo, buttons at least 44px tall;
 * - at 360 and 320 px with the <style> block removed (the Gmail app drops it for non-Google
 *   accounts, so only the inline styles apply): no horizontal overflow;
 * - Outlook on Windows (Word engine) ignores padding and display:block on <a>: with those removed
 *   and each button cell's mso-padding-alt applied, the buttons are still at least 44px tall.
 * The logo URL is served from the local test logo.
 * Usage: node shot-email.js [out-dir]
 */
const path = require('path');
const fs = require('fs');
let playwright;
try {
	playwright = require('playwright');
} catch (e) {
	console.log('  NOTE  playwright not found: email screenshots skipped');
	process.exit(0);
}

const out = path.resolve(process.argv[2] || path.join(__dirname, '..', 'out'));
const logo = '/tmp/claude-0/-home-user-Mosaic-Digital/11279077-d7c0-513e-8725-04117a99ad61/scratchpad/shop/email/assets/logo-placeholder-622x210.png';

async function measure(page) {
	return page.evaluate(() => {
		const doc = document.documentElement;
		const logoImg = document.querySelector('img[alt="Ledger Plus"]');
		return {
			overflow: doc.scrollWidth - doc.clientWidth,
			logo: logoImg ? Math.round(logoImg.getBoundingClientRect().width) : 0,
			logoLoaded: logoImg ? logoImg.naturalWidth > 0 : false,
			buttons: Array.from(document.querySelectorAll('a')).filter((a) => /Reply to this email|WhatsApp us/.test(a.textContent)).map((a) => Math.round(a.getBoundingClientRect().height)),
		};
	});
}

/** Outlook's Word engine: no padding or display:block on <a>; the cell's mso-padding-alt applies. */
async function outlookButtons(page) {
	return page.evaluate(() => {
		const links = Array.from(document.querySelectorAll('a')).filter((a) => /Reply to this email|WhatsApp us/.test(a.textContent));
		return links.map((a) => {
			const td = a.closest('td');
			const alt = /mso-padding-alt:\s*([^;"]+)/.exec(td.getAttribute('style') || '');
			a.style.padding = '0';
			a.style.display = 'inline';
			td.style.padding = alt ? alt[1] : '0';
			return Math.round(td.getBoundingClientRect().height);
		});
	});
}

(async () => {
	const files = fs.readdirSync(out).filter((f) => /^email-[a-z]+\.html$/.test(f)).sort();
	if (!files.length) {
		console.log('  FAIL  no email-*.html in ' + out + ' (run make-samples.php first)');
		process.exit(1);
	}
	const browser = await playwright.chromium.launch();
	let fail = 0;
	const report = (ok, msg) => {
		if (!ok) fail++;
		console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${msg}`);
	};
	const route = (page) => page.route('**/*', (r) => {
		const url = r.request().url();
		if (/\.(png|jpe?g)$/i.test(url) && fs.existsSync(logo)) {
			return r.fulfill({ path: logo, contentType: 'image/png' });
		}
		if (url.startsWith('file:') || url.startsWith('about:')) {
			return r.continue();
		}
		return r.abort();
	});

	for (const width of [600, 375, 320]) {
		const page = await browser.newPage({ viewport: { width, height: 900 }, deviceScaleFactor: 1 });
		await route(page);
		for (const file of files) {
			const name = file.replace(/\.html$/, '');
			await page.goto('file://' + path.join(out, file));
			await page.waitForLoadState('load');
			const m = await measure(page);
			const shot = path.join(out, `${name}-${width}.png`);
			await page.screenshot({ path: shot, fullPage: true });
			report(m.overflow <= 0 && m.logo === 180 && m.logoLoaded && m.buttons.length === 2 && m.buttons.every((h) => h >= 44),
				`${name} @${width}: overflow ${m.overflow}px, logo ${m.logo}px, buttons ${m.buttons.join('/')}px -> ${path.basename(shot)}`);
			if (width === 600) {
				const mso = await outlookButtons(page);
				report(mso.length === 2 && mso.every((h) => h >= 44), `${name} @600 Outlook (no <a> padding, mso-padding-alt on the cell): buttons ${mso.join('/')}px`);
			}
		}
		await page.close();
	}

	for (const width of [360, 320]) {
		const page = await browser.newPage({ viewport: { width, height: 900 }, deviceScaleFactor: 1 });
		await route(page);
		for (const file of files) {
			const name = file.replace(/\.html$/, '');
			const html = fs.readFileSync(path.join(out, file), 'utf8').replace(/<style>[\s\S]*?<\/style>/, '');
			await page.setContent(html, { waitUntil: 'load' });
			const m = await measure(page);
			const shot = path.join(out, `${name}-nomq-${width}.png`);
			await page.screenshot({ path: shot, fullPage: true });
			report(m.overflow <= 0 && m.buttons.every((h) => h >= 44), `${name} @${width} without <style>: overflow ${m.overflow}px, buttons ${m.buttons.join('/')}px -> ${path.basename(shot)}`);
		}
		await page.close();
	}
	await browser.close();
	process.exit(fail ? 1 : 0);
})().catch((e) => {
	console.error(e);
	process.exit(1);
});
