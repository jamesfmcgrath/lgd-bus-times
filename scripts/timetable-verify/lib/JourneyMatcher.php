<?php

declare(strict_types=1);

namespace LocalgovBusData\TimetableVerify;

/**
 * Pairs journeys from two sources so their times can be compared.
 *
 * Neither source numbers its journeys, so pairing is done on content. Every
 * candidate pair is scored, and pairs are taken best first, one to one.
 * Scoring puts identical times ahead of merely shared stops, so a journey
 * whose times are all correct wins its partner before a near-duplicate can
 * take it; scoring shared stops at all means a journey with one wrong time
 * still pairs up and is reported as a time difference rather than as two
 * unmatched journeys.
 */
final class JourneyMatcher {

  private function __construct() {}

  /**
   * Matches two sets of journeys.
   *
   * @param array<array-key, Journey> $left
   *   Journeys from the first source.
   * @param array<array-key, Journey> $right
   *   Journeys from the second source.
   * @param callable $keyOf
   *   Maps a Call to the string identifying the stop it serves. Both sides
   *   must use the same identity scheme.
   * @param int $minimumSharedStops
   *   Fewest shared stops a pair needs before it counts as the same journey.
   *
   * @return array{
   *   pairs: array<int, array{left: array-key, right: array-key, shared: int, equal: int}>,
   *   unmatchedLeft: array<int, array-key>,
   *   unmatchedRight: array<int, array-key>
   *   }
   *   The pairing, plus the keys left over on each side.
   */
  public static function match(array $left, array $right, callable $keyOf, int $minimumSharedStops = 1): array {
    $left_index = [];
    foreach ($left as $key => $journey) {
      $left_index[$key] = self::index($journey, $keyOf);
    }
    $right_index = [];
    foreach ($right as $key => $journey) {
      $right_index[$key] = self::index($journey, $keyOf);
    }

    $candidates = [];
    foreach ($left_index as $left_key => $left_calls) {
      foreach ($right_index as $right_key => $right_calls) {
        $shared = 0;
        $equal = 0;
        foreach ($left_calls as $stop => $time) {
          if (!isset($right_calls[$stop])) {
            continue;
          }
          $shared++;
          if (BusTime::sameMoment($time, $right_calls[$stop])) {
            $equal++;
          }
        }
        if ($shared < $minimumSharedStops) {
          continue;
        }
        $candidates[] = [
          'left' => $left_key,
          'right' => $right_key,
          'shared' => $shared,
          'equal' => $equal,
          'drift' => self::drift($left[$left_key], $right[$right_key]),
        ];
      }
    }

    usort($candidates, static function (array $a, array $b): int {
      return [$b['equal'], $b['shared'], $a['drift']] <=> [$a['equal'], $a['shared'], $b['drift']];
    });

    $pairs = [];
    $taken_left = [];
    $taken_right = [];
    foreach ($candidates as $candidate) {
      if (isset($taken_left[$candidate['left']]) || isset($taken_right[$candidate['right']])) {
        continue;
      }
      $taken_left[$candidate['left']] = TRUE;
      $taken_right[$candidate['right']] = TRUE;
      $pairs[] = [
        'left' => $candidate['left'],
        'right' => $candidate['right'],
        'shared' => $candidate['shared'],
        'equal' => $candidate['equal'],
      ];
    }

    return [
      'pairs' => $pairs,
      'unmatchedLeft' => array_values(array_diff(array_keys($left), array_keys($taken_left))),
      'unmatchedRight' => array_values(array_diff(array_keys($right), array_keys($taken_right))),
    ];
  }

  /**
   * Indexes a journey's calls by stop identity.
   *
   * @param \LocalgovBusData\TimetableVerify\Journey $journey
   *   The journey to index.
   * @param callable $keyOf
   *   Maps a Call to its stop identity string.
   *
   * @return array<string, string>
   *   Departure time keyed by stop identity.
   */
  private static function index(Journey $journey, callable $keyOf): array {
    $indexed = [];
    foreach ($journey->calls as $call) {
      $indexed[$keyOf($call)] = $call->time;
    }

    return $indexed;
  }

  /**
   * Measures how far apart two journeys start, in minutes.
   *
   * Used only to break ties between pairs that score the same on stops and
   * times, so that near-identical journeys pair up in running order.
   *
   * @param \LocalgovBusData\TimetableVerify\Journey $a
   *   First journey.
   * @param \LocalgovBusData\TimetableVerify\Journey $b
   *   Second journey.
   *
   * @return int
   *   Absolute difference in minutes, or a large value when either journey
   *   has no parseable first departure.
   */
  private static function drift(Journey $a, Journey $b): int {
    $left = BusTime::toMinutes($a->firstTime());
    $right = BusTime::toMinutes($b->firstTime());
    if ($left === NULL || $right === NULL) {
      return 9999;
    }

    return abs($left - $right);
  }

}
