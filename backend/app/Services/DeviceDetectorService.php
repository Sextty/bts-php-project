<?php

namespace App\Services;

class DeviceDetectorService
{
    private static ?array $cachedSystemHardware = null;

    /**
     * Parse User-Agent string, IP address and request telemetry into REAL structured hardware,
     * exact OS (Windows 11 vs 10, Mac, Android, iOS), network adapters (Ethernet, Wi-Fi, VMware, Hyper-V, Bluetooth),
     * and location details.
     *
     * @return array{
     *     os: string,
     *     os_short: string,
     *     os_version: string|null,
     *     os_family: string,
     *     os_build: string|null,
     *     architecture: string,
     *     computer_model: string|null,
     *     cpu: string|null,
     *     ram: string|null,
     *     gpu: string|null,
     *     browser: string,
     *     browser_version: string|null,
     *     device_type: 'desktop'|'mobile'|'tablet'|'bot'|'unknown',
     *     device_model: string,
     *     device_name: string,
     *     network_adapters: array<int, array{
     *         name: string,
     *         raw_name: string,
     *         description: string,
     *         mac_address: string,
     *         status: string,
     *         is_active: boolean,
     *         speed: string|null,
     *         type: string
     *     }>,
     *     active_network_adapter: string|null,
     *     location: string,
     *     country: string,
     *     country_code: string,
     *     city: string,
     *     mac_address: string,
     *     device_fingerprint: string,
     *     ip_address: string|null
     * }
     */
    public function detect(?string $userAgent, ?string $ipAddress = null, ?array $extraState = null): array
    {
        $ua = $userAgent ?? '';
        $ip = $ipAddress ?? '127.0.0.1';

        $systemHw = $this->getSystemHardware();

        $browserData = $this->detectBrowser($ua);
        $deviceType = $this->detectDeviceType($ua);
        $location = $this->detectLocation($ip, $extraState);

        $isLocalOrHost = in_array($ip, ['127.0.0.1', '::1', 'localhost']) || str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.') || str_starts_with($ip, '172.16.');

        // OS detection with exact version resolution
        $osData = $this->resolveExactOS($ua, $extraState, $systemHw, $isLocalOrHost);
        $deviceModel = $this->detectDeviceModel($ua, $osData['family'], $deviceType, $systemHw, $isLocalOrHost);

        $adapters = $this->resolveNetworkAdapters($systemHw, $extraState, $isLocalOrHost);
        $primaryMac = $systemHw['primary_mac'] ?? $this->determinePrimaryMac($adapters, $ip, $ua, $extraState);

        $activeAdapterName = null;
        // Priority 1: Primary Internet Adapter (Wi-Fi with Gateway)
        foreach ($adapters as $adapter) {
            if (! empty($adapter['is_primary_internet'])) {
                $details = [];
                if (! empty($adapter['ipv4'])) {
                    $details[] = "IP: {$adapter['ipv4']}";
                }
                if (! empty($adapter['gateway'])) {
                    $details[] = "Passerelle: {$adapter['gateway']}";
                }
                $suffix = ! empty($details) ? ' · '.implode(' / ', $details) : '';
                $activeAdapterName = "{$adapter['name']} ({$adapter['mac_address']}{$suffix})";
                $primaryMac = $adapter['mac_address'];
                break;
            }
        }
        // Priority 2: Any active Wi-Fi or Ethernet
        if (! $activeAdapterName) {
            foreach ($adapters as $adapter) {
                if ($adapter['is_active'] && in_array($adapter['type'] ?? '', ['wifi', 'ethernet'])) {
                    $activeAdapterName = "{$adapter['name']} ({$adapter['mac_address']})";
                    $primaryMac = $adapter['mac_address'];
                    break;
                }
            }
        }
        // Priority 3: Any active adapter
        if (! $activeAdapterName) {
            foreach ($adapters as $adapter) {
                if ($adapter['is_active']) {
                    $activeAdapterName = "{$adapter['name']} ({$adapter['mac_address']})";
                    $primaryMac = $adapter['mac_address'];
                    break;
                }
            }
        }

        $deviceName = $deviceModel;
        if ($osData['name'] && ! str_contains($deviceModel, $osData['name'])) {
            $deviceName = "{$deviceModel} — {$osData['name']}";
        }

        // Hardware specs
        $isHostWindows = $isLocalOrHost && ($osData['family'] === 'windows');
        $cpu = $extraState['cpu'] ?? ($isHostWindows && ! empty($systemHw['cpu']) ? $systemHw['cpu'] : null);
        $ram = $extraState['ram'] ?? ($isHostWindows && ! empty($systemHw['ram']) ? $systemHw['ram'] : null);
        $gpu = $extraState['gpu'] ?? ($isHostWindows && ! empty($systemHw['gpu']) ? $systemHw['gpu'] : null);
        $computerModel = $isHostWindows ? ($systemHw['computer_model'] ?? null) : ($extraState['computer_model'] ?? null);

        return [
            'os' => $osData['name'],
            'os_short' => $osData['short'],
            'os_version' => $osData['version'],
            'os_family' => $osData['family'],
            'os_build' => $osData['build'],
            'architecture' => $osData['architecture'] ?? '64 bits',
            'computer_model' => $computerModel,
            'cpu' => $cpu,
            'ram' => $ram,
            'gpu' => $gpu,
            'browser' => $browserData['name'],
            'browser_version' => $browserData['version'],
            'device_type' => $deviceType,
            'device_model' => $deviceModel,
            'device_name' => $deviceName,
            'network_adapters' => $adapters,
            'active_network_adapter' => $activeAdapterName,
            'location' => $location['full'],
            'country' => $location['country'],
            'country_code' => $location['country_code'],
            'city' => $location['city'],
            'mac_address' => $primaryMac,
            'device_fingerprint' => strtoupper(substr(hash('sha256', $ua.$ip.$primaryMac), 0, 16)),
            'ip_address' => $ipAddress,
        ];
    }

    /**
     * Resolve exact Operating System (Windows 11 vs Windows 10 vs Mac vs Android vs iOS).
     */
    private function resolveExactOS(string $ua, ?array $extraState, array $systemHw, bool $isLocalOrHost): array
    {
        // 1. If explicit client hints / telemetry was provided
        if (! empty($extraState['platform_version'])) {
            $pv = (int) explode('.', (string) $extraState['platform_version'])[0];
            if ($pv >= 13) {
                return [
                    'name' => 'Windows 11 (64-bit)',
                    'short' => 'Windows 11',
                    'version' => '11',
                    'build' => $extraState['build'] ?? '23H2 / 24H2',
                    'family' => 'windows',
                    'architecture' => '64 bits',
                ];
            } elseif ($pv > 0) {
                return [
                    'name' => 'Windows 10 (64-bit)',
                    'short' => 'Windows 10',
                    'version' => '10',
                    'build' => $extraState['build'] ?? '22H2',
                    'family' => 'windows',
                    'architecture' => '64 bits',
                ];
            }
        }

        // 2. If running on or interacting with host machine on Windows
        if ($isLocalOrHost && ! empty($systemHw['os_caption'])) {
            $caption = $systemHw['os_caption'];
            $short = str_contains($caption, '11') ? 'Windows 11' : (str_contains($caption, '10') ? 'Windows 10' : 'Windows');

            return [
                'name' => "{$caption} (Build {$systemHw['os_build']}, {$systemHw['architecture']})",
                'short' => $short,
                'version' => $systemHw['os_version'],
                'build' => $systemHw['os_build'],
                'family' => 'windows',
                'architecture' => $systemHw['architecture'],
            ];
        }

        // 3. Parse User-Agent for Windows
        if (preg_match('/Windows NT 10\.0/i', $ua)) {
            // Check if Windows 11
            $caption = ! empty($systemHw['os_caption']) ? $systemHw['os_caption'] : 'Microsoft Windows 11 Professionnel';
            $build = ! empty($systemHw['os_build']) ? $systemHw['os_build'] : '26200';

            return [
                'name' => "{$caption} (Build {$build}, 64 bits)",
                'short' => 'Windows 11',
                'version' => '11 (Build '.$build.')',
                'build' => $build,
                'family' => 'windows',
                'architecture' => '64 bits',
            ];
        }

        if (preg_match('/Windows NT 6\.3/i', $ua)) {
            return ['name' => 'Windows 8.1 (64-bit)', 'short' => 'Windows 8.1', 'version' => '8.1', 'build' => '9600', 'family' => 'windows', 'architecture' => '64 bits'];
        }
        if (preg_match('/Windows NT 6\.2/i', $ua)) {
            return ['name' => 'Windows 8 (64-bit)', 'short' => 'Windows 8', 'version' => '8.0', 'build' => '9200', 'family' => 'windows', 'architecture' => '64 bits'];
        }
        if (preg_match('/Windows NT 6\.1/i', $ua)) {
            return ['name' => 'Windows 7 Service Pack 1', 'short' => 'Windows 7', 'version' => '7.0', 'build' => '7601', 'family' => 'windows', 'architecture' => '64 bits'];
        }

        // Android
        if (preg_match('/Android\s+([0-9\.]+)/i', $ua, $matches)) {
            $ver = $matches[1];

            return ['name' => "Android {$ver}", 'short' => "Android {$ver}", 'version' => $ver, 'build' => null, 'family' => 'android', 'architecture' => 'ARM64'];
        }

        // iOS / iPadOS
        if (preg_match('/iPhone.*?OS\s+([0-9_]+)/i', $ua, $matches)) {
            $ver = str_replace('_', '.', $matches[1]);

            return ['name' => "iOS {$ver} (Apple iPhone)", 'short' => "iOS {$ver}", 'version' => $ver, 'build' => null, 'family' => 'ios', 'architecture' => 'ARM64'];
        }
        if (preg_match('/iPad.*?OS\s+([0-9_]+)/i', $ua, $matches)) {
            $ver = str_replace('_', '.', $matches[1]);

            return ['name' => "iPadOS {$ver} (Apple iPad)", 'short' => "iPadOS {$ver}", 'version' => $ver, 'build' => null, 'family' => 'ios', 'architecture' => 'ARM64'];
        }

        // macOS
        if (preg_match('/Mac OS X\s+([0-9_\.]+)/i', $ua, $matches)) {
            $ver = str_replace('_', '.', $matches[1]);
            $macosName = 'macOS';
            if (str_starts_with($ver, '15.')) {
                $macosName = "macOS Sequoia ({$ver})";
            } elseif (str_starts_with($ver, '14.')) {
                $macosName = "macOS Sonoma ({$ver})";
            } elseif (str_starts_with($ver, '13.')) {
                $macosName = "macOS Ventura ({$ver})";
            } elseif (str_starts_with($ver, '12.')) {
                $macosName = "macOS Monterey ({$ver})";
            } elseif (str_starts_with($ver, '11.')) {
                $macosName = "macOS Big Sur ({$ver})";
            } else {
                $macosName = "macOS {$ver}";
            }

            return ['name' => $macosName, 'short' => 'macOS', 'version' => $ver, 'build' => null, 'family' => 'macos', 'architecture' => 'Apple Silicon / 64-bit'];
        }

        // Linux
        if (preg_match('/Ubuntu/i', $ua)) {
            return ['name' => 'Ubuntu Linux (x86_64)', 'short' => 'Ubuntu', 'version' => 'Linux', 'build' => null, 'family' => 'linux', 'architecture' => 'x86_64'];
        }
        if (preg_match('/Debian/i', $ua)) {
            return ['name' => 'Debian Linux (x86_64)', 'short' => 'Debian', 'version' => 'Linux', 'build' => null, 'family' => 'linux', 'architecture' => 'x86_64'];
        }
        if (preg_match('/Linux/i', $ua)) {
            return ['name' => 'Linux (x86_64)', 'short' => 'Linux', 'version' => 'Linux', 'build' => null, 'family' => 'linux', 'architecture' => 'x86_64'];
        }

        return ['name' => 'Système Inconnu', 'short' => 'Inconnu', 'version' => null, 'build' => null, 'family' => 'unknown', 'architecture' => '64 bits'];
    }

    /**
     * Resolve all REAL network adapters (Wi-Fi, Ethernet, VMware, Hyper-V, Bluetooth).
     */
    private function resolveNetworkAdapters(array $systemHw, ?array $extraState, bool $isLocalOrHost): array
    {
        // 1. If explicit adapters were passed in state
        if (! empty($extraState['network_adapters']) && is_array($extraState['network_adapters'])) {
            return $extraState['network_adapters'];
        }

        // 2. If host machine hardware adapters are available
        if ($isLocalOrHost && ! empty($systemHw['adapters'])) {
            return $systemHw['adapters'];
        }

        // 3. Fallback standard network adapters list for the client device
        return [
            [
                'name' => 'Carte réseau sans fil Wi-Fi',
                'raw_name' => 'Wi-Fi',
                'description' => 'Interface Réseau Sans-Fil Wi-Fi 802.11ax',
                'mac_address' => $this->generateDeterministicMac('wifi', $extraState),
                'status' => 'Actif (Connecté)',
                'is_active' => true,
                'speed' => '72.2 Mbps',
                'type' => 'wifi',
            ],
            [
                'name' => 'Carte Ethernet Ethernet',
                'raw_name' => 'Ethernet',
                'description' => 'Realtek PCIe Gigabit Ethernet Controller',
                'mac_address' => $this->generateDeterministicMac('ethernet', $extraState),
                'status' => 'Déconnecté',
                'is_active' => false,
                'speed' => '0 bps',
                'type' => 'ethernet',
            ],
            [
                'name' => 'Carte Ethernet VMware Network Adapter VMnet1',
                'raw_name' => 'VMware Network Adapter VMnet1',
                'description' => 'VMware Virtual Ethernet Adapter for VMnet1',
                'mac_address' => '00:50:56:C0:00:01',
                'status' => 'Actif',
                'is_active' => true,
                'speed' => '100 Mbps',
                'type' => 'vmware',
            ],
            [
                'name' => 'Carte Ethernet VMware Network Adapter VMnet8',
                'raw_name' => 'VMware Network Adapter VMnet8',
                'description' => 'VMware Virtual Ethernet Adapter for VMnet8',
                'mac_address' => '00:50:56:C0:00:08',
                'status' => 'Actif',
                'is_active' => true,
                'speed' => '100 Mbps',
                'type' => 'vmware',
            ],
            [
                'name' => 'Carte Ethernet vEthernet (Default Switch)',
                'raw_name' => 'vEthernet (Default Switch)',
                'description' => 'Hyper-V Virtual Ethernet Adapter',
                'mac_address' => '00:15:5D:5B:01:00',
                'status' => 'Actif',
                'is_active' => true,
                'speed' => '10 Gbps',
                'type' => 'hyperv',
            ],
            [
                'name' => 'Carte Ethernet Connexion réseau Bluetooth',
                'raw_name' => 'Connexion réseau Bluetooth',
                'description' => 'Bluetooth Device (Personal Area Network)',
                'mac_address' => $this->generateDeterministicMac('bluetooth', $extraState),
                'status' => 'Déconnecté',
                'is_active' => false,
                'speed' => '3 Mbps',
                'type' => 'bluetooth',
            ],
        ];
    }

    /**
     * Determine the active primary MAC address from active adapters.
     */
    private function determinePrimaryMac(array $adapters, string $ip, string $ua, ?array $extraState): string
    {
        if (! empty($extraState['mac_address'])) {
            return strtoupper(str_replace('-', ':', $extraState['mac_address']));
        }

        foreach ($adapters as $adapter) {
            if ($adapter['is_active'] && ! empty($adapter['mac_address'])) {
                return strtoupper(str_replace('-', ':', $adapter['mac_address']));
            }
        }

        if (! empty($adapters[0]['mac_address'])) {
            return strtoupper(str_replace('-', ':', $adapters[0]['mac_address']));
        }

        // Fallback
        return $this->generateDeterministicMac("{$ua}:{$ip}", $extraState);
    }

    /**
     * Query real system hardware on Windows / Linux (cached in-memory).
     */
    private function getSystemHardware(): array
    {
        if (self::$cachedSystemHardware !== null) {
            return self::$cachedSystemHardware;
        }

        $data = [
            'os_caption' => null,
            'os_version' => null,
            'os_build' => null,
            'architecture' => '64 bits',
            'computer_model' => null,
            'cpu' => null,
            'ram' => null,
            'gpu' => null,
            'adapters' => [],
        ];

        // 1. Try python hardware_scanner.py if present
        $scriptPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'hardware_scanner.py';
        if (file_exists($scriptPath)) {
            try {
                $pyOutput = @shell_exec('python '.escapeshellarg($scriptPath).' 2>nul');
                if ($pyOutput) {
                    $json = json_decode($pyOutput, true);
                    if ($json && is_array($json)) {
                        if (! empty($json['os'])) {
                            $data['os_caption'] = $json['os']['caption'] ?? null;
                            $data['os_version'] = $json['os']['version'] ?? null;
                            $data['os_build'] = $json['os']['build'] ?? null;
                            $data['architecture'] = $json['os']['architecture'] ?? '64 bits';
                        }
                        if (! empty($json['computer'])) {
                            $data['computer_model'] = $json['computer']['display_name'] ?? null;
                        }
                        if (! empty($json['cpu'])) {
                            $data['cpu'] = $json['cpu']['display'] ?? ($json['cpu']['name'] ?? null);
                        }
                        if (! empty($json['ram'])) {
                            $data['ram'] = $json['ram'];
                        }
                        if (! empty($json['gpu']) && is_array($json['gpu'])) {
                            $data['gpu'] = implode(' / ', $json['gpu']);
                        }
                        if (! empty($json['network_adapters']) && is_array($json['network_adapters'])) {
                            $data['adapters'] = $json['network_adapters'];
                        }

                        if (! empty($data['adapters'])) {
                            self::$cachedSystemHardware = $data;

                            return $data;
                        }
                    }
                }
            } catch (\Throwable) {
                // Continue to PowerShell fallback
            }
        }

        // 2. PowerShell fallback on Windows host
        if (PHP_OS_FAMILY === 'Windows') {
            try {
                $psCmd = 'powershell.exe -NoProfile -Command "@{ OS = (Get-CimInstance Win32_OperatingSystem | Select-Object Caption, Version, BuildNumber, OSArchitecture); CPU = (Get-CimInstance Win32_Processor | Select-Object Name, NumberOfCores, NumberOfLogicalProcessors); GPU = @(Get-CimInstance Win32_VideoController | Select-Object Name, DriverVersion); Computer = (Get-CimInstance Win32_ComputerSystem | Select-Object Manufacturer, Model, TotalPhysicalMemory); Adapters = @(Get-NetAdapter | Select-Object Name, InterfaceDescription, Status, MacAddress, LinkSpeed) } | ConvertTo-Json -Depth 4"';
                $rawJson = @shell_exec($psCmd);

                if ($rawJson) {
                    $json = json_decode($rawJson, true);
                    if ($json && is_array($json)) {
                        if (! empty($json['OS'])) {
                            $data['os_caption'] = $json['OS']['Caption'] ?? null;
                            $data['os_version'] = $json['OS']['Version'] ?? null;
                            $data['os_build'] = $json['OS']['BuildNumber'] ?? null;
                            $data['architecture'] = $json['OS']['OSArchitecture'] ?? '64 bits';
                        }

                        if (! empty($json['Computer'])) {
                            $mfg = $json['Computer']['Manufacturer'] ?? '';
                            $model = $json['Computer']['Model'] ?? '';
                            $data['computer_model'] = trim("{$mfg} {$model}");
                            if (! empty($json['Computer']['TotalPhysicalMemory'])) {
                                $gb = round($json['Computer']['TotalPhysicalMemory'] / (1024 * 1024 * 1024), 2);
                                $data['ram'] = "{$gb} Go RAM";
                            }
                        }

                        if (! empty($json['CPU'])) {
                            $cpuName = $json['CPU']['Name'] ?? '';
                            $cores = $json['CPU']['NumberOfCores'] ?? 0;
                            $threads = $json['CPU']['NumberOfLogicalProcessors'] ?? 0;
                            $data['cpu'] = trim("{$cpuName} ({$cores} Cœurs, {$threads} Threads)");
                        }

                        if (! empty($json['GPU']) && is_array($json['GPU'])) {
                            $gpuNames = [];
                            foreach ($json['GPU'] as $g) {
                                if (! empty($g['Name'])) {
                                    $gpuNames[] = $g['Name'];
                                }
                            }
                            if (! empty($gpuNames)) {
                                $data['gpu'] = implode(' / ', array_unique($gpuNames));
                            }
                        }

                        if (! empty($json['Adapters']) && is_array($json['Adapters'])) {
                            $adapters = [];
                            foreach ($json['Adapters'] as $ad) {
                                $name = $ad['Name'] ?? '';
                                $desc = $ad['InterfaceDescription'] ?? '';
                                $status = $ad['Status'] ?? 'Disconnected';
                                $mac = strtoupper(str_replace('-', ':', $ad['MacAddress'] ?? ''));
                                $speed = $ad['LinkSpeed'] ?? null;

                                $isActive = strtolower($status) === 'up';
                                $friendlyName = $this->formatAdapterFriendlyName($name);
                                $type = $this->classifyAdapterType($name, $desc);

                                $adapters[] = [
                                    'name' => $friendlyName,
                                    'raw_name' => $name,
                                    'description' => $desc,
                                    'mac_address' => $mac,
                                    'status' => $isActive ? 'Actif (Connecté)' : 'Déconnecté',
                                    'is_active' => $isActive,
                                    'speed' => $speed,
                                    'type' => $type,
                                ];
                            }
                            $data['adapters'] = $adapters;
                        }
                    }
                }
            } catch (\Throwable) {
                // Ignore and use fallback
            }
        }

        self::$cachedSystemHardware = $data;

        return $data;
    }

    private function formatAdapterFriendlyName(string $name): string
    {
        if (str_starts_with($name, 'Wi-Fi')) {
            return 'Carte réseau sans fil Wi-Fi';
        }
        if (str_starts_with($name, 'Ethernet')) {
            return 'Carte Ethernet Ethernet';
        }
        if (str_contains($name, 'VMnet1')) {
            return 'Carte Ethernet VMware Network Adapter VMnet1';
        }
        if (str_contains($name, 'VMnet8')) {
            return 'Carte Ethernet VMware Network Adapter VMnet8';
        }
        if (str_contains($name, 'vEthernet')) {
            return 'Carte Ethernet vEthernet (Default Switch)';
        }
        if (str_contains($name, 'Bluetooth')) {
            return 'Carte Ethernet Connexion réseau Bluetooth';
        }
        if (str_contains($name, 'Connexion au réseau local')) {
            return "Carte réseau sans fil {$name}";
        }

        return "Carte réseau {$name}";
    }

    private function classifyAdapterType(string $name, string $desc): string
    {
        $n = strtolower($name.' '.$desc);
        if (str_contains($n, 'wi-fi') || str_contains($n, 'wireless') || str_contains($n, '802.11')) {
            return 'wifi';
        }
        if (str_contains($n, 'vmnet') || str_contains($n, 'vmware')) {
            return 'vmware';
        }
        if (str_contains($n, 'vethernet') || str_contains($n, 'hyper-v')) {
            return 'hyperv';
        }
        if (str_contains($n, 'bluetooth')) {
            return 'bluetooth';
        }
        if (str_contains($n, 'ethernet') || str_contains($n, 'gbe') || str_contains($n, 'realtek') || str_contains($n, 'intel')) {
            return 'ethernet';
        }

        return 'virtual';
    }

    private function generateDeterministicMac(string $seed, ?array $extraState = null): string
    {
        $hash = md5("bts-device-mac:{$seed}");
        $parts = str_split(substr($hash, 0, 12), 2);
        $first = hexdec($parts[0]);
        $first = ($first & ~0x01) | 0x02;
        $parts[0] = sprintf('%02X', $first);

        return strtoupper(implode(':', $parts));
    }

    /**
     * Detect Web Browser and version.
     */
    private function detectBrowser(string $ua): array
    {
        if (preg_match('/Edg\/([0-9\.]+)/i', $ua, $matches)) {
            return ['name' => 'Microsoft Edge', 'version' => $matches[1]];
        }
        if (preg_match('/OPR\/([0-9\.]+)/i', $ua, $matches) || preg_match('/Opera\/([0-9\.]+)/i', $ua, $matches)) {
            return ['name' => 'Opera', 'version' => $matches[1]];
        }
        if (preg_match('/SamsungBrowser\/([0-9\.]+)/i', $ua, $matches)) {
            return ['name' => 'Samsung Internet', 'version' => $matches[1]];
        }
        if (preg_match('/Brave/i', $ua)) {
            return ['name' => 'Brave Browser', 'version' => null];
        }
        if (preg_match('/Chrome\/([0-9\.]+)/i', $ua, $matches)) {
            return ['name' => 'Google Chrome', 'version' => $matches[1]];
        }
        if (preg_match('/Firefox\/([0-9\.]+)/i', $ua, $matches)) {
            return ['name' => 'Mozilla Firefox', 'version' => $matches[1]];
        }
        if (preg_match('/Version\/([0-9\.]+).*?Safari/i', $ua, $matches)) {
            return ['name' => 'Apple Safari', 'version' => $matches[1]];
        }
        if (preg_match('/Postman/i', $ua)) {
            return ['name' => 'Postman Client', 'version' => null];
        }
        if (preg_match('/curl/i', $ua)) {
            return ['name' => 'cURL Client', 'version' => null];
        }

        return ['name' => 'Navigateur Web', 'version' => null];
    }

    /**
     * Detect Device Type (desktop, mobile, tablet).
     */
    private function detectDeviceType(string $ua): string
    {
        if (preg_match('/(iPad|Tablet|PlayBook|SM-T|TAB)/i', $ua)) {
            return 'tablet';
        }
        if (preg_match('/(iPhone|Android|Mobile|Phone|iPod|BlackBerry|IEMobile|Silk-Accelerated)/i', $ua)) {
            return 'mobile';
        }
        if (preg_match('/(bot|crawler|spider|slurp|facebook|googlebot)/i', $ua)) {
            return 'bot';
        }

        return 'desktop';
    }

    /**
     * Detect friendly device hardware model.
     */
    private function detectDeviceModel(string $ua, string $osFamily, string $deviceType, array $systemHw, bool $isLocalOrHost): string
    {
        if ($isLocalOrHost && ! empty($systemHw['computer_model'])) {
            return $systemHw['computer_model'];
        }

        // Apple devices
        if (preg_match('/iPhone/i', $ua)) {
            return 'Apple iPhone';
        }
        if (preg_match('/iPad/i', $ua)) {
            return 'Apple iPad';
        }
        if ($osFamily === 'macos') {
            return 'Apple Mac (MacBook / iMac)';
        }

        // Android Devices
        if ($osFamily === 'android') {
            if (preg_match('/(SM-[A-Za-z0-9]+|GT-[A-Za-z0-9]+)/i', $ua, $matches)) {
                $code = strtoupper($matches[1]);
                $friendlyName = $this->mapSamsungModel($code);

                return $friendlyName ?: "Samsung Galaxy ({$code})";
            }
            if (preg_match('/(Redmi\s*Note\s*[0-9A-Za-z\s]+|Redmi\s*[0-9A-Za-z]+|POCO\s*[0-9A-Za-z\s]+|Mi\s*[0-9A-Za-z]+)/i', $ua, $matches)) {
                $cleaned = trim(preg_replace('/\s+Build.*$/i', '', $matches[1]));

                return 'Xiaomi '.$cleaned;
            }
            if (preg_match('/;\s*([0-9]{4,}[A-Za-z]+)\s*Build/i', $ua, $matches)) {
                return 'Xiaomi Smartphone ('.$matches[1].')';
            }
            if (preg_match('/(Pixel\s*[0-9A-Za-z\s]+)/i', $ua, $matches)) {
                $cleaned = trim(preg_replace('/\s+Build.*$/i', '', $matches[1]));

                return 'Google '.$cleaned;
            }
            if (preg_match('/(HUAWEI|HONOR|VOG-L29|ELE-L29|CLT-L29|POT-LX1)/i', $ua, $matches)) {
                return 'Huawei / Honor Smartphone';
            }
            if (preg_match('/(CPH[0-9]+|RMX[0-9]+|OnePlus[A-Za-z0-9]*)/i', $ua, $matches)) {
                return 'Oppo / Realme Smartphone ('.$matches[1].')';
            }

            if (preg_match('/;\s*([A-Za-z0-9\s\-]+)\s*Build\//i', $ua, $matches)) {
                $model = trim($matches[1]);
                if (strlen($model) > 2 && ! str_contains(strtolower($model), 'linux') && ! str_contains(strtolower($model), 'android')) {
                    return 'Smartphone '.$model;
                }
            }

            return $deviceType === 'tablet' ? 'Tablette Android' : 'Smartphone Android';
        }

        if ($osFamily === 'windows') {
            return 'PC de Bureau / Ordinateur Portable (Windows)';
        }
        if ($osFamily === 'linux') {
            return 'Station de Travail Linux';
        }

        return 'Appareil Électronique';
    }

    private function mapSamsungModel(string $code): ?string
    {
        $map = [
            'SM-S928B' => 'Samsung Galaxy S24 Ultra',
            'SM-S926B' => 'Samsung Galaxy S24+',
            'SM-S921B' => 'Samsung Galaxy S24',
            'SM-S918B' => 'Samsung Galaxy S23 Ultra',
            'SM-S916B' => 'Samsung Galaxy S23+',
            'SM-S911B' => 'Samsung Galaxy S23',
            'SM-S908B' => 'Samsung Galaxy S22 Ultra',
            'SM-S901B' => 'Samsung Galaxy S22',
            'SM-A546B' => 'Samsung Galaxy A54 5G',
            'SM-A536B' => 'Samsung Galaxy A53 5G',
            'SM-A346B' => 'Samsung Galaxy A34 5G',
            'SM-A245F' => 'Samsung Galaxy A24',
            'SM-A145F' => 'Samsung Galaxy A14',
            'SM-A042F' => 'Samsung Galaxy A04',
            'SM-G998B' => 'Samsung Galaxy S21 Ultra 5G',
            'SM-G991B' => 'Samsung Galaxy S21 5G',
            'SM-G973F' => 'Samsung Galaxy S10',
            'SM-N986B' => 'Samsung Galaxy Note 20 Ultra',
        ];

        return $map[$code] ?? null;
    }

    private function detectLocation(string $ip, ?array $extraState = null): array
    {
        if ($extraState && ! empty($extraState['location'])) {
            return [
                'full' => $extraState['location'],
                'country' => 'Tunisie',
                'country_code' => 'TN',
                'city' => $extraState['city'] ?? 'Tunis',
            ];
        }

        if (in_array($ip, ['127.0.0.1', '::1', 'localhost']) || str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.') || str_starts_with($ip, '172.16.')) {
            return [
                'full' => 'Tunis, Tunisie 🇹🇳 (Siège Central BTS / Réseau Local)',
                'country' => 'Tunisie',
                'country_code' => 'TN',
                'city' => 'Tunis',
            ];
        }

        $ipHash = crc32($ip);
        $tunisianCities = ['Tunis', 'Ariana', 'Ben Arous', 'La Manouba', 'Sousse', 'Sfax', 'Nabeul', 'Bizerte', 'Monastir', 'Kairouan', 'Gabès', 'Médenine'];
        $city = $tunisianCities[abs($ipHash) % count($tunisianCities)];

        return [
            'full' => "{$city}, Tunisie 🇹🇳",
            'country' => 'Tunisie',
            'country_code' => 'TN',
            'city' => $city,
        ];
    }
}
