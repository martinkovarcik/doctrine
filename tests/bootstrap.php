<?php

use Nette\Caching\Storages\DevNullStorage;
use Nette\Loaders\RobotLoader;
use Tester\Environment;

require __DIR__ . '/../vendor/autoload.php';

Environment::setup();

$loader = new RobotLoader;
$loader->addDirectory(__DIR__ . '/../src');
$loader->setCacheStorage(new DevNullStorage());
$loader->register();
