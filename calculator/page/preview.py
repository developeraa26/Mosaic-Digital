"""Local preview of the calculator page built by build_page.py (no WordPress needed).

Renders out/page.json the way Elementor 4 prints it (optimized markup, container CSS variables, native
typography controls, Elementor Pro Custom CSS with `selector` replaced), under the live site's header and
real CSS, and screenshots it with Playwright (Chromium).

  python3 preview.py [faithful-dir]

Writes, under calculator/page/out/: preview-placeholder.html, preview-real.html, team-email.html.
Screenshots, under calculator/test/out/:
  page-hero-<w>.png          hero + an empty card placeholder (1440, 1024, 768, 390, 360)
  page-hero-real-<w>.png     hero + the real calculator template rendered by PHP (static, no JS)
  page-team-email-<w>.png    the team email (email_content) with sample values (640, 375)
The live site's CSS, DM Sans files and header come from calculator/test/ui/fixtures/site (copies of
ledgerplus.ae). If a "faithful" capture directory is passed (or the session default exists), its
head-inline.html (the live <head> inline styles) is added too.
"""
import html as H, json, os, re, subprocess, sys

HERE = os.path.dirname(os.path.abspath(__file__))
CALC = os.path.dirname(HERE)
OUT = os.path.join(HERE, "out")
SHOTS = os.path.join(CALC, "test", "out")
SITE = os.path.join(CALC, "test", "ui", "fixtures", "site")
FONTS = os.path.join(SITE, "fonts")
FAITH = sys.argv[1] if len(sys.argv) > 1 else \
    "/tmp/claude-0/-home-user-Mosaic-Digital/11279077-d7c0-513e-8725-04117a99ad61/scratchpad/faithful"
HEAD_INLINE = os.path.join(FAITH, "head-inline.html")
PID = 9101  # stand-in page id
os.makedirs(SHOTS, exist_ok=True)

body = json.load(open(os.path.join(OUT, "page.json")))
DATA = body["data"]
BP = {"": None, "_tablet": 1024, "_mobile": 767}


def walk(e):
    yield e
    for c in e.get("elements", []):
        yield from walk(c)


def sel(e):
    return ".elementor-%d .elementor-element.elementor-element-%s" % (PID, e["id"])


def unit(v):
    return "%s%s" % (v["size"], v["unit"])


def container_css(e):
    s, x = e["settings"], sel(e)
    out = {k: [] for k in BP}
    for sfx in BP:
        p = s.get("padding" + sfx)
        if p:
            out[sfx].append("--padding-top:{top}{unit};--padding-bottom:{bottom}{unit};--padding-left:{left}{unit};--padding-right:{right}{unit}".format(**p))
        m = s.get("margin" + sfx)
        if m:
            out[sfx].append("--margin-top:{top}{unit};--margin-bottom:{bottom}{unit};--margin-left:{left}{unit};--margin-right:{right}{unit}".format(**m))
        g = s.get("flex_gap" + sfx)
        if g:
            out[sfx].append("--gap:{row}px {column}px;--row-gap:{row}px;--column-gap:{column}px".format(**g))
        d = s.get("flex_direction" + sfx)
        if d:
            out[sfx].append("--flex-direction:%s;--container-widget-width:%s" % (d, "initial" if d == "row" else "100%"))
    if s.get("content_width") == "boxed" and s.get("boxed_width"):
        out[""].append("--content-width:%s" % unit(s["boxed_width"]))
    css = "%s{--display:flex;%s}" % (x, ";".join(out[""]))
    if s.get("background_background") == "gradient":
        css += "%s{background-color:transparent;background-image:linear-gradient(%sdeg, %s 0%%, %s 100%%)}" % (
            x, s["background_gradient_angle"]["size"], s["background_color"], s["background_color_b"])
    elif s.get("background_background") == "classic":
        css += "%s{background-color:%s}" % (x, s["background_color"])
    for sfx in ("_tablet", "_mobile"):
        if out[sfx]:
            css += "@media(max-width:%dpx){%s{%s}}" % (BP[sfx], x, ";".join(out[sfx]))
    return css


def typo_rules(s, prefix="typography"):
    res = {k: [] for k in BP}
    if s.get(prefix + "_typography") != "custom":
        return res
    if s.get(prefix + "_font_family"):
        res[""].append('font-family:"%s", Sans-serif' % s[prefix + "_font_family"])
    for sfx in BP:
        for key, prop in (("font_size", "font-size"), ("line_height", "line-height"), ("letter_spacing", "letter-spacing")):
            v = s.get("%s_%s%s" % (prefix, key, sfx))
            if v:
                res[sfx].append("%s:%s" % (prop, unit(v)))
    if s.get(prefix + "_font_weight"):
        res[""].append("font-weight:%s" % s[prefix + "_font_weight"])
    if s.get(prefix + "_text_transform"):
        res[""].append("text-transform:%s" % s[prefix + "_text_transform"])
    return res


def widget_css(e):
    s, x, wt = e["settings"], sel(e), e["widgetType"]
    if wt == "heading":
        target, color = x + " .elementor-heading-title", s.get("title_color")
    elif wt == "text-editor":
        target, color = x, s.get("text_color")
    else:
        return ""
    r = typo_rules(s)
    if color:
        r[""].append("color:%s" % color)
    css = "%s{%s}" % (target, ";".join(r[""])) if r[""] else ""
    for sfx in ("_tablet", "_mobile"):
        if r[sfx]:
            css += "@media(max-width:%dpx){%s{%s}}" % (BP[sfx], target, ";".join(r[sfx]))
    return css


def post_css():
    # widget-type defaults Elementor prints into every post CSS file (taken from the live post-30.css)
    css = (".elementor-widget-heading .elementor-heading-title{font-family:var( --e-global-typography-primary-font-family ), Sans-serif;"
           "font-weight:var( --e-global-typography-primary-font-weight );color:var( --e-global-color-primary );}"
           ".elementor-widget-text-editor{font-family:var( --e-global-typography-text-font-family ), Sans-serif;"
           "font-weight:var( --e-global-typography-text-font-weight );color:var( --e-global-color-text );}")
    for t in DATA:
        for e in walk(t):
            css += container_css(e) if e["elType"] == "container" else widget_css(e)
    for t in DATA:  # Elementor Pro custom CSS is appended after the generated rules
        for e in walk(t):
            if e["settings"].get("custom_css"):
                css += e["settings"]["custom_css"].replace("selector", sel(e))
    return css


def render(e, card_html, top=False):
    s = e["settings"]
    if e["elType"] == "container":
        inner = "".join(render(c, card_html) for c in e["elements"])
        boxed = s.get("content_width") == "boxed"
        cls = "elementor-element elementor-element-%s e-flex %s e-con %s %s" % (
            e["id"], "e-con-boxed" if boxed else "e-con-full", "e-parent" if top else "e-child", s.get("css_classes", ""))
        if boxed:
            inner = '<div class="e-con-inner">%s</div>' % inner
        return '<div class="%s" data-id="%s" data-element_type="container">%s</div>' % (cls.strip(), e["id"], inner)
    wt = e["widgetType"]
    if wt == "heading":
        t = s.get("header_size", "h2")
        inner = '<%s class="elementor-heading-title elementor-size-default">%s</%s>' % (t, s["title"], t)
    elif wt == "text-editor":
        inner = s["editor"]
    elif wt == "shortcode":
        inner = '<div class="elementor-shortcode">%s</div>' % card_html
    elif wt == "form":
        inner = '<form class="elementor-form" name="%s"></form>' % H.escape(s["form_name"])
    else:
        inner = ""
    cls = "elementor-element elementor-element-%s %s elementor-widget elementor-widget-%s" % (e["id"], s.get("_css_classes", ""), wt)
    return '<div class="%s" data-id="%s" data-element_type="widget" data-widget_type="%s.default">%s</div>' % (
        re.sub(r"\s+", " ", cls), e["id"], wt, inner)


PLACEHOLDER_CSS = """
.ph-calc{display:grid;grid-template-columns:240px minmax(0,1fr);gap:20px;align-items:start;font-family:'DM Sans',sans-serif}
.ph-box{background:#fff;border:1px solid #dde5f4;border-radius:18px;box-shadow:0 1px 2px rgba(4,25,80,.04),0 16px 44px rgba(4,25,80,.09)}
.ph-rail{padding:22px}.ph-rail p{margin:0 0 14px;font-size:11px;font-weight:700;letter-spacing:.13em;text-transform:uppercase;color:#5b6684}
.ph-step{display:flex;align-items:center;gap:11px;padding:11px 0;border-bottom:1px solid #eef2f9;font-size:13px;font-weight:600;color:#5b6684}
.ph-step:last-of-type{border:0}.ph-step i{display:grid;place-items:center;width:28px;height:28px;border:1px solid #dde5f4;border-radius:50%;font-style:normal;font-size:12px}
.ph-step.on{color:#030922}.ph-step.on i{background:#0f5cd8;border-color:#0f5cd8;color:#fff}
.ph-total{margin-top:18px;padding:14px;border-radius:12px;background:#f6f8fd;border:1px solid #dde5f4;font-size:12px;color:#5b6684}
.ph-total b{display:block;margin-top:4px;font-size:20px;color:#041950}
.ph-main{min-height:520px;overflow:hidden}.ph-top{padding:24px 32px 18px;border-bottom:1px solid #dde5f4}
.ph-row{display:flex;justify-content:space-between;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#0f5cd8}
.ph-row span:last-child{color:#5b6684}.ph-bar{height:4px;margin-top:16px;border-radius:9px;background:#edf1f8}.ph-bar i{display:block;width:20%;height:100%;border-radius:9px;background:#0f5cd8}
.ph-body{padding:30px 32px}.ph-body h2{margin:0 0 8px;font-size:25px;line-height:1.2;color:#030922;font-weight:700;letter-spacing:-.6px}
.ph-body p{margin:0 0 24px;font-size:14px;color:#5b6684}.ph-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.ph-grid div{height:52px;border:1px solid #dde5f4;border-radius:10px;background:#fbfcff}
.ph-note{position:absolute;right:12px;bottom:10px;font-size:11px;color:#9aa5bf}
@media(max-width:850px){.ph-calc{grid-template-columns:1fr}.ph-rail p,.ph-total{display:none}.ph-rail{padding:14px 18px;display:grid;grid-template-columns:repeat(5,1fr)}
.ph-step{flex-direction:column;gap:6px;border:0;padding:2px;font-size:10px;text-align:center}}
@media(max-width:560px){.ph-grid{grid-template-columns:1fr}.ph-top{padding:20px}.ph-body{padding:22px 20px}.ph-body h2{font-size:21px}}
"""
STEPS = ["Business", "Volumes", "Tax status", "Services", "Your details"]
PLACEHOLDER = ('<div class="ph-calc" style="position:relative"><aside class="ph-box ph-rail"><p>Your package setup</p>%s'
               '<div class="ph-total">Estimated annual total<b>AED 500</b></div></aside>'
               '<section class="ph-box ph-main"><div class="ph-top"><div class="ph-row"><span>Your business</span><span>Step 01 / 05</span></div>'
               '<div class="ph-bar"><i></i></div></div><div class="ph-body"><h2>First, tell us about your business.</h2>'
               '<p>Placeholder card: the [lp_package_calculator] shortcode renders the real calculator here.</p>'
               '<div class="ph-grid">%s</div></div></section></div>') % (
    "".join('<div class="ph-step%s"><i>%d</i>%s</div>' % (" on" if i == 0 else "", i + 1, s) for i, s in enumerate(STEPS)),
    "<div></div>" * 8)


def header_html():
    head = open(os.path.join(SITE, "header.html")).read()
    logo = ('data:image/svg+xml;utf8,' + "<svg xmlns='http://www.w3.org/2000/svg' width='622' height='210'><rect width='622' height='210' fill='white'/>"
            "<text x='8' y='138' font-family='DM Sans,Arial' font-weight='700' font-size='112' fill='%23041950'>Ledger<tspan fill='%230f5cd8'>Plus</tspan></text></svg>")
    head = re.sub(r'src="https://ledgerplus\.ae/wp-content/uploads/2026/09/Logo-Assests-01-edited\.png"', 'src="%s"' % logo, head)
    head = re.sub(r'\ssrcset="[^"]*"', "", head)
    head = re.sub(r'(src|href)="https://ledgerplus\.ae/wp-content/[^"]*\.(webp|jpg|png)"', r'\1=""', head)
    return head


def page(card_html, extra_css="", title="preview"):
    fonts = "".join("@font-face{font-family:'DM Sans';font-style:normal;font-weight:%d;font-display:block;src:url(file://%s/dm-sans-latin-%d-normal.woff2) format('woff2')}"
                    % (w, FONTS, w) for w in (400, 500, 600, 700))
    links = "".join('<link rel="stylesheet" href="file://%s/css/%s.css">' % (SITE, c) for c in
                    ("reset", "frontend-part1", "frontend-part2", "post-4", "nav-menu-subset", "widget-heading", "widget-form", "post-32-subset"))
    content = "".join(render(t, card_html, top=True) for t in DATA)
    cls = ("wp-singular page-template page-template-elementor_header_footer page page-id-%d wp-embed-responsive wp-theme-hello-elementor "
           "theme-hello-elementor hello-elementor-default elementor-default elementor-template-full-width elementor-kit-4 elementor-page elementor-page-%d") % (PID, PID)
    footer = '<footer style="height:260px;background:#030922;color:#8fb8ff;font:600 13px DM Sans,sans-serif;padding:40px 24px">site footer (stand-in)</footer>'
    return ('<!doctype html><html lang="en-US"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            '<title>%s</title><style>%s</style>%s%s<style id="post-%d">%s</style><style>%s</style></head><body class="%s">%s'
            '<div data-elementor-type="wp-page" data-elementor-id="%d" class="elementor elementor-%d" data-elementor-post-type="page">%s</div>%s</body></html>'
            % (H.escape(title), fonts, links, (open(HEAD_INLINE).read() if os.path.exists(HEAD_INLINE) else ""), PID, post_css(), extra_css, cls,
               header_html(), PID, PID, content, footer))


def real_card():
    php = subprocess.run(["php", os.path.join(CALC, "test", "ui", "render.php")], capture_output=True, text=True, timeout=60)
    if php.returncode != 0 or "data-lp-calc" not in php.stdout:
        sys.stderr.write("real template not rendered: %s\n" % php.stderr[-400:])
        return None
    return php.stdout


def team_email():
    form = [e for t in DATA for e in walk(t) if e.get("widgetType") == "form"][0]["settings"]
    sample = {  # test data only
        "name": "Test Customer", "company": "Test Trading LLC", "email": "test.customer@example.com", "phone": "+971 50 000 0000",
        "message": "We also need help moving our books from Excel.\nPlease call after 2pm.", "quote_ref": "LPQ-261127-4F2A9",
        "quote_total": "AED 7,000",
        "quote_summary": ("Quote LPQ-261127-4F2A9\nBusiness: Trading / Wholesale\nAnnual transactions: 1,000 transactions a year\n"
                          "VAT: Already registered\nCorporate Tax: Not registered\nEmployees: Up to 20 employees\n\nServices:\n"
                          "- Accounting and bookkeeping: AED 2,000\n- VAT returns filing and compliance: AED 2,000\n"
                          "- Corporate Tax registration and filing: AED 2,000\n- Payroll and WPS support: AED 1,000\n\n"
                          "Estimated annual total (incl. 5% VAT): AED 7,000"),
        "field_page_title": "Build Your Package", "page_url": "https://ledgerplus.ae/package-calculator/?utm_source=google&utm_medium=cpc",
        "lead_source": "Website", "field_lead_form": "Ledgerplus Package Calculator", "utm_source": "google", "utm_medium": "cpc",
        "utm_campaign": "", "utm_term": "", "utm_keyword": "",
    }
    fill = lambda t: re.sub(r'\[field id="(\w+)"\]', lambda m: H.escape(sample.get(m.group(1), ""), quote=True), t)
    subj = fill(form["email_subject"])
    return ('<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            '<title>%s</title><style>%s body{margin:0}</style></head><body>%s</body></html>') % (
        H.escape(subj), "".join("@font-face{font-family:'DM Sans';font-weight:%d;src:url(file://%s/dm-sans-latin-%d-normal.woff2)}" % (w, FONTS, w)
                                for w in (400, 600, 700)), fill(form["email_content"])), subj


SHOOT = r"""
const { chromium } = require('playwright');
const jobs = JSON.parse(process.argv[1]);
(async () => {
  const browser = await chromium.launch();
  const report = [];
  for (const j of jobs) {
    const page = await browser.newPage({ viewport: { width: j.w, height: j.h }, deviceScaleFactor: 1 });
    await page.goto('file://' + j.file);
    await page.evaluate(() => document.fonts.ready);
    const m = await page.evaluate(() => {
      const q = s => document.querySelector(s), r = el => el ? el.getBoundingClientRect() : null;
      const hero = r(q('.lpcp-hero')), calc = r(q('.lpcp-calc')), card = r(q('.elementor-shortcode .lp-calc, .elementor-shortcode .ph-calc'));
      const h1 = q('.lpcp-h1 .elementor-heading-title'), eb = q('.lpcp-eyebrow .elementor-heading-title');
      const cs = el => el ? getComputedStyle(el) : null;
      return { scrollW: document.documentElement.scrollWidth, innerW: innerWidth,
        heroBottom: hero && Math.round(hero.bottom), calcTop: calc && Math.round(calc.top), cardTop: card && Math.round(card.top),
        cardLeft: card && Math.round(card.left), cardW: card && Math.round(card.width),
        h1: h1 && { size: cs(h1).fontSize, family: cs(h1).fontFamily, color: cs(h1).color, lines: Math.round(h1.getBoundingClientRect().height / parseFloat(cs(h1).lineHeight)) },
        eyebrow: eb && { text: eb.innerText, color: cs(eb).color },
        h1Left: h1 && Math.round(h1.getBoundingClientRect().left),
        calcBg: calc && cs(q('.lpcp-calc')).backgroundImage.slice(0, 120) };
    });
    report.push(Object.assign({ out: j.out }, m));
    await page.screenshot({ path: j.out, fullPage: !!j.full, clip: j.full ? undefined : { x: 0, y: 0, width: j.w, height: j.h } });
    await page.close();
  }
  await browser.close();
  console.log(JSON.stringify(report, null, 1));
})().catch(e => { console.error(e); process.exit(1); });
"""


def main():
    files = {}
    files["ph"] = os.path.join(OUT, "preview-placeholder.html")
    open(files["ph"], "w").write(page(PLACEHOLDER, PLACEHOLDER_CSS, "Build Your Package (placeholder card)"))
    card = real_card()
    if card:
        files["real"] = os.path.join(OUT, "preview-real.html")
        css = '<link rel="stylesheet" href="file://%s">' % os.path.join(CALC, "plugin", "ledgerplus-calculator", "assets", "calculator.css")
        open(files["real"], "w").write(page(card, "", "Build Your Package (real template)").replace("</head>", css + "</head>", 1))
    em, subj = team_email()
    files["email"] = os.path.join(OUT, "team-email.html")
    open(files["email"], "w").write(em)
    print("team email subject:", subj)
    jobs = []
    for w, h in ((1440, 1000), (1024, 900), (768, 1000), (390, 900), (360, 800)):
        jobs.append({"file": files["ph"], "w": w, "h": h, "out": os.path.join(SHOTS, "page-hero-%d.png" % w)})
        if "real" in files:
            jobs.append({"file": files["real"], "w": w, "h": h, "out": os.path.join(SHOTS, "page-hero-real-%d.png" % w)})
    for w in (640, 375):
        jobs.append({"file": files["email"], "w": w, "h": 900, "full": True, "out": os.path.join(SHOTS, "page-team-email-%d.png" % w)})
    env = dict(os.environ, NODE_PATH=os.path.join(CALC, "test", "node_modules") + os.pathsep + os.environ.get("NODE_PATH", ""))
    r = subprocess.run(["node", "-e", SHOOT, json.dumps(jobs)], capture_output=True, text=True, env=env, timeout=300, cwd=os.path.join(CALC, "test"))
    sys.stdout.write(r.stdout)
    if r.returncode != 0:
        sys.stderr.write(r.stderr)
        sys.exit(1)
    bad = [j for j in json.loads(r.stdout) if j["scrollW"] > j["innerW"]]
    if bad:
        sys.stderr.write("horizontal scroll in: %s\n" % [b["out"] for b in bad])
        sys.exit(1)


if __name__ == "__main__":
    main()
