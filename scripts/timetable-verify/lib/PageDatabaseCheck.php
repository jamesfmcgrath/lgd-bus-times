<?php

declare(strict_types=1);

namespace LocalgovBusData\TimetableVerify;

/**
 * Check 1: does the rendered page reproduce the imported data exactly?
 *
 * This is the module-correctness check. Both sides come from the same
 * database, so any difference is the view, the style plugin or the template
 * getting something wrong. The expected result is zero on all three counts.
 *
 * Trips whose entire schedule duplicates another trip's are excluded before
 * comparison: the style plugin deliberately drops them, because the BODS feed
 * carries duplicate trip records, and that is documented behaviour rather
 * than a defect. They are counted separately so the report can show how many
 * were dropped.
 */
final class PageDatabaseCheck {

  private function __construct() {}

  /**
   * Compares one rendered page against the database rows behind it.
   *
   * @param \LocalgovBusData\TimetableVerify\Timetable $page
   *   The parsed page grid.
   * @param array<string, Journey> $trips
   *   Trips loaded from the database, keyed by trip entity ID.
   *
   * @return array{
   *   cellsDiffer: array<int, array{journey: string, stop: string, page: string, database: string}>,
   *   databaseMissingFromPage: array<int, array{journey: string, stop: string, time: string}>,
   *   pageMissingFromDatabase: array<int, array{journey: string, stop: string, time: string}>,
   *   pageCells: int,
   *   databaseCells: int,
   *   pageColumns: int,
   *   databaseTrips: int,
   *   deduplicatedTrips: int,
   *   differenceCount: int
   *   }
   *   The three difference lists plus the counts the summary table needs.
   */
  public static function run(Timetable $page, array $trips): array {
    [$distinct, $duplicates] = self::deduplicate($trips);

    $columns = $page->journeys();

    $matching = JourneyMatcher::match(
      $columns,
      $distinct,
      static fn(Call $call): string => $call->nameKey(),
    );

    $cells_differ = [];
    $database_missing = [];
    $page_missing = [];

    foreach ($matching['pairs'] as $pair) {
      /** @var \LocalgovBusData\TimetableVerify\Journey $column */
      $column = $columns[$pair['left']];
      /** @var \LocalgovBusData\TimetableVerify\Journey $trip */
      $trip = $distinct[$pair['right']];
      $label = sprintf('%s / %s', $column->label, $trip->label);

      $page_calls = $column->byNameKey();
      $trip_calls = $trip->byNameKey();

      foreach ($trip_calls as $key => $call) {
        if (!isset($page_calls[$key])) {
          $database_missing[] = [
            'journey' => $label,
            'stop' => $call->stopName,
            'time' => $call->time,
          ];
          continue;
        }
        if ($page_calls[$key]->time !== $call->time) {
          $cells_differ[] = [
            'journey' => $label,
            'stop' => $call->stopName,
            'page' => $page_calls[$key]->time,
            'database' => $call->time,
          ];
        }
      }

      foreach ($page_calls as $key => $call) {
        if (!isset($trip_calls[$key])) {
          $page_missing[] = [
            'journey' => $label,
            'stop' => $call->stopName,
            'time' => $call->time,
          ];
        }
      }
    }

    foreach ($matching['unmatchedRight'] as $trip_key) {
      $trip = $distinct[$trip_key];
      foreach ($trip->calls as $call) {
        $database_missing[] = [
          'journey' => $trip->label . ' (no page column)',
          'stop' => $call->stopName,
          'time' => $call->time,
        ];
      }
    }

    foreach ($matching['unmatchedLeft'] as $column_key) {
      $column = $columns[$column_key];
      foreach ($column->calls as $call) {
        $page_missing[] = [
          'journey' => $column->label . ' (no database trip)',
          'stop' => $call->stopName,
          'time' => $call->time,
        ];
      }
    }

    $database_cells = 0;
    foreach ($distinct as $trip) {
      $database_cells += count($trip->calls);
    }

    return [
      'cellsDiffer' => $cells_differ,
      'databaseMissingFromPage' => $database_missing,
      'pageMissingFromDatabase' => $page_missing,
      'pageCells' => $page->cellCount(),
      'databaseCells' => $database_cells,
      'pageColumns' => $page->columnCount,
      'databaseTrips' => count($trips),
      'deduplicatedTrips' => $duplicates,
      'differenceCount' => count($cells_differ) + count($database_missing) + count($page_missing),
    ];
  }

  /**
   * Drops trips that duplicate another trip's whole schedule.
   *
   * @param array<string, Journey> $trips
   *   Trips keyed by trip entity ID.
   *
   * @return array{0: array<string, Journey>, 1: int}
   *   The surviving trips, and how many were dropped.
   */
  private static function deduplicate(array $trips): array {
    $seen = [];
    $distinct = [];
    $dropped = 0;

    foreach ($trips as $key => $trip) {
      $fingerprint = DatabaseTimetable::fingerprint($trip);
      if (isset($seen[$fingerprint])) {
        $dropped++;
        continue;
      }
      $seen[$fingerprint] = TRUE;
      $distinct[$key] = $trip;
    }

    return [$distinct, $dropped];
  }

}
