<?php

namespace Esports\Doctrine;

use Doctrine\Common\Proxy\AbstractProxyFactory;
use Kdyby\DoctrineCache\DI\Helpers as CacheHelpers;
use Nette\DI\CompilerExtension;
use Nette\DI\ContainerBuilder;
use Nette\DI\Helpers;
use Nette\DI\ServiceDefinition;
use Nette\PhpGenerator\ClassType;
use Nette\Utils\AssertionException;
use Nette\Utils\Validators;

class OrmExtension extends CompilerExtension {

	/**
	 * @var array
	 */
	public $defaults = array(
		'dbname' => NULL,
		'host' => '127.0.0.1',
		'port' => NULL,
		'user' => NULL,
		'password' => NULL,
		'charset' => 'UTF8',
		'driver' => 'pdo_mysql',
		'driverClass' => NULL,
		'options' => NULL,
		'path' => NULL,
		'memory' => NULL,
		'unix_socket' => NULL,
		'logging' => '%debugMode%',
		'platformService' => NULL,
		'defaultTableOptions' => [],
		'resultCache' => 'default',
		'schemaFilter' => NULL,
		'metadataCache' => 'default',
		'queryCache' => 'default',
		'resultCache' => 'default',
		'hydrationCache' => 'default',
		'proxyDir' => '%tempDir%/proxies',
		'proxyNamespace' => 'DoctrineProxies',
		'dql' => array('string' => [], 'numeric' => [], 'datetime' => []),
		'hydrators' => [],
		'metadata' => [],
		'filters' => [],
		'namespaceAlias' => [],
		'targetEntityMappings' => [],
		'entityListenerResolver' => NULL,
		'namingStrategy' => NULL,
		'quoteStrategy' => NULL,
		'autoGenerateProxyClasses' => '%debugMode%'
	);

	/**
	 * @inheritDoc
	 */
	public function loadConfiguration() {
		$builder = $this->getContainerBuilder();
		$config = Helpers::expand($this->getConfig() + $this->defaults, $builder->parameters);
		$this->assertConfig($config);
		$builder->parameters[$this->prefix('debug')] = !empty($config['debug']);

		$this->registerMetadataDrivers($builder, $config['metadata']);
		$builder->addDefinition($this->prefix('config'), $this->createConfigurationServiceDefinition($config));
		$builder->addDefinition($this->prefix('evm'), $this->createEventManagerDefinition());
		$builder->addDefinition($this->prefix('connection'), $this->createConnectionDefinition($config));
		$builder->addDefinition($this->prefix('em'), $this->createEntityManagerDefinition());
	}

	/**
	 * @return ServiceDefinition
	 */
	protected function createEventManagerDefinition() {
		return (new ServiceDefinition)
						->setClass('Doctrine\Common\EventManager')
						->setAutowired(FALSE)
						->setInject(FALSE);
	}

	/**
	 * @return ServiceDefinition
	 */
	protected function createEntityManagerDefinition() {
		return (new ServiceDefinition)
						->setClass('Doctrine\Common\EventManager')
						->setClass('Doctrine\ORM\EntityManager')
						->setFactory('Doctrine\ORM\EntityManager::create', [
							$this->prefix('@connection'),
							$this->prefix('@config')
						])
						->setAutowired(TRUE)
						->setInject(FALSE);
	}

	/**
	 * @param array $config
	 * @return ServiceDefinition
	 */
	protected function createConnectionDefinition($config) {
		$connection = (new ServiceDefinition)
				->setClass('Doctrine\DBAL\Connection')
				->setFactory('Doctrine\DBAL\DriverManager::getConnection', [
					$config,
					$this->prefix('@config'),
					$this->prefix('@evm')
				])
				->setAutowired(TRUE)
				->setInject(FALSE);

		foreach ($config['types'] as $type => $class) {
			$connection
					->addSetup('Doctrine\DBAL\Types\Type::addType', [$type, $class])
					->addSetup('$service->getDatabasePlatform()->registerDoctrineTypeMapping(?, ?)', [$type, $type]);
		}

		$connection->addSetup('Esports\Doctrine\Diagnostics\Panel::register', ['@self']);
		return $connection;
	}

	/**
	 * @param ContainerBuilder $builder
	 * @param array $metadata
	 */
	protected function registerMetadataDrivers(ContainerBuilder $builder, $metadata) {
		$builder->addDefinition($this->prefix('reader'))
				->setClass('Doctrine\Common\Annotations\AnnotationReader');

		$builder->addDefinition($this->prefix('cachedReader'))
				->setClass('Doctrine\Common\Annotations\CachedReader')
				->setArguments([$this->prefix('@reader'), $this->prefix('@cache.metadata')]);

		$metadataDriver = $builder->addDefinition($this->prefix('metadataDriver'))
				->setClass('Doctrine\Common\Persistence\Mapping\Driver\MappingDriverChain')
				->setAutowired(FALSE)
				->setInject(FALSE);

		foreach ($metadata as $namespace => $path) {
			$serviceName = $this->prefix('driver.' . str_replace('\\', '_', $namespace) . '.' . 'Impl');
			$driver = $this->createAnnotationDriver((array) $path);
			$builder->addDefinition($serviceName, $driver);
			$metadataDriver->addSetup('addDriver', ['@' . $serviceName, $namespace]);
		}
	}

	/**
	 * @param array $config
	 * @return ServiceDefinition
	 */
	protected function createConfigurationServiceDefinition(array $config) {
		$configuration = (new ServiceDefinition())
				->setClass('Doctrine\ORM\Configuration')
				->addSetup('setMetadataCacheImpl', [$this->processCache($config['metadataCache'], 'metadata')])
				->addSetup('setQueryCacheImpl', [$this->processCache($config['queryCache'], 'query')])
				->addSetup('setResultCacheImpl', [$this->processCache($config['resultCache'], 'ormResult')])
				->addSetup('setHydrationCacheImpl', [$this->processCache($config['hydrationCache'], 'hydration')])
				->addSetup('setMetadataDriverImpl', [$this->prefix('@metadataDriver')])
				->addSetup('setProxyDir', [$config['proxyDir']])
				->addSetup('setProxyNamespace', [$config['proxyNamespace']])
				->addSetup('setEntityNamespaces', [$config['namespaceAlias']])
				->addSetup('setCustomHydrationModes', [$config['hydrators']])
				->addSetup('setCustomStringFunctions', [$config['dql']['string']])
				->addSetup('setCustomNumericFunctions', [$config['dql']['numeric']])
				->addSetup('setCustomDatetimeFunctions', [$config['dql']['datetime']])
				->setAutowired(FALSE)
				->setInject(FALSE);

		foreach (['entityListenerResolver', 'namingStrategy', 'quoteStrategy'] as $key) {
			if ($config[$key]) {
				$configuration->addSetup('set' . ucfirst($key), $config[$key]);
			}
		}

		foreach ($config['filters'] as $name => $class) {
			$configuration->addSetup('addFilter', [$name, $class]);
		}

		$autoGenerateProxyClasses = is_bool($config['autoGenerateProxyClasses']) ? ($config['autoGenerateProxyClasses'] ? AbstractProxyFactory::AUTOGENERATE_ALWAYS : AbstractProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS) : $config['autoGenerateProxyClasses'];
		$configuration->addSetup('setAutoGenerateProxyClasses', [$autoGenerateProxyClasses]);
		return $configuration;
	}

	/**
	 * @param array $config
	 * @throws AssertionException
	 */
	protected function assertConfig(array $config) {
		Validators::assertField($config, 'namespaceAlias', 'array');
		Validators::assertField($config, 'hydrators', 'array');
		Validators::assertField($config, 'dql', 'array');
		Validators::assertField($config['dql'], 'string', 'array');
		Validators::assertField($config['dql'], 'numeric', 'array');
		Validators::assertField($config['dql'], 'datetime', 'array');
		Validators::assertField($config, 'metadata', 'array');
		Validators::assertField($config, 'filters', 'array');

		foreach ($config['metadata'] as $paths) {
			foreach ((array) $paths as $path) {
				$this->checkPath($path);
			}
		}
	}

	/**
	 * @param string $path
	 * @throws AssertionException
	 */
	protected function checkPath($path) {
		if (($pos = strrpos($path, '*')) !== FALSE) {
			$path = substr($path, 0, $pos);
		}

		if (!file_exists($path)) {
			throw new AssertionException("The metadata path expects to be an existing directory, $path given.");
		}
	}

	/**
	 * @param string $cache
	 * @param string $suffix
	 * @return string
	 */
	protected function processCache($cache, $suffix) {
		return CacheHelpers::processCache($this, $cache, $suffix, $this->getContainerBuilder()->parameters[$this->prefix('debug')]);
	}

	/**
	 * @param array $path
	 * @return ServiceDefinition
	 * @throws AssertionException
	 */
	protected function createAnnotationDriver($path) {
		return (new ServiceDefinition())
						->setClass('Doctrine\ORM\Mapping\Driver\AnnotationDriver', [$this->prefix('@cachedReader'), $path])
						->setAutowired(FALSE)
						->setInject(FALSE);
	}

	/**
	 * @inheritDoc
	 */
	public function afterCompile(ClassType $class) {
		$init = $class->methods['initialize'];
		$init->addBody('Esports\Doctrine\Diagnostics\Panel::registerBluescreen($this);');
	}

}
