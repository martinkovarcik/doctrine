# Doctrine ORM Nette integration #

Extension, that registers Doctrine ORM into DI Container.

## Extension registration ##

```
extensions:
    doctrine: ESports\Doctrine\DI\CompilerExtension
```

## Extension configuration ###

### Default extension configuration ###
```
doctrine:
    connection:
        dbname: null
        host: null
        port: null
        user: null
        password: null
        charset: UTF8
        driver: null
        driverClass: null
        driverOptions: null
        server_version: null
    dbal:
        types: []
    orm:
        dql:
            string: []
            numeric: []
            datetime: []
        eventManager:
            subscribers: []
        metadata:
            drivers: []
        proxy:
            autoGenerateProxyClasses: false,
            proxyDir: '%tempDir%/proxy',
            proxyNamespace: DoctrineProxy,
        cache:
            metadata: null
            query: null
            result: null
            hydration: null

    autowired: true
```

### Metadata configuration example ###

Drivers expect key - value map. Key is namespace of entities.
Value is a mapping driver. Drive must be an is instance of *Doctrine\Common\Persistence\Mapping\Driver\MappingDriver*.

```
yamlDriver: Doctrine\ORM\Mapping\Driver\YamlDriver(%pathToMetadata%)

doctrine:
    orm:
        metadata:
            drivers:
                EntityNamespace: @yamlDriver
```
