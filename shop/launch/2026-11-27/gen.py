# Generates WPVibe content/edit bodies for the launch. Stored Elementor JSON escapes "/" as "\/" and non-ASCII as \uXXXX.
import json, sys

def el_str(s):
    """Python text -> the bytes Elementor stores inside a JSON string (no surrounding quotes)."""
    return json.dumps(s, ensure_ascii=True)[1:-1].replace('/', '\\/')

def el_obj(o):
    return json.dumps(o, ensure_ascii=True, separators=(',', ':')).replace('/', '\\/')

def body_meta(post_id, old, new):
    return json.dumps({"target_type": "meta", "post_id": post_id, "meta_key": "_elementor_data", "old_content": old, "new_content": new}, ensure_ascii=False)

def body_post(post_id, old, new):
    return json.dumps({"target_type": "post", "post_id": post_id, "field": "post_content", "old_content": old, "new_content": new}, ensure_ascii=False)

TERMS = "https://ledgerplus.ae/terms-of-sale/"
edits = []
# ---- 892 purchase page (old = stored bytes exactly as read from the site on 27 Nov 2026) ----
P = [
 ("pc_note", el_str("VAT included · [One-time or yearly: to confirm]"), el_str("Per year · VAT included")),
 ("how_pay", el_str("<p>You get your login and tax invoice by email [straight away: to confirm].</p>"),
             el_str("<p>You get an order confirmation by email as soon as your payment is confirmed. Our team then contacts you to create your Ledger Plus login.</p>")),
 ("ref_small", el_str("<p>Cash back is paid by [payment method] within [X days] of the referred company’s payment.</p><p>[Reward for each referral, or one time only: to confirm]</p>"),
               el_str("<p>No cash back is due if the referred company’s order is refunded, cancelled or disputed. Our team contacts you to arrange the payment.</p>")),
 ("order_after", el_str("<li>Your login and tax invoice arrive by email [right after payment: to confirm].</li>"),
                 el_str("<li>Your order confirmation arrives by email as soon as your payment is confirmed. Our team then contacts you to create your Ledger Plus login.</li>")),
 ("faq_yearly", '"title":"' + el_str("[One-time or yearly: to confirm]") + '"',
                '"title":"' + el_str("No. AED 1,999 is a yearly price, VAT included. Before your year ends we send you a renewal invoice. Your card is never charged automatically.") + '"'),
 ("faq_start", el_str("Your account is created [right after payment: to confirm]. Our team then contacts you [within X working days] to move your data and book onboarding."),
               el_str("After your payment, our team contacts you to create your Ledger Plus login. We then move your data and book your free onboarding.")),
 ("faq_cash", el_str("After the company you referred has paid. We pay it by [payment method] within [X days]."),
              el_str("After the company you referred has paid. No cash back is due if that order is refunded, cancelled or disputed. Our team contacts you to arrange the payment.")),
 ("faq_1000", '"title":"' + el_str("[To confirm]") + '"',
              '"title":"' + el_str("Contact us before you reach the limit and we will suggest the right plan for your volume.") + '"'),
 ("faq_compl", el_str("They include a dedicated accountant and start at [AED 5,999 a year: to confirm]."),
               el_str("They include a dedicated accountant.")),
 ("faq_refund", '"title":"' + el_str("[Refund policy: to confirm]") + '"',
                '"title":"' + el_str('Yes. If you ask within 14 days of your payment and have not sent any e-invoices yet, we refund the full amount to the card you paid with. See our <a href="%s">Terms of Sale</a>.' % TERMS) + '"'),
]
for name, old, new in P:
    # new must be a valid JSON string fragment (or "title":"..." pair)
    frag = new if not new.startswith('"title"') else new[len('"title":"'):-1]
    json.loads('"' + frag + '"')
    assert '[' not in json.loads('"' + frag + '"') or 'href' in frag, name
    edits.append(("892_" + name, body_meta(892, old, new)))

# ---- 887 checkout note (post_content, plain HTML) ----
edits.append(("887_tbc", body_post(887, '<span class="lp-shop-tbc">[Tax invoice by email: to confirm]</span>', 'The price is for one year and does not renew automatically.')))

P2 = [
 ("ref_example", el_str("Your software has cost you AED 1,000."), el_str("Your first year has cost you AED 1,000.")),
 ("final_p", el_str("<p>AED 1,999. Refer one company that buys and get AED 999 cash back.</p>"), el_str("<p>AED 1,999 a year. Refer one company that buys and get AED 999 cash back.</p>")),
]
for name, old, new in P2:
    json.loads('"' + new + '"')
    edits.append(("892_" + name, body_meta(892, old, new)))
edits.append(("886_cart_head", body_post(886, 'E-Invoicing Starter package, AED 1,999 including 5% VAT.', 'E-Invoicing Starter package, AED 1,999 a year including 5% VAT. Renewal is not automatic.')))
edits.append(("888_account_head", body_post(888, '<p class="lp-shop-sub">View your orders, your tax invoices and your referral code.</p>',
  '<p class="lp-shop-sub">Sign in to see your shop orders and your referral code.</p>\n<p class="lp-shop-note">This is not the Ledger Plus software login. After your payment, our team contacts you to create that login. Bought without an account? Your order details and referral code are in your order confirmation email.</p>')))

# ---- 129 pricing page Starter button (launch/pricing_patch_launch.json) ----
pp = json.load(open('/home/user/Mosaic-Digital/shop/launch/pricing_patch_launch.json'))
e = pp["edits"][0]
edits.append(("129_button", body_meta(129, e["old_content"], e["new_content"])))

# ---- 36 footer: Terms of Sale link after Privacy Policy ----
def li(_id, text, url):
    return {"_id": _id, "text": text, "selected_icon": {"value": "", "library": ""}, "link": {"url": url, "is_external": "", "nofollow": "", "custom_attributes": ""}}
priv = el_obj(li("c7e2a5f", "Privacy Policy", "https://ledgerplus.ae/privacy-policy/"))
terms = el_obj(li("e2d9b47", "Terms of Sale", TERMS))
edits.append(("36_terms", body_meta(36, priv, priv + "," + terms)))

# ---- 32 header: account icon + Menu Cart after the nav menu ----
account = {"id": "a1c5e30", "elType": "widget", "settings": {
    "selected_icon": {"value": "far fa-user-circle", "library": "fa-regular"},
    "link": {"url": "https://ledgerplus.ae/my-account/", "is_external": "", "nofollow": "", "custom_attributes": "aria-label|My account,title|My account"},
    "primary_color": "#27304d", "hover_primary_color": "#0f5cd8",
    "size": {"unit": "px", "size": 22}, "size_mobile": {"unit": "px", "size": 22},
    "_padding": {"unit": "px", "top": "10", "right": "10", "bottom": "10", "left": "10", "isLinked": True},
    "_title": "Account icon (links to My account)", "_flex_size": "none"},
    "elements": [], "widgetType": "icon"}
cart = {"id": "c4a7d12", "elType": "widget", "settings": {
    "icon": "cart-medium", "items_indicator": "bubble", "hide_empty_indicator": "yes", "show_subtotal": "",
    "cart_type": "side-cart", "open_cart": "click", "automatically_open_cart": "",
    "toggle_button_icon_color": "#27304d", "toggle_button_hover_icon_color": "#0f5cd8",
    "toggle_button_background_color": "rgba(0,0,0,0)", "toggle_button_hover_background_color": "rgba(0,0,0,0)",
    "toggle_button_border_width": {"unit": "px", "size": 0},
    "toggle_icon_size": {"unit": "px", "size": 22},
    "toggle_button_padding": {"unit": "px", "top": "10", "right": "10", "bottom": "10", "left": "10", "isLinked": True},
    "items_indicator_background_color": "#0f5cd8", "items_indicator_text_color": "#ffffff",
    "_title": "Cart icon (Menu Cart)", "_flex_size": "none"},
    "elements": [], "widgetType": "woocommerce-menu-cart"}
old_tail = '"widgetType":"nav-menu"}],"isInner":false}]'
new_tail = '"widgetType":"nav-menu"},' + el_obj(account) + ',' + el_obj(cart) + '],"isInner":false}]'
json.loads('[{"a":[{' + new_tail[:0] + '"widgetType":"nav-menu"},' + el_obj(account) + ',' + el_obj(cart) + ']}]')
edits.append(("32_header", body_meta(32, old_tail, new_tail)))

for name, b in edits:
    open(name + ".json", "w").write(b)
    json.loads(b)
print("\n".join(n for n, _ in edits))
