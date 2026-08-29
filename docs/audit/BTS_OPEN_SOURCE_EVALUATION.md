# BTS Bank — évaluation open source et détection de doublons

## Méthode

Cette matrice compare le besoin démontré, l'équivalent BTS actuel, le coût opérationnel, l'activité/documentation et la licence. « USE » signifie candidat validé pour la prochaine phase, pas installation autorisée dans cette phase. Les licences doivent être validées juridiquement avant livraison, particulièrement AGPL/GPL et licences source-available.

Principes : **garder** le domaine qui fonctionne (Sanctum, Reverb, permissions custom, state machine, générateur), **améliorer** ses garanties, puis ajouter une brique seulement si elle résout un déficit prouvé.

## Synthèse des décisions

| Statut | Projet | Décision |
|---|---|---|
| KEEP | Sanctum, Reverb, permissions custom, audit métier custom, générateur synthétique, PHPUnit | ne pas remplacer ; corriger les écarts d'intégrité/sécurité |
| USE / ADAPT P1 | Larastan, Playwright existant, Gitleaks, Syft, ASVS/Cheat Sheets, ClamAV, Valkey (si queue/cache justifiés), MariaDB Backup | réduisent des risques prouvés |
| STUDY P2/P3 | Pulse, Horizon, k6, ZAP, Prometheus/Grafana/Loki, OpenTelemetry, Superset/Metabase, SeaweedFS, Percona Toolkit | adopter seulement avec critères de volume/ops |
| SKIP maintenant | Spatie Permission, Spatie Activitylog, Pest, Meilisearch, Typesense, Redis, MinIO, Sentry self-hosted, microservices/Kafka | doublon, licence/ops ou besoin absent |

## Matrice — Laravel et domaine

| Projet | Purpose | BTS problem | Current equivalent | Benefit | Difficulty | Risk | Maintenance | License | Priority | Decision |
|---|---|---|---|---|---|---|---|---|---|---|
| Laravel Pulse | métriques applicatives | endpoints/jobs lents invisibles | health + dashboard maison | visibilité simple de perf | M | M (données sensibles/DB) | Active, first-party | MIT | P2 | STUDY : DB dédiée/Redis ingest si charge |
| Laravel Telescope | debug requêtes/jobs | diagnostic local/staging | logs/Pail | investigation détaillée | L | M (PII/secrets) | Active, first-party | MIT | P2 | ADAPT dev/staging seulement, jamais collecte large prod |
| Laravel Horizon | supervision queues Redis-compatible | queue database sans supervision | worker Laravel manuel | workers, retries, métriques | M | M (nouvelle infra) | Active, first-party | MIT | P1 | USE quand queue async/Valkey activés |
| Laravel Reverb | WebSocket self-hosted | realtime existant | `laravel/reverb` ^1.11 | aucun remplacement nécessaire | L | L | Active, first-party | MIT | P1 | KEEP/EXTEND : async broadcasts, monitoring |
| Laravel Sanctum | auth tokens/session | auth API existante | Sanctum ^4.3 | aucun remplacement | L | L | Active, first-party | MIT | P1 | KEEP ; changer stockage navigateur/flux session |
| Larastan | analyse statique Laravel/PHPStan | erreurs type/relations/config non vues | TypeScript seulement ; aucun PHP static analysis | détection précoce | L/M | L | Active | MIT | P1 | USE progressivement avec baseline |
| Pest | framework tests PHP | ergonomie/BDD | PHPUnit 11 riche | lisibilité possible | M | M (migration massive) | Active | MIT | P2 | SKIP maintenant ; PHPUnit n'est pas le problème |
| Spatie Permission | RBAC persistence | permissions/roles | `PermissionRegistry` + middleware custom | peu de gain ; migration données/semantics | H | H | Active | MIT | P3 | SKIP : garder registre et durcir tests/branch scope |
| Spatie Activitylog | activity log générique | audit | `AuditLogService` métier | ne résout pas l'inviolabilité/rétention | M/H | H (replacement audit) | Active | MIT | P2 | SKIP : améliorer audit BTS, s'inspirer sans remplacer |
| Spatie Laravel Data | DTO/validation mapping | payloads/API types | Form Requests + réponses custom | cohérence possible | M | L | Active | MIT | P3 | STUDY seulement si mapping devient douloureux |
| Symfony Workflow | machine d'état générique | états crédit | machine custom exhaustive | pas de gain immédiat, migration risquée | H | H | Active | MIT | P3 | SKIP |

Laravel Pulse est pertinent après instrumentation : il peut stocker ses données sur MySQL/MariaDB/PostgreSQL et recommande une connexion dédiée ou Redis ingest sous trafic ([Pulse](https://laravel.com/docs/12.x/pulse)). Telescope doit filtrer strictement les entrées en production ([Telescope](https://laravel.com/docs/12.x/telescope)). Horizon fournit des stratégies de balancing et reste conditionné à une queue Redis-compatible ([Horizon](https://laravel.com/docs/12.x/horizon)).

## Matrice — test, assurance et sécurité

| Projet | Purpose | BTS problem | Current equivalent | Benefit | Difficulty | Risk | Maintenance | License | Priority | Decision |
|---|---|---|---|---|---|---|---|---|---|---|
| Grafana k6 | charge/scénarios API | generator ne teste pas HTTP/realtime | générateur DB synthétique | charge reproductible et assertions | M | M (AGPL/ops) | Active | AGPL-3.0 | P2 | ADAPT dans repo de load tests isolé |
| Playwright | E2E browser | specs existent hors CI | `e2e/` existant | parcours critique réel | M | M (fixtures/env) | Active | Apache-2.0 | P1 | EXTEND/USE : base éphémère, SC, CI |
| OWASP ZAP | DAST | pas de DAST automatisé | aucun | scan passif/actif contrôlé | M | M (faux positifs) | Active | Apache-2.0 | P2 | ADAPT en environnement isolé |
| OWASP ASVS | standard de vérification | aucune baseline financière | audit maison | exigences vérifiables L3 | L | L | Active | CC BY-SA 4.0 | P1 | USE comme backlog de contrôles |
| OWASP Cheat Sheet Series | guides pratiques | hardening ciblé | connaissances ad hoc | décisions reproductibles | L | L | Active | CC BY-SA 4.0 | P1 | USE comme référence |
| Gitleaks | détection de secrets Git | pas de scan de secrets | `.gitignore` seulement | scan historique/PR et baseline | L | L | Active | MIT | P1 | USE |
| TruffleHog | secrets + vérification | même problème | aucun | complément utile à terme | M | M (AGPL, bruit) | Active | AGPL-3.0 | P2 | STUDY ; ne pas doubler Gitleaks immédiatement |
| Syft | SBOM | pas d'inventaire release | Composer/npm lockfiles | CycloneDX/SPDX traçable | L | L | Active | Apache-2.0 | P1 | USE |
| Grype | vulnérabilités SBOM/images | audit dépendances minimal | composer/npm audit | scan OS/images/SBOM | M | M (feed/triage) | Active | Apache-2.0 | P2 | STUDY après SBOM |
| ClamAV | scan fichiers uploadés | pas de malware barrier | MIME/extension seulement | quarantaine/scan libre | M | M (faux négatifs/opérations) | Active | GPL-2.0 | P1 | USE derrière daemon privé + signatures |

Playwright est déjà le meilleur choix pour les quatre portails : consolider ce qui existe au lieu de le remplacer. ZAP Automation Framework permet de définir environnement, authentification et jobs dans un fichier YAML ([ZAP](https://www.zaproxy.org/docs/automate/automation-framework/)). Gitleaks propose scans Git/répertoire/stdin et baseline ([Gitleaks](https://github.com/gitleaks/gitleaks)). ClamAV fournit un daemon multi-thread et base de signatures ; son socket ne doit jamais être exposé ([ClamAV](https://docs.clamav.net/manual/Usage/Scanning.html?highlight=false+positive)).

## Matrice — observabilité et exploitation

| Projet | Purpose | BTS problem | Current equivalent | Benefit | Difficulty | Risk | Maintenance | License | Priority | Decision |
|---|---|---|---|---|---|---|---|---|---|---|
| OpenTelemetry | traces/métriques/logs standard | corrélation interservices future | request_id et logs locaux | portable, vendor-neutral | M/H | M (PII/coût) | Active | Apache-2.0 | P3 | STUDY après métriques de base |
| Prometheus | métriques/alertes | aucun monitoring standard | dashboard/app health | alertes et séries temporelles | M | M (ops) | Active | Apache-2.0 | P2 | ADAPT si supervision serveur/workers nécessaire |
| Grafana OSS | dashboards/alertes | vues techniques limitées | dashboard BTS métier | métriques/logs/traces centralisés | M | M (RBAC) | Active | AGPL-3.0 | P2 | ADAPT avec Prometheus, RBAC strict |
| Grafana Loki | agrégation logs | logs locaux non centralisés | Pail/fichiers | recherche/retention logs techniques | M | M (PII) | Active | AGPL-3.0 | P2 | ADAPT, jamais source d'audit légal |
| Sentry self-hosted | erreurs/performance | error tracking absent | logs Laravel | UX/stack traces | H | H (licence/forte stack) | Active | FSL/source-available, à vérifier | P3 | SKIP : pas « open source libre » simple |
| Laravel Pail | logs locaux | dev diagnostics | déjà dépendance dev | utile en développement | L | L | Active | MIT | P2 | KEEP local seulement |
| Node Exporter/mysqld exporter | host/DB metrics | métriques infra absentes | SC emulation locale | métriques standard | M | M | Active | Apache-2.0 | P2 | STUDY avec Prometheus |

OpenTelemetry PHP déclare traces, métriques et logs stables, mais exige instrumentation/exporter : ne pas l'introduire avant d'avoir défini les signaux à conserver ([OTel PHP](https://opentelemetry.io/docs/languages/php/)). Prometheus collecte les séries et délègue les alertes à Alertmanager ([Prometheus](https://prometheus.io/docs/introduction/overview/)); Grafana OSS visualise métriques, logs et traces ([Grafana](https://grafana.com/docs/grafana/latest/)).

## Matrice — analytics, recherche et stockage

| Projet | Purpose | BTS problem | Current equivalent | Benefit | Difficulty | Risk | Maintenance | License | Priority | Decision |
|---|---|---|---|---|---|---|---|---|---|---|
| Apache Superset | BI data exploration | dashboards analytiques futurs | dashboards Laravel/Next | BI riche sur source lecture | H | H (données sensibles/RBAC) | Active | Apache-2.0 | P3 | STUDY ; lecture seule/réplica d'abord |
| Metabase OSS | BI simple | idem | aucun BI externe | démarrage plus simple | M | M (AGPL/RBAC) | Active | AGPL-3.0 | P3 | STUDY, choisir un seul outil après cas d'usage |
| Meilisearch CE | recherche plein texte | recherches SQL `%term%` futures | MariaDB/LIKE | UX rapide quand indexes DB insuffisants | M | M (index PII/synchro) | Active | MIT | P3 | SKIP maintenant ; prouver le besoin |
| Typesense | recherche | idem | MariaDB | alternative search | M | M (GPL) | Active | GPL-3.0 | P3 | SKIP : Meilisearch plus compatible si besoin |
| MinIO | S3 object storage | fichiers locaux non HA futurs | disk `documents`, driver S3 prêt | S3-compatible | M/H | M/H (licence/distribution/ops) | à revalider | AGPL-3.0/source delivery à revalider | P3 | SKIP maintenant |
| SeaweedFS | S3/files/distributed storage | S3-compatible futur | driver S3 prêt | licence Apache, scalabilité | H | H (cluster) | Active | Apache-2.0 | P3 | STUDY seulement si volume/HA prouvé |
| Ceph | object/block storage | très forte résilience | aucun | robuste mais lourd | H | H | Active | LGPL-2.1 | P3 | SKIP : surdimensionné |

Superset est une plateforme Apache de data exploration/visualisation, mais nécessite une gouvernance de source lecture et de droits ([Superset](https://superset.apache.org/)). Metabase peut être self-hosted mais le produit doit aussi recevoir sauvegardes/patches/RBAC ([Metabase](https://www.metabase.com/docs/latest/)). Meilisearch Community Edition est MIT et adaptée seulement quand MariaDB indexé ne suffit plus ([Meilisearch](https://github.com/meilisearch/meilisearch)). SeaweedFS est Apache-2.0 mais apporte un cluster à opérer ([SeaweedFS](https://github.com/seaweedfs/seaweedfs)).

## Matrice — infrastructure et MariaDB

| Projet | Purpose | BTS problem | Current equivalent | Benefit | Difficulty | Risk | Maintenance | License | Priority | Decision |
|---|---|---|---|---|---|---|---|---|---|---|
| Valkey | cache/queue/pubsub Redis-compatible | cache/queue database et workers non supervisés | Laravel database drivers | licence permissive, latence, Horizon compatible via protocole Redis | M | M | Active | BSD-3-Clause | P1 | ADAPT quand jobs async activés |
| Redis 8 | cache/queue | même besoin | aucun Redis | techniquement adéquat | M | M (tri-licence AGPL/RSAL/SSPL) | Active | choix AGPL/RSAL/SSPL | P2 | SKIP : Valkey plus simple juridiquement |
| MariaDB Backup | backup physique online | pas de stratégie de restauration prouvée | dump local implicite | backup/prepare/restores cohérents | M | M | Active | GPL-2.0 | P1 | USE dans plan de backup/restore |
| Percona Toolkit | analyse MySQL | plans/locks à haute charge | aucun | diagnostics ciblés | M | M (compatibilité) | Active | GPL-2.0 | P2 | STUDY après baseline MariaDB |
| PMM | monitoring DB | métriques DB insuffisantes | aucun | observabilité query/DB | H | H (stack) | Active | AGPL-3.0 | P3 | STUDY ; exporters Prometheus d'abord |
| OpenBao | secrets management | secrets env/deploy à maturer | `.env` | coffre open source | H | H (ops/HA) | Active | MPL-2.0 | P3 | STUDY après gestion de secrets simple et rôles DB |

Valkey est le choix recommandé si la charge justifie une clé-valeur externe : son dépôt est BSD-3-Clause. Redis 8 propose trois licences, dont AGPL mais aussi RSAL/SSPL non-OSI ; choisir Valkey simplifie la politique ([Redis licensing](https://redis.io/legal/licenses/), [Valkey license](https://github.com/valkey-io/valkey/blob/unstable/LICENSES/BSD-3-Clause.txt)). MariaDB Backup supporte les sauvegardes physiques et le chiffrement à repos ([MariaDB Backup](https://mariadb.com/docs/server/server-usage/backup-and-restore/mariadb-backup/mariadb-backup-overview)).

## Détection explicite des doublons BTS

| Fonction | BTS actuel | Décision | Justification |
|---|---|---|---|
| Auth API | Sanctum + modèles séparés + OTP | KEEP / IMPROVE | remplacer serait coûteux ; renforcer session browser/MFA |
| Permissions | registry custom, middleware, policies, branch scopes | KEEP / IMPROVE | Spatie ne résout ni branches ni règles de domaine ; tester et deny-by-default |
| Audit | service/modele custom lié crédit | KEEP / EXTEND | activité générique Spatie ne rend pas les preuves infalsifiables |
| Realtime | Reverb + channels privés | KEEP / EXTEND | déplacer broadcasts vers queue/outbox |
| Workflow | machine crédit custom | KEEP / IMPROVE | le workflow est spécifique ; retirer sauts/mass assignment et couvrir MariaDB |
| Documents | storage interface local/S3, validation, AI consultative | KEEP / EXTEND | ajouter quarantine/scan plutôt que réécrire |
| Synthétique | command chunks/checkpoints/seed | KEEP / EXTEND | ajouter k6 pour le trafic, pas remplacer la génération DB |
| Tests backend | PHPUnit + Feature/domain tests | KEEP / EXTEND | ajouter Larastan/MariaDB/E2E/UI, pas migration Pest urgente |
| BI/search | aucun besoin mesuré | SKIP | MariaDB/indexes et dashboards BTS d'abord |

## Ordre recommandé d'adoption

1. **P0/P1 sans surcharge inutile :** ASVS, Gitleaks, Syft, Larastan, MariaDB integration test, Playwright CI, ClamAV/quarantaine, backup/restore.
2. **Quand les chemins deviennent asynchrones :** Valkey + Horizon, monitoring de jobs et notifications/outbox.
3. **Après preuve métrique :** Pulse, Prometheus/Grafana/Loki, k6, ZAP en environnement isolé.
4. **Seulement après un besoin métier documenté :** BI, moteur de recherche, object storage cluster, OpenTelemetry complet, coffre HA.
