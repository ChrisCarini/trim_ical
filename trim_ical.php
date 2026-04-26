<?php
/**
 * trim_ical.php?url=https%3A%2F%2Fcalendar...ical(ical url, url encoded)&token=YOUR-RANDOM-TOKEN&tz=Europe/Berlin (optional) - pick your local timezone for floating times
 */

declare(strict_types=1);

$url = $_GET['url'] ?? '';

$token = $_GET['token'] ?? '';
// TODO: ADD A RANDOM TOKEN HERE
if($token != "YOUR-RANDOM-TOKEN") {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Invalid token!\n";
    exit;
}
if (!$url) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Missing required parameter: url\n";
    exit;
}
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Invalid url\n";
    exit;
}

$parts = parse_url($url);
$scheme = strtolower($parts['scheme'] ?? '');
if (!in_array($scheme, ['http', 'https'], true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Only http/https URLs are allowed\n";
    exit;
}

$ical = download_url($url);
if ($ical === null) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Failed to download iCal\n";
    exit;
}

$ical = normalize_newlines($ical);
$lines = unfold_ical_lines(explode("\n", $ical));

// Window: [now-7d, now+30d)
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$windowStart = $now->sub(new DateInterval('P7D'));
$windowEnd   = $now->add(new DateInterval('P30D'));

// Parse VCALENDAR into components (keep VTIMEZONE blocks so TZID/DST can be preserved)
$calendar = parse_vcalendar_components($lines);
$headerLines    = $calendar['header'];      // lines to keep (BEGIN:VCALENDAR..)
$vtimezones     = $calendar['vtimezones'];  // array of VTIMEZONE blocks (array of lines)
$vevents        = $calendar['vevents'];     // array of VEVENT blocks (array of lines)
$footerLines    = $calendar['footer'];      // END:VCALENDAR etc

// Default timezone for "floating" date-times (no TZID and no trailing Z)
// Priority: explicit query param ?tz=America/New_York -> calendar header (X-WR-TIMEZONE/X-LIC-LOCATION) -> UTC
$GLOBALS['ICAL_DEFAULT_TZ'] = resolve_default_ical_timezone($_GET['tz'] ?? '', $headerLines);

// Build maps: base recurring events by UID, and exception instances by UID
$baseRecurringByUid = [];  // uid => ['lines'=>..., 'props'=>..., 'idx'=>...]
$singleEvents = [];        // kept non-recurring / standalone events blocks
$exceptionsByUid = [];     // uid => array of exdate items (DATE or DATE-TIME in UTC)
$exceptionStandalone = []; // standalone VEVENTs for non-cancelled exceptions (already normalized)

// 1st pass: classify VEVENTs, collect exceptions
foreach ($vevents as $block) {
    $parsed = parse_event_properties_with_indexes($block);
    $props  = $parsed['props'];
    $idx    = $parsed['idx'];

    $uid = trim((string)($props['UID'] ?? ''));
    if ($uid === '') continue;

    $hasRecId = isset($props['RECURRENCE-ID']);
    $hasRrule = isset($props['RRULE']);

    if ($hasRecId) {
        // Exception instance for a recurring series
        $recIdItem = normalize_to_exdate_item($props['RECURRENCE-ID'], $props['RECURRENCE-ID_PARAMS'] ?? []);
        if ($recIdItem !== null) {
            $exceptionsByUid[$uid][] = $recIdItem;
        }

        $isCancelled = looks_like_cancellation($props);

        if (!$isCancelled) {
            // Keep as standalone single instance (but without RECURRENCE-ID/RRULE/EXDATE/X-*)
            $standalone = build_standalone_from_exception($block, $props, $idx);
            if ($standalone !== null) {
                // Trim standalone to window
                $p2 = parse_event_properties_with_indexes($standalone)['props'];
                if (event_overlaps_window_props($p2, $windowStart, $windowEnd)) {
                    $exceptionStandalone[] = $standalone;
                }
            }
        }
        // If cancelled, do not emit exception VEVENT (we'll exclude via EXDATE on base)
        continue;
    }

    if ($hasRrule) {
        // Base recurring series
        $baseRecurringByUid[$uid] = ['lines' => $block, 'props' => $props, 'idx' => $idx];
        continue;
    }

    // Non-recurring
    if (event_overlaps_window_props($props, $windowStart, $windowEnd)) {
        $singleEvents[] = minimize_event_block($block, $props, $idx);
    }
}

// 2nd pass: process recurring series (trim/rebase/bound + apply EXDATE for exceptions)
$processedRecurring = [];

foreach ($baseRecurringByUid as $uid => $item) {
    $block = $item['lines'];
    $props = $item['props'];
    $idx   = $item['idx'];

    $exdates = $exceptionsByUid[$uid] ?? [];
    $exdates = unique_sorted_exdates($exdates);

    $processed = process_recurring_series($block, $props, $idx, $windowStart, $windowEnd, $exdates);
    if ($processed !== null) {
        $processedRecurring[] = $processed;
    }
}

// Merge: header + selected VTIMEZONE + VEVENTs + footer
$out = [];
foreach ($headerLines as $l) $out[] = $l;

// Only emit VTIMEZONE components that are referenced by output events.
$allEventBlocks = array_merge($processedRecurring, $singleEvents, $exceptionStandalone);
$neededTzids = collect_tzids_from_event_blocks($allEventBlocks);
foreach (select_vtimezones_by_tzid($vtimezones, $neededTzids) as $tzBlock) {
    foreach ($tzBlock as $l) $out[] = $l;
}

// emit recurring series first, then single events, then exception-standalones
foreach ($processedRecurring as $b) foreach ($b as $l) $out[] = $l;
foreach ($singleEvents as $b)        foreach ($b as $l) $out[] = $l;
foreach ($exceptionStandalone as $b) foreach ($b as $l) $out[] = $l;

foreach ($footerLines as $l) $out[] = $l;

// Fold (byte-based, faster, and RFC is octets anyway)
$outText = fold_ical_lines_bytes($out);

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="trimmed.ics"');
echo $outText;


/* ============================ helpers ============================ */

function download_url(string $url): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'ical-trimmer/2.0',
        ]);
        $data = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($data === false || $code < 200 || $code >= 300) return null;
        return (string)$data;
    }
    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => 20, 'header' => "User-Agent: ical-trimmer/2.0\r\n"],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false) return null;
    return (string)$data;
}

function normalize_newlines(string $s): string {
    return str_replace(["\r\n", "\r"], "\n", $s);
}

/** Unfold: continuation lines (space/tab) get concatenated to previous */
function unfold_ical_lines(array $lines): array {
    $out = [];
    $current = '';
    foreach ($lines as $line) {
        if ($line === '') {
            if ($current !== '') { $out[] = $current; $current = ''; }
            $out[] = '';
            continue;
        }
        if (isset($line[0]) && ($line[0] === ' ' || $line[0] === "\t")) {
            $current .= substr($line, 1);
        } else {
            if ($current !== '') $out[] = $current;
            $current = $line;
        }
    }
    if ($current !== '') $out[] = $current;
    return $out;
}

/** Fold at 75 octets (byte-based), fast */
function fold_ical_lines_bytes(array $lines): string {
    $out = [];
    foreach ($lines as $line) {
        if ($line === '') { $out[] = ''; continue; }
        while (strlen($line) > 75) {
            $out[] = substr($line, 0, 75);
            $line = ' ' . substr($line, 75);
        }
        $out[] = $line;
    }
    return implode("\r\n", $out) . "\r\n";
}

/**
 * Parse VCALENDAR components.
 * Returns: ['header'=>[], 'vtimezones'=>[blockLines...], 'vevents'=>[blockLines...], 'footer'=>[]]
 */
function parse_vcalendar_components(array $lines): array {
    $header = [];
    $footer = [];
     $vtimezones = [];
    $vevents = [];

    $inVevent = false;
    $inVtimezone = false;
    $vevent = [];
    $vtimezone = [];

    $seenBeginCal = false;

    foreach ($lines as $line) {
        $t = trim($line);

        if ($t === 'BEGIN:VCALENDAR') {
            $seenBeginCal = true;
            $header[] = $line;
            continue;
        }
        if (!$seenBeginCal) continue;

        if ($t === 'BEGIN:VTIMEZONE') {
            $inVtimezone = true;
            $vtimezone = [$line];
            continue;
        }
        if ($inVtimezone) {
            $vtimezone[] = $line;
            if ($t === 'END:VTIMEZONE') {
                $inVtimezone = false;
                $vtimezones[] = $vtimezone;
                $vtimezone = [];
            }
            continue;
        }

        if ($t === 'BEGIN:VEVENT') {
            $inVevent = true;
            $vevent = [$line];
            continue;
        }

        if ($inVevent) {
            $vevent[] = $line;
            if ($t === 'END:VEVENT') {
                $inVevent = false;
                $vevents[] = $vevent;
                $vevent = [];
            }
            continue;
        }

        if ($t === 'END:VCALENDAR') {
            $footer[] = $line;
            continue;
        }

        // Keep everything else in header (METHOD, PRODID, VERSION, etc.)
        $header[] = $line;
    }

    // Ensure END:VCALENDAR exists
    if (empty($footer)) $footer[] = 'END:VCALENDAR';

    return ['header' => $header, 'vtimezones' => $vtimezones, 'vevents' => $vevents, 'footer' => $footer];
}

function collect_tzids_from_event_blocks(array $eventBlocks): array {
    $tzids = [];
    foreach ($eventBlocks as $block) {
        if (!is_array($block)) continue;
        foreach ($block as $line) {
            $t = trim((string)$line);
            if ($t === '' || stripos($t, 'TZID=') === false) continue;
            if (preg_match('/(?:^|;)(TZID)=([^;:]+)(?:[;:]|$)/i', $t, $m)) {
                $tzid = trim($m[2], '"');
                if ($tzid !== '') $tzids[$tzid] = true;
            }
        }
    }
    return array_keys($tzids);
}

function select_vtimezones_by_tzid(array $vtimezones, array $neededTzids): array {
    if (!$vtimezones || !$neededTzids) return [];
    $need = array_fill_keys($neededTzids, true);
    $out = [];
    foreach ($vtimezones as $block) {
        $tzid = parse_vtimezone_tzid($block);
        if ($tzid !== null && isset($need[$tzid])) $out[] = $block;
    }
    return $out;
}

function parse_vtimezone_tzid(array $vtimezoneBlock): ?string {
    foreach ($vtimezoneBlock as $line) {
        $t = trim((string)$line);
        if (stripos($t, 'TZID') === 0) {
            $pos = strpos($t, ':');
            if ($pos === false) continue;
            $val = trim(substr($t, $pos + 1));
            $val = trim($val, '"');
            if ($val !== '') return $val;
        }
    }
    return null;
}

function parse_event_properties_with_indexes(array $eventLines): array {
    $props = [];
    $idx = [];
    foreach ($eventLines as $i => $line) {
        $t = trim($line);
        if ($t === '' || $t === 'BEGIN:VEVENT' || $t === 'END:VEVENT') continue;

        $posColon = strpos($t, ':');
        if ($posColon === false) continue;

        $left = substr($t, 0, $posColon);
        $value = substr($t, $posColon + 1);

        $parts = explode(';', $left);
        $name = strtoupper(array_shift($parts));

        $params = [];
        foreach ($parts as $p) {
            $eq = strpos($p, '=');
            if ($eq === false) continue;
            $k = strtoupper(substr($p, 0, $eq));
            $v = substr($p, $eq + 1);
            $params[$k] = $v;
        }

        $props[$name] = $value;
        if (!empty($params)) $props[$name . '_PARAMS'] = $params;
        if (!isset($idx[$name])) $idx[$name] = $i;
    }
    return ['props' => $props, 'idx' => $idx];
}

/* ---------- Cancellation heuristic ---------- */

function looks_like_cancellation(array $props): bool {
    $status = strtoupper(trim((string)($props['STATUS'] ?? '')));
    if ($status === 'CANCELLED' || $status === 'CANCELED') return true;

    $summary = trim((string)($props['SUMMARY'] ?? ''));
    if ($summary !== '') {
        if (preg_match('/^(abgesagt|cancelled|canceled)\s*:/i', $summary)) return true;
        if (preg_match('/\b(abgesagt|cancelled|canceled)\b/i', $summary) && isset($props['RECURRENCE-ID'])) return true;
    }

    // Some Exchange cancellations come as "free + transparent" exceptions
    $transp = strtoupper(trim((string)($props['TRANSP'] ?? '')));
    $busy = strtoupper(trim((string)($props['X-MICROSOFT-CDO-BUSYSTATUS'] ?? '')));
    if ($transp === 'TRANSPARENT' && $busy === 'FREE') {
        // treat as cancel-ish exception
        return true;
    }

    return false;
}

/* ---------- Minimal output + UTC normalization ---------- */

function minimize_event_block(array $block, array $props, array $idx): array {
    // Keep only a small whitelist
    $keep = ['UID', 'SUMMARY', 'DTSTART', 'DTEND', 'DURATION', 'RRULE', 'EXDATE'];
    $out = ['BEGIN:VEVENT'];

    foreach ($keep as $k) {
        if (!isset($props[$k])) continue;
        if ($k === 'DTSTART' || $k === 'DTEND') {
            $norm = normalize_dt_line_to_utc($k, $props[$k], $props[$k . '_PARAMS'] ?? []);
            if ($norm !== null) $out[] = $norm;
            continue;
        }
        if ($k === 'EXDATE') {
            // drop original EXDATE; we rebuild EXDATE only for base series
            continue;
        }
        $out[] = $k . ':' . trim((string)$props[$k]);
    }

    $out[] = 'END:VEVENT';
    return $out;
}

function build_standalone_from_exception(array $block, array $props, array $idx): ?array {
    // Standalone event should represent the modified instance only:
    // Keep UID (same UID is OK), SUMMARY, DTSTART/DTEND/DURATION.
    // Remove RECURRENCE-ID, RRULE, EXDATE, all X-* props.
    if (!isset($props['DTSTART'])) return null;

    $out = ['BEGIN:VEVENT'];

    if (isset($props['UID'])) $out[] = 'UID:' . trim((string)$props['UID']);
    if (isset($props['SUMMARY'])) $out[] = 'SUMMARY:' . trim((string)$props['SUMMARY']);

    // Preserve TZID/floating/Z semantics for recurring exceptions to avoid duplicates on edit.
    $dtstart = build_dt_line_preserving_tz('DTSTART', $props['DTSTART'], $props['DTSTART_PARAMS'] ?? []);
    if ($dtstart === null) return null;
    $out[] = $dtstart;

    if (isset($props['DTEND'])) {
        $dtend = build_dt_line_preserving_tz('DTEND', $props['DTEND'], $props['DTEND_PARAMS'] ?? []);
        if ($dtend !== null) $out[] = $dtend;
    } elseif (isset($props['DURATION'])) {
        $out[] = 'DURATION:' . trim((string)$props['DURATION']);
    } else {
        // Instant -> 1 second
        $start = parse_ical_datetime_local($props['DTSTART'], $props['DTSTART_PARAMS'] ?? []);
        if ($start) {
            $end = $start->add(new DateInterval('PT1S'));
            $out[] = build_dt_line_from_datetime('DTEND', $end, $props['DTSTART_PARAMS'] ?? [], (string)$props['DTSTART']);
        }
    }

    $out[] = 'END:VEVENT';
    return $out;
}

function normalize_dt_line_to_utc(string $name, string $rawValue, array $params): ?string {
    $rawValue = trim($rawValue);

    // DATE-only stays DATE-only (no TZID)
    $isDateOnly = (isset($params['VALUE']) && strtoupper($params['VALUE']) === 'DATE') || preg_match('/^\d{8}$/', $rawValue);
    if ($isDateOnly) {
        return $name . ';VALUE=DATE:' . $rawValue;
    }

    $dt = parse_ical_datetime($rawValue, $params);
    if (!$dt) return null;
    return $name . ':' . $dt->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
}

function normalize_to_exdate_item(string $rawValue, array $params): ?array {
    $rawValue = trim($rawValue);

    $isDateOnly = (isset($params['VALUE']) && strtoupper($params['VALUE']) === 'DATE') || preg_match('/^\d{8}$/', $rawValue);
    if ($isDateOnly) {
        return ['type' => 'DATE', 'date' => $rawValue, 'sort' => $rawValue];
    }

    // Store exception instants in UTC; we will format EXDATE to match the base series TZID later.
    $dt = parse_ical_datetime($rawValue, $params);
    if (!$dt) return null;
    return [
        'type' => 'DATETIME',
        'utc' => $dt->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),
        'sort' => $dt->getTimestamp(),
        'raw' => $rawValue,
        'rawParams' => $params,
    ];
}

function unique_sorted_exdates(array $items): array {
    // Uniqueness on canonical UTC (DATETIME) / DATE string.
    $seen = [];
    $out = [];
    foreach ($items as $it) {
        if (!is_array($it) || !isset($it['type'])) continue;
        $key = null;
        if ($it['type'] === 'DATE' && isset($it['date'])) $key = 'D:' . $it['date'];
        if ($it['type'] === 'DATETIME' && isset($it['utc'])) $key = 'T:' . $it['utc'];
        if ($key === null || isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $it;
    }
    usort($out, function ($a, $b) {
        $sa = $a['sort'] ?? 0;
        $sb = $b['sort'] ?? 0;
        if ($sa === $sb) return 0;
        return ($sa < $sb) ? -1 : 1;
    });
    return $out;
}

/* ---------- Trimming logic ---------- */

function event_overlaps_window_props(array $props, DateTimeImmutable $ws, DateTimeImmutable $we): bool {
    list($start, $endExclusive) = get_event_interval_exclusive_end($props);
    if ($start === null || $endExclusive === null) return true;
    return ($start < $we) && ($endExclusive > $ws);
}

function get_event_interval_exclusive_end(array $props): array {
    $dtStartRaw = $props['DTSTART'] ?? null;
    $dtStartParams = $props['DTSTART_PARAMS'] ?? [];
    $start = parse_ical_datetime($dtStartRaw, $dtStartParams);
    if (!$start) return [null, null];

    $isDateOnly = is_date_only($dtStartRaw, $dtStartParams);

    if (isset($props['DTEND'])) {
        $end = parse_ical_datetime($props['DTEND'], $props['DTEND_PARAMS'] ?? []);
        if (!$end) return [$start, $start->add(new DateInterval('PT1S'))];
        return [$start, $end];
    }

    if (isset($props['DURATION'])) {
        $end = apply_duration($start, $props['DURATION']);
        return [$start, $end];
    }

    if ($isDateOnly) {
        return [$start, $start->add(new DateInterval('P1D'))];
    }

    return [$start, $start->add(new DateInterval('PT1S'))];
}

function is_date_only($value, array $params): bool {
    if ($value === null) return false;
    $value = trim((string)$value);
    return (isset($params['VALUE']) && strtoupper($params['VALUE']) === 'DATE') || preg_match('/^\d{8}$/', $value);
}

/* ---------- Recurring series processing: bound + rebase + apply EXDATE ---------- */
function process_recurring_series(array $block, array $props, array $idx, DateTimeImmutable $ws, DateTimeImmutable $we, array $exdates): ?array {
    if (!isset($props['DTSTART']) || !isset($props['RRULE'])) return null;

    $dtStartParams = $props['DTSTART_PARAMS'] ?? [];
    $isAllDay = is_date_only($props['DTSTART'], $dtStartParams);

    // Keep DTSTART timezone semantics for recurring events (TZID/floating/Z) while still comparing in absolute time.
    $dtStartLocal = parse_ical_datetime_local($props['DTSTART'], $dtStartParams);
    if (!$dtStartLocal) return null;
    $dtStartUtc = $dtStartLocal->setTimezone(new DateTimeZone('UTC'));

    $rule = parse_rrule(trim((string)$props['RRULE']));

    // ----- Bound UNTIL / drop if ended -----
    if (!isset($rule['UNTIL']) && !isset($rule['COUNT'])) {
        // Infinite series: bound to window end
        if ($isAllDay) {
            $rule['UNTIL'] = $we->setTimezone(new DateTimeZone('UTC'))->format('Ymd');
        } else {
            // Preserve UNTIL style: if series is TZID/floating, prefer local UNTIL (no Z); otherwise use UTC Z.
            $useLocalUntil = !ends_with_z(trim((string)$props['DTSTART'])) && !empty($dtStartParams['TZID']);
            if ($useLocalUntil) {
                $rule['UNTIL'] = $we->setTimezone($dtStartLocal->getTimezone())->format('Ymd\THis');
            } else {
                $rule['UNTIL'] = $we->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
            }
        }
    } elseif (isset($rule['UNTIL'])) {
        $until = parse_rrule_until((string)$rule['UNTIL'], $dtStartParams);
        if ($until && $until < $ws) return null;
        if ($until && $until > $we) {
            if ($isAllDay) {
                $rule['UNTIL'] = $we->setTimezone(new DateTimeZone('UTC'))->format('Ymd');
            } else {
                // Keep existing UNTIL style when truncating.
                $untilRaw = trim((string)$rule['UNTIL']);
                if (ends_with_z($untilRaw)) {
                    $rule['UNTIL'] = $we->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
                } else {
                    $rule['UNTIL'] = $we->setTimezone($dtStartLocal->getTimezone())->format('Ymd\THis');
                }
            }
        }
    }

    // ----- Rebase DTSTART to first occurrence in window -----
    // Compute in the event's timezone to keep DST-correct wall clock recurrence.
    $tzid = !empty($dtStartParams['TZID']) ? trim((string)$dtStartParams['TZID']) : '';
    $tzidParseable = ($tzid !== '') ? tzid_is_parseable($tzid) : true;

    if ($tzid !== '' && !$tzidParseable) {
        // If TZID is custom/unparseable, do NOT rewrite DTSTART/DTEND: preserve raw values.
        $newStartLocal = $dtStartLocal;
    } else {
        $wsLocal = $ws->setTimezone($dtStartLocal->getTimezone());
        $newStartLocal = next_occurrence_on_or_after($dtStartLocal, $rule, $wsLocal);
        if ($newStartLocal === null) {
            // Can't compute; keep bounded series only if DTSTART could still be relevant
            if ($dtStartUtc >= $we) return null;
            $newStartLocal = $dtStartLocal;
        }
        if ($newStartLocal->setTimezone(new DateTimeZone('UTC')) >= $we) return null;
    }

    // ----- Compute original duration -----
    // For DATE events: duration in whole days (DTEND is exclusive)
    $durationDays = 1;
    if ($isAllDay) {
        if (isset($props['DTEND'])) {
            $oldEnd = parse_ical_datetime($props['DTEND'], $props['DTEND_PARAMS'] ?? []);
            if ($oldEnd) {
                $sec = $oldEnd->getTimestamp() - $dtStartUtc->getTimestamp();
                if ($sec > 0) {
                    $durationDays = max(1, (int)round($sec / 86400));
                }
            }
        } elseif (isset($props['DURATION'])) {
            $oldEnd = apply_duration($dtStartUtc, (string)$props['DURATION']);
            $sec = $oldEnd->getTimestamp() - $dtStartUtc->getTimestamp();
            if ($sec > 0) $durationDays = max(1, (int)round($sec / 86400));
        }
    }

    // ----- Build output VEVENT (minimal, HA-fast) -----
    $out = ['BEGIN:VEVENT'];

    $uid = trim((string)($props['UID'] ?? ''));
    if ($uid !== '') $out[] = 'UID:' . $uid;

    if (isset($props['SUMMARY'])) $out[] = 'SUMMARY:' . trim((string)$props['SUMMARY']);

    if ($isAllDay) {
        // IMPORTANT: keep all-day events as VALUE=DATE, no timezone conversion.
        $startDate = $newStartLocal->setTimezone(new DateTimeZone('UTC'))->format('Ymd');
        $endDate   = $newStartLocal->add(new DateInterval('P' . $durationDays . 'D'))
                              ->setTimezone(new DateTimeZone('UTC'))->format('Ymd');

        $out[] = 'DTSTART;VALUE=DATE:' . $startDate;
        $out[] = 'DTEND;VALUE=DATE:' . $endDate;

        // RRULE UNTIL must be DATE for DATE-based series (avoid “midnight Z” semantics)
        if (isset($rule['UNTIL']) && preg_match('/^\d{8}T/', (string)$rule['UNTIL'])) {
            $rule['UNTIL'] = $we->setTimezone(new DateTimeZone('UTC'))->format('Ymd');
        }
    } else {
        if ($tzid !== '' && !$tzidParseable) {
            // Preserve raw DTSTART/DTEND/DURATION exactly.
            $out[] = build_dt_line_preserving_tz('DTSTART', (string)$props['DTSTART'], $dtStartParams);
            if (isset($props['DTEND'])) {
                $out[] = build_dt_line_preserving_tz('DTEND', (string)$props['DTEND'], $props['DTEND_PARAMS'] ?? []);
            } elseif (isset($props['DURATION'])) {
                $out[] = 'DURATION:' . trim((string)$props['DURATION']);
            } else {
                $out[] = build_dt_line_from_datetime('DTEND', $dtStartLocal->add(new DateInterval('PT1S')), $dtStartParams, (string)$props['DTSTART']);
            }
        } else {
            // Timed recurring events: preserve TZID/floating/Z semantics to keep DST behavior stable.
            $out[] = build_dt_line_from_datetime('DTSTART', $newStartLocal, $dtStartParams, (string)$props['DTSTART']);

            // Preserve duration (in seconds) relative to DTSTART.
            $durationSeconds = null;
            if (isset($props['DTEND'])) {
                $oldEndLocal = parse_ical_datetime_local($props['DTEND'], $props['DTEND_PARAMS'] ?? []);
                if ($oldEndLocal) {
                    $durationSeconds = $oldEndLocal->setTimezone(new DateTimeZone('UTC'))->getTimestamp() - $dtStartUtc->getTimestamp();
                }
            } elseif (isset($props['DURATION'])) {
                $oldEndUtc = apply_duration($dtStartUtc, (string)$props['DURATION']);
                $durationSeconds = $oldEndUtc->getTimestamp() - $dtStartUtc->getTimestamp();
            }

            if ($durationSeconds === null) $durationSeconds = 1;
            if ($durationSeconds < 1) $durationSeconds = 1;
            $newEndLocal = $newStartLocal->add(new DateInterval('PT' . $durationSeconds . 'S'));
            $out[] = build_dt_line_from_datetime('DTEND', $newEndLocal, $dtStartParams, (string)$props['DTSTART']);
        }
    }

    $out[] = 'RRULE:' . build_rrule($rule);

    // EXDATE: match the base DTSTART timezone semantics (TZID/floating/Z) to avoid duplicates.
    if (!empty($exdates)) {
        if ($isAllDay) {
            $dateTokens = [];
            foreach ($exdates as $it) {
                if (is_array($it) && ($it['type'] ?? '') === 'DATE' && isset($it['date'])) {
                    $dateTokens[] = $it['date'];
                }
            }
            if ($dateTokens) {
                $dateTokens = array_values(array_unique($dateTokens));
                sort($dateTokens);
                $out[] = 'EXDATE;VALUE=DATE:' . implode(',', $dateTokens);
            }
        } else {
            $tokens = [];
            foreach ($exdates as $it) {
                if (!is_array($it) || ($it['type'] ?? '') !== 'DATETIME' || empty($it['utc'])) continue;
                if ($tzid !== '' && !$tzidParseable) {
                    // Use original raw values (typically RECURRENCE-ID) to match the server's TZID rules.
                    if (!empty($it['raw'])) $tokens[] = trim((string)$it['raw']);
                    continue;
                }

                $dtUtc = DateTimeImmutable::createFromFormat('Ymd\THis\Z', (string)$it['utc'], new DateTimeZone('UTC'));
                if ($dtUtc === false) continue;
                $tokens[] = format_dt_for_params($dtUtc->setTimezone($dtStartLocal->getTimezone()), $dtStartParams, (string)$props['DTSTART']);
            }
            $tokens = array_values(array_unique($tokens));
            sort($tokens);
            if ($tokens) {
                if (!empty($dtStartParams['TZID']) && !ends_with_z(trim((string)$props['DTSTART']))) {
                    $out[] = 'EXDATE;TZID=' . trim((string)$dtStartParams['TZID']) . ':' . implode(',', $tokens);
                } else {
                    $out[] = 'EXDATE:' . implode(',', $tokens);
                }
            }
        }
    }

    $out[] = 'END:VEVENT';
    return $out;
}


/* ---------- RRULE parsing/building and next occurrence ---------- */

function parse_rrule(string $rrule): array {
    $out = [];
    foreach (explode(';', $rrule) as $part) {
        $eq = strpos($part, '=');
        if ($eq === false) continue;
        $k = strtoupper(trim(substr($part, 0, $eq)));
        $v = trim(substr($part, $eq + 1));
        $out[$k] = $v;
    }
    return $out;
}

function build_rrule(array $rule): string {
    $order = ['FREQ','INTERVAL','BYDAY','BYMONTHDAY','BYMONTH','BYSETPOS','WKST','COUNT','UNTIL'];
    $parts = [];
    foreach ($order as $k) {
        if (isset($rule[$k])) {
            $parts[] = $k . '=' . $rule[$k];
            unset($rule[$k]);
        }
    }
    foreach ($rule as $k => $v) $parts[] = strtoupper($k) . '=' . $v;
    return implode(';', $parts);
}

function parse_rrule_until(string $untilRaw, array $dtstartParams): ?DateTimeImmutable {
    $untilRaw = trim($untilRaw);
    $params = [];
    if (preg_match('/^\d{8}$/', $untilRaw)) $params['VALUE'] = 'DATE';
    // Prefer DTSTART TZID mapping if non-Z UNTIL; otherwise UTC
    if (substr($untilRaw, -1) !== 'Z' && isset($dtstartParams['TZID'])) $params['TZID'] = $dtstartParams['TZID'];
    return parse_ical_datetime($untilRaw, $params);
}

/**
 * Supports:
 * - FREQ=WEEKLY with BYDAY (incl. multiple), WKST (optional), INTERVAL
 * - FREQ=MONTHLY with BYDAY like 2TH / -1FR (single), INTERVAL
 * - FREQ=DAILY/WEEKLY/MONTHLY/YEARLY simple fallbacks
 */
function next_occurrence_on_or_after(DateTimeImmutable $dtStart, array $rule, DateTimeImmutable $ws): ?DateTimeImmutable {
    $freq = strtoupper((string)($rule['FREQ'] ?? ''));
    $interval = 1;
    if (isset($rule['INTERVAL']) && ctype_digit((string)$rule['INTERVAL'])) $interval = max(1, (int)$rule['INTERVAL']);

    if ($dtStart >= $ws) return $dtStart;

    if ($freq === 'WEEKLY' && isset($rule['BYDAY'])) {
        $bydays = parse_byday_list((string)$rule['BYDAY']);
        if (!$bydays) return null;
        $wkst = strtoupper((string)($rule['WKST'] ?? 'MO'));
        $wkstNum = weekday_to_num($wkst) ?? 1;
        return next_weekly_byday($dtStart, $ws, $bydays, $interval, $wkstNum);
    }

    if ($freq === 'MONTHLY' && isset($rule['BYDAY']) && preg_match('/^([+-]?\d)(MO|TU|WE|TH|FR|SA|SU)$/', strtoupper(trim((string)$rule['BYDAY'])), $m)) {
        $nth = (int)$m[1]; // e.g. 2 or -1
        $dow = weekday_to_num($m[2]);
        if ($dow === null || $nth === 0) return null;
        return next_monthly_nth_weekday($dtStart, $ws, $interval, $nth, $dow);
    }

    // Simple fallbacks (calendar-aware; avoid fixed-second jumps across DST)
    switch ($freq) {
        case 'DAILY':   return jump_by_days($dtStart, $ws, $interval);
        case 'WEEKLY':  return jump_by_days($dtStart, $ws, $interval * 7);
        case 'MONTHLY': return jump_by_months($dtStart, $ws, $interval);
        case 'YEARLY':  return jump_by_years($dtStart, $ws, $interval);
        default: return null;
    }
}

function parse_byday_list(string $s): array {
    $out = [];
    foreach (explode(',', strtoupper(trim($s))) as $tok) {
        $tok = trim($tok);
        if ($tok === '') continue;
        $tok = preg_replace('/^[+-]?\d+/', '', $tok); // drop numeric prefixes
        $n = weekday_to_num($tok);
        if ($n !== null) $out[] = $n;
    }
    $out = array_values(array_unique($out));
    sort($out);
    return $out;
}

function weekday_to_num(string $w): ?int {
    switch ($w) {
        case 'MO': return 1;
        case 'TU': return 2;
        case 'WE': return 3;
        case 'TH': return 4;
        case 'FR': return 5;
        case 'SA': return 6;
        case 'SU': return 7;
        default: return null;
    }
}

function week_start(DateTimeImmutable $dt, int $wkstNum): DateTimeImmutable {
    $dow = (int)$dt->format('N'); // 1..7
    $shift = ($dow - $wkstNum + 7) % 7;
    return $dt->setTime(0,0,0)->sub(new DateInterval('P' . $shift . 'D'));
}

function next_weekly_byday(DateTimeImmutable $dtStart, DateTimeImmutable $ws, array $bydays, int $interval, int $wkstNum): ?DateTimeImmutable {
    $cursor = ($ws > $dtStart) ? $ws : $dtStart;

    $baseWeekStart = week_start($dtStart, $wkstNum);
    $curWeekStart  = week_start($cursor, $wkstNum);

    $weeksDiff = diff_weeks($baseWeekStart, $curWeekStart);

    $mod = $weeksDiff % $interval;
    if ($mod !== 0) {
        $weeksDiff += ($interval - $mod);
        $curWeekStart = $baseWeekStart->add(new DateInterval('P' . ($weeksDiff * 7) . 'D'));
    }

    for ($guard = 0; $guard < 520; $guard++) {
        foreach ($bydays as $dow) {
            $offset = (($dow - $wkstNum + 7) % 7);
            $candDate = $curWeekStart->add(new DateInterval('P' . $offset . 'D'));
            $cand = $candDate->setTime((int)$dtStart->format('H'), (int)$dtStart->format('i'), (int)$dtStart->format('s'));
            if ($cand >= $cursor) return $cand;
        }
        $curWeekStart = $curWeekStart->add(new DateInterval('P' . ($interval * 7) . 'D'));
    }
    return null;
}

function next_monthly_nth_weekday(DateTimeImmutable $dtStart, DateTimeImmutable $ws, int $interval, int $nth, int $dow): ?DateTimeImmutable {
    // Jump to the month containing ws, aligned to interval from dtStart's month
    $startIndex = ((int)$dtStart->format('Y')) * 12 + ((int)$dtStart->format('n') - 1);
    $targetIndex = ((int)$ws->format('Y')) * 12 + ((int)$ws->format('n') - 1);

    $diff = $targetIndex - $startIndex;
    $steps = (int)ceil($diff / $interval);
    if ($steps < 0) $steps = 0;

    $monthCursor = $dtStart->add(new DateInterval('P' . ($steps * $interval) . 'M'));

    for ($guard = 0; $guard < 240; $guard++) {
        $y = (int)$monthCursor->format('Y');
        $m = (int)$monthCursor->format('n');
        $cand = nth_weekday_of_month_local($y, $m, $nth, $dow, $dtStart->getTimezone());
        if ($cand) {
            $cand = $cand->setTime((int)$dtStart->format('H'), (int)$dtStart->format('i'), (int)$dtStart->format('s'));
            if ($cand >= $ws) return $cand;
        }
        $monthCursor = $monthCursor->add(new DateInterval('P' . $interval . 'M'));
    }

    return null;
}

function nth_weekday_of_month_local(int $year, int $month, int $nth, int $dow, DateTimeZone $tz): ?DateTimeImmutable {
    // $dow: 1..7 (Mon..Sun)
    $first = DateTimeImmutable::createFromFormat('!Y-n-j', $year . '-' . $month . '-1', $tz);
    if ($first === false) return null;

    if ($nth > 0) {
        $firstDow = (int)$first->format('N');
        $delta = ($dow - $firstDow + 7) % 7;
        $day = 1 + $delta + 7 * ($nth - 1);
        $cand = DateTimeImmutable::createFromFormat('!Y-n-j', $year . '-' . $month . '-' . $day, $tz);
        return ($cand && (int)$cand->format('n') === $month) ? $cand : null;
    }

    // nth < 0: e.g. -1 = last weekday
    $last = $first->add(new DateInterval('P1M'))->sub(new DateInterval('P1D'));
    $lastDow = (int)$last->format('N');
    $deltaBack = ($lastDow - $dow + 7) % 7;
    $cand = $last->sub(new DateInterval('P' . $deltaBack . 'D'));
    return $cand;
}

function jump_by_days(DateTimeImmutable $start, DateTimeImmutable $target, int $stepDays): DateTimeImmutable {
    if ($start >= $target) return $start;
    $stepDays = max(1, $stepDays);

    // Work in calendar days (midnight) to avoid DST/seconds assumptions.
    $startDate = $start->setTime(0, 0, 0);
    $targetDate = $target->setTime(0, 0, 0);
    $diff = $startDate->diff($targetDate);
    $daysBetween = (int)($diff->days ?? 0);

    // Fast-forward close to the target date, then correct for time-of-day.
    $roughSteps = intdiv(max(0, $daysBetween), $stepDays);
    $cand = $start->add(new DateInterval('P' . ($roughSteps * $stepDays) . 'D'));

    for ($guard = 0; $guard < 4000 && $cand < $target; $guard++) {
        $cand = $cand->add(new DateInterval('P' . $stepDays . 'D'));
    }
    return $cand;
}

function diff_weeks(DateTimeImmutable $a, DateTimeImmutable $b): int {
    if ($b <= $a) return 0;
    $diff = $a->diff($b);
    $days = (int)($diff->days ?? 0);
    return intdiv($days, 7);
}

function jump_by_months(DateTimeImmutable $start, DateTimeImmutable $target, int $intervalMonths): DateTimeImmutable {
    if ($start >= $target) return $start;
    $sy = (int)$start->format('Y'); $sm = (int)$start->format('n');
    $ty = (int)$target->format('Y'); $tm = (int)$target->format('n');
    $startIndex  = $sy * 12 + ($sm - 1);
    $targetIndex = $ty * 12 + ($tm - 1);
    $diffMonths = $targetIndex - $startIndex;
    $steps = (int)ceil($diffMonths / $intervalMonths);
    if ($steps < 0) $steps = 0;
    $cand = $start->add(new DateInterval('P' . ($steps * $intervalMonths) . 'M'));
    for ($guard = 0; $guard < 240 && $cand < $target; $guard++) {
        $cand = $cand->add(new DateInterval('P' . $intervalMonths . 'M'));
    }
    return $cand;
}

function jump_by_years(DateTimeImmutable $start, DateTimeImmutable $target, int $intervalYears): DateTimeImmutable {
    if ($start >= $target) return $start;
    $sy = (int)$start->format('Y');
    $ty = (int)$target->format('Y');
    $diffYears = $ty - $sy;
    $steps = (int)ceil($diffYears / $intervalYears);
    if ($steps < 0) $steps = 0;
    $cand = $start->add(new DateInterval('P' . ($steps * $intervalYears) . 'Y'));
    for ($guard = 0; $guard < 120 && $cand < $target; $guard++) {
        $cand = $cand->add(new DateInterval('P' . $intervalYears . 'Y'));
    }
    return $cand;
}

/* ---------- DateTime parsing with Windows TZID mapping ---------- */

function resolve_default_ical_timezone(string $tzOverride, array $headerLines): DateTimeZone {
    $tzOverride = trim($tzOverride);
    if ($tzOverride !== '') {
        $tz = safe_timezone_from_string($tzOverride);
        if ($tz) return $tz;
    }

    $headerTz = detect_calendar_timezone_from_header($headerLines);
    if ($headerTz !== null) {
        $tz = safe_timezone_from_string($headerTz);
        if ($tz) return $tz;
    }

    return new DateTimeZone('UTC');
}

function detect_calendar_timezone_from_header(array $headerLines): ?string {
    // Common non-standard calendar-level timezone declarations.
    foreach ($headerLines as $line) {
        $t = trim((string)$line);
        if ($t === '' || strpos($t, ':') === false) continue;

        // Example: X-WR-TIMEZONE:Europe/Berlin
        if (stripos($t, 'X-WR-TIMEZONE:') === 0) {
            return trim(substr($t, strlen('X-WR-TIMEZONE:')));
        }

        // Example: X-LIC-LOCATION:America/New_York
        if (stripos($t, 'X-LIC-LOCATION:') === 0) {
            return trim(substr($t, strlen('X-LIC-LOCATION:')));
        }
    }

    return null;
}

function safe_timezone_from_string(string $tzid): ?DateTimeZone {
    $tzid = trim($tzid, '"');
    if ($tzid === '') return null;
    try {
        return new DateTimeZone($tzid);
    } catch (Throwable $e) {
        return null;
    }
}

function get_default_ical_timezone(): DateTimeZone {
    $tz = $GLOBALS['ICAL_DEFAULT_TZ'] ?? null;
    return ($tz instanceof DateTimeZone) ? $tz : new DateTimeZone('UTC');
}

function map_tzid(string $tzid): ?string {
    $tzid = trim($tzid, '"');
    if ($tzid === '') return null;

    // If it's already a valid IANA/DateTimeZone ID, use it.
    if (safe_timezone_from_string($tzid) !== null) return $tzid;

    // If ext/intl is available, try ICU's resolver (often knows Windows IDs).
    if (class_exists('IntlTimeZone')) {
        try {
            $itz = IntlTimeZone::createTimeZone($tzid);
            if ($itz instanceof IntlTimeZone) {
                $id = $itz->getID();
                if (is_string($id) && $id !== '' && $id !== 'Etc/Unknown') {
                    if (safe_timezone_from_string($id) !== null) return $id;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    // Fallback: common Windows -> IANA mappings.
    // Note: many Windows zones map to a representative city within the same ruleset.
    static $map = [
        'UTC' => 'UTC',
        'GMT Standard Time' => 'Europe/London',
        'Greenwich Standard Time' => 'Etc/GMT',
        'W. Europe Standard Time' => 'Europe/Berlin',
        'Central Europe Standard Time' => 'Europe/Budapest',
        'Romance Standard Time' => 'Europe/Paris',
        'Central European Standard Time' => 'Europe/Warsaw',
        'E. Europe Standard Time' => 'Europe/Bucharest',
        'Turkey Standard Time' => 'Europe/Istanbul',
        'Russian Standard Time' => 'Europe/Moscow',
        'Israel Standard Time' => 'Asia/Jerusalem',
        'South Africa Standard Time' => 'Africa/Johannesburg',

        'Arab Standard Time' => 'Asia/Riyadh',
        'Arabian Standard Time' => 'Asia/Dubai',
        'Iran Standard Time' => 'Asia/Tehran',
        'Pakistan Standard Time' => 'Asia/Karachi',
        'India Standard Time' => 'Asia/Kolkata',
        'Bangladesh Standard Time' => 'Asia/Dhaka',
        'China Standard Time' => 'Asia/Shanghai',
        'Tokyo Standard Time' => 'Asia/Tokyo',
        'Korea Standard Time' => 'Asia/Seoul',
        'Singapore Standard Time' => 'Asia/Singapore',

        'AUS Eastern Standard Time' => 'Australia/Sydney',
        'E. Australia Standard Time' => 'Australia/Brisbane',
        'AUS Central Standard Time' => 'Australia/Darwin',
        'W. Australia Standard Time' => 'Australia/Perth',
        'New Zealand Standard Time' => 'Pacific/Auckland',

        'Pacific Standard Time' => 'America/Los_Angeles',
        'Mountain Standard Time' => 'America/Denver',
        'Central Standard Time' => 'America/Chicago',
        'Eastern Standard Time' => 'America/New_York',
        'Atlantic Standard Time' => 'America/Halifax',

        'SA Pacific Standard Time' => 'America/Bogota',
        'SA Eastern Standard Time' => 'America/Sao_Paulo',
        'Argentina Standard Time' => 'America/Argentina/Buenos_Aires',
        'E. South America Standard Time' => 'America/Sao_Paulo',

        // Some servers emit this placeholder; treat it as "unknown" so we fall back to calendar/default.
        'Customized Time Zone' => '',
    ];

    $mapped = $map[$tzid] ?? null;
    if ($mapped === '') return null;
    return $mapped;
}

function parse_ical_datetime(?string $value, array $params): ?DateTimeImmutable {
    if ($value === null || $value === '') return null;
    $value = trim($value);

    $isDateOnly = (isset($params['VALUE']) && strtoupper($params['VALUE']) === 'DATE') || preg_match('/^\d{8}$/', $value);
    if ($isDateOnly) {
        $dt = DateTimeImmutable::createFromFormat('!Ymd', $value, new DateTimeZone('UTC'));
        return $dt ?: null;
    }

    // For floating values (no TZID and no trailing Z), interpret in calendar/default timezone.
    $tz = get_default_ical_timezone();
    if (!empty($params['TZID'])) {
        $tzidRaw = (string)$params['TZID'];
        $mapped = map_tzid($tzidRaw);
        if ($mapped) {
            try { $tz = new DateTimeZone($mapped); } catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }
        } else {
            // fallback: try as IANA directly
            try { $tz = new DateTimeZone(trim($tzidRaw, '"')); } catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }
        }
    }

    try {
        if (preg_match('/^\d{8}T\d{6}Z$/', $value)) {
            $dt = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $value, new DateTimeZone('UTC'));
            return $dt ?: null;
        }

        if (preg_match('/^\d{8}T\d{6}$/', $value)) {
            $dt = DateTimeImmutable::createFromFormat('Ymd\THis', $value, $tz);
            if (!$dt) return null;
            return $dt->setTimezone(new DateTimeZone('UTC'));
        }

        $dt = new DateTimeImmutable($value, $tz);
        return $dt->setTimezone(new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        return null;
    }
}

/** Like parse_ical_datetime(), but returns the DateTime in its local timezone (TZID/default/UTC) without forcing UTC. */
function parse_ical_datetime_local(?string $value, array $params): ?DateTimeImmutable {
    if ($value === null || $value === '') return null;
    $value = trim($value);

    $isDateOnly = (isset($params['VALUE']) && strtoupper($params['VALUE']) === 'DATE') || preg_match('/^\d{8}$/', $value);
    if ($isDateOnly) {
        $dt = DateTimeImmutable::createFromFormat('!Ymd', $value, new DateTimeZone('UTC'));
        return $dt ?: null;
    }

    $tz = get_default_ical_timezone();
    if (!empty($params['TZID'])) {
        $tzidRaw = (string)$params['TZID'];
        $mapped = map_tzid($tzidRaw);
        if ($mapped) {
            try { $tz = new DateTimeZone($mapped); } catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }
        } else {
            try { $tz = new DateTimeZone(trim($tzidRaw, '"')); } catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }
        }
    }

    try {
        if (preg_match('/^\d{8}T\d{6}Z$/', $value)) {
            $dt = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $value, new DateTimeZone('UTC'));
            return $dt ?: null;
        }

        if (preg_match('/^\d{8}T\d{6}$/', $value)) {
            $dt = DateTimeImmutable::createFromFormat('Ymd\THis', $value, $tz);
            return $dt ?: null;
        }

        return new DateTimeImmutable($value, $tz);
    } catch (Throwable $e) {
        return null;
    }
}

function ends_with_z(string $value): bool {
    return $value !== '' && strtoupper(substr($value, -1)) === 'Z';
}

function tzid_is_parseable(string $tzid): bool {
    $tzid = trim($tzid, '"');
    if ($tzid === '') return false;
    $mapped = map_tzid($tzid);
    if ($mapped !== null) {
        return safe_timezone_from_string($mapped) !== null;
    }
    return safe_timezone_from_string($tzid) !== null;
}

function build_dt_line_preserving_tz(string $name, string $rawValue, array $params): ?string {
    $rawValue = trim($rawValue);
    if ($rawValue === '') return null;

    $isDateOnly = (isset($params['VALUE']) && strtoupper($params['VALUE']) === 'DATE') || preg_match('/^\d{8}$/', $rawValue);
    if ($isDateOnly) {
        return $name . ';VALUE=DATE:' . $rawValue;
    }

    // If original had TZID, preserve it; if it was Z, keep it; else leave floating.
    if (!empty($params['TZID']) && !ends_with_z($rawValue)) {
        return $name . ';TZID=' . trim((string)$params['TZID']) . ':' . $rawValue;
    }

    return $name . ':' . $rawValue;
}

function build_dt_line_from_datetime(string $name, DateTimeImmutable $dtLocal, array $dtParams, string $originalRaw): string {
    // DATE events are handled elsewhere.
    $originalRaw = trim($originalRaw);

    // If original was explicit UTC, keep UTC-Z.
    if (ends_with_z($originalRaw)) {
        return $name . ':' . $dtLocal->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    // If original had TZID, emit local time with the same TZID.
    if (!empty($dtParams['TZID'])) {
        return $name . ';TZID=' . trim((string)$dtParams['TZID']) . ':' . $dtLocal->format('Ymd\THis');
    }

    // Floating: preserve floating (no TZID, no Z)
    return $name . ':' . $dtLocal->format('Ymd\THis');
}

function format_dt_for_params(DateTimeImmutable $dtLocal, array $dtParams, string $originalRaw): string {
    $originalRaw = trim($originalRaw);
    if (ends_with_z($originalRaw)) {
        return $dtLocal->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }
    if (!empty($dtParams['TZID'])) {
        return $dtLocal->format('Ymd\THis');
    }
    return $dtLocal->format('Ymd\THis');
}

function apply_duration(DateTimeImmutable $start, string $duration): DateTimeImmutable {
    $duration = trim($duration);
    try {
        $di = new DateInterval($duration);
        return $start->add($di);
    } catch (Throwable $e) {
        return $start;
    }
}
