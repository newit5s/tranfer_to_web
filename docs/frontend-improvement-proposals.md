# Frontend Improvement Proposals

This document consolidates the issues observed during the evaluation of the Booking frontend and the Location Manager portal, and outlines actionable adjustments to address them.

## Booking Frontend

### 1. Align default date with backend configuration
* **Problem:** The booking modal initializes the date picker with "tomorrow" on the client side. When the backend enforces a minimum advance booking window greater than 24 hours, the default date violates the generated `min_date`, immediately flagging the field as invalid.
* **Proposal:** Remove the hard-coded client-side default and source the initial date directly from the backend configuration (e.g., inject `min_date` via data attributes or JSON). Ensure the initialization logic uses that value when the modal opens or resets.

### 2. Restore initial state after reset/close
* **Problem:** Closing the modal or pressing the "reset" action invokes `clearForm()`, leaving all fields empty and no time slots loaded. The user must manually reselect every filter before availability is shown again.
* **Proposal:** After clearing, re-run the same initialization routine used on first load (`setInitialValues()` or equivalent). This keeps the UX consistent, preloads slots for the default branch/date, and avoids an empty state on reopen.

### 3. Validate responsive layout regression
* **Problem:** Some environments report that the "Full Name" and "Phone Number" fields overlap when the modal is narrow, despite responsive CSS rules designed to stack them.
* **Proposal:** Audit the compiled CSS for overrides from themes or cached assets. Add automated visual regression tests (e.g., Playwright + Percy) for breakpoints at 820px and 640px to detect layout regressions. Consider scoping the form grid styles more narrowly to prevent theme conflicts.

## Location Manager Portal

### 4. Relax phone number validation in booking form
* **Problem:** The manager-side booking form enforces the regex `[0-9]{8,15}`, rejecting international formats with `+`, spaces, or dashes—formats that the customer-facing widget accepts. Staff cannot enter the phone numbers captured from the public frontend.
* **Proposal:** Update the validation pattern to accept standard international formats (e.g., `^[+]?[0-9\s-]{8,20}$`) or delegate validation to a shared utility used by both portals. Normalize phone numbers server-side before persistence.

### 5. Provide timeline error fallback
* **Problem:** The timeline view renders a spinner (`"Loading timeline data…"`) in static HTML and relies entirely on JavaScript to replace it. If the AJAX call fails, users see a blank screen with no guidance.
* **Proposal:** Implement an error state with retry controls. Options include: render a server-side message when data is unavailable, add client-side error handling to display alerts, and log failures for monitoring. Ensure accessibility by exposing status updates to assistive technologies.

## Cross-cutting Recommendations

* Add automated end-to-end scenarios covering modal reset flows and manager-side booking submissions with international numbers.
* Document the dependency between frontend defaults and backend configuration to avoid reintroducing hard-coded values in future updates.
* Review caching strategy for CSS/JS bundles to minimize stale assets causing layout issues on mobile devices.

