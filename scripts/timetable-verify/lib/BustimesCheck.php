<?php

declare(strict_types=1);

namespace LocalgovBusData\TimetableVerify;

/**
 * Check 2: does the imported data agree with bustimes.org?
 *
 * This is a feed-quality check, not a module check. A difference here where
 * check 1 is clean means our page faithfully shows what the operator
 * published to BODS and bustimes.org shows something else. That is a data
 * problem for the operator to fix, so nothing here affects the exit code.
 *
 * bustimes.org renders one date at a time, so a fetched page covers a single
 * day of the week; the comparison uses whichever local day tab that date
 * falls on.
 */
final class BustimesCheck {

  /**
   * Fewest shared stops before two journeys count as the same journey.
   */
  private const MINIMUM_SHARED_STOPS = 2;

  /**
   * How many differing stops to show per mismatched journey.
   */
  public const DIFFERENCES_SHOWN = 3;

  private function __construct() {}

  /**
   * Compares one route's local pages against its bustimes.org page.
   *
   * @param array<int, array{heading: string, journeys: array<int, Journey>, stops: int}> $groupings
   *   Groupings parsed from bustimes.org, one per direction it publishes.
   * @param array<int, array<int, Journey>> $localByDirection
   *   Local page journeys keyed by GTFS direction ID, with ATCO codes already
   *   resolved onto their calls.
   *
   * @return array<int, array{
   *   direction: int|null,
   *   heading: string,
   *   matched: int,
   *   mismatched: int,
   *   bustimesOnly: array<int, string>,
   *   localOnly: array<int, string>,
   *   differences: array<int, array{bustimes: string, local: string, total: int, stops: array<int, array{identity: string, stop: string, bustimes: string, local: string}>}>,
   *   boundaryOnly: int,
   *   boundaryStops: array<int, string>
   *   }>
   *   One result per bustimes.org grouping.
   */
  public static function run(array $groupings, array $localByDirection): array {
    $assignment = self::assignDirections($groupings, $localByDirection);

    $results = [];
    foreach ($groupings as $index => $grouping) {
      $direction = $assignment[$index];
      $local = $direction === NULL ? [] : ($localByDirection[$direction] ?? []);

      $matching = JourneyMatcher::match(
        $grouping['journeys'],
        $local,
        static fn(Call $call): string => $call->stopIdentity(),
        self::MINIMUM_SHARED_STOPS,
      );

      $differences = [];
      $matched = 0;
      $boundary_only = 0;
      $boundary_stops = [];
      foreach ($matching['pairs'] as $pair) {
        $remote = $grouping['journeys'][$pair['left']];
        $mine = $local[$pair['right']];
        $stops = self::compareTimes($remote, $mine);
        if ($stops === []) {
          $matched++;
          continue;
        }
        $boundary = self::isLegBoundary($stops, $mine);
        if ($boundary !== NULL) {
          $boundary_only++;
          $boundary_stops[$boundary] = TRUE;
        }
        $differences[] = [
          'bustimes' => $remote->describe(),
          'local' => $mine->describe(),
          'total' => count($stops),
          'stops' => array_slice($stops, 0, self::DIFFERENCES_SHOWN),
        ];
      }

      $results[] = [
        'direction' => $direction,
        'heading' => $grouping['heading'],
        'matched' => $matched,
        'mismatched' => count($differences),
        'bustimesOnly' => array_map(
          static fn($key): string => $grouping['journeys'][$key]->describe(),
          $matching['unmatchedLeft'],
        ),
        'localOnly' => array_map(
          static fn($key): string => $local[$key]->describe(),
          $matching['unmatchedRight'],
        ),
        'differences' => $differences,
        'boundaryOnly' => $boundary_only,
        'boundaryStops' => array_keys($boundary_stops),
      ];
    }

    return $results;
  }

  /**
   * Copies page journeys with ATCO codes filled in from the database.
   *
   * The page prints stop names but not codes. Resolving each name back to the
   * code the database holds for it lets check 2 match stops exactly. A name
   * the database gives to more than one stop is left unresolved, so those
   * calls fall back to fuzzy name matching.
   *
   * @param array<int, Journey> $journeys
   *   Journeys read off the page.
   * @param array<string, string|null> $nameToAtco
   *   Stop display name to ATCO code, with NULL for ambiguous names.
   *
   * @return array<int, Journey>
   *   The same journeys with ATCO codes attached where they resolved.
   */
  public static function withAtco(array $journeys, array $nameToAtco): array {
    $resolved = [];
    foreach ($journeys as $key => $journey) {
      $calls = [];
      foreach ($journey->calls as $call) {
        $calls[] = new Call(
          stopName: $call->stopName,
          time: $call->time,
          occurrence: $call->occurrence,
          rowIndex: $call->rowIndex,
          atco: $nameToAtco[$call->stopName] ?? NULL,
          stopKey: $call->stopKey,
        );
      }
      $resolved[$key] = new Journey(label: $journey->label, calls: $calls);
    }

    return $resolved;
  }

  /**
   * Reports whether a journey's only difference is at where its route ends.
   *
   * Where the GTFS feed splits a through service into two routes, the local
   * page for one leg ends at the interchange and shows the arrival there,
   * while bustimes.org runs straight through and shows the departure. Every
   * other stop agrees. That is an artefact of the split, not a wrong time, and
   * naming it keeps it from reading as thirty separate discrepancies.
   *
   * @param array<int, array{identity: string, stop: string, bustimes: string, local: string}> $stops
   *   The differing stops.
   * @param \LocalgovBusData\TimetableVerify\Journey $mine
   *   The local journey.
   *
   * @return string|null
   *   The boundary stop name when that is the only difference, else NULL.
   */
  private static function isLegBoundary(array $stops, Journey $mine): ?string {
    if (count($stops) !== 1 || $mine->calls === []) {
      return NULL;
    }
    $last = $mine->calls[count($mine->calls) - 1];

    return $stops[0]['identity'] === $last->stopIdentity()
      ? $stops[0]['stop']
      : NULL;
  }

  /**
   * Lists the stops where two matched journeys give different times.
   *
   * @param \LocalgovBusData\TimetableVerify\Journey $remote
   *   The bustimes.org journey.
   * @param \LocalgovBusData\TimetableVerify\Journey $mine
   *   The local journey.
   *
   * @return array<int, array{identity: string, stop: string, bustimes: string, local: string}>
   *   Differing stops, in the order the journey serves them.
   */
  private static function compareTimes(Journey $remote, Journey $mine): array {
    $local_calls = $mine->byStopIdentity();

    $differences = [];
    foreach ($remote->calls as $call) {
      $identity = $call->stopIdentity();
      if (!isset($local_calls[$identity])) {
        continue;
      }
      $local_call = $local_calls[$identity];
      if (BusTime::sameMoment($call->time, $local_call->time)) {
        continue;
      }
      $differences[] = [
        'identity' => $identity,
        'stop' => $call->stopName,
        'bustimes' => $call->time,
        'local' => $local_call->time,
      ];
    }

    return $differences;
  }

  /**
   * Works out which GTFS direction each bustimes.org grouping corresponds to.
   *
   * Both directions call at nearly the same stops, so the stop list alone
   * cannot tell them apart. Scoring on exact (stop, time) agreement can: a
   * grouping's times line up with one direction and not the other. Groupings
   * are assigned best score first, one direction each.
   *
   * @param array<int, array{heading: string, journeys: array<int, Journey>, stops: int}> $groupings
   *   Groupings parsed from bustimes.org.
   * @param array<int, array<int, Journey>> $localByDirection
   *   Local journeys keyed by direction ID.
   *
   * @return array<int, int|null>
   *   Direction ID for each grouping index, or NULL when none is left.
   */
  private static function assignDirections(array $groupings, array $localByDirection): array {
    $candidates = [];
    foreach ($groupings as $grouping_index => $grouping) {
      $remote = self::stopTimeSet($grouping['journeys']);
      foreach ($localByDirection as $direction => $journeys) {
        $candidates[] = [
          'grouping' => $grouping_index,
          'direction' => $direction,
          'score' => count(array_intersect_key($remote, self::stopTimeSet($journeys))),
        ];
      }
    }

    usort($candidates, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

    $assignment = array_fill(0, count($groupings), NULL);
    $taken_groupings = [];
    $taken_directions = [];
    foreach ($candidates as $candidate) {
      if ($candidate['score'] === 0) {
        continue;
      }
      if (isset($taken_groupings[$candidate['grouping']]) || isset($taken_directions[$candidate['direction']])) {
        continue;
      }
      $taken_groupings[$candidate['grouping']] = TRUE;
      $taken_directions[$candidate['direction']] = TRUE;
      $assignment[$candidate['grouping']] = $candidate['direction'];
    }

    // Nothing agreed anywhere: fall back to publication order so the report
    // still shows the two sides side by side.
    $spare = array_values(array_diff(array_keys($localByDirection), array_keys($taken_directions)));
    foreach ($assignment as $index => $direction) {
      if ($direction !== NULL || $spare === []) {
        continue;
      }
      $assignment[$index] = array_shift($spare);
    }

    return $assignment;
  }

  /**
   * Builds the set of (stop, time) pairs a set of journeys covers.
   *
   * @param array<int, Journey> $journeys
   *   Journeys to summarise.
   *
   * @return array<string, true>
   *   Set keyed by stop identity and minute of the day.
   */
  private static function stopTimeSet(array $journeys): array {
    $set = [];
    foreach ($journeys as $journey) {
      foreach ($journey->calls as $call) {
        $minutes = BusTime::toMinutes($call->time);
        if ($minutes === NULL) {
          continue;
        }
        $set[$call->stopIdentity() . '@' . $minutes] = TRUE;
      }
    }

    return $set;
  }

}
