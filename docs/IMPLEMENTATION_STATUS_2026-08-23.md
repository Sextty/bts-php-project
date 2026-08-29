# BTS Bank — état réel de l'implémentation (23 août 2026)

## Résultat de ce lot

Ce dépôt est maintenant une plateforme de crédit bancaire nettement durcie et testable sur
MariaDB/XAMPP. Il ne doit toutefois pas être présenté comme un core banking certifié ni traiter
des fonds ou données réels sans infrastructure de production, revue comptable, pentest et
validation réglementaire indépendants.

## Contrôles implémentés

| Domaine | État | Réalisation |
|---|---:|---|
| Schéma workflow | Terminé | `credit_applications.status` est explicitement `VARCHAR(50)`; migration appliquée sur MariaDB 11.4/XAMPP. |
| Machine d'état | Terminé | Étapes non sautables, statut hors mass-assignment, contrôle acteur, écriture compare-and-set contre les décisions concurrentes. |
| Agences | Terminé | Employé opérationnel sans agence = zéro dossier; administration et sécurité restent globales; `staff:make` exige `--branch-id`. |
| Comptes suspendus | Terminé | Middleware client refuse tout compte non actif et supprime le jeton courant. |
| Rendez-vous | Terminé | Verrous transactionnels, proposition + transition atomiques et unicité `(application, tentative)`. |
| Documents | Terminé dans l'application | MIME réel + stockage privé existants; scan ClamAV gratuit avant stockage; mode `required` refuse si scanner absent; statut persisté. |
| Audit | Partiel fort | Nouveaux événements chaînés par HMAC, tête verrouillée, altération détectable, commande et contrôle quotidien. Un stockage WORM externe reste requis pour une preuve indépendante. |
| Notifications/realtime | Terminé | Broadcasts en queue après commit; audience des dossiers limitée à l'agence et aux rôles globaux; déduplication concurrente. |
| Sessions navigateur | Intermédiaire | Jetons déplacés de `localStorage` vers `sessionStorage`, anciens secrets migrés/supprimés, CSP et headers sur quatre portails. Cookie HttpOnly/BFF reste la cible production. |
| Santé/exploitation | Terminé dans l'application | Readiness DB/cache/storage/Reverb/queue, détection backlog, request IDs, vérification audit et prune planifiés. |
| CI | Renforcé | Backend, migrations SQLite, migration MariaDB 11.4 réelle, quatre portails, SC Vitest et audits de dépendances. |
| Données synthétiques | Déjà présent | Profils et commande `bts:generate-data`, tests d'intégrité et garde production. |
| Ledger | Prototype seulement | Double écriture/idempotence/maker-checker présents; usage monétaire réel toujours interdit avant spécification et certification comptables. |

## Validation exécutée

- Backend Laravel: **369 tests, 2 883 assertions**, zéro échec.
- Client, Staff, Admin, Security Center: lint, TypeScript et build Next.js 16 passent.
- Security Center: **6 tests Vitest** passent.
- MariaDB XAMPP: quatre migrations de durcissement appliquées; aucun doublon de tentative détecté.
- Audit: `php artisan audit:verify-integrity` valide la chaîne active.

## Restes obligatoires avant production bancaire

1. Déployer derrière TLS avec reverse proxy/WAF, secrets gérés hors `.env` local et comptes DB séparés.
2. Remplacer le bearer accessible au JavaScript par une architecture BFF/cookie HttpOnly avec CSRF et rotation.
3. Installer ClamAV et imposer `DOCUMENT_MALWARE_SCAN=required`; isoler le scanner et maintenir ses signatures.
4. Exporter audit/logs vers un stockage externe immuable; retirer UPDATE/DELETE au compte applicatif sur l'audit.
5. Mettre en place sauvegardes base + documents chiffrées, hors site, puis réussir des exercices de restauration RPO/RTO.
6. Ajouter MFA résistante au phishing et accès JIT pour admin/security/super-admin.
7. Isoler la télémétrie hôte/SC dans un agent dédié; ne pas exécuter de collecteur système dans l'API publique.
8. Faire pentest, threat model/ASVS L3, revue juridique (protection des données) et homologation réglementaire.
9. Faire certifier les règles comptables avant d'activer tout ledger pour de l'argent réel.

Ces éléments dépendent d'identités, serveurs, DNS, certificats, stockage hors site et décisions
d'organisation; ils ne peuvent pas être rendus réels uniquement par du code local dans ce dépôt.
