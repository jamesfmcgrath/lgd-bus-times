<?php

declare(strict_types=1);

namespace LocalgovBusData\TimetableVerify;

/**
 * Normalises stop names so the two sides of check 2 can be compared.
 *
 * The local site renders "Name (Indicator) Street, Locality"; bustimes.org
 * renders variants such as "Locality Name (Indicator)" or
 * "Street, at Cross Street". Normalising to a bag of significant words lets
 * the same physical stop match across both conventions.
 *
 * Stop matching prefers the ATCO code, which both sides carry: bustimes.org
 * links each row to /stops/<atco>, and the imported GTFS stop_id is the same
 * ATCO code. This class is the fallback for rows where no code is available.
 */
final class StopName {

  /**
   * Words that carry no identifying value and are dropped before matching.
   */
  private const NOISE = [
    'the', 'at', 'opp', 'opposite', 'adj', 'adjacent', 'near', 'nr', 'o/s',
    'os', 'outside', 'by', 'before', 'after', 'stop', 'stand', 'bay', 'stance',
    'arrivals', 'departures', 'road', 'end', 'corner', 'junction', 'jct',
  ];

  private function __construct() {}

  /**
   * Reduces a stop name to a lowercase, punctuation-free token string.
   *
   * @param string $name
   *   Raw stop name from either side.
   *
   * @return string
   *   Space-joined significant tokens, in their original order.
   */
  public static function normalise(string $name): string {
    return implode(' ', self::tokens($name));
  }

  /**
   * Extracts the significant tokens of a stop name.
   *
   * @param string $name
   *   Raw stop name from either side.
   *
   * @return string[]
   *   Lowercase tokens with punctuation and noise words removed.
   */
  public static function tokens(string $name): array {
    $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $name = mb_strtolower(trim($name));
    // Fold the accented characters NaPTAN occasionally carries.
    $folded = @iconv('UTF-8', 'ASCII//TRANSLIT', $name);
    if (is_string($folded)) {
      $name = $folded;
    }
    $name = (string) preg_replace('/[^a-z0-9]+/', ' ', $name);

    $tokens = [];
    foreach (preg_split('/\s+/', trim($name)) ?: [] as $token) {
      if ($token === '' || in_array($token, self::NOISE, TRUE)) {
        continue;
      }
      $tokens[] = $token;
    }

    return $tokens;
  }

  /**
   * Scores how well two stop names describe the same stop.
   *
   * Uses the Jaccard overlap of the significant token sets, so word order
   * and the locality's position in the string do not matter.
   *
   * @param string $a
   *   First stop name.
   * @param string $b
   *   Second stop name.
   *
   * @return float
   *   Similarity between 0.0 (nothing in common) and 1.0 (same token set).
   */
  public static function similarity(string $a, string $b): float {
    $left = array_unique(self::tokens($a));
    $right = array_unique(self::tokens($b));
    if ($left === [] || $right === []) {
      return 0.0;
    }

    $shared = count(array_intersect($left, $right));
    $union = count(array_unique(array_merge($left, $right)));

    return $union === 0 ? 0.0 : $shared / $union;
  }

  /**
   * Decides whether two stop names are close enough to be the same stop.
   *
   * @param string $a
   *   First stop name.
   * @param string $b
   *   Second stop name.
   *
   * @return bool
   *   TRUE when the names match fuzzily.
   */
  public static function matches(string $a, string $b): bool {
    return self::similarity($a, $b) >= 0.5;
  }

}
