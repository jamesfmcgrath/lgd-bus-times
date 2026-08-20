<?php

declare(strict_types=1);

namespace LocalgovBusData\TimetableVerify;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Parses the pivot table rendered by the localgov_bus_timetable view.
 *
 * The table has one row per stop call and one column per trip. A cell that
 * holds an em-dash means the trip in that column does not call at that stop.
 *
 * @see \Drupal\localgov_bus_data\Plugin\views\style\BusTimetableStyle
 * @see templates/views-view-localgov-bus-timetable.html.twig
 */
final class LocalPageParser {

  private function __construct() {}

  /**
   * Parses a rendered timetable page into a grid.
   *
   * @param string $html
   *   Full page HTML.
   *
   * @return \LocalgovBusData\TimetableVerify\Timetable
   *   The parsed grid. A page with no timetable table (a route with no
   *   services on the selected day) yields an empty grid, which is a valid
   *   result rather than an error.
   */
  public static function parse(string $html): Timetable {
    $crawler = new Crawler($html);
    $tables = $crawler->filter('table.bus-timetable__table');
    if ($tables->count() === 0) {
      return new Timetable([], 0);
    }

    $columns = $tables->first()->filter('thead th.bus-timetable__time-col')->count();

    $rows = [];
    $tables->first()->filter('tbody tr.bus-timetable__row')->each(
      static function (Crawler $row) use (&$rows): void {
        $heading = $row->filter('th.bus-timetable__stop-name');
        if ($heading->count() === 0) {
          return;
        }

        $times = [];
        $row->filter('td.bus-timetable__time')->each(
          static function (Crawler $cell, int $index) use (&$times): void {
            $value = trim($cell->text(''));
            // The template prints an em-dash where a trip skips the stop.
            $times[$index] = ($value === '' || $value === '—') ? NULL : $value;
          }
        );

        $rows[] = [
          'name' => trim($heading->text('')),
          'times' => $times,
        ];
      }
    );

    return new Timetable($rows, $columns);
  }

}
