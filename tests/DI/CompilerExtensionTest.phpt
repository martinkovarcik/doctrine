<?php

namespace Test;

use ESports\Doctrine\DI\CompilerExtension;
use Nette\DI\Compiler;
use Nette\DI\ContainerBuilder;
use Tester\Assert;
use Tester\TestCase;

require __DIR__ . '/../bootstrap.php';

class CompilerExtensionTest extends TestCase {

	public function testNoError() {
		$compiler = new Compiler(new ContainerBuilder());
		$compiler->addExtension('doctrine', new CompilerExtension);
		$compiler->addConfig(
			[
				'parameters' => [
					'tempDir' => __DIR__,
				],
			]
		);
		Assert::noError(
			function () use ($compiler) {
				$compiler->compile();
			}
		);
	}
}

(new CompilerExtensionTest)->run();
