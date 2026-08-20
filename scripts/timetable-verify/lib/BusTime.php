<?php

declare(strict_types=1);

namespace LocalgovBusData\TimetableVerify;

/**
 * Helpers for the clock times that appear on both sides of the comparison.
 */
final class BusTime {

  private function __construct() {}

  /**
   * Trims a GTFS "HH:MM:SS" departure to the "HH:MM" the page renders.
   *
   * @param string $time
   *   Raw time value.
   *
   * @return string
   *   Time as "HH:MM", or the input unchanged when it is not 8 characters.
   */
  public static function toHourMinute(string $time): string {
    return strlen($time) === 8 ? substr($time, 0, 5) : $time;
  }

  /**
   * Converts a clock time to minutes past midnight.
   *
   * GTFS lets a journey that runs past midnight carry an hour of 24 or more.
   * bustimes.org wraps those to 00:xx, so comparisons take the result modulo
   * a full day.
   *
   * @param string $time
   *   Time as "HH:MM".
   *
   * @return int|null
   *   Minutes past midnight in the range 0-1439, or NULL when unparseable.
   */
  public static function toMinutes(string $time): ?int {
    if (preg_match('/^(\d{1,2}):(\d{2})/', trim($time), $match) !== 1) {
      return NULL;
    }

    return (((int) $match[1] * 60) + (int) $match[2]) % 1440;
  }

  /**
   * Reports whether two clock times are the same moment in the day.
   *
   * @param string $a
   *   First time.
   * @param string $b
   *   Second time.
   *
   * @return bool
   *   TRUE when both parse to the same minute past midnight.
   */
  public static function sameMoment(string $a, string $b): bool {
    $left = self::toMinutes($a);
    $right = self::toMinutes($b);

    return $left !== NULL && $left === $right;
  }

}
