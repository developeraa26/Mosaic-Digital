# UAE e-invoicing deadline counter (home page hero)

Live on https://ledgerplus.ae/ since 26 Nov 2026: Elementor HTML widget `c0d7e1a9` ("E-invoicing deadline counter", CSS class `lp-dl-w`) inside the hero container `d10fc0ae`, directly above the Feature bar widget `f6334ba5`. Source: `deadline-counter-widget.html` (style + markup + script in one snippet).

- Deadlines (UAE time, UTC+4): 1 Jan 2027 00:00 (revenue AED 50m or more); end of 31 Mar 2027 (ASP appointment, revenue under AED 50m); then 1 Jul 2027 00:00 (go-live, revenue under AED 50m). The phases switch automatically: after each date the card shows "E-invoicing is now mandatory", and after 31 Mar the right card counts to 1 Jul.
- Both links open Elementor Pro popup 532 ("Talk to Agent") through Elementor's native popup action link. The popup must keep its display condition on the home page.
- Link text avoids calling Ledger Plus an accredited ASP, because the e-invoicing page says Ledger Plus is "supported by an FTA-approved Accredited Service Provider". Change the two link texts in the widget if Ledger Plus holds MoF accreditation itself.
- To edit: Elementor editor on the home page > hero > "E-invoicing deadline counter" HTML widget.
