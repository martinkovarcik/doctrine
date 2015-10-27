# esports/nette-doctrine #

Rozšíření pro integraci Doctrine ORM do Nette Framework

# Konfigurace #

## Registrace rozšíření ##

```
extensions:
	doctrine: App\OrmExtension
```

## Nastavení parametrů ##
```
doctrine:
	user: username
	password: somepassword
	dbname: databasename
	metadata:
		NamespaceName:
			DriverName:
				- PathToDomainObjects
				- AnotherPathToDomainObjects

		SecondNamespaceName:
			DriverName:
				- PathToDomainObjects
```

Jako parametr DriverName je možné využít parametry:

* annotation (pro čtení anotací z doménového objektu)
* xml
* yml
* yaml
* db
* static