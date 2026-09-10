<?php

// Version 1.0.0
// Standalone Hikvision linkage configuration module.
// Keeps camera configuration traffic separate from the alarm-processing module.

class HikvisionCameraConfiguration extends IPSModule
{
    private const RUNTIME_VERSION = '2026-09-10-01';
    private const WEBHOOK_MODULE_GUID = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';

    public function Create()
    {
        parent::Create();

        // Defaults match the currently tested installation and remain configurable.
        $this->RegisterPropertyInteger('SecretsManagerInstanceID', 47118);
        $this->RegisterPropertyString('SecretName', 'Hikvision');
        $this->RegisterPropertyInteger('CameraRootID', 11654);
        $this->RegisterPropertyString('CameraVariableNameContains', 'IP-192.168.50.');
        $this->RegisterPropertyInteger('CurlTimeout', 15);
        $this->RegisterPropertyBoolean('Debug', false);

        $this->RegisterVariableString('ConfigurationHTML', 'Camera Configuration', '~HTMLBox', 10);
        $this->RegisterVariableString('WebhookPath', 'Webhook Path', '', 20);
        $this->RegisterVariableString('LastRefresh', 'Last Refresh', '', 30);
        $this->RegisterVariableString('LastResult', 'Last Result', '', 40);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $hook = $this->GetWebhookPath();
        $this->SetValue('WebhookPath', $hook);

        if (!$this->ValidateConfiguration()) {
            $this->SetStatus(201);
            $this->SetPlaceholderHTML('Configuration incomplete. Please configure SecretsManager and camera root object.');
            return;
        }

        if (!$this->RegisterHook($hook)) {
            $this->SetStatus(202);
            $this->SetPlaceholderHTML('Webhook registration failed or the path is already used by another instance.');
            return;
        }

        $this->SetStatus(102);

        if ($this->GetValue('ConfigurationHTML') === '') {
            $this->SetPlaceholderHTML('Module ready. Use "Refresh camera configuration" or open the webhook path.');
        }
    }

    public function Destroy()
    {
        $this->UnregisterHook($this->GetWebhookPath());
        parent::Destroy();
    }

    public function RefreshConfiguration(): bool
    {
        $result = $this->BuildConfigurationPage();

        $this->SetValue('LastRefresh', date('Y-m-d H:i:s'));
        $this->SetValue('LastResult', $result['message']);

        if (!$result['success']) {
            $this->SetPlaceholderHTML($result['message']);
            return false;
        }

        $this->SetValue('ConfigurationHTML', $result['html']);
        return true;
    }

    public function GetWebhookPath(): string
    {
        return '/hook/hikvision_config_' . $this->InstanceID;
    }

    public function ProcessHookData()
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if (!$this->EnsurePortalAuthentication($method)) {
            return;
        }

        if ($method === 'POST') {
            $this->HandleSaveRequest();
            return;
        }

        if ($method !== 'GET') {
            http_response_code(405);
            header('Allow: GET, POST');
            echo 'Method Not Allowed';
            return;
        }

        $result = $this->BuildConfigurationPage();
        $this->SetValue('LastRefresh', date('Y-m-d H:i:s'));
        $this->SetValue('LastResult', $result['message']);

        if (!$result['success']) {
            http_response_code(500);
            echo htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8');
            return;
        }

        $this->SetValue('ConfigurationHTML', $result['html']);
        header('Content-Type: text/html; charset=UTF-8');
        echo $result['html'];
    }

    private function ValidateConfiguration(): bool
    {
        $secretInstanceID = $this->ReadPropertyInteger('SecretsManagerInstanceID');
        $cameraRootID = $this->ReadPropertyInteger('CameraRootID');

        if ($secretInstanceID <= 0 || !IPS_InstanceExists($secretInstanceID)) {
            return false;
        }

        if ($cameraRootID <= 0 || !IPS_ObjectExists($cameraRootID)) {
            return false;
        }

        return true;
    }

    private function EnsurePortalAuthentication(string $requestMethod): bool
    {
        $instanceID = $this->ReadPropertyInteger('SecretsManagerInstanceID');

        if (!function_exists('SEC_IsPortalAuthenticated')) {
            $this->SendJsonOrTextError($requestMethod, 503, 'SEC_IsPortalAuthenticated is not available.');
            return false;
        }

        if (SEC_IsPortalAuthenticated($instanceID)) {
            return true;
        }

        if ($requestMethod === 'POST') {
            $this->SendJsonResponse([
                'success' => false,
                'version' => self::RUNTIME_VERSION,
                'message' => 'Authentication expired. Reload the configuration page.'
            ], 401);
            return false;
        }

        $currentUrl = $_SERVER['REQUEST_URI'] ?? $this->GetWebhookPath();
        $loginUrl = '/hook/secrets_' . $instanceID . '?portal=1&return=' . urlencode($currentUrl);
        header('Location: ' . $loginUrl);
        return false;
    }

    private function SendJsonOrTextError(string $requestMethod, int $status, string $message): void
    {
        if ($requestMethod === 'POST') {
            $this->SendJsonResponse([
                'success' => false,
                'version' => self::RUNTIME_VERSION,
                'message' => $message
            ], $status);
            return;
        }

        http_response_code($status);
        echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    }

    private function GetCredentials(): array
    {
        if (!function_exists('SEC_GetSecret')) {
            return ['success' => false, 'message' => 'SEC_GetSecret is not available.'];
        }

        $instanceID = $this->ReadPropertyInteger('SecretsManagerInstanceID');
        $secretName = trim($this->ReadPropertyString('SecretName'));

        $raw = SEC_GetSecret($instanceID, $secretName);
        $data = json_decode((string)$raw, true);

        if (!is_array($data)) {
            return ['success' => false, 'message' => 'SecretsManager returned invalid JSON for secret "' . $secretName . '".'];
        }

        $username = (string)($data['User'] ?? '');
        $password = (string)($data['Pass'] ?? '');

        if ($username === '' || $password === '') {
            return ['success' => false, 'message' => 'Hikvision username or password is missing in SecretsManager.'];
        }

        return [
            'success' => true,
            'username' => $username,
            'password' => $password
        ];
    }

    private function BuildConfigurationPage(): array
    {
        $credentials = $this->GetCredentials();
        if (!$credentials['success']) {
            return ['success' => false, 'message' => $credentials['message'], 'html' => ''];
        }

        $inventory = $this->GetCameraInventory();
        if ($inventory === []) {
            return ['success' => false, 'message' => 'No camera IP variables found under the configured root object.', 'html' => ''];
        }

        $cameraData = [];
        $capabilities = [];
        $cameraNames = [];

        foreach ($inventory as $ip => $cameraName) {
            $cameraNames[$ip] = $cameraName;
            $cameraData[$ip] = $this->LoadXmlAsArray(
                'http://' . $ip . '/ISAPI/Event/triggers/',
                $credentials['username'],
                $credentials['password']
            );
            $capabilities[$ip] = $this->LoadXmlAsArray(
                'http://' . $ip . '/ISAPI/Event/triggersCap',
                $credentials['username'],
                $credentials['password']
            );
        }

        $triggerToCapMap = $this->GetTriggerToCapabilityMap();
        $alarmToCapMap = $this->GetAlarmToCapabilityMap();

        $analysis = $this->AnalyzeCameraData(
            $cameraData,
            array_keys($triggerToCapMap),
            array_keys($alarmToCapMap)
        );

        $html = $this->GenerateHtmlPage(
            $analysis,
            $capabilities,
            $cameraNames,
            $triggerToCapMap,
            $alarmToCapMap,
            $this->GetWebhookPath()
        );

        $size = strlen($html);
        $this->DebugLog('Generated HTML size: ' . $size . ' bytes');

        if ($size >= 1000000) {
            return [
                'success' => false,
                'message' => 'Generated HTML is too large (' . $size . ' bytes).',
                'html' => ''
            ];
        }

        return [
            'success' => true,
            'message' => 'Configuration loaded from ' . count($inventory) . ' camera(s).',
            'html' => $html
        ];
    }

    private function HandleSaveRequest(): void
    {
        $credentials = $this->GetCredentials();
        if (!$credentials['success']) {
            $this->SendJsonResponse([
                'success' => false,
                'version' => self::RUNTIME_VERSION,
                'message' => $credentials['message']
            ], 500);
            return;
        }

        $rawData = file_get_contents('php://input');
        $data = json_decode((string)$rawData, true);

        if (!is_array($data)) {
            $this->SendJsonResponse([
                'success' => false,
                'version' => self::RUNTIME_VERSION,
                'message' => 'Invalid JSON request.'
            ], 400);
            return;
        }

        $changes = $data['changes'] ?? null;

        if (!is_array($changes) && isset($data['ip'], $data['trigger'], $data['alarm'], $data['status'])) {
            $changes = [[
                'ip' => $data['ip'],
                'trigger' => $data['trigger'],
                'alarm' => $data['alarm'],
                'status' => $data['status']
            ]];
        }

        if (!is_array($changes) || count($changes) === 0) {
            $this->SendJsonResponse([
                'success' => true,
                'version' => self::RUNTIME_VERSION,
                'message' => 'No changes submitted.',
                'results' => []
            ]);
            return;
        }

        if (count($changes) > 250) {
            $this->SendJsonResponse([
                'success' => false,
                'version' => self::RUNTIME_VERSION,
                'message' => 'Too many changes in one request.'
            ], 400);
            return;
        }

        $inventory = $this->GetCameraInventory();
        $triggerToCapMap = $this->GetTriggerToCapabilityMap();
        $alarmToCapMap = $this->GetAlarmToCapabilityMap();
        $groups = [];
        $errors = [];

        foreach ($changes as $index => $change) {
            if (!is_array($change)) {
                $errors[] = 'Change #' . ($index + 1) . ' is invalid.';
                continue;
            }

            $ip = trim((string)($change['ip'] ?? ''));
            $trigger = trim((string)($change['trigger'] ?? ''));
            $alarm = trim((string)($change['alarm'] ?? ''));
            $status = strtolower(trim((string)($change['status'] ?? '')));

            if (!isset($inventory[$ip])) {
                $errors[] = 'Unknown camera IP: ' . $ip;
                continue;
            }
            if (!isset($triggerToCapMap[$trigger])) {
                $errors[] = 'Unknown trigger: ' . $trigger;
                continue;
            }
            if (!isset($alarmToCapMap[$alarm])) {
                $errors[] = 'Unknown alarm method: ' . $alarm;
                continue;
            }
            if (!in_array($status, ['green', 'red'], true)) {
                $errors[] = 'Invalid status for ' . $ip . ' / ' . $trigger . ' / ' . $alarm;
                continue;
            }

            $groups[$ip][$trigger][$alarm] = $status;
        }

        if ($errors !== []) {
            $this->DebugLog('Validation failed: ' . implode(' | ', $errors), KL_ERROR);
            $this->SendJsonResponse([
                'success' => false,
                'version' => self::RUNTIME_VERSION,
                'message' => 'Configuration validation failed.',
                'errors' => $errors
            ], 400);
            return;
        }

        $results = [];
        $allSuccessful = true;

        foreach ($groups as $ip => $triggerGroups) {
            foreach ($triggerGroups as $triggerID => $requestedStates) {
                $result = $this->ApplyNotificationChanges(
                    $ip,
                    $credentials['username'],
                    $credentials['password'],
                    $triggerID,
                    $requestedStates
                );

                $results[] = [
                    'ip' => $ip,
                    'camera' => $inventory[$ip],
                    'trigger' => $triggerID,
                    'changes' => $requestedStates,
                    'success' => $result['success'],
                    'changed' => $result['changed'] ?? false,
                    'message' => $result['message']
                ];

                if (!$result['success']) {
                    $allSuccessful = false;
                }
            }
        }

        $log = 'Configuration save ' . self::RUNTIME_VERSION . ': ' . json_encode(
            $results,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $this->DebugLog($log, $allSuccessful ? KL_DEBUG : KL_ERROR);
        $this->SetValue('LastResult', $allSuccessful ? 'Configuration successfully applied.' : 'One or more changes failed.');

        $this->SendJsonResponse([
            'success' => $allSuccessful,
            'version' => self::RUNTIME_VERSION,
            'message' => $allSuccessful ? 'Configuration successfully applied.' : 'One or more camera changes failed.',
            'results' => $results
        ], $allSuccessful ? 200 : 500);
    }

    private function ApplyNotificationChanges(
        string $ip,
        string $username,
        string $password,
        string $triggerID,
        array $requestedStates
    ): array {
        $url = 'http://' . $ip . '/ISAPI/Event/triggers/' . rawurlencode($triggerID) . '/notifications';

        $getResult = $this->HikvisionRequest('GET', $url, $username, $password);
        if (!$getResult['success']) {
            return ['success' => false, 'changed' => false, 'message' => 'GET notifications failed: ' . $getResult['error']];
        }

        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;

        libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($getResult['body']);
        $xmlErrors = libxml_get_errors();
        libxml_clear_errors();

        if (!$loaded || $doc->documentElement === null) {
            $detail = $xmlErrors !== [] ? trim((string)$xmlErrors[0]->message) : '';
            return [
                'success' => false,
                'changed' => false,
                'message' => 'Invalid notification XML returned by camera' . ($detail !== '' ? ': ' . $detail : '.')
            ];
        }

        $root = $doc->documentElement;
        if ($root->localName !== 'EventTriggerNotificationList') {
            return ['success' => false, 'changed' => false, 'message' => 'Unexpected XML root: ' . $root->localName];
        }

        $namespaceURI = $root->namespaceURI ?: 'http://www.isapi.org/ver20/XMLSchema';
        $changed = false;
        $details = [];

        foreach ($requestedStates as $alarm => $status) {
            $matchingNodes = $this->FindMatchingNotificationNodes($root, $alarm);

            if ($status === 'green') {
                if ($matchingNodes === []) {
                    $root->appendChild($this->CreateNotificationNode($doc, $namespaceURI, $alarm));
                    $changed = true;
                    $details[] = $alarm . '=enabled';
                } else {
                    $details[] = $alarm . '=already enabled';
                }
                continue;
            }

            if ($matchingNodes !== []) {
                foreach ($matchingNodes as $node) {
                    $root->removeChild($node);
                }
                $changed = true;
                $details[] = $alarm . '=disabled';
            } else {
                $details[] = $alarm . '=already disabled';
            }
        }

        if (!$changed) {
            return ['success' => true, 'changed' => false, 'message' => implode(', ', $details)];
        }

        $modifiedXml = $doc->saveXML();
        if ($modifiedXml === false) {
            return ['success' => false, 'changed' => false, 'message' => 'Unable to serialize modified XML.'];
        }

        $putResult = $this->HikvisionRequest('PUT', $url, $username, $password, $modifiedXml);
        if (!$putResult['success']) {
            return ['success' => false, 'changed' => false, 'message' => 'PUT notifications failed: ' . $putResult['error']];
        }

        $isapiStatus = $this->ValidateIsapiResponse($putResult['body']);
        if (!$isapiStatus['success']) {
            return ['success' => false, 'changed' => false, 'message' => 'Camera rejected configuration: ' . $isapiStatus['message']];
        }

        return ['success' => true, 'changed' => true, 'message' => implode(', ', $details)];
    }

    private function HikvisionRequest(
        string $method,
        string $url,
        string $username,
        string $password,
        ?string $body = null
    ): array {
        $ch = curl_init();
        if ($ch === false) {
            return ['success' => false, 'httpCode' => 0, 'body' => '', 'error' => 'Unable to initialize cURL.'];
        }

        $timeout = max(1, $this->ReadPropertyInteger('CurlTimeout'));
        $headers = ['Accept: application/xml'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/xml; charset=UTF-8';
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $username . ':' . $password,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $curlErrorNo = curl_errno($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErrorNo !== 0 || $response === false) {
            return [
                'success' => false,
                'httpCode' => $httpCode,
                'body' => '',
                'error' => $curlError !== '' ? $curlError : 'Unknown cURL error.'
            ];
        }

        $success = $httpCode >= 200 && $httpCode < 300;
        return [
            'success' => $success,
            'httpCode' => $httpCode,
            'body' => (string)$response,
            'error' => $success ? '' : 'HTTP ' . $httpCode
        ];
    }

    private function LoadXmlAsArray(string $url, string $username, string $password): array
    {
        $result = $this->HikvisionRequest('GET', $url, $username, $password);
        if (!$result['success']) {
            return ['error' => 'Failed to fetch data: ' . $result['error']];
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($result['body']);
        libxml_clear_errors();

        if ($xml === false) {
            return ['error' => 'Failed to parse XML'];
        }

        $array = json_decode(json_encode($xml), true);
        return is_array($array) ? $array : ['error' => 'Failed to convert XML'];
    }

    private function ValidateIsapiResponse(string $body): array
    {
        if (trim($body) === '') {
            return ['success' => true, 'message' => 'OK'];
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();

        if ($xml === false) {
            return ['success' => true, 'message' => 'HTTP request accepted'];
        }

        $statusCode = (string)($xml->statusCode ?? '');
        if ($statusCode === '' || $statusCode === '1') {
            return ['success' => true, 'message' => (string)($xml->statusString ?? 'OK')];
        }

        $parts = ['statusCode=' . $statusCode];
        $statusString = trim((string)($xml->statusString ?? ''));
        $subStatusCode = trim((string)($xml->subStatusCode ?? ''));
        if ($statusString !== '') {
            $parts[] = 'statusString=' . $statusString;
        }
        if ($subStatusCode !== '') {
            $parts[] = 'subStatusCode=' . $subStatusCode;
        }

        return ['success' => false, 'message' => implode(', ', $parts)];
    }

    private function FindMatchingNotificationNodes(DOMElement $root, string $alarm): array
    {
        $matches = [];

        foreach ($root->childNodes as $child) {
            if (!$child instanceof DOMElement || $child->localName !== 'EventTriggerNotification') {
                continue;
            }

            $id = $this->GetDirectChildText($child, 'id');
            $method = $this->GetDirectChildText($child, 'notificationMethod');
            $isMatch = false;

            switch ($alarm) {
                case 'record':
                    $isMatch = ($method === 'record' || $id === 'record-1');
                    break;
                case 'IO-1':
                    $isMatch = ($method === 'IO' || $id === 'IO-1');
                    break;
                default:
                    $isMatch = ($method === $alarm || $id === $alarm);
                    break;
            }

            if ($isMatch) {
                $matches[] = $child;
            }
        }

        return $matches;
    }

    private function GetDirectChildText(DOMElement $parent, string $localName): string
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                return trim($child->textContent);
            }
        }
        return '';
    }

    private function CreateNotificationNode(DOMDocument $doc, string $namespaceURI, string $alarm): DOMElement
    {
        $specs = [
            'email' => ['id' => 'email', 'method' => 'email'],
            'FTP' => ['id' => 'FTP', 'method' => 'FTP'],
            'record' => ['id' => 'record-1', 'method' => 'record'],
            'center' => ['id' => 'center', 'method' => 'center'],
            'supplementLight' => ['id' => 'supplementLight', 'method' => 'supplementLight'],
            'whiteLight' => ['id' => 'whiteLight', 'method' => 'whiteLight'],
            'beep' => ['id' => 'beep', 'method' => 'beep'],
            'IO-1' => ['id' => 'IO-1', 'method' => 'IO']
        ];

        if (!isset($specs[$alarm])) {
            throw new InvalidArgumentException('Unsupported alarm method: ' . $alarm);
        }

        $spec = $specs[$alarm];
        $notification = $doc->createElementNS($namespaceURI, 'EventTriggerNotification');
        $this->AppendNsElement($doc, $notification, $namespaceURI, 'id', $spec['id']);
        $this->AppendNsElement($doc, $notification, $namespaceURI, 'notificationMethod', $spec['method']);
        $this->AppendNsElement($doc, $notification, $namespaceURI, 'notificationRecurrence', 'beginning');

        if ($alarm === 'record') {
            $this->AppendNsElement($doc, $notification, $namespaceURI, 'videoInputID', '1');
        } elseif ($alarm === 'IO-1') {
            $this->AppendNsElement($doc, $notification, $namespaceURI, 'outputIOPortID', '1');
        } elseif ($alarm === 'whiteLight') {
            $action = $doc->createElementNS($namespaceURI, 'WhiteLightAction');
            $this->AppendNsElement($doc, $action, $namespaceURI, 'whiteLightDurationTime', '5');
            $notification->appendChild($action);
        } elseif ($alarm === 'supplementLight') {
            $action = $doc->createElementNS($namespaceURI, 'SupplementLightAlarm');
            $this->AppendNsElement($doc, $action, $namespaceURI, 'durationTime', '5');
            $notification->appendChild($action);
        }

        return $notification;
    }

    private function AppendNsElement(
        DOMDocument $doc,
        DOMElement $parent,
        string $namespaceURI,
        string $name,
        string $value
    ): DOMElement {
        $element = $doc->createElementNS($namespaceURI, $name);
        $element->appendChild($doc->createTextNode($value));
        $parent->appendChild($element);
        return $element;
    }

    private function GetCameraInventory(): array
    {
        $rootID = $this->ReadPropertyInteger('CameraRootID');
        $needle = $this->ReadPropertyString('CameraVariableNameContains');
        $ids = [];

        if (!IPS_ObjectExists($rootID)) {
            return [];
        }

        $this->FindObjectsRecursive($rootID, 2, $needle, $ids);

        $inventory = [];
        foreach ($ids as $variableID) {
            $ip = trim((string)GetValueString($variableID));
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                continue;
            }
            $inventory[$ip] = IPS_GetName(IPS_GetParent($variableID));
        }

        ksort($inventory, SORT_NATURAL);
        return $inventory;
    }

    private function FindObjectsRecursive(int $objectID, int $objectType, string $nameContains, array &$ids): void
    {
        $object = IPS_GetObject($objectID);

        if (
            $object['ObjectType'] === $objectType &&
            mb_strpos($object['ObjectName'], $nameContains) !== false
        ) {
            $ids[] = $objectID;
        }

        foreach (IPS_GetChildrenIDs($objectID) as $childID) {
            $this->FindObjectsRecursive($childID, $objectType, $nameContains, $ids);
        }
    }

    private function AnalyzeCameraData(array $cameraData, array $eventTriggerIDs, array $alarmTypes): array
    {
        $results = [];

        foreach ($cameraData as $ip => $data) {
            $results[$ip] = [];
            foreach ($eventTriggerIDs as $triggerID) {
                $results[$ip][$triggerID] = array_fill_keys($alarmTypes, 'red');
            }

            if (!isset($data['EventTrigger']) || !is_array($data['EventTrigger'])) {
                continue;
            }

            $triggerEntries = $data['EventTrigger'];
            if (isset($triggerEntries['id'])) {
                $triggerEntries = [$triggerEntries];
            }

            foreach ($triggerEntries as $triggerEntry) {
                if (!is_array($triggerEntry) || !isset($triggerEntry['id'])) {
                    continue;
                }

                $currentTriggerID = (string)$triggerEntry['id'];
                if (!in_array($currentTriggerID, $eventTriggerIDs, true)) {
                    continue;
                }

                $notifications = $triggerEntry['EventTriggerNotificationList']['EventTriggerNotification'] ?? null;
                if (!is_array($notifications)) {
                    continue;
                }

                if (isset($notifications['id']) || isset($notifications['notificationMethod'])) {
                    $notifications = [$notifications];
                }

                foreach ($notifications as $notification) {
                    if (!is_array($notification)) {
                        continue;
                    }

                    $method = (string)($notification['notificationMethod'] ?? '');
                    $id = (string)($notification['id'] ?? '');

                    if (in_array($method, $alarmTypes, true)) {
                        $results[$ip][$currentTriggerID][$method] = 'green';
                    }

                    foreach ($alarmTypes as $alarm) {
                        if ($id !== '' && strpos($id, $alarm) === 0) {
                            $results[$ip][$currentTriggerID][$alarm] = 'green';
                        }
                    }

                    if ($method === 'IO' && $id === 'IO-1') {
                        $results[$ip][$currentTriggerID]['IO-1'] = 'green';
                    }
                }
            }
        }

        return $results;
    }

    private function GenerateHtmlPage(
        array $analysisResult,
        array $capabilities,
        array $cameraNames,
        array $triggerToCapMap,
        array $alarmToCapMap,
        string $hookPath
    ): string {
        foreach ($analysisResult as $ip => &$triggers) {
            foreach ($triggers as $triggerID => &$alarms) {
                $capKey = $triggerToCapMap[$triggerID] ?? null;

                foreach ($alarms as $alarmType => &$status) {
                    if ($status === 'green') {
                        continue;
                    }

                    $isSupported = false;
                    if (
                        $capKey !== null &&
                        isset($capabilities[$ip][$capKey]) &&
                        is_array($capabilities[$ip][$capKey]) &&
                        isset($alarmToCapMap[$alarmType])
                    ) {
                        $supportKey = $alarmToCapMap[$alarmType];
                        $capValue = $capabilities[$ip][$capKey][$supportKey] ?? null;
                        $isSupported = ($capValue === true || $capValue === 'true' || $capValue === 1 || $capValue === '1');
                    }

                    if (!$isSupported) {
                        $status = 'yellow';
                    }
                }
                unset($status);
            }
            unset($alarms);
        }
        unset($triggers);

        $version = self::RUNTIME_VERSION;
        $versionJson = json_encode($version, JSON_UNESCAPED_SLASHES);
        $hookJson = json_encode($hookPath, JSON_UNESCAPED_SLASHES);

        // Deliberately compact to stay well below IP-Symcon's 1 MiB output-buffer limit.
        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        $html .= '<title>Hikvision Camera Configuration</title><style>';
        $html .= 'body{font-family:Arial,sans-serif;background:#f0f0f0;margin:0;padding:10px}.head{margin:10px}.ver{color:#666;font-size:.85em;margin-top:4px}.cam{border:1px solid #333;background:#fff;padding:10px;margin:10px;display:inline-block;vertical-align:top;width:250px}.ip{font-weight:bold;font-size:1.2em;margin-bottom:5px}.name{margin-bottom:10px;color:#555}.trg{margin-bottom:15px}.trgt{font-weight:bold;margin-bottom:5px}.alarm{display:inline-block;padding:5px 10px;margin:5px 5px 0 0;border-radius:3px;font-size:.9em;text-align:center;cursor:pointer;user-select:none}.alarm[data-toggleable="false"]{cursor:not-allowed;opacity:.6}.submit{margin:20px 10px}#saveStatus{margin-top:10px;white-space:pre-wrap}.ok{color:#146c2e}.err{color:#a40000}';
        $html .= '</style><script>';
        $html .= 'const PAGE_VERSION=' . $versionJson . ',HOOK_URL=' . $hookJson . ';';
        $html .= 'function toggleAlarm(e){if(e.dataset.toggleable==="false")return;const c=e.dataset.color,i=e.dataset.initialcolor,n=c==="green"?"red":"green";e.dataset.color=n;if(n!==i){e.style.backgroundColor=n==="green"?"lightgreen":"lightcoral";e.style.color="black"}else{e.style.backgroundColor=n==="green"?"green":"red";e.style.color="white"}}';
        $html .= 'async function submitConfiguration(ev){ev.preventDefault();const st=document.getElementById("saveStatus"),bt=document.getElementById("submitButton"),els=Array.from(document.querySelectorAll(".alarm")).filter(e=>e.dataset.toggleable!=="false"&&e.dataset.color!==e.dataset.initialcolor);if(els.length===0){st.className="ok";st.textContent="No changes to save.";return}const changes=els.map(e=>({ip:e.dataset.ip,trigger:e.dataset.trigger,alarm:e.dataset.name,status:e.dataset.color}));bt.disabled=true;st.className="";st.textContent="Saving "+changes.length+" change(s)...";try{const r=await fetch(HOOK_URL,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json","Accept":"application/json"},body:JSON.stringify({version:PAGE_VERSION,changes})});if(r.status===401){window.location.href=HOOK_URL;return}const txt=await r.text();let result;try{result=JSON.parse(txt)}catch(x){throw new Error("Server did not return JSON: "+txt.substring(0,500))}if(!r.ok||!result.success){let d="";if(Array.isArray(result.results))d=result.results.filter(x=>!x.success).map(x=>x.camera+" / "+x.trigger+": "+x.message).join("\\n");throw new Error((result.message||"Configuration failed")+(d?"\\n"+d:""))}st.className="ok";st.textContent=result.message+" Reloading...";window.location.href=HOOK_URL}catch(error){st.className="err";st.textContent="Save failed: "+error.message;bt.disabled=false}}';
        $html .= '</script></head><body><div class="head"><strong>Hikvision Event Configuration</strong><div class="ver">Version ' . htmlspecialchars($version, ENT_QUOTES, 'UTF-8') . '</div></div><form id="configForm" onsubmit="submitConfiguration(event)">';

        foreach ($analysisResult as $ip => $triggers) {
            $safeIp = htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');
            $html .= '<div class="cam"><div class="ip">' . $safeIp . '</div>';

            if (isset($cameraNames[$ip])) {
                $html .= '<div class="name">' . htmlspecialchars($cameraNames[$ip], ENT_QUOTES, 'UTF-8') . '</div>';
            }

            foreach ($triggers as $triggerID => $alarms) {
                $safeTrigger = htmlspecialchars($triggerID, ENT_QUOTES, 'UTF-8');
                $html .= '<div class="trg"><div class="trgt">' . $safeTrigger . '</div>';

                foreach ($alarms as $alarmType => $status) {
                    if ($status === 'green') {
                        $color = 'green';
                        $textColor = 'white';
                        $toggleable = 'true';
                    } elseif ($status === 'yellow') {
                        $color = 'yellow';
                        $textColor = 'black';
                        $toggleable = 'false';
                    } else {
                        $color = 'red';
                        $textColor = 'white';
                        $toggleable = 'true';
                    }

                    $safeAlarm = htmlspecialchars($alarmType, ENT_QUOTES, 'UTF-8');
                    $html .= '<div class="alarm" style="background-color:' . $color . ';color:' . $textColor . '" data-color="' . $color . '" data-initialcolor="' . $color . '" data-name="' . $safeAlarm . '" data-toggleable="' . $toggleable . '" data-ip="' . $safeIp . '" data-trigger="' . $safeTrigger . '" onclick="toggleAlarm(this)">' . $safeAlarm . '</div>';
                }

                $html .= '</div>';
            }

            $html .= '</div>';
        }

        $html .= '<div class="submit"><button id="submitButton" type="submit">Submit Configuration</button><div id="saveStatus"></div></div></form></body></html>';
        return $html;
    }

    private function GetTriggerToCapabilityMap(): array
    {
        return [
            'linedetection-1' => 'LineDetectTriggerCap',
            'fielddetection-1' => 'FiledDetectTriggerCap',
            'regionEntrance-1' => 'RegionEntranceTriggerCap',
            'regionExiting-1' => 'RegionExitingTriggerCap',
            'nicbroken' => 'NicbrokenTriggerCap',
            'ipconflict' => 'IpconflictTriggerCap',
            'illaccess' => 'IllaccesTriggerCap',
            'tamper-1' => 'TamperDetectionTriggerCap',
            'SceneChangeDetection' => 'SceneChangeDetectionTriggerCap',
            'storageDetection-1' => 'StorageDetectionTriggerCap',
            'diskfull' => 'DiskfullTriggerCap',
            'diskerror' => 'DiskerrorTriggerCap'
        ];
    }

    private function GetAlarmToCapabilityMap(): array
    {
        return [
            'email' => 'isSupportEmail',
            'FTP' => 'isSupportFTP',
            'record' => 'isSupportRecord',
            'center' => 'isSupportCenter',
            'supplementLight' => 'isSupportSupplementLightAlarm',
            'whiteLight' => 'isSupportWhiteLight',
            'beep' => 'isSupportBeep',
            'IO-1' => 'isSupportIO'
        ];
    }

    private function RegisterHook(string $hook): bool
    {
        $ids = IPS_GetInstanceListByModuleID(self::WEBHOOK_MODULE_GUID);
        if (count($ids) === 0) {
            $this->DebugLog('No WebHook Control instance found.', KL_ERROR);
            return false;
        }

        $webHookControlID = $ids[0];
        $hooks = json_decode(IPS_GetProperty($webHookControlID, 'Hooks'), true);
        if (!is_array($hooks)) {
            $hooks = [];
        }

        foreach ($hooks as $entry) {
            if (($entry['Hook'] ?? '') === $hook && (int)($entry['TargetID'] ?? 0) !== $this->InstanceID) {
                $this->DebugLog('Webhook path already used by another target: ' . $hook, KL_ERROR);
                return false;
            }
        }

        $newHooks = [];
        $added = false;

        foreach ($hooks as $entry) {
            if ((int)($entry['TargetID'] ?? 0) === $this->InstanceID) {
                if (!$added) {
                    $newHooks[] = ['Hook' => $hook, 'TargetID' => $this->InstanceID];
                    $added = true;
                }
                continue;
            }
            $newHooks[] = $entry;
        }

        if (!$added) {
            $newHooks[] = ['Hook' => $hook, 'TargetID' => $this->InstanceID];
        }

        if (json_encode($newHooks) !== json_encode($hooks)) {
            IPS_SetProperty($webHookControlID, 'Hooks', json_encode($newHooks));
            IPS_ApplyChanges($webHookControlID);
            $this->DebugLog('Registered webhook ' . $hook);
        }

        return true;
    }

    private function UnregisterHook(string $hook): void
    {
        $ids = IPS_GetInstanceListByModuleID(self::WEBHOOK_MODULE_GUID);
        if (count($ids) === 0) {
            return;
        }

        $webHookControlID = $ids[0];
        $hooks = json_decode(IPS_GetProperty($webHookControlID, 'Hooks'), true);
        if (!is_array($hooks)) {
            return;
        }

        $newHooks = array_values(array_filter($hooks, function ($entry) use ($hook) {
            return !(
                (int)($entry['TargetID'] ?? 0) === $this->InstanceID &&
                ($entry['Hook'] ?? '') === $hook
            );
        }));

        if (count($newHooks) !== count($hooks)) {
            IPS_SetProperty($webHookControlID, 'Hooks', json_encode($newHooks));
            IPS_ApplyChanges($webHookControlID);
        }
    }

    private function SendJsonResponse(array $payload, int $httpStatus = 200): void
    {
        http_response_code($httpStatus);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
    }

    private function SetPlaceholderHTML(string $message): void
    {
        $hook = htmlspecialchars($this->GetWebhookPath(), ENT_QUOTES, 'UTF-8');
        $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $html = '<div style="font-family:Arial,sans-serif;padding:12px"><strong>Hikvision Camera Configuration</strong><br><small>Version ' . self::RUNTIME_VERSION . '</small><p>' . $safe . '</p><p>Webhook: <code>' . $hook . '</code></p></div>';
        $this->SetValue('ConfigurationHTML', $html);
        $this->SetValue('LastResult', $message);
    }

    private function DebugLog(string $message, int $severity = KL_DEBUG): void
    {
        if ($this->ReadPropertyBoolean('Debug') || $severity === KL_ERROR) {
            $this->LogMessage(self::RUNTIME_VERSION . ' | ' . $message, $severity);
        }
    }
}
