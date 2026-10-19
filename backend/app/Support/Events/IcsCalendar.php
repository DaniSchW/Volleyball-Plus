<?php

namespace App\Support\Events;

use App\Models\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Minimal iCalendar (RFC 5545) writer for the calendar subscription.
 */
class IcsCalendar
{
    /**
     * @param  Collection<int, Event>  $events
     */
    public function render(string $calendarName, Collection $events, string $appUrl, string $tenantSlug): string
    {
        $host = parse_url($appUrl, PHP_URL_HOST) ?: 'volleyorga';
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//VolleyOrga//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escape($calendarName),
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
        ];

        foreach ($events as $event) {
            $cancelled = $event->isCancelled();
            $description = trim(implode("\n", array_filter([
                $event->description,
                rtrim($appUrl, '/').'/'.$tenantSlug.'/events/'.$event->id,
            ])));

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$event->id.'@'.$host;
            $lines[] = 'DTSTAMP:'.$this->utc($event->updated_at ?? Carbon::now());
            $lines[] = 'DTSTART:'.$this->utc($event->starts_at);
            $lines[] = 'DTEND:'.$this->utc($event->ends_at);
            $lines[] = 'SUMMARY:'.$this->escape(($cancelled ? __('events.cancelled_prefix').' ' : '').$event->title);
            if ($event->address || $event->location_name) {
                $lines[] = 'LOCATION:'.$this->escape(implode(', ', array_filter([$event->location_name, $event->address])));
            }
            $lines[] = 'DESCRIPTION:'.$this->escape($description);
            $lines[] = 'STATUS:'.($cancelled ? 'CANCELLED' : 'CONFIRMED');
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([$this, 'fold'], $lines))."\r\n";
    }

    private function utc(Carbon|\DateTimeInterface $time): string
    {
        return Carbon::instance($time)->utc()->format('Ymd\THis\Z');
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $text);
    }

    /** Lines longer than 75 octets are folded (continuation starts with a space). */
    private function fold(string $line): string
    {
        $out = '';
        while (strlen($line) > 75) {
            $chunk = mb_strcut($line, 0, 75, 'UTF-8');
            $out .= $chunk."\r\n ";
            $line = substr($line, strlen($chunk));
        }

        return $out.$line;
    }
}
