# BTS Bank — audit performance et passage à l'échelle

## Position

Le système est adapté à une démonstration et à un volume modéré. Il possède de bons mécanismes locaux (pagination sur plusieurs listes, index dashboard, compteur de numéro sous verrou, rendez-vous sérialisé par agence, insertion synthétique par batches). À 100K–1M clients et plusieurs millions d'événements, les voies synchrones, certains scans/exports et les bases de données utilisées comme queue/cache deviennent les contraintes principales.

Les priorités ci-dessous ne sont pas des promesses de gain chiffré : aucun benchmark de matériel cible n'a été exécuté.

## Points chauds observés

| Domaine | Constat | Risque à l'échelle | Priorité | Décision |
|---|---|---|---|---|
| Notifications | `notifyStaff()` itère tous les employés actifs, persiste une ligne et diffuse immédiatement par destinataire | O(nombre d'agents) par soumission, bruit/fuite et latence HTTP | P1 | router par agence/rôle, queue/outbox |
| Reverb | événements `ShouldBroadcastNow` | la réponse HTTP attend la diffusion ; panne/retry peut retarder le flux | P1 | jobs après commit, worker supervisé |
| Queue/cache | drivers `database` par défaut | contention `jobs/cache`, polling DB et croissance de tables | P1 après mesures | Valkey/Redis-compatible + Horizon si charge justifiée |
| Audit | dashboards font plusieurs `COUNT`, `GROUP BY`, `DISTINCT`; recherche `LIKE %term%`; `availableActions()` non borné | index difficilement utilisables, scans et pages lentes à millions de logs | P1 | index composites/time partition/archive, recherche dédiée seulement si nécessaire |
| Security Center | télémétrie PowerShell/netstat appelée dans la requête ; audit dashboard fait counts globaux | blocage CPU/processus API et scans DB synchrones | P1 | collecte asynchrone/cachée, métriques externes |
| Exports audit | export chargé en mémoire jusqu'à 1000 lignes puis renvoyé en JSON | payloads lourds, pas de job/fichier/expiration | P2 | export async streamé, stockage temporaire privé |
| Client applications | index client utilise `get()` de toutes les demandes de l'utilisateur | historique client volumineux → grosse réponse/mémoire | P2 | pagination curseur et projections minimales |
| Rendez-vous | `latestAppointment()` hors transaction; scans d'occupation par jour et requête de slots déjà proposés | concurrence sur un même dossier et scans quotidiens croissants | P1 | contraintes + verrou dossier/rendez-vous, index adaptés |
| Branch matching | calcule/charge les agences pour matching géographique | acceptable pour quelques agences, linéaire à réseau large | P3 | index géospatial/normalisation seulement au besoin |
| Documents | upload, vérification et certains appels IA dans le chemin de validation | upload/validation deviennent lents sous fichiers ou fournisseur lent | P1 | pipeline asynchrone de vérification/quarantaine |
| Ledger | solde dérivé des lignes et insertions transactionnelles | lecture de solde/statement devient coûteuse à très grande volumétrie sans projections sûres | P1 avant usage financier | stratégie de snapshots vérifiables, index, clôtures |

## Base de données et requêtes

### Index et bonnes bases présents

* `credit_applications`: index `user_id,status`, `status`, `created_at` et FK `branch_id`.
* `audit_logs`: index `user_id,action`, `created_at`, `credit_application_id`.
* `appointments`: index `branch_id,scheduled_date` et `credit_application_id,attempt_number`.
* `documents.disk_path`, identifiants de compte, références de ledger et idempotency keys ont une contrainte unique.
* Les numéros de demande utilisent `insertOrIgnore` + `lockForUpdate`, une solution solide à la concurrence locale.

### Lacunes à confirmer par `EXPLAIN ANALYZE` sur MariaDB

1. **Audit :** prévoir `created_at` comme axe de rétention/archivage et des indexes composites dérivés de vraies requêtes (par exemple date+action, date+staff). Ne pas indexer aveuglément `user_agent` ou faire croire que `%term%` devient rapide.
2. **Staff queue :** les filtres utiles sont typiquement `branch_id,status,created_at`; vérifier le plan de `scopeAccessibleToStaff()` avant ajout d'un index composite.
3. **Rendez-vous :** garantir d'abord l'unicité/correctness, puis mesurer un index `(branch_id, scheduled_date, status, scheduled_time)` si le plan le justifie.
4. **Notifications :** prévoir index de boîte (`notifiable_type,notifiable_id,read_at,created_at`) et purge/archivage. Le modèle polymorphique et les charges `all unread` peuvent grossir vite.
5. **Messages et documents :** une demande peut accumuler fichiers/messages ; utiliser pagination de relation et index `(credit_application_id,created_at)` lorsque les mesures le demandent.
6. **Ledger :** les statements ont besoin d'index `(bank_account_id,created_at,id)` ; les agrégats doivent être testés séparément avant snapshots/caches.

## Concurrence et cohérence qui affectent aussi le débit

* La réservation de créneau verrouille l'agence, donc deux demandes de la même agence se séquencent. À faible capacité, c'est cohérent ; à forte cadence, un modèle de capacité pré-calculée ou de créneaux matérialisés pourra réduire le verrou global.
* En revanche, l'acceptation/rejet du même rendez-vous n'acquiert pas un verrou du rendez-vous/dossier et il n'y a pas de contrainte DB d'unicité de place active/numéro d'essai. La priorité est la correction avant l'optimisation.
* Les notifications et diffusions sont déclenchées au milieu des flux métier. Elles doivent être émises via outbox/queue après commit : cela évite de ralentir ou rendre ambiguë la transaction principale.
* Les transactions de génération synthétique sont correctement chunkées ; conserver des batches bornés et mesurer locks, redo/binlog et taille des indexes sur le serveur cible.

## Objectifs de capacité par étape

| Taille | Ce qui doit être vrai avant de l'annoncer | Charge à mesurer |
|---|---|---|
| 100K clients | MariaDB reproductible, pagination partout, jobs surveillés, backups/restores testés | login/OTP, création/dossier, recherche staff, dashboard audit |
| 500K clients | audit/notifications retenus ou archivés, queue hors DB ou justifiée, requêtes expliquées | pics de soumission, revue agence, websocket, documents concurrents |
| 1M+ clients | partitions/archives selon rétention, capacité disque/IOPS, plan de reprise, lecture replica/BI séparée si démontrée | milliers de VU synthétiques, saturation workers, temps de restauration |

## Charge synthétique et future architecture de test

Le générateur actuel est une très bonne **préparation de base** : profils jusqu'à 1M, seed, checkpoints, `flock`, foreign keys activées, insertion brute par lots et transactions bornées. Il doit être lancé seulement sur une instance isolée, avec projections de taille disque et sauvegarde préalable.

Pour tester le système réel, utiliser un projet k6 séparé : scénarios d'inscription/login OTP contrôlé, lecture dashboard, création/édition/soumission de dossier, revue staff, décision admin, documents et WebSocket. Paramètres versionnés : `customers`, `applications_per_customer`, `vus`, `duration`, `think_time`, `seed`, distribution d'agences/statuts. k6 est un moteur de charge libre mais sous AGPLv3 : validation licence nécessaire. Sa documentation présente des scénarios et des métriques de charge ; aucune exécution n'est recommandée contre production ([k6](https://grafana.com/docs/k6/latest/)).

Exemple de campagne future isolée : 30 comptes synthétiques, 30 workflows concurrents, une demande chacun, seed fixe, base MariaDB éphémère, Reverb/worker/Valkey actifs, métriques Prometheus et logs centralisés. Les assertions doivent couvrir taux d'erreur, latence, aucune double décision/écriture, pas seulement le débit.

## Observabilité ciblée

1. **Phase utile maintenant :** logs JSON avec `request_id`, métriques d'API/DB/jobs, health enrichi, alertes simples (erreurs, queue backlog, disque, sauvegarde ratée).
2. **Laravel Pulse :** pertinent pour visualiser endpoints/jobs lents ; la documentation indique qu'il supporte MySQL/MariaDB/PostgreSQL et conseille une connexion DB séparée ou Redis ingest à fort trafic ([Pulse](https://laravel.com/docs/12.x/pulse)). Ne pas l'utiliser comme remplacement de logs d'audit.
3. **Prometheus + Grafana :** adaptés aux séries numériques/alertes, Grafana aux dashboards. Prometheus fournit serveur de métriques, exporters et Alertmanager ([Prometheus](https://prometheus.io/docs/introduction/overview/)); Grafana OSS peut visualiser métriques/logs/traces ([Grafana](https://grafana.com/docs/grafana/latest/)).
4. **Loki :** approprié aux logs techniques centralisés, pas au journal légal primaire. Il indexe les labels et compresse les lignes, mais exige une politique de labels/rétention soigneuse ([Loki](https://grafana.com/docs/loki/latest/)).
5. **OpenTelemetry :** utile quand l'API/worker/realtime deviennent distribués ; PHP prend traces, métriques et logs en charge stable. À reporter si le déploiement reste mono-machine ([OpenTelemetry PHP](https://opentelemetry.io/docs/languages/php/)).

## Recommandations par phase

### P1

* déplacer notifications, email, vérification documentaire et broadcast après commit vers des jobs;
* introduire métriques de queue, requête et error rate; mesurer avant changement d'infrastructure;
* corriger les verrous/contraintes de rendez-vous et le drift de schéma;
* paginer la liste client et limiter projections/relations;
* définir la rétention de logs, notifications et fichiers.

### P2

* Valkey/Redis-compatible pour queue/cache/realtime, plus Horizon quand les workers deviennent critiques;
* pages de staff/audit cursor pagination, exports asynchrones et stockage temporaire;
* tests `EXPLAIN`, budgets de requêtes et jobs de purge/archivage;
* benchmark de snapshots ledger et BI sur réplica/warehouse plutôt que la primaire.

### P3 / à éviter sans preuve

* microservices, Kubernetes, Kafka, Elasticsearch/search cluster, traces distribuées complètes, index géospatiaux et replica DB : tous ajoutent une charge opérationnelle. Les introduire seulement quand un benchmark ou une obligation de résilience les rend nécessaires.

## Mesures de sortie obligatoires

* baseline MariaDB : latence p50/p95/p99, IOPS, locks/deadlocks, buffer pool, slow query log;
* API : taux 4xx/5xx, temps endpoint, uploads, taille réponse, rate-limit;
* queue : backlog, âge max, retries, failed jobs, durée de job;
* realtime : connexions, drops, délai publish→réception;
* stockage : volume documents, objets en quarantaine, taux de scan, croissance audit/notifications;
* reprise : RPO/RTO observés par sauvegarde et restauration répétée.
