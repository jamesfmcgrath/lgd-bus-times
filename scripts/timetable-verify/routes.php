<?php

/**
 * @file
 * Route configuration for the timetable verification tool.
 *
 * Each entry identifies one route page by the triple the timetable URL takes:
 * agency_id / route_short_name / slug. Distinct GTFS routes that share an
 * operator and route number get one entry each, because they are separate
 * pages (see issue #3599802).
 *
 * Keys:
 * - agency_id: GTFS agency ID, e.g. 'OP539'.
 * - route_short_name: GTFS route short name, e.g. 'X5'.
 * - slug: localgov_bus_route.bustimes_slug, the third URL segment.
 * - bustimes_url: (optional) bustimes.org service page for check 2. Omit or
 *   set to NULL to make the entry check-1-only.
 * - notes: (optional) Free text rendered into the report under this route,
 *   for known structural differences that are not defects.
 */

declare(strict_types=1);

$x5_note = <<<'NOTE'
The BODS GTFS feed splits this service at Keswick into two routes, so the
local site has an X5 page per leg (`keswick` and `penrith`) while
bustimes.org shows one through service. Through journeys will therefore not
match end to end: expect unmatched bustimes.org journeys and local journeys
that cover only part of a bustimes.org column. Time differences on the stops
the two sides do share are still meaningful.
NOTE;

$extra_slug_note = <<<'NOTE'
This slug was not in the original seed list. The imported data holds two
distinct GTFS routes under this operator and route number, so there are two
route pages; both are verified. Check 2 compares each against the single
bustimes.org service page, so journeys belonging to the other slug will show
as unmatched on the bustimes.org side.
NOTE;

return [
  [
    'agency_id' => 'OP539',
    'route_short_name' => 'X5',
    'slug' => 'keswick',
    'bustimes_url' => 'https://bustimes.org/services/x5-workington-bus-station-w-keswick-bus-station-2',
    'notes' => $x5_note,
  ],
  [
    'agency_id' => 'OP539',
    'route_short_name' => 'X5',
    'slug' => 'penrith',
    'bustimes_url' => 'https://bustimes.org/services/x5-workington-bus-station-w-keswick-bus-station-2',
    'notes' => $x5_note,
  ],
  [
    'agency_id' => 'OP539',
    'route_short_name' => '554',
    'slug' => 'keswick-bus-station',
    'bustimes_url' => 'https://bustimes.org/services/554-carlisle-keswick',
  ],
  [
    'agency_id' => 'OP539',
    'route_short_name' => '554',
    'slug' => 'wigton-newsagents',
    'bustimes_url' => 'https://bustimes.org/services/554-carlisle-keswick',
    'notes' => $extra_slug_note,
  ],
  [
    'agency_id' => 'OP539',
    'route_short_name' => 'X7',
    'slug' => 'ravenglass-station',
    'bustimes_url' => 'https://bustimes.org/services/x7-ravenglass-millom',
  ],
  [
    'agency_id' => 'OP539',
    'route_short_name' => 'X7',
    'slug' => 'egremont-main-st',
    'bustimes_url' => 'https://bustimes.org/services/x7-ravenglass-millom',
    'notes' => $extra_slug_note,
  ],
  [
    'agency_id' => 'OP539',
    'route_short_name' => '22',
    'slug' => 'egremont-st-t-cross',
    'bustimes_url' => 'https://bustimes.org/services/22-cleator-west-cumberland-hospital',
  ],
  [
    'agency_id' => 'OP539',
    'route_short_name' => '300',
    'slug' => 'carlisle-bus-stn',
    'bustimes_url' => 'https://bustimes.org/services/300-kells-carlisle',
  ],
  [
    'agency_id' => 'OP539',
    'route_short_name' => '300',
    'slug' => 'maryport-b-m',
    'bustimes_url' => 'https://bustimes.org/services/300-kells-carlisle',
    'notes' => $extra_slug_note,
  ],
  [
    'agency_id' => 'OP261',
    'route_short_name' => 'X12',
    'slug' => 'the-crown',
    'bustimes_url' => NULL,
  ],
  [
    'agency_id' => 'OP261',
    'route_short_name' => 'X12',
    'slug' => 'the-crown-2',
    'bustimes_url' => NULL,
  ],
];
