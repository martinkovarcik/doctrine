<?php

namespace Test;

use ESports\Doctrine\DI\CompilerExtension;
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
			'tempDir' => __DIR__,
		];
		$compiler = new Compiler($containerBuilder);
		$compiler->addExtension('doctrine', new CompilerExtension);

		Assert::noError(
			function () use ($compiler) {
				$compiler->compile();
			}
		);
	}
}

(new CompilerExtensionTest)->run();
