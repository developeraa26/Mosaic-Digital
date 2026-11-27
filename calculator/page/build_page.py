"""Ledger Plus "Build Your Package" calculator page (Elementor, draft) for ledgerplus.ae.

Contract: calculator/SPEC.md, section "Page (Elementor, draft)".
House conventions: shop/pages/purchase-page-builder.py (uid(), dim(), gap(), W(), C(), enc(), save-page body).

Layout:
  1. Hero container: navy gradient, eyebrow, H1, sub text (from the client sample), three tags.
     Typography and colours use native Elementor controls (as on the home page); the eyebrow rule,
     the tag pills and the decoration are in the container's Custom CSS (Elementor Pro).
  2. Calculator container: boxed 1160px, pulled up 80px (64px on phones) over the hero. Its Custom CSS
     paints #f6f8fd below the hero and keeps the overlapping band transparent.
     - shortcode widget [lp_package_calculator] (the ledgerplus-calculator plugin);
     - Elementor Pro form "Ledger Plus package calculator" (class lp-calc-form). calculator.js moves it
       into step 5. It creates the Zoho CRM lead (zoho_Ledgers_Plus), emails the team, and the plugin
       emails the client the PDF quotation.
Hidden tracking fields, their __dynamic__ tags and the Zoho mapping are copied from the live home hero
form (page 30, widget 4eee519d, "Home hero enquiry").

Writes out/page.json (save-page body) and out/elementor-data.json (enc(data), the _elementor_data form).
usage: python3 build_page.py [page_id]      exits non-zero if any check fails.
"""
import hashlib, html as H, json, os, re, sys

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, "out")
os.makedirs(OUT, exist_ok=True)

TITLE = "Build Your Package"
SLUG = "package-calculator"
FORM_NAME = "Ledger Plus package calculator"
LEAD_FORM = "Ledgerplus Package Calculator"
BOXED = 1160
OVERLAP = {"": 80, "_tablet": 80, "_mobile": 64}  # calculator container top margin (negative) per breakpoint
NAVY, NAVY_2, BLUE, INK, TEXT, MUTED, LINE, SOFT, ACCENT = (
    "#041950", "#0a2a7a", "#0f5cd8", "#030922", "#2c3651", "#5b6684", "#dde5f4", "#f6f8fd", "#8fb8ff")


def enc(o):
    return json.dumps(o, ensure_ascii=True, separators=(",", ":")).replace("/", "\\/")


# ------------------------------------------------------------------ copy
T = {
    "eyebrow": "Ledger Plus UAE · Package calculator",  # shown in capitals (text-transform)
    "h1": "Build a package that works for your business.",
    "sub": "Choose your transaction volumes and the services you need. We'll prepare a clear estimate around "
           "the way your business operates.",  # verbatim from the client sample
    "tags": ["AI-powered accounting", "VAT & Corporate Tax", "E-invoicing"],
}

# ------------------------------------------------------------------ element helpers (as in purchase-page-builder.py)
_used = set()


def uid(seed):
    i = 0
    while True:
        h = hashlib.sha1(("lpcalc|%s|%d" % (seed, i)).encode()).hexdigest()[:8]
        if h not in _used and not h.isdigit():
            _used.add(h)
            return h
        i += 1


_used_fid = set()


def fid(seed):
    """7-hex repeater _id, unique within the page."""
    i = 0
    while True:
        h = hashlib.sha1(("lpcalc-field|%s|%d" % (seed, i)).encode()).hexdigest()[:7]
        if h not in _used_fid and h not in _used and not h.isdigit():
            _used_fid.add(h)
            return h
        i += 1


def dim(t, r=None, b=None, l=None, unit="px"):
    r = t if r is None else r
    b = t if b is None else b
    l = r if l is None else l
    return {"unit": unit, "top": t, "right": r, "bottom": b, "left": l, "isLinked": t == r == b == l}


def gap(v, row=None):
    row = v if row is None else row
    return {"unit": "px", "column": str(v), "row": str(row), "isLinked": v == row}


def px(v):
    return {"unit": "px", "size": v, "sizes": []}


def W(seed, wtype, **s):
    return {"id": uid(seed), "elType": "widget", "settings": s, "elements": [], "widgetType": wtype}


def C(seed, children, top=False, **s):
    base = {"content_width": "boxed" if top else "full", "flex_direction": "column", "flex_gap": gap(0), "padding": dim(0)}
    if top:
        base["boxed_width"] = {"unit": "px", "size": BOXED}
    base.update(s)
    return {"id": uid(seed), "elType": "container", "settings": base, "elements": children, "isInner": not top}


def esc(t):
    return H.escape(t, quote=False)


def typo(prefix="typography", family="DM Sans", weight="400", size=None, lh=None, ls=None, transform=None):
    """Native Elementor typography group values. size/lh/ls: (desktop, tablet, mobile) in px; None skips."""
    s = {prefix + "_typography": "custom", prefix + "_font_family": family, prefix + "_font_weight": weight}
    for key, vals in (("font_size", size), ("line_height", lh), ("letter_spacing", ls)):
        if vals is None:
            continue
        for sfx, v in zip(("", "_tablet", "_mobile"), vals):
            if v is not None:
                s["%s_%s%s" % (prefix, key, sfx)] = px(v)
    if transform:
        s[prefix + "_text_transform"] = transform
    return s


def min_css(css):
    return "\n".join(l.strip() for l in css.strip().splitlines() if l.strip())


# ------------------------------------------------------------------ 1. hero
HERO_CSS = min_css("""
selector{position:relative;overflow:hidden}
selector::after{content:'';position:absolute;right:-150px;top:-260px;width:560px;height:560px;border-radius:50%;border:1px solid rgba(143,184,255,.16);background:radial-gradient(closest-side,rgba(15,92,216,.42),rgba(15,92,216,0));box-shadow:0 0 0 56px rgba(143,184,255,.035),0 0 0 112px rgba(143,184,255,.025);pointer-events:none;z-index:0}
selector>.e-con-inner{position:relative;z-index:1}
selector .lpcp-eyebrow .elementor-heading-title{display:inline-flex;align-items:center;gap:10px;margin:0}
selector .lpcp-eyebrow .elementor-heading-title::before{content:'';flex:none;width:22px;height:2px;border-radius:2px;background:currentColor}
selector .lpcp-h1 .elementor-heading-title{max-width:760px;margin:0}
selector .lpcp-sub{max-width:620px}
selector .lpcp-sub p{margin:0}
selector .lpcp-tags{margin-top:8px}
selector .lpcp-tags ul{display:flex;flex-wrap:wrap;gap:9px;list-style:none;margin:0;padding:0}
selector .lpcp-tags li{margin:0;padding:8px 14px;border-radius:999px;background:rgba(255,255,255,.08);border:1px solid rgba(143,184,255,.34)}
@media(max-width:767px){selector::after{right:-260px;top:-300px}selector .lpcp-tags{margin-top:4px}selector .lpcp-tags li{padding:7px 12px}}
""")

hero = C("hero", [
    W("hero_eyebrow", "heading", title=esc(T["eyebrow"]), header_size="p", _css_classes="lpcp-eyebrow", title_color=ACCENT,
      **typo(weight="700", size=(12, 12, 11), lh=(16, 16, 15), ls=(1.7, 1.7, 1.5), transform="uppercase")),
    W("hero_h1", "heading", title=esc(T["h1"]), header_size="h1", _css_classes="lpcp-h1", title_color="#ffffff",
      **typo(weight="700", size=(56, 46, 34), lh=(60, 51, 39), ls=(-2, -1.5, -1))),
    W("hero_sub", "text-editor", editor="<p>%s</p>" % esc(T["sub"]), _css_classes="lpcp-sub", text_color="rgba(255,255,255,0.8)",
      **typo(weight="400", size=(17, 17, 15.5), lh=(28, 28, 25))),
    W("hero_tags", "text-editor", editor="<ul>%s</ul>" % "".join("<li>%s</li>" % esc(t) for t in T["tags"]),
      _css_classes="lpcp-tags", text_color="#e3ecff", **typo(weight="600", size=(13, 13, 12.5), lh=(18, 18, 17))),
], top=True, flex_gap=gap(0, 18), flex_gap_mobile=gap(0, 14),
    padding=dim(72, 24, 120, 24), padding_tablet=dim(64, 24, 120, 24), padding_mobile=dim(48, 16, 104, 16),
    background_background="gradient", background_color=NAVY, background_color_b=NAVY_2,
    background_gradient_angle={"unit": "deg", "size": 135, "sizes": []},
    css_classes="lpcp-hero", custom_css=HERO_CSS, _title="Hero")

# ------------------------------------------------------------------ 2. form (Elementor Pro)
VISIBLE = [  # custom_id, type, label, extra
    ("name", "text", "Full name", {"width": "50", "width_mobile": "100", "required": "true"}),
    ("company", "text", "Company name", {"width": "50", "width_mobile": "100", "required": "true"}),
    ("email", "email", "Business email", {"width": "50", "width_mobile": "100", "required": "true"}),
    ("phone", "tel", "Contact number", {"width": "50", "width_mobile": "100", "required": "true", "ccfef-country-code-default": "ae"}),
    ("message", "textarea", "Additional requirements or notes (optional)", {"rows": 3}),
]
CALC_HIDDEN = [  # filled by calculator.js (lpc_*) or by the plugin before the actions run (quote_*)
    ("lpc_version", "Calculator version", "1"),
    ("lpc_business", "Business activity", ""),
    ("lpc_business_other", "Business activity (other)", ""),
    ("lpc_transactions", "Annual transactions", ""),
    ("lpc_vat_status", "VAT status", ""),
    ("lpc_ct_status", "Corporate Tax status", ""),
    ("lpc_employees", "Employees", ""),
    ("lpc_reports", "Reports a year", ""),
    ("lpc_services", "Services", ""),
    ("quote_ref", "Quote reference", ""),
    ("quote_total", "Quote total", ""),
    ("quote_summary", "Quote summary", ""),
]


def tag_req_arg(param):
    return '[elementor-tag id="b94c60d" name="request-arg" settings="%%7B%%22param_name%%22%%3A%%22%s%%22%%7D"]' % param


DYN = {  # __dynamic__ values, as on the live home hero form
    "field_page_title": '[elementor-tag id="df148ed" name="post-title" settings="%7B%7D"]',
    "page_url": '[elementor-tag id="03d1df1" name="shortcode" settings="%7B%22shortcode%22%3A%22%5Bgeturl%5D%22%7D"]',
}
for _p in ("utm_source", "utm_medium", "utm_campaign", "utm_term", "utm_keyword"):
    DYN[_p] = tag_req_arg(_p)
SITE_HIDDEN = [  # custom_id, label (as on the hero form), static value
    ("field_page_title", "page_title", None),
    ("page_url", "page_url", None),
    ("lead_source", "lead_source", "Website"),
    ("utm_source", "utm_source", None),
    ("utm_medium", "utm_medium", None),
    ("utm_campaign", "utm_campaign", None),
    ("utm_term", "utm_term", None),
    ("utm_keyword", "utm_keyword", None),
    ("field_lead_form", "Lead Form", LEAD_FORM),
]

fields = []
for cid, ftype, label, extra in VISIBLE:
    f = {"_id": fid(cid), "custom_id": cid, "field_type": ftype, "field_label": label}
    f.update(extra)
    fields.append(f)
fields.append({"_id": fid("hp"), "custom_id": "hp", "field_type": "honeypot"})
for cid, label, value in CALC_HIDDEN:
    f = {"_id": fid(cid), "custom_id": cid, "field_type": "hidden", "field_label": label}
    if value:
        f["field_value"] = value
    fields.append(f)
for cid, label, value in SITE_HIDDEN:
    f = {"_id": fid(cid), "custom_id": cid, "field_type": "hidden", "field_label": label}
    if value is not None:
        f["field_value"] = value
    if cid in DYN:
        f["__dynamic__"] = {"field_value": DYN[cid]}
    fields.append(f)

# Team email: same design as the home hero form's "New website enquiry" email.
S_SEC = "padding:%s;font-size:12px;letter-spacing:1.5px;text-transform:uppercase;color:#0f5cd8;font-weight:700;"
S_L = "width:34%;padding:10px 12px;background:#f6f8fd;border:1px solid #dde5f4;color:#041950;font-weight:600;vertical-align:top;"
S_R = "padding:10px 12px;border:1px solid #dde5f4;color:#030922;vertical-align:top;word-break:break-word;"
S_A = "color:#0f5cd8;text-decoration:none;"


def f_(cid):
    return '[field id="%s"]' % cid


def e_sec(title, first=False):
    return '<tr><td colspan="2" style="%s">%s</td></tr>' % (S_SEC % ("0 0 10px" if first else "22px 0 10px"), esc(title))


def e_row(label, value, extra=""):
    return '<tr><td style="%s">%s</td><td style="%s%s">%s</td></tr>' % (S_L, esc(label), S_R, extra, value)


EMAIL_ROWS = "".join([
    e_sec("Contact details", first=True),
    e_row("Full name", f_("name")),
    e_row("Company", f_("company")),
    e_row("Business email", '<a href="mailto:%s" style="%s">%s</a>' % (f_("email"), S_A, f_("email"))),
    e_row("Contact number", '<a href="tel:%s" style="%s">%s</a>' % (f_("phone"), S_A, f_("phone"))),
    e_row("Notes", f_("message"), "white-space:pre-wrap;"),
    e_sec("Package estimate"),
    e_row("Quote reference", '<strong style="color:#041950;">%s</strong>' % f_("quote_ref")),
    e_row("Estimated annual total (incl. 5% VAT)", '<strong style="font-size:16px;color:#041950;">%s</strong>' % f_("quote_total")),
    e_row("Selections and prices", f_("quote_summary"), "white-space:pre-wrap;"),
    e_sec("Source & tracking"),
    e_row("Page", f_("field_page_title")),
    e_row("Page URL", f_("page_url"), "word-break:break-all;"),
    e_row("Lead source", f_("lead_source")),
    e_row("Lead form", f_("field_lead_form")),
    e_row("UTM source", f_("utm_source")),
    e_row("UTM medium", f_("utm_medium")),
    e_row("UTM campaign", f_("utm_campaign")),
    e_row("UTM term", f_("utm_term")),
    e_row("UTM keyword", f_("utm_keyword")),
])
EMAIL_HTML = "".join([
    '<div style="margin:0;padding:24px 12px;background:#eef3fb;font-family:\'DM Sans\',Arial,Helvetica,sans-serif;color:#030922;">',
    '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:640px;margin:0 auto;',
    'background:#ffffff;border:1px solid #dde5f4;border-radius:14px;border-collapse:separate;overflow:hidden;">',
    '<tr><td style="background:#041950;padding:22px 26px;border-bottom:4px solid #0f5cd8;">',
    '<div style="font-size:12px;letter-spacing:2px;text-transform:uppercase;color:#8fb8ff;font-weight:700;">Ledger Plus UAE</div>',
    '<div style="font-size:20px;line-height:1.3;color:#ffffff;font-weight:700;margin-top:6px;">New package calculator lead</div>',
    '<div style="font-size:13px;line-height:1.5;color:#c7d9ff;margin-top:4px;">Quote ', f_("quote_ref"), ' &middot; ',
    f_("quote_total"), ' a year (incl. 5% VAT)</div>',
    '</td></tr>',
    '<tr><td style="padding:22px 26px 6px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" ',
    'style="border-collapse:collapse;font-size:14px;line-height:1.5;">', EMAIL_ROWS, '</table></td></tr>',
    '<tr><td style="padding:16px 26px 24px;font-size:12px;line-height:1.6;color:#5b6684;">',
    'Sent from the package calculator on ledgerplus.ae. The client is emailed the PDF quotation separately. ',
    'Reply to this email to answer the client.</td></tr>',
    '</table></div>',
])

Z = "el_pro_zoho_zoho_Ledgers_Plus_"
ZOHO = {  # exact keys and values, as on the home hero form (Description adapted for the calculator)
    Z + "Name": f_("name"),
    Z + "Owner": "794156085",
    Z + "Email": f_("email"),
    Z + "Mobile": f_("phone"),
    Z + "Lead_Source": f_("lead_source"),
    Z + "Lead_Status": "Prospecting",
    Z + "Description": "Client Company Name: %s\n\nPackage calculator estimate:\n%s" % (f_("company"), f_("quote_summary")),
    Z + "Lead_Form": f_("field_lead_form"),
    Z + "utm_keyword": f_("utm_keyword"),
    Z + "utm_campaign": f_("utm_campaign"),
    Z + "utm_term": f_("utm_term"),
    Z + "Page_Name": f_("field_page_title"),
    Z + "utm_source": f_("utm_source"),
    Z + "Landing_page_URL": f_("page_url"),
    Z + "utm_medium": f_("utm_medium"),
    Z + "Client_s_message": f_("message"),
}

MESSAGES = {
    "success_message": "Thank you. Your quotation is on its way to your inbox.",
    "error_message": "Something went wrong. Please try again, or email info@ledgerplus.ae.",
    "required_field_message": "This field is required.",
    "invalid_message": "Please check the highlighted fields and try again.",
}

form_settings = {
    "form_name": FORM_NAME,
    "form_fields": fields,
    # Elementor's switcher returns "true" for show_labels (its default); "yes" would also render labels,
    # but the editor would then show the switch as off. mark_required uses "yes", as on the hero form.
    "show_labels": "true",
    "mark_required": "yes",
    "button_text": "Email me my quotation",
    "button_size": "md",
    "button_width": "100",
    "submit_actions": ["save-to-database", "email", "zoho_Ledgers_Plus"],
    "email_to": "info@ledgerplus.ae",
    "email_subject": 'Package calculator lead: [field id="company"] ([field id="quote_ref"])',
    "email_content": EMAIL_HTML,
    "email_from": "no-reply@ledgerplus.ae",
    "email_from_name": "Ledger Plus UAE website",
    "email_reply_to": "email",
    "email_content_type": "html",
    "form_metadata": [],  # as on the hero form: no "---" metadata block (IP, user agent) under the HTML email
    "custom_messages": "yes",
}
form_settings.update(ZOHO)
form_settings.update(MESSAGES)
form_settings.update({"_css_classes": "lp-calc-form", "_element_cache": "no",
                      "_title": "Ledger Plus package calculator form (moved into step 5 by the plugin)"})
form = {"id": uid("form"), "elType": "widget", "settings": form_settings, "elements": [], "widgetType": "form"}

# ------------------------------------------------------------------ 3. calculator container
CALC_CSS = min_css("""
selector{--lpcp-overlap:%dpx;background:linear-gradient(180deg,rgba(246,248,253,0) 0,rgba(246,248,253,0) var(--lpcp-overlap),#f6f8fd var(--lpcp-overlap),#f6f8fd 100%%)}
@media(max-width:1024px){selector{--lpcp-overlap:%dpx}}
@media(max-width:767px){selector{--lpcp-overlap:%dpx}}
""" % (OVERLAP[""], OVERLAP["_tablet"], OVERLAP["_mobile"]))

shortcode = W("calc_shortcode", "shortcode", shortcode="[lp_package_calculator]", _element_cache="no",
              _title="Package calculator (ledgerplus-calculator plugin)")
calc = C("calc", [shortcode, form], top=True, flex_gap=gap(0),
         margin=dim(-OVERLAP[""], 0, 0, 0), margin_tablet=dim(-OVERLAP["_tablet"], 0, 0, 0),
         margin_mobile=dim(-OVERLAP["_mobile"], 0, 0, 0),
         padding=dim(0, 24, 96, 24), padding_tablet=dim(0, 24, 80, 24), padding_mobile=dim(0, 16, 56, 16),
         css_classes="lpcp-calc", custom_css=CALC_CSS,
         _title="Calculator (Custom CSS paints #f6f8fd below the hero)")

DATA = [hero, calc]


# ------------------------------------------------------------------ checks
def walk(e):
    yield e
    for c in e.get("elements", []):
        yield from walk(c)


SPEC_CUSTOM_IDS = ["name", "company", "email", "phone", "message", "hp",
                   "lpc_version", "lpc_business", "lpc_business_other", "lpc_transactions", "lpc_vat_status",
                   "lpc_ct_status", "lpc_employees", "lpc_reports", "lpc_services", "quote_ref", "quote_total",
                   "quote_summary", "field_page_title", "page_url", "lead_source", "utm_source", "utm_medium",
                   "utm_campaign", "utm_term", "utm_keyword", "field_lead_form"]
SPEC_ZOHO = {
    "Name": '[field id="name"]', "Owner": "794156085", "Email": '[field id="email"]', "Mobile": '[field id="phone"]',
    "Lead_Source": '[field id="lead_source"]', "Lead_Status": "Prospecting",
    "Description": 'Client Company Name: [field id="company"]\n\nPackage calculator estimate:\n[field id="quote_summary"]',
    "Lead_Form": '[field id="field_lead_form"]', "utm_keyword": '[field id="utm_keyword"]',
    "utm_campaign": '[field id="utm_campaign"]', "utm_term": '[field id="utm_term"]',
    "Page_Name": '[field id="field_page_title"]', "utm_source": '[field id="utm_source"]',
    "Landing_page_URL": '[field id="page_url"]', "utm_medium": '[field id="utm_medium"]',
    "Client_s_message": '[field id="message"]',
}
SPEC_DYN = {
    "field_page_title": '{"field_value":"[elementor-tag id=\\"df148ed\\" name=\\"post-title\\" settings=\\"%7B%7D\\"]"}',
    "page_url": '{"field_value":"[elementor-tag id=\\"03d1df1\\" name=\\"shortcode\\" settings=\\"%7B%22shortcode%22%3A%22%5Bgeturl%5D%22%7D\\"]"}',
    "utm_source": '{"field_value":"[elementor-tag id=\\"b94c60d\\" name=\\"request-arg\\" settings=\\"%7B%22param_name%22%3A%22utm_source%22%7D\\"]"}',
}
problems = []


def check(cond, msg):
    if not cond:
        problems.append(msg)


els = [e for t in DATA for e in walk(t)]
ids = [e["id"] for e in els]
check(len(ids) == len(set(ids)), "element ids are not unique")
check(all(re.fullmatch(r"[0-9a-f]{7,8}", i) for i in ids), "element id not 7-8 hex: %s" % ids)
check([e["elType"] for e in DATA] == ["container", "container"], "top level must be hero + calculator containers")
check([c.get("widgetType") for c in calc["elements"]] == ["shortcode", "form"], "calculator container must hold shortcode + form")
check(shortcode["settings"]["shortcode"] == "[lp_package_calculator]" and shortcode["settings"]["_element_cache"] == "no", "shortcode widget")

S = form["settings"]
cids = [f["custom_id"] for f in S["form_fields"]]
check(cids == SPEC_CUSTOM_IDS, "custom_ids differ from the spec list: %s" % cids)
fids = [f["_id"] for f in S["form_fields"]]
check(len(fids) == len(set(fids)) and all(re.fullmatch(r"[0-9a-f]{7}", i) for i in fids), "field _ids must be unique 7-hex")
check(not (set(fids) & set(ids)), "a field _id equals an element id")
check(all(re.fullmatch(r"\w+", c) for c in cids), "custom_ids must match \\w+ (Elementor's [field id] regex)")
byid = {f["custom_id"]: f for f in S["form_fields"]}
for cid, ftype, label, extra in VISIBLE:
    f = byid[cid]
    check(f["field_type"] == ftype and f["field_label"] == label, "field %s type/label" % cid)
for cid in ("name", "company", "email", "phone"):
    check(byid[cid].get("required") == "true" and byid[cid].get("width") == "50" and byid[cid].get("width_mobile") == "100", "field %s required/width" % cid)
check(byid["phone"].get("ccfef-country-code-default") == "ae", "phone country code")
check(byid["message"].get("rows") == 3 and "required" not in byid["message"], "message rows/optional")
check(byid["hp"]["field_type"] == "honeypot", "honeypot")
for cid in cids[6:]:
    check(byid[cid]["field_type"] == "hidden", "%s must be hidden" % cid)
check(byid["lpc_version"].get("field_value") == "1", "lpc_version value")
check(byid["lead_source"].get("field_value") == "Website", "lead_source value")
check(byid["field_lead_form"].get("field_value") == LEAD_FORM, "field_lead_form value")
for cid, want in SPEC_DYN.items():
    check(json.dumps(byid[cid]["__dynamic__"], separators=(",", ":")) == want, "__dynamic__ of %s" % cid)
for p in ("utm_medium", "utm_campaign", "utm_term", "utm_keyword"):
    check(json.dumps(byid[p]["__dynamic__"], separators=(",", ":")) == SPEC_DYN["utm_source"].replace("utm_source", p), "__dynamic__ of %s" % p)
check(sorted(c for c in cids if "__dynamic__" in byid[c]) == sorted(DYN), "only the tracking fields are dynamic")
check({k[len(Z):]: v for k, v in S.items() if k.startswith(Z)} == SPEC_ZOHO, "Zoho mapping differs from the spec")
check(S["submit_actions"] == ["save-to-database", "email", "zoho_Ledgers_Plus"], "submit_actions")
for k, v in {"form_name": FORM_NAME, "_css_classes": "lp-calc-form", "_element_cache": "no", "mark_required": "yes",
             "button_text": "Email me my quotation", "button_size": "md", "button_width": "100", "email_to": "info@ledgerplus.ae",
             "email_from": "no-reply@ledgerplus.ae", "email_from_name": "Ledger Plus UAE website", "email_reply_to": "email",
             "email_content_type": "html", "custom_messages": "yes"}.items():
    check(S.get(k) == v, "form setting %s" % k)
check(S["email_subject"] == 'Package calculator lead: [field id="company"] ([field id="quote_ref"])', "email_subject")
for k, v in MESSAGES.items():
    check(S[k] == v, k)
for key in [k for k in S if k.startswith(Z)] + ["email_subject", "email_content"]:
    for m in re.findall(r'\[field id="([^"]+)"\]', S[key]):
        check(m in byid, "%s references unknown field %s" % (key, m))
ec = S["email_content"]
for need in ("#041950", "border-bottom:4px solid #0f5cd8", "color:#8fb8ff", "Ledger Plus UAE", "New package calculator lead",
             "background:#f6f8fd", "border:1px solid #dde5f4"):
    check(need in ec, "email_content lacks %s" % need)
for cid in ("name", "company", "email", "phone", "message", "quote_ref", "quote_total", "quote_summary", "field_page_title",
            "page_url", "lead_source", "field_lead_form", "utm_source", "utm_medium", "utm_campaign", "utm_term", "utm_keyword"):
    check(f_(cid) in ec, "email_content lacks %s" % cid)
check(re.search(r'white-space:pre-wrap;">\[field id="quote_summary"\]</td>', ec) is not None, "quote_summary must sit in a pre-wrap cell")
check("\n" not in ec and "<script" not in ec.lower(), "email_content must be one line without scripts")
check(ec.count("<table") == ec.count("</table>") == 2 and ec.count("<tr>") == ec.count("</tr>"), "email_content table balance")
hs = hero["settings"]
check(hs["background_background"] == "gradient" and hs["background_color"] == NAVY and hs["background_color_b"] == NAVY_2, "hero gradient")
check(hs["padding"]["bottom"] == 120, "hero bottom padding")
cs = calc["settings"]
check(cs["boxed_width"]["size"] == 1160 and cs["content_width"] == "boxed", "calculator boxed 1160")
for sfx, v in OVERLAP.items():
    check(cs["margin" + sfx]["top"] == -v and ("--lpcp-overlap:%dpx" % v) in cs["custom_css"], "overlap %s" % sfx)
check([w["settings"]["title"] for w in hero["elements"][:2]] == [esc(T["eyebrow"]), esc(T["h1"])], "hero headings")
check(T["eyebrow"].upper() == "LEDGER PLUS UAE · PACKAGE CALCULATOR", "eyebrow text")

body = {"title": TITLE, "status": "draft", "post_type": "page", "template_type": "wp-page",
        "page_template": "elementor_header_footer", "slug": SLUG, "data": DATA}
if len(sys.argv) > 1:
    body["id"] = int(sys.argv[1])
raw = json.dumps(body, ensure_ascii=False, separators=(",", ":"))
check(json.loads(raw) == body, "page.json does not round-trip")
check(json.loads(enc(DATA)) == DATA, "enc(data) does not round-trip")
if problems:
    sys.stderr.write("build_page.py: %d check(s) failed:\n  - %s\n" % (len(problems), "\n  - ".join(problems)))
    sys.exit(1)

open(os.path.join(OUT, "page.json"), "w").write(raw)
open(os.path.join(OUT, "elementor-data.json"), "w").write(enc(DATA))

print("page.json %d bytes, %d elements (%s), %d form fields, all checks passed" % (
    len(raw.encode()), len(ids), ", ".join("%s:%s" % (e.get("widgetType") or e["elType"], e["id"]) for e in els), len(cids)))
print("%-19s %-9s %-7s %-44s %-12s %s" % ("custom_id", "type", "_id", "label", "width/mob", "required / value"))
for f in S["form_fields"]:
    w = "%s/%s" % (f.get("width", ""), f.get("width_mobile", "")) if f.get("width") else ""
    v = "required" if f.get("required") else ""
    if f.get("field_value"):
        v = "value %r" % f["field_value"]
    if f.get("__dynamic__"):
        v = "dynamic " + re.search(r'name="([^"]+)"', f["__dynamic__"]["field_value"]).group(1) + (
            " " + re.search(r"%22param_name%22%3A%22(\w+)%22", f["__dynamic__"]["field_value"]).group(1)
            if "request-arg" in f["__dynamic__"]["field_value"] else "")
    if f.get("rows"):
        v = "rows %d, optional" % f["rows"]
    if f.get("ccfef-country-code-default"):
        v += ", country ae"
    print("%-19s %-9s %-7s %-44s %-12s %s" % (f["custom_id"], f["field_type"], f["_id"], f.get("field_label", ""), w, v))
