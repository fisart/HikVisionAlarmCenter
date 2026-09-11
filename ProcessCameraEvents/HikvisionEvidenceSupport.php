<?php

/**
 * Optional NVR evidence support for ProcessCameraEvents v1.6.5.
 *
 * Evidence capture is disabled by default. The alarm webhook only schedules a
 * short isolated worker; all HTTP communication with the evidence service is
 * performed outside the webhook-receiving module context.
 */
trait HikvisionEvidenceSupport
{
    public function GetConfigurationForm(): string
    {
        $formFile = __DIR__ . DIRECTORY_SEPARATOR . 'form.json';
        $form = json_decode((string) @file_get_contents($formFile), true);

        if (!is_array($form)) {
            return '{}';
        }

        $visible = $this->ReadPropertyBoolean('EnableEvidenceRecording');
        $cameraRows = $this->BuildEvidenceCameraRows();

        foreach (($form['elements'] ?? []) as $index => $element) {
            if (($element['name'] ?? '') !== 'EvidencePanel') {
                continue;
            }

            $form['elements'][$index]['visible'] = $visible;

            foreach (($element['items'] ?? []) as $itemIndex => $item) {
                if (($item['name'] ?? '') === 'EvidenceCameraMappings') {
                    $form['elements'][$index]['items'][$itemIndex]['values'] = $cameraRows;
                    break;
                }
            }
            break;
        }

        $encoded = json_encode($form);
        return $encoded === false ? '{}' : $encoded;
    }

    public function SetEvidenceFormVisibility(bool $enabled): void
    {
        $this->UpdateFormField('EvidencePanel', 'visible', $enabled);
    }

    /**
     * Builds the NVR mapping table from camera objects below this instance.
     * Camera and IP are discovered automatically; only NVRChannel is entered
     * by the user.
     *
     * The first v1.6.5 form saved only the editable NVRChannel column. For
     * those existing configurations we retain assignments by row position once
     * while migrating to the complete Camera/IP/NVRChannel representation.
     */
    private function BuildEvidenceCameraRows(): array
    {
        $savedMappings = json_decode($this->ReadPropertyString('EvidenceCameraMappings'), true);
        if (!is_array($savedMappings)) {
            $savedMappings = [];
        }

        $channelsByIp = [];
        $channelsByName = [];
        $legacyChannels = [];
        $hasSavedIdentity = false;

        foreach ($savedMappings as $mapping) {
            if (!is_array($mapping)) {
                $legacyChannels[] = 0;
                continue;
            }

            $channel = max(0, min(99, (int) ($mapping['NVRChannel'] ?? 0)));
            $ip = trim((string) ($mapping['IPAddress'] ?? ''));
            $camera = trim((string) ($mapping['Camera'] ?? ''));

            $legacyChannels[] = $channel;

            if ($ip !== '') {
                $channelsByIp[$ip] = $channel;
                $hasSavedIdentity = true;
            }
            if ($camera !== '') {
                $channelsByName[strtolower($camera)] = $channel;
                $hasSavedIdentity = true;
            }
        }

        $rows = [];

        foreach (IPS_GetChildrenIDs($this->InstanceID) as $cameraId) {
            $object = IPS_GetObject($cameraId);
            if ((int) ($object['ObjectType'] ?? -1) !== 2) {
                continue;
            }

            $variable = IPS_GetVariable($cameraId);
            if ((int) ($variable['VariableType'] ?? -1) !== 0) {
                continue;
            }
            if (($variable['VariableCustomProfile'] ?? '') !== 'Motion') {
                continue;
            }

            $rows[] = [
                'Camera'     => IPS_GetName($cameraId),
                'IPAddress'  => $this->GetEvidenceCameraIpAddress($cameraId),
                'NVRChannel' => 0
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            return strnatcasecmp((string) ($left['Camera'] ?? ''), (string) ($right['Camera'] ?? ''));
        });

        foreach ($rows as $index => $row) {
            $cameraName = (string) ($row['Camera'] ?? '');
            $ipAddress = (string) ($row['IPAddress'] ?? '');
            $channel = 0;

            if ($ipAddress !== '' && array_key_exists($ipAddress, $channelsByIp)) {
                $channel = $channelsByIp[$ipAddress];
            } else {
                $nameKey = strtolower($cameraName);
                if ($nameKey !== '' && array_key_exists($nameKey, $channelsByName)) {
                    $channel = $channelsByName[$nameKey];
                } elseif (!$hasSavedIdentity && array_key_exists($index, $legacyChannels)) {
                    // Compatibility with the initial v1.6.5 list, which persisted only channels.
                    $channel = $legacyChannels[$index];
                }
            }

            $rows[$index]['NVRChannel'] = max(0, min(99, (int) $channel));
        }

        return $rows;
    }

    private function GetEvidenceCameraIpAddress(int $cameraId): string
    {
        foreach (IPS_GetChildrenIDs($cameraId) as $childId) {
            $object = IPS_GetObject($childId);
            if ((int) ($object['ObjectType'] ?? -1) !== 2) {
                continue;
            }

            $variable = IPS_GetVariable($childId);
            if ((int) ($variable['VariableType'] ?? -1) !== 3) {
                continue;
            }

            $name = IPS_GetName($childId);
            if (strpos($name, 'IP-') !== 0) {
                continue;
            }

            return trim((string) GetValueString($childId));
        }

        return '';
    }

    private function DispatchEvidenceRequest(int $kameraId, string $cameraName, array $motionData): void
    {
        if (!$this->ReadPropertyBoolean('EnableEvidenceRecording')) {
            return;
        }

        $cameraIp = trim((string) ($motionData['ipAddress'] ?? ''));
        $nvrChannel = $this->GetEvidenceNvrChannelForCamera($cameraName, $cameraIp);
        if ($nvrChannel === null) {
            if ($this->ReadPropertyBoolean('debug')) {
                $this->LogMessage(
                    'NVR evidence skipped for camera "' . $cameraName . '": no NVR channel mapping found.',
                    KL_DEBUG
                );
            }
            return;
        }

        $serviceUrl = rtrim(trim($this->ReadPropertyString('EvidenceServiceURL')), '/');
        if ($serviceUrl === '' || !preg_match('#^https?://#i', $serviceUrl)) {
            $this->LogMessage('NVR evidence is enabled, but Evidence Service URL is empty or invalid.', KL_WARNING);
            return;
        }

        $eventTime = trim((string) ($motionData['dateTime'] ?? ''));
        if ($eventTime === '' || !preg_match('/(?:Z|[+\-]\d{2}:\d{2})$/i', $eventTime)) {
            $this->LogMessage(
                'NVR evidence skipped for camera "' . $cameraName . '": event timestamp has no timezone.',
                KL_WARNING
            );
            return;
        }

        $workerFile = __DIR__ . DIRECTORY_SEPARATOR . 'HikvisionEvidenceRequestWorker.php';
        if (!is_file($workerFile)) {
            $this->LogMessage('HikvisionEvidenceRequestWorker.php is missing from the module directory.', KL_WARNING);
            return;
        }

        $instanceInfo = IPS_GetInstance($this->InstanceID);
        $moduleId = $instanceInfo['ModuleInfo']['ModuleID'] ?? '';
        $moduleInfo = $moduleId !== '' ? IPS_GetModule($moduleId) : [];
        $prefix = (string) ($moduleInfo['Prefix'] ?? '');

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $prefix)) {
            $this->LogMessage('Unable to determine a valid module prefix for the NVR evidence worker.', KL_WARNING);
            return;
        }

        $workerData = [
            'instanceId'  => $this->InstanceID,
            'cameraId'    => $kameraId,
            'cameraName'  => $cameraName,
            'serviceUrl'  => $serviceUrl,
            'track'       => ($nvrChannel * 100) + 1,
            'eventTime'   => $eventTime,
            'before'      => max(0, min(300, $this->ReadPropertyInteger('EvidenceBeforeSeconds'))),
            'after'       => max(0, min(300, $this->ReadPropertyInteger('EvidenceAfterSeconds'))),
            'callback'    => $prefix . '_CompleteEvidenceRequest',
            'debug'       => $this->ReadPropertyBoolean('debug')
        ];

        $workerScript = 'require_once (string) $_IPS["WorkerFile"]; HikvisionEvidenceRequestWorker::Run((string) $_IPS["WorkerData"]);';
        $started = IPS_RunScriptTextEx(
            $workerScript,
            [
                'WorkerFile' => $workerFile,
                'WorkerData' => json_encode($workerData)
            ]
        );

        if (!$started) {
            $this->LogMessage('IP-Symcon did not start the NVR evidence worker for camera "' . $cameraName . '".', KL_WARNING);
        } elseif ($this->ReadPropertyBoolean('debug')) {
            $this->LogMessage(
                sprintf('NVR evidence worker scheduled for camera "%s" using NVR channel %d.', $cameraName, $nvrChannel),
                KL_DEBUG
            );
        }
    }

    private function GetEvidenceNvrChannelForCamera(string $cameraName, string $cameraIp = ''): ?int
    {
        // Use the same reconstructed rows as the configuration form. This also
        // understands channel-only mappings saved by the initial v1.6.5 form.
        $mappings = $this->BuildEvidenceCameraRows();

        $cameraName = trim($cameraName);
        $cameraIp = trim($cameraIp);
        $nameFallbackChannel = null;

        foreach ($mappings as $mapping) {
            if (!is_array($mapping)) {
                continue;
            }

            $channel = (int) ($mapping['NVRChannel'] ?? 0);
            if ($channel < 1 || $channel > 99) {
                continue;
            }

            $mappedIp = trim((string) ($mapping['IPAddress'] ?? ''));
            if ($cameraIp !== '' && $mappedIp !== '' && $mappedIp === $cameraIp) {
                return $channel;
            }

            $mappedCamera = trim((string) ($mapping['Camera'] ?? ''));
            if ($mappedCamera !== '' && strcasecmp($mappedCamera, $cameraName) === 0) {
                $nameFallbackChannel = $channel;
            }
        }

        return $nameFallbackChannel;
    }

    public function CompleteEvidenceRequest(int $kameraId, string $resultJson): void
    {
        if (!IPS_VariableExists($kameraId)) {
            return;
        }

        $result = json_decode($resultJson, true);
        if (!is_array($result)) {
            $this->LogMessage('NVR evidence worker returned invalid result JSON.', KL_WARNING);
            return;
        }

        if (empty($result['success'])) {
            $cameraName = IPS_GetName($kameraId);
            $message = trim((string) ($result['message'] ?? 'Unknown evidence service error.'));
            $this->LogMessage('NVR evidence request failed for camera "' . $cameraName . '": ' . $message, KL_WARNING);
            return;
        }

        $serviceUrl = rtrim((string) ($result['serviceUrl'] ?? ''), '/');
        $statusUrl = $this->ResolveEvidenceUrl($serviceUrl, (string) ($result['statusUrl'] ?? ''));
        $videoUrl = $this->ResolveEvidenceUrl($serviceUrl, (string) ($result['videoUrl'] ?? ''));

        $this->SetEvidenceStringVariable($kameraId, 'Evidence Status', (string) ($result['status'] ?? 'accepted'));
        $this->SetEvidenceStringVariable($kameraId, 'Evidence Job ID', (string) ($result['job'] ?? ''));
        $this->SetEvidenceStringVariable($kameraId, 'Evidence File', (string) ($result['file'] ?? ''));
        $this->SetEvidenceStringVariable($kameraId, 'Evidence Status URL', $statusUrl);
        $this->SetEvidenceStringVariable($kameraId, 'Evidence URL', $videoUrl);

        if ($this->ReadPropertyBoolean('debug')) {
            $this->LogMessage(
                'NVR evidence request accepted for camera "' . IPS_GetName($kameraId) . '". Job: ' . (string) ($result['job'] ?? ''),
                KL_DEBUG
            );
        }
    }

    private function ResolveEvidenceUrl(string $serviceUrl, string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        return rtrim($serviceUrl, '/') . '/' . ltrim($url, '/');
    }

    private function SetEvidenceStringVariable(int $parentId, string $name, string $value): void
    {
        $variableId = @IPS_GetVariableIDByName($name, $parentId);
        if ($variableId === false) {
            $variableId = IPS_CreateVariable(3);
            IPS_SetName($variableId, $name);
            IPS_SetParent($variableId, $parentId);
            IPS_SetVariableCustomProfile($variableId, '~TextBox');
        }
        SetValueString($variableId, $value);
    }
}
