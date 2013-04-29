<?php
/**
 * Proof of concept of dog piling protection, put contents of this package
 * on a webserver and hit the URI of this file once in your browser to warm
 * up the cache.
 *
 * Use GET parameter 'client' to switch between clients. Either 'wrapper' or 'memcached' are
 * supported.
 *
 * Follow with Apache Bench with something like -n 2000 -c 100 in 5 minutes and watch your error
 * log for 'database' hits each implementation let's through.
 *
 * @package Cache
 * @subpackage Cache\Examples
 * @version 0.2
 * @license MIT
 * @author Aleksey Korzun <al.ko@webfoundation.net>
 * @link https://github.com/AlekseyKorzun/memcached-wrapper-php
 * @link http://www.alekseykorzun.com
 */

/**
 * You must run `composer install` in order to generate autoloader for this example
 */
require __DIR__ . '/../vendor/autoload.php';

use \Memcached;
use \Memcached\Wrapper;

try {
    // Server pool
    $servers = array(
        array('127.0.0.1', 11211)
    );

    // Set TTL for new keys
    $ttl = time() + (Wrapper::EXTENDED_TTL * 2);

    // Set client
    $client = (isset($_GET['client']) && ($_GET['client'] == 'wrapper')) ? 'wrapper' : 'memcached';

    if ($client == 'wrapper') {
        // Initialize our Memcached wrapper and simulate caching of a large SQL query
        $wrapper = new Wrapper('wrapper', $servers);
        $wrapper->toggleStorage();

        $result = null;
        if (!$wrapper->get('wrapper', $result)) {
            error_log('Wrapper database hit: ' . date('Y-m-d H:i:s'));

            // Some query that takes 5 seconds
            sleep(5);

            $wrapper->set('wrapper', 'value', $ttl);
        }
    } else {
        // Initialize regular Memcached instance and simulate caching of a large SQL query
        $memcached = new Memcached('memcached');
        $memcached->addServers($servers);

        if (!$memcached->get('memcached')) {
            error_log('Memcached database hit: ' . date('Y-m-d H:i:s'));

            // Some query that takes 5 seconds
            sleep(5);

            $memcached->set('memcached', 'value', $ttl);
        }
    }

} catch (Exception $exception) {
    header('HTTP/1.1 500 Internal Server Error');
    print $exception->getMessage();
    exit(1);
}


