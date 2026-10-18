<?php

namespace App\Support\Import;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Reads match schedules: iCalendar (.ics/.ical, e.g. from SAMS or any
 * calendar) and the SAMS CSV export (semicolon separated, often
 * Windows-1252 encoded).
 */
class ScheduleParser
{
    public const MAX_MATCHES = 300;

    /** Separators between home and away team in calendar titles. */
    private const VERSUS = ['/\s+vs\.?\s+/i', '/\s+[–-]\s+/u', '/\s+:\s+/'];

    /**
     * @return list<ParsedMatch>
     */
    public function parse(string $content, string $timezone): array
    {
        $content = $this->toUtf8($content);
        $matches = str_contains($content, 'BEGIN:VCALENDAR')
            ? $this->parseIcal($content, $timezone)
            : $this->parseCsv($content, $timezone);

        if ($matches === []) {
            throw ValidationException::withMessages(['file' => __('import.empty')]);
        }
        if (count($matches) > self::MAX_MATCHES) {
            throw ValidationException::withMessages(['file' => __('import.too_many', ['max' => self::MAX_MATCHES])]);
        }

        usort($matches, fn (ParsedMatch $a, ParsedMatch $b) => $a->startsAt <=> $b->startsAt);

        return $matches;
    }

    private function toUtf8(string $content): string
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        return mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    // ---- iCalendar ----

    /**
     * @return list<ParsedMatch>
     */
    private function parseIcal(string $content, string $timezone): array
    {
        // Unfold continuation lines (RFC 5545: a line break followed by a space or tab).
        $content = preg_replace("/\r?\n[ \t]/", '', $content);
        $lines = preg_split("/\r?\n/", $content);

        $matches = [];
        $event = null;
        foreach ($lines as $line) {
            if ($line === 'BEGIN:VEVENT') {
                $event = [];

                continue;
            }
            if ($line === 'END:VEVENT') {
                if ($event !== null && ($match = $this->icalEvent($event, $timezone)) !== null) {
                    $matches[] = $match;
                }
                $event = null;

                continue;
            }
            if ($event === null || ! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $params = explode(';', $name);
            $event[strtoupper(array_shift($params))] = ['value' => $value, 'params' => $params];
        }

        return $matches;
    }

    /**
     * @param  array<string, array{value: string, params: list<string>}>  $event
     */
    private function icalEvent(array $event, string $timezone): ?ParsedMatch
    {
        if (! isset($event['DTSTART'])) {
            return null;
        }
        $start = $this->icalDate($event['DTSTART'], $timezone);
        $end = isset($event['DTEND']) ? $this->icalDate($event['DTEND'], $timezone) : null;
        if ($start === null) {
            return null;
        }

        $summary = $this->unescape($event['SUMMARY']['value'] ?? '');
        [$home, $away] = $this->splitTeams($summary);
        [$locationName, $address] = $this->splitLocation($this->unescape($event['LOCATION']['value'] ?? ''));

        return new ParsedMatch(
            key: 'uid:'.($event['UID']['value'] ?? md5($start->toIso8601String().$summary)),
            startsAt: $start,
            endsAt: $end,
            home: $home,
            away: $away,
            summary: $home === null ? ($summary ?: null) : null,
            locationName: $locationName,
            address: $address,
            round: $home !== null ? $this->roundFromSummary($summary) : null,
        );
    }

    /**
     * @param  array{value: string, params: list<string>}  $property
     */
    private function icalDate(array $property, string $timezone): ?CarbonImmutable
    {
        $value = trim($property['value']);
        $tz = $timezone;
        foreach ($property['params'] as $param) {
            if (str_starts_with(strtoupper($param), 'TZID=')) {
                $candidate = trim(substr($param, 5), '"');
                $tz = in_array($candidate, DateTimeZone::listIdentifiers(), true) ? $candidate : $timezone;
            }
        }

        try {
            if (preg_match('/^\d{8}T\d{6}Z$/', $value)) {
                return CarbonImmutable::createFromFormat('Ymd\THis\Z', $value, 'UTC');
            }
            if (preg_match('/^\d{8}T\d{6}$/', $value)) {
                return CarbonImmutable::createFromFormat('Ymd\THis', $value, $tz)->utc();
            }
            if (preg_match('/^\d{8}$/', $value)) {
                return CarbonImmutable::createFromFormat('Ymd', $value, $tz)->startOfDay()->utc();
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function unescape(string $value): string
    {
        return trim(strtr($value, ['\\,' => ',', '\;' => ';', '\\n' => ' ', '\\N' => ' ', '\\\\' => '\\']));
    }

    /**
     * "SSV Ulm 1846 vs. SG Volley Alb/Brenztal, 10/11/26, LL3 M" → [home, away].
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function splitTeams(string $summary): array
    {
        // Drop the trailing ", date, league" that SAMS appends.
        $teams = preg_replace('/,\s*\d{1,2}\/\d{1,2}\/\d{2,4}\b.*$/', '', $summary);

        foreach (self::VERSUS as $pattern) {
            $parts = preg_split($pattern, $teams, 2);
            if (count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
                return [trim($parts[0]), trim($parts[1])];
            }
        }

        return [null, null];
    }

    private function roundFromSummary(string $summary): ?string
    {
        return preg_match('/,\s*\d{1,2}\/\d{1,2}\/\d{2,4},\s*(.+)$/', $summary, $m) ? trim($m[1]) : null;
    }

    /**
     * "Blauringhalle, Böblinger Straße 31, 89077 Ulm" → ["Blauringhalle", "Böblinger Straße 31, 89077 Ulm"].
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function splitLocation(string $location): array
    {
        if ($location === '') {
            return [null, null];
        }
        // "Bibrishalle, 89542 Herbrechtingen, 89542 Herbrechtingen": drop repeated parts.
        $parts = array_values(array_unique(array_filter(array_map('trim', explode(',', $location)))));

        return count($parts) > 1 ? [$parts[0], implode(', ', $parts)] : [$parts[0] ?? $location, null];
    }

    // ---- SAMS CSV ----

    /**
     * @return list<ParsedMatch>
     */
    private function parseCsv(string $content, string $timezone): array
    {
        $lines = preg_split("/\r?\n/", trim($content));
        $delimiter = substr_count($lines[0] ?? '', ';') >= substr_count($lines[0] ?? '', ',') ? ';' : ',';
        $header = array_map(fn ($h) => mb_strtolower(trim($h)), str_getcsv(array_shift($lines) ?? '', $delimiter, '"', ''));
        $col = fn (string ...$names) => collect($names)->map(fn ($n) => array_search($n, $header, true))->first(fn ($i) => $i !== false);

        $columns = [
            'datetime' => $col('datum und uhrzeit'),
            'date' => $col('datum'),
            'time' => $col('uhrzeit'),
            'number' => $col('#', 'spielnummer', 'nr'),
            'matchday' => $col('st', 'spieltag'),
            'home' => $col('mannschaft 1', 'heim'),
            'away' => $col('mannschaft 2', 'gast'),
            'referee' => $col('schiedsgericht'),
            'host' => $col('gastgeber'),
            'location' => $col('austragungsort', 'halle', 'ort'),
            'result' => $col('ergebnis'),
            'season' => $col('saison'),
            'round' => $col('spielrunde', 'liga', 'staffel'),
        ];
        if (($columns['datetime'] === null && $columns['date'] === null) || $columns['home'] === null || $columns['away'] === null) {
            throw ValidationException::withMessages(['file' => __('import.unknown_format')]);
        }

        $matches = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = str_getcsv($line, $delimiter, '"', '');
            $get = fn (string $key) => $columns[$key] !== null ? (trim($row[$columns[$key]] ?? '') ?: null) : null;

            $start = $this->csvDate($get('datetime') ?? trim(($get('date') ?? '').' '.($get('time') ?? '')), $timezone);
            if ($start === null || $get('home') === null || $get('away') === null) {
                continue;
            }

            $host = $get('host');
            $homeIsHost = $host === null || $host === $get('home');
            [$locationName, $address] = $this->csvLocation($get('location'));
            $result = $get('result');

            $matches[] = new ParsedMatch(
                key: 'nr:'.implode('|', array_filter([$get('season'), $get('round'), $get('number') ?? $start->toIso8601String().$get('home').$get('away')])),
                startsAt: $start,
                endsAt: null,
                home: $homeIsHost ? $get('home') : $get('away'),
                away: $homeIsHost ? $get('away') : $get('home'),
                referee: $get('referee'),
                locationName: $locationName,
                address: $address,
                round: $get('round'),
                matchday: $get('matchday'),
                result: $result !== null && $result !== '-' && preg_match('/^\d+:\d+/', $result, $m) ? $m[0] : null,
            );
        }

        return $matches;
    }

    private function csvDate(string $value, string $timezone): ?CarbonImmutable
    {
        $value = trim(str_replace(',', ' ', $value));
        $value = preg_replace('/\s+/', ' ', $value);
        foreach (['d.m.Y H:i:s', 'd.m.Y H:i', 'd.m.y H:i', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value, $timezone);
                if ($date !== null && $date->format($format) === $value) {
                    return $date->utc();
                }
            } catch (Throwable) {
                // try the next format
            }
        }

        return null;
    }

    /**
     * "Blauringhalle (89077 Ulm)" → ["Blauringhalle", "Blauringhalle, 89077 Ulm"]; the
     * hall name helps the route planner to find the right building.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function csvLocation(?string $location): array
    {
        if ($location === null) {
            return [null, null];
        }
        if (preg_match('/^(.*?)\s*\(([^()]*)\)\s*$/u', $location, $m)) {
            $name = trim($m[1]);
            $place = trim($m[2]);
            // "Bibrishalle, 89542 Herbrechtingen (89542 Herbrechtingen)": the town is already in there.
            $address = str_contains($name, $place) ? $name : $name.', '.$place;
            $hall = trim(explode(',', $name)[0]);

            return [$hall, $address];
        }

        return [$location, $location];
    }
}
