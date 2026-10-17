<?php
declare(strict_types=1);

// Identical, versioned helper copies can be loaded by several modules in one PHP request.
if (!class_exists('AFSystemPortalNavigationV1', false)) {
    final class AFSystemPortalNavigationV1
    {
        private const PORTAL = '{922E5A10-A048-463D-A973-97B4BBD9096B}';
        private const VAULT = '{7C5A3841-3F7B-4D2A-9E1C-5B6D8F9A0E12}';
        private const WEBHOOKS = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';

        private static function validVault(int $id): bool
        {
            try {
                if ($id <= 0 || !IPS_InstanceExists($id)
                    || strtoupper((string)(IPS_GetInstance($id)['ModuleInfo']['ModuleID'] ?? '')) !== self::VAULT
                    || IPS_GetProperty($id, 'PortalEnabled') !== true) return false;
                $origin = parse_url((string)IPS_GetProperty($id, 'PortalOrigin'));
                return is_array($origin) && ($origin['scheme'] ?? '') === 'https' && !empty($origin['host'])
                    && !isset($origin['user']) && !isset($origin['pass']) && !isset($origin['query']) && !isset($origin['fragment'])
                    && in_array($origin['path'] ?? '', ['', '/'], true);
            } catch (Throwable $e) { return false; }
        }

        private static function vaultForPortal(int $selection): int
        {
            if ($selection > 0) return self::validVault($selection) ? $selection : 0;
            $eligible = [];
            foreach (IPS_GetInstanceListByModuleID(self::VAULT) as $id) {
                $id = (int)$id;
                if (self::validVault($id)) $eligible[$id] = $id;
            }
            return count($eligible) === 1 ? array_values($eligible)[0] : 0;
        }

        /** Read native metadata only. Never invoke a destination or change an instance. */
        public static function path(int $vault = 0, int $preferred = 0): string
        {
            try {
                $controls = IPS_GetInstanceListByModuleID(self::WEBHOOKS);
                if (count($controls) !== 1) return '';
                $hooks = json_decode((string)IPS_GetProperty((int)$controls[0], 'Hooks'), true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($hooks) || array_values($hooks) !== $hooks) return '';
                $ids = $preferred > 0 ? [$preferred] : IPS_GetInstanceListByModuleID(self::PORTAL);
                $eligible = [];
                foreach ($ids as $id) {
                    $id = (int)$id;
                    try {
                        if ($id <= 0 || !IPS_InstanceExists($id)
                            || strtoupper((string)(IPS_GetInstance($id)['ModuleInfo']['ModuleID'] ?? '')) !== self::PORTAL
                            || IPS_GetProperty($id, 'Enabled') !== true) continue;
                        $portalVault = self::vaultForPortal((int)IPS_GetProperty($id, 'VaultInstanceID'));
                        if ($portalVault === 0 || ($vault > 0 && $portalVault !== $vault)) continue;
                        $path = '/hook/alarm_portal_' . $id;
                        $matches = array_values(array_filter($hooks, static fn($hook) => is_array($hook) && ($hook['Hook'] ?? '') === $path));
                        if (count($matches) === 1 && (int)($matches[0]['TargetID'] ?? 0) === $id) $eligible[$id] = $path;
                    } catch (Throwable $e) { continue; }
                }
                return count($eligible) === 1 ? array_values($eligible)[0] : '';
            } catch (Throwable $e) { return ''; }
        }

        public static function button(int $vault = 0, int $preferred = 0): string
        {
            $path = self::path($vault, $preferred);
            $control = $path !== ''
                ? '<a id="system-portal-link" class="system-portal-button" role="button" href="' . htmlspecialchars($path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" target="_top">&#8592; System portal</a>'
                : '<button id="system-portal-link" class="system-portal-button" type="button" disabled title="No unique enabled System Portal with a matching vault and registered webhook was found.">&#8592; System portal</button>';
            return '<nav id="system-portal-navigation" class="system-portal-nav" aria-label="Top-level system navigation">' . $control . '</nav>';
        }

        /** Add navigation to complete HTML pages; preserve JSON, fragments and media bytes. */
        public static function inject(string $html, int $vault = 0, int $preferred = 0): string
        {
            if (!preg_match('~^\s*(?:<!doctype\s+html[^>]*>\s*)?<html\b~i', $html)
                || !preg_match('~<body\b[^>]*>~i', $html)
                || preg_match('~\bid=["\x27]system-portal-navigation["\x27]~i', $html)) return $html;
            $css = "\n" . '.system-portal-nav{box-sizing:border-box;display:block;flex:0 0 auto;width:100%;max-width:100%;padding:12px 16px;margin:0 0 12px;background:#edf5f4;border:1px solid #cbdcda;border-radius:8px;color:#173d42;font:14px/1.4 system-ui,-apple-system,Segoe UI,sans-serif}.system-portal-nav .system-portal-button{box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;min-height:40px;max-width:100%;padding:8px 14px;border:1px solid #7fa9a4;border-radius:7px;background:#fff;color:#174b50;font:inherit;font-weight:650;text-decoration:none;white-space:normal}.system-portal-nav a:hover{background:#dcefed}.system-portal-nav a:focus-visible{outline:3px solid #df9b35;outline-offset:3px}.system-portal-nav button:disabled{opacity:.65;cursor:default}@media print{.system-portal-nav{display:none}}' . "\n";
            // Append to an existing style block, retaining the page's CSP nonce.
            if (stripos($html, '</style>') !== false) $html = preg_replace_callback('~</style>~i', static fn() => $css . '</style>', $html, 1);
            $navigation = self::button($vault, $preferred);
            if (preg_match('~<main\b[^>]*>~i', $html)) {
                return preg_replace_callback('~<main\b[^>]*>~i', static fn($m) => $m[0] . $navigation, $html, 1);
            }
            // Vault login screens use a centered .box inside a flex body.
            if (preg_match('~<div\b[^>]*\bclass=["\x27]box["\x27][^>]*>~i', $html)) {
                return preg_replace_callback('~<div\b[^>]*\bclass=["\x27]box["\x27][^>]*>~i', static fn($m) => $m[0] . $navigation, $html, 1);
            }
            return preg_replace_callback('~<body\b[^>]*>~i', static fn($m) => $m[0] . $navigation, $html, 1);
        }
    }
}
