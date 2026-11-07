# AGENTS.md - FLZ Shared Packages

## Grenze

Dieses Repository besitzt ausschließlich die zentral versionierten Quellen
zustandsloser Kategorie-A-Pakete nach der Parent-ADR 0001. Es ist keine
Nextcloud-App und darf weder persistente Daten, Nextcloud-AppConfig,
Administration, Hintergrundjobs noch öffentliche Kategorie-B-Laufzeitdienste
besitzen.

## Arbeitsregeln

- Neue gemeinsame Bausteine benötigen mindestens zwei belegte semantisch
  identische Consumer und denselben Änderungsgrund.
- Paketquellen bleiben neutral; app-spezifische Namen entstehen ausschließlich
  im deterministischen Bundle-Build.
- Consumer laden weder dieses Repository noch benachbarte Workspace-Pfade zur
  Laufzeit. Produktionsdateien und Autoloader liegen vollständig app-lokal.
- PHP-Ausgaben werden je App unter einem eigenen Namespace geprefixt. Browser-
  Ausgaben verwenden je App ein eigenes `window.OCA.<App>.Shared`-Objekt und
  erzeugen kein globales `window.LocalBase`.
- Paket- und Lock-Versionen, Quell-Digests und Lizenzmetadaten werden gemeinsam
  aktualisiert. Bestehende Buildziele werden nicht still überschrieben.
- Verhaltensänderungen folgen Red-Green-Refactor; die kleinste reale
  Verbrauchergrenze wird zusätzlich im jeweiligen App-Repository geprüft.
- Keine Commits, Pushes oder Veröffentlichung ohne ausdrückliche Freigabe.

## Prüfung

```bash
php tests/run.php
composer validate --strict packages/php-runtime/composer.json
node --check packages/browser/src/api-client.js
node --check packages/browser/src/model.js
node --check packages/browser/src/repository.js
node --check packages/browser/src/ui.js
git diff --check
git status --short
```
