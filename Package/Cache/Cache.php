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
 * @version 0.2
 * @license MIT
 * @link http://www.webfoundation.net
 * @link http://www.alekseykorzun.com
 */
class Cache
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
    protected $memcached;

    /**
     * Local storage
     *
     * @var array
     */
    protected $storage = array();

    /**
     * Current setting for local storage
     *
     * @var bool
     */
    protected $isStorageEnabled = true;

    /**
     * Indicates that current look-up will expire shortly (dog-pile)
     *
     * @var bool
     */
    protected $isResourceExpired = false;

    /**
     * Marks current request as invalid (not-found, etc)
     *
     * @var bool
     */
    protected $isResourceInvalid = false;

    /**
     * Cache activation switch
     *
     * @var bool
     */
    protected $isActive = true;

    /**
     * Class initializer, creates a new singleton instance of Memcached
     * with optimized configuration
     *
     * @throws Exception we want to bail if Memcached extension is not loaded or
     * if passed server list is invalid
     * @param string $pool create an instance of cache client with a specfic pool
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
     */
    public function __construct($pool = null, array $servers = null)
    {
        // Make sure extension is available at the run-time
        if (!extension_loaded('memcached')) {
            throw new Exception('Memcached extension failed to load.');
        }

        // Validate passed server list
        if (!is_null($servers)) {
            $this->validateServers($servers);
        }

        // Create a new Memcached instance and set optimized options
        $this->memcached = new Memcached($pool);

        // Use faster compression if available
        if (Memcached::HAVE_IGBINARY) {
            $this->instance()->setOption(Memcached::OPT_SERIALIZER, Memcached::SERIALIZER_IGBINARY);
        }

        $this->instance()->setOption(Memcached::OPT_DISTRIBUTION, Memcached::DISTRIBUTION_CONSISTENT);
        $this->instance()->setOption(Memcached::OPT_LIBKETAMA_COMPATIBLE, true);
        $this->instance()->setOption(Memcached::OPT_NO_BLOCK, true);
        $this->instance()->setOption(Memcached::OPT_TCP_NODELAY, true);
        $this->instance()->setOption(Memcached::OPT_COMPRESSION, true);
        $this->instance()->setOption(Memcached::OPT_CONNECT_TIMEOUT, 2);

        if (!is_null($servers)) {
            // Since we are using persistent connections, make sure servers are not
            // reloaded
            if (!count($this->instance()->getServerList())) {
                $this->instance()->addServers($servers);
            }
        }
    }

    /**
     * Delete key(s) from cache and local storage
     *
     * @param string[]|string $keys array of keys to delete or a single key
     * @return bool returns false if it failed to delete any of the keys
     */
    public function delete($keys)
    {
        // Convert keys to an array
        if (!is_array($keys)) {
            $keys = array($keys);
        }

        if ($keys) {
            foreach ($keys as $key) {
                // Attempt to remove data from Memcached pool
                if (!$this->instance()->delete($key)) {
                    // If we were unable to remove it only care if resource was not stored
                    if ($this->instance()->getResultCode() != Memcached::RES_NOTSTORED) {
                        return false;
                    }
                }

                // Also purge from our instance upon successful removal or if item
                // is no longer stored in our cache pool
                if ($this->isStored($key)) {
                    unset($this->storage[$key]);
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
    public function set($key, $resource, $ttl = self::DEFAULT_TTL)
    {
        // If caching is turned off return false
        if (!$this->isActive()) {
            return false;
        }

        // Properly format key
        $key = str_replace(' ', '', $key);

        // Make sure we are under the proper limit
        if (strlen($this->instance()->getOption(Memcached::OPT_PREFIX_KEY) . $key) > 250) {
            throw new Exception('The passed cache key is over 250 bytes');
        }

        // Save our data within cache pool
        if ($this->instance()->set($key, $this->wrap($resource, $ttl), $ttl)) {
            // Attempt to store data locally
            $this->store($key, $resource);

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
    public function get($keys, &$resource, $purge = false)
    {
        // If caching is turned off return false
        if (!$this->isActive()) {
            return false;
        }

        // Remove expired flag and make sure item is set to valid
        $this->isResourceExpired = $this->isResourceInvalid = false;

        // Prevent multi-get requests if array only contains a single key
        if (is_array($keys)) {
            if (count($keys) == 1) {
                $keys = array_shift($keys);
                $isDiverted = true;
            }
        }

        // Determine method of retrieval
        $resource = (is_array($keys)
                        ? $this->getArray($keys)
                        : $this->getSimple(str_replace(' ', '', $keys)));

        // If multi get by-pass is activated, convert result to an array
        if (isset($isDiverted)) {
            $resource = array($resource);
        }

        // If key is marked as expired (needs to be updated within this request)
        // or not found return false
        if ($this->isResourceExpired || $this->isResourceInvalid) {
            return false;
        }

        // If purge was passed, delete requested resource
        if ($purge) {
            $this->delete($keys);
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
    protected function getArray(array $keys)
    {
        // Initialize variables
        $results = array();

        // Check local storage first
        if ($this->isStorageEnabled()) {
            foreach ($keys as $pointer => $key) {
                if ($this->isStored($key)) {
                    $results[$key] = $this->storage[$key];
                    continue;
                }

                // Remove matches from look up
                unset($keys[$pointer]);
            }
        }

        // All results were retrieved within local storage
        if (empty($keys)) {
            return $results;
        }

        // Look up keys within cache pool
        $resources = $this->instance()->getMulti(array_values($keys));

        if ($this->instance()->getResultCode() == Memcached::RES_SUCCESS) {
            foreach ($resources as $key => $resource) {
                $results[$key] = $this->unwrap($key, $resource);
            }
        }

        // If we got some of the results, let's return them
        if ($results) {
            return $results;
        }

        // Mark resource as invalid
        $this->isResourceInvalid = true;

        return false;
    }

    /**
     * Retrieve single resource from cache pool / local storage
     *
     * @param string $key key to look-up from cache
     * @return mixed|bool returns cached resource or false on failure
     */
    protected function getSimple($key)
    {
        // Attempt to retrieve record within local storage
        if ($this->isStorageEnabled()) {
            if ($this->isStored($key)) {
                return $this->storage[$key];
            }
        }

        // Attempt to retrieve record within cache pool
        $resource = $this->instance()->get($key);

        if ($this->instance()->getResultCode() == Memcached::RES_SUCCESS) {
            return $this->unwrap($key, $resource);
        }

        // Mark requested resource as invalid
        $this->isResourceInvalid = true;

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
    protected function unwrap($key, array $data)
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
                if ($this->instance()->set($key, $data, self::DELAY)) {
                    $this->isResourceExpired = true;
                }
            }
        }

        return $this->store($key, $data['resource']);
    }

    /**
     * Wrap new cached resource into an array containing TTL stamp
     *
     * @see http://highscalability.com/strategy-break-memcache-dog-pile
     * @param mixed $resource resource that is getting cached
     * @param int $ttl internal extended expiration
     * @return mixed[] returns packed resource with TTL stamp to store in cache
     */
    protected function wrap($resource, $ttl)
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
     * Store data locally
     *
     * @param string $key key to save resource under
     * @param mixed $resource what you are storing in cache
     * @return mixed resource that we attempted to store
     */
    protected function store($key, $resource)
    {
        if ($this->isStorageEnabled()) {
            $this->storage[$key] = $resource;
        }

        return $resource;
    }

    /**
     * Checks if local storage is enabled
     *
     * @return bool returns true if local storage is enabled false otherwise
     */
    public function isStorageEnabled()
    {
        return (bool) $this->isStorageEnabled;
    }

    /**
     * Check if passed key is stored using local store
     *
     * @param string $key
     * @return bool
     */
    public function isStored($key) {
        return (bool) isset($this->storage[$key]);
    }

    /**
     * Deactivate caching
     */
    public function deactivate()
    {
        $this->isActive = false;
    }

    /**
     * Activate caching
     */
    public function activate()
    {
        $this->isActive = true;
    }

    /**
     * Check if caching is currently active
     *
     * @return bool returns true if caching is active otherwise false
     */
    public function isActive()
    {
        return (bool) $this->isActive;
    }

    /**
     * Validate array of cache servers that should be loaded to Memcached extension
     *
     * @throws Exception if we detect something out of specification
     * @param mixed[] $servers a list of Memcached servers we will be using
     */
    public function validateServers(array $servers)
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

    /**
     * Retrieve instance of Memcached client
     *
     * @return Memcached
     */
    public function instance() {
        return $this->memcached;
    }

    /**
     * Pass all method calls directly to instance of Memcached
     *
     * @param string $name method that was invoked
     * @param mixed[] $arguments arguments that were passed to invoked method
     *
     * @return mixed
     */
    public function __call($name, $arguments) {
        return $this->instance()->$name(array_shift($arguments));
    }
}
