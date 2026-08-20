# Timetable verification tool

Dev tooling for the `lgd-bus-data-dev` site. Nothing here is part of the
`localgov_bus_data` module and nothing here ships to Drupal.org.

Cumberland residents reported wrong times on the bus timetable pages. "Wrong"
can mean two different things, and telling them apart is the whole point of
this tool:

1. **Check 1, page versus database.** Does each rendered timetable page
   reproduce the imported GTFS data exactly? Both sides come from the same
   database, so any difference here is the view, the style plugin or the
   template getting something wrong. **This is the module-correctness check
   and it must be zero.** A nonzero result fails the run.

2. **Check 2, page versus bustimes.org.** Does the data we imported agree with
   what bustimes.org publishes for the same service? A difference here on a
   page that passed check 1 means the site is showing the operator's data
   faithfully and bustimes.org shows something else. That is a feed-quality
   question for the operator, not a module bug, so **check 2 never affects the
   exit code**.

## Running it

```bash
ddev exec php scripts/timetable-verify/verify.php
```

Options:

| Option | Effect |
| --- | --- |
| `--base-url=URL` | Site to read pages from. Default `https://lgd-bus-data-dev.ddev.site` |
| `--route=NAME` | Only routes with this `route_short_name`, e.g. `X5` |
| `--slug=SLUG` | Only the route page with this slug |
| `--refresh` | Refetch bustimes.org pages instead of using the disk cache |
| `--skip-bustimes` | Run check 1 only |
| `--help` | Usage |

Exit code is 1 when check 1 finds any difference or a page could not be
fetched, and 0 otherwise.

A markdown report lands in `reports/`, named for the run time: a summary table
of every route, day and direction, then detail sections for anything that
differs. Reports are gitignored; commit one deliberately if it is worth
keeping.

There is a self-test that does not touch the site or the network:

```bash
ddev exec php scripts/timetable-verify/selftest.php
```

It feeds a synthetic page and synthetic trips through the comparison and
asserts that each kind of difference is actually detected. A verifier that
reports "zero differences" is only worth reading if it can find a difference
when there is one, so run this after changing anything in `lib/`.

## Why a plain PHP script and not `drush php:script`

Drush treats a script that calls `exit()` as an abnormal termination and
rewrites the status to 1 either way, so `drush php:script` cannot return a
meaningful exit code. This tool has to exit nonzero on a check 1 difference
and zero otherwise, so it boots Drupal itself. It still needs the site's
autoloader and database, so it must run inside the DDEV web container.

## Configuration

Routes live in `routes.php`, one entry per route page:

```php
[
  'agency_id' => 'OP539',
  'route_short_name' => 'X5',
  'slug' => 'keswick',
  'bustimes_url' => 'https://bustimes.org/services/x5-...',
  'notes' => 'Rendered into the report under this route.',
]
```

The triple `agency_id` / `route_short_name` / `slug` is what the timetable URL
takes, so distinct GTFS routes that share an operator and route number get one
entry each. Omit `bustimes_url` or set it to `NULL` for a check-1-only entry.
`notes` is for known structural differences that are not defects; it is
rendered into the check 2 section so a reader does not have to rediscover
them.

**The seeded list includes second slugs for 554, X7 and 300.** They were not
in the original brief, but the imported data holds two distinct GTFS routes
under each of those operator and route number pairs, so each has two route
pages. Verifying only one would leave half the pages unchecked. Their entries
carry a note saying so.

## How check 1 works

For every configured route, every day tab (Mon–Fri, Saturday, Sunday) and both
directions, the tool fetches the page and runs a direct query over
`localgov_bus_route`, `localgov_bus_trip`, `localgov_bus_calendar`,
`localgov_bus_stop_time` and `localgov_bus_stop`.

The query reproduces what the view asks for:

- the four relationships as inner joins, since the view marks them required;
- `bustimes_direction_id` from the direction filter;
- the calendar day column the selected tab filters on (`bustimes_weekday`,
  `bustimes_saturday` or `bustimes_sunday`);
- `start_date <= today <= end_date`, the current-service-period restriction
  `localgov_bus_data_views_query_alter()` adds, using the same UTC date.

Page URLs mirror exactly what the page's own exposed filter form submits: the
selected day is `1` and the other two are empty, which Views reads as "any".

Journey columns on the page are then paired with database trips on content,
because neither side numbers them, and the pair is diffed to produce the three
reported lists: cells that differ, times in the database missing from the
page, times on the page missing from the database.

Two behaviours are reproduced deliberately rather than reported as
differences:

- **Duplicate trips.** The style plugin drops a trip whose whole schedule
  duplicates one already rendered, because the BODS feed carries duplicate
  trip records. The tool fingerprints trips the same way, on stop entity ID
  and time, and counts how many it dropped.
- **Enriched stop names.** The page labels rows with
  `BusStopHelper::getEnrichedName()`, so the tool builds the same
  "Name (Indicator) Street, Locality" string from the stop row in order to
  compare labels literally.

## How check 2 works

Each configured `bustimes_url` is fetched **once per run**, with a custom user
agent identifying the tool, and cached to `cache/` so re-runs cost bustimes.org
nothing. A failed fetch is recorded and not retried. If the markup defeats the
parser, the report says so for that route instead of guessing at the content.

bustimes.org renders **one date at a time**, defaulting to today. A fetched
page therefore covers a single day of the week, and the tool compares it
against whichever local day tab that date falls on. The report states the date
and the tab. The other two day tabs have no check 2 result, which is why their
summary columns are blank.

Stops are matched on the **NaPTAN ATCO code**: bustimes.org links each row to
`/stops/<atco>` and the imported GTFS `stop_id` is that same code, so the match
is exact. `StopName` normalisation (lowercase, punctuation stripped, noise
words such as `opp`, `adj`, `stand` and `road` removed, compared as a token
overlap) is the fallback for rows where no code is available on either side,
since the two sides order the locality differently: the site renders
"Bus Station (Ca) (Bay 4) Lonsdale Street, Carlisle" and bustimes.org renders
"Carlisle Bus Station (Ca) (Bay 4)".

bustimes.org draws a timing point where the bus waits as two rows joined by
`rowspan`, arrival above departure, so the grid is built span-aware and the
**departure** is taken as the comparable time.

bustimes.org publishes one "grouping" per direction, but both directions serve
nearly the same stops, so the grouping headings alone cannot say which GTFS
direction each one is. Groupings are assigned to directions by exact
(stop, time) agreement, which is decisive, and the report prints the mapping.

Journeys are then paired one to one, scoring identical times ahead of merely
shared stops so a journey with one wrong time is reported as a time difference
rather than as two unmatched journeys. Each direction reports journeys only on
bustimes.org, journeys only on the local page, and matched journeys with time
differences showing the first three differing stops.

### Leg boundaries

Where the GTFS feed splits a through service into two routes, the local page
for one leg ends at the interchange and shows the arrival there, while
bustimes.org runs straight through and shows the departure. Every other stop
agrees. The tool detects when every difference in a direction is that single
boundary stop and says so, so it does not read as thirty separate
discrepancies. X5 at Keswick is the documented case; 554 at Wigton, X7 at
Ravenglass and 300 at Maryport turn out to behave the same way.

## Layout

```
verify.php     entry point: options, orchestration, exit code
selftest.php   synthetic tests for the comparison logic
routes.php     route configuration
lib/           the pieces
cache/         bustimes.org responses (gitignored)
reports/       generated reports (gitignored)
```

| File | Role |
| --- | --- |
| `lib/Fetcher.php` | HTTP, with the on-disk cache for third-party pages |
| `lib/LocalPageParser.php` | Reads the rendered pivot table into a grid |
| `lib/Timetable.php` | The grid, and splitting it into journeys |
| `lib/Journey.php`, `lib/Call.php` | A journey and one of its calls |
| `lib/DatabaseTimetable.php` | The direct query, and the trip fingerprint |
| `lib/JourneyMatcher.php` | Pairs journeys from two sources on content |
| `lib/PageDatabaseCheck.php` | Check 1 |
| `lib/BustimesParser.php` | Reads a bustimes.org service page |
| `lib/BustimesCheck.php` | Check 2 |
| `lib/StopName.php`, `lib/BusTime.php` | Stop name and clock time normalisation |
| `lib/ReportWriter.php` | Renders the markdown report |
