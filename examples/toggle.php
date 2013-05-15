<?php
/**
 * Example of how to use the library with operations that do not support
 * race condition protection
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

use \stdClass;
use \Memcached;
use \Memcached\Wrapper;

try {
    // Server pool
    $servers = array(
        array('127.0.0.1', 11211)
    );

    $wrapper = new Wrapper('wrapper', $servers);

    // Turn off local storage, compression and race condition protection because
    // append/prepend and increment/decrement operations do not support it
    $wrapper->toggleAll();

    // Append operation
    $wrapper->set('key', '1');
    $wrapper->append('key', '00');

    $result = null;
    $wrapper->get('key', $result);

    // Result is now 100
    print "Should be 100: {$result} \n";

     // Increment operation
    $wrapper->increment('key', 50);

    $result = null;
    $wrapper->get('key', $result);

    // Result is now 150
    print "Should be 150: {$result} \n";

    // Turn on local storage, compression and race condition protection for regular storage
    $wrapper->toggleAll();

    $wrapper->set('key', new stdClass());

    $result = null;
    $wrapper->get('key', $result);

    $result = get_class($result);

    // Result is now instance of new stdClass
    print "Should be stdClass: {$result} \n";

} catch (Exception $exception) {
    print $exception->getMessage();
}