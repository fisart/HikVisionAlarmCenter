<?php

/**
 * Optional NVR evidence support for ProcessCameraEvents v1.6.9.
 *
 * Evidence capture is disabled by default. Mapping configuration deliberately
 * avoids relying on persistence of dynamic List rows because some IP-Symcon
 * consoles submit only editable/partial row data. The camera object tree is the
 * source of truth, a module buffer is the working copy, and explicit Save
 * buttons commit a healed full mapping to the persistent property.
 *
 * Hikvision historical RTSP playback on the target NVR expects the recorder's
 * local wall-clock value even though the query uses a trailing Z. Therefore the
 * camera supplied local date/time is used for evidence timing, while its
 * timezone suffix is deliberately ignored for the RTSP request. The original
 * camera timestamp is still stored unchanged in the camera event tree.
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

        // Full-load working copy: the object tree supplies CameraID/name/IP and
        // the persistent property supplies only the user-maintained channel.
        $bufferJson = json_encode($cameraRows);
        if ($bufferJson !== false) {
            $this->SetBuffer('EvidenceMappingBuffer', $bufferJson);
        }

        foreach (($form['elements'] ?? []) as $index => $element) {
            if (($element['name'] ?? '') !== 'EvidencePanel') {
                continue;
            }

            $form['elements'][$index]['visible'] = $visible;

            // Do not expose the property-bound dynamic List. Older/legacy
            // consoles can drop non-editable identity columns when applying it.
            // Replace it at runtime with an explanatory label. The actual
            // editor is generated in the actions area below.
            foreach (($element['items'] ?? []) as $itemIndex => $item) {
                if (($item['name'] ?? '') === 'EvidenceCameraMappings') {
                    $form['elements'][$index]['items'][$itemIndex] = [
                        'type'    => 'Label',
                        'caption' => 'Camera/NVR channel mappings are edited in the "NVR Evidence Camera Mapping" section below. Camera name and IP are read automatically from the object tree.'
                    ];
                    break;
                }
            }
            break;
        }

        if (!isset($form['actions']) || !is_array($form['actions'])) {
            $form['actions'] = [];
        }

        $form['actions'][] = $this->BuildEvidenceMappingActionPanel($cameraRows, $visible);

        $encoded = json_encode($form);
        return $encoded === false ? '{}' : $encoded;
    }

    public function SetEvidenceFormVisibility(bool $enabled): void
    {
        $this->UpdateFormField('EvidencePanel', 'visible', $enabled);
        $this->UpdateFormField('EvidenceMappingActions', 'visible', $enabled);
    }

    private function BuildEvidenceMappingActionPanel(array $cameraRows, bool $visible): array
    {
        $items = [
            [
                'type'    => 'Label',
                'caption' => 'Detected cameras are read from the module object tree. Enter 0 for cameras not connected to the NVR. Press Save on a changed row. Saving one row commits the complete healed mapping, including all camera IDs and IP addresses.'
            ],
            [
                'type'  => 'RowLayout',
                'items' => [
                    ['type' => 'Label', 'caption' => 'Camera',      'width' => '260px', 'bold' => true],
                    ['type' => 'Label', 'caption' => 'IP Address',  'width' => '180px', 'bold' => true],
                    ['type' => 'Label', 'caption' => 'NVR Channel', 'width' => '120px', 'bold' => true],
                    ['type' => 'Label', 'caption' => '',            'width' => '90px']
                ]
            ]
        ];

        foreach ($cameraRows as $row) {
            $cameraId = (int) ($row['CameraID'] ?? 0);
            if ($cameraId <= 0) {
                continue;
            }

            $fieldName = 'EvidenceChannel_' . $cameraId;
            $items[] = [
                'type'  => 'RowLayout',
                'items' => [
                    [
                        'type'    => 'Label',
                        'caption' => (string) ($row['Camera'] ?? ''),
                        'width'   => '260px'
                    ],
                    [
                        'type'    => 'Label',
                        'caption' => (string) ($row['IPAddress'] ?? ''),
                        'width'   => '180px'
                    ],
                    [
                        'type'    => 'NumberSpinner',
                        'name'    => $fieldName,
                        'value'   => max(0, min(99, (int) ($row['NVRChannel'] ?? 0))),
                        'minimum' => 0,
                        'maximum' => 99,
                        'width'   => '120px'
                    ],
                    [
                        'type'    => 'Button',
                        'caption' => 'Save',
                        'width'   => '90px',
                        'onClick' => 'HIK_SaveEvidenceChannel($id, ' . $cameraId . ', $' . $fieldName . ');'
                    ]
                ]
            ];
        }

        $items[] = [
            'type'    => 'Button',
            'caption' => 'Commit detected mappings',
            'onClick' => 'HIK_CommitEvidenceMappings($id);'
        ];

        return [
            'type'     => 'ExpansionPanel',
            'name'     => 'EvidenceMappingActions',
            'caption'  => 'NVR Evidence Camera Mapping',
            'visible'  => $visible,
            'expanded' => true,
            'items'    => $items
        ];
    }

    /**
     * Explicitly saves one edited channel while preserving all other rows.
     * Camera identity metadata is always healed from the live object tree.
     */
    public function SaveEvidenceChannel(int $cameraId, int $channel): void
    {
        $channel = max(0, min(99, $channel));
        $rows = $this->ReadEvidenceMappingBuffer();
        if (count($rows) === 0) {
            $rows = $this->BuildEvidenceCameraRows();
        }

        $metadataById = [];
        foreach ($this->DetectEvidenceCameras() as $camera) {
            $metadataById[(int) $camera['CameraID']] = $camera;
        }

        if (!isset($metadataById[$cameraId])) {
            $this->LogMessage('Unable to save NVR evidence mapping: camera ID ' . $cameraId . ' no longer exists.', KL_WARNING);
            return;
        }

        $rowsById = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['CameraID'] ?? 0);
            if ($id > 0) {
                $rowsById[$id] = $row;
            }
        }

        // Rebuild from master metadata so no partial browser payload can remove
        // camera names, IP addresses or unrelated rows.
        $healedRows = [];
        foreach ($metadataById as $id => $metadata) {
            $existing = $rowsById[$id] ?? [];
            $healedRows[] = [
                'CameraID'   => $id,
                'Camera'     => (string) $metadata['Camera'],
                'IPAddress'  => (string) $metadata['IPAddress'],
                'NVRChannel' => $id === $cameraId
                    ? $channel
                    : max(0, min(99, (int) ($existing['NVRChannel'] ?? 0)))
            ];
        }

        usort($healedRows, static function (array $left, array $right): int {
            return strnatcasecmp((string) ($left['Camera'] ?? ''), (string) ($right['Camera'] ?? ''));
        });

        $this->PersistEvidenceMappings($healedRows);
    }

    /**
     * Commits the current full working copy without changing any channel. This
     * is useful for migrating the old channel-only v1.6.5/v1.6.6 property.
     */
    public function CommitEvidenceMappings(): void
    {
        $rows = $this->ReadEvidenceMappingBuffer();
        if (count($rows) === 0) {
            $rows = $this->BuildEvidenceCameraRows();
        }
        $this->PersistEvidenceMappings($rows);
    }

    private function PersistEvidenceMappings(array $rows): void
    {
        $json = json_encode(array_values($rows));
        if ($json === false) {
            $this->LogMessage('Unable to encode NVR evidence mappings.', KL_WARNING);
            return;
        }

        $this->SetBuffer('EvidenceMappingBuffer', $json);
        IPS_SetProperty($this->InstanceID, 'EvidenceCameraMappings', $json);
        IPS_ApplyChanges($this->InstanceID);
    }

    private function ReadEvidenceMappingBuffer(): array
    {
        $rows = json_decode($this->GetBuffer('EvidenceMappingBuffer'), true);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Builds the NVR mapping table from the current camera object tree.
     * Saved mappings are matched by camera ID, then IP address, then camera
     * name. Legacy channel-only rows are migrated by deterministic natural
     * camera-name order.
     */
    private function BuildEvidenceCameraRows(): array
    {
        $savedMappings = $this->ReadEvidenceMappings();
        $detectedCameras = $this->DetectEvidenceCameras();

        $channelsById = [];
        $channelsByIp = [];
        $channelsByName = [];
        $hasIdentityData = false;

        foreach ($savedMappings as $mapping) {
            if (!is_array($mapping)) {
                continue;
            }

            $channel = max(0, min(99, (int) ($mapping['NVRChannel'] ?? 0)));
            $cameraId = (int) ($mapping['CameraID'] ?? 0);
            $ip = trim((string) ($mapping['IPAddress'] ?? ''));
            $camera = trim((string) ($mapping['Camera'] ?? ''));

            if ($cameraId > 0) {
                $channelsById[$cameraId] = $channel;
                $hasIdentityData = true;
            }
            if ($ip !== '') {
                $channelsByIp[$ip] = $channel;
                $hasIdentityData = true;
            }
            if ($camera !== '') {
                $channelsByName[strtolower($camera)] = $channel;
                $hasIdentityData = true;
            }
        }

        $rows = [];
        foreach ($detectedCameras as $index => $camera) {
            $cameraId = (int) $camera['CameraID'];
            $cameraName = (string) $camera['Camera'];
            $ipAddress = (string) $camera['IPAddress'];
            $channel = 0;

            if (array_key_exists($cameraId, $channelsById)) {
                $channel = $channelsById[$cameraId];
            } elseif ($ipAddress !== '' && array_key_exists($ipAddress, $channelsByIp)) {
                $channel = $channelsByIp[$ipAddress];
            } else {
                $nameKey = strtolower($cameraName);
                if (array_key_exists($nameKey, $channelsByName)) {
                    $channel = $channelsByName[$nameKey];
                } elseif (!$hasIdentityData && isset($savedMappings[$index]) && is_array($savedMappings[$index])) {
                    $channel = max(0, min(99, (int) ($savedMappings[$index]['NVRChannel'] ?? 0)));
                }
            }

            $rows[] = [
                'CameraID'   => $cameraId,
                'Camera'     => $cameraName,
                'IPAddress'  => $ipAddress,
                'NVRChannel' => $channel
            ];
        }

        return $rows;
    }

    private function DetectEvidenceCameras(): array
    {
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
                'CameraID'  => (int) $cameraId,
                'Camera'    => IPS_GetName($cameraId),
                'IPAddress' => $this->GetEvidenceCameraIpAddress($cameraId)
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            return strnatcasecmp((string) ($left['Camera'] ?? ''), (string) ($right['Camera'] ?? ''));
        });

        return $rows;
    }

    private function ReadEvidenceMappings(): array
    {
        $mappings = json_decode($this->ReadPropertyString('EvidenceCameraMappings'), true);
        return is_array($mappings) ? $mappings : [];
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

    /**
     * Hikvision historical RTSP on this NVR expects the local recorder
     * wall-clock value with a trailing Z. Keep the YYYY-MM-DDTHH:MM:SS part
     * supplied by the camera and deliberately discard its timezone suffix.
     */
    private function NormalizeEvidenceCameraTime(string $cameraTimestamp): ?string
    {
        $cameraTimestamp = trim($cameraTimestamp);
        if (!preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})?$/i', $cameraTimestamp, $matches)) {
            return null;
        }

        return $matches[1] . 'Z';
    }

    private function DispatchEvidenceRequest(int $kameraId, string $cameraName, array $motionData): void
    {
        if (!$this->ReadPropertyBoolean('EnableEvidenceRecording')) {
            return;
        }

        $cameraIp = trim((string) ($motionData['ipAddress'] ?? ''));
        $nvrChannel = $this->GetEvidenceNvrChannelForCamera($kameraId, $cameraName, $cameraIp);
        if ($nvrChannel === null) {
            if ($this->ReadPropertyBoolean('debug')) {
                $this->LogMessage('No NVR evidence channel mapping found for camera "' . $cameraName . '" (' . $cameraIp . ').', KL_DEBUG);
            }
            return;
        }

        $serviceUrl = rtrim(trim($this->ReadPropertyString('EvidenceServiceURL')), '/');
        if ($serviceUrl === '' || !preg_match('#^https?://#i', $serviceUrl)) {
            $this->LogMessage('NVR evidence is enabled, but Evidence Service URL is empty or invalid.', KL_WARNING);
            return;
        }

        $cameraReportedTime = trim((string) ($motionData['dateTime'] ?? ''));
        $eventTime = $this->NormalizeEvidenceCameraTime($cameraReportedTime);
        if ($eventTime === null) {
            $this->LogMessage(
                'NVR evidence skipped for camera "' . $cameraName . '": camera timestamp is missing or invalid.',
                KL_WARNING
            );
            return;
        }

        if ($this->ReadPropertyBoolean('debug')) {
            $this->LogMessage(
                'NVR evidence time for camera "' . $cameraName . '": camera reported=' . $cameraReportedTime .
                ', NVR wall-clock request=' . $eventTime,
                KL_DEBUG
            );
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

    private function GetEvidenceNvrChannelForCamera(int $cameraId, string $cameraName, string $cameraIp = ''): ?int
    {
        $mappings = $this->ReadEvidenceMappings();
        $cameraName = trim($cameraName);
        $cameraIp = trim($cameraIp);
        $nameFallbackChannel = null;
        $hasIdentityData = false;

        foreach ($mappings as $mapping) {
            if (!is_array($mapping)) {
                continue;
            }

            $channel = (int) ($mapping['NVRChannel'] ?? 0);
            if ($channel < 1 || $channel > 99) {
                continue;
            }

            $mappedCameraId = (int) ($mapping['CameraID'] ?? 0);
            $mappedIp = trim((string) ($mapping['IPAddress'] ?? ''));
            $mappedCamera = trim((string) ($mapping['Camera'] ?? ''));

            if ($mappedCameraId > 0 || $mappedIp !== '' || $mappedCamera !== '') {
                $hasIdentityData = true;
            }

            if ($mappedCameraId > 0 && $mappedCameraId === $cameraId) {
                return $channel;
            }
            if ($cameraIp !== '' && $mappedIp !== '' && $mappedIp === $cameraIp) {
                return $channel;
            }
            if ($mappedCamera !== '' && strcasecmp($mappedCamera, $cameraName) === 0) {
                $nameFallbackChannel = $channel;
            }
        }

        if ($nameFallbackChannel !== null) {
            return $nameFallbackChannel;
        }

        if (!$hasIdentityData) {
            foreach ($this->DetectEvidenceCameras() as $index => $camera) {
                if ((int) ($camera['CameraID'] ?? 0) !== $cameraId) {
                    continue;
                }

                $channel = (int) ($mappings[$index]['NVRChannel'] ?? 0);
                return ($channel >= 1 && $channel <= 99) ? $channel : null;
            }
        }

        return null;
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
