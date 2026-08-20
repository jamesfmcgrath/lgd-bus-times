<?php

declare(strict_types=1);

namespace LocalgovBusData\TimetableVerify;

/**
 * A parsed timetable grid: stop rows by journey columns.
 */
final class Timetable {

  /**
   * Constructs a timetable grid.
   *
   * @param array<int, array{name: string, times: array<int, string|null>}> $rows
   *   Ordered stop rows. Each row's 'times' maps column index to a "HH:MM"
   *   string, or NULL where the journey does not call at that stop.
   * @param int $columnCount
   *   Number of journey columns.
   */
  public function __construct(
    public readonly array $rows,
    public readonly int $columnCount,
  ) {}

  /**
   * Reports whether the grid holds no rows at all.
   *
   * @return bool
   *   TRUE when there is nothing to compare.
   */
  public function isEmpty(): bool {
    return $this->rows === [];
  }

  /**
   * Splits the grid into one journey per column.
   *
   * Each call is keyed by the stop name plus how many times this journey has
   * already called there, so a circular route that passes the same stop twice
   * keeps both calls distinct. This mirrors the row keying the view's style
   * plugin uses, which counts occurrences per trip.
   *
   * @return array<int, Journey>
   *   Journeys in column order.
   */
  public function journeys(): array {
    $journeys = [];

    for ($column = 0; $column < $this->columnCount; $column++) {
      $calls = [];
      $occurrences = [];
      foreach ($this->rows as $index => $row) {
        $time = $row['times'][$column] ?? NULL;
        if ($time === NULL) {
          continue;
        }
        $name = $row['name'];
        $occurrence = $occurrences[$name] ?? 0;
        $occurrences[$name] = $occurrence + 1;

        $calls[] = new Call(
          stopName: $name,
          time: $time,
          occurrence: $occurrence,
          rowIndex: $index,
        );
      }

      $journeys[$column] = new Journey(
        label: sprintf('column %d', $column + 1),
        calls: $calls,
      );
    }

    return $journeys;
  }

  /**
   * Counts the times printed on the grid.
   *
   * @return int
   *   Number of non-empty cells.
   */
  public function cellCount(): int {
    $total = 0;
    foreach ($this->rows as $row) {
      foreach ($row['times'] as $time) {
        if ($time !== NULL) {
          $total++;
        }
      }
    }

    return $total;
  }

}
