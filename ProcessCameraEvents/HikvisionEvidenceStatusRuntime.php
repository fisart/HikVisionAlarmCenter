<?php

/**
 * Runtime helper for non-blocking Hikvision evidence status tracking.
 *
 * This class deliberately stays outside the ProcessCameraEvents module class.
 * The alarm/webhook path only starts the already existing request worker. That
 * isolated worker then initializes status tracking. A hidden IP-Symcon script
 * timer polls only while at least one evidence job is active, and every HTTP
 * status request runs in its own short isolated worker.
 */
final class HikvisionEvidenceStatusRuntime
{
    private const POLL_SCRIPT_NAME = 'Evidence Status Poll';
    private const POLL_INTERVAL_SECONDS = 10;
    private const MAX_TRACKING_SECONDS = 900;

    public static function Initialize(int $instanceId): void
    {
        if (!IPS_InstanceExists($instanceId)) {
            return;
        }

        $semaphore = 'HikvisionEvidenceInit_' . $instanceId;
        if (IPS_SemaphoreEnter($semaphore, 5000)) {
            try {
                self::EnsureEvidenceReadyVariables($instanceId);
            } finally {
                IPS_SemaphoreLeave($semaphore);
            }
        }

        // Resume polling after ApplyChanges/module reload only if an accepted,
        // waiting or running evidence job is still present in the camera tree.
        self::RefreshPollTimer($instanceId);
    }

    public static function Begin(int $instanceId, int $cameraId): void
    {
        if (!IPS_InstanceExists($instanceId) || !IPS_VariableExists($cameraId)) {
            return;
        }

        $semaphore = 'HikvisionEvidenceInit_' . $instanceId;
        if (IPS_SemaphoreEnter($semaphore, 5000)) {
            try {
                self::EnsureEvidenceReadyVariables($instanceId);

                // Clear the previous clip state when a new evidence request is
                // actually dispatched by the isolated request worker.
                self::SetEvidenceReady($cameraId, false);
                self::SetString($cameraId, 'Evidence Status', 'requesting');
                self::SetString($cameraId, 'Evidence Job ID', '');
                self::SetString($cameraId, 'Evidence File', '');
                self::SetString($cameraId, 'Evidence Status URL', '');
                self::SetString($cameraId, 'Evidence URL', '');
            } finally {
                IPS_SemaphoreLeave($semaphore);
            }
        }

        self::RefreshPollTimer($instanceId);
    }

    public static function TrackRequestResult(int $instanceId, int $cameraId, string $resultJson): void
    {
        if (!IPS_InstanceExists($instanceId) || !IPS_VariableExists($cameraId)) {
            return;
        }

        $result = json_decode($resultJson, true);
        if (!is_array($result) || empty($result['success'])) {
            self::SetEvidenceReady($cameraId, false);
            self::SetString($cameraId, 'Evidence Status', 'failed');
            self::RefreshPollTimer($instanceId);
            return;
        }

        $serviceUrl = rtrim(trim((string) ($result['serviceUrl'] ?? '')), '/');
        $jobId = trim((string) ($result['job'] ?? ''));
        $statusUrl = self::ResolveUrl($serviceUrl, (string) ($result['statusUrl'] ?? ''));
        $videoUrl = self::ResolveUrl($serviceUrl, (string) ($result['videoUrl'] ?? ''));

        if ($jobId === '' || $statusUrl === '') {
            self::SetEvidenceReady($cameraId, false);
            self::SetString($cameraId, 'Evidence Status', 'failed');
            self::Log('Evidence service returned no usable job/status URL for camera "' . IPS_GetName($cameraId) . '".', KL_WARNING);
            self::RefreshPollTimer($instanceId);
            return;
        }

        $status = self::NormalizeStatus((string) ($result['status'] ?? 'accepted'));
        if ($status === '') {
            $status = 'accepted';
        }

        self::SetEvidenceReady($cameraId, $status === 'done');
        self::SetString($cameraId, 'Evidence Status', $status);
        self::SetString($cameraId, 'Evidence Job ID', $jobId);
        self::SetString($cameraId, 'Evidence File', (string) ($result['file'] ?? ''));
        self::SetString($cameraId, 'Evidence Status URL', $statusUrl);
        self::SetString($cameraId, 'Evidence URL', $videoUrl);

        self::RefreshPollTimer($instanceId);
    }

    public static function Poll(int $instanceId): void
    {
        if (!IPS_InstanceExists($instanceId)) {
            return;
        }

        if (!(bool) IPS_GetProperty($instanceId, 'EnableEvidenceRecording')) {
            self::SetPollTimer($instanceId, 0);
            return;
        }

        $workerFile = __DIR__ . DIRECTORY_SEPARATOR . 'HikvisionEvidenceStatusWorker.php';
        if (!is_file($workerFile)) {
            self::Log('HikvisionEvidenceStatusWorker.php is missing.', KL_WARNING);
            self::SetPollTimer($instanceId, 0);
            return;
        }

        $jobs = self::GetActiveJobs($instanceId);
        if (count($jobs) === 0) {
            self::SetPollTimer($instanceId, 0);
            return;
        }

        $workerScript = 'require_once (string) $_IPS["WorkerFile"]; HikvisionEvidenceStatusWorker::Run((string) $_IPS["WorkerData"]);';

        foreach ($jobs as $job) {
            $cameraId = (int) $job['cameraId'];
            $jobId = (string) $job['jobId'];

            if ((time() - (int) $job['acceptedAt']) > self::MAX_TRACKING_SECONDS) {
                self::SetEvidenceReady($cameraId, false);
                self::SetString($cameraId, 'Evidence Status', 'failed');
                self::Log(
                    'Evidence status polling timed out for camera "' . IPS_GetName($cameraId) . '". Job: ' . $jobId,
                    KL_WARNING
                );
                continue;
            }

            $workerData = [
                'instanceId' => $instanceId,
                'cameraId'   => $cameraId,
                'jobId'      => $jobId,
                'serviceUrl' => (string) $job['serviceUrl'],
                'statusUrl'  => (string) $job['statusUrl']
            ];

            $started = IPS_RunScriptTextEx(
                $workerScript,
                [
                    'WorkerFile' => $workerFile,
                    'WorkerData' => json_encode($workerData)
                ]
            );

            if (!$started) {
                self::Log(
                    'IP-Symcon did not start the evidence status worker for camera "' . IPS_GetName($cameraId) . '".',
                    KL_WARNING
                );
            }
        }

        self::RefreshPollTimer($instanceId);
    }

    public static function CompletePoll(int $instanceId, int $cameraId, string $jobId, string $resultJson): void
    {
        if (!IPS_InstanceExists($instanceId) || !IPS_VariableExists($cameraId)) {
            return;
        }

        // The object tree is authoritative. A newer alarm cycle may have
        // replaced the job while this isolated status worker was running.
        if (self::GetString($cameraId, 'Evidence Job ID') !== $jobId) {
            return;
        }

        $result = json_decode($resultJson, true);
        if (!is_array($result) || empty($result['success'])) {
            if (self::IsDebug($instanceId)) {
                self::Log(
                    'Evidence status poll failed for camera "' . IPS_GetName($cameraId) . '" job ' . $jobId . ': ' .
                    trim((string) (($result['message'] ?? '') ?: 'Unknown status error.')),
                    KL_WARNING
                );
            }
            self::RefreshPollTimer($instanceId);
            return;
        }

        $status = self::NormalizeStatus((string) ($result['status'] ?? ''));
        if ($status === '') {
            self::RefreshPollTimer($instanceId);
            return;
        }

        self::SetString($cameraId, 'Evidence Status', $status);
        self::SetEvidenceReady($cameraId, $status === 'done');

        $file = trim((string) ($result['file'] ?? ''));
        if ($file !== '') {
            self::SetString($cameraId, 'Evidence File', $file);
        }

        $serviceUrl = rtrim(trim((string) ($result['serviceUrl'] ?? '')), '/');
        $videoUrl = self::ResolveUrl($serviceUrl, (string) ($result['videoUrl'] ?? ''));
        if ($videoUrl !== '') {
            self::SetString($cameraId, 'Evidence URL', $videoUrl);
        }

        if (($status === 'done' || $status === 'failed') && self::IsDebug($instanceId)) {
            self::Log(
                'Evidence job ' . $jobId . ' for camera "' . IPS_GetName($cameraId) . '" reached terminal status ' . $status . '.',
                KL_DEBUG
            );
        }

        self::RefreshPollTimer($instanceId);
    }

    private static function GetActiveJobs(int $instanceId): array
    {
        $jobs = [];
        $serviceUrl = rtrim(trim((string) IPS_GetProperty($instanceId, 'EvidenceServiceURL')), '/');

        foreach (self::GetCameraIds($instanceId) as $cameraId) {
            $status = strtolower(self::GetString($cameraId, 'Evidence Status'));
            if (!in_array($status, ['accepted', 'waiting', 'running'], true)) {
                continue;
            }

            $jobVariableId = @IPS_GetVariableIDByName('Evidence Job ID', $cameraId);
            if ($jobVariableId === false) {
                continue;
            }

            $jobId = trim((string) GetValueString($jobVariableId));
            $statusUrl = self::GetString($cameraId, 'Evidence Status URL');
            if ($jobId === '' || $statusUrl === '') {
                continue;
            }

            $variable = IPS_GetVariable($jobVariableId);
            $acceptedAt = (int) ($variable['VariableUpdated'] ?? time());

            $jobs[] = [
                'cameraId'   => $cameraId,
                'jobId'      => $jobId,
                'statusUrl'  => $statusUrl,
                'serviceUrl' => $serviceUrl,
                'acceptedAt' => $acceptedAt
            ];
        }

        return $jobs;
    }

    private static function RefreshPollTimer(int $instanceId): void
    {
        $enabled = IPS_InstanceExists($instanceId) &&
            (bool) IPS_GetProperty($instanceId, 'EnableEvidenceRecording') &&
            count(self::GetActiveJobs($instanceId)) > 0;

        self::SetPollTimer($instanceId, $enabled ? self::POLL_INTERVAL_SECONDS : 0);
    }

    private static function SetPollTimer(int $instanceId, int $seconds): void
    {
        $semaphore = 'HikvisionEvidenceTimer_' . $instanceId;
        if (!IPS_SemaphoreEnter($semaphore, 5000)) {
            return;
        }

        try {
            $scriptId = self::FindPollScript($instanceId);

            if ($seconds <= 0) {
                if ($scriptId !== null) {
                    IPS_SetScriptTimer($scriptId, 0);
                }
                return;
            }

            if ($scriptId === null) {
                $scriptId = IPS_CreateScript(0);
                IPS_SetName($scriptId, self::POLL_SCRIPT_NAME);
                IPS_SetParent($scriptId, $instanceId);
                IPS_SetHidden($scriptId, true);
            }

            $runtimeFile = var_export(__FILE__, true);
            IPS_SetScriptContent(
                $scriptId,
                "<?php\nrequire_once " . $runtimeFile . ";\nHikvisionEvidenceStatusRuntime::Poll(" . $instanceId . ");\n"
            );
            IPS_SetScriptTimer($scriptId, $seconds);
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    private static function FindPollScript(int $instanceId): ?int
    {
        $objectId = @IPS_GetObjectIDByName(self::POLL_SCRIPT_NAME, $instanceId);
        if ($objectId === false) {
            return null;
        }

        $object = IPS_GetObject($objectId);
        if ((int) ($object['ObjectType'] ?? -1) !== 3) {
            self::Log('Object "' . self::POLL_SCRIPT_NAME . '" exists below the module but is not a script.', KL_WARNING);
            return null;
        }

        return (int) $objectId;
    }

    private static function EnsureEvidenceReadyVariables(int $instanceId): void
    {
        foreach (self::GetCameraIds($instanceId) as $cameraId) {
            self::EnsureEvidenceReadyVariable($cameraId);
        }
    }

    private static function GetCameraIds(int $instanceId): array
    {
        $cameraIds = [];
        foreach (IPS_GetChildrenIDs($instanceId) as $cameraId) {
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

            $cameraIds[] = (int) $cameraId;
        }
        return $cameraIds;
    }

    private static function SetEvidenceReady(int $cameraId, bool $ready): void
    {
        $variableId = self::EnsureEvidenceReadyVariable($cameraId);
        if ($variableId !== null) {
            SetValueBoolean($variableId, $ready);
        }
    }

    private static function EnsureEvidenceReadyVariable(int $cameraId): ?int
    {
        $variableId = @IPS_GetObjectIDByName('Evidence Ready', $cameraId);
        if ($variableId !== false) {
            $object = IPS_GetObject($variableId);
            if ((int) ($object['ObjectType'] ?? -1) !== 2) {
                self::Log('Object "Evidence Ready" exists below camera ID ' . $cameraId . ' but is not a variable.', KL_WARNING);
                return null;
            }

            $variable = IPS_GetVariable($variableId);
            if ((int) ($variable['VariableType'] ?? -1) !== 0) {
                self::Log('Variable "Evidence Ready" below camera ID ' . $cameraId . ' is not Boolean.', KL_WARNING);
                return null;
            }

            return (int) $variableId;
        }

        $variableId = IPS_CreateVariable(0);
        IPS_SetName($variableId, 'Evidence Ready');
        IPS_SetParent($variableId, $cameraId);
        IPS_SetVariableCustomProfile($variableId, '~Switch');
        SetValueBoolean($variableId, false);
        return $variableId;
    }

    private static function SetString(int $parentId, string $name, string $value): void
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

    private static function GetString(int $parentId, string $name): string
    {
        $variableId = @IPS_GetVariableIDByName($name, $parentId);
        if ($variableId === false) {
            return '';
        }

        $variable = IPS_GetVariable($variableId);
        if ((int) ($variable['VariableType'] ?? -1) !== 3) {
            return '';
        }

        return trim((string) GetValueString($variableId));
    }

    private static function NormalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === 'pending') {
            $status = 'waiting';
        }

        return in_array($status, ['accepted', 'waiting', 'running', 'done', 'failed'], true) ? $status : '';
    }

    private static function ResolveUrl(string $serviceUrl, string $url): string
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

    private static function IsDebug(int $instanceId): bool
    {
        return IPS_InstanceExists($instanceId) && (bool) IPS_GetProperty($instanceId, 'debug');
    }

    private static function Log(string $message, int $level): void
    {
        // IPS_LogMessage has no level parameter; include the severity in the text.
        $label = $level === KL_WARNING ? 'WARNING' : ($level === KL_ERROR ? 'ERROR' : 'DEBUG');
        IPS_LogMessage('Hikvision Evidence Status', '[' . $label . '] ' . $message);
    }
}
