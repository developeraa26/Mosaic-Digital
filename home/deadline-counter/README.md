# UAE e-invoicing deadline counter (home page hero)

Live on https://ledgerplus.ae/ since 26 Nov 2026. It is an Elementor HTML widget:

- Widget `c0d7e1a9` ("E-invoicing deadline counter", CSS class `lp-dl-w`).
- It is the first visible item in the hero container `d10fc0ae`. It sits after the hidden hero-styles widget `7c3e91a2` and above the headline row `e159c591` (changed from the bottom of the hero, above the Feature bar, on the client's request).
- Source: `deadline-counter-widget.html` (style, markup and script in one snippet).
- Previews: `preview-desktop-1440.png` and `preview-phone-390.png`. These come from a local mock of the hero with the same widget code, not captures of the live site.

## Behaviour

- **Deadlines (UAE time, UTC+4):**
  - 1 Jan 2027 00:00: go-live for revenue of AED 50m or more.
  - End of 31 Mar 2027: appoint an ASP, for revenue under AED 50m.
  - 1 Jul 2027 00:00: go-live for revenue under AED 50m.
- **Phases switch by themselves:**
  - After each date, the card shows "E-invoicing is now mandatory".
  - After 31 Mar, the right card counts to 1 Jul.
- **Popup links:** both links open Elementor Pro popup 532 ("Talk to Agent") through Elementor's native popup action link. The popup must keep its display condition on the home page.
- **Headings:** the eyebrow and the card titles are `<p>` tags, not headings. Because the counter now sits above the hero headline, this keeps the H1 as the first heading on the page.
- **Mobile:** below 768px the cards stack and the padding and spacing are tighter, so the hero headline stays close to the top of the screen.

## Wording and editing

- **Link text:** the links avoid calling Ledger Plus an accredited ASP. The e-invoicing page says Ledger Plus is "supported by an FTA-approved Accredited Service Provider". If Ledger Plus holds MoF accreditation itself, change the two link texts in the widget.
- **To edit:** open the home page in the Elementor editor, then the hero, then the "E-invoicing deadline counter" HTML widget.
