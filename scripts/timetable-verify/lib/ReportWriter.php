<?php

declare(strict_types=1);

namespace LocalgovBusData\TimetableVerify;

/**
 * Renders a run's results as a markdown report.
 */
final class ReportWriter {

  /**
   * Human-readable labels for the three day tabs.
   */
  private const DAY_LABELS = [
    'weekday' => 'Mon–Fri',
    'saturday' => 'Saturday',
    'sunday' => 'Sunday',
  ];

  /**
   * How many rows of each difference list to print before truncating.
   */
  private const MAX_ROWS = 25;

  public function __construct(
    private readonly array $meta,
    private readonly array $results,
  ) {}

  /**
   * Renders the whole report.
   *
   * @return string
   *   Markdown.
   */
  public function render(): string {
    $out = [];
    $out[] = '# Bus timetable verification report';
    $out[] = '';
    $out[] = $this->renderMeta();
    $out[] = $this->renderSummary();
    $out[] = $this->renderCheckOne();
    $out[] = $this->renderCheckTwo();

    return implode("\n", $out) . "\n";
  }

  /**
   * Renders the run metadata block.
   *
   * @return string
   *   Markdown.
   */
  private function renderMeta(): string {
    $out = [];
    $out[] = '| Field | Value |';
    $out[] = '| --- | --- |';
    foreach ($this->meta as $label => $value) {
      $out[] = sprintf('| %s | %s |', $label, $value);
    }
    $out[] = '';

    return implode("\n", $out);
  }

  /**
   * Renders the summary table.
   *
   * @return string
   *   Markdown.
   */
  private function renderSummary(): string {
    $out = [];
    $out[] = '## Summary';
    $out[] = '';
    $out[] = 'Check 1 is the module-correctness check and must read `0 diffs`.';
    $out[] = 'Check 2 is diagnostic only and never fails the run. It compares one';
    $out[] = 'day, the day bustimes.org rendered, so its columns are blank on the';
    $out[] = 'other day tabs.';
    $out[] = '';
    $out[] = '| Route | Slug | Day | Dir | Check 1 | Cells | bustimes matched | bustimes mismatched |';
    $out[] = '| --- | --- | --- | --- | --- | --- | --- | --- |';

    foreach ($this->results as $result) {
      $route = $result['route'];
      $bustimes_by_direction = $this->bustimesByDirection($result);

      foreach ($result['pages'] as $page) {
        $check_two_matched = '';
        $check_two_mismatched = '';
        $comparable = isset($result['bustimes']['day'])
          && $result['bustimes']['day'] === $page['day']
          && isset($bustimes_by_direction[$page['direction']]);
        if ($comparable) {
          $entry = $bustimes_by_direction[$page['direction']];
          $check_two_matched = (string) $entry['matched'];
          $check_two_mismatched = (string) ($entry['mismatched'] + count($entry['bustimesOnly']) + count($entry['localOnly']));
        }

        if ($page['error'] !== NULL) {
          $verdict = 'ERROR';
          $cells = '';
        }
        else {
          $count = $page['check1']['differenceCount'];
          $verdict = $count === 0 ? '0 diffs' : sprintf('**%d diffs**', $count);
          $cells = sprintf('%d / %d', $page['check1']['pageCells'], $page['check1']['databaseCells']);
        }

        $out[] = sprintf(
          '| %s | %s | %s | %d | %s | %s | %s | %s |',
          $route['route_short_name'],
          $route['slug'],
          self::DAY_LABELS[$page['day']],
          $page['direction'],
          $verdict,
          $cells,
          $check_two_matched,
          $check_two_mismatched,
        );
      }
    }
    $out[] = '';
    $out[] = 'Cells shows page cells / database cells after duplicate trips are dropped.';
    $out[] = 'bustimes mismatched combines journeys with time differences, journeys only';
    $out[] = 'on bustimes.org, and journeys only on the local page.';
    $out[] = '';

    return implode("\n", $out);
  }

  /**
   * Renders the check 1 detail section.
   *
   * @return string
   *   Markdown.
   */
  private function renderCheckOne(): string {
    $out = [];
    $out[] = '## Check 1, page versus database';
    $out[] = '';
    $out[] = 'For every route page, every day tab and both directions: the rendered';
    $out[] = 'pivot table against a direct query over `localgov_bus_route`,';
    $out[] = '`localgov_bus_trip`, `localgov_bus_calendar`, `localgov_bus_stop_time`';
    $out[] = 'and `localgov_bus_stop` using the filters the view applies (direction,';
    $out[] = 'day column, current service period). Any difference is a module bug.';
    $out[] = '';

    $problem_pages = [];
    $error_pages = [];
    $total_pages = 0;
    foreach ($this->results as $result) {
      foreach ($result['pages'] as $page) {
        $total_pages++;
        if ($page['error'] !== NULL) {
          $error_pages[] = [$result['route'], $page];
        }
        elseif ($page['check1']['differenceCount'] > 0) {
          $problem_pages[] = [$result['route'], $page];
        }
      }
    }

    if ($error_pages === [] && $problem_pages === []) {
      $out[] = sprintf(
        '**Result: %d of %d pages match the database exactly. Zero cells differ, zero database times missing from a page, zero page times missing from the database.**',
        $total_pages,
        $total_pages,
      );
      $out[] = '';
      return implode("\n", $out);
    }

    $out[] = sprintf(
      '**Result: %d of %d pages differ from the database; %d pages could not be fetched.**',
      count($problem_pages),
      $total_pages,
      count($error_pages),
    );
    $out[] = '';

    foreach ($error_pages as [$route, $page]) {
      $out[] = sprintf(
        '### %s %s / %s / direction %d: fetch failed',
        $route['route_short_name'],
        $route['slug'],
        self::DAY_LABELS[$page['day']],
        $page['direction'],
      );
      $out[] = '';
      $out[] = '- URL: `' . $page['url'] . '`';
      $out[] = '- Error: ' . $page['error'];
      $out[] = '';
    }

    foreach ($problem_pages as [$route, $page]) {
      $check = $page['check1'];
      $out[] = sprintf(
        '### %s %s / %s / direction %d',
        $route['route_short_name'],
        $route['slug'],
        self::DAY_LABELS[$page['day']],
        $page['direction'],
      );
      $out[] = '';
      $out[] = '- URL: `' . $page['url'] . '`';
      $out[] = sprintf(
        '- Page columns: %d. Database trips: %d (%d dropped as exact duplicates).',
        $check['pageColumns'],
        $check['databaseTrips'],
        $check['deduplicatedTrips'],
      );
      $out[] = '';

      $out[] = self::table(
        sprintf('Cells that differ (%d)', count($check['cellsDiffer'])),
        ['Journey', 'Stop', 'Page', 'Database'],
        array_map(
          static fn(array $row): array => [$row['journey'], $row['stop'], $row['page'], $row['database']],
          $check['cellsDiffer'],
        ),
      );
      $out[] = self::table(
        sprintf('Times in the database missing from the page (%d)', count($check['databaseMissingFromPage'])),
        ['Journey', 'Stop', 'Time'],
        array_map(
          static fn(array $row): array => [$row['journey'], $row['stop'], $row['time']],
          $check['databaseMissingFromPage'],
        ),
      );
      $out[] = self::table(
        sprintf('Times on the page missing from the database (%d)', count($check['pageMissingFromDatabase'])),
        ['Journey', 'Stop', 'Time'],
        array_map(
          static fn(array $row): array => [$row['journey'], $row['stop'], $row['time']],
          $check['pageMissingFromDatabase'],
        ),
      );
    }

    return implode("\n", $out);
  }

  /**
   * Renders the check 2 detail section.
   *
   * @return string
   *   Markdown.
   */
  private function renderCheckTwo(): string {
    $out = [];
    $out[] = '## Check 2, page versus bustimes.org';
    $out[] = '';
    $out[] = 'Diagnostic only. A difference here on a page that passed check 1 means';
    $out[] = 'the site is showing the operator data faithfully and bustimes.org shows';
    $out[] = 'something else: a feed-quality question for the operator, not a module bug.';
    $out[] = '';

    foreach ($this->results as $result) {
      $route = $result['route'];
      $title = sprintf('### %s (%s / %s)', $route['route_short_name'], $route['agency_id'], $route['slug']);
      $out[] = $title;
      $out[] = '';

      if (!empty($route['notes'])) {
        $out[] = '> **Note.** ' . str_replace("\n", "\n> ", trim($route['notes']));
        $out[] = '';
      }

      $bustimes = $result['bustimes'];
      if ($bustimes === NULL) {
        $out[] = 'No bustimes.org URL configured. Check 1 only.';
        $out[] = '';
        continue;
      }

      $out[] = '- Source: <' . $bustimes['url'] . '>';
      if ($bustimes['error'] !== NULL) {
        $out[] = '- **Not compared: ' . $bustimes['error'] . '**';
        $out[] = '';
        continue;
      }
      $out[] = sprintf(
        '- bustimes.org rendered %s, which is a %s; compared against the %s tab.',
        $bustimes['date'] ?? 'an unstated date',
        $bustimes['weekdayName'] ?? 'unknown day',
        self::DAY_LABELS[$bustimes['day']],
      );
      $out[] = '';

      foreach ($bustimes['directions'] as $entry) {
        $out[] = sprintf(
          '#### %s → local direction %s',
          $entry['heading'] !== '' ? $entry['heading'] : 'unnamed grouping',
          $entry['direction'] === NULL ? 'unmatched' : (string) $entry['direction'],
        );
        $out[] = '';
        $out[] = sprintf(
          '- Journeys matched with identical times: **%d**',
          $entry['matched'],
        );
        $out[] = sprintf(
          '- Journeys matched with time differences: **%d**',
          $entry['mismatched'],
        );
        $out[] = sprintf(
          '- On bustimes.org with no matching local journey: **%d**',
          count($entry['bustimesOnly']),
        );
        $out[] = sprintf(
          '- Local journeys with no bustimes.org match: **%d**',
          count($entry['localOnly']),
        );
        $out[] = '';

        if ($entry['differences'] !== [] && $entry['boundaryOnly'] === count($entry['differences'])) {
          $out[] = sprintf(
            'All %d of these differ at one stop only, %s, which is where this'
            . ' local route ends. The GTFS feed splits the through service'
            . ' there, so the local page shows the arrival at its final stop'
            . ' while bustimes.org shows the through departure. Every other'
            . ' stop on every one of these journeys agrees exactly.',
            $entry['boundaryOnly'],
            implode(', ', $entry['boundaryStops']),
          );
          $out[] = '';
        }

        if ($entry['differences'] !== []) {
          $out[] = 'Time differences, first ' . BustimesCheck::DIFFERENCES_SHOWN . ' differing stops each:';
          $out[] = '';
          foreach (array_slice($entry['differences'], 0, self::MAX_ROWS) as $difference) {
            $out[] = sprintf(
              '- bustimes.org `%s` vs local `%s`, %d differing stop(s):',
              $difference['bustimes'],
              $difference['local'],
              $difference['total'],
            );
            foreach ($difference['stops'] as $stop) {
              $out[] = sprintf(
                '  - %s: bustimes.org %s, local %s',
                $stop['stop'],
                $stop['bustimes'],
                $stop['local'],
              );
            }
          }
          if (count($entry['differences']) > self::MAX_ROWS) {
            $out[] = sprintf('- ... and %d more.', count($entry['differences']) - self::MAX_ROWS);
          }
          $out[] = '';
        }

        foreach ([
          'On bustimes.org only' => $entry['bustimesOnly'],
          'On the local page only' => $entry['localOnly'],
        ] as $label => $list) {
          if ($list === []) {
            continue;
          }
          $out[] = $label . ':';
          $out[] = '';
          foreach (array_slice($list, 0, self::MAX_ROWS) as $item) {
            $out[] = '- ' . $item;
          }
          if (count($list) > self::MAX_ROWS) {
            $out[] = sprintf('- ... and %d more.', count($list) - self::MAX_ROWS);
          }
          $out[] = '';
        }
      }
    }

    return implode("\n", $out);
  }

  /**
   * Indexes a route's check 2 results by local direction.
   *
   * @param array $result
   *   One route's run result.
   *
   * @return array<int, array>
   *   Check 2 entries keyed by direction ID.
   */
  private function bustimesByDirection(array $result): array {
    $indexed = [];
    foreach ($result['bustimes']['directions'] ?? [] as $entry) {
      if ($entry['direction'] !== NULL) {
        $indexed[$entry['direction']] = $entry;
      }
    }

    return $indexed;
  }

  /**
   * Renders a titled markdown table, or a "none" line when there are no rows.
   *
   * @param string $title
   *   Heading for the block.
   * @param array<int, string> $headers
   *   Column headers.
   * @param array<int, array<int, string>> $rows
   *   Table rows.
   *
   * @return string
   *   Markdown.
   */
  private static function table(string $title, array $headers, array $rows): string {
    $out = [];
    $out[] = '**' . $title . '**';
    $out[] = '';
    if ($rows === []) {
      $out[] = 'None.';
      $out[] = '';
      return implode("\n", $out);
    }

    $out[] = '| ' . implode(' | ', $headers) . ' |';
    $out[] = '| ' . implode(' | ', array_fill(0, count($headers), '---')) . ' |';
    foreach (array_slice($rows, 0, self::MAX_ROWS) as $row) {
      $out[] = '| ' . implode(' | ', array_map(
        static fn(string $cell): string => str_replace('|', '\\|', $cell),
        $row,
      )) . ' |';
    }
    if (count($rows) > self::MAX_ROWS) {
      $out[] = sprintf('| ... and %d more | | | |', count($rows) - self::MAX_ROWS);
    }
    $out[] = '';

    return implode("\n", $out);
  }

}
