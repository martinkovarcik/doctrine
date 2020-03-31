<?php

namespace ESports\Doctrine\DI;

use Doctrine\Common\EventManager;
use Doctrine\Common\Persistence\Mapping\Driver\MappingDriverChain;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Nette\DI\CompilerExtension as BaseCompilerExtension;
use Nette\DI\Config\Helpers;
use Nette\DI\Extensions\InjectExtension;
use Nette\DI\Helpers as DIHelpers;

class CompilerExtension extends BaseCompilerExtension {

	const CONNECTION = 'connection';

	const EVENT_MANAGER = 'evm';

	const CONFIGURATION = 'configuration';

	const METADATA_DRIVER = 'metadata';

	/**
	 * @var array
	 */
	private $defaults;

	public function __construct() {
		$this->defaults = [
			'connection' => [
				'dbname'         => NULL,
				'host'           => NULL,
				'port'           => NULL,
				'user'           => NULL,
				'password'       => NULL,
				'charset'        => 'UTF8',
				'driver'         => NULL,
				'driverClass'    => NULL,
				'driverOptions'  => NULL,
				'server_version' => NULL,
			],
			'dbal'       => [
				'types' => [],
			],
			'orm'        => [
				'dql'          => [
					'string'   => [],
					'numeric'  => [],
					'datetime' => [],
				],
				'eventManager' => [
					'subscribers' => [],
				],
				'metadata'     => [
					'drivers' => [],
				],
				'proxy'        => [
					'autoGenerateProxyClasses' => FALSE,
					'proxyDir'                 => '%tempDir%/proxy',
					'proxyNamespace'           => 'DoctrineProxy',
				],
				'cache'        => [
					'metadata'  => NULL,
					'query'     => NULL,
					'result'    => NULL,
					'hydration' => NULL,
				],
			],

			'autowired' => TRUE,
		];
	}

	public function loadConfiguration(): void {
		$builder = $this->getContainerBuilder();
		$config = Helpers::merge($this->config, DIHelpers::expand($this->defaults, $builder->parameters));

		$this->createMetadataImplementationDriver($config['orm']['metadata']);
		$this->createConfigurationService($config['orm']);
		$this->createEventManager($config['orm']['eventManager']);
		$this->createConnection($config['connection'], $config['dbal'], $config['autowired']);
		$this->createEntityManager($config['autowired']);
	}

	private function createMetadataImplementationDriver(array $config): void {
		$builder = $this->getContainerBuilder();
		$evm = $builder->addDefinition($this->prefix(self::METADATA_DRIVER));
		$evm->setClass(MappingDriverChain::class);
		$evm->setAutowired(FALSE);
		$evm->addTag(InjectExtension::TAG_INJECT, FALSE);

		foreach ($config['drivers'] as $namespace => $driver) {
			$evm->addSetup('addDriver', [$driver, $namespace]);
		}
	}

	private function createConnection(array $config, array $dbalConfig, $autowired): void {
		$builder = $this->getContainerBuilder();
		$connection = $builder->addDefinition($this->prefix(self::CONNECTION));
		$connection->setClass(Connection::class);
		$connection->setFactory(
			DriverManager::class . '::getConnection',
			[$config, $this->prefix('@' . self::CONFIGURATION), $this->prefix('@' . self::EVENT_MANAGER)]
		);
		$connection->setAutowired($autowired);
		$connection->addTag(InjectExtension::TAG_INJECT, FALSE);

		foreach ($dbalConfig['types'] as $type => $class) {
			$connection->addSetup(
				'if (!' . Type::class . '::hasType(?)) {' . Type::class . '::addType(?, ?);}',
				[$type, $type, $class]
			);
			$connection->addSetup(
				'$service->getDatabasePlatform()->registerDoctrineTypeMapping(?, ?)',
				[$type, $type]
			);
		}
	}

	private function createEventManager(array $config): void {
		$builder = $this->getContainerBuilder();
		$evm = $builder->addDefinition($this->prefix(self::EVENT_MANAGER));
		$evm->setClass(EventManager::class);
		$evm->setAutowired(FALSE);
		$evm->addTag(InjectExtension::TAG_INJECT, FALSE);

		foreach ($config['subscribers'] as $eventSubscriber) {
			$evm->addSetup('addEventSubscriber', [$eventSubscriber]);
		}
	}

	private function createEntityManager(bool $autoWired): void {
		$entityManager = EntityManager::class;

		$builder = $this->getContainerBuilder();
		$em = $builder->addDefinition($this->prefix('em'));
		$em->setClass(EntityManager::class);
		$em->setFactory(
			"{$entityManager}::create",
			[
				$this->prefix('@' . self::CONNECTION),
				$this->prefix('@' . self::CONFIGURATION),
				$this->prefix('@' . self::EVENT_MANAGER),
			]
		);
		$em->setAutowired($autoWired);
		$em->addTag(InjectExtension::TAG_INJECT, FALSE);
	}

	private function createConfigurationService(array $config): void {
		$builder = $this->getContainerBuilder();
		$configuration = $builder->addDefinition($this->prefix(self::CONFIGURATION));
		$configuration->setClass(Configuration::class);
		$configuration->setAutowired(FALSE);
		$configuration->addTag(InjectExtension::TAG_INJECT, FALSE);

		$configuration->addSetup('setMetadataDriverImpl', [$this->prefix('@' . self::METADATA_DRIVER)]);
		$configuration->addSetup('setProxyDir', [$config['proxy']['proxyDir']]);
		$configuration->addSetup('setProxyNamespace', [$config['proxy']['proxyNamespace']]);
		$configuration->addSetup('setAutoGenerateProxyClasses', [$config['proxy']['autoGenerateProxyClasses']]);
		$configuration->addSetup('setCustomStringFunctions', [$config['dql']['string']]);
		$configuration->addSetup('setCustomNumericFunctions', [$config['dql']['numeric']]);
		$configuration->addSetup('setCustomDatetimeFunctions', [$config['dql']['datetime']]);

		if ($config['cache']['metadata']) {
			$configuration->addSetup('setMetadataCacheImpl', [$config['cache']['metadata']]);
		}

		if ($config['cache']['query']) {
			$configuration->addSetup('setQueryCacheImpl', [$config['cache']['query']]);
		}

		if ($config['cache']['result']) {
			$configuration->addSetup('setResultCacheImpl', [$config['cache']['result']]);
		}

		if ($config['cache']['hydration']) {
			$configuration->addSetup('setHydrationCacheImpl', [$config['cache']['hydration']]);
		}
	}

}
