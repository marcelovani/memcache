<?php

/**
 * @file
 * Contains \Drupal\memcache\DrupalMemcache.
 */

namespace Drupal\memcache;

/*
 * Core dmemcache functions required by:
 *   memcache.inc
 *   memcache.db.inc
 *   session-memcache.inc
 *   session-memcache.db.inc
 */
class DrupalMemcache {

  /**
   * @var array
   */
  static protected $memcacheStatistics = array();

  /**
   * @var null
   */
  static protected $variableChecked = NULL;

  /**
   * @var null
   */
  static protected $userAccessChecked = NULL;

  /**
   * @var null
   */
  static protected $extension = NULL;

  /**
   * @var array
   */
  static protected $memcacheCache = array();

  /**
   * @var array
   */
  static protected $memcacheServers = array();

  /**
   * @var array
   */
  static protected $memcacheBins = array();

  /**
   * @var null
   */
  static protected $memcachePersistent = NULL;

  /**
   * @var array
   */
  static protected $failedConnectionCache = array();

  /**
   *  Place an item into memcache
   *
   * @param $key The string with which you will retrieve this item later.
   * @param $value The item to be stored.
   * @param $exp Parameter expire is expiration time in seconds. If it's 0, the
   *   item never expires (but memcached server doesn't guarantee this item to be
   *   stored all the time, it could be deleted from the cache to make place for
   *   other items).
   * @param $bin The name of the Drupal subsystem that is making this call.
   *   Examples could be 'cache', 'alias', 'taxonomy term' etc. It is possible to
   *   map different $bin values to different memcache servers.
   * @param $mc Optionally pass in the memcache object.  Normally this value is
   *   determined automatically based on the bin the object is being stored to.
   *
   * @return bool
   */
  public static function set($key, $value, $exp = 0, $bin = 'cache', $mc = NULL) {
    $full_key = static::key($key, $bin);
    if (static::collectStats()) {
      static::$memcacheStatistics[] = array('set', $bin, $full_key, '');
    }
    if ($mc || ($mc = static::getObject($bin))) {
      if ($mc instanceof Memcached) {
        return $mc->set($full_key, $value, $exp);
      }
      else {
        return $mc->set($full_key, $value, $exp);
      }
    }

    return FALSE;
  }

  /**
   *  Adds an item into memcache.
   *
   * @param $key The string with which you will retrieve this item later.
   * @param $value The item to be stored.
   * @param $exp Parameter expire is expiration time in seconds. If it's 0, the
   *   item never expires (but memcached server doesn't guarantee this item to be
   *   stored all the time, it could be deleted from the cache to make place for
   *   other items).
   * @param $bin The name of the Drupal subsystem that is making this call.
   *   Examples could be 'cache', 'alias', 'taxonomy term' etc. It is possible
   *   to map different $bin values to different memcache servers.
   * @param $mc Optionally pass in the memcache object.  Normally this value is
   *   determined automatically based on the bin the object is being stored to.
   * @param $flag If using the older memcache PECL extension as opposed to the
   *   newer memcached PECL extension, the MEMCACHE_COMPRESSED flag can be set
   *   to use zlib to store a compressed copy of the item.  This flag option is
   *   completely ignored when using the newer memcached PECL extension.
   *
   * @return bool
   */
  public static function add($key, $value, $exp = 0, $bin = 'cache', $mc = NULL, $flag = FALSE) {
    $full_key = static::key($key, $bin);
    if (static::collectStats()) {
      static::$memcacheStatistics[] = array('add', $bin, $full_key, '');
    }
    if ($mc || ($mc = static::getObject($bin))) {
      if ($mc instanceof Memcached) {
        return $mc->add($full_key, $value, $exp);
      }
      else {
        return $mc->add($full_key, $value, $flag, $exp);
      }
    }
    return FALSE;
  }

  /**
   * Retrieve a value from the cache.
   *
   * @param $key The key with which the item was stored.
   * @param $bin The bin in which the item was stored.
   *
   * @return The item which was originally saved or FALSE
   */
  public static function get($key, $bin = 'cache', $mc = NULL) {
    $result = FALSE;
    $full_key = static::key($key, $bin);
    $statistics = array('get', $bin, $full_key);

    if ($mc || $mc = static::getObject($bin)) {
      $track_errors = ini_set('track_errors', '1');
      $php_errormsg = '';

      $result = @$mc->get($full_key);
      if (static::collectStats()) {
        $statistics[] = (bool) $result;
        static::$memcacheStatistics[] = $statistics;
      }

      if (!empty($php_errormsg)) {
        register_shutdown_function('watchdog', 'memcache', 'Exception caught in dmemcache_get: !msg', array('!msg' => $php_errormsg), WATCHDOG_WARNING);
        $php_errormsg = '';
      }
      ini_set('track_errors', $track_errors);
    }

    return $result;
  }

  /**
   * Retrieves multiple values from the cache.
   *
   * @param $keys The keys with which the items were stored.
   * @param $bin The bin in which the item was stored.
   *
   * @return The item which was originally saved or FALSE
   */
  public static function getMulti($keys, $bin = 'cache', $mc = NULL) {
    $full_keys = array();
    $statistics = array();
    $results = array();

    foreach ($keys as $key => $cid) {
      $full_key = static::key($cid, $bin);
      if (static::collectStats()) {
        $statistics[$full_key] = array('getMulti', $bin, $full_key);
      }
      $full_keys[$cid] = $full_key;
    }

    if ($mc || ($mc = static::getObject($bin))) {
      if ($mc instanceof Memcached) {
        $results = $mc->getMulti($full_keys);
      }
      elseif ($mc instanceof Memcache) {
        $track_errors = ini_set('track_errors', '1');
        $php_errormsg = '';

        $results = @$mc->get($full_keys);

        if (!empty($php_errormsg)) {
          register_shutdown_function('watchdog', 'memcache', 'Exception caught in dmemcache_get_multi: !msg', array('!msg' => $php_errormsg), WATCHDOG_WARNING);
          $php_errormsg = '';
        }
        ini_set('track_errors', $track_errors);
      }
    }

    if (static::collectStats()) {
      foreach ($statistics as $key => $values) {
        $values[] = isset($results[$key]) ? '1': '0';
        static::$memcacheStatistics[] = $values;
      }
    }

    // If $results is FALSE, convert it to an empty array.
    if (!$results) {
      $results = array();
    }

    // Convert the full keys back to the cid.
    $cid_results = array();
    $cid_lookup = array_flip($full_keys);
    foreach ($results as $key => $value) {
      $cid_results[$cid_lookup[$key]] = $value;
    }
    return $cid_results;
  }

  /**
   * Deletes an item from the cache.
   *
   * @param $key The key with which the item was stored.
   * @param $bin The bin in which the item was stored.
   *
   * @return Returns TRUE on success or FALSE on failure.
   */
  public static function delete($key, $bin = 'cache', $mc = NULL) {
    $full_key = static::key($key, $bin);
    if (static::collectStats()) {
      static::$memcacheStatistics[] = array('delete', $bin, $full_key, '');
    }
    if ($mc || ($mc = static::getObject($bin))) {
      return $mc->delete($full_key, 0);
    }
    return FALSE;
  }

  /**
   * Immediately invalidates all existing items. dmemcache_flush doesn't actually free any
   * resources, it only marks all the items as expired, so occupied memory will be overwritten by
   * new items.
   *
   * @param $bin The bin to flush. Note that this will flush all bins mapped to the same server
   *   as $bin. There is no way at this time to empty just one bin.
   *
   * @return Returns TRUE on success or FALSE on failure.
   */
  public static function flush($bin = 'cache', $mc = NULL) {
    if (static::collectStats()) {
      static::$memcacheStatistics[] = array('flush', $bin, '', '');
    }
    if ($mc || ($mc = static::getObject($bin))) {
      return memcache_flush($mc);
    }
  }

  /**
   * @todo
   *
   * @param string $stats_bin
   * @param string $stats_type
   * @param bool $aggregate
   *
   * @return mixed
   */
  public static function stats($stats_bin = 'cache', $stats_type = 'default', $aggregate = FALSE) {
    // @todo !!
    $memcache_bins = variable_get('memcache_bins', array('cache' => 'default'));
    // The stats_type can be over-loaded with an integer slab id, if doing a
    // cachedump.  We know we're doing a cachedump if $slab is non-zero.
    $slab = (int)$stats_type;

    foreach ($memcache_bins as $bin => $target) {
      if ($stats_bin == $bin) {
        if ($mc = static::getObject($bin)) {
          if ($mc instanceof \Memcached) {
            $stats[$bin] = $mc->getStats();
          }
          // The PHP Memcache extension 3.x version throws an error if the stats
          // type is NULL or not in {reset, malloc, slabs, cachedump, items,
          // sizes}. If $stats_type is 'default', then no parameter should be
          // passed to the Memcache memcache_get_extended_stats() function.
          else if ($mc instanceof \Memcache) {
            if ($stats_type == 'default' || $stats_type == '') {
              $stats[$bin] = $mc->getExtendedStats();
            }
            // If $slab isn't zero, then we are dumping the contents of a
            // specific cache slab.
            else if (!empty($slab))  {
              $stats[$bin] = $mc->getStats('cachedump', $slab);
            }
            else {
              $stats[$bin] = $mc->getExtendedStats($stats_type);
            }
          }
        }
      }
    }

    // Optionally calculate a sum-total for all servers in the current bin.
    if ($aggregate) {
      // Some variables don't logically aggregate.
      $no_aggregate = array('pid', 'time', 'version', 'pointer_size', 'accepting_conns', 'listen_disabled_num');
      foreach($stats as $bin => $servers) {
        if (is_array($servers)) {
          foreach ($servers as $server) {
            if (is_array($server)) {
              foreach ($server as $key => $value) {
                if (!in_array($key, $no_aggregate)) {
                  if (isset($stats[$bin]['total'][$key])) {
                    $stats[$bin]['total'][$key] += $value;
                  }
                  else {
                    $stats[$bin]['total'][$key] = $value;
                  }
                }
              }
            }
          }
        }
      }
    }

    return $stats;
  }

  /**
   * @todo
   *
   * @param $key
   * @param string $bin
   *
   * @return string
   */
  public static function key($key, $bin = 'cache') {
    $prefix = '';
    if ($prefix = variable_get('memcache_key_prefix', '')) {
      $prefix .= '-';
    }
    // When simpletest is running, emulate the simpletest database prefix here
    // to avoid the child site setting cache entries in the parent site.
    if (isset($GLOBALS['drupal_test_info']['test_run_id'])) {
      $prefix .= $GLOBALS['drupal_test_info']['test_run_id'];
    }
    $full_key = urlencode($prefix . $bin . '-' . $key);

    // Memcache only supports key lengths up to 250 bytes.  If we have generated
    // a longer key, we shrink it to an acceptible length with a configurable
    // hashing algorithm. Sha1 was selected as the default as it performs
    // quickly with minimal collisions.
    if (strlen($full_key) > 250) {
      $full_key = urlencode(hash(variable_get('memcache_key_hash_algorithm', 'sha1'), $prefix . $bin . '-' . $key));
    }

    return $full_key;
  }

  /**
   * Checks whether memcache stats need to be collected.
   */
  public static function collectStats() {
    global $user;

    // Confirm DRUPAL_BOOTSTRAP_VARIABLES has been reached. We don't use
    // drupal_get_bootstrap_phase() as it's buggy. We can use variable_get()
    // here because _drupal_bootstrap_variables() includes module.inc
    // immediately after it calls variable_initialize().
    if (!isset(static::$variableChecked) && function_exists('module_list')) {
      static::$variableChecked = variable_get('show_memcache_statistics', FALSE);
    }
    // If statistics are enabled we need to check user access.
    if (!empty(static::$variableChecked) && !isset(static::$userAccessChecked) && !empty($user) && function_exists('user_access')) {
      // Statistics are enabled and the $user object has been populated, so check
      // that the user has access to view them.
      static::$userAccessChecked = user_access('access memcache statistics');
    }

    // Return whether or not statistics are enabled and the user can access them.
    return (!isset(static::$variableChecked) || static::$variableChecked) && (!isset(static::$userAccessChecked) || static::$userAccessChecked);
  }

  /**
   * Returns a Memcache object based on the bin requested. Note that there is
   * nothing preventing developers from calling this function directly to get the
   * Memcache object. Do this if you need functionality not provided by this API
   * or if you need to use legacy code. Otherwise, use the dmemcache (get, set,
   * delete, flush) API functions provided here.
   *
   * @param $bin The bin which is to be used.
   *
   * @param $flush Rebuild the bin/server/cache mapping.
   *
   * @return an Memcache object or FALSE.
   */
  public static function getObject($bin = NULL, $flush = FALSE) {
    if (!isset(static::$extension)) {
      // If an extension is specified in settings.php, use that when available.
      $preferred = variable_get('memcache_extension', NULL);
      if (isset($preferred) && class_exists($preferred)) {
        $extension = $preferred;
      }
      // If no extension is set, default to Memcache.
      // The Memcached extension has some features that the older extension lacks
      // but also an unfixed bug that affects cache clears.
      // @see http://pecl.php.net/bugs/bug.php?id=16829
      elseif (class_exists('Memcache')) {
        static::$extension = 'Memcache';
      }
      elseif (class_exists('Memcached')) {
        static::$extension = 'Memcached';
      }

      // Indicate whether to connect to memcache using a persistent connection.
      // Note: this only affects the Memcache PECL extension, and does not
      // affect the Memcached PECL extension.  For a detailed explanation see:
      //  http://drupal.org/node/822316#comment-4427676
      if (!isset(static::$memcachePersistent)) {
        static::$memcachePersistent = variable_get('memcache_persistent', FALSE);
      }
    }

    if ($flush) {
      foreach (static::$memcacheCache as $cluster) {
        memcache_close($cluster);
      }
      static::$memcacheCache = array();
    }

    if (empty(static::$memcacheCache) || empty(static::$memcacheCache[$bin])) {
      // $memcache_servers and $memcache_bins originate from settings.php.
      // $memcache_servers_custom and $memcache_bins_custom get set by
      // memcache.module. They are then merged into $memcache_servers and
      // $memcache_bins, which are statically cached for performance.
      if (empty(static::$memcacheServers)) {
        // Values from settings.php
        static::$memcacheServers = variable_get('memcache_servers', array('127.0.0.1:11211' => 'default'));
        static::$memcacheBins    = variable_get('memcache_bins', array('cache' => 'default'));
      }

      // If there is no cluster for this bin in $memcache_bins, cluster is 'default'.
      $cluster = empty(static::$memcacheBins[$bin]) ? 'default' : static::$memcacheBins[$bin];

      // If this bin isn't in our $memcache_bins configuration array, and the
      // 'default' cluster is already initialized, map the bin to 'cache' because
      // we always map the 'cache' bin to the 'default' cluster.
      if (empty(static::$memcacheBins[$bin]) && !empty(static::$memcacheCache['cache'])) {
        static::$memcacheCache[$bin] = &static::$memcacheCache['cache'];
      }
      else {
        // Create a new Memcache object. Each cluster gets its own Memcache object.
        if (static::$extension == 'Memcached') {
          $memcache = new \Memcached();
          $default_opts = array(
            \Memcached::OPT_COMPRESSION => FALSE,
            \Memcached::OPT_DISTRIBUTION => \Memcached::DISTRIBUTION_CONSISTENT,
          );
          foreach ($default_opts as $key => $value) {
            $memcache->setOption($key, $value);
          }
          // See README.txt for setting custom Memcache options when using the
          // memcached PECL extension.
          $memconf = variable_get('memcache_options', array());
          foreach ($memconf as $key => $value) {
            $memcache->setOption($key, $value);
          }
        }
        elseif (static::$extension == 'Memcache') {
          $memcache = new \Memcache();
        }
        else {
          drupal_set_message(t('You must enable the PECL memcached or memcache extension to use memcache.inc.'), 'error');
          return;
        }
        // A variable to track whether we've connected to the first server.
        $init = FALSE;

        // Link all the servers to this cluster.
        foreach (static::$memcacheServers as $s => $c) {
          if ($c == $cluster && !isset($failed_connection_cache[$s])) {
            list($host, $port) = explode(':', $s);

            // Using the Memcache PECL extension.
            if ($memcache instanceof \Memcache) {
              // Support unix sockets in the format 'unix:///path/to/socket'.
              if ($host == 'unix') {
                // When using unix sockets with Memcache use the full path for $host.
                $host = $s;
                // Port is always 0 for unix sockets.
                $port = 0;
              }
              // When using the PECL memcache extension, we must use ->(p)connect
              // for the first connection.
              if (!$init) {
                $track_errors = ini_set('track_errors', '1');
                $php_errormsg = '';

                if (static::$memcachePersistent && @$memcache->pconnect($host, $port)) {
                  $init = TRUE;
                }
                elseif (!static::$memcachePersistent && @$memcache->connect($host, $port)) {
                  $init = TRUE;
                }

                if (!empty($php_errormsg)) {
                  register_shutdown_function('watchdog', 'memcache', 'Exception caught in dmemcache_object: !msg', array('!msg' => $php_errormsg), WATCHDOG_WARNING);
                  $php_errormsg = '';
                }
                ini_set('track_errors', $track_errors);
              }
              else {
                $memcache->addServer($host, $port, static::$memcachePersistent);
              }
            }
            else {
              // Support unix sockets in the format 'unix:///path/to/socket'.
              if ($host == 'unix') {
                // Memcached expects just the path to the socket without the protocol
                $host = substr($s, 7);
                // Port is always 0 for unix sockets.
                $port = 0;
              }
              if ($memcache->addServer($host, $port) && !$init) {
                $init = TRUE;
              }
            }

            if (!$init) {
              // We can't use watchdog because this happens in a bootstrap phase
              // where watchdog is non-functional. Register a shutdown handler
              // instead so it gets recorded at the end of page load.
              register_shutdown_function('watchdog', 'memcache', 'Failed to connect to memcache server: !server', array('!server' => $s), WATCHDOG_ERROR);
              static::$failedConnectionCache[$s] = FALSE;
            }
          }
        }

        if ($init) {
          // Map the current bin with the new Memcache object.
          static::$memcacheCache[$bin] = $memcache;

          // Now that all the servers have been mapped to this cluster, look for
          // other bins that belong to the cluster and map them too.
          foreach (static::$memcacheBins as $b => $c) {
            if ($c == $cluster && $b != $bin) {
              // Map this bin and cluster by reference.
              static::$memcacheCache[$b] = &static::$memcacheCache[$bin];
            }
          }
        }
      }
    }

    return empty(static::$memcacheCache[$bin]) ? FALSE : static::$memcacheCache[$bin];
  }

}
