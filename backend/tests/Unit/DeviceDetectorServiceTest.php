<?php

namespace Tests\Unit;

use App\Services\DeviceDetectorService;
use PHPUnit\Framework\TestCase;

class DeviceDetectorServiceTest extends TestCase
{
    private DeviceDetectorService $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new DeviceDetectorService();
    }

    public function test_detects_windows_11_and_10(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36 Edg/127.0.0.0';
        $result = $this->detector->detect($ua, '197.3.45.10');

        $this->assertStringContainsString('Windows', $result['os']);
        $this->assertSame('windows', $result['os_family']);
        $this->assertSame('Microsoft Edge', $result['browser']);
        $this->assertSame('desktop', $result['device_type']);
        $this->assertNotEmpty($result['mac_address']);
        $this->assertMatchesRegularExpression('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $result['mac_address']);
        $this->assertStringContainsString('Tunisie', $result['location']);
    }

    public function test_detects_android_samsung_galaxy(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36';
        $result = $this->detector->detect($ua, '197.3.45.20');

        $this->assertSame('Android 14', $result['os']);
        $this->assertSame('android', $result['os_family']);
        $this->assertSame('Samsung Galaxy S23 Ultra', $result['device_model']);
        $this->assertSame('mobile', $result['device_type']);
        $this->assertMatchesRegularExpression('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $result['mac_address']);
    }

    public function test_detects_android_xiaomi_redmi(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 13; Redmi Note 12 Pro Build/TP1A.220624.014) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36';
        $result = $this->detector->detect($ua, '197.3.45.22');

        $this->assertSame('Android 13', $result['os']);
        $this->assertSame('android', $result['os_family']);
        $this->assertStringContainsString('Xiaomi', $result['device_model']);
        $this->assertSame('mobile', $result['device_type']);
    }

    public function test_detects_android_google_pixel(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 14; Pixel 8 Pro Build/UD1A.230803.041) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Mobile Safari/537.36';
        $result = $this->detector->detect($ua, '197.3.45.24');

        $this->assertSame('Android 14', $result['os']);
        $this->assertSame('Google Pixel 8 Pro', $result['device_model']);
        $this->assertSame('mobile', $result['device_type']);
    }

    public function test_detects_iphone_ios(): void
    {
        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4.1 Mobile/15E148 Safari/604.1';
        $result = $this->detector->detect($ua, '197.3.45.30');

        $this->assertStringContainsString('iOS 17.4.1', $result['os']);
        $this->assertSame('iOS 17.4.1', $result['os_short']);
        $this->assertSame('ios', $result['os_family']);
        $this->assertSame('Apple iPhone', $result['device_model']);
        $this->assertSame('mobile', $result['device_type']);
        $this->assertSame('Apple Safari', $result['browser']);
    }

    public function test_detects_macos_sonoma(): void
    {
        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
        $result = $this->detector->detect($ua, '197.3.45.40');

        $this->assertStringContainsString('macOS', $result['os']);
        $this->assertSame('macos', $result['os_family']);
        $this->assertSame('Apple Mac (MacBook / iMac)', $result['device_model']);
        $this->assertSame('desktop', $result['device_type']);
    }

    public function test_detects_network_adapters_and_mac_addresses(): void
    {
        $result = $this->detector->detect('Mozilla/5.0 (Windows NT 10.0)', '127.0.0.1');

        $this->assertNotEmpty($result['network_adapters']);
        $this->assertIsArray($result['network_adapters']);

        $adapterNames = array_column($result['network_adapters'], 'name');
        $this->assertTrue(
            collect($adapterNames)->contains(fn ($n) => str_contains($n, 'Wi-Fi') || str_contains($n, 'Ethernet')),
            'Should contain real or standard network adapters'
        );

        $macs = array_column($result['network_adapters'], 'mac_address');
        foreach ($macs as $mac) {
            $this->assertMatchesRegularExpression('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac);
        }
    }

    public function test_client_hints_distinguish_windows_11_from_windows_10(): void
    {
        // Platform Version 15.0.0 is Windows 11
        $resWin11 = $this->detector->detect('Mozilla/5.0 (Windows NT 10.0)', '197.3.45.10', [
            'platform_version' => '15.0.0',
        ]);
        $this->assertSame('Windows 11 (64-bit)', $resWin11['os']);
        $this->assertSame('Windows 11', $resWin11['os_short']);

        // Platform Version 10.0.0 is Windows 10
        $resWin10 = $this->detector->detect('Mozilla/5.0 (Windows NT 10.0)', '197.3.45.10', [
            'platform_version' => '10.0.0',
        ]);
        $this->assertSame('Windows 10 (64-bit)', $resWin10['os']);
        $this->assertSame('Windows 10', $resWin10['os_short']);
    }
}
