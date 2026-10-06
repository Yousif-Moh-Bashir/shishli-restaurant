# Audit evidence

See [FINAL_SYSTEM_AUDIT.md](../FINAL_SYSTEM_AUDIT.md) for the Arabic report with all 22 requested sections.

- `backend-inventory.json`: source files, methods, dependencies and extracted contract references.
- `backend-routes.json`: actual Laravel API route inventory with authorization metadata.
- `endpoint-coverage.{json,csv,md}`: all 207 route entries, frontend mappings and explicit blockers.
- `backend-schema.json`: actual migration tables and column declarations.
- `backend-permissions.json`: 92 permissions returned by the real administrative API.
- `frontend-inventory.json`: current source files, imports, forms, dialogs and permissions.
- `backend-integrity.json`: SHA-256 comparison of 618 inventoried backend source files with the baseline taken before frontend work.
- `test-results.json`: sanitized final test summary; no tokens, customer records or screenshots.

Coverage is a source integration finding. It does not imply that every mutation was repeated against the live local database. Features lacking an API are documented separately from existing route entries. Test skips and unavailable production providers are explicit.

Browser tests use the installed Windows Chrome and Node WebSocket support, with no new dependency. They require the existing Laravel local/testing demo fixture accounts and a running API at `127.0.0.1:8000`. **Do not point the mutation tests at production.** The fixture accounts are not a frontend fallback.

From `front-end`, with the frontend preview on port 5175:

```powershell
$env:DESIGN_APP_URL='http://127.0.0.1:5175'
node --experimental-websocket tests/final-audit.browser.mjs
node --experimental-websocket tests/final-domains.browser.mjs
$env:BROWSER_CHECKOUT='1'
node --experimental-websocket tests/final-public-api.mjs
node --experimental-websocket tests/final-auth-media.browser.mjs
npm run build
```

The read-only test visits current pages and checks responsive layouts, dialogs and filters. Domain and checkout tests restore changed records, delete temporary promotions/media and cancel the local checkout order. Its immutable historical order and activity entries remain. Reports and browser profiles under `.reports-check/` are excluded from Git because screenshots may contain authorized local fixture data.
