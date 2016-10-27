<?php

namespace Test;

use Tester;
use Tester\Assert;
use Esports\Doctrine\DI\CompilerExtension;

require __DIR__ . '/../bootstrap.php';

class CompilerExtensionTest extends Tester\TestCase {

	function setUp() {

	}

	public function testNoError()
	{
		$containerBuilder = new \Nette\DI\ContainerBuilder;
		$containerBuilder->parameters = [
			'debugMode' => true,
			'tempDir' => __DIR__,
		];
		$compiler = new \Nette\DI\Compiler($containerBuilder);

		Assert::noError(function () use ($compiler) {
			$compilerExtension = new CompilerExtension;
			$compilerExtension->setCompiler($compiler, 'doctrine');
			$compilerExtension->loadConfiguration();
		});

	}

}

(new CompilerExtensionTest)->run();
