<?php
namespace PolyPayPlugin;

/** Validate storefront URLs according to the merchant's HTTPS requirement. */
class HostUrl
{
    /** Require HTTPS by default; disabling the switch also permits HTTP. */
    public static function validate(string $url, $forceHttps = 1): string
    {
        if (!in_array($forceHttps, [0, 1, '0', '1', false, true], true)) {
            throw new \InvalidArgumentException('Force HTTPS must be enabled or disabled');
        }
        $required = (bool)$forceHttps;
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])
            || !in_array($parts['scheme'] ?? '', $required ? ['https'] : ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new \InvalidArgumentException($required ? 'A valid HTTPS URL is required' : 'A valid HTTP or HTTPS URL is required', 400);
        }
        return $url;
    }
}
