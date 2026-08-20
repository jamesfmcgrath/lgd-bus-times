<?php

declare(strict_types=1);

namespace LocalgovBusData\TimetableVerify;

/**
 * One journey: an ordered list of calls.
 */
final class Journey {

  /**
   * Constructs a journey.
   *
   * @param string $label
   *   Human-readable identifier used in report output.
   * @param array<int, Call> $calls
   *   Calls in the order the journey makes them.
   */
  public function __construct(
    public readonly string $label,
    public readonly array $calls,
  ) {}

  /**
   * Indexes the journey's calls by stop name and occurrence.
   *
   * @return array<string, Call>
   *   Calls keyed by Call::nameKey().
   */
  public function byNameKey(): array {
    $indexed = [];
    foreach ($this->calls as $call) {
      $indexed[$call->nameKey()] = $call;
    }

    return $indexed;
  }

  /**
   * Indexes the journey's calls by stop identity.
   *
   * @return array<string, Call>
   *   Calls keyed by Call::stopIdentity().
   */
  public function byStopIdentity(): array {
    $indexed = [];
    foreach ($this->calls as $call) {
      $indexed[$call->stopIdentity()] = $call;
    }

    return $indexed;
  }

  /**
   * Returns the journey's first departure time.
   *
   * @return string
   *   Time as "HH:MM", or an empty string for a journey with no calls.
   */
  public function firstTime(): string {
    return $this->calls === [] ? '' : $this->calls[0]->time;
  }

  /**
   * Describes the journey by where and when it starts and ends.
   *
   * @return string
   *   A short "HH:MM Origin to HH:MM Destination" summary.
   */
  public function describe(): string {
    if ($this->calls === []) {
      return $this->label . ' (no calls)';
    }
    $first = $this->calls[0];
    $last = $this->calls[count($this->calls) - 1];

    return sprintf(
      '%s %s to %s %s',
      $first->time,
      $first->stopName,
      $last->time,
      $last->stopName,
    );
  }

}
