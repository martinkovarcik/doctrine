<?php

namespace Esports\Doctrine\DI;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\CachedReader;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\EventManager;
use Doctrine\Common\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Common\Persistence\Mapping\Driver\MappingDriverChain;
use Doctrine\Common\Persistence\Mapping\Driver\StaticPHPDriver;
use Doctrine\Common\Proxy\AbstractProxyFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\ORM\Mapping\Driver\DatabaseDriver;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\ORM\Mapping\Driver\YamlDriver;
use Doctrine\ORM\Tools\ResolveTargetEntityListener;
use Esports\Doctrine\Diagnostics\Panel;
use InvalidArgumentException;
use Kdyby\DoctrineCache\DI\Helpers as CacheHelpers;
use Nette\DI\CompilerExtension as BaseCompilerExtension;
use Nette\DI\Helpers;
use Nette\DI\ServiceDefinition;
use Nette\PhpGenerator\ClassType;
use Nette\Utils\Arrays;
use Nette\Utils\AssertionException;
use Nette\Utils\Validators;

class CompilerExtension extends BaseCompilerExtension
{

	/**
	 * @var array
	 */
	public $defaults = array(
		'dbname' => null,
		'host' => '127.0.0.1',
		'port' => null,
		'user' => null,
		'password' => null,
		'charset' => 'UTF8',
		'driver' => 'pdo_mysql',
		'driverClass' => null,
		'driverOptions' => null,
		'logging' => '%debugMode%',
		'schemaFilter' => null,
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
		'targetEntityMapping' => [],
		'entityListenerResolver' => null,
		'namingStrategy' => null,
		'quoteStrategy' => null,
		'autoGenerateProxyClasses' => '%debugMode%',
		'eventSubscribers' => [],
		'types' => [],
	);
	
	/**
	 * @var array
	 */
	private $metadataDriverClasses = [
		'annotation',
		'static',
		'yml',
		'yaml',
		'xml',
		'db',
	];

	/**
	 * @inheritDoc
	 */
	public function loadConfiguration()
	{
		$builder = $this->getContainerBuilder();
		$config = Helpers::expand($this->getConfig() + $this->defaults, $builder->parameters);
		$this->setupConfigByExtensions($config);
		$this->assertConfig($config);
		$builder->parameters[$this->prefix('debug')] = !empty($config['debug']);

		$this->createMetadataDriver();
		$this->createConfigurationService($config);
		$this->createEventManager($config);
		$this->createConnection($config);
		$this->createEntityManager();

		$this->registerMetadata($config['metadata']);
		$this->registerEventSubscribers($config['eventSubscribers']);
	}

	public function registerMetadata(array $metadata)
	{
		$builder = $this->getContainerBuilder();
		$metadataDriver = $builder->getDefinition($this->prefix('metadataDriver'));

		$this->assertMetadataConfiguration($metadata);

		foreach ($metadata as $namespace => $driverMetadata) {
			foreach ($driverMetadata as $driverName => $paths) {
				$serviceName = $this->prefix('driver.' . str_replace('\\', '_', $namespace) . ".$driverName.Impl");
				$driver = $this->createMetadataDriverByType($driverName, (array) $paths);
				$builder->addDefinition($serviceName, $driver);
				$metadataDriver->addSetup('addDriver', ['@' . $serviceName, $namespace]);
			}
		}
	}

	public function registerEventSubscribers(array $eventSubscribers)
	{
		$builder = $this->getContainerBuilder();
		$evm = $builder->getDefinition($this->prefix('evm'));

		foreach ($eventSubscribers as $eventSubscriber) {
			$evm->addSetup('addEventSubscriber', [$eventSubscriber]);
		}
	}

	/**
	 * @inheritDoc
	 */
	public function afterCompile(ClassType $class)
	{
		$panel = Panel::class;
		$annotationRegistry = AnnotationRegistry::class;

		$init = $class->methods['initialize'];
		$init->addBody("{$panel}::registerBluescreen(\$this);");
		$init->addBody("{$annotationRegistry}::registerLoader('class_exists');");
	}

	private function createMetadataDriverByType($type, array $paths)
	{
		switch ($type) {
			case 'annotation':
				return $this->createMetadataAnnotationDriver($paths);
			case 'static':
				return $this->createMetadataStaticDriver($paths);
			case 'yml':
				return $this->createMetadataYmlDriver($paths);
			case 'yaml':
				return $this->createMetadataYamlDriver($paths);
			case 'xml':
				return $this->createMetadataXmlDriver($paths);
			case 'db':
				return $this->createMetadataDbDriver($paths);
			case 'static':
				return $this->createMetadataAnnotationDriver($paths);
		}

		throw new InvalidArgumentException;
	}

	/**
	 * @param array $config
	 */
	private function setupConfigByExtensions(array &$config) {
		foreach ($this->compiler->getExtensions() as $extension) {
			if ($extension instanceof EntityProvider) {
				$metadata = $extension->getEntityMapping();
				Validators::assert($metadata, 'array');
				$config['metadata'] = array_merge($config['metadata'], $metadata);
			}

			if ($extension instanceof TargetEntityProvider) {
				$targetEntities = $extension->getTargetEntityMapping();
				Validators::assert($targetEntities, 'array');
				$config['targetEntityMapping'] = Arrays::mergeTree($config['targetEntityMapping'], $targetEntities);
			}

			if ($extension instanceof EventSubscriberProvider) {
				$subscribers = $extension->getEventSubscribers();
				Validators::assert($subscribers, 'array');
				$config['eventSubscribers'] = array_merge($config['eventSubscribers'], $subscribers);
			}
		}
	}

	/**
	 * @param array $config
	 */
	private function createEventManager(array $config)
	{
		$builder = $this->getContainerBuilder();
		$evm = $builder->addDefinition($this->prefix('evm'));
		$evm->setClass(EventManager::class);
		$evm->setAutowired(false);
		$evm->setInject(false);

		if (count($config['targetEntityMapping'])) {
			$listener = $builder->addDefinition($this->prefix('resolveTargetEntityListener'))
				->setClass(ResolveTargetEntityListener::class)
				->setInject(false);

			foreach ($config['targetEntityMapping'] as $originalEntity => $mapping) {
				$listener->addSetup(
					'addResolveTargetEntity',
					[$originalEntity, $mapping['targetEntity'], $mapping]
				);
			}
			
			$evm->addSetup('addEventListener', [Events::loadClassMetadata, $listener]);
		}
	}

	private function createEntityManager()
	{
		$entityManager = EntityManager::class;

		$buider = $this->getContainerBuilder();
		$em = $buider->addDefinition($this->prefix('em'));
		$em->setClass(EntityManager::class);
		$em->setFactory(
			"{$entityManager}::create",
			[$this->prefix('@connection'), $this->prefix('@config'), $this->prefix('@evm')]
		);
		$em->setAutowired(true);
		$em->setInject(false);
	}

	/**
	 * @param array $config
	 * @return ServiceDefinition
	 */
	private function createConnection($config)
	{
		$driverManager = DriverManager::class;
		$dbalType = Type::class;
		$panel = Panel::class;

		$builder = $this->getContainerBuilder();
		$connection = $builder->addDefinition($this->prefix('connection'));
		$connection->setClass(Connection::class);
		$connection->setFactory(
			"$driverManager::getConnection",
			[$config, $this->prefix('@config'), $this->prefix('@evm')]
		);
		$connection->setAutowired(true);
		$connection->setInject(false);

		foreach ($config['types'] as $type => $class) {
			$connection->addSetup(
				'if (!' . $dbalType . '::hasType(?)) {' . $dbalType . '::addType(?, ?);}',
				[$type, $type, $class]
			);
			$connection->addSetup(
				'$service->getDatabasePlatform()->registerDoctrineTypeMapping(?, ?)',
				[$type, $type]
			);
		}

		if ($config['logging']) {
			$connection->addSetup("$panel::register", ['@self']);
		}
	}

	private function createMetadataDriver()
	{
		$builder = $this->getContainerBuilder();
		$reader = $builder->addDefinition($this->prefix('reader'));
		$reader->setClass(AnnotationReader::class);
		$reader->setAutowired(false);

		$cachedReader = $builder->addDefinition($this->prefix('cachedReader'));
		$cachedReader->setClass(Reader::class);
		$cachedReader->setFactory(
			CachedReader::class,
			[$this->prefix('@reader'), $this->prefix('@cache.metadata')]
		);
		$cachedReader->setInject(false);

		$metadataDriver = $builder->addDefinition($this->prefix('metadataDriver'));
		$metadataDriver->setClass(MappingDriverChain::class);
		$metadataDriver->setAutowired(false);
		$metadataDriver->setInject(false);
	}

	/**
	 * @param array $config
	 */
	private function createConfigurationService(array $config)
	{
		$builder = $this->getContainerBuilder();
		$configuration = $builder->addDefinition($this->prefix('config'));
		$configuration->setClass(Configuration::class);
		$configuration->addSetup('setMetadataCacheImpl', [$this->processCache($config['metadataCache'], 'metadata')]);
		$configuration->addSetup('setQueryCacheImpl', [$this->processCache($config['queryCache'], 'query')]);
		$configuration->addSetup('setResultCacheImpl', [$this->processCache($config['resultCache'], 'ormResult')]);
		$configuration->addSetup(
			'setHydrationCacheImpl',
			[$this->processCache($config['hydrationCache'], 'hydration')]
		);
		$configuration->addSetup('setMetadataDriverImpl', [$this->prefix('@metadataDriver')]);
		$configuration->addSetup('setProxyDir', [$config['proxyDir']]);
		$configuration->addSetup('setProxyNamespace', [$config['proxyNamespace']]);
		$configuration->addSetup('setEntityNamespaces', [$config['namespaceAlias']]);
		$configuration->addSetup('setCustomHydrationModes', [$config['hydrators']]);
		$configuration->addSetup('setCustomStringFunctions', [$config['dql']['string']]);
		$configuration->addSetup('setCustomNumericFunctions', [$config['dql']['numeric']]);
		$configuration->addSetup('setCustomDatetimeFunctions', [$config['dql']['datetime']]);
		$configuration->setAutowired(false);
		$configuration->setInject(false);

		foreach (['entityListenerResolver', 'namingStrategy', 'quoteStrategy'] as $key) {
			if ($config[$key]) {
				$configuration->addSetup('set' . ucfirst($key), $config[$key]);
			}
		}

		foreach ($config['filters'] as $name => $class) {
			$configuration->addSetup('addFilter', [$name, $class]);
		}

		if (is_bool($config['autoGenerateProxyClasses'])) {
			$autoGenerateProxyClasses = $config['autoGenerateProxyClasses']
				? AbstractProxyFactory::AUTOGENERATE_ALWAYS : AbstractProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS;
		} else {
			$autoGenerateProxyClasses = $config['autoGenerateProxyClasses'];
		}

		$configuration->addSetup('setAutoGenerateProxyClasses', [$autoGenerateProxyClasses]);
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
	}

	private function assertMetadataConfiguration($metadata)
	{
		foreach ($metadata as $driver) {
			Validators::assert($driver, 'array');

			foreach ($driver as $driverName => $paths) {
				if (!in_array($driverName, $this->metadataDriverClasses, true)) {
					throw new AssertionException(
						"Wrong metadata driver $driverName. Allowed drivers are " .
						implode(', ', $this->metadataDriverClasses) . '.'
					);
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
		if (($pos = strrpos($path, '*')) !== false) {
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
		return CacheHelpers::processCache(
			$this,
			$cache,
			$suffix,
			$this->getContainerBuilder()->parameters[$this->prefix('debug')]
		);
	}

	/**
	 * @param array $path
	 * @return ServiceDefinition
	 */
	private function createMetadataAnnotationDriver($path)
	{
		return $this->createMetadataServiceDefinition()->setClass(
			AnnotationDriver::class,
			[$this->prefix('@cachedReader'), $path]
		);
	}

	/**
	 * @param array $path
	 * @return ServiceDefinition
	 */
	private function createMetadataYmlDriver($path)
	{
		return $this->createMetadataServiceDefinition()->setClass(YamlDriver::class, [$path]);
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
		return $this->createMetadataServiceDefinition()->setClass(StaticPHPDriver::class, [$path]);
	}

	/**
	 * @param array $path
	 * @return ServiceDefinition
	 */
	private function createMetadataXmlDriver($path)
	{
		return $this->createMetadataServiceDefinition()->setClass(XmlDriver::class, [$path]);
	}

	/**
	 * @param array $path
	 * @return ServiceDefinition
	 */
	private function createMetadataDbDriver($path)
	{
		return $this->createMetadataServiceDefinition()->setClass(DatabaseDriver::class, [$path]);
	}

	/**
	 * @return ServiceDefinition
	 */
	private function createMetadataServiceDefinition()
	{
		$definition = new ServiceDefinition();
		$definition->setClass(MappingDriver::class);
		$definition->setAutowired(false);
		$definition->setInject(false);
		return $definition;
	}
	
}
