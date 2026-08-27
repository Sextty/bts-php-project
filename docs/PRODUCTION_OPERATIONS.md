# BTS Bank — exploitation locale et cible production

## Démarrage local XAMPP

Prérequis: Apache/MySQL XAMPP démarré, MariaDB/MySQL sur `127.0.0.1:3306`, PHP 8.2+, Node 22.

Dans cinq terminaux:

```powershell
cd backend
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8000
```

```powershell
cd backend
php artisan queue:work --tries=3 --backoff=2 --timeout=90
```

```powershell
cd backend
php artisan reverb:start --host=127.0.0.1 --port=6001
```

```powershell
cd backend
php artisan schedule:work
```

Puis lancer les portails avec `npm run dev` dans `client` (3000), `staff` (3001), `admin`
(3002) et `sc` (3003). L'API est sur `http://127.0.0.1:8000`.

Le worker est maintenant obligatoire: notifications, e-mails et broadcasts realtime passent par
la queue. `/api/health` devient dégradé si la plus ancienne tâche attend trop longtemps.

## Comptes internes

Un employé opérationnel doit avoir une agence:

```powershell
php artisan staff:make reviewer@bts.test --role=staff --branch-id=1
```

Ne jamais créer un staff sans agence directement en base. Les rôles globaux sont réservés à
`admin`, `super_admin` et `security`, avec MFA/JIT à ajouter dans l'infrastructure de production.

## Documents et ClamAV

Local sans ClamAV:

```dotenv
DOCUMENT_MALWARE_SCAN=optional
```

Production:

```dotenv
DOCUMENT_MALWARE_SCAN=required
CLAMAV_BINARY=/usr/bin/clamdscan
CLAMAV_TIMEOUT_SECONDS=30
```

Le binaire est appelé avec une liste d'arguments, sans shell. Un verdict infecté est audité et le
fichier n'entre jamais dans le stockage documentaire. Mettre à jour les signatures ClamAV et
surveiller les erreurs de scan.

## Contrôles quotidiens

```powershell
php artisan audit:verify-integrity
php artisan queue:monitor default:1000
php artisan schedule:list
php artisan migrate:status
```

Superviser `GET /api/health`, les erreurs 5xx, la latence p95/p99, le backlog de queue, l'espace
disque, les connexions MariaDB, les échecs de scan et les échecs d'authentification.

## Sauvegarde MariaDB avec XAMPP

Exemple local; ne pas écrire le mot de passe dans la ligne de commande ou dans Git:

```powershell
$env:MYSQL_PWD = '<mot-de-passe-lu-depuis-le-coffre>'
C:\xampp\mysql\bin\mysqldump.exe --host=127.0.0.1 --port=3306 --user=bts_backup --single-transaction --routines --triggers --events --hex-blob --default-character-set=utf8mb4 --result-file=C:\secure-backups\bts.sql bts_php_backend
Remove-Item Env:MYSQL_PWD
```

Sauvegarder séparément le disque `documents`. Chiffrer les deux sauvegardes, les copier hors site,
restreindre l'accès au compte backup et générer un SHA-256. Une sauvegarde n'est valide qu'après
restauration réussie sur une base isolée et vérification des FK, documents et totaux métier.

## Paramètres production minimaux

```dotenv
APP_ENV=production
APP_DEBUG=false
QUEUE_CONNECTION=database
DOCUMENT_MALWARE_SCAN=required
SANCTUM_EXPIRATION=60
```

Exiger HTTPS, HSTS au reverse proxy, cookies sécurisés lors du passage au BFF, clés longues et
uniques, CORS limité aux quatre domaines réels, stockage documentaire privé, rotation de secrets,
compte DB applicatif sans DDL et compte migration séparé.

XAMPP convient au développement. Il ne constitue pas une architecture de production bancaire:
utiliser des services supervisés, redondants, sauvegardés, patchés et journalisés.


## Santé, logs et alertes

| Sonde | Usage | Résultat attendu |
|---|---|---|
| `GET /api/health/live` | liveness : processus API vivant | 200, sans accès DB/cache/stockage |
| `GET /api/health` | readiness : monitor/load balancer | 200 si toutes les dépendances requises sont saines, sinon 503 |
| `php artisan operations:status --json` | diagnostic opérateur | même état, sans secrets ni chemins internes |

La readiness couvre MariaDB, cache, queue/backlog, worker, scheduler, jobs échoués, outbox, Reverb, stockage documentaire et espace disque local. Le disque devient dégradé sous `STORAGE_HEALTH_MIN_FREE_BYTES` (1 Go par défaut). Pour S3/compatible, la capacité relève du fournisseur et le contrôle est `provider_managed`.

Pour production, activer le canal JSON sans modifier les logs locaux lisibles :

```dotenv
LOG_CHANNEL=stack
LOG_STACK=json
LOG_JSON_PATH=/var/log/bts/bts.jsonl
LOG_LEVEL=info
```

Chaque réponse API écrit `api.request_completed` avec request/correlation ID, route modèle, statut, durée et identifiant d’acteur si disponible. Les jobs écrivent type, file, ID et durée. Les mots de passe, bearer tokens, OTP, secrets, e-mails, téléphones, adresses, en-têtes, payloads et contenus de documents sont redacted avant sérialisation. Alerter sur readiness 503, erreurs 5xx, worker/scheduler stales, backlog/échecs outbox, Reverb indisponible et disque bas.

## Procédure officielle backup et restore

Ne pas utiliser phpMyAdmin comme procédure officielle. Employer un compte MariaDB de backup avec les droits nécessaires et une destination hors projet :

```powershell
$env:MYSQL_PWD = '<secret depuis coffre, jamais Git>'
cd backend
.\scripts\backup-mariadb.ps1 -DatabaseName bts_php_backend -DatabaseUser bts_backup -Destination D:\bts-backups
Remove-Item Env:MYSQL_PWD
```

Le script produit un répertoire daté, dump transactionnel, routines/triggers/events/blobs, copie documents et `manifest.json` SHA-256 avec tailles et comptes représentatifs. La suppression est opt-in : `-Prune -RetentionDays 14`, limitée aux répertoires `bts-backup-YYYYMMDDTHHMMSSZ` de la destination choisie.

Tester chaque backup important sans jamais écraser la base normale :

```powershell
$env:MYSQL_PWD = '<secret depuis coffre, jamais Git>'
.\scripts\verify-mariadb-restore.ps1 -BackupPath D:\bts-backups\bts-backup-YYYYMMDDTHHMMSSZ -RestoreDatabase bts_restoreverify_YYYYMMDDHHMMSS -DatabaseUser bts_backup
Remove-Item Env:MYSQL_PWD
```

Le restore vérifie SHA-256, comptes de tables, `migrate:status`, chaîne d’audit et copie documentaire, puis détruit seulement la base jetable. Il refuse tout autre nom de base ; ajouter `-KeepRestoreDatabase` uniquement pendant un diagnostic. Objectifs à mesurer, pas garanties : backup quotidien, rétention locale 14 jours, copie chiffrée hors site et restore trimestriel.

Planifier le script au niveau OS (Windows Task Scheduler local ou systemd timer/cron en production), jamais depuis une requête HTTP. Le compte planifié doit avoir accès au coffre/secret store, à la destination chiffrée et au binaire MariaDB, mais aucun droit d’administration applicative.

## Runbooks d’incident et rétention

API dégradée : contrôler liveness puis readiness, noter `X-Request-Id`, chercher cet ID dans les logs JSON et suivre le composant degraded. MariaDB indisponible : vérifier service/port/disque, ne jamais lancer `migrate:fresh`, puis contrôler migrations, audit et readiness après retour.

Worker ou scheduler : confirmer heartbeat/backlog, redémarrer le processus supervisé, puis suivre outbox et jobs anciens. Reverb indisponible : les transactions restent durables et l’outbox réessaie ; rétablir Reverb/worker avant de relancer un job compris avec `queue:retry <uuid>`. Backup échoué ou disque bas : conserver le dernier backup valide, corriger destination/droits/espace et refaire backup + restore-test. Ne nettoyer que logs avec rétention, backups validés ou artefacts synthétiques : jamais audit, documents, demandes, messages, données banking ou jobs échoués sans politique approuvée.

`queue:prune-failed --hours=168` est la seule purge automatique existante : elle vise les échecs techniques, pas les preuves métier. Local/XAMPP accepte stockage et backup locaux ; CI utilise des bases jetables ; staging utilise des données synthétiques/masquées ; production impose TLS, comptes DB séparés, scanner requis, workers supervisés, backup chiffré hors site et accès opérateur restreint.

## Campagnes synthétiques de charge

Les campagnes Phase 5 sont des opérations de test, jamais des opérations de production. Sur Windows/XAMPP, exécuter `tools\synthetic-load-tester\setup.bat`, puis `gui.bat` ou le wrapper `run.ps1`. Le wrapper accepte seulement une URL loopback, crée une base `bts_load_<timestamp>_<pid>`, exige un marqueur signé, démarre des processus API/queue/scheduler/Reverb isolés et supprime uniquement cette base après export. `-KeepDatabase` est réservé au diagnostic de la base isolée.

Commencer par Smoke, puis 20 et 30. Surveiller CPU, mémoire, disque, connexions/attentes MariaDB, jobs, outbox et Reverb avant 50/100/500. Le Stop de l'interface demande à k6 de ne plus ouvrir de nouveaux workflows et laisse finir les transactions en cours; il ne tue ni MariaDB ni la base normale. Les rapports JSON/CSV/Markdown et le vérificateur métier sont sous `tools/synthetic-load-tester/results` et ne doivent contenir aucun mot de passe, OTP ou bearer token.

Le job CI `Synthetic API load smoke — 5 users` est volontairement léger. Les campagnes de stress complètes restent manuelles sur une machine isolée. Aucune mesure du serveur PHP intégré et du proxy local ne doit être transformée en SLA ou dimensionnement production.

## Exploitation analytics

Les dashboards Admin/Staff utilisent uniquement l'API Laravel agrégée. Ne jamais donner aux bundles
Next.js, à Metabase ou à un analyste le compte DB applicatif/migration. Le Staff est limité côté
serveur à son agence; l'Admin peut choisir une agence ou lire le global; Security Center ne reçoit
pas les métriques financières.

```dotenv
ANALYTICS_DEFAULT_DAYS=365
ANALYTICS_MAX_DAYS=730
ANALYTICS_EXPORT_MAX_ROWS=2500
```

Vérification opérateur non destructive :

```powershell
cd backend
php artisan analytics:verify-data-quality --json
php artisan analytics:benchmark --from=2025-08-24 --to=2026-08-23 --json
```

Planifier le contrôle qualité selon la volumétrie et alerter si `passed=false`; il ne corrige jamais
les données. Mesurer périodiquement le snapshot global et par agence, la latence API, les queries
lentes, les connexions/locks MariaDB et la latence OLTP. La fenêtre maximale est 730 jours et les
exports agrégés sont limités à 2 500 lignes, audités et rate-limités.

La migration d'indexes Phase 6 est réversible mais la création d'index/colonne générée sur une table
`audit_logs` volumineuse peut tenir un verrou metadata. En production: backup/restore validé, revue
`EXPLAIN`, estimation taille/temps, fenêtre approuvée ou stratégie online-DDL compatible, puis contrôle
`migrate:status`, qualité analytics et santé OLTP. Le test local moyen a pris environ 54 secondes;
ce temps ne prédit pas la production.

Metabase reste différé. Son déclencheur est un besoin BI récurrent approuvé ou une contention OLTP
mesurée. Avant déploiement: vues/read model minimisés, compte DB readonly séparé, idéalement
réplica/reporting DB, TLS, RBAC, sauvegarde de sa base applicative, patching et monitoring. Superset,
Meilisearch, Typesense, warehouse/ETL et migration S3 ne sont pas des dépendances actuelles.
