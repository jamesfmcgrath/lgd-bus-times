<?php

declare(strict_types=1);

namespace LocalgovBusData\TimetableVerify;

/**
 * One journey calling at one stop at one time.
 */
final class Call {

  /**
   * Constructs a call.
   *
   * @param string $stopName
   *   Stop name as displayed by whichever side produced this call.
   * @param string $time
   *   Departure time as "HH:MM".
   * @param int $occurrence
   *   How many times this journey had already called at this stop, so a
   *   circular route's repeat calls stay distinct. Zero-based.
   * @param int|null $rowIndex
   *   Grid row this call was read from, where the source was a grid.
   * @param string|null $atco
   *   NaPTAN ATCO code for the stop, where it is known.
   * @param int|null $stopKey
   *   Local stop entity ID, where it is known.
   */
  public function __construct(
    public readonly string $stopName,
    public readonly string $time,
    public readonly int $occurrence = 0,
    public readonly ?int $rowIndex = NULL,
    public readonly ?string $atco = NULL,
    public readonly ?int $stopKey = NULL,
  ) {}

  /**
   * Returns the key used to line this call up with the other side's calls.
   *
   * @return string
   *   The stop name and occurrence, which is what both the page and the
   *   database agree on for check 1.
   */
  public function nameKey(): string {
    return $this->stopName . '#' . $this->occurrence;
  }

  /**
   * Returns the best available stop identity for check 2.
   *
   * Prefers the ATCO code, which both bustimes.org and the imported GTFS
   * data carry, and falls back to the normalised name.
   *
   * @return string
   *   Stop identity, including the occurrence counter.
   */
  public function stopIdentity(): string {
    $stop = $this->atco !== NULL && $this->atco !== ''
      ? 'atco:' . $this->atco
      : 'name:' . StopName::normalise($this->stopName);

    return $stop . '#' . $this->occurrence;
  }

}
