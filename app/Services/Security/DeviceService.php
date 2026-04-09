<?php

namespace App\Services\Security;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\Request;

class DeviceService
{
    /**
     * Generate a unique fingerprint for a device from request data.
     * The frontend should also send X-Device-Fingerprint header
     * (generated from browser fingerprinting JS) for better accuracy.
     */
    public function generateFingerprint(Request $request): string
    {
        $components = implode('|', [
            $request->userAgent() ?? '',
            $request->header('Accept-Language') ?? '',
            $request->header('Accept-Encoding') ?? '',
            // IP is intentionally excluded so same device on different networks is recognized
        ]);

        return hash('sha256', $components);
    }

    /**
     * Find existing device or create a new one.
     */
    public function findOrCreateDevice(User $user, Request $request, string $fingerprint): UserDevice
    {
        $device = UserDevice::where('user_id', $user->id)
                            ->where('device_fingerprint', $fingerprint)
                            ->first();

        if ($device) {
            return $device;
        }

        // Parse device info from user agent
        $parsed = $this->parseUserAgent($request->userAgent() ?? '');

        return UserDevice::create([
            'user_id'            => $user->id,
            'device_fingerprint' => $fingerprint,
            'device_name'        => $parsed['name'],
            'device_type'        => $parsed['type'],
            'browser'            => $parsed['browser'],
            'platform'           => $parsed['platform'],
            'ip_address'         => $request->ip(),
            'is_trusted'         => false,
        ]);
    }

    /**
     * Simple UA parsing. In production install jenssegers/agent for better accuracy.
     */
    private function parseUserAgent(string $ua): array
    {
        $type     = 'desktop';
        $platform = 'Unknown';
        $browser  = 'Unknown';

        if (preg_match('/mobile/i', $ua))  $type = 'mobile';
        if (preg_match('/tablet/i', $ua))  $type = 'tablet';

        if (preg_match('/windows/i', $ua))        $platform = 'Windows';
        elseif (preg_match('/macintosh/i', $ua))  $platform = 'macOS';
        elseif (preg_match('/linux/i', $ua))      $platform = 'Linux';
        elseif (preg_match('/android/i', $ua))    $platform = 'Android';
        elseif (preg_match('/iphone|ipad/i', $ua)) $platform = 'iOS';

        if (preg_match('/chrome/i', $ua))         $browser = 'Chrome';
        elseif (preg_match('/firefox/i', $ua))    $browser = 'Firefox';
        elseif (preg_match('/safari/i', $ua))     $browser = 'Safari';
        elseif (preg_match('/edge/i', $ua))       $browser = 'Edge';
        elseif (preg_match('/opera/i', $ua))      $browser = 'Opera';

        return [
            'name'     => "{$browser} on {$platform}",
            'type'     => $type,
            'browser'  => $browser,
            'platform' => $platform,
        ];
    }
}