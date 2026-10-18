<?php

namespace App\Support\Import;

use Carbon\CarbonImmutable;

/**
 * One match as read from a schedule file, before it is related to a team.
 */
final class ParsedMatch
{
    public function __construct(
        /** Stable id from the file (iCalendar UID or match number). */
        public readonly string $key,
        public readonly CarbonImmutable $startsAt,
        public readonly ?CarbonImmutable $endsAt,
        public readonly ?string $home,
        public readonly ?string $away,
        /** Free text if the file names no teams (title of the calendar entry). */
        public readonly ?string $summary = null,
        /** Team doing the refereeing (SAMS CSV). */
        public readonly ?string $referee = null,
        public readonly ?string $locationName = null,
        public readonly ?string $address = null,
        public readonly ?string $round = null,
        public readonly ?string $matchday = null,
        public readonly ?string $result = null,
    ) {}
}
