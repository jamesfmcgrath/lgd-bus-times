<?php

declare(strict_types=1);

namespace LocalgovBusData\TimetableVerify;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

/**
 * Fetches HTML, with an on-disk cache for third-party pages.
 *
 * Bustimes.org is someone else's server. Every URL is requested at most once
 * per run and the response is cached to disk, so re-running the tool while
 * developing it costs them nothing. A failed fetch is recorded and not
 * retried.
 */
final class Fetcher {

  /**
   * Identifies this tool to bustimes.org.
   */
  private const USER_AGENT = 'localgov-bus-data timetable-verify/1.0 (Cumberland Council dev tooling; +https://git.drupalcode.org/project/localgov_bus_data)';

  /**
   * URLs already attempted this run, keyed by URL.
   *
   * @var array<string, string|null>
   */
  private array $attempted = [];

  /**
   * Errors recorded against URLs, keyed by URL.
   *
   * @var array<string, string>
   */
  private array $errors = [];

  public function __construct(
    private readonly ClientInterface $client,
    private readonly string $cacheDirectory,
    private readonly bool $refresh = FALSE,
  ) {}

  /**
   * Fetches a page on the site under test.
   *
   * Local pages are never cached: the whole point is to read what the site
   * renders right now.
   *
   * @param string $url
   *   Absolute URL on the site under test.
   *
   * @return string
   *   Response body.
   *
   * @throws \RuntimeException
   *   When the request fails or the status is not 200.
   */
  public function fetchLocal(string $url): string {
    $response = $this->client->request('GET', $url, [
      RequestOptions::HTTP_ERRORS => FALSE,
      RequestOptions::VERIFY => FALSE,
      RequestOptions::TIMEOUT => 60,
      RequestOptions::HEADERS => ['User-Agent' => self::USER_AGENT],
    ]);

    $status = $response->getStatusCode();
    if ($status !== 200) {
      throw new \RuntimeException(sprintf('HTTP %d for %s', $status, $url));
    }

    return (string) $response->getBody();
  }

  /**
   * Fetches a third-party page, using the disk cache when possible.
   *
   * @param string $url
   *   Absolute URL.
   *
   * @return string|null
   *   Response body, or NULL when the fetch failed. The failure reason is
   *   available from error().
   */
  public function fetchCached(string $url): ?string {
    if (array_key_exists($url, $this->attempted)) {
      return $this->attempted[$url];
    }

    $path = $this->cachePath($url);
    if (!$this->refresh && is_readable($path)) {
      $body = file_get_contents($path);
      if ($body !== FALSE) {
        return $this->attempted[$url] = $body;
      }
    }

    try {
      $response = $this->client->request('GET', $url, [
        RequestOptions::HTTP_ERRORS => FALSE,
        RequestOptions::TIMEOUT => 60,
        RequestOptions::ALLOW_REDIRECTS => TRUE,
        RequestOptions::HEADERS => [
          'User-Agent' => self::USER_AGENT,
          'Accept' => 'text/html',
        ],
      ]);
      $status = $response->getStatusCode();
      if ($status !== 200) {
        $this->errors[$url] = sprintf('HTTP %d', $status);
        return $this->attempted[$url] = NULL;
      }
      $body = (string) $response->getBody();
    }
    catch (\Throwable $e) {
      $this->errors[$url] = $e->getMessage();
      return $this->attempted[$url] = NULL;
    }

    if (!is_dir($this->cacheDirectory)) {
      mkdir($this->cacheDirectory, 0777, TRUE);
    }
    file_put_contents($path, $body);

    return $this->attempted[$url] = $body;
  }

  /**
   * Returns the reason a URL failed, if it did.
   *
   * @param string $url
   *   Absolute URL.
   *
   * @return string|null
   *   Failure reason, or NULL when the URL did not fail.
   */
  public function error(string $url): ?string {
    return $this->errors[$url] ?? NULL;
  }

  /**
   * Reports whether a URL was served from the cache rather than refetched.
   *
   * @param string $url
   *   Absolute URL.
   *
   * @return bool
   *   TRUE when a cache file for this URL exists.
   */
  public function isCached(string $url): bool {
    return is_readable($this->cachePath($url));
  }

  /**
   * Builds the cache file path for a URL.
   *
   * @param string $url
   *   Absolute URL.
   *
   * @return string
   *   Absolute path to the cache file.
   */
  private function cachePath(string $url): string {
    return $this->cacheDirectory . '/' . sha1($url) . '.html';
  }

}
