<?php

declare(strict_types=1);

namespace LocalgovBusData\TimetableVerify;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Parses a bustimes.org service page into journeys.
 *
 * The page holds one "grouping" per direction, each with a heading and one or
 * more tables. Rows are stops, columns are journeys, and an empty cell means
 * the journey skips that stop. A timing point where the bus waits is drawn as
 * two rows joined by rowspan, arrival above departure, so the grid has to be
 * built span-aware and the departure taken from the last covered row.
 *
 * Each row links to /stops/<ATCO code>, and the imported GTFS stop_id is that
 * same ATCO code, so stops match exactly rather than only by name.
 *
 * The page renders one date at a time, defaulting to today, so a fetched page
 * covers whichever day of the week that date falls on.
 */
final class BustimesParser {

  private function __construct() {}

  /**
   * Parses a bustimes.org service page.
   *
   * @param string $html
   *   Full page HTML.
   *
   * @return array{date: string|null, groupings: array<int, array{heading: string, journeys: array<int, Journey>, stops: int}>}
   *   The date the page was rendered for and one entry per direction.
   *
   * @throws \RuntimeException
   *   When the page holds no timetable this parser recognises. The caller
   *   reports that rather than guessing at the content.
   */
  public static function parse(string $html): array {
    $crawler = new Crawler($html);

    $groupings = [];
    $crawler->filter('div.grouping')->each(
      static function (Crawler $grouping) use (&$groupings): void {
        $heading_node = $grouping->filter('h2');
        $heading = $heading_node->count() > 0 ? trim($heading_node->first()->text('')) : '';

        $journeys = [];
        $stops = 0;
        $grouping->filter('table.timetable')->each(
          static function (Crawler $table) use (&$journeys, &$stops): void {
            $parsed = self::parseTable($table);
            $stops += $parsed['stops'];
            foreach ($parsed['journeys'] as $journey) {
              $journeys[] = $journey;
            }
          }
        );

        if ($journeys === []) {
          return;
        }

        // Renumber the labels so they read consecutively across a grouping
        // that bustimes.org split into several tables.
        $renumbered = [];
        foreach ($journeys as $index => $journey) {
          $renumbered[] = new Journey(
            label: sprintf('bustimes journey %d', $index + 1),
            calls: $journey->calls,
          );
        }

        $groupings[] = [
          'heading' => $heading,
          'journeys' => $renumbered,
          'stops' => $stops,
        ];
      }
    );

    if ($groupings === []) {
      throw new \RuntimeException('No div.grouping timetable found on the page.');
    }

    return [
      'date' => self::extractDate($crawler, $html),
      'groupings' => $groupings,
    ];
  }

  /**
   * Parses one timetable table into journeys.
   *
   * @param \Symfony\Component\DomCrawler\Crawler $table
   *   The table element.
   *
   * @return array{journeys: array<int, Journey>, stops: int}
   *   Journeys in column order, and how many stops the table listed.
   */
  private static function parseTable(Crawler $table): array {
    // Span-aware grid: $grid[row][column], column 0 being the stop label.
    $grid = [];
    $stop_cells = [];

    $row_index = 0;
    $table->filter('tr')->each(
      static function (Crawler $row) use (&$grid, &$stop_cells, &$row_index): void {
        $column = 0;
        foreach ($row->children() as $node) {
          $tag = strtolower($node->nodeName);
          if ($tag !== 'th' && $tag !== 'td') {
            continue;
          }
          while (isset($grid[$row_index][$column])) {
            $column++;
          }

          $rowspan = max(1, (int) ($node->getAttribute('rowspan') ?: 1));
          $colspan = max(1, (int) ($node->getAttribute('colspan') ?: 1));

          $cell = new Crawler($node);
          $value = trim($cell->text(''));
          $value = (string) preg_replace('/\s+/u', ' ', $value);

          if ($tag === 'th' && $column === 0) {
            $link = $cell->filter('a');
            $atco = NULL;
            if ($link->count() > 0) {
              $href = (string) $link->first()->attr('href');
              if (preg_match('~/stops/([^/?#]+)~', $href, $match) === 1) {
                $atco = $match[1];
              }
            }
            $stop_cells[] = [
              'name' => $value,
              'atco' => $atco,
              'firstRow' => $row_index,
              'lastRow' => $row_index + $rowspan - 1,
            ];
          }

          for ($r = 0; $r < $rowspan; $r++) {
            for ($c = 0; $c < $colspan; $c++) {
              $grid[$row_index + $r][$column + $c] = $value;
            }
          }
          $column += $colspan;
        }
        $row_index++;
      }
    );

    if ($stop_cells === []) {
      return ['journeys' => [], 'stops' => 0];
    }

    $columns = 0;
    foreach ($grid as $cells) {
      $columns = max($columns, count($cells));
    }

    $journeys = [];
    for ($column = 1; $column < $columns; $column++) {
      $calls = [];
      $occurrences = [];
      foreach ($stop_cells as $stop) {
        // A timing point drawn as arrival over departure spans two rows; the
        // departure is the last time printed in the span.
        $time = '';
        for ($row = $stop['firstRow']; $row <= $stop['lastRow']; $row++) {
          $value = $grid[$row][$column] ?? '';
          if ($value !== '') {
            $time = $value;
          }
        }
        if ($time === '' || BusTime::toMinutes($time) === NULL) {
          continue;
        }

        $identity = $stop['atco'] ?? $stop['name'];
        $occurrence = $occurrences[$identity] ?? 0;
        $occurrences[$identity] = $occurrence + 1;

        $calls[] = new Call(
          stopName: $stop['name'],
          time: $time,
          occurrence: $occurrence,
          rowIndex: $stop['firstRow'],
          atco: $stop['atco'],
        );
      }

      if ($calls === []) {
        continue;
      }
      $journeys[] = new Journey(
        label: sprintf('bustimes column %d', $column),
        calls: $calls,
      );
    }

    return ['journeys' => $journeys, 'stops' => count($stop_cells)];
  }

  /**
   * Finds the date the page was rendered for.
   *
   * @param \Symfony\Component\DomCrawler\Crawler $crawler
   *   The page crawler.
   * @param string $html
   *   Raw page HTML, for the noscript fallback the crawler does not expose
   *   as an element.
   *
   * @return string|null
   *   Date as "Y-m-d", or NULL when the page does not state one.
   */
  private static function extractDate(Crawler $crawler, string $html): ?string {
    $selected = $crawler->filter('select option[selected]');
    if ($selected->count() > 0) {
      $value = (string) $selected->first()->attr('value');
      if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
        return $value;
      }
    }
    if (preg_match('#<noscript>\s*(\d{4}-\d{2}-\d{2})\s*</noscript>#', $html, $match) === 1) {
      return $match[1];
    }

    return NULL;
  }

}
