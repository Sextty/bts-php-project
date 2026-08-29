# BTS Bank - diagrammes verifies pour le rapport

Ce dossier contient les sources Mermaid editables et les rendus PNG utilises par le rapport academique. La verification a ete realisee contre le code courant, les 51 migrations Laravel, les routes, la machine d'etats et l'audit final du 24 aout 2026.

La plateforme est decrite comme une plateforme avancee de gestion de demandes de credit avec un pilote ledger. Les diagrammes ne pretendent ni affiliation institutionnelle officielle, ni certification bancaire, ni disponibilite en production.

## Inventaire actif

| No | Source editable | Rendu PNG | Sujet | Rapport |
|---:|---|---|---|---|
| 01 | `01_system_architecture.mmd` | `img/01_system_architecture.png` | Architecture globale a quatre portails | Chapitre 2 |
| 02 | `02_entity_relationship_diagram.mmd` | `img/02_entity_relationship_diagram.png` | Modele relationnel courant | Chapitre 2 |
| 03 | `03_use_case_diagram.mmd` | `img/03_use_case_diagram.png` | Cas d'utilisation par acteur | Chapitre 1 |
| 04 | `04_state_transition_diagram.mmd` | `img/04_state_transition_diagram.png` | Machine d'etats a 17 statuts | Chapitre 2 |
| 05 | `05_sequence_auth_onboarding.mmd` | `img/05_sequence_auth_onboarding.png` | Inscription, connexion, OTP et Google | Chapitre 2 |
| 06 | `06_sequence_credit_application_ai.mmd` | `img/06_sequence_credit_application_ai.png` | Saisie, documents, validations et soumission | Chapitre 2 |
| 07 | `07_sequence_dual_approval_workflow.mmd` | `img/07_sequence_dual_approval_workflow.png` | Double decision et rendez-vous | Chapitre 2 |
| 08 | `08_sequence_realtime_chat_websockets.mmd` | `img/08_sequence_realtime_chat_websockets.png` | Rapport temps reel, outbox et Reverb | Chapitre 3 |
| 09 | `09_class_diagram_backend.mmd` | `img/09_class_diagram_backend.png` | Classes et services principaux | Chapitre 2 |
| 10 | `10_deployment_infrastructure.mmd` | `img/10_deployment_infrastructure.png` | Topologie locale observee et limites production | Chapitre 3 |
| 11 | `11_document_security_pipeline.mmd` | `img/11_document_security_pipeline.png` | Pipeline de securite documentaire | Chapitre 5 |
| 12 | `12_async_notifications_observability.mmd` | `img/12_async_notifications_observability.png` | Queue, outbox, notifications et sante | Chapitre 3 |
| 13 | `13_synthetic_load_analytics.mmd` | `img/13_synthetic_load_analytics.png` | Donnees synthetiques, charge et analytics | Chapitre 4 |
| 14 | `14_ci_cd_backup_restore.mmd` | `img/14_ci_cd_backup_restore.png` | CI, limites de release et preuve backup/restore | Chapitres 4 et 5 |

## Points de vigilance representes

- Le diagramme d'etats montre explicitement la transition defectueuse depuis `STAFF_APPROVED` vers le cycle de rendez-vous (finding F-001).
- Le diagramme temps reel distingue le canal rapport correctement isole du canal `staff` partage vise par F-002.
- Le pipeline documentaire distingue detection de malware, analyse IA consultative et controle humain.
- La topologie de deploiement decrit l'environnement local XAMPP; les composants HA, MFA privilegiee, sauvegarde hors site et supervision centralisee restent des exigences futures.
- Le ledger est presente comme un pilote additif, non comme un coeur bancaire complet.

## Validation Mermaid

Les 14 sources actives sont compatibles avec Mermaid CLI 11.12.0 et ont ete rendues sur fond blanc en PNG haute resolution. Les fichiers historiques de `mm/` et les anciens rendus ranges sous `img/legacy/` sont exclus du rapport.
