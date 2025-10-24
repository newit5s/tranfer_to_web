# Frontend UI Bug Sweep

## Booking widget (customer-facing)
- **Inline embed announces itself as a modal.** When the shortcode disables the trigger button, the markup still outputs `<div role="dialog" aria-modal="true">` even though the container is rendered inline with no overlay. Screen readers will think the entire page is trapped in a modal from initial paint. 【F:public/class-frontend-public.php†L170-L205】
- **Language dropdowns are unlabeled.** Both step 1 and step 2 render `<select class="rb-new-lang-select">` elements without an associated `<label>` or `aria-label`, so assistive tech only exposes the raw locale codes. 【F:public/class-frontend-public.php†L368-L378】
- **Modal lacks a focus trap.** Keyboard handling only listens for the Escape key; focus can freely move to the underlying page after opening, which breaks the expected modal experience for keyboard/screen-reader users. 【F:assets/js/new-booking.js†L201-L219】
- **Contact details grid breaks at narrow desktop widths.** Step 2 always forces a two-column grid via `.rb-new-form-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }`, so when the widget is embedded in a sidebar-sized container the name/phone inputs collapse and overlap each other instead of wrapping. 【F:assets/css/new-frontend.css†L352-L371】【F:public/class-frontend-public.php†L404-L438】
- **Mobile layout still allows staggered columns.** Even under the mobile breakpoints the form retains `.rb-new-form-group-wide { grid-column: span 2; }`, leaving the "Special Requests" textarea stretched across a phantom column while the other inputs remain narrow, producing the diagonal/uneven field layout reported on phones. 【F:assets/css/new-frontend.css†L388-L418】【F:assets/css/new-frontend.css†L713-L735】

## Location manager portal (staff-facing)
- **Primary navigation has no active state for screen readers.** The Gmail-style nav only toggles a CSS class on the `<li>`; without `aria-current="page"`, assistive tech cannot announce which section is open. 【F:public/class-frontend-manager.php†L326-L339】
- **Customer history dialog has an unlabelled close button.** The overlay relies on an "×" character with no accessible name, so screen readers do not announce what the control does. 【F:public/class-frontend-manager.php†L1820-L1823】
