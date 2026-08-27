<?php

namespace App\Services\Osquery;

class OsqueryPresets
{
    /**
     * Curated security audit and system inspection queries.
     *
     * @return array<int, array{
     *     category: string,
     *     title: string,
     *     description: string,
     *     risk_level: 'info'|'warning'|'critical',
     *     icon: string,
     *     queries: array<int, array{
     *         name: string,
     *         description: string,
     *         sql: string,
     *         recommended_interval?: string
     *     }>
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'category' => 'audit_journal_sql',
                'title' => 'Journal d\'Audit & Événements de Sécurité',
                'description' => 'Interrogation SQL directe des journaux d\'audit et des tentatives de connexion du portail.',
                'risk_level' => 'critical',
                'icon' => 'Activity',
                'queries' => [
                    [
                        'name' => 'Derniers Événements du Journal d\'Audit',
                        'description' => 'Affiche les 50 dernières actions enregistrées avec acteur, IP et dossier.',
                        'sql' => 'SELECT id, action, actor_name, actor_type, ip_address, created_at FROM audit_logs ORDER BY id DESC LIMIT 50;',
                    ],
                    [
                        'name' => 'Événements d\'Authentification & Sécurité',
                        'description' => 'Filtre les connexions, vérifications OTP, et requêtes de sécurité.',
                        'sql' => 'SELECT id, action, actor_name, ip_address, created_at FROM security_events ORDER BY id DESC LIMIT 30;',
                    ],
                    [
                        'name' => 'Comptage des Événements par Type',
                        'description' => 'Nombre total d\'événements enregistrés dans l\'audit trail.',
                        'sql' => 'SELECT COUNT(*) FROM audit_logs;',
                    ],
                ],
            ],
            [
                'category' => 'network_security',
                'title' => 'Sécurité Réseau & Ports d\'Écoute',
                'description' => 'Inspection des sockets ouverts, serveurs en écoute et flux réseau entrants.',
                'risk_level' => 'critical',
                'icon' => 'Network',
                'queries' => [
                    [
                        'name' => 'Ports TCP Ouverts & Processus Associés',
                        'description' => 'Détecte tous les ports d\'écoute actifs sur le serveur avec les noms des processus et PIDs.',
                        'sql' => 'SELECT pid, port, protocol, address, process_name, state FROM listening_ports WHERE port != 0 ORDER BY port ASC;',
                    ],
                    [
                        'name' => 'Sockets & Connexions Ouvertes',
                        'description' => 'Liste des sockets réseaux et correspondances locales/distantes.',
                        'sql' => 'SELECT pid, protocol, local_address, local_port, state FROM process_open_sockets ORDER BY local_port ASC;',
                    ],
                    [
                        'name' => 'Interfaces & Cartes Réseau Actives',
                        'description' => 'Identifie les cartes Wi-Fi, Ethernet, passerelles par défaut et adresses MAC physiques.',
                        'sql' => 'SELECT interface, mac, type, status, is_primary, ipv4, gateway, speed FROM interface_details ORDER BY is_primary DESC;',
                    ],
                    [
                        'name' => 'Table de Routage & Passerelles IP',
                        'description' => 'Inspection de la table de routage IP du serveur.',
                        'sql' => 'SELECT destination, netmask, gateway, interface, metric FROM routes;',
                    ],
                    [
                        'name' => 'Cache ARP & Adresses Physiques',
                        'description' => 'Table des résolutions IP / MAC sur le réseau local.',
                        'sql' => 'SELECT address, mac, interface, permanent FROM arp_cache;',
                    ],
                    [
                        'name' => 'Table de Résolution Locale (/etc/hosts)',
                        'description' => 'Vérifie les redirections d\'hôtes locales pour détecter d\'éventuels détournements DNS.',
                        'sql' => 'SELECT address, hostnames FROM etc_hosts;',
                    ],
                ],
            ],
            [
                'category' => 'system_hardware',
                'title' => 'Audit Système, Matériel & Noyau',
                'description' => 'Spécifications de la machine hôte, processeur, mémoire vive et version du système.',
                'risk_level' => 'info',
                'icon' => 'Cpu',
                'queries' => [
                    [
                        'name' => 'Identité Matérielle & Spécifications Hôte',
                        'description' => 'Nom d\'hôte, modèle de machine, processeur, cœurs logiques et mémoire vive physique.',
                        'sql' => 'SELECT hostname, hardware_vendor, hardware_model, cpu_brand, cpu_physical_cores, cpu_logical_cores, physical_memory, hardware_serial FROM system_info;',
                    ],
                    [
                        'name' => 'Version du Système d\'Exploitation (OS)',
                        'description' => 'Nom complet de l\'OS, numéro de build, architecture et plateforme.',
                        'sql' => 'SELECT name, version, build, arch, platform, codename FROM os_version;',
                    ],
                    [
                        'name' => 'Utilisation & Disponibilité Mémoire RAM',
                        'description' => 'Mémoire vive totale, mémoire libre, buffers et espace d\'échange (swap).',
                        'sql' => 'SELECT memory_total, memory_free, memory_available, cached, swap_total, swap_free FROM memory_info;',
                    ],
                    [
                        'name' => 'Temps de Fonctionnement & Disponibilité (Uptime)',
                        'description' => 'Durée écoulée depuis le dernier démarrage du serveur.',
                        'sql' => 'SELECT days, hours, minutes, seconds, total_seconds FROM uptime;',
                    ],
                    [
                        'name' => 'Informations sur le Noyau (Kernel)',
                        'description' => 'Version exacte du noyau et chemin du binaire système.',
                        'sql' => 'SELECT version, path, arguments, device FROM kernel_info;',
                    ],
                    [
                        'name' => 'BIOS & Firmware de la Plateforme',
                        'description' => 'Constructeur, version du firmware UEFI/BIOS et date de release.',
                        'sql' => 'SELECT vendor, version, date, size, extra FROM platform_info;',
                    ],
                ],
            ],
            [
                'category' => 'processes_perf',
                'title' => 'Surveillance des Processus & Performance',
                'description' => 'Détection des processus gourmands, démons actifs et consommation mémoire.',
                'risk_level' => 'warning',
                'icon' => 'Activity',
                'queries' => [
                    [
                        'name' => 'Top Processus Actifs & Consommation RAM',
                        'description' => 'Liste des processus en cours d\'exécution triés par mémoire résidente occupée.',
                        'sql' => 'SELECT pid, name, resident_size, threads, state, path FROM processes ORDER BY pid ASC LIMIT 25;',
                    ],
                    [
                        'name' => 'Démons Systèmes & Services d\'Arrière-Plan',
                        'description' => 'Surveille les processus racines et services de fond du système.',
                        'sql' => 'SELECT pid, name, parent, threads, path FROM processes WHERE parent <= 4;',
                    ],
                ],
            ],
            [
                'category' => 'users_access',
                'title' => 'Sessions & Comptes Utilisateurs',
                'description' => 'Vérification des utilisateurs connectés, sessions distantes et privilèges administratifs.',
                'risk_level' => 'critical',
                'icon' => 'Users',
                'queries' => [
                    [
                        'name' => 'Utilisateurs Actuellement Connectés',
                        'description' => 'Sessions console interactives et accès administratifs distants.',
                        'sql' => 'SELECT user, type, tty, host, time, pid, status FROM logged_in_users;',
                    ],
                    [
                        'name' => 'Inventaire des Comptes Locaux & Système',
                        'description' => 'Liste des identifiants système, administrateurs et répertoires de base.',
                        'sql' => 'SELECT uid, gid, username, description, directory, shell, type FROM users ORDER BY uid ASC;',
                    ],
                    [
                        'name' => 'Groupes d\'Utilisateurs & Droits',
                        'description' => 'Groupes de sécurité et administrateurs du système.',
                        'sql' => 'SELECT gid, groupname, comment FROM groups ORDER BY gid ASC;',
                    ],
                ],
            ],
            [
                'category' => 'storage_disks',
                'title' => 'Stockage, Partitions & Certificats',
                'description' => 'Taux d\'occupation des volumes disques, points de montage et certificats SSL.',
                'risk_level' => 'warning',
                'icon' => 'HardDrive',
                'queries' => [
                    [
                        'name' => 'Espace Disponible sur les Disques & Partitions',
                        'description' => 'Capacité totale, espace libre, pourcentage d\'utilisation et statut de santé des volumes.',
                        'sql' => 'SELECT device, path, type, total_space, free_space, used_space, percent_used, status FROM disk_info ORDER BY percent_used DESC;',
                    ],
                    [
                        'name' => 'Points de Montage du Système de Fichiers',
                        'description' => 'Volumes montés et blocs de données alloués.',
                        'sql' => 'SELECT device, path, type, flags, blocks_size, blocks, blocks_free FROM mounts;',
                    ],
                    [
                        'name' => 'Certificats SSL & Clés de Chiffrement',
                        'description' => 'Validité des certificats de sécurité SSL/TLS et empreintes SHA-256.',
                        'sql' => 'SELECT common_name, issuer, valid_from, valid_to, sha256, status FROM certificates;',
                    ],
                ],
            ],
        ];
    }
}
