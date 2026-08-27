# BTS Bank — Phase 4 : observabilité, sauvegarde et opérations

## Portée et décision outils

Cette phase n’ajoute pas une plateforme d’infrastructure sans besoin mesuré.

| Outil | Décision | Motif / déclencheur futur |
|---|---|---|
| Logs JSON Laravel | USE NOW | API et workers ont une télémétrie structurée, redacted et corrélée. |
| Health/readiness Laravel | USE NOW | MariaDB, cache, queue, outbox, Reverb, documents et disque sont déjà réels. |
| Scripts MariaDB backup/restore | USE NOW | XAMPP/MariaDB local disponible ; restore isolé mesurable. |
| Laravel Pulse | DEFER | Adopter quand staging/production offre un accès opérateur protégé et un besoin dashboard prouvé. |
| Prometheus/Grafana/Loki | DEFER | Aucun hébergement/collecteur ni SLO production décidé. |
| OpenTelemetry | DEFER | Monolithe local : pas de flux multi-services justifiant le tracing distribué. |

## Implémentation

- `/api/health/live` est une liveness rapide sans dépendance.
- `/api/health` reste la readiness compatible : MariaDB, cache, Redis si utilisé, queue/backlog, worker, scheduler, failed jobs, outbox, Reverb, stockage et disque local.
- `php artisan operations:status [--json]` donne le même état aux opérateurs sans exposer secrets ou chemins.
- Le canal `json` Laravel est opt-in. Le processeur redacts credentials, PII, headers, payloads et contenu documentaire. Les événements HTTP/queue donnent volume, statut/erreurs, latence/durée, type de job, backlog et heartbeat.
- `backend/scripts/backup-mariadb.ps1` et `verify-mariadb-restore.ps1` produisent dump transactionnel, manifest SHA-256, copie documents, comptes représentatifs, migration status et audit integrity. La destruction est limitée au nom strict `bts_restoreverify_<digits>`.

## Rétention et sécurité

La purge des backups est explicite (`-Prune`) et bornée aux noms de backup attendus. Aucun dossier, document, audit, message, notification métier ou donnée bancaire n’est supprimé automatiquement. Les backups doivent être chiffrés et copiés hors site par l’outil/stockage approuvé ; aucun secret n’est mis en argument de commande ou dans les logs. Les dashboards futurs doivent rester privés et réservés aux opérateurs autorisés.

## Preuve MariaDB locale — 24 août 2026

Exercice sur MariaDB XAMPP 10.4.32, port 3306, sans toucher la base de développement :

1. création de `bts_phase4_backup_20260824152025`, migration fraîche et seed ;
2. backup transactionnel de 57 179 octets, manifest SHA-256 et 2 886 fichiers documentaires (1 537 268 octets) ; durée backup : 39 371 ms ;
3. restore dans `bts_restoreverify_20260824152025` ; comptes représentatifs, migrations et audit validés ; durée restore-verification : 3 281 ms ;
4. la base restore jetable a été supprimée par le script. La source jetable est supprimée à la fin de validation Phase 4.

Ces temps sont locaux et ne constituent pas un RPO/RTO de production.

## Risques restants

- XAMPP et disque local ne sont pas une architecture bancaire de production.
- Aucune alerte externe, stockage hors site chiffré, compte DB de backup séparé ou observabilité centralisée n’est encore provisionné.
- Les métriques sont dans les logs JSON ; dashboard, SLO et rétention centralisés attendent le choix d’hébergement.
- Les documents IA restent en mode local tant que le propriétaire n’autorise pas explicitement l’envoi cloud Gemini.
- L’artefact de backup de validation local reste dans le répertoire temporaire car la suppression
  récursive a été bloquée par la protection du terminal ; il doit être supprimé manuellement avant
  de partager la machine : `C:\Users\wassi\AppData\Local\Temp\bts-phase4-backup-20260824152025`.

## Verdict
## Validation

| Contrôle | Résultat |
|---|---|
| Tests ciblés santé/logging/queue/outbox | PASS — 28 tests, 134 assertions |
| Full Laravel | PASS — 416 tests, 3 055 assertions ; 3 tests MariaDB dédiés skipped dans SQLite |
| MariaDB fresh migration | PASS — base jetable Phase 4 |
| Backup + restore MariaDB isolé | PASS — SHA-256, comptes, migrations, audit, documents |
| Client / Staff / Admin | PASS — lint, TypeScript, builds production |
| Security Center | PASS — lint, TypeScript, Vitest 8/8, build production |
| Playwright isolé | PASS — 9/9 en 5,3 min |
| Composer audit | PASS — aucune alerte |
| npm audit production (4 portails) | PASS — 0 vulnérabilité |
| Gitleaks local | NOT RUN — binaire indisponible ; CI reste le contrôle prévu |



SAFE TO CONTINUE TO PHASE 5

Phase 5 — Synthetic Data & Real Load Testing. Elle n’est pas commencée ici.
