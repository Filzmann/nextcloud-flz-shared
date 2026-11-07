# FLZ Shared Packages

Dieses eigenständig versionierte Quellrepository enthält ausschließlich
zustandslose Kategorie-A-Bausteine nach ADR 0001. Es ist keine Nextcloud-App
und wird nicht als gemeinsame Laufzeit installiert.

## Pakete

- `packages/php-runtime`: Composer-Paket `filzmann/shared-runtime` mit
  `ApiResponder`, `ModelApiTrait` und `AppLogger`.
- `packages/browser`: Browser-Paket `@filzmann/shared-browser` mit
  `ApiClient`, `Model`, `Repository` und kleinen UI-Primitives.

Die Paketquellen verwenden neutrale Namespaces. Der reproduzierbare Build
erzeugt je Consumer eine app-spezifisch isolierte Laufzeitkopie, beispielsweise
`OCA\FlzUrlaub\Vendor\Filzmann\Shared` und
`window.OCA.FlzUrlaub.Shared`. Ein Consumer lädt niemals dieses Repository
oder einen benachbarten Workspace-Pfad zur Laufzeit.

## Build

```bash
php scripts/build-app-bundle.php \
  --app-id=flzurlaub \
  --app-namespace=FlzUrlaub \
  --target=/absoluter/pfad/zum/php-bundle \
  --browser-target=/absoluter/pfad/zum/app-js-bundle
```

Ohne `--browser-target` wird der Browser-Build unter `<target>/browser`
erzeugt. Bestehende Ziele werden bewusst nicht überschrieben. Der Build
schreibt einen stabilen Lock mit Paketname, Version, Quell-Digest und
Zielnamespace. Consumer-Repositories übernehmen nur diese gebaute,
versionierte Produktionsabhängigkeit und halten ihren Lock mit dem Release.

## Prüfung

```bash
php tests/run.php
composer validate --strict packages/php-runtime/composer.json
node --check packages/browser/src/api-client.js
node --check packages/browser/src/model.js
node --check packages/browser/src/repository.js
node --check packages/browser/src/ui.js
```

Der fokussierte Test beweist deterministische Ausgabe, isolierte PHP- und
Browser-Namen, Paketdigests, Lizenzmetadaten und die Auflösung durch genau
einen app-lokalen Autoloader. Er beweist keine Nextcloud-DI- oder
Installationsmatrix; diese bleibt Aufgabe jedes Consumers.
