# trim_ical

**A single PHP file that trims bloated iCal feeds down to a usable window** so slow calendar clients (Home Assistant, e-paper displays, low-RAM widgets, anything that polls and parses in-process) can actually keep up.

Drop it on any PHP host. Point your client at it instead of directly at Outlook / Exchange / Google Calendar. Get back a small, well-formed feed with recurring events, cancellations, timezones, and DST handled correctly.

```
+--------------------+      +------------------+      +---------------------+
|  Outlook / GCal    |  ->  |  trim_ical.php   |  ->  |  Home Assistant     |
|  (12 MB, 2018+)    |      |   (~150 KB out)  |      |  (parses in ~ms)    |
+--------------------+      +------------------+      +---------------------+
```

---

## Why this exists

Subscribing a calendar client to your work Outlook feed *should* just work. In practice:

- Outlook / Exchange feeds carry **years of history** plus every recurring meeting expanded into hundreds of cancellation exceptions.
- Many clients (Home Assistant's `ics` integration, e-paper widgets, low-RAM devices) **download the entire feed on every poll** and parse it in-process. A 12 MB feed times out, OOMs, or freezes the device.
- Naive trimming — strip events outside a date window with regex — **breaks recurring events**: you lose `RRULE`, exceptions stop lining up, DST flips at the wrong instant, Outlook's `Abgesagt:` / `Cancelled:` exceptions come back from the dead.

`trim_ical` handles the hard parts so the upstream client gets a small, correct feed.

## What it actually does

- **Bounded output window.** Default `[now − 7d, now + 30d)`, configurable.
- **`RRULE` truncation & rebasing.** Infinite series get a bounded `UNTIL`. `DTSTART` is rebased to the first occurrence inside the window so clients don't have to expand a decade of history to find next Tuesday.
- **DST-correct recurrence.** Series are rebased in their original `TZID` so the wall-clock time stays right across DST transitions.
- **Cancellation detection.** `STATUS:CANCELLED`, German `Abgesagt:`, and Exchange's `TRANSP:TRANSPARENT + X-MICROSOFT-CDO-BUSYSTATUS:FREE` heuristic are detected and folded into proper `EXDATE` entries on the base series.
- **Recurring exceptions** (`RECURRENCE-ID` instances) are emitted as standalone `VEVENT`s with TZID / floating / UTC semantics preserved, so clients don't see duplicates.
- **Windows TZID → IANA mapping.** Exchange's `"W. Europe Standard Time"` and friends are mapped to `Europe/Berlin` etc. — first via ICU (`ext/intl`) when available, then a hand-curated fallback table.
- **`VTIMEZONE` pruning.** Only `VTIMEZONE` blocks actually referenced by output events are emitted.
- **Floating-time resolution** in priority order: `?tz=` query param → `X-WR-TIMEZONE` → `X-LIC-LOCATION` → `UTC`.

## Hardened against the obvious mistakes

This is a public-facing fetcher, so it's been deliberately tightened:

- **SSRF protection.** Resolved IPs are checked against private, loopback, link-local (including `169.254.169.254` cloud metadata), CGNAT, and IPv6 unique-local ranges. Redirects are followed manually and re-validated each hop. DNS is pinned via `CURLOPT_RESOLVE` to close the rebinding window.
- **Protocol lockdown.** `CURLOPT_PROTOCOLS` and `CURLOPT_REDIR_PROTOCOLS` are restricted to HTTP/HTTPS — no `file://`, `gopher://`, etc.
- **Constant-time token comparison** via `hash_equals`.
- **Fail-closed misconfiguration.** The script refuses to start if you forgot to replace the placeholder token.
- **Bounded download.** 20 MB hard cap enforced via a write callback (works even when the upstream lies about `Content-Length`).
- **TLS verification on by default.**

---

## Quick start

You need PHP 7.4+ with the cURL extension. Most shared hosts qualify.

**1. Grab the file.**

```bash
curl -O https://raw.githubusercontent.com/ChrisCarini/trim_ical/main/trim_ical.php
```

Upload it to your webroot however you normally do.

**2. Generate a token.**

```bash
openssl rand -hex 32
```

**3. Edit `trim_ical.php`** and replace the placeholder token near the top:

```php
$expectedToken = 'paste-your-hex-token-here';
```

The script will refuse to run until you do this.

**4. Test it.**

```bash
export HOST='example.com'                                       # the host where you uploaded trim_ical.php
export URL='https://outlook.office365.com/owa/calendar/.../calendar.ics'
export token='your-hex-token'
export tz='America/Los_Angeles'                                 # optional, IANA zone for floating times

URL_ENC=$(python3 -c 'import os,urllib.parse; print(urllib.parse.quote(os.environ["URL"], safe=""))')
curl -s "https://${HOST}/trim_ical.php?url=${URL_ENC}&token=${token}&tz=${tz}" | head -5
```

You should get back something starting with `BEGIN:VCALENDAR`. That's it.

---

## Configuration

All tunables are constants near the top of `trim_ical.php`:

| Constant | Default | Purpose |
|---|---|---|
| `ICAL_WINDOW_PAST` | `'P7D'` | How far back to keep events. Any ISO-8601 duration. |
| `ICAL_WINDOW_FUTURE` | `'P30D'` | How far forward to keep events. |
| `ICAL_MAX_DOWNLOAD_BYTES` | `20 * 1024 * 1024` | Hard cap on upstream download size. |
| `ICAL_MAX_REDIRECTS` | `5` | Max HTTP redirects to follow. |
| `$expectedToken` | placeholder | **Required.** Must be replaced before deploy. |

`ICAL_WINDOW_PAST` / `ICAL_WINDOW_FUTURE` accept anything `DateInterval` does: `P14D`, `P2W`, `P1M`, `P3M`, `P1Y6M`, etc.

## Query parameters

| Parameter | Required | Notes |
|---|---|---|
| `url` | yes | The upstream `.ics` URL, **percent-encoded**. |
| `token` | yes | Must match `$expectedToken`. |
| `tz` | no | IANA timezone (e.g. `Europe/Berlin`) used for floating times when the source feed has no `X-WR-TIMEZONE`. |

---

## How recurring events are handled

This is the part most "trim my iCal" scripts get wrong, so it's worth spelling out.

For each recurring `VEVENT`:

1. **`UNTIL` is bounded** to the window end. If the series already ended before the window, the whole `VEVENT` is dropped.
2. **`DTSTART` is rebased** to the first occurrence on or after the window start, computed in the event's own timezone. `DTEND` / `DURATION` are recomputed to preserve the original duration.
3. **Series semantics are preserved.** TZID stays TZID, floating stays floating, UTC-Z stays UTC-Z. Mixing them silently shifts events by hours.
4. **Exceptions are merged.** Cancelled `RECURRENCE-ID` instances become `EXDATE` entries on the base series. Modified-but-not-cancelled exceptions are emitted as standalone `VEVENT`s with the same UID.
5. **Unparseable TZIDs** (custom Exchange zones the mapping table doesn't cover) are detected and the original `DTSTART` / `DTEND` / `EXDATE` strings are passed through unchanged so the client's native handling takes over.

Supported `RRULE` patterns for **rebasing**: `DAILY`, `WEEKLY` (including multi-day `BYDAY` and `WKST`), `MONTHLY` (`BYDAY=2TH`, `BYDAY=-1FR`, etc.), and `YEARLY`. Anything more exotic still gets a bounded `UNTIL` — it just won't be rebased, so the client expands from the original `DTSTART`.

## Limitations

- **`VEVENT` only.** `VTODO` and `VJOURNAL` are dropped.
- **No `RDATE` support.** Explicit additional dates on a recurring series are ignored.
- **Single shared token.** No per-user auth, rate limiting, or revocation. Treat the token as a bearer credential.
- **No caching.** If you want it, put a CDN or `mod_cache` rule in front. Trim is fast (sub-100ms for typical feeds) so uncached requests are fine for most polling clients.

## FAQ

**Why PHP?**
Because every cheap shared-hosting plan in the world runs it, and this is meant to be a "drop one file and forget about it" tool. Ports to other languages (Node, Go, Python, etc.) are welcome — open a PR and add yours alongside the PHP version in this repo.

**Why not Cloudflare Workers / Vercel / Lambda?**
Those are great too. This is for people who already have an Apache/Nginx + PHP host sitting around.

**The token is in my URL — isn't that bad?**
It ends up in webserver and proxy logs. Acceptable for personal use, less ideal for multi-user deployments. If that matters, modify the script to read from an `Authorization` header instead.

**My events show up at the wrong time.**
Pass `?tz=America/New_York` (or your zone). This kicks in for feeds with floating times and no `X-WR-TIMEZONE` declaration.

**It returned `Server misconfigured`.**
You haven't replaced the placeholder token in `trim_ical.php`. That's intentional.

## Contributing

PRs welcome.

## License

[MIT](LICENSE)
