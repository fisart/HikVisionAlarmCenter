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
        foreach (($form['elements'] ?? []) as $index => $element) {
            if (($element['name'] ?? '') === 'EvidencePanel') {
                $form['elements'][$index]['visible'] = $visible;
                break;
            }
        }

        $encoded = json_encode($form);
        return $encoded === false ? '{}' : $encoded;
    }

    public function SetEvidenceFormVisibility(bool $enabled): void
    {
        $this->UpdateFormField('EvidencePanel', 'visible', $enabled);
    }

    private function DispatchEvidenceRequest(int $kameraId, string $cameraName, array $motionData): void
    {
        if (!$this->ReadPropertyBoolean('EnableEvidenceRecording')) {
            return;
        }

        $nvrChannel = $this->GetEvidenceNvrChannelForCamera($cameraName);
        if ($nvrChannel === null) {
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

    private function GetEvidenceNvrChannelForCamera(string $cameraName): ?int
    {
        $mappings = json_decode($this->ReadPropertyString('EvidenceCameraMappings'), true);
        if (!is_array($mappings)) {
            return null;
        }

        $cameraName = trim($cameraName);
        foreach ($mappings as $mapping) {
            if (!is_array($mapping)) {
                continue;
            }

            $mappedCamera = trim((string) ($mapping['Camera'] ?? ''));
            $channel = (int) ($mapping['NVRChannel'] ?? 0);

            if ($mappedCamera === '' || $channel < 1 || $channel > 99) {
                continue;
            }

            if (strcasecmp($mappedCamera, $cameraName) === 0) {
                return $channel;
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
