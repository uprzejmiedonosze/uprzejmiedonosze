<?php namespace cache;

$cache = new \Memcache;
$cache->connect('localhost', 11211);

function get(string $key) {
    global $cache;
    return $cache->get("%HOST%-$key");
}

function set(string $key, $value, $flag=0, $expire=24*60*60): void {
    global $cache;
    $cache->set("%HOST%-$key", $value, $flag, $expire);
}

function delete(string $key): void {
    global $cache;
    $cache->delete("%HOST%-$key");
}
