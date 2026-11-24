"""Ledger Plus E-Invoicing Starter purchase page (draft) for ledgerplus.ae.

Copy: client's plan "Ledger-Plus-purchase-page-content-v4" (plan.md, pandoc of the .docx), sections 1-9.
VAT confirmed by the agency: AED 1,999 includes 5% VAT. Other unconfirmed points stay in [square brackets].
Buy buttons add product 890 (E-Invoicing Starter Package) to the WooCommerce cart and open checkout.
Writes out/page.json (save-page body) and out/content.json.
"""
import hashlib, html as H, json, os, sys

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, "out")
os.makedirs(OUT, exist_ok=True)
SITE = "https://ledgerplus.ae"
U = SITE + "/wp-content/uploads/"
PRODUCT_ID = 890
BUY = SITE + "/checkout/?add-to-cart=%d" % PRODUCT_ID
DEMO = "https://setup.ledgerplus.ae/accounting-software-in-uae/"  # current demo booking page (plan: "Is the free demo ready?")
PRICING = SITE + "/pricing/"
TEL = "tel:+971503420832"
WA = "https://wa.me/971503420832"
PHONE = "+971 50 342 0832"
IMG = {
    "office": (860, U + "2026/11/Modern-office-interior-in-Dubai-Photo-by-Hammer-Group-on-Unsplash.jpg", "Modern office interior in Dubai"),
    "dashboard": (849, U + "2026/11/Ledger-Plus-dashboard.png", "Ledger Plus dashboard"),
}
ASPECT = {"office": (16, 9), "dashboard": (1461, 1077)}
ORDER_ANCHOR = "order"
FAQ_ANCHOR = "faq"


def enc(o):
    return json.dumps(o, ensure_ascii=True, separators=(",", ":")).replace("/", "\\/")


# ------------------------------------------------------------------ copy (plan v4)
T = {
    "banner": "E-invoicing becomes mandatory for UAE businesses with revenue under AED 50 million on 1 July 2027. Get ready today.",
    "pill": "E-Invoicing Starter package",
    "h1": "Get your business ready for e-invoicing for AED 1,999",
    "hero_p": "Software, setup and data migration in one package. Buy online in about 2 minutes. Our team does the rest.",
    "buy": "Buy now for AED 1,999",
    "demo": "Try the free demo",
    "points": ["No integration costs", "Unlimited users", "Free onboarding"],
    "offers": [
        ("One price. Everything included.", "AED 1,999 covers the software, 1,000 e-invoices, data migration and onboarding. No hidden costs.", "tag"),
        ("Refer one company. Get AED 999 back.", "When a company you refer buys Ledger Plus, you get AED 999 cash back.", "gift"),
        ("We set it up for you.", "Our team moves your data and shows you how to use Ledger Plus. You do not need an IT team.", "tool"),
    ],
    "get_h2": "Everything in your AED 1,999 package",
    "get": [
        ("1,000 e-invoices", "Send up to 1,000 e-invoices to your customers.", "invoice"),
        ("Ledger Plus software", "Create invoices and keep your accounts in one place.", "app"),
        ("Data migration", "We move your existing data into Ledger Plus for you.", "move"),
        ("No integration costs", "You pay nothing extra to get connected.", "plug"),
        ("Unlimited users", "Add your whole team and your accountant at no extra cost.", "users"),
        ("Free onboarding", "Our team walks you through the software, step by step.", "cap"),
    ],
    "why_h2": "The e-invoicing deadline is close",
    "why": [
        ("1 July 2027", "From 1 July 2027, UAE businesses with annual revenue under AED 50 million must issue and receive business invoices as e-invoices. Larger businesses start on 1 January 2027."),
        ("31 March 2027", "These businesses must appoint an e-invoicing provider accredited by the Ministry of Finance by 31 March 2027."),
        ("PDF & Word", "For invoices to other businesses, a PDF or Word file will no longer count. The invoice must be a structured e-invoice sent through an accredited provider."),
        ("AED 5,000 / month", "Businesses that miss the deadline face fines of AED 5,000 a month (up to AED 50,000 in any 12 months), plus AED 100 for each invoice not issued as an e-invoice (up to AED 5,000 a month)."),
    ],
    "why_close": "Do not wait for the deadline. Buy today and we start your setup.",
    "why_source": "Source: UAE Ministry of Finance decisions on the e-invoicing system, checked 24 November 2026.",
    "how_h2": "Start in three simple steps",
    "how": [
        ("Fill in your details", "It takes about 2 minutes."),
        ("Pay securely by card", "You get your login and tax invoice by email [straight away: to confirm]."),
        ("We set you up", "We move your data and book your free onboarding. Then you start sending e-invoices."),
    ],
    "ref_h2": "Refer one company. Get AED 999 cash back.",
    "ref_steps": [
        "Buy Ledger Plus for AED 1,999. We email you your personal referral code.",
        "Share your code with a customer, a supplier or a friend who runs a business.",
        "They buy Ledger Plus for AED 1,999 and enter your code in the order form.",
        "You get AED 999 cash back.",
    ],
    "ref_example": "You pay AED 1,999. One company you refer buys. You get AED 999 back. Your software has cost you AED 1,000.",
    "ref_condition": "The reward is given only after the company you refer has paid for its licence.",
    "ref_small": ["Cash back is paid by [payment method] within [X days] of the referred company’s payment.",
                  "[Reward for each referral, or one time only: to confirm]"],
    "ref_line": "Were you referred by a Ledger Plus customer? Enter their code in the order form below.",
    "order_h2": "Complete your order",
    "order_p": "It takes about 2 minutes.",
    "order_fields": ["Full name", "Company name", "Work email", "Mobile number", "Trade licence number", "Referral code (optional)"],
    "order_ref": "Did a Ledger Plus customer refer you? Enter their code so they receive their reward. Your price stays AED 1,999.",
    "order_sum": [("E-Invoicing Starter package", "AED 1,999"), ("VAT (5%)", "Included"), ("Total today", "AED 1,999")],
    "order_after": ["You pay by card on the secure payment page of Geidea.", "We never see or store your card details.",
                    "Your login and tax invoice arrive by email [right after payment: to confirm]."],
    "help": "Need help? Call or WhatsApp",
    "faq_h2": "Common questions",
    "faq": [
        ("What do I get for AED 1,999?", "The Ledger Plus software, 1,000 e-invoices, data migration, unlimited users and free onboarding."),
        ("Is it a one-time payment?", "[One-time or yearly: to confirm]"),
        ("Is VAT included?", "Yes. AED 1,999 includes 5% VAT. There is nothing extra to pay at checkout."),
        ("How fast can I start?", "Your account is created [right after payment: to confirm]. Our team then contacts you [within X working days] to move your data and book onboarding."),
        ("Do I need an IT team or an accountant?", "No. We set everything up with you. You can also give your accountant access for free."),
        ("Can I try it before I buy?", "Yes. Use the free demo with sample data. No payment is needed."),
        ("How does the referral reward work?", "After you buy, you get a referral code. When a company buys Ledger Plus with your code, you get AED 999 cash back."),
        ("When do I get my cash back?", "After the company you referred has paid. We pay it by [payment method] within [X days]."),
        ("What if I need more than 1,000 e-invoices?", "[To confirm]"),
        ("I also need bookkeeping and tax filing. What should I choose?",
         'Look at our <a href="%s">Compliance plans</a>. They include a dedicated accountant and start at [AED 5,999 a year: to confirm].' % PRICING),
        ("Can I get a refund?", "[Refund policy: to confirm]"),
    ],
    "final_h2": "Get e-invoicing ready today",
    "final_p": "AED 1,999. Refer one company that buys and get AED 999 cash back.",
    "final_contact": "Questions? Call or WhatsApp",
}
assert len(T["faq"]) == 11 and len(T["get"]) == 6 and len(T["why"]) == 4

# ------------------------------------------------------------------ element helpers
_used = set()


def uid(seed):
    i = 0
    while True:
        h = hashlib.sha1(("lp3|%s|%d" % (seed, i)).encode()).hexdigest()[:8]
        if h not in _used and not h.isdigit():
            _used.add(h)
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


def W(seed, wtype, **s):
    return {"id": uid(seed), "elType": "widget", "settings": s, "elements": [], "widgetType": wtype}


def C(seed, children, top=False, **s):
    base = {"content_width": "boxed" if top else "full", "flex_direction": "column", "flex_gap": gap(0), "padding": dim(0)}
    if top:
        base["boxed_width"] = {"unit": "px", "size": 1200}
    base.update(s)
    return {"id": uid(seed), "elType": "container", "settings": base, "elements": children, "isInner": not top}


def esc(t):
    return H.escape(t, quote=False)


def heading(seed, text, tag="h2", cls=""):
    return W(seed, "heading", title=esc(text), header_size=tag, _css_classes=cls)


def text(seed, ps, cls="lp3-text", raw=False):
    return W(seed, "text-editor", editor="".join("<p>%s</p>" % (p if raw else esc(p)) for p in ps), _css_classes=cls)


def checklist(seed, items, cls="lp3-check"):
    return W(seed, "text-editor", editor="<ul>%s</ul>" % "".join("<li>%s</li>" % esc(i) for i in items), _css_classes=cls)


def link(url, ext=False):
    return {"url": url, "is_external": "on" if ext else "", "nofollow": "", "custom_attributes": ""}


def button(seed, label, url, cls="lp3-btn", ext=False, ghost=False):
    s = dict(text=label, link=link(url, ext), size="md", _css_classes=cls + (" lp3-ghost" if ghost else ""),
             border_radius=dim(12), text_padding=dim(17, 26, 17, 26))
    if ghost:
        s.update(background_color="rgba(255,255,255,0)", button_text_color="#ffffff", button_background_hover_color="#ffffff",
                 hover_color="#041950", border_border="solid", border_width=dim(1.5), border_color="rgba(255,255,255,0.55)")
    else:
        s.update(background_color="#0f5cd8", button_text_color="#ffffff", button_background_hover_color="#041950", hover_color="#ffffff")
    return W(seed, "button", **s)


def image(seed, key, cls="lp3-shot", size="large"):
    iid, url, alt = IMG[key]
    return W(seed, "image", image={"url": url, "id": iid, "alt": alt, "source": "library", "size": ""}, image_size=size,
             _css_classes=cls)


def row(seed, children, gap_px=64, tablet_dir="column", align="center", **s):
    return C(seed, children, flex_direction="row", flex_direction_tablet=tablet_dir, flex_gap=gap(gap_px),
             flex_gap_tablet=gap(36), flex_gap_mobile=gap(28), flex_align_items=align, flex_align_items_tablet="stretch", **s)


def col(seed, children, w=50, g=18, **s):
    return C(seed, children, width={"unit": "%", "size": w}, width_tablet={"unit": "%", "size": 100}, flex_gap=gap(g), **s)


def section(seed, children, cls, pad=(96, 24, 96, 24), pad_t=(80, 24, 80, 24), pad_m=(64, 16, 64, 16), title="", **s):
    return C(seed, children, top=True, padding=dim(*pad), padding_tablet=dim(*pad_t), padding_mobile=dim(*pad_m),
             css_classes="lp3 " + cls, _title=title, **s)


def grid(seed, children, cls, title=""):
    """A plain container; the CSS class turns it into a responsive grid."""
    return C(seed, children, css_classes=cls, _title=title)


def card(seed, children, cls, g=12, title="", **s):
    return C(seed, children, css_classes=cls, flex_gap=gap(g), _title=title, **s)


# ------------------------------------------------------------------ icons (CSS backgrounds, white strokes)
def svg_url(paths, stroke="white", sw=2):
    s = ("<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%s' stroke-width='%s' "
         "stroke-linecap='round' stroke-linejoin='round'>%s</svg>") % (stroke, sw, paths)
    return 'url("data:image/svg+xml,' + s.replace("<", "%3C").replace(">", "%3E").replace("#", "%23").replace('"', "'") + '")'


ICONS = {
    "check": "<path d='M5 12.5l4.2 4.2L19 7'/>",
    "tag": "<path d='M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z'/><circle cx='7.5' cy='7.5' r='1.5'/>",
    "gift": "<rect x='3' y='8' width='18' height='4' rx='1'/><path d='M12 8v13M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7'/><path d='M7.5 8a2.5 2.5 0 0 1 0-5C11 3 12 8 12 8s1-5 4.5-5a2.5 2.5 0 0 1 0 5'/>",
    "tool": "<path d='M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z'/>",
    "invoice": "<path d='M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z'/><path d='M14 2v6h6M8 13h8M8 17h5'/>",
    "app": "<rect x='3' y='3' width='18' height='14' rx='2'/><path d='M8 21h8M12 17v4M7 12l3-3 2 2 4-4'/>",
    "move": "<path d='M4 7h13l-3-3M20 17H7l3 3'/>",
    "plug": "<path d='M9 2v6M15 2v6M6 8h12v4a6 6 0 0 1-12 0zM12 18v4'/>",
    "users": "<path d='M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2'/><circle cx='9' cy='7' r='4'/><path d='M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75'/>",
    "cap": "<path d='M22 10L12 5 2 10l10 5 10-5z'/><path d='M6 12v5c3 2 9 2 12 0v-5'/>",
    "clock": "<circle cx='12' cy='12' r='9'/><path d='M12 7v5l3 2'/>",
    "lock": "<rect x='4' y='11' width='16' height='10' rx='2'/><path d='M8 11V7a4 4 0 0 1 8 0v4'/>",
    "phone": "<path d='M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z'/>",
}

# ------------------------------------------------------------------ CSS
NAVY, BLUE, INK, MUTED, LINE, TINT, SURF = "#041950", "#0f5cd8", "#030922", "#5b6684", "#dde5f4", "#eaf1fd", "#f6f8fd"
CSS = """
.lp3{font-family:'DM Sans',system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;color:#2c3651}
.lp3 .elementor-heading-title{font-family:inherit}
.elementor .lp3 .elementor-widget-text-editor,.elementor .lp3 .elementor-button{font-family:inherit}
.lp3 .lp3-eyebrow .elementor-heading-title{display:inline-flex;align-items:center;gap:8px;margin:0;font-size:12px;line-height:1.4;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#0f5cd8}
.lp3 .lp3-eyebrow .elementor-heading-title::before{content:'';width:18px;height:2px;border-radius:2px;background:currentColor}
.lp3 .lp3-h2 .elementor-heading-title{margin:0;font-size:40px;line-height:1.15;font-weight:700;letter-spacing:-.6px;color:#041950}
.lp3 .lp3-h3 .elementor-heading-title{margin:0;font-size:19px;line-height:1.35;font-weight:700;letter-spacing:-.2px;color:#041950}
.lp3 .lp3-text{font-size:17px;line-height:1.7;color:#4a5578}
.lp3 .lp3-text p{margin:0 0 12px}.lp3 .lp3-text p:last-child{margin-bottom:0}
.lp3 .lp3-text a{color:#0f5cd8;font-weight:600;text-decoration:underline;text-underline-offset:3px}
.lp3 .lp3-small{font-size:14px;line-height:1.6;color:#5b6684}
.lp3 .lp3-check ul{list-style:none;margin:0;padding:0;display:grid;gap:12px}
.lp3 .lp3-check li{position:relative;margin:0;padding-left:32px;font-size:16px;line-height:1.5;font-weight:500;color:#1d2640}
.lp3 .lp3-check li::before{content:'';position:absolute;left:0;top:1px;width:22px;height:22px;border-radius:50%;background:#0f5cd8 I_CHECK center/12px no-repeat}
.lp3 .lp3-inline ul{display:flex;flex-wrap:wrap;gap:10px 26px}
.lp3 .lp3-btns{flex-wrap:wrap}
.lp3 .lp3-btn .elementor-button{display:inline-flex;align-items:center;justify-content:center;gap:10px;border-radius:12px;font-size:15.5px;line-height:1.2;font-weight:700;letter-spacing:.01em;box-shadow:0 16px 30px -16px rgba(15,92,216,.85);transition:background-color .25s,color .25s,border-color .25s,transform .25s}
.lp3 .lp3-btn .elementor-button:hover,.lp3 .lp3-btn .elementor-button:focus-visible{transform:translateY(-2px)}
.lp3 .lp3-btn .elementor-button:focus-visible{outline:3px solid #8fb8ff;outline-offset:3px}
.lp3 .lp3-btn:not(.lp3-ghost) .elementor-button-text::after{content:'\\2192';margin-left:10px;display:inline-block;transition:transform .25s}
.lp3 .lp3-btn:not(.lp3-ghost) .elementor-button:hover .elementor-button-text::after{transform:translateX(3px)}
.lp3 .lp3-ghost .elementor-button{box-shadow:none}
.lp3 .lp3-ghost-dark .elementor-button{color:#041950;border-color:#bccbe6}
.lp3 .lp3-ghost-dark .elementor-button:hover,.lp3 .lp3-ghost-dark .elementor-button:focus-visible{background:#041950;border-color:#041950;color:#fff}
.lp3 .lp3-ico{display:block;width:48px;height:48px;border-radius:14px;background:#0f5cd8 center/24px no-repeat;flex:none;box-shadow:0 14px 26px -16px rgba(15,92,216,.9)}
I_CLASSES
.lp3-center{text-align:center}.lp3-center .lp3-eyebrow .elementor-heading-title{justify-content:center}
.lp3 .lp3-center{align-items:center}
/* banner */
.lp3-banner{background:#030922;border-bottom:1px solid rgba(143,184,255,.18)}
.lp3-banner .lp3-bannertext .elementor-heading-title{display:flex;align-items:center;justify-content:center;gap:10px;margin:0;font-size:14px;line-height:1.45;font-weight:600;color:#fff;text-align:center}
.lp3-banner .lp3-bannertext .elementor-heading-title::before{content:'';flex:none;width:22px;height:22px;border-radius:50%;background:rgba(143,184,255,.18) I_CLOCK center/14px no-repeat}
/* hero */
.lp3-hero{position:relative;overflow:hidden}
.lp3-hero::after{content:'';position:absolute;right:-160px;top:-200px;width:620px;height:620px;border-radius:50%;background:radial-gradient(closest-side,rgba(15,92,216,.40),rgba(15,92,216,0));pointer-events:none;z-index:1}
.lp3-hero > .e-con-inner{position:relative;z-index:2}
.lp3 .lp3-pill .elementor-heading-title{display:inline-flex;align-items:center;gap:10px;margin:0;padding:7px 14px 7px 11px;border-radius:999px;background:rgba(15,92,216,.22);border:1px solid rgba(143,184,255,.45);color:#d6e4ff;font-size:12.5px;line-height:1.3;font-weight:700;letter-spacing:.06em;text-transform:uppercase}
.lp3 .lp3-pill .elementor-heading-title::before{content:'';width:8px;height:8px;border-radius:50%;background:#4ade80}
.lp3 .lp3-h1 .elementor-heading-title{margin:0;font-size:54px;line-height:1.08;font-weight:700;letter-spacing:-1.3px;color:#fff}
.lp3-hero .lp3-text{color:rgba(255,255,255,.82);font-size:18.5px}
.lp3-hero .lp3-check li{color:#fff;font-weight:600}
.lp3-hero .lp3-check li::before{background-color:#22c55e}
/* price card */
.lp3 .lp3-pricecard{background:#fff;border-radius:24px;box-shadow:0 50px 90px -40px rgba(0,0,0,.65);overflow:hidden}
.lp3 .lp3-pricetop{background:linear-gradient(135deg,#eaf1fd 0%,#f6f8fd 100%);border-bottom:1px solid #dde5f4}
.lp3 .lp3-pricename .elementor-heading-title{margin:0;font-size:13px;line-height:1.4;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#0f5cd8}
.lp3 .lp3-price .elementor-heading-title{margin:0;font-size:52px;line-height:1;font-weight:700;letter-spacing:-1.4px;color:#041950}
.lp3 .lp3-price .elementor-heading-title small{font-size:.42em;letter-spacing:0;font-weight:700;margin-right:6px;vertical-align:.55em;color:#0f5cd8}
.lp3 .lp3-pricenote .elementor-heading-title{margin:0;font-size:14px;line-height:1.5;font-weight:500;color:#5b6684}
.lp3-hero .lp3-pricecard .lp3-check li{font-size:15px;font-weight:500;color:#1d2640}
.lp3-hero .lp3-pricecard .lp3-check li::before{background-color:#0f5cd8}
.lp3 .lp3-pricecard .lp3-btn,.lp3 .lp3-pricecard .lp3-btn .elementor-button-wrapper,.lp3 .lp3-pricecard .lp3-btn .elementor-button{width:100%}
.lp3 .lp3-secure .elementor-heading-title{display:flex;align-items:center;justify-content:center;gap:8px;margin:0;font-size:13px;line-height:1.5;font-weight:500;color:#5b6684;text-align:center}
.lp3 .lp3-secure .elementor-heading-title::before{content:'';flex:none;width:16px;height:16px;background:I_LOCK_MUTED center/16px no-repeat}
/* cards */
.lp3-surf{background:#f6f8fd}
.lp3 .lp3-grid3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:24px}
.lp3 .lp3-grid4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:20px}
.lp3 .lp3-grid2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
.lp3 .lp3-card{background:#fff;border:1px solid #dde5f4;border-radius:20px;transition:border-color .25s,box-shadow .25s,transform .25s}
.lp3 .lp3-card:hover{border-color:#bcd0f3;box-shadow:0 26px 48px -34px rgba(4,25,80,.55)}
.lp3 .lp3-offers{margin-top:-64px;position:relative;z-index:3}
.lp3 .lp3-offer{box-shadow:0 30px 60px -44px rgba(4,25,80,.6)}
.lp3 .lp3-offer .lp3-text,.lp3 .lp3-item .lp3-text{font-size:15.5px;line-height:1.6}
.lp3 .lp3-item{position:relative}
.lp3 .lp3-item>.elementor-widget-html{position:absolute;left:20px;top:20px;width:auto;margin:0}
.lp3 .lp3-item .lp3-ico{width:42px;height:42px;border-radius:12px;background-size:21px}
.lp3 .lp3-shot{padding:10px;background:#fff;border:1px solid #dde5f4;border-radius:20px;box-shadow:0 40px 80px -48px rgba(4,25,80,.55)}
.lp3 .lp3-shot img{width:100%;height:auto;display:block;border-radius:12px}
/* deadline */
.lp3-dark{background:#041950;position:relative;overflow:hidden}
.lp3-dark::before{content:'';position:absolute;left:-220px;bottom:-260px;width:640px;height:640px;border-radius:50%;background:radial-gradient(closest-side,rgba(15,92,216,.40),rgba(15,92,216,0));pointer-events:none}
.lp3-dark > .e-con-inner{position:relative;z-index:1}
.lp3-dark .lp3-h2 .elementor-heading-title,.lp3-dark .lp3-h3 .elementor-heading-title{color:#fff}
.lp3-dark .lp3-text{color:rgba(255,255,255,.8)}
.lp3-dark .lp3-eyebrow .elementor-heading-title{color:#8fb8ff}
.lp3 .lp3-fact{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.18);border-radius:20px}
.lp3 .lp3-factnum .elementor-heading-title{margin:0;font-size:24px;line-height:1.15;font-weight:700;letter-spacing:-.5px;color:#8fb8ff}
.lp3 .lp3-fact .lp3-text{font-size:15.5px;line-height:1.6;color:rgba(255,255,255,.86)}
.lp3 .lp3-fact-alert{background:rgba(239,68,68,.12);border-color:rgba(252,165,165,.4)}
.lp3 .lp3-fact-alert .lp3-factnum .elementor-heading-title{color:#fca5a5}
.lp3 .lp3-closing .elementor-heading-title{margin:0;font-size:20px;line-height:1.45;font-weight:700;color:#fff}
.lp3 .lp3-source .elementor-heading-title{margin:0;font-size:13px;line-height:1.5;font-weight:400;color:rgba(255,255,255,.6)}
/* steps */
.lp3 .lp3-num .elementor-heading-title{display:flex;align-items:center;justify-content:center;width:46px;height:46px;margin:0;border-radius:14px;background:#041950;color:#fff;font-size:16px;font-weight:700;letter-spacing:.02em}
.lp3 .lp3-step{position:relative}
.lp3 .lp3-steps .lp3-step:not(:last-child)::after{content:'';position:absolute;top:50px;right:-25px;width:26px;height:2px;background:#bcd0f3}
/* referral */
.lp3 .lp3-olist ol{list-style:none;counter-reset:lp3;margin:0;padding:0;display:grid;gap:14px}
.lp3 .lp3-olist li{counter-increment:lp3;position:relative;margin:0;padding:3px 0 0 46px;min-height:32px;font-size:16.5px;line-height:1.55;font-weight:500;color:#1d2640}
.lp3 .lp3-olist li::before{content:counter(lp3);position:absolute;left:0;top:0;width:32px;height:32px;border-radius:10px;background:#eaf1fd;color:#0f5cd8;font-size:14px;font-weight:700;display:flex;align-items:center;justify-content:center}
.lp3 .lp3-example{background:linear-gradient(150deg,#030922 0%,#041950 55%,#0b3aa0 100%);border-radius:24px;box-shadow:0 50px 90px -50px rgba(4,25,80,.9)}
.lp3 .lp3-example .lp3-eyebrow .elementor-heading-title{color:#8fb8ff}
.lp3 .lp3-math{border-radius:16px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.16)}
.lp3 .lp3-mathrow,.lp3 .lp3-sumrow{display:grid !important;grid-template-columns:minmax(0,1fr) auto;align-items:center;column-gap:16px;row-gap:4px}
.lp3 .lp3-mathrow{border-bottom:1px solid rgba(255,255,255,.14)}
.lp3 .lp3-mathrow:last-child{border-bottom:0}
.lp3 .lp3-mathl .elementor-heading-title{margin:0;font-size:15.5px;line-height:1.4;font-weight:500;color:rgba(255,255,255,.82)}
.lp3 .lp3-mathr .elementor-heading-title{margin:0;font-size:18px;line-height:1.3;font-weight:700;color:#fff;white-space:nowrap}
.lp3 .lp3-mathrow-plus .lp3-mathr .elementor-heading-title{color:#4ade80}
.lp3 .lp3-mathrow-total{background:rgba(255,255,255,.1);border-radius:0 0 16px 16px}
.lp3 .lp3-mathrow-total .lp3-mathl .elementor-heading-title{color:#fff;font-weight:700}
.lp3 .lp3-mathrow-total .lp3-mathr .elementor-heading-title{font-size:24px}
.lp3 .lp3-example .lp3-text{color:rgba(255,255,255,.8);font-size:15px;line-height:1.6}
.lp3 .lp3-callout{background:#eaf1fd;border-left:4px solid #0f5cd8;border-radius:0 16px 16px 0}
.lp3 .lp3-callout .lp3-text{color:#1d2640;font-size:16px;font-weight:500}
/* order */
.lp3 .lp3-ordercard{background:#fff;border:1px solid #dde5f4;border-radius:24px;box-shadow:0 40px 80px -56px rgba(4,25,80,.6);scroll-margin-top:110px}
.lp3 .lp3-fields ul{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.lp3 .lp3-fields li{margin:0;padding:12px 14px 12px 40px;position:relative;border:1px solid #dde5f4;border-radius:12px;background:#f6f8fd;font-size:15px;line-height:1.4;font-weight:600;color:#1d2640}
.lp3 .lp3-fields li::before{content:'';position:absolute;left:14px;top:50%;width:16px;height:16px;margin-top:-8px;border-radius:50%;background:#0f5cd8 I_CHECK center/9px no-repeat}
.lp3 .lp3-summary{background:#f6f8fd;border:1px solid #dde5f4;border-radius:20px}
.lp3 .lp3-sumrow{border-bottom:1px dashed #cfdaee}
.lp3 .lp3-sumrow:last-child{border-bottom:0}
.lp3 .lp3-suml .elementor-heading-title{margin:0;font-size:15.5px;line-height:1.4;font-weight:500;color:#4a5578}
.lp3 .lp3-sumr .elementor-heading-title{margin:0;font-size:16px;line-height:1.4;font-weight:700;color:#041950;white-space:nowrap;text-align:right}
.lp3 .lp3-sumrow-total .lp3-suml .elementor-heading-title{font-weight:700;color:#041950;font-size:17px}
.lp3 .lp3-sumrow-total .lp3-sumr .elementor-heading-title{font-size:24px;color:#0f5cd8}
.lp3 .lp3-summary .lp3-btn,.lp3 .lp3-summary .lp3-btn .elementor-button-wrapper,.lp3 .lp3-summary .lp3-btn .elementor-button{width:100%}
.lp3 .lp3-after ul{list-style:none;margin:0;padding:0;display:grid;gap:8px}
.lp3 .lp3-after li{position:relative;margin:0;padding-left:26px;font-size:14px;line-height:1.5;color:#4a5578}
.lp3 .lp3-after li::before{content:'';position:absolute;left:0;top:2px;width:16px;height:16px;background:I_LOCK_BLUE center/15px no-repeat}
.lp3 .lp3-help .elementor-heading-title,.lp3 .lp3-help p{margin:0;font-size:15px;line-height:1.5;font-weight:500;color:#1d2640}
.lp3 .lp3-help a{color:#0f5cd8;font-weight:700;white-space:nowrap}
.lp3 .lp3-help a:hover{text-decoration:underline}
/* faq */
.lp3-faq .e-n-accordion-item{border-bottom:1px solid #e3e8f2}
.lp3-faq .e-n-accordion-item > .e-con{border:0}
.lp3-faq .e-n-accordion-item:last-child{border-bottom:0}
.lp3-faq .e-n-accordion-item .elementor-heading-title{font-family:'DM Sans',sans-serif;font-size:15.5px;font-weight:400;line-height:26px;color:#4a5578;margin:0}
.lp3-faq .e-n-accordion-item .elementor-heading-title a{color:#0f5cd8;font-weight:600;text-decoration:underline;text-underline-offset:3px}
.lp3-faq .e-n-accordion-item-title{gap:20px}
.lp3-faq .e-n-accordion-item-title-text{overflow-wrap:anywhere}
.lp3-faq .e-n-accordion-item-title-icon{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:#eaf1fd;flex:none;transition:background-color .25s}
.lp3-faq .e-n-accordion-item-title:hover .e-n-accordion-item-title-icon,.lp3-faq .e-n-accordion-item[open] > .e-n-accordion-item-title .e-n-accordion-item-title-icon{background:#0f5cd8}
@media (min-width:1025px){.lp3-faq .lp3-faqintro{position:sticky;top:110px}}
/* final */
.lp3-final{background:linear-gradient(140deg,#030922 0%,#041950 45%,#0b3aa0 100%);position:relative;overflow:hidden}
.lp3-final::before{content:'';position:absolute;right:-200px;top:-220px;width:600px;height:600px;border-radius:50%;background:radial-gradient(closest-side,rgba(143,184,255,.22),rgba(143,184,255,0));pointer-events:none}
.lp3-final > .e-con-inner{position:relative;z-index:1}
.lp3-final .lp3-h2 .elementor-heading-title{color:#fff;font-size:44px}
.lp3-final .lp3-text{color:rgba(255,255,255,.82);font-size:18.5px}
.lp3-final .lp3-help .elementor-heading-title,.lp3-final .lp3-help p{color:rgba(255,255,255,.86)}
.lp3-final .lp3-help a{color:#8fb8ff}
.lp3-final .lp3-btns,.lp3-hero .lp3-btns{gap:14px}
@media (max-width:1024px){
.lp3 .lp3-h1 .elementor-heading-title{font-size:44px}
.lp3 .lp3-h2 .elementor-heading-title,.lp3-final .lp3-h2 .elementor-heading-title{font-size:34px}
.lp3 .lp3-grid3,.lp3 .lp3-grid4{grid-template-columns:repeat(2,minmax(0,1fr))}
.lp3 .lp3-steps{grid-template-columns:minmax(0,1fr)}
.lp3 .lp3-steps .lp3-step:not(:last-child)::after{display:none}
.lp3 .lp3-offers{margin-top:-48px}
.lp3 .lp3-pricecard{max-width:560px;width:100%;align-self:center}
.elementor.elementor .lp3 .e-con>.lp3-shot{max-width:600px;width:100%;align-self:center}
.lp3 .lp3-offers{grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.lp3 .lp3-offer .lp3-h3 .elementor-heading-title{font-size:17px}
}
@media (max-width:767px){
.lp3 .lp3-h1 .elementor-heading-title{font-size:34px;letter-spacing:-.8px}
.lp3 .lp3-h2 .elementor-heading-title,.lp3-final .lp3-h2 .elementor-heading-title{font-size:28px;letter-spacing:-.4px}
.lp3 .lp3-text{font-size:16px}
.lp3-hero .lp3-text,.lp3-final .lp3-text{font-size:16.5px}
.lp3 .lp3-grid3,.lp3 .lp3-grid4,.lp3 .lp3-grid2,.lp3 .lp3-fields ul{grid-template-columns:minmax(0,1fr)}
.lp3 .lp3-price .elementor-heading-title{font-size:44px}
.lp3 .lp3-offers{margin-top:-36px;grid-template-columns:minmax(0,1fr)}
.lp3 .lp3-btn,.lp3 .lp3-btn .elementor-button-wrapper,.lp3 .lp3-btn .elementor-button{width:100%}
.lp3 .lp3-btns{flex-direction:column;align-items:stretch}
.lp3-banner .lp3-bannertext .elementor-heading-title{font-size:13px;text-align:left;justify-content:flex-start}
.lp3 .lp3-mathr .elementor-heading-title{font-size:16px}
.lp3 .lp3-mathrow-total .lp3-mathr .elementor-heading-title{font-size:20px}
.lp3 .lp3-factnum .elementor-heading-title{font-size:24px}
.lp3-faq .e-n-accordion-item-title-icon{width:28px;height:28px}
.lp3-faq .e-n-accordion-item-title{gap:14px}
}
@media (prefers-reduced-motion:reduce){.lp3 .lp3-btn .elementor-button,.lp3 .lp3-card{transition:none}}
"""
icls = "".join(".lp3 .lp3-ico-%s{background-image:%s}" % (k, svg_url(v)) for k, v in ICONS.items() if k not in ("check", "clock", "lock", "phone"))
CSS = (CSS.replace("I_CLASSES", icls).replace("I_CHECK", svg_url(ICONS["check"], sw=3)).replace("I_CLOCK", svg_url(ICONS["clock"], "#8fb8ff"))
       .replace("I_LOCK_MUTED", svg_url(ICONS["lock"], "#5b6684")).replace("I_LOCK_BLUE", svg_url(ICONS["lock"], "#0f5cd8")))
CSS = "\n".join(l.strip() for l in CSS.strip().splitlines())
styles = C("styles", [W("styleshtml", "html", html="<style>\n%s\n</style>" % CSS, _title="Purchase page styles (do not delete)")],
           top=True, padding=dim(0), _title="Purchase page styles", css_classes="lp3-styles")


def icon(seed, key):
    return W(seed, "html", html='<span class="lp3-ico lp3-ico-%s" aria-hidden="true"></span>' % key, _title="Icon: " + key)


def help_line(seed, prefix, cls="lp3-help"):
    assert prefix.endswith("Call or WhatsApp")
    html = '%s <a href="%s" target="_blank" rel="noopener">WhatsApp</a> <a href="%s">%s</a>.' % (esc(prefix[:-len("WhatsApp")].rstrip()), WA, TEL, PHONE)
    return W(seed, "text-editor", editor="<p>%s</p>" % html, _css_classes=cls)


# ------------------------------------------------------------------ 0. banner
banner = section("banner", [heading("banner_t", T["banner"], "p", "lp3-bannertext")], "lp3-banner",
                 pad=(12, 24, 12, 24), pad_t=(12, 24, 12, 24), pad_m=(10, 16, 10, 16), title="Top banner",
                 background_background="classic", background_color="#030922")

# ------------------------------------------------------------------ 1. hero (+ price card)
price_card = card("pc", [
    card("pc_top", [
        heading("pc_name", T["pill"], "p", "lp3-pricename"),
        W("pc_price", "heading", title="<small>AED</small>1,999", header_size="p", _css_classes="lp3-price"),
        heading("pc_note", "VAT included · [One-time or yearly: to confirm]", "p", "lp3-pricenote"),
    ], "lp3-pricetop", g=10, padding=dim(28, 30, 24, 30), padding_mobile=dim(24, 22, 20, 22), title="Price"),
    card("pc_body", [
        checklist("pc_list", [t for t, _, _ in T["get"]]),
        button("pc_btn", T["buy"], BUY),
        heading("pc_secure", "Secure card payment · Price includes 5% VAT", "p", "lp3-secure"),
    ], "", g=22, padding=dim(26, 30, 28, 30), padding_mobile=dim(22, 22, 24, 22), title="Included + buy"),
], "lp3-pricecard", g=0, width={"unit": "%", "size": 40}, width_tablet={"unit": "%", "size": 100}, title="Price card")

hero = section("hero", [
    row("hero_row", [
        col("hero_l", [
            heading("hero_pill", T["pill"], "p", "lp3-pill"),
            heading("hero_h1", T["h1"], "h1", "lp3-h1"),
            text("hero_p", [T["hero_p"]]),
            C("hero_btns", [button("hero_buy", T["buy"], BUY), button("hero_demo", T["demo"], DEMO, ext=True, ghost=True)],
              flex_direction="row", flex_gap=gap(14), css_classes="lp3-btns", margin=dim(8, 0, 0, 0), _title="Buttons"),
            checklist("hero_pts", T["points"], "lp3-check lp3-inline"),
        ], w=60, g=22),
        price_card,
    ], gap_px=56, align="center"),
], "lp3-hero", pad=(88, 24, 140, 24), pad_t=(64, 24, 120, 24), pad_m=(44, 16, 96, 16), title="Hero",
    background_background="classic", background_color="#0b1426",
    background_image={"url": IMG["office"][1], "id": IMG["office"][0], "alt": IMG["office"][2], "source": "library", "size": ""},
    background_position="center center", background_repeat="no-repeat", background_size="cover",
    background_overlay_background="classic", background_overlay_color="#0b1426", background_overlay_opacity={"unit": "px", "size": 0.8})

# ------------------------------------------------------------------ 2. three offers (overlap the hero)
offers = section("offers", [
    grid("offers_grid", [
        card("offer%d" % i, [icon("offer_ico%d" % i, ico), heading("offer_h%d" % i, h, "h3", "lp3-h3"), text("offer_p%d" % i, [p])],
             "lp3-card lp3-offer", g=14, padding=dim(28), padding_tablet=dim(22, 20, 22, 20), padding_mobile=dim(24), title="Offer %d" % (i + 1))
        for i, (h, p, ico) in enumerate(T["offers"])
    ], "lp3-grid3 lp3-offers", title="Three offers"),
], "lp3-offerwrap", pad=(0, 24, 40, 24), pad_t=(0, 24, 32, 24), pad_m=(0, 16, 24, 16), title="Three offers",
    background_background="classic", background_color="#ffffff")

# ------------------------------------------------------------------ 3. what you get
get = section("get", [
    C("get_head", [heading("get_eb", "What you get", "p", "lp3-eyebrow"), heading("get_h2", T["get_h2"], "h2", "lp3-h2")],
      flex_gap=gap(14), css_classes="lp3-center", margin=dim(0, 0, 48, 0), margin_mobile=dim(0, 0, 32, 0), _title="Heading"),
    row("get_row", [
        col("get_img", [image("get_shot", "dashboard")], w=44),
        col("get_r", [
            grid("get_grid", [
                card("item%d" % i, [icon("item_ico%d" % i, ico),
                                    C("item_t%d" % i, [heading("item_h%d" % i, h, "h3", "lp3-h3"), text("item_p%d" % i, [p])], flex_gap=gap(6))],
                     "lp3-card lp3-item", g=0, padding=dim(20, 20, 20, 78), title=h)
                for i, (h, p, ico) in enumerate(T["get"])
            ], "lp3-grid2", title="Package items"),
            button("get_btn", T["buy"], BUY),
        ], w=56, g=28),
    ], gap_px=48, align="center"),
], "lp3-get", pad=(72, 24, 96, 24), pad_t=(56, 24, 80, 24), pad_m=(48, 16, 64, 16), title="What you get",
    background_background="classic", background_color="#ffffff")

# ------------------------------------------------------------------ 4. why act now
why = section("why", [
    C("why_head", [heading("why_eb", "Why act now", "p", "lp3-eyebrow"), heading("why_h2", T["why_h2"], "h2", "lp3-h2")],
      flex_gap=gap(14), css_classes="lp3-center", margin=dim(0, 0, 44, 0), margin_mobile=dim(0, 0, 28, 0), _title="Heading"),
    grid("why_grid", [
        card("fact%d" % i, [heading("fact_n%d" % i, n, "p", "lp3-factnum"), text("fact_p%d" % i, [p])],
             "lp3-fact" + (" lp3-fact-alert" if i == 3 else ""), g=12, padding=dim(26), padding_mobile=dim(22), title=n)
        for i, (n, p) in enumerate(T["why"])
    ], "lp3-grid4", title="Deadline facts"),
    C("why_end", [heading("why_close", T["why_close"], "p", "lp3-closing"), button("why_btn", T["buy"], BUY),
                  heading("why_src", T["why_source"], "p", "lp3-source")],
      flex_gap=gap(22), css_classes="lp3-center", margin=dim(44, 0, 0, 0), margin_mobile=dim(32, 0, 0, 0), _title="Closing line"),
], "lp3-dark", title="Why act now", background_background="classic", background_color=NAVY)

# ------------------------------------------------------------------ 5. how it works
how = section("how", [
    C("how_head", [heading("how_eb", "How it works", "p", "lp3-eyebrow"), heading("how_h2", T["how_h2"], "h2", "lp3-h2")],
      flex_gap=gap(14), css_classes="lp3-center", margin=dim(0, 0, 44, 0), margin_mobile=dim(0, 0, 28, 0), _title="Heading"),
    grid("how_grid", [
        card("step%d" % i, [heading("step_n%d" % i, "%02d" % (i + 1), "p", "lp3-num"), heading("step_h%d" % i, h, "h3", "lp3-h3"),
                            text("step_p%d" % i, [p])], "lp3-card lp3-step", g=14, padding=dim(28), padding_mobile=dim(24), title="Step %d" % (i + 1))
        for i, (h, p) in enumerate(T["how"])
    ], "lp3-grid3 lp3-steps", title="Steps"),
], "lp3-surf", title="How it works", background_background="classic", background_color=SURF)

# ------------------------------------------------------------------ 6. referral
math_rows = [("You pay", "AED 1,999", ""), ("One company you refer buys", "+ AED 999 back", "lp3-mathrow-plus"),
             ("Your software has cost you", "AED 1,000", "lp3-mathrow-total")]
referral = section("ref", [
    row("ref_row", [
        col("ref_l", [
            heading("ref_eb", "Referral offer", "p", "lp3-eyebrow"),
            heading("ref_h2", T["ref_h2"], "h2", "lp3-h2"),
            W("ref_steps", "text-editor", editor="<ol>%s</ol>" % "".join("<li>%s</li>" % esc(s) for s in T["ref_steps"]), _css_classes="lp3-olist"),
            text("ref_cond", [T["ref_condition"]], "lp3-text"),
            text("ref_small", T["ref_small"], "lp3-small"),
        ], w=54, g=20),
        col("ref_r", [
            card("ref_ex", [
                heading("ref_ex_eb", "Simple example", "p", "lp3-eyebrow"),
                card("ref_math", [
                    card("mrow%d" % i, [heading("mrow_l%d" % i, l, "p", "lp3-mathl"), heading("mrow_r%d" % i, r, "p", "lp3-mathr")],
                         "lp3-mathrow " + c, g=16, padding=dim(16, 20, 16, 20), title=l)
                    for i, (l, r, c) in enumerate(math_rows)
                ], "lp3-math", g=0, title="Example maths"),
                text("ref_ex_p", [T["ref_example"]], "lp3-text"),
            ], "lp3-example", g=18, padding=dim(30), padding_mobile=dim(22), title="Example card"),
            card("ref_callout", [text("ref_line", [T["ref_line"]], "lp3-text")], "lp3-callout", padding=dim(18, 22, 18, 22), title="Code line"),
        ], w=46, g=20),
    ], gap_px=56, align="flex-start"),
], "lp3-ref", title="Referral offer", background_background="classic", background_color="#ffffff")

# ------------------------------------------------------------------ 7. order
summary = card("sum", [
    heading("sum_eb", "Order summary", "p", "lp3-eyebrow"),
    C("sum_rows", [
        card("srow%d" % i, [heading("srow_l%d" % i, l, "p", "lp3-suml"), heading("srow_r%d" % i, r, "p", "lp3-sumr")],
             "lp3-sumrow" + (" lp3-sumrow-total" if i == 2 else ""), padding=dim(13, 0, 13, 0), title=l)
        for i, (l, r) in enumerate(T["order_sum"])
    ], _title="Rows"),
    button("sum_btn", T["buy"], BUY),
    W("sum_after", "text-editor", editor="<ul>%s</ul>" % "".join("<li>%s</li>" % esc(s) for s in T["order_after"]), _css_classes="lp3-after"),
], "lp3-summary", g=18, padding=dim(28), padding_mobile=dim(22), width={"unit": "%", "size": 44},
    width_tablet={"unit": "%", "size": 100}, title="Order summary")

order = section("order", [
    C("order_card", [
        row("order_row", [
            col("order_l", [
                heading("order_eb", "Order online", "p", "lp3-eyebrow"),
                heading("order_h2", T["order_h2"], "h2", "lp3-h2"),
                text("order_p", [T["order_p"] + " At checkout you fill in:"]),
                W("order_fields", "text-editor", editor="<ul>%s</ul>" % "".join("<li>%s</li>" % esc(f) for f in T["order_fields"]),
                  _css_classes="lp3-fields"),
                text("order_ref", [T["order_ref"]], "lp3-small"),
                help_line("order_help", T["help"]),
            ], w=56, g=18),
            summary,
        ], gap_px=48, align="flex-start"),
    ], css_classes="lp3-ordercard", padding=dim(44), padding_tablet=dim(36), padding_mobile=dim(22), _element_id=ORDER_ANCHOR,
      _title="Order card"),
], "lp3-surf", title="Order", background_background="classic", background_color=SURF)

# ------------------------------------------------------------------ 8. FAQ (accordion settings as on the gulf-news page)
acc_settings = {"accordion_item_title_position_horizontal": "stretch", "accordion_item_title_icon_position": "end", "default_state": "all_collapsed",
                "accordion_item_title_space_between": {"unit": "px", "size": 0}, "accordion_item_title_distance_from_content": {"unit": "px", "size": 0},
                "accordion_padding": {"unit": "px", "top": 22, "right": 0, "bottom": 22, "left": 0}, "accordion_padding_mobile": {"unit": "px", "top": 16, "right": 0, "bottom": 16, "left": 0},
                "accordion_border_radius": {"unit": "px", "top": 0, "right": 0, "bottom": 0, "left": 0},
                "accordion_background_normal_background": "classic", "accordion_background_normal_color": "rgba(255,255,255,0)",
                "accordion_background_hover_background": "classic", "accordion_background_hover_color": "rgba(255,255,255,0)",
                "accordion_background_active_background": "classic", "accordion_background_active_color": "rgba(255,255,255,0)",
                "accordion_border_normal_border": "none", "accordion_border_hover_border": "none", "accordion_border_active_border": "none",
                "normal_title_color": "#030922", "hover_title_color": "#0f5cd8", "active_title_color": "#0f5cd8", "icon_size": {"unit": "px", "size": 12},
                "normal_icon_color": "#0f5cd8", "hover_icon_color": "#ffffff", "active_icon_color": "#ffffff", "_title": "FAQ accordion",
                "title_typography_typography": "custom", "title_typography_font_family": "DM Sans", "title_typography_font_size": {"unit": "px", "size": 17},
                "title_typography_font_size_mobile": {"unit": "px", "size": 16}, "title_typography_font_weight": "600",
                "title_typography_line_height": {"unit": "px", "size": 26}, "title_typography_line_height_mobile": {"unit": "px", "size": 24}, "faq_schema": "yes"}
acc_settings["items"] = [{"_id": uid("faqitem%d" % i)[:7], "item_title": esc(q)} for i, (q, a) in enumerate(T["faq"])]
acc_kids = [{"id": uid("faqc%d" % i), "elType": "container",
             "settings": {"flex_direction": "column", "flex_gap": gap(0), "padding": {"unit": "px", "top": 0, "right": 56, "bottom": 22, "left": 0},
                          "content_width": "full", "padding_mobile": {"unit": "px", "top": 0, "right": 0, "bottom": 18, "left": 0}},
             "elements": [W("faqa%d" % i, "heading", title=(a if "<a " in a else esc(a)), header_size="p")], "isInner": True}
            for i, (q, a) in enumerate(T["faq"])]
accordion = {"id": uid("faqacc"), "elType": "widget", "settings": acc_settings, "elements": acc_kids, "widgetType": "nested-accordion"}
faq = section("faq", [
    row("faq_row", [
        col("faq_l", [heading("faq_eb", "FAQs", "p", "lp3-eyebrow"), heading("faq_h2", T["faq_h2"], "h2", "lp3-h2"),
                      help_line("faq_help", T["final_contact"]), button("faq_btn", T["buy"], BUY)], w=36, g=18, css_classes="lp3-faqintro"),
        col("faq_r", [accordion], w=64),
    ], gap_px=64, align="flex-start"),
], "lp3-faq", title="FAQs", background_background="classic", background_color="#ffffff", _element_id=FAQ_ANCHOR)

# ------------------------------------------------------------------ 9. final CTA
final = section("final", [
    C("final_c", [
        heading("final_h2", T["final_h2"], "h2", "lp3-h2"),
        text("final_p", [T["final_p"]]),
        C("final_btns", [button("final_buy", T["buy"], BUY), button("final_demo", T["demo"], DEMO, ext=True, ghost=True)],
          flex_direction="row", flex_gap=gap(14), flex_justify_content="center", css_classes="lp3-btns", margin=dim(8, 0, 4, 0), _title="Buttons"),
        help_line("final_help", T["final_contact"]),
    ], flex_gap=gap(18), flex_align_items="center", css_classes="lp3-center", _title="Final CTA"),
], "lp3-final", pad=(96, 24, 96, 24), title="Final call to action", background_background="gradient", background_color="#030922",
    background_color_b="#0b3aa0", background_gradient_angle={"unit": "deg", "size": 140})

DATA = [styles, banner, hero, offers, get, why, how, referral, order, faq, final]


def walk(e):
    yield e
    for c in e.get("elements", []):
        yield from walk(c)


ids = [e["id"] for t in DATA for e in walk(t)]
assert len(ids) == len(set(ids))
body = {"title": "E-Invoicing Starter Package", "status": "draft", "post_type": "page",
        "template_type": "wp-page", "page_template": "elementor_header_footer", "data": DATA}
if len(sys.argv) > 1:
    body["id"] = int(sys.argv[1])
open(os.path.join(OUT, "page.json"), "w").write(json.dumps(body, ensure_ascii=False, separators=(",", ":")))
json.dump(T, open(os.path.join(OUT, "content.json"), "w"), ensure_ascii=False, indent=1)
print("page.json", len(json.dumps(body)), "elements", len(ids), "css", len(CSS))
