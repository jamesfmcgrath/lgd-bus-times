<?php

/**
 * @file
 * Self-test for the verification logic.
 *
 * A tool that reports "zero differences" is only worth reading if it can
 * find a difference when there is one. This exercises each of check 1's
 * three difference categories against a synthetic page and a synthetic set
 * of trips, so a parser or comparison regression that silently reports a
 * clean run gets caught.
 *
 * @code
 * ddev exec php scripts/timetable-verify/selftest.php
 * @endcode
 */

declare(strict_types=1);

use LocalgovBusData\TimetableVerify\BusTime;
use LocalgovBusData\TimetableVerify\BustimesParser;
use LocalgovBusData\TimetableVerify\Call;
use LocalgovBusData\TimetableVerify\Journey;
use LocalgovBusData\TimetableVerify\LocalPageParser;
use LocalgovBusData\TimetableVerify\PageDatabaseCheck;
use LocalgovBusData\TimetableVerify\StopName;
use LocalgovBusData\TimetableVerify\Timetable;

foreach (glob(__DIR__ . '/lib/*.php') ?: [] as $file) {
  require_once $file;
}
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$failures = 0;

/**
 * Asserts a condition and records the outcome.
 *
 * @param string $name
 *   What is being asserted.
 * @param bool $condition
 *   The assertion.
 */
function check(string $name, bool $condition): void {
  global $failures;
  if (!$condition) {
    $failures++;
  }
  fwrite(STDOUT, sprintf("%s %s\n", $condition ? 'ok  ' : 'FAIL', $name));
}

/**
 * Builds a page grid from a stop name to per-column times map.
 *
 * @param array<string, array<int, string|null>> $rows
 *   Stop name to ordered column times.
 *
 * @return \LocalgovBusData\TimetableVerify\Timetable
 *   The grid.
 */
function grid(array $rows): Timetable {
  $built = [];
  $columns = 0;
  foreach ($rows as $name => $times) {
    $built[] = ['name' => $name, 'times' => $times];
    $columns = max($columns, count($times));
  }

  return new Timetable($built, $columns);
}

/**
 * Builds a database trip from a stop name to time map.
 *
 * @param string $label
 *   Trip label.
 * @param array<string, string> $calls
 *   Stop name to departure time, in call order.
 * @param int $stopKeyBase
 *   Starting stop entity ID, so fingerprints differ between patterns.
 *
 * @return \LocalgovBusData\TimetableVerify\Journey
 *   The trip.
 */
function trip(string $label, array $calls, int $stopKeyBase = 100): Journey {
  $built = [];
  $sequence = 1;
  foreach ($calls as $name => $time) {
    $built[] = new Call(
      stopName: $name,
      time: $time,
      occurrence: 0,
      rowIndex: $sequence,
      atco: 'ATCO' . ($stopKeyBase + $sequence),
      stopKey: $stopKeyBase + $sequence,
    );
    $sequence++;
  }

  return new Journey(label: $label, calls: $built);
}

$stops = ['Bus Station, Workington', 'Market Place, Cockermouth', 'Bus Station, Keswick'];

$trips = [
  '1' => trip('trip 1', array_combine($stops, ['09:00', '09:30', '10:00'])),
  '2' => trip('trip 2', array_combine($stops, ['10:00', '10:30', '11:00'])),
];

$clean = grid([
  $stops[0] => ['09:00', '10:00'],
  $stops[1] => ['09:30', '10:30'],
  $stops[2] => ['10:00', '11:00'],
]);

$result = PageDatabaseCheck::run($clean, $trips);
check('matching page and database report zero differences', $result['differenceCount'] === 0);
check('matching page counts all cells', $result['pageCells'] === 6 && $result['databaseCells'] === 6);

// One wrong time on the page.
$wrong = grid([
  $stops[0] => ['09:00', '10:00'],
  $stops[1] => ['09:35', '10:30'],
  $stops[2] => ['10:00', '11:00'],
]);
$result = PageDatabaseCheck::run($wrong, $trips);
check('a wrong time is reported as a differing cell', count($result['cellsDiffer']) === 1);
check(
  'the differing cell names both values',
  ($result['cellsDiffer'][0]['page'] ?? '') === '09:35'
  && ($result['cellsDiffer'][0]['database'] ?? '') === '09:30',
);
check('a wrong time reports nothing missing', $result['databaseMissingFromPage'] === [] && $result['pageMissingFromDatabase'] === []);

// A time the database holds but the page drops.
$dropped = grid([
  $stops[0] => ['09:00', '10:00'],
  $stops[1] => [NULL, '10:30'],
  $stops[2] => ['10:00', '11:00'],
]);
$result = PageDatabaseCheck::run($dropped, $trips);
check('a dropped time is reported as missing from the page', count($result['databaseMissingFromPage']) === 1);
check('a dropped time is not also reported as a cell difference', $result['cellsDiffer'] === []);

// A time the page shows that the database does not have.
$invented = grid([
  $stops[0] => ['09:00', '10:00'],
  $stops[1] => ['09:30', '10:30'],
  $stops[2] => ['10:00', '11:00'],
  'Bus Station, Penrith' => ['10:45', NULL],
]);
$result = PageDatabaseCheck::run($invented, $trips);
check('an invented time is reported as missing from the database', count($result['pageMissingFromDatabase']) === 1);

// A whole column with no database trip behind it.
$extra_column = grid([
  $stops[0] => ['09:00', '10:00', '11:00'],
  $stops[1] => ['09:30', '10:30', '11:30'],
  $stops[2] => ['10:00', '11:00', '12:00'],
]);
$result = PageDatabaseCheck::run($extra_column, $trips);
check('an unbacked column is reported in full', count($result['pageMissingFromDatabase']) === 3);

// A trip the page does not render at all.
$missing_column = grid([
  $stops[0] => ['09:00'],
  $stops[1] => ['09:30'],
  $stops[2] => ['10:00'],
]);
$result = PageDatabaseCheck::run($missing_column, $trips);
check('a missing column is reported in full', count($result['databaseMissingFromPage']) === 3);

// Duplicate GTFS trips are dropped, matching the style plugin.
$with_duplicate = $trips;
$with_duplicate['3'] = trip('trip 3', array_combine($stops, ['09:00', '09:30', '10:00']));
$result = PageDatabaseCheck::run($clean, $with_duplicate);
check('an exact duplicate trip is dropped, not reported', $result['differenceCount'] === 0);
check('the dropped duplicate is counted', $result['deduplicatedTrips'] === 1);

// A circular route calling twice at the same stop keeps both calls, and the
// page must give each call its own row. Rows are built directly here because
// a stop name keyed array cannot hold the same stop twice.
$circular_trip = new Journey('circular', [
  new Call('Depot, Whitehaven', '08:00', 0, 1, 'ATCO1', 1),
  new Call('Square, Whitehaven', '08:10', 0, 2, 'ATCO2', 2),
  new Call('Depot, Whitehaven', '08:20', 1, 3, 'ATCO1', 1),
]);

$circular_page = new Timetable([
  ['name' => 'Depot, Whitehaven', 'times' => ['08:00']],
  ['name' => 'Square, Whitehaven', 'times' => ['08:10']],
  ['name' => 'Depot, Whitehaven', 'times' => ['08:20']],
], 1);
$result = PageDatabaseCheck::run($circular_page, ['9' => $circular_trip]);
check('a repeated stop given its own row matches', $result['differenceCount'] === 0);

// The same trip with the second call collapsed onto the first row would lose
// a time and invent one, which is exactly what the tool must catch.
$collapsed_page = new Timetable([
  ['name' => 'Depot, Whitehaven', 'times' => ['08:00']],
  ['name' => 'Square, Whitehaven', 'times' => ['08:10']],
], 1);
$result = PageDatabaseCheck::run($collapsed_page, ['9' => $circular_trip]);
check(
  'a repeated stop collapsed onto one row is caught',
  count($result['databaseMissingFromPage']) === 1,
);

// Parsing the real template markup.
$html = <<<'HTML'
<div class="bus-timetable"><table class="bus-timetable__table">
<thead><tr><th class="bus-timetable__stop-col">Stop</th>
<th class="bus-timetable__time-col"></th><th class="bus-timetable__time-col"></th></tr></thead>
<tbody>
<tr class="bus-timetable__row"><th class="bus-timetable__stop-name">A, Town</th>
<td class="bus-timetable__time">09:00</td><td class="bus-timetable__time">—</td></tr>
<tr class="bus-timetable__row"><th class="bus-timetable__stop-name">B, Town</th>
<td class="bus-timetable__time">09:10</td><td class="bus-timetable__time">10:10</td></tr>
</tbody></table></div>
HTML;
$parsed = LocalPageParser::parse($html);
check('the page parser reads the column count', $parsed->columnCount === 2);
check('the page parser reads two rows', count($parsed->rows) === 2);
check('the page parser treats an em-dash as no call', $parsed->cellCount() === 3);
check('a page with no timetable table parses as empty', LocalPageParser::parse('<p>nothing</p>')->isEmpty());

// Parsing the bustimes.org markup, including the ATCO code each row links to
// and the rowspan a timing point uses to show arrival above departure.
$bustimes_html = <<<'HTML'
<div class="groupings"><div class="grouping"><h2>Carlisle - Keswick</h2>
<table class="timetable"><tbody>
<tr><th class="stop-name" scope="row"><a href="/stops/090033101563">Carlisle Bus Station (Bay 4)</a></th>
<td>07:20</td><td>09:00</td></tr>
<tr><th rowspan="2" class="stop-name" scope="row"><a href="/stops/090002563029">Wigton Throstles Nest</a></th>
<td rowspan="2"></td><td>09:17</td></tr>
<tr><td>09:20</td></tr>
<tr><th class="stop-name" scope="row"><a href="/stops/090002372830">Keswick Bus Station</a></th>
<td>08:33</td><td>10:23</td></tr>
</tbody></table></div></div>
<noscript>2026-08-20</noscript>
HTML;
$bustimes = BustimesParser::parse($bustimes_html);
check('the bustimes parser reads the rendered date', $bustimes['date'] === '2026-08-20');
check('the bustimes parser finds one grouping', count($bustimes['groupings']) === 1);
$bustimes_journeys = $bustimes['groupings'][0]['journeys'];
check('the bustimes parser finds both journeys', count($bustimes_journeys) === 2);

$first_call = $bustimes_journeys[0]->calls[0];
check(
  'the bustimes parser extracts the ATCO code from the stop link',
  $first_call->atco === '090033101563',
);
check(
  'an extracted ATCO code is used as the stop identity',
  $first_call->stopIdentity() === 'atco:090033101563#0',
);
check(
  'a journey that skips a rowspan stop keeps its other calls',
  count($bustimes_journeys[0]->calls) === 2,
);
check(
  'a timing point drawn as two rows contributes its departure, not its arrival',
  ($bustimes_journeys[1]->calls[1]->time ?? '') === '09:20',
);
check(
  'markup with no grouping is reported rather than guessed at',
  (static function (): bool {
    try {
      BustimesParser::parse('<p>not a timetable</p>');
      return FALSE;
    }
    catch (\RuntimeException) {
      return TRUE;
    }
  })(),
);

// Time handling.
check('an eight character GTFS time is trimmed', BusTime::toHourMinute('09:30:00') === '09:30');
check('a past-midnight GTFS time wraps for comparison', BusTime::sameMoment('25:10', '01:10'));
check('different times do not compare equal', !BusTime::sameMoment('09:30', '09:35'));

// Stop name normalisation.
check(
  'locality position does not stop two names matching',
  StopName::matches('Bus Station (Ca) (Bay 4) Lonsdale Street, Carlisle', 'Carlisle Bus Station (Ca) (Bay 4)'),
);
check(
  'unrelated stops do not match',
  !StopName::matches('Market Place, Cockermouth', 'Ravenglass Station'),
);

fwrite(STDOUT, sprintf("\n%s\n", $failures === 0 ? 'All self-tests passed.' : $failures . ' self-test(s) failed.'));
exit($failures === 0 ? 0 : 1);
