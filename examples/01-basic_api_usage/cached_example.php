<?php

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

include_once '../../vendor/autoload.php';
$logger     = new \Psr\Log\NullLogger;
$httpClient = new \GuzzleHttp\Client();
// the PSR-6 cache object that you want to use (you might also use a PSR-16 Interface Object directly)
$psr6Cache  = new FilesystemAdapter();
$psr16Cache = new Psr16Cache($psr6Cache);
$api        = new \OpenFoodFacts\Api('Example app - dev - https://example.org', 'food', 'world', $logger, $httpClient, $psr16Cache);
// fetched through the versioned READ API (v3.6); only the listed fields are returned
$product    = $api->getProduct('3057640385148', ['product_name', 'nutriscore_grade']);
