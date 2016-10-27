<?php

namespace Test;

use Esports\Doctrine\DI\CompilerExtension;
use Nette\DI\Compiler;
use Nette\DI\ContainerBuilder;
use Tester;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

class CompilerExtensionTest extends Tester\TestCase
{

	public function testNoError()
	{
		$containerBuilder = new ContainerBuilder;
		$containerBuilder->parameters = [
			'debugMode' => true,
			'tempDir' => __DIR__,
		];
		$compiler = new Compiler($containerBuilder);

		Assert::noError(function () use ($compiler) {
			$compilerExtension = new CompilerExtension;
			$compilerExtension->setCompiler($compiler, 'doctrine');
			$compilerExtension->loadConfiguration();
		});
	}
}

(new CompilerExtensionTest)->run();
