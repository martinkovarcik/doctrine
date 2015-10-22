# esports/nette-doctrine #

Rozšíření pro integraci Doctrine ORM do Nette Framework

# Konfigurace #

## Registrace rozšíření ##

```
extensions:
	annotations: Kdyby\Annotations\DI\AnnotationsExtension
	doctrine: App\OrmExtension
```

## Nastavení parametrů ##
```
doctrine:
	user: username
	password: somepassword
	dbname: databasename
	metadata:
		TargetNamespace: %appDir%
```