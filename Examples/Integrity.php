<?php
/**
 * Example of Memcache operations using PHP 5 wrapper, run this script twice
 * to complete all of the tests.
 *
 * @package Cache
 * @subpackage Cache\Examples
 * @version 0.2
 * @license MIT
 * @author Aleksey Korzun <al.ko@webfoundation.net>
 * @link https://github.com/AlekseyKorzun/Memcached-Wrapper-PHP-5
 * @link http://www.alekseykorzun.com
 */

/**
 * You must run `composer install` in order to generate autoloader for this example
 */
//require __DIR__ . '/../vendor/autoload.php';

use \stdClass;
use Cache\Cache;

// Key to use for integrity tests
define('KEY', 'key');

// List of pools
$servers = array(
    array('127.0.0.1', 11211, 10),
    array('127.0.0.1', 11211, 20)
);

try {
    $cache = new Cache('pool', $servers);

    // Attempt to retrieve previously cached result from pool (run this twice)
    if (!$cache->get(KEY, $resource)) {
        print "Key was not found in our cache pool!\n";

        // Create test resource
        $resource = new stdClass();
        $resource->name = 'Test';

        // If nothing was found during our cache look up, save resource to cache pool
        if ($cache->set(KEY, $resource)) {
            print "Stored resource in cache pool!\n";
        } else {
            print "Failed to store resource in cache pool!\n";
        }
    } else {
        print "Key was found in our cache pool!\n";

        // Let's get fancy
        $server = $cache->getServerByKey(KEY);
        if ($server) {
            print "Server key is mapped to: " . $server . "\n";
        }

        // We retrieved resource from cache, let's make sure delete works
        if ($cache->delete(KEY)) {
            print "Deleted resource from cache!\n";
        } else {
            print "Failed to delete resource from cache!\n";
        }
    }

    print "Resource: \n";

    print_r($resource);

} catch (Exception $exception) {
    print $exception->getMessage();
}


