# Customer map locking

The customer address picker opens with a locked map. GPS, selecting a search result and returning to the GPS position lock it again. The customer explicitly presses «تعديل الموقع على الخريطة» to enable dragging, touch zoom, double-click zoom, box zoom and keyboard interaction. While locked, these interactions and zoom buttons cannot change the selected point; vertical page scrolling remains available. «تأكيد هذا الموقع» closes the picker and locks it for the next opening. This applies to checkout and saved-address editing; administrative map pickers retain their existing behavior.

Reopening a locked map with unchanged coordinates preserves the existing delivery quote. Moving an unlocked map still invalidates the previous point/quote immediately, and existing reverse-geocoding and quote cancellation handle stale responses. Coverage previews continue after the point settles. No provider, fee, distance formula, zone priority or Laravel behavior changed.

Modified application files: `MapLocationPicker.vue`, `CheckoutAddress.vue`, `useAddressLocation.js`. Browser regression coverage is in `front-end/tests/delivery-location-ux.browser.mjs` and uses controlled API/GPS fixtures, without writing customer data.

## BACKEND → FRONTEND COVERAGE

- Backend features used: existing delivery quote and saved-address contracts; Laravel remains authoritative for delivery eligibility and fees.
- Existing UI covered: GPS, search, manual details, saved addresses and coverage previews.
- Missing UI discovered and implemented: intentional unlock action, visible locked status, automatic relocking, and preserving quote when merely reopening an unchanged location.
- Backend-only features remain internal: zone matching, fee calculation, coordinate checks and permissions; this UI change requires no backend changes.

Build and runtime verification details are recorded in the task report. Browser tests simulate device widths and GPS outcomes; physical Android GPS is a separate manual check.

Verification: production Vue build passed locally and in the Nginx image. Location and provider unit tests passed. The separate Node delivery-quote race test stalled and was interrupted; it is not reported as passing. Browser regression checks cover invalidating old quotes during movement and rejecting late old responses. HTTP returned 200; HTTPS returned 200 with the exported CA and full certificate validation. Only the two frontend Nginx containers were recreated, with no dependency restart or database mutation.
