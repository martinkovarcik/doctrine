<?php

use Nette\Loaders\RobotLoader;
use Tester\Environment;

require __DIR__ . '/../vendor/autoload.php';

Environment::setup();

define('TEMP_DIR', sys_get_temp_dir() . '/' . uniqid());
@mkdir(TEMP_DIR);

$loader = new RobotLoader;
$loader->addDirectory(__DIR__ . '/../src');
$loader->setTempDirectory(TEMP_DIR);
$loader->register();
