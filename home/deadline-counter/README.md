# UAE e-invoicing deadline counter (home page hero)

Live on https://ledgerplus.ae/ since 26 Nov 2026. It is an Elementor HTML widget:

- Widget `c0d7e1a9` ("E-invoicing deadline counter", CSS class `lp-dl-w`).
- It is the first visible item in the hero container `d10fc0ae`. It sits after the hidden hero-styles widget `7c3e91a2` and above the headline row `e159c591`. It was moved there from the bottom of the hero, above the Feature bar, on the client's request.
- Source: `deadline-counter-widget.html` (style, markup and script in one snippet).
- Previews: `preview-desktop-1440.png` and `preview-phone-390.png`. They come from a local copy of the live page with the site's real Elementor and theme CSS. The only stand-ins are the logo and the fonts; the hero background is the real image from `home/assets/`.

## Behaviour

- **Deadlines (UAE time, UTC+4):**
  - 1 Jan 2027 00:00: go-live for revenue of AED 50m or more.
  - End of 31 Mar 2027: appoint an ASP, for revenue under AED 50m.
  - 1 Jul 2027 00:00: go-live for revenue under AED 50m.
- **Phases switch by themselves:**
  - After each date, the card shows "E-invoicing is now mandatory".
  - After 31 Mar, the right card counts to 1 Jul.
- **Popup links:** both links open Elementor Pro popup 532 ("Talk to Agent") through Elementor's native popup action link. The popup must keep its display condition on the home page.
- **Headings:** the eyebrow and the card titles are `<p>` tags, not headings. Because the counter sits above the hero headline, this keeps the H1 as the first heading on the page.

## Spacing and phone layout (27 Nov 2026)

- **Gap below the panel:** 56px on desktop, 40px at 1024px and below, 28px at 767px and below.
  - It is a margin on `.lp-dl`, the panel inside the widget, not on the widget.
  - Elementor's `.e-con .elementor-widget.elementor-widget{margin-block-end:0}` removes any bottom margin on widgets inside containers. That is why the first version had no gap.
  - A margin set later in the Elementor editor (Advanced > Margin) adds to this gap.
- **Phones (767px and below):** a compact version of the same stacked layout.
  - At 390px wide the panel is 323px tall, down from 439px.
  - Timer boxes are at least 40px tall, numbers 18px, links 13.5px and 26px tall.
- **Large phones and phones held sideways (540-767px):** the link sits beside the timer, as on desktop.
  - The boxes are at least 56px wide and grow evenly with larger text.
  - The row wraps if the text gets too big.
- **Desktop and tablet (768px and up):** unchanged by the phone work. Pixel comparisons against the previous version were identical.
- **Checks:** before release this was checked at 280-1440px in all four phases (now, after 1 Jan, after 31 Mar, after 1 Jul), plus:
  - the WCAG 1.4.12 text-spacing override;
  - a 12-14px minimum font size;
  - 150% text scaling;
  - a wide fallback font.

## Wording and editing

- **Link text:** the links avoid calling Ledger Plus an accredited ASP. The e-invoicing page says Ledger Plus is "supported by an FTA-approved Accredited Service Provider". If Ledger Plus holds MoF accreditation itself, change the two link texts in the widget.
- **To edit:** open the home page in the Elementor editor, then the hero, then the "E-invoicing deadline counter" HTML widget.
