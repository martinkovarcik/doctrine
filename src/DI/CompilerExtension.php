<?php

namespace Esports\Doctrine\DI;

use Doctrine\Common\Proxy\AbstractProxyFactory;
use Kdyby\DoctrineCache\DI\Helpers as CacheHelpers;
use Nette\DI\CompilerExtension AS BaseCompilerExtension;
use Nette\DI\ContainerBuilder;
use Nette\DI\Helpers;
use Nette\DI\ServiceDefinition;
use Nette\PhpGenerator\ClassType;
use Nette\Utils\AssertionException;
use Nette\Utils\Validators;

class CompilerExtension extends BaseCompilerExtension
{

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
		'driverOptions' => NULL,
		'logging' => '%debugMode%',
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
		'autoGenerateProxyClasses' => '%debugMode%',
		'eventSubscribers' => []
	);
	
	/**
	 * @var array
	 */
	private $metadataDriverClasses = [
		'annotation' => 'createMetadataAnnotationDriver',
		'static' => 'createMetadataStaticDriver',
		'yml' => 'createMetadataYmlDriver',
		'yaml' => 'createMetadataYamlDriver',
		'xml' => 'createMetadataXmlDriver',
		'db' => 'createMetadataDbDriver'
	];

	/**
	 * @inheritDoc
	 */
	public function loadConfiguration()
	{
		$builder = $this->getContainerBuilder();
		$config = Helpers::expand($this->getConfig() + $this->defaults, $builder->parameters);
		$this->assertConfig($config);
		$builder->parameters[$this->prefix('debug')] = !empty($config['debug']);

		$this->registerMetadataDrivers($builder, $config['metadata']);
		$builder->addDefinition($this->prefix('config'), $this->createConfigurationServiceDefinition($config));
		$builder->addDefinition($this->prefix('evm'), $this->createEventManagerDefinition($config));
		$builder->addDefinition($this->prefix('connection'), $this->createConnectionDefinition($config));
		$builder->addDefinition($this->prefix('em'), $this->createEntityManagerDefinition());
	}
	
	/**
	 * @inheritDoc
	 */
	public function afterCompile(ClassType $class)
	{
		$init = $class->methods['initialize'];
		$init->addBody('Esports\Doctrine\Diagnostics\Panel::registerBluescreen($this);');
		$init->addBody('Doctrine\Common\Annotations\AnnotationRegistry::registerLoader("class_exists");');
	}

	/**
	 * @param array $config
	 * @return ServiceDefinition
	 */
	private function createEventManagerDefinition(array $config)
	{
		$evm = (new ServiceDefinition)
			->setClass('Doctrine\Common\EventManager')
			->setAutowired(FALSE)
			->setInject(FALSE);

		foreach ($config['eventSubscribers'] as $eventSubscriber) {
			$evm->addSetup('addEventSubscriber', [$eventSubscriber]);
		}

		return $evm;
	}

	/**
	 * @return ServiceDefinition
	 */
	private function createEntityManagerDefinition()
	{
		return (new ServiceDefinition)
				->setClass('Doctrine\ORM\EntityManager')
				->setFactory('Doctrine\ORM\EntityManager::create', [
					$this->prefix('@connection'),
					$this->prefix('@config'),
					$this->prefix('@evm')
				])
				->setAutowired(TRUE)
				->setInject(FALSE);
	}

	/**
	 * @param array $config
	 * @return ServiceDefinition
	 */
	private function createConnectionDefinition($config)
	{
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
				->addSetup('if (!Doctrine\DBAL\Types\Type::hasType(?)) {Doctrine\DBAL\Types\Type::addType(?, ?);}', [$type, $type, $class])
				->addSetup('$service->getDatabasePlatform()->registerDoctrineTypeMapping(?, ?)', [$type, $type]);
		}

		if ($config['logging']) {
			$connection->addSetup('Esports\Doctrine\Diagnostics\Panel::register', ['@self']);
		}
			
		return $connection;
	}

	/**
	 * @param ContainerBuilder $builder
	 * @param array $metadata
	 */
	private function registerMetadataDrivers(ContainerBuilder $builder, $metadata)
	{
		$builder->addDefinition($this->prefix('reader'))
			->setClass('Doctrine\Common\Annotations\AnnotationReader')
			->setAutowired(FALSE);

		$builder->addDefinition($this->prefix('cachedReader'))
			->setClass('Doctrine\Common\Annotations\Reader')
			->setFactory('Doctrine\Common\Annotations\CachedReader', [
				$this->prefix('@reader'),
				$this->prefix('@cache.metadata')
			])
			->setInject(FALSE);

		$metadataDriver = $builder->addDefinition($this->prefix('metadataDriver'))
			->setClass('Doctrine\Common\Persistence\Mapping\Driver\MappingDriverChain')
			->setAutowired(FALSE)
			->setInject(FALSE);

		foreach ($metadata as $namespace => $driverMetadata) {
			foreach ($driverMetadata as $driverName => $paths) {
				$serviceName = $this->prefix('driver.' . str_replace('\\', '_', $namespace) . ".$driverName.Impl");
				$callback = [$this, $this->metadataDriverClasses[$driverName]];
				$driver = $callback((array) $paths);
				$builder->addDefinition($serviceName, $driver);
				$metadataDriver->addSetup('addDriver', ['@' . $serviceName, $namespace]);
			}
		}
	}

	/**
	 * @param array $config
	 * @return ServiceDefinition
	 */
	private function createConfigurationServiceDefinition(array $config)
	{
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
	private function assertConfig(array $config)
	{
		Validators::assertField($config, 'namespaceAlias', 'array');
		Validators::assertField($config, 'hydrators', 'array');
		Validators::assertField($config, 'dql', 'array');
		Validators::assertField($config['dql'], 'string', 'array');
		Validators::assertField($config['dql'], 'numeric', 'array');
		Validators::assertField($config['dql'], 'datetime', 'array');
		Validators::assertField($config, 'metadata', 'array');
		Validators::assertField($config, 'filters', 'array');
		Validators::assertField($config, 'eventSubscribers', 'array');

		foreach ($config['metadata'] as $driver) {
			Validators::assert($driver, 'array');

			foreach ($driver as $driverName => $paths) {
				if (!isset($this->metadataDriverClasses[$driverName])) {
					throw new AssertionException("Wrong metadata driver $driverName. Allowed drivers are " . implode(', ', array_keys($this->metadataDriverClasses)) . '.');
				}

				foreach ((array) $paths as $path) {
					$this->checkPath($path);
				}
			}
		}
	}

	/**
	 * @param string $path
	 * @throws AssertionException
	 */
	private function checkPath($path)
	{
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
	private function processCache($cache, $suffix)
	{
		return CacheHelpers::processCache($this, $cache, $suffix, $this->getContainerBuilder()->parameters[$this->prefix('debug')]);
	}

	/**
	 * @param array $path
	 * @return ServiceDefinition
	 */
	private function createMetadataAnnotationDriver($path)
	{
		return $this->createMetadataServiceDefinition()
				->setClass('Doctrine\ORM\Mapping\Driver\AnnotationDriver', [$this->prefix('@cachedReader'), $path]);
	}

	/**
	 * @param array $path
	 * @return ServiceDefinition
	 */
	private function createMetadataYmlDriver($path)
	{
		return $this->createMetadataServiceDefinition()
				->setClass('Doctrine\ORM\Mapping\Driver\YamlDriver', [$path]);
	}

	/**
	 * @param array $path
	 * @return ServiceDefinition
	 */
	private function createMetadataYamlDriver($path)
	{
		return $this->createMetadataYmlDriver($path);
	}

	/**
	 * @param array $path
	 * @return ServiceDefinition
	 */
	private function createMetadataStaticDriver($path)
	{
		return $this->createMetadataServiceDefinition()
				->setClass('Doctrine\Common\Persistence\Mapping\Driver\StaticPHPDriver', [$path]);
	}

	/**
	 * @param array $path
	 * @return ServiceDefinition
	 */
	private function createMetadataXmlDriver($path)
	{
		return $this->createMetadataServiceDefinition()
				->setClass('Doctrine\ORM\Mapping\Driver\XmlDriver', [$path]);
	}

	/**
	 * @param array $path
	 * @return ServiceDefinition
	 */
	private function createMetadataDbDriver($path)
	{
		return $this->createMetadataServiceDefinition()
				->setClass('Doctrine\ORM\Mapping\Driver\DatabaseDriver', [$path]);
	}

	/**
	 * @return ServiceDefinition
	 */
	private function createMetadataServiceDefinition()
	{
		return (new ServiceDefinition())
				->setClass('Doctrine\Common\Persistence\Mapping\Driver\MappingDriver')
				->setAutowired(FALSE)
				->setInject(FALSE);
	}
	
}
