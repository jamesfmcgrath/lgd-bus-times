<?php

declare(strict_types=1);

namespace LocalgovBusData\TimetableVerify;

use Drupal\Core\Database\Connection;

/**
 * Reads trips straight out of the bus data tables.
 *
 * The query reproduces exactly what the localgov_bus_timetable view asks for:
 * the four required relationships as inner joins, the direction filter, the
 * selected day column, and the current-service-period restriction that
 * localgov_bus_data_views_query_alter() adds.
 *
 * @see \localgov_bus_data_views_query_alter()
 * @see config/install/views.view.localgov_bus_timetable.yml
 */
final class DatabaseTimetable {

  /**
   * Day tab identifier to the calendar column the view filters on.
   */
  public const DAY_COLUMNS = [
    'weekday' => 'bustimes_weekday',
    'saturday' => 'bustimes_saturday',
    'sunday' => 'bustimes_sunday',
  ];

  public function __construct(
    private readonly Connection $database,
    private readonly string $today,
  ) {}

  /**
   * Loads the trips the timetable page for these arguments should show.
   *
   * @param string $agencyId
   *   GTFS agency ID.
   * @param string $routeShortName
   *   GTFS route short name.
   * @param string $slug
   *   Route slug, the third URL segment.
   * @param string $day
   *   One of the DAY_COLUMNS keys.
   * @param int $direction
   *   GTFS direction ID, 0 or 1.
   *
   * @return array<string, Journey>
   *   Journeys keyed by trip entity ID, each call ordered by stop sequence.
   */
  public function trips(string $agencyId, string $routeShortName, string $slug, string $day, int $direction): array {
    if (!isset(self::DAY_COLUMNS[$day])) {
      throw new \InvalidArgumentException(sprintf('Unknown day "%s".', $day));
    }
    $day_column = self::DAY_COLUMNS[$day];

    $query = $this->database->select('localgov_bus_stop_time', 'st');
    $query->innerJoin('localgov_bus_trip', 't', 't.id = st.bustimes_trip_id');
    $query->innerJoin('localgov_bus_route', 'r', 'r.id = t.bustimes_route_id');
    $query->innerJoin('localgov_bus_calendar', 'c', 'c.id = t.bustimes_service_id');
    $query->innerJoin('localgov_bus_stop', 's', 's.id = st.bustimes_stop_id');

    $query->fields('st', ['bustimes_stop_sequence', 'bustimes_departure_time']);
    $query->addField('t', 'id', 'trip_key');
    $query->addField('t', 'bustimes_trip_id', 'gtfs_trip_id');
    $query->addField('s', 'id', 'stop_key');
    $query->addField('s', 'bustimes_stop_id', 'atco');
    $query->fields('s', [
      'bustimes_stop_name',
      'bustimes_indicator',
      'bustimes_street',
      'bustimes_locality',
    ]);

    $query->condition('r.bustimes_agency_id', $agencyId);
    $query->condition('r.bustimes_route_short_name', $routeShortName);
    $query->condition('r.bustimes_slug', $slug);
    $query->condition('t.bustimes_direction_id', $direction);
    $query->condition('c.' . $day_column, 1);
    // The service-period restriction the view's query alter adds.
    $query->condition('c.bustimes_start_date', $this->today, '<=');
    $query->condition('c.bustimes_end_date', $this->today, '>=');

    $query->orderBy('t.id');
    $query->orderBy('st.bustimes_stop_sequence');

    $grouped = [];
    foreach ($query->execute() as $row) {
      $grouped[(string) $row->trip_key][] = $row;
    }

    $trips = [];
    foreach ($grouped as $trip_key => $rows) {
      $calls = [];
      $occurrences = [];
      foreach ($rows as $row) {
        $stop_key = (int) $row->stop_key;
        $occurrence = $occurrences[$stop_key] ?? 0;
        $occurrences[$stop_key] = $occurrence + 1;

        $calls[] = new Call(
          stopName: self::enrichedName($row),
          time: BusTime::toHourMinute((string) $row->bustimes_departure_time),
          occurrence: $occurrence,
          rowIndex: (int) $row->bustimes_stop_sequence,
          atco: (string) $row->atco,
          stopKey: $stop_key,
        );
      }

      $trips[$trip_key] = new Journey(
        label: sprintf('trip %s (%s)', $trip_key, $rows[0]->gtfs_trip_id),
        calls: $calls,
      );
    }

    return $trips;
  }

  /**
   * Fingerprints a trip the way the view's style plugin deduplicates them.
   *
   * The style plugin drops a trip whose (stop, time) pairs are identical to
   * one it has already rendered, because the BODS feed contains duplicate
   * trip records. Fingerprinting on the stop entity ID and occurrence, not
   * the stop name, matches what the plugin does.
   *
   * @param \LocalgovBusData\TimetableVerify\Journey $trip
   *   A trip loaded by trips().
   *
   * @return string
   *   Fingerprint string.
   */
  public static function fingerprint(Journey $trip): string {
    $parts = [];
    foreach ($trip->calls as $call) {
      $stop = $call->stopKey !== NULL
        ? 'stop:' . $call->stopKey
        : 'seq:' . $call->rowIndex;
      $parts[] = $stop . '#' . $call->occurrence . '@' . $call->time;
    }

    return implode('|', $parts);
  }

  /**
   * Builds the display name the timetable page shows for a stop.
   *
   * Mirrors BusStopHelper::getEnrichedName(), which the style plugin calls,
   * so stop labels can be compared literally.
   *
   * @param object $row
   *   Query result row carrying the stop's name fields.
   *
   * @return string
   *   Enriched display name.
   *
   * @see \Drupal\localgov_bus_data\Utility\BusStopHelper::getEnrichedName()
   */
  private static function enrichedName(object $row): string {
    $name = (string) $row->bustimes_stop_name;
    $indicator = trim((string) ($row->bustimes_indicator ?? ''));
    $street = trim((string) ($row->bustimes_street ?? ''));
    $locality = trim((string) ($row->bustimes_locality ?? ''));

    if ($indicator === '' && $street === '' && $locality === '') {
      return $name;
    }

    $parts = [];
    if ($indicator !== '') {
      $parts[] = '(' . $indicator . ')';
    }
    if ($street !== '') {
      $parts[] = $street;
    }
    $detail = implode(' ', $parts);

    $enriched = $name;
    if ($detail !== '') {
      $enriched .= ' ' . $detail;
    }
    if ($locality !== '') {
      $enriched .= ', ' . $locality;
    }

    return $enriched;
  }

}
