<?php

/**
 * Non-blocking evidence status tracking for ProcessCameraEvents.
 *
 * A hidden IP-Symcon script timer wakes every 10 seconds only while at least one
 * evidence job is active. The timer itself performs no network I/O; it only
 * schedules short isolated HTTP workers. This keeps all status communication
 * outside the alarm/webhook execution path.
 */
trait HikvisionEvidenceStatusSupport
{
    public function BeginEvidenceStatusTracking(int $cameraId): void
    {
        if (!IPS_VariableExists($cameraId)) {
            return;
        }

        $this->EnsureEvidenceReadyVariables();
        $this->RemoveEvidenceTrackingState($cameraId);

        // Invalidate the previous result only after its tracking state is gone,
        // so a stale in-flight callback cannot make the old clip Ready again.
        $this->SetEvidenceReadyVariable($cameraId, false);
        $this->SetEvidenceStringVariable($cameraId, 'Evidence Status', 'requesting');
        $this->SetEvidenceStringVariable($cameraId, 'Evidence Job ID', '');
        $this->SetEvidenceStringVariable($cameraId, 'Evidence File', '');
        $this->SetEvidenceStringVariable($cameraId, 'Evidence Status URL', '');
        $this->SetEvidenceStringVariable($cameraId, 'Evidence URL', '');

        $this->RefreshEvidencePollingTimer();
    }

    public function TrackEvidenceRequestResult(int $cameraId, string $resultJson): void
    {
        if (!IPS_VariableExists($cameraId)) {
            return;
        }

        $result = json_decode($resultJson, true);
        if (!is_array($result)) {
            $this->SetEvidenceTrackingFailed($cameraId, 'Evidence request worker returned invalid result JSON.');
            return;
        }

        if (empty($result['success'])) {
            $this->SetEvidenceTrackingFailed(
                $cameraId,
                trim((string) ($result['message'] ?? 'Unknown evidence service error.'))
            );
            return;
        }

        $jobId = trim((string) ($result['job'] ?? ''));
        $serviceUrl = rtrim(trim((string) ($result['serviceUrl'] ?? '')), '/');
        $statusUrl = $this->ResolveEvidenceUrl($serviceUrl, (string) ($result['statusUrl'] ?? ''));
        $videoUrl = $this->ResolveEvidenceUrl($serviceUrl, (string) ($result['videoUrl'] ?? ''));

        if ($jobId === '' || $statusUrl === '') {
            $this->SetEvidenceTrackingFailed($cameraId, 'Evidence service did not return a usable job/status URL.');
            return;
        }

        $status = strtolower(trim((string) ($result['status'] ?? 'accepted')));
        if ($status === 'pending') {
            $status = 'waiting';
        }
        if (!in_array($status, ['accepted', 'waiting', 'running', 'done', 'failed'], true)) {
            $status = 'accepted';
        }

        $this->SetEvidenceReadyVariable($cameraId, $status === 'done');
        $this->SetEvidenceStringVariable($cameraId, 'Evidence Status', $status);
        $this->SetEvidenceStringVariable($cameraId, 'Evidence Job ID', $jobId);
        $this->SetEvidenceStringVariable($cameraId, 'Evidence File', (string) ($result['file'] ?? ''));
        $this->SetEvidenceStringVariable($cameraId, 'Evidence Status URL', $statusUrl);
        $this->SetEvidenceStringVariable($cameraId, 'Evidence URL', $videoUrl);

        if ($status === 'done' || $status === 'failed') {
            $this->RemoveEvidenceTrackingState($cameraId);
            $this->RefreshEvidencePollingTimer();
            return;
        }

        $this->SetEvidenceTrackingState($cameraId, [
            'job'           => $jobId,
            'serviceUrl'    => $serviceUrl,
            'statusUrl'     => $statusUrl,
            'acceptedAt'    => time(),
            'pollInFlight'  => false,
            'pollStartedAt' => 0,
            'pollErrors'    => 0
        ]);

        $this->RefreshEvidencePollingTimer();
    }

    private function InitializeEvidenceStatusSupport(): void
    {
        $this->EnsureEvidenceReadyVariables();
        $this->RefreshEvidencePollingTimer();
    }

    public function PollEvidenceJobs(): void
    {
        if (!$this->ReadPropertyBoolean('EnableEvidenceRecording')) {
            $this->SetEvidencePollScriptTimer(0);
            return;
        }

        $workerFile = __DIR__ . DIRECTORY_SEPARATOR . 'HikvisionEvidenceStatusWorker.php';
        if (!is_file($workerFile)) {
            $this->LogMessage('HikvisionEvidenceStatusWorker.php is missing from the module directory.', KL_WARNING);
            $this->SetEvidencePollScriptTimer(0);
            return;
        }

        $prefix = $this->GetEvidenceModulePrefix();
        if ($prefix === '') {
            $this->LogMessage('Unable to determine a valid module prefix for evidence status polling.', KL_WARNING);
            $this->SetEvidencePollScriptTimer(0);
            return;
        }

        $semaphore = 'HikvisionEvidenceStatus_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 1000)) {
            return;
        }

        $polls = [];
        $now = time();

        try {
            $activeJobs = $this->GetActiveEvidenceJobsFromVariables();
            $state = $this->ReadEvidenceTrackingState();

            foreach ($state as $cameraKey => $entry) {
                if (!isset($activeJobs[$cameraKey]) || !is_array($entry) ||
                    (string) ($entry['job'] ?? '') !== (string) ($activeJobs[$cameraKey]['job'] ?? '')) {
                    unset($state[$cameraKey]);
                }
            }

            foreach ($activeJobs as $cameraKey => $job) {
                $cameraId = (int) $cameraKey;
                $jobId = (string) $job['job'];
                $statusUrl = (string) $job['statusUrl'];
                $serviceUrl = (string) $job['serviceUrl'];

                if (!isset($state[$cameraKey]) || !is_array($state[$cameraKey])) {
                    $state[$cameraKey] = [
                        'job'           => $jobId,
                        'serviceUrl'    => $serviceUrl,
                        'statusUrl'     => $statusUrl,
                        'acceptedAt'    => $now,
                        'pollInFlight'  => false,
                        'pollStartedAt' => 0,
                        'pollErrors'    => 0
                    ];
                }

                $acceptedAt = (int) ($state[$cameraKey]['acceptedAt'] ?? $now);
                if (($now - $acceptedAt) > 900) {
                    $this->SetEvidenceReadyVariable($cameraId, false);
                    $this->SetEvidenceStringVariable($cameraId, 'Evidence Status', 'failed');
                    $this->LogMessage(
                        'Evidence status polling timed out for camera "' . IPS_GetName($cameraId) . '". Job: ' . $jobId,
                        KL_WARNING
                    );
                    unset($state[$cameraKey]);
                    continue;
                }

                $pollInFlight = !empty($state[$cameraKey]['pollInFlight']);
                $pollStartedAt = (int) ($state[$cameraKey]['pollStartedAt'] ?? 0);
                if ($pollInFlight && ($now - $pollStartedAt) < 30) {
                    continue;
                }

                $state[$cameraKey]['pollInFlight'] = true;
                $state[$cameraKey]['pollStartedAt'] = $now;
                $state[$cameraKey]['serviceUrl'] = $serviceUrl;
                $state[$cameraKey]['statusUrl'] = $statusUrl;

                $polls[] = [
                    'cameraId'   => $cameraId,
                    'jobId'      => $jobId,
                    'serviceUrl' => $serviceUrl,
                    'statusUrl'  => $statusUrl
                ];
            }

            $this->WriteEvidenceTrackingState($state);
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }

        $this->RefreshEvidencePollingTimer();

        if (count($polls) === 0) {
            return;
        }

        $workerScript = 'require_once (string) $_IPS["WorkerFile"]; HikvisionEvidenceStatusWorker::Run((string) $_IPS["WorkerData"]);';
        $callback = $prefix . '_CompleteEvidenceStatusPoll';

        foreach ($polls as $poll) {
            $workerData = [
                'instanceId' => $this->InstanceID,
                'cameraId'   => $poll['cameraId'],
                'jobId'      => $poll['jobId'],
                'serviceUrl' => $poll['serviceUrl'],
                'statusUrl'  => $poll['statusUrl'],
                'callback'   => $callback,
                'debug'      => $this->ReadPropertyBoolean('debug')
            ];

            $started = IPS_RunScriptTextEx(
                $workerScript,
                [
                    'WorkerFile' => $workerFile,
                    'WorkerData' => json_encode($workerData)
                ]
            );

            if (!$started) {
                $this->CompleteEvidenceStatusPoll(
                    (int) $poll['cameraId'],
                    (string) $poll['jobId'],
                    json_encode([
                        'success' => false,
                        'job'     => (string) $poll['jobId'],
                        'message' => 'IP-Symcon did not start the evidence status worker.'
                    ])
                );
            }
        }
    }

    public function CompleteEvidenceStatusPoll(int $cameraId, string $jobId, string $resultJson): void
    {
        if (!IPS_VariableExists($cameraId)) {
            return;
        }

        $currentJobId = $this->GetEvidenceStringVariableValue($cameraId, 'Evidence Job ID');
        if ($currentJobId === '' || $currentJobId !== $jobId) {
            return;
        }

        $result = json_decode($resultJson, true);
        if (!is_array($result)) {
            $result = [
                'success' => false,
                'message' => 'Evidence status worker returned invalid result JSON.'
            ];
        }

        $semaphore = 'HikvisionEvidenceStatus_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 2000)) {
            return;
        }

        $logMessage = '';
        $logLevel = KL_DEBUG;

        try {
            $state = $this->ReadEvidenceTrackingState();
            $cameraKey = (string) $cameraId;
            $entry = $state[$cameraKey] ?? [
                'job'           => $jobId,
                'serviceUrl'    => '',
                'statusUrl'     => $this->GetEvidenceStringVariableValue($cameraId, 'Evidence Status URL'),
                'acceptedAt'    => time(),
                'pollInFlight'  => false,
                'pollStartedAt' => 0,
                'pollErrors'    => 0
            ];

            if ((string) ($entry['job'] ?? '') !== $jobId) {
                return;
            }

            $entry['pollInFlight'] = false;
            $entry['pollStartedAt'] = 0;

            if (empty($result['success'])) {
                $errors = (int) ($entry['pollErrors'] ?? 0) + 1;
                $entry['pollErrors'] = $errors;
                $state[$cameraKey] = $entry;
                $this->WriteEvidenceTrackingState($state);

                if ($errors === 1 || ($errors % 6) === 0) {
                    $logMessage = 'Evidence status poll failed for camera "' . IPS_GetName($cameraId) . '" job ' . $jobId . ': ' .
                        trim((string) ($result['message'] ?? 'Unknown status error.'));
                    $logLevel = KL_WARNING;
                }
            } else {
                $status = strtolower(trim((string) ($result['status'] ?? '')));
                if ($status === 'pending') {
                    $status = 'waiting';
                }
                if (!in_array($status, ['accepted', 'waiting', 'running', 'done', 'failed'], true)) {
                    $status = 'waiting';
                }

                $entry['pollErrors'] = 0;
                $this->SetEvidenceStringVariable($cameraId, 'Evidence Status', $status);
                $this->SetEvidenceReadyVariable($cameraId, $status === 'done');

                $file = trim((string) ($result['file'] ?? ''));
                if ($file !== '') {
                    $this->SetEvidenceStringVariable($cameraId, 'Evidence File', $file);
                }

                $serviceUrl = rtrim(trim((string) ($result['serviceUrl'] ?? ($entry['serviceUrl'] ?? ''))), '/');
                $videoUrl = $this->ResolveEvidenceUrl($serviceUrl, (string) ($result['videoUrl'] ?? ''));
                if ($videoUrl !== '') {
                    $this->SetEvidenceStringVariable($cameraId, 'Evidence URL', $videoUrl);
                }

                if ($status === 'done' || $status === 'failed') {
                    unset($state[$cameraKey]);
                    if ($this->ReadPropertyBoolean('debug')) {
                        $logMessage = 'Evidence job ' . $jobId . ' for camera "' . IPS_GetName($cameraId) . '" reached terminal status ' . $status . '.';
                        $logLevel = KL_DEBUG;
                    }
                } else {
                    $state[$cameraKey] = $entry;
                }

                $this->WriteEvidenceTrackingState($state);
            }
        } finally {
            IPS_SemaphoreLeave($semaphore);
            $this->RefreshEvidencePollingTimer();
        }

        if ($logMessage !== '') {
            $this->LogMessage($logMessage, $logLevel);
        }
    }

    private function SetEvidenceTrackingFailed(int $cameraId, string $message): void
    {
        $this->RemoveEvidenceTrackingState($cameraId);
        $this->SetEvidenceReadyVariable($cameraId, false);
        $this->SetEvidenceStringVariable($cameraId, 'Evidence Status', 'failed');

        if ($message !== '' && $this->ReadPropertyBoolean('debug')) {
            $this->LogMessage('Evidence request failed for camera "' . IPS_GetName($cameraId) . '": ' . $message, KL_DEBUG);
        }

        $this->RefreshEvidencePollingTimer();
    }

    private function EnsureEvidenceReadyVariables(): void
    {
        foreach ($this->GetEvidenceCameraIds() as $cameraId) {
            $this->EnsureEvidenceReadyVariable($cameraId);
        }
    }

    private function EnsureEvidenceReadyVariable(int $cameraId): ?int
    {
        $existingId = @IPS_GetObjectIDByName('Evidence Ready', $cameraId);
        if ($existingId !== false) {
            $object = IPS_GetObject($existingId);
            if ((int) ($object['ObjectType'] ?? -1) !== 2) {
                $this->LogMessage('Object "Evidence Ready" exists under camera ID ' . $cameraId . ' but is not a variable.', KL_WARNING);
                return null;
            }

            $variable = IPS_GetVariable($existingId);
            if ((int) ($variable['VariableType'] ?? -1) !== 0) {
                $this->LogMessage('Variable "Evidence Ready" under camera ID ' . $cameraId . ' is not Boolean.', KL_WARNING);
                return null;
            }

            return $existingId;
        }

        $variableId = IPS_CreateVariable(0);
        IPS_SetName($variableId, 'Evidence Ready');
        IPS_SetParent($variableId, $cameraId);
        IPS_SetVariableCustomProfile($variableId, '~Switch');
        SetValueBoolean($variableId, false);
        return $variableId;
    }

    private function SetEvidenceReadyVariable(int $cameraId, bool $ready): void
    {
        $variableId = $this->EnsureEvidenceReadyVariable($cameraId);
        if ($variableId !== null) {
            SetValueBoolean($variableId, $ready);
        }
    }

    private function GetEvidenceCameraIds(): array
    {
        $cameraIds = [];
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

            $cameraIds[] = (int) $cameraId;
        }
        return $cameraIds;
    }

    private function GetActiveEvidenceJobsFromVariables(): array
    {
        $jobs = [];
        $serviceUrl = rtrim(trim($this->ReadPropertyString('EvidenceServiceURL')), '/');

        foreach ($this->GetEvidenceCameraIds() as $cameraId) {
            $status = strtolower(trim($this->GetEvidenceStringVariableValue($cameraId, 'Evidence Status')));
            if (!in_array($status, ['accepted', 'waiting', 'running'], true)) {
                continue;
            }

            $jobId = trim($this->GetEvidenceStringVariableValue($cameraId, 'Evidence Job ID'));
            $statusUrl = trim($this->GetEvidenceStringVariableValue($cameraId, 'Evidence Status URL'));
            if ($jobId === '' || $statusUrl === '') {
                continue;
            }

            $jobs[(string) $cameraId] = [
                'job'        => $jobId,
                'statusUrl'  => $statusUrl,
                'serviceUrl' => $serviceUrl
            ];
        }

        return $jobs;
    }

    private function GetEvidenceStringVariableValue(int $cameraId, string $name): string
    {
        $variableId = @IPS_GetVariableIDByName($name, $cameraId);
        if ($variableId === false) {
            return '';
        }

        $variable = IPS_GetVariable($variableId);
        if ((int) ($variable['VariableType'] ?? -1) !== 3) {
            return '';
        }

        return trim((string) GetValueString($variableId));
    }

    private function ReadEvidenceTrackingState(): array
    {
        $state = json_decode($this->GetBuffer('EvidenceTrackingState'), true);
        return is_array($state) ? $state : [];
    }

    private function WriteEvidenceTrackingState(array $state): void
    {
        $encoded = json_encode($state);
        $this->SetBuffer('EvidenceTrackingState', $encoded === false ? '{}' : $encoded);
    }

    private function SetEvidenceTrackingState(int $cameraId, array $entry): void
    {
        $semaphore = 'HikvisionEvidenceStatus_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 2000)) {
            return;
        }

        try {
            $state = $this->ReadEvidenceTrackingState();
            $state[(string) $cameraId] = $entry;
            $this->WriteEvidenceTrackingState($state);
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    private function RemoveEvidenceTrackingState(int $cameraId): void
    {
        $semaphore = 'HikvisionEvidenceStatus_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 2000)) {
            return;
        }

        try {
            $state = $this->ReadEvidenceTrackingState();
            unset($state[(string) $cameraId]);
            $this->WriteEvidenceTrackingState($state);
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    private function RefreshEvidencePollingTimer(): void
    {
        $active = $this->ReadPropertyBoolean('EnableEvidenceRecording') && count($this->GetActiveEvidenceJobsFromVariables()) > 0;
        $this->SetEvidencePollScriptTimer($active ? 10 : 0);
    }

    private function SetEvidencePollScriptTimer(int $seconds): void
    {
        $scriptId = $this->FindEvidencePollScript();
        if ($seconds <= 0) {
            if ($scriptId !== null) {
                IPS_SetScriptTimer($scriptId, 0);
            }
            return;
        }

        if ($scriptId === null) {
            $scriptId = $this->CreateEvidencePollScript();
        } else {
            $this->UpdateEvidencePollScriptContent($scriptId);
        }

        if ($scriptId !== null) {
            IPS_SetScriptTimer($scriptId, $seconds);
        }
    }

    private function FindEvidencePollScript(): ?int
    {
        $scriptId = @IPS_GetObjectIDByName('Evidence Status Poll', $this->InstanceID);
        if ($scriptId === false) {
            return null;
        }

        $object = IPS_GetObject($scriptId);
        if ((int) ($object['ObjectType'] ?? -1) !== 3) {
            $this->LogMessage('Object "Evidence Status Poll" exists below the module but is not a script.', KL_WARNING);
            return null;
        }

        return (int) $scriptId;
    }

    private function CreateEvidencePollScript(): ?int
    {
        $prefix = $this->GetEvidenceModulePrefix();
        if ($prefix === '') {
            return null;
        }

        $scriptId = IPS_CreateScript(0);
        IPS_SetName($scriptId, 'Evidence Status Poll');
        IPS_SetParent($scriptId, $this->InstanceID);
        IPS_SetHidden($scriptId, true);
        $this->UpdateEvidencePollScriptContent($scriptId);
        return $scriptId;
    }

    private function UpdateEvidencePollScriptContent(int $scriptId): void
    {
        $prefix = $this->GetEvidenceModulePrefix();
        if ($prefix === '') {
            return;
        }

        IPS_SetScriptContent(
            $scriptId,
            "<?php\n" . $prefix . '_PollEvidenceJobs(' . $this->InstanceID . ");\n"
        );
    }

    private function GetEvidenceModulePrefix(): string
    {
        $instanceInfo = IPS_GetInstance($this->InstanceID);
        $moduleId = $instanceInfo['ModuleInfo']['ModuleID'] ?? '';
        $moduleInfo = $moduleId !== '' ? IPS_GetModule($moduleId) : [];
        $prefix = (string) ($moduleInfo['Prefix'] ?? '');

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $prefix) ? $prefix : '';
    }
}
