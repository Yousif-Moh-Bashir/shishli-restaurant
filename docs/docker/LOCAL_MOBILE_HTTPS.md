# Local Android HTTPS — Shishli

Implemented and verified on 2026-10-14. Phone URL: **https://192.168.1.218:8443**.
Existing HTTP remains **http://127.0.0.1:8088**. Physical-phone reachability and GPS are still pending.

## Architecture and files

`docker/compose.mobile-https.yaml` adds an optional `mobile-https` profile/service using the existing Nginx image. It binds **192.168.1.218:8443 only**, joins only the existing ingress network, and proxies every route to the original `nginx:80`. Original Compose, Laravel, Vue, CORS, Trusted Proxies, database and delivery rules are unchanged. MySQL, Redis and PHP-FPM remain private. No original containers were recreated or volumes removed.

The template `docker/nginx/mobile-https.conf.template` retains the original geocoding gateway, provider TLS verification, rate limits and cache. HTML/JSON responses rewrite the two known local HTTP origins to the HTTPS origin, including escaped JSON URLs. This accommodates existing stored image URLs without changing APP_URL or data. Scripts/styles and image binaries are unchanged. This is a local testing gateway, not a public deployment configuration.

Added helper: `docker/mobile-https.ps1`; read-only network verifier: `docker/verify-mobile-https.mjs`; browser verifier: `docker/mobile-https.browser.mjs`. `.local-mobile-https/` is excluded by both Git and Docker build context.

## Setup, start, verify and stop

Run from `C:\xampp\htdocs\projects\shishli` in PowerShell, with Docker Desktop running and the existing Shishli services already started:

```powershell
powershell -ExecutionPolicy Bypass -File docker/mobile-https.ps1 -Action Setup
powershell -ExecutionPolicy Bypass -File docker/mobile-https.ps1 -Action Start
powershell -ExecutionPolicy Bypass -File docker/mobile-https.ps1 -Action Status
powershell -ExecutionPolicy Bypass -File docker/mobile-https.ps1 -Action Verify
```

Setup downloads official mkcert 1.4.4, checks its SHA256 against the pinned Microsoft WinGet manifest, installs a dedicated local CA in Windows trust, and issues the server certificate. Its CA directory is **outside the project**:

`%LOCALAPPDATA%\Shishli\mobile-https-ca`

Only `.local-mobile-https/server.crt`, `server.key` and rendered Nginx configuration are mounted into the new service, all read-only. The root private key is never mounted or copied into the project.

Start checks the actual Wi-Fi IP /24 and port availability, validates Compose and Nginx, and starts only the optional service with `--no-deps`. No rebuild is needed. If DHCP changes the IP within 192.168.1.0/24, stop, repeat Setup with `-LanIP NEW_IP`, then Start. This reuses the same CA; update the firewall local address and phone URL.

Stop only HTTPS:

```powershell
powershell -ExecutionPolicy Bypass -File docker/mobile-https.ps1 -Action Stop
```

Stop was tested: port 8443 closed and HTTP 8088 still returned 200. HTTPS has subsequently been restarted and is running.

## Windows firewall and Wi-Fi

Verified: Wi-Fi address 192.168.1.218/24, gateway 192.168.1.1, profile **Public**. Public firewall is enabled with inbound blocked by default. The session is not Administrator, and firewall-rule enumeration was denied. **No firewall rule was installed.** Docker successfully published a listener on 192.168.1.218:8443; this proves local binding, not phone reachability.

Review and run this proposed rule in **Administrator PowerShell**:

```powershell
New-NetFirewallRule -Name 'Shishli-Mobile-HTTPS-8443' -DisplayName 'Shishli local Android HTTPS' -Direction Inbound -Action Allow -Protocol TCP -LocalPort 8443 -LocalAddress 192.168.1.218 -RemoteAddress 192.168.1.0/24 -Profile Public -InterfaceAlias 'Wi-Fi' -EdgeTraversalPolicy Block
```

It permits only local-subnet clients through the Wi-Fi interface to this IP/port. Do not disable the firewall or configure router port forwarding. If the network profile changes, review the rule's profile. To print a suggestion for the current profile:

```powershell
powershell -ExecutionPolicy Bypass -File docker/mobile-https.ps1 -Action FirewallCommand
```

Phone must use the same Wi-Fi, with an address 192.168.1.x and gateway 192.168.1.1, not guest Wi-Fi with client isolation. A ProTUN VPN interface was present on Windows; inspect its LAN-access settings if the phone cannot connect. No VPN/router settings were changed. We cannot verify bidirectional PC/phone connectivity without the phone and its IP; ICMP ping failure alone is not proof of isolation. Test the exact HTTPS URL after installing the CA and applying the scoped rule.

## Transfer and trust the public CA on Android

Transfer **only this public DER certificate**, preferably by USB:

`C:\xampp\htdocs\projects\shishli\.local-mobile-https\shishli-rootCA.crt`

Never transfer `rootCA-key.pem` or `server.key`. The public CA SHA256 fingerprint is:

`A0:94:D5:38:74:9A:19:CB:71:87:02:24:0E:D5:81:4C:3F:35:29:FF:17:0A:80:E5:8D:BF:10:B4:77:7E:3F:82`

On Android, open Settings → Security / Security & privacy → More security settings / Encryption & credentials → Install a certificate → **CA certificate**. Menu names differ by manufacturer. Select `shishli-rootCA.crt`, confirm with the phone screen lock, and restart Chrome. Install as a CA certificate rather than a Wi-Fi client certificate. Open the exact URL and confirm there is no certificate warning; do not bypass warnings. Android may show its normal notification that a user CA is installed.

Public-source guidance: [mkcert mobile trust and private-key precautions](https://github.com/FiloSottile/mkcert), [Google Android certificate installation](https://support.google.com/pixelphone/answer/2844832?hl=en).

## GPS test on the real phone

1. Enable Android Location. Give Chrome location access while in use; enable precise location if available.
2. Open **https://192.168.1.218:8443** and allow this site's location permission. See [Chrome location settings](https://support.google.com/chrome/answer/142065?co=GENIE.Platform%3DAndroid&hl=en).
3. Choose delivery and open address selection. Press **استخدام موقعي الحالي**. Check the GPS point against your actual location and review its accuracy message. A reverse-geocoded address alone does not prove GPS accuracy.
4. Test permission refusal, retry, map movement, search suggestions, address details and coverage/fee updates. Check scrolling and keyboard behavior on your device.
5. Record phone model, Android/Chrome versions, Wi-Fi IP, permission outcome and any error message.

HTTP and HTTPS have separate browser storage/session origins, so login or basket state may need to be recreated in HTTPS. The automated browser test intercepted cart/quote writes and did not create an order or change customer data. Real phone checkout testing is still pending.

## Verified results and limitations

- Both Vue origins return 200. Optional Nginx is healthy; original Shishli services remain healthy. All 24 original container IDs were retained, including unrelated projects.
- TLS handshake authorized with **TLS 1.3**, without insecure bypasses. Certificate SAN includes 192.168.1.218, localhost and 127.0.0.1. Validity: **2026-10-14 21:04:36 UTC → 2029-01-14 21:04:36 UTC**. Wrong-IP identity was correctly rejected.
- Final API check: health, branches, public settings, banners, gallery and menu returned 200; two stored public images returned 200. `/api/v1/home` returned 404 on both origins. An intermediate banners check returned 500 on both origins; it returned 200 in the final check. This is an observed application issue, not a successful API result or a diagnosed TLS failure.
- Fifteen production assets were byte-identical between HTTP/HTTPS. No insecure absolute URLs appeared in the tested public API responses.
- Reverse geocoding and autocomplete returned 200. Final cache headers were HIT through HTTPS and for the HTTP reverse baseline; earlier MISS results were also observed. Existing provider cache policy was not changed.
- Native Chrome trusted the certificate without certificate-error flags. At mobile viewport 390×844, home/menu/checkout had `window.isSecureContext === true`, geolocation available, no horizontal overflow, no JavaScript exceptions and no HTTP resource requests. Actual Leaflet tiles loaded from OSM over HTTPS. This is desktop Chrome with a mobile viewport, not an Android hardware test.
- **Actual Android trust, Wi-Fi access, GPS accuracy and delivery completion are not yet verified.** Firewall permission is also a remaining setup step.

Evidence: [TLS/API report](evidence/local-mobile-https/tls-api-report.json), [browser report](evidence/local-mobile-https/browser-report.json), [mobile map screenshot](evidence/local-mobile-https/https-map-mobile.png). The browser test can be repeated with `node --experimental-websocket docker/mobile-https.browser.mjs`; it launches local Chrome with normal certificate validation.

## Cleanup

To remove only the optional HTTPS container and its named server-certificate/config/public-export files:

```powershell
powershell -ExecutionPolicy Bypass -File docker/mobile-https.ps1 -Action Cleanup
```

This preserves every original service and volume, the downloaded mkcert tool and the outside-project CA. To remove the proposed firewall rule, if you installed it, use Administrator PowerShell:

```powershell
Remove-NetFirewallRule -Name 'Shishli-Mobile-HTTPS-8443'
```

Optionally uninstall this dedicated Windows CA when finished:

```powershell
$previousCaRoot = $env:CAROOT
try {
    $env:CAROOT = Join-Path $env:LOCALAPPDATA 'Shishli\mobile-https-ca'
    & .\.local-mobile-https\mkcert.exe -uninstall
} finally { $env:CAROOT = $previousCaRoot }
```

On Android remove this CA from Trusted credentials → User, identifying the installed mkcert CA. Delete the transferred public `.crt` from Downloads. No root-key deletion or project-wide Docker cleanup is performed automatically. Never use `down -v`, `prune`, migrations or seeders for this setup.
