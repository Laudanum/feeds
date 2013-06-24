<?php

/**
 * @file
 * Contains \Drupal\feeds\HTTPRequest.
 *
 * @todo Remove this.
 */

namespace Drupal\feeds;

/**
 * PCRE for finding the link tags in html.
 */
const HTTP_REQUEST_PCRE_LINK_TAG = '/<link((?:[\x09\x0A\x0B\x0C\x0D\x20]+[^\x09\x0A\x0B\x0C\x0D\x20\x2F\x3E][^\x09\x0A\x0B\x0C\x0D\x20\x2F\x3D\x3E]*(?:[\x09\x0A\x0B\x0C\x0D\x20]*=[\x09\x0A\x0B\x0C\x0D\x20]*(?:"(?:[^"]*)"|\'(?:[^\']*)\'|(?:[^\x09\x0A\x0B\x0C\x0D\x20\x22\x27\x3E][^\x09\x0A\x0B\x0C\x0D\x20\x3E]*)?))?)*)[\x09\x0A\x0B\x0C\x0D\x20]*(>(.*)<\/link>|(\/)?>)/si';

/**
 * PCRE for matching all the attributes in a tag.
 */
const HTTP_REQUEST_PCRE_TAG_ATTRIBUTES = '/[\x09\x0A\x0B\x0C\x0D\x20]+([^\x09\x0A\x0B\x0C\x0D\x20\x2F\x3E][^\x09\x0A\x0B\x0C\x0D\x20\x2F\x3D\x3E]*)(?:[\x09\x0A\x0B\x0C\x0D\x20]*=[\x09\x0A\x0B\x0C\x0D\x20]*(?:"([^"]*)"|\'([^\']*)\'|([^\x09\x0A\x0B\x0C\x0D\x20\x22\x27\x3E][^\x09\x0A\x0B\x0C\x0D\x20\x3E]*)?))?/';

/**
 * Support caching, HTTP Basic Authentication, detection of RSS/Atom feeds,
 * redirects.
 */
class HTTPRequest {

  /**
   * Discovers RSS or atom feeds at the given URL.
   *
   * If document in given URL is an HTML document, function attempts to discover
   * RSS or Atom feeds.
   *
   * @param string $url
   *   The url of the feed to retrieve.
   * @param array $settings
   *   An optional array of settings. Valid options are: accept_invalid_cert.
   *
   * @return bool|string
   *   The discovered feed, or FALSE if the URL is not reachable or there was an
   *   error.
   */
  public static function getCommonSyndication($url, $settings = NULL) {

    $accept_invalid_cert = isset($settings['accept_invalid_cert']) ? $settings['accept_invalid_cert'] : FALSE;
    $download = static::get($url, NULL, NULL, $accept_invalid_cert);

    // Cannot get the feed, return.
    // static::get() always returns 200 even if its 304.
    if ($download->code != 200) {
      return FALSE;
    }

    // Drop the data into a seperate variable so all manipulations of the html
    // will not effect the actual object that exists in the static cache.
    // @see http_request_get.
    $downloaded_string = $download->data;
    // If this happens to be a feed then just return the url.
    if (static::isFeed($download->headers['content-type'], $downloaded_string)) {
      return $url;
    }

    $discovered_feeds = static::findFeeds($downloaded_string);
    foreach ($discovered_feeds as $feed_url) {
      $absolute = static::createAbsoluteURL($feed_url, $url);
      if (!empty($absolute)) {
        // @TODO: something more intelligent?
        return $absolute;
      }
    }
  }

  /**
   * Gets the content from the given URL.
   *
   * @param string $url
   *   A valid URL (not only web URLs).
   * @param string $username
   *   If the URL uses authentication, supply the username.
   * @param string $password
   *   If the URL uses authentication, supply the password.
   * @param bool $accept_invalid_cert
   *   Whether to accept invalid certificates.
   * @param integer $timeout
   *   Timeout in seconds to wait for an HTTP get request to finish.
   *
   * @return stdClass
   *   An object that describes the data downloaded from $url.
   */
  public static function get($url, $username = NULL, $password = NULL, $accept_invalid_cert = FALSE, $timeout = NULL) {
    // Intra-pagedownload cache, avoid to download the same content twice within
    // one page download (it's possible, compatible and parse calls).
    static $download_cache = array();
    if (isset($download_cache[$url])) {
      return $download_cache[$url];
    }

    // Determine request timeout.
    $request_timeout = !empty($timeout) ? $timeout : variable_get('http_request_timeout', 30);

    if (!$username && valid_url($url, TRUE)) {
      // Handle password protected feeds.
      $url_parts = parse_url($url);
      if (!empty($url_parts['user'])) {
        $password = $url_parts['pass'];
        $username = $url_parts['user'];
      }
    }

    // Support the 'feed' and 'webcal' schemes by converting them into 'http'.
    $url = strtr($url, array('feed://' => 'http://', 'webcal://' => 'http://'));

    $request = \Drupal::httpClient()->get($url);

    // Only download and parse data if really needs refresh.
    // Based on "Last-Modified" and "If-Modified-Since".
    if ($cache = cache()->get('feeds_http_download_' . md5($url))) {
      $last_result = $cache->data;
      $last_headers = array_change_key_case($last_result->headers);

      if (!empty($last_headers['etag'])) {
        $request->addHeader('If-None-Match', $last_headers['etag']);

      }
      if (!empty($last_headers['last-modified'])) {
        $request->addHeader('If-Modified-Since', $last_headers['last-modified']);
      }
      if (!empty($username)) {
        $request->addHeader('Authorization', 'Basic ' . base64_encode("$username:$password"));
      }
    }

    $result = new \stdClass();

    try {
      $response = $request->send();
      $result->data = $response->getBody(TRUE);
      $result->headers = $response->getHeaders()->toArray();
      $result->code = $response->getStatusCode();
    }
    catch (BadResponseException $e) {
      $response = $e->getResponse();
      watchdog('feeds', 'The feed %url seems to be broken due to "%error".', array('%url' => $url, '%error' => $response->getStatusCode() . ' ' . $response->getReasonPhrase()), WATCHDOG_WARNING);
      drupal_set_message(t('The feed %url seems to be broken because of error "%error".', array('%url' => $url, '%error' => $response->getStatusCode() . ' ' . $response->getReasonPhrase())));
      return FALSE;
    }
    catch (RequestException $e) {
      watchdog('feeds', 'The feed %url seems to be broken due to "%error".', array('%url' => $url, '%error' => $e->getMessage()), WATCHDOG_WARNING);
      drupal_set_message(t('The feed %url seems to be broken because of error "%error".', array('%url' => $url, '%error' => $e->getMessage())));
      return FALSE;
    }

    // In case of 304 Not Modified try to return cached data.
    if ($result->code == 304) {

      if (isset($last_result)) {
        $last_result->from_cache = TRUE;
        return $last_result;
      }
      else {
        // It's a tragedy, this file must exist and contain good data.
        // In this case, clear cache and repeat.
        cache()->delete('feeds_http_download_' . md5($url));
        return static::get($url, $username, $password, $accept_invalid_cert, $request_timeout);
      }
    }

    // Set caches.
    cache()->set('feeds_http_download_' . md5($url), $result);
    $download_cache[$url] = $result;

    return $result;
  }

  /**
   * Returns if the provided $content_type is a feed.
   *
   * @param string $content_type
   *   The Content-Type header.
   *
   * @param string $data
   *   The actual data from the http request.
   *
   * @return bool
   *   Returns TRUE if this is a parsable feed.
   */
  public static function isFeed($content_type, $data) {
    $pos = strpos($content_type, ';');
    if ($pos !== FALSE) {
      $content_type = substr($content_type, 0, $pos);
    }
    $content_type = strtolower($content_type);
    if (strpos($content_type, 'xml') !== FALSE) {
      return TRUE;
    }

    // @TODO: Sometimes the content-type can be text/html but still be a valid
    // feed.
    return FALSE;
  }

  /**
   * Finds potential feed tags in the HTML document.
   *
   * @param string $html
   *   The html string to search.
   *
   * @return array
   *   An array of href to feeds.
   */
  public static function findFeeds($html) {
    $matches = array();
    preg_match_all(HTTP_REQUEST_PCRE_LINK_TAG, $html, $matches);
    $links = $matches[1];
    $valid_links = array();

    // Build up all the links information.
    foreach ($links as $link_tag) {
      $attributes = array();
      $candidate = array();

      preg_match_all(HTTP_REQUEST_PCRE_TAG_ATTRIBUTES, $link_tag, $attributes, PREG_SET_ORDER);
      foreach ($attributes as $attribute) {
        // Find the key value pairs, attribute[1] is key and attribute[2] is the
        // value.
        if (!empty($attribute[1]) && !empty($attribute[2])) {
          $candidate[drupal_strtolower($attribute[1])] = drupal_strtolower(decode_entities($attribute[2]));
        }
      }

      // Examine candidate to see if it s a feed.
      // @TODO: could/should use http_request_is_feed ??
      if (isset($candidate['rel']) && $candidate['rel'] == 'alternate') {
        if (isset($candidate['href']) && isset($candidate['type']) && strpos($candidate['type'], 'xml') !== FALSE) {
          // All tests pass, its a valid candidate.
          $valid_links[] = $candidate['href'];
        }
      }
    }

    return $valid_links;
  }

  /**
   * Create an absolute url.
   *
   * @param string $url
   *   The href to transform.
   * @param string $base_url
   *   The url to be used as the base for a relative $url.
   *
   * @return string
   *   An absolute url
   */
  public static function createAbsoluteURL($url, $base_url) {
    $url = trim($url);
    if (valid_url($url, TRUE)) {
      // Valid absolute url already.
      return $url;
    }

    // Turn relative url into absolute.
    if (valid_url($url, FALSE)) {
      // Produces variables $scheme, $host, $user, $pass, $path, $query and
      // $fragment.
      $parsed_url = parse_url($base_url);

      $path = dirname($parsed_url['path']);

      // Adding to the existing path.
      if ($url{0} == '/') {
        $cparts = array_filter(explode("/", $url));
      }
      else {
        // Backtracking from the existing path.
        $cparts = array_merge(array_filter(explode("/", $path)), array_filter(explode("/", $url)));
        foreach ($cparts as $i => $part) {
          if ($part == '.') {
            $cparts[$i] = NULL;
          }
          if ($part == '..') {
            $cparts[$i - 1] = NULL;
            $cparts[$i] = NULL;
          }
        }
        $cparts = array_filter($cparts);
      }
      $path = implode("/", $cparts);

      // Build the prefix to the path.
      $absolute_url = '';
      if (isset($parsed_url['scheme'])) {
        $absolute_url = $parsed_url['scheme'] . '://';
      }

      if (isset($parsed_url['user'])) {
        $absolute_url .= $parsed_url['user'];
        if (isset($pass)) {
          $absolute_url .= ':' . $parsed_url['pass'];
        }
        $absolute_url .= '@';
      }
      if (isset($parsed_url['host'])) {
        $absolute_url .= $parsed_url['host'] . '/';
      }

      $absolute_url .= $path;

      if (valid_url($absolute_url, TRUE)) {
        return $absolute_url;
      }
    }
    return FALSE;
  }

}