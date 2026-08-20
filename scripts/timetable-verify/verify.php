<?php

/**
 * @file
 * Timetable verification tool.
 *
 * Dev tooling, not module code. Nothing here is part of localgov_bus_data.
 *
 * Run it inside the DDEV web container:
 *
 * @code
 * ddev exec php scripts/timetable-verify/verify.php
 * ddev exec php scripts/timetable-verify/verify.php --route=X5
 * @endcode
 *
 * The script boots Drupal itself rather than running through
 * `drush php:script`, because Drush treats a script that calls exit() as an
 * abnormal termination and rewrites the status to 1 either way. This tool has
 * to exit nonzero when check 1 finds a difference and zero when it does not,
 * so it needs the exit code to be its own.
 *
 * See README.md in this directory for the options and for what the two
 * checks mean.
 */

declare(strict_types=1);

use Drupal\Core\Database\Connection;
use Drupal\Core\DrupalKernel;
use GuzzleHttp\Client;
use LocalgovBusData\TimetableVerify\BustimesCheck;
use LocalgovBusData\TimetableVerify\BustimesParser;
use LocalgovBusData\TimetableVerify\DatabaseTimetable;
use LocalgovBusData\TimetableVerify\Fetcher;
use LocalgovBusData\TimetableVerify\LocalPageParser;
use LocalgovBusData\TimetableVerify\PageDatabaseCheck;
use LocalgovBusData\TimetableVerify\ReportWriter;
use Symfony\Component\HttpFoundation\Request;

if (PHP_SAPI !== 'cli') {
  exit(1);
}

$verify_dir = __DIR__;
foreach (glob($verify_dir . '/lib/*.php') ?: [] as $verify_class_file) {
  require_once $verify_class_file;
}

/**
 * Reads a --name=value option out of the command line arguments.
 *
 * @param array $arguments
 *   Raw command line arguments.
 * @param string $name
 *   Option name without the leading dashes.
 * @param string|null $default
 *   Value to use when the option is absent.
 *
 * @return string|null
 *   The option value.
 */
function timetable_verify_option(array $arguments, string $name, ?string $default = NULL): ?string {
  foreach ($arguments as $argument) {
    if (str_starts_with((string) $argument, '--' . $name . '=')) {
      return substr((string) $argument, strlen($name) + 3);
    }
  }

  return $default;
}

/**
 * Reads a boolean --name flag out of the command line arguments.
 *
 * @param array $arguments
 *   Raw command line arguments.
 * @param string $name
 *   Flag name without the leading dashes.
 *
 * @return bool
 *   TRUE when the flag is present.
 */
function timetable_verify_flag(array $arguments, string $name): bool {
  return in_array('--' . $name, array_map('strval', $arguments), TRUE);
}

$arguments = array_slice($argv, 1);

if (timetable_verify_flag($arguments, 'help')) {
  fwrite(STDOUT, <<<'HELP'
Usage: ddev exec php scripts/timetable-verify/verify.php [options]

  --base-url=URL   Site to read timetable pages from.
                   Default https://lgd-bus-data-dev.ddev.site
  --route=NAME     Only routes with this route_short_name, e.g. X5.
  --slug=SLUG      Only the route page with this slug.
  --refresh        Refetch bustimes.org pages instead of using the disk cache.
  --skip-bustimes  Run check 1 only.
  --help           Show this message.

Exits 1 when check 1 finds any difference or a page could not be fetched.
Check 2 differences never affect the exit code.

HELP);
  exit(0);
}

$repository_root = dirname($verify_dir, 2);
$document_root = $repository_root . '/web';

$autoloader = require_once $repository_root . '/vendor/autoload.php';
chdir($document_root);

// Named $site_url, not $base_url: DrupalKernel::initializeRequestGlobals()
// assigns the global $base_url during preHandle(), which would silently
// overwrite a script variable of that name and send every request to the
// default site instead of the one asked for.
$site_url = rtrim((string) timetable_verify_option($arguments, 'base-url', 'https://lgd-bus-data-dev.ddev.site'), '/');

// Boot Drupal against the site under test so the database connection and the
// date settings are the ones the pages are rendered with.
$kernel = DrupalKernel::createFromRequest(Request::create($site_url . '/'), $autoloader, 'prod');
$kernel->boot();
$kernel->preHandle(Request::create($site_url . '/'));

$route_filter = timetable_verify_option($arguments, 'route');
$slug_filter = timetable_verify_option($arguments, 'slug');
$refresh = timetable_verify_flag($arguments, 'refresh');
$skip_bustimes = timetable_verify_flag($arguments, 'skip-bustimes');

$database = \Drupal::service('database');
assert($database instanceof Connection);

// The same UTC date localgov_bus_data_views_query_alter() uses to restrict
// the calendar join to the current service period.
$today = \Drupal::service('date.formatter')->format(
  \Drupal::service('datetime.time')->getRequestTime(),
  'custom',
  'Y-m-d',
  'UTC',
);

$routes = require $verify_dir . '/routes.php';
if ($route_filter !== NULL) {
  $routes = array_values(array_filter(
    $routes,
    static fn(array $route): bool => $route['route_short_name'] === $route_filter,
  ));
}
if ($slug_filter !== NULL) {
  $routes = array_values(array_filter(
    $routes,
    static fn(array $route): bool => $route['slug'] === $slug_filter,
  ));
}
if ($routes === []) {
  fwrite(STDERR, "No routes selected.\n");
  exit(2);
}

$fetcher = new Fetcher(
  new Client(['http_errors' => FALSE]),
  $verify_dir . '/cache',
  $refresh,
);
$timetable = new DatabaseTimetable($database, $today);

$days = array_keys(DatabaseTimetable::DAY_COLUMNS);
$day_parameters = [
  'weekday' => 'bustimes_weekdays',
  'saturday' => 'bustimes_saturday',
  'sunday' => 'bustimes_sunday',
];
$directions = [0, 1];

$results = [];
$local_fetches = 0;
$bustimes_fetches = 0;

foreach ($routes as $route) {
  fwrite(STDOUT, sprintf(
    "Verifying %s %s / %s\n",
    $route['agency_id'],
    $route['route_short_name'],
    $route['slug'],
  ));

  $pages = [];
  // Page journeys for check 2, keyed by day then direction.
  $page_journeys = [];
  // Stop display name to ATCO code, for resolving page rows to real stops.
  $name_to_atco = [];
  $ambiguous_names = [];

  foreach ($days as $day) {
    foreach ($directions as $direction) {
      // Mirrors what the page's own exposed filter form submits: the chosen
      // day is '1' and the other two are empty, which Views reads as "any".
      $query = [];
      foreach ($day_parameters as $key => $parameter) {
        $query[$parameter] = $key === $day ? '1' : '';
      }
      $query['bustimes_direction_id'] = (string) $direction;

      $url = sprintf(
        '%s/buses/routes/%s/%s/%s?%s',
        $site_url,
        rawurlencode($route['agency_id']),
        rawurlencode($route['route_short_name']),
        rawurlencode($route['slug']),
        http_build_query($query),
      );

      $trips = $timetable->trips(
        $route['agency_id'],
        $route['route_short_name'],
        $route['slug'],
        $day,
        $direction,
      );

      foreach ($trips as $trip) {
        foreach ($trip->calls as $call) {
          $name = $call->stopName;
          $atco = $call->atco;
          if (isset($name_to_atco[$name]) && $name_to_atco[$name] !== $atco) {
            $ambiguous_names[$name] = TRUE;
          }
          $name_to_atco[$name] = $atco;
        }
      }

      try {
        $html = $fetcher->fetchLocal($url);
        $local_fetches++;
      }
      catch (\Throwable $e) {
        $pages[] = [
          'day' => $day,
          'direction' => $direction,
          'url' => $url,
          'error' => $e->getMessage(),
          'check1' => NULL,
        ];
        continue;
      }

      $grid = LocalPageParser::parse($html);
      $page_journeys[$day][$direction] = $grid->journeys();

      $pages[] = [
        'day' => $day,
        'direction' => $direction,
        'url' => $url,
        'error' => NULL,
        'check1' => PageDatabaseCheck::run($grid, $trips),
      ];
    }
  }

  foreach (array_keys($ambiguous_names) as $name) {
    $name_to_atco[$name] = NULL;
  }

  $bustimes = NULL;
  $bustimes_url = $route['bustimes_url'] ?? NULL;
  if ($bustimes_url !== NULL && !$skip_bustimes) {
    $already_cached = $fetcher->isCached($bustimes_url);
    $body = $fetcher->fetchCached($bustimes_url);
    if (!$already_cached && $body !== NULL) {
      $bustimes_fetches++;
    }

    if ($body === NULL) {
      $bustimes = [
        'url' => $bustimes_url,
        'error' => 'fetch failed: ' . ($fetcher->error($bustimes_url) ?? 'unknown error'),
        'date' => NULL,
        'day' => NULL,
        'directions' => [],
      ];
    }
    else {
      try {
        $parsed = BustimesParser::parse($body);
        $date = $parsed['date'] ?? $today;
        $weekday = (int) date('N', (int) strtotime($date));
        $day = match (TRUE) {
          $weekday === 6 => 'saturday',
          $weekday === 7 => 'sunday',
          default => 'weekday',
        };

        $local = [];
        foreach ($page_journeys[$day] ?? [] as $direction => $journeys) {
          $local[$direction] = BustimesCheck::withAtco($journeys, $name_to_atco);
        }

        $bustimes = [
          'url' => $bustimes_url,
          'error' => NULL,
          'date' => $date,
          'weekdayName' => date('l', (int) strtotime($date)),
          'day' => $day,
          'directions' => BustimesCheck::run($parsed['groupings'], $local),
        ];
      }
      catch (\Throwable $e) {
        // The markup defeated the parser. Say so rather than guess at what
        // the page meant, and do not retry.
        $bustimes = [
          'url' => $bustimes_url,
          'error' => 'could not be parsed: ' . $e->getMessage()
          . '. The bustimes.org markup for this service is not what this'
          . ' parser expects, so no comparison was attempted.',
          'date' => NULL,
          'day' => NULL,
          'directions' => [],
        ];
      }
    }
  }

  $results[] = [
    'route' => $route,
    'pages' => $pages,
    'bustimes' => $bustimes,
  ];
}

$differences = 0;
$errors = 0;
foreach ($results as $result) {
  foreach ($result['pages'] as $page) {
    if ($page['error'] !== NULL) {
      $errors++;
      continue;
    }
    $differences += $page['check1']['differenceCount'];
  }
}

$report = new ReportWriter(
  [
    'Generated' => date('Y-m-d H:i:s T'),
    'Site' => $site_url,
    'Service period date' => $today . ' (UTC, as the view uses)',
    'Routes checked' => (string) count($routes),
    'Local pages fetched' => (string) $local_fetches,
    'bustimes.org pages fetched' => $bustimes_fetches === 0
      ? '0 (served from the on-disk cache)'
      : (string) $bustimes_fetches,
    'Check 1 differences' => (string) $differences,
    'Pages that failed to fetch' => (string) $errors,
  ],
  $results,
);

$reports_dir = $verify_dir . '/reports';
if (!is_dir($reports_dir)) {
  mkdir($reports_dir, 0777, TRUE);
}
$report_path = $reports_dir . '/' . date('Y-m-d-His') . '.md';
file_put_contents($report_path, $report->render());

fwrite(STDOUT, sprintf(
  "\nReport: %s\nCheck 1 differences: %d\nPages that failed to fetch: %d\n",
  $report_path,
  $differences,
  $errors,
));

// Check 1 differences and unreachable pages fail the run. Check 2 never does.
exit($differences > 0 || $errors > 0 ? 1 : 0);
