<?php
declare(strict_types=1);
// Native Symcon metadata fixture. No destination module, action or live vault is invoked.
require __DIR__ . '/../libs/SystemPortalNavigation.php';
const PORTAL_GUID = '{922E5A10-A048-463D-A973-97B4BBD9096B}';
const VAULT_GUID = '{7C5A3841-3F7B-4D2A-9E1C-5B6D8F9A0E12}';
const HOOK_GUID = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';
function IPS_InstanceExists($id) { return isset($GLOBALS['native'][$id]); }
function IPS_GetInstance($id) { return ['ModuleInfo' => ['ModuleID'=>$GLOBALS['native'][$id]['guid'], 'ModuleName'=>$GLOBALS['native'][$id]['name'] ?? 'SystemPortal']]; }
function IPS_GetProperty($id,$key) { if (!array_key_exists($key,$GLOBALS['native'][$id]['props'])) throw new RuntimeException('Missing property'); return $GLOBALS['native'][$id]['props'][$key]; }
function IPS_GetInstanceListByModuleID($guid) { return array_keys(array_filter($GLOBALS['native'], static fn($row) => $row['guid'] === $guid)); }
function baseline(): void {
    $GLOBALS['native'] = [
        5=>['guid'=>HOOK_GUID, 'props'=>['Hooks'=>json_encode([['Hook'=>'/hook/alarm_portal_43','TargetID'=>43]])]],
        7=>['guid'=>VAULT_GUID, 'props'=>['PortalEnabled'=>true,'PortalOrigin'=>'https://system.example']],
        43=>['guid'=>PORTAL_GUID, 'props'=>['Enabled'=>true,'VaultInstanceID'=>7]]
    ];
}
$count=0;
function expect($condition,$label): void { global $count; $count++; if (!$condition) throw new RuntimeException($label); }
baseline();
expect(AFSystemPortalNavigationV1::path(7)==='/hook/alarm_portal_43','Resolve registered neutral portal');
$GLOBALS['native'][43]['name']='AlarmPortal';
expect(AFSystemPortalNavigationV1::path(7)==='/hook/alarm_portal_43','Legacy class label still resolves through stable GUID');
expect(AFSystemPortalNavigationV1::path(7,43)==='/hook/alarm_portal_43','Explicit selection resolves');
expect(AFSystemPortalNavigationV1::path(7,99)==='','Invalid explicit selection does not fall back');
expect(AFSystemPortalNavigationV1::path(99)==='','Other vault cannot be selected');
foreach ([['Enabled',false],['VaultInstanceID',99]] as [$key,$value]) {
 baseline();$GLOBALS['native'][43]['props'][$key]=$value;
 expect(AFSystemPortalNavigationV1::path(7)==='','Reject unavailable portal '.$key);
}
baseline();$GLOBALS['native'][43]['guid']='{OTHER}';expect(AFSystemPortalNavigationV1::path(7,43)==='','Reject wrong module GUID');
foreach (['[]','broken','{}',json_encode([['Hook'=>'/hook/alarm_portal_43','TargetID'=>999]]),json_encode([['Hook'=>'/hook/alarm_portal_43','TargetID'=>43],['Hook'=>'/hook/alarm_portal_43','TargetID'=>43]])] as $hooks) {
 baseline();$GLOBALS['native'][5]['props']['Hooks']=$hooks;
 expect(AFSystemPortalNavigationV1::path(7)==='','Reject missing, malformed, conflicting or duplicate webhook');
}
baseline();$GLOBALS['native'][6]=$GLOBALS['native'][5];expect(AFSystemPortalNavigationV1::path(7)==='','Reject ambiguous WebhookControl');
baseline();$GLOBALS['native'][43]['props']['VaultInstanceID']=0;expect(AFSystemPortalNavigationV1::path(7)==='/hook/alarm_portal_43','Resolve unique automatic portal vault');
$GLOBALS['native'][8]=$GLOBALS['native'][7];expect(AFSystemPortalNavigationV1::path(7)==='','Reject ambiguous automatic portal vault');
foreach (['http://system.example','https://user@system.example','https://user:pass@system.example','https://system.example/subpath','https://system.example?query=1','https://system.example#fragment','invalid'] as $origin) {
 baseline();$GLOBALS['native'][7]['props']['PortalOrigin']=$origin;
 expect(AFSystemPortalNavigationV1::path(7)==='','Reject invalid vault origin');
}
baseline();$GLOBALS['native'][7]['props']['PortalEnabled']=false;expect(AFSystemPortalNavigationV1::path(7)==='','Reject disabled vault');
baseline();$GLOBALS['native'][44]=$GLOBALS['native'][43];$GLOBALS['native'][5]['props']['Hooks']=json_encode([['Hook'=>'/hook/alarm_portal_43','TargetID'=>43],['Hook'=>'/hook/alarm_portal_44','TargetID'=>44]]);
expect(AFSystemPortalNavigationV1::path(7)==='','Reject ambiguous portals');
expect(AFSystemPortalNavigationV1::path(7,44)==='/hook/alarm_portal_44','Explicit portal resolves ambiguity');
$GLOBALS['native'][8]=$GLOBALS['native'][7];$GLOBALS['native'][44]['props']['VaultInstanceID']=8;
expect(AFSystemPortalNavigationV1::path(7)==='/hook/alarm_portal_43','Resolve same-vault portal in multi-vault installation');
expect(AFSystemPortalNavigationV1::path(8)==='/hook/alarm_portal_44','Resolve second vault separately');
baseline();
foreach (["<!doctype html><html><head><style nonce=\"fixture\">body{margin:0}</style></head><body><main><h1>Page</h1></main></body></html>", '<html><body><h1>Manual</h1></body></html>', '<html><body><div class="box"><h2>Sign in</h2></div></body></html>'] as $html) {
 $out=AFSystemPortalNavigationV1::inject($html,7);
 expect(substr_count($out,'id="system-portal-navigation"')===1,'Exactly one navigation on complete page');
 expect(str_contains($out,'href="/hook/alarm_portal_43" target="_top"'),'Navigate directly to top-level portal');
 expect(strpos($out,'id="system-portal-navigation"')<strpos($out,str_contains($html,'<h1>') ? '<h1>' : '<h2>'),'Navigation precedes first heading');
 expect(AFSystemPortalNavigationV1::inject($out,7)===$out,'Injection is idempotent');
 if(str_contains($html,'<style')) {
  expect(str_contains($out,"\n.system-portal-nav{"),'Navigation CSS has a real newline');
  expect(substr_count($out,'<style')===1 && str_contains($out,'nonce="fixture"'),'Existing style nonce retained');
 }
}
foreach (['{"ok":true,"html":"<html><body>data</body></html>"}','<div>AJAX content</div>',"\x89PNG\r\n\x1a\n",'','Sign in required'] as $body) expect(AFSystemPortalNavigationV1::inject($body,7)===$body,'Preserve API, fragment, image or plain-text bytes');
baseline();unset($GLOBALS['native'][43]);$out=AFSystemPortalNavigationV1::inject('<html><body><h1>Page</h1></body></html>',7);
expect(str_contains($out,'disabled title=') && !str_contains($out,'href='),'Unavailable portal is disabled, never guessed');
expect(!str_contains($out,'history.back') && !str_contains($out,'javascript:'),'No history or executable navigation fallback');
echo "System Portal navigation: $count assertions passed.\n";
