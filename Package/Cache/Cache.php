<?php
namespace Cache;

use \ArrayObject;
use \Exception;
use \Memcached;

/**
 * Wrapper for Memcached with local storage support
 *
 * @throws Exception
 * @package Cache
 * @see http://pecl.php.net/package/memcached
 * @see http://pecl.php.net/package/igbinary
 * @author Aleksey Korzun <al.ko@webfoundation.net>
 * @license MIT
 * @link http://www.webfoundation.net
 * @link http://www.alekseykorzun.com
 */
final class Cache
{
    /**
     * Dog-pile prevention delay in seconds, adjust if you have a constant miss
     * of cache after expiration because un-cached call takes more then specified
     * delay
     *
     * @var int
     */
    const DELAY = 30;

    /**
     * Default life time of all caches wrapper creates in seconds
     *
     * @var int
     */
    const DEFAULT_TTL = 3600;

    /**
     * Parent expiration padding (so internal cache stamp does not expire before
     * the actual cache) in seconds
     *
     * @var int
     */
    const EXTENDED_TTL = 300;

    /**
     * Instance of Memcached
     *
     * @var Memcached
     */
    private static $memcached;

    /**
     * Local storage
     *
     * @var array
     */
    protected static $storage = array();

    /**
     * Current setting for local storage
     *
     * @var bool
     */
    protected static $isStorageEnabled = true;

    /**
     * Indicates that current look-up will expire shortly (dog-pile)
     *
     * @var bool
     */
    protected static $isResourceExpired = false;

    /**
     * Marks current request as invalid (not-found, etc)
     *
     * @var bool
     */
    protected static $isResourceInvalid = false;

    /**
     * Cache activation switch
     *
     * @var bool
     */
    protected static $isActive = true;

    /**
     * Class constuctor, passes everything over to initializer
     *
     * @see Cache::initialize();
     *
     * @param mixed[] $servers a list of Memcached servers we will be using
     * @param string $prefix an optional prefix for this cache pool
     *
     * @return void
     */
    public function __constructor(array $servers, $prefix = null)
    {
        self::initialize($servers, $prefix);
    }

    /**
     * Class initializer, creates a new singleton instance of Memcached
     * with optimized configuration
     *
     * @throws Exception we want to bail if Memcached extension is not loaded or
     * if passed server list is invalid
     * @param mixed[] $servers a list of Memcached servers we will be using, each entry
     * in servers is supposed to be an array containing hostname, port, and
     * optionally, weight of the server
     *
     * Example:
     *
     *    $servers = array(
     *        array('mem1.domain.com', 11211, 33),
     *        array('mem2.domain.com', 11211, 67)
     *  );
     *
     * See: http://www.php.net/manual/en/memcached.addservers.php
     * @param string $prefix an optional prefix for this cache pool
     * @return void
     */
    public function __construct(array $servers, $prefix = null)
    {
        // Do not allow multiple instances
        if (self::$memcached) {
            throw new Exception('This class may not be initialized more than once');
        }

        // Make sure extension is available at the run-time
        if (!extension_loaded('memcached')) {
            throw new Exception('Memcached extension failed to load.');
        }

        // Validate passed server list
        self::validateServers($servers);

        // Create a new Memcached instance and set optimized options
        self::$memcached = new Memcached($prefix);

        // Use faster compression if available
        if (extension_loaded('igbinary')) {
            self::instance()->setOption(Memcached::OPT_SERIALIZER, Memcached::SERIALIZER_IGBINARY);
        }

        // If prefix is passed, use it
        if (!is_null($prefix)) {
            self::instance()->setOption(Memcached::OPT_PREFIX_KEY, (string) $prefix);
        }

        self::instance()->setOption(Memcached::OPT_DISTRIBUTION, Memcached::DISTRIBUTION_CONSISTENT);
        self::instance()->setOption(Memcached::OPT_LIBKETAMA_COMPATIBLE, true);
        self::instance()->setOption(Memcached::OPT_NO_BLOCK, true);
        self::instance()->setOption(Memcached::OPT_TCP_NODELAY, true);
        self::instance()->setOption(Memcached::OPT_COMPRESSION, true);
        self::instance()->setOption(Memcached::OPT_CONNECT_TIMEOUT, 2);

        // Since we are using persistent connections, make sure servers are not
        // reloaded
        if (!count(self::instance()->getServerList())) {
            self::instance()->addServers($servers);
        }
    }

    /**
     * Retrieve an active instance of Memcached, if instance was not created
     * attempt to create one.
     *
     * @throws Exception if this class was never setup via constructor
     * @see Cache::__constructor()
     * @return Memcached
     */
    protected static function instance()
    {
        if (!self::$memcached) {
            throw new Exception(
                'You must load and setup this class via constructor prior to using it.'
            );
        }

        return self::$memcached;
    }

    /**
     * Delete key(s) from cache and local storage
     *
     * @param string[]|string $keys array of keys to delete or a single key
     * @return bool returns false if it failed to delete any of the keys
     */
    public static function delete($keys)
    {
        // Convert keys to an array
        if (!is_array($keys)) {
            $keys = array($keys);
        }

        if ($keys) {
            foreach ($keys as $key) {
                // Attempt to remove data from Memcached pool
                if (!self::instance()->delete($key)) {
                    // If we were unable to remove it only care if resource was not stored
                    if (self::instance()->getResultCode() != Memcached::RES_NOTSTORED) {
                        return false;
                    }
                }

                // Also purge from our instance upon successful removal or if item
                // is no longer stored in our cache pool
                if (isset(self::$storage[$key])) {
                    unset(self::$storage[$key]);
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Add a new cached record using passed resource and key association
     *
     * @throws Exception if the key is over 250 bytes
     * @param string $key key to store passed resource under
     * @param mixed $resource resource you want to cache
     * @param int $ttl when should this key expire in seconds
     * @return bool
     */
    public static function set($key, $resource, $ttl = self::DEFAULT_TTL)
    {
        // If caching is turned off return false
        if (!self::isActive()) {
            return false;
        }

        // Properly format key
        $key = str_replace(' ', '', $key);

        // Make sure we are under the proper limit
        if (strlen(self::instance()->getOption(Memcached::OPT_PREFIX_KEY) . $key) > 250) {
            throw new Exception('The passed cache key is over 250 bytes');
        }

        // Save our data within cache pool
        if (self::instance()->set($key, self::wrap($resource, $ttl), $ttl)) {
            // Attempt to store data locally
            self::store($key, $resource);

            return true;
        }

        return false;
    }

    /**
     * Retrieve data based on provided key from cache pool, this method
     * will call either getArray or getSimple depending on amount of keys
     * requested.
     *
     * @param string[]|string $keys an array of keys or a single key to look-up from cache
     * @param mixed $resource where to store retrieved resource
     * @param bool $purge if you wish to remove key from pool after retrieving resource
     * associated with this you can pass this as true
     * @return bool returns true on a successful request, false on a failure or forced
     * expiration
     */
    public static function get($keys, &$resource, $purge = false)
    {
        // If caching is turned off return false
        if (!self::isActive()) {
            return false;
        }

        // Remove expired flag and make sure item is set to valid
        self::$isResourceExpired = self::$isResourceInvalid = false;

        // Prevent multi-get requests if array only contains a single key
        if (is_array($keys)) {
            if (count($keys) == 1) {
                $keys = array_shift($keys);
                $isDiverted = true;
            }
        }

        // Determine method of retrieval
        $resource = (is_array($keys)
                        ? self::getArray($keys)
                        : self::getSimple(str_replace(' ', '', $keys)));

        // If multi get by-pass is activated, convert result to an array
        if (isset($isDiverted)) {
            $resource = array($resource);
        }

        // If key is marked as expired (needs to be updated within this request)
        // or not found return false
        if (self::$isResourceExpired || self::$isResourceInvalid) {
            return false;
        }

        // If purge was passed, delete requested resource
        if ($purge) {
            self::delete($keys);
        }

        return true;
    }

    /**
     * Retrieve multiple resources from cache pool and/or local storage
     *
     * @param string[] $keys array of keys to look-up from cache
     * @return mixed[]|bool returns array of retrieved resources or false
     * if look up fails
     */
    protected static function getArray(array $keys)
    {
        // Initialize variables
        $results = $missing = array();

        // Check local storage first
        if (self::isStorageEnabled()) {
            for ($i = 0; $i < count($keys); ++$i) {
                $key = $keys[$i];

                // Attempt to retrieve record within storage
                if (isset(self::$storage[$key])) {
                    $results[$keys[$i]] = self::$storage[$key];
                    continue;
                }

                // Add non-instance hits to missing array for further look up(s)
                $missing[$key] = $keys[$i];
            }
        }

        // All results were retrieved within local storage
        if (empty($missing)) {
            return $results;
        }

        // Look up keys within cache pool
        $resources = self::instance()->getMulti(array_keys($missing));

        if (self::instance()->getResultCode() == Memcached::RES_SUCCESS) {
            foreach ($resource as $key => $resource) {
                $results[$missing[$key]] = self::unwrap($key, $resource);
            }
        }

        // If we got some of the results, let's return them
        if ($results) {
            return $results;
        }

        // Mark resource as invalid
        self::$isResourceInvalid = true;

        return false;
    }

    /**
     * Retrieve single resource from cache pool / local storage
     *
     * @param string $key key to look-up from cache
     * @return mixed|bool returns cached resource or false on failure
     */
    protected static function getSimple($key)
    {
        // Attempt to retrieve record within local storage
        if (self::isStorageEnabled()) {
            if (isset(self::$storage[$key])) {
                return self::$storage[$key];
            }
        }

        // Attempt to retrieve record within cache pool
        $resource = self::instance()->get($key);

        if (self::instance()->getResultCode() == Memcached::RES_SUCCESS) {
            return self::unwrap($key, $resource);
        }

        // Mark requested resource as invalid
        self::$isResourceInvalid = true;

        return false;
    }

    /**
     * Get requested data back into memory while setting a delayed cache entry
     * if data is expiring soon
     *
     * @see http://highscalability.com/strategy-break-memcache-dog-pile
     * @param string $key key that you are retrieving
     * @param mixed[] packed data that we got back from cache pool
     * @return mixed|bool returns cached resource or false if invalid data was
     * passed for unwrapping
     */
    protected static function unwrap($key, array $data)
    {
        // Enforce that data we get back was previously packed
        if (!isset($data['ttl']) || !isset($data['resource'])) {
            return false;
        }

        if ($data['ttl'] > 0) {
            if (time() >= $data['ttl']) {
                // Update TTL value with a delay
                $data['ttl'] = time() + self::DELAY;

                // Set the stale value back into cache for a short 'delay'
                // so no one else tries to write the same data
                if (self::instance()->set($key, $data, self::DELAY)) {
                    self::$isResourceExpired = true;
                }
            }
        }

        return self::store($key, $data['resource']);
    }

    /**
     * Wrap new cached resource into an array containing TTL stamp
     *
     * @see http://highscalability.com/strategy-break-memcache-dog-pile
     * @param mixed $resource resource that is getting cached
     * @param int $ttl internal extended expiration
     * @return mixed[] returns packed resource with TTL stamp to store in cache
     */
    protected static function wrap($resource, $ttl)
    {
        // The actual cache time must be padded in order to properly maintain internal
        // cache expiration system
        $ttl = time() + ((int) $ttl + self::EXTENDED_TTL);

        return array(
            'ttl' => $ttl,
            'resource' => $resource
        );
    }

    /**
     * Returns server IP passed key is mapped to
     *
     * @param string $key key to look up server by
     * @return string|bool returns server IP or false on a failure
     */
    public static function getServerByKey($key)
    {
        $server = self::instance()->getServerByKey($key);
        if ($server) {
            return $server['host'];
        }

        return false;
    }

    /**
     * Store data locally
     *
     * @param string $key key to save resource under
     * @param mixed $resource what you are storing in cache
     * @return mixed resource that we attempted to store
     */
    protected static function store($key, $resource)
    {
        if (self::isStorageEnabled()) {
            self::$storage[$key] = $resource;
        }

        return $resource;
    }

    /**
     * Checks if local storage is enabled
     *
     * @return bool returns true if local storage is enabled false otherwise
     */
    public static function isStorageEnabled()
    {
        return (bool) self::$isStorageEnabled;
    }

    /**
     * Deactivate caching
     *
     * @return void
     */
    public static function deactivate()
    {
        self::$isActive = false;
    }

    /**
     * Activate caching
     *
     * @return void
     */
    public static function activate()
    {
        self::$isActive = true;
    }

    /**
     * Check if caching is currently active
     *
     * @return bool returns true if caching is active otherwise false
     */
    public static function isActive()
    {
        return (bool) self::$isActive;
    }

    /**
     * Validate array of cache servers that should be loaded to Memcached extension
     *
     * @throws Exception if we detect something out of specification
     * @param mixed[] $servers a list of Memcached servers we will be using
     * @return void
     */
    public static function validateServers(array $servers)
    {
        if ($servers) {
            foreach ($servers as $key => $server) {
                // Check number of parameters associated with passed server
                if (!is_array($server) || count($server) < 2 || count($server) > 3) {
                    throw new Exception(
                        'Invalid server parameters found in passed server array on key ' . $key . ', please see'
                        . ' http://www.php.net/manual/en/memcached.addservers.php'
                    );
                }

                // Auto adjust weight of servers
                if (count($server) == 2) {
                    $server[] = 100;
                }

                list($ip, $port, $weight) = $server;

                // Check port and weight
                if (!is_numeric($port) || (!is_null($weight) && !is_numeric($weight))) {
                    throw new Exception(
                        'Invalid server port and/or weight found in passed server array on key ' . $key . ', please see'
                        . ' http://www.php.net/manual/en/memcached.addservers.php'
                    );
                }
            }
        }
    }
}

