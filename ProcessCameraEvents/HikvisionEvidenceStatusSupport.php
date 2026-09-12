<?php

/**
 * Non-blocking evidence status tracking for ProcessCameraEvents.
 *
 * The module timer only schedules short isolated HTTP workers. All network I/O
 * therefore remains outside the alarm/webhook execution path. Active jobs are
 * persisted in an instance attribute so polling can resume after ApplyChanges
 * or a module reload. Only the newest evidence job per camera is tracked.
 */
trait HikvisionEvidenceStatusSupport
{
    public function BeginEvidenceStatusTracking(int $cameraId): void
    {
        $this->BeginEvidenceDispatch($cameraId);
    }

    public function TrackEvidenceRequestResult(int $cameraId, string $resultJson): void
    {
        if (!IPS_VariableExists($cameraId)) {
            return;
        }

        $result = json_decode($resultJson, true);
        if (!is_array($result)) {
            $this->FailEvidenceDispatch($cameraId, 'Evidence request worker returned invalid result JSON.');
            return;
        }

        if (empty($result['success'])) {
            $this->FailEvidenceDispatch(
                $cameraId,
                trim((string) ($result['message'] ?? 'Unknown evidence service error.'))
            );
            return;
        }

        $this->AcceptEvidenceJob($cameraId, $result);
    }

    private function InitializeEvidenceStatusSupport(): void
    {
        $this->EnsureEvidenceReadyVariables();
        $this->RefreshEvidencePollingTimer();
    }

    private function BeginEvidenceDispatch(int $cameraId): void
    {
        if (!IPS_VariableExists($cameraId)) {
            return;
        }

        $semaphore = 'HikvisionEvidenceJobs_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 2000)) {
            $this->LogMessage('Unable to clear the previous evidence job state for camera ID ' . $cameraId . '.', KL_WARNING);
            return;
        }

        try {
            $jobs = $this->ReadEvidenceActiveJobs();
            unset($jobs[(string) $cameraId]);
            $this->WriteEvidenceActiveJobs($jobs);
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }

        // Clear the previous result only after the old tracked job has been
        // invalidated, so a stale in-flight callback cannot make it Ready again.
        $this->SetEvidenceReadyVariable($cameraId, false);
        $this->SetEvidenceStringVariable($cameraId, 'Evidence Status', 'requesting');
        $this->SetEvidenceStringVariable($cameraId, 'Evidence Job ID', '');
        $this->SetEvidenceStringVariable($cameraId, 'Evidence File', '');
        $this->SetEvidenceStringVariable($cameraId, 'Evidence Status URL', '');
        $this->SetEvidenceStringVariable($cameraId, 'Evidence URL', '');

        $this->RefreshEvidencePollingTimer();
    }

    private function FailEvidenceDispatch(int $cameraId, string $message = ''): void
    {
        if (!IPS_VariableExists($cameraId)) {
            return;
        }

        $semaphore = 'HikvisionEvidenceJobs_' . $this->InstanceID;
        if (IPS_SemaphoreEnter($semaphore, 2000)) {
            try {
                $jobs = $this->ReadEvidenceActiveJobs();
                unset($jobs[(string) $cameraId]);
                $this->WriteEvidenceActiveJobs($jobs);
            } finally {
                IPS_SemaphoreLeave($semaphore);
            }
        }

        $this->SetEvidenceReadyVariable($cameraId, false);
        $this->SetEvidenceStringVariable($cameraId, 'Evidence Status', 'failed');

        if ($message !== '' && $this->ReadPropertyBoolean('debug')) {
            $this->LogMessage('Evidence dispatch failed for camera "' . IPS_GetName($cameraId) . '": ' . $message, KL_DEBUG);
        }

        $this->RefreshEvidencePollingTimer();
    }

    private function AcceptEvidenceJob(int $cameraId, array $result): void
    {
        if (!IPS_VariableExists($cameraId)) {
            return;
        }

        $jobId = trim((string) ($result['job'] ?? ''));
        $serviceUrl = rtrim(trim((string) ($result['serviceUrl'] ?? '')), '/');
        $statusUrl = $this->ResolveEvidenceUrl($serviceUrl, (string) ($result['statusUrl'] ?? ''));
        $videoUrl = $this->ResolveEvidenceUrl($serviceUrl, (string) ($result['videoUrl'] ?? ''));
        $status = strtolower(trim((string) ($result['status'] ?? 'accepted'));
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

        if ($jobId === '' || $statusUrl === '') {
            $this->SetEvidenceReadyVariable($cameraId, false);
            $this->SetEvidenceStringVariable($cameraId, 'Evidence Status', 'failed');
            $this->LogMessage(
                'Evidence service accepted a request for camera "' . IPS_GetName($cameraId) . '" but did not return a usable job/status URL.',
                KL_WARNING
            );
            return;
        }

        if ($status === 'done' || $status === 'failed') {
            $this->RefreshEvidencePollingTimer();
            return;
        }

        $semaphore = 'HikvisionEvidenceJobs_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 2000)) {
            $this->LogMessage('Unable to register evidence polling for camera "' . IPS_GetName($cameraId) . '".', KL_WARNING);
            return;
        }

        try {
            $jobs = $this->ReadEvidenceActiveJobs();
            $jobs[(string) $cameraId] = [
                'job'           => $jobId,
                'serviceUrl'    => $serviceUrl,
                'statusUrl'     => $statusUrl,
                'acceptedAt'    => time(),
                'pollInFlight'  => false,
                'pollStartedAt' => 0,
                'pollErrors'    => 0
            ];
            $this->WriteEvidenceActiveJobs($jobs);
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }

        $this->RefreshEvidencePollingTimer();
    }

    public function PollEvidenceJobs(): void
    {
        if (!$this->ReadPropertyBoolean('EnableEvidenceRecording')) {
            $this->SetTimerInterval('EvidenceStatusPoll', 0);
            return;
        }

        $workerFile = __DIR__ . DIRECTORY_SEPARATOR . 'HikvisionEvidenceStatusWorker.php';
        if (!is_file($workerFile)) {
            $this->LogMessage('HikvisionEvidenceStatusWorker.php is missing from the module directory.', KL_WARNING);
            $this->SetTimerInterval('EvidenceStatusPoll', 0);
            return;
        }

        $prefix = $this->GetEvidenceModulePrefix();
        if ($prefix === '') {
            $this->LogMessage('Unable to determine a valid module prefix for evidence status polling.', KL_WARNING);
            $this->SetTimerInterval('EvidenceStatusPoll', 0);
            return;
        }

        $semaphore = 'HikvisionEvidenceJobs_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 1000)) {
            return;
        }

        $polls = [];
        $now = time();

        try {
            $jobs = $this->ReadEvidenceActiveJobs();

            foreach ($jobs as $cameraKey => $job) {
                $cameraId = (int) $cameraKey;
                if ($cameraId <= 0 || !IPS_VariableExists($cameraId) || !is_array($job)) {
                    unset($jobs[$cameraKey]);
                    continue;
                }

                $jobId = trim((string) ($job['job'] ?? ''));
                $statusUrl = trim((string) ($job['statusUrl'] ?? ''));
                $acceptedAt = (int) ($job['acceptedAt'] ?? $now);

                if ($jobId === '' || $statusUrl === '') {
                    $this->SetEvidenceReadyVariable($cameraId, false);
                    $this->SetEvidenceStringVariable($cameraId, 'Evidence Status', 'failed');
                    unset($jobs[$cameraKey]);
                    continue;
                }

                // The QNAP service currently retries for up to 10 minutes. A
                // 15-minute local ceiling prevents an unreachable status URL
                // from creating an unbounded polling loop.
                if (($now - $acceptedAt) > 900) {
                    $this->SetEvidenceReadyVariable($cameraId, false);
                    $this->SetEvidenceStringVariable($cameraId, 'Evidence Status', 'failed');
                    $this->LogMessage(
                        'Evidence status polling timed out for camera "' . IPS_GetName($cameraId) . '". Job: ' . $jobId,
                        KL_WARNING
                    );
                    unset($jobs[$cameraKey]);
                    continue;
                }

                $pollInFlight = !empty($job['pollInFlight']);
                $pollStartedAt = (int) ($job['pollStartedAt'] ?? 0);
                if ($pollInFlight && ($now - $pollStartedAt) < 30) {
                    continue;
                }

                $jobs[$cameraKey]['pollInFlight'] = true;
                $jobs[$cameraKey]['pollStartedAt'] = $now;

                $polls[] = [
                    'cameraId'   => $cameraId,
                    'jobId'      => $jobId,
                    'serviceUrl' => (string) ($job['serviceUrl'] ?? ''),
                    'statusUrl'  => $statusUrl
                ];
            }

            $this->WriteEvidenceActiveJobs($jobs);
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }

        if (count($polls) === 0) {
            $this->RefreshEvidencePollingTimer();
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
        $result = json_decode($resultJson, true);
        if (!is_array($result)) {
            $result = [
                'success' => false,
                'message' => 'Evidence status worker returned invalid result JSON.'
            ];
        }

        $semaphore = 'HikvisionEvidenceJobs_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 2000)) {
            return;
        }

        $logMessage = '';
        $logLevel = KL_DEBUG;
        $staleCallback = false;

        try {
            $jobs = $this->ReadEvidenceActiveJobs();
            $cameraKey = (string) $cameraId;
            $active = $jobs[$cameraKey] ?? null;

            // A newer alarm cycle can replace the tracked job while an older
            // status worker is still in flight. Ignore such stale callbacks.
            if (!is_array($active) || (string) ($active['job'] ?? '') !== $jobId) {
                $staleCallback = true;
            } else {
                $jobs[$cameraKey]['pollInFlight'] = false;
                $jobs[$cameraKey]['pollStartedAt'] = 0;

                if (empty($result['success'])) {
                    $errors = (int) ($jobs[$cameraKey]['pollErrors'] ?? 0) + 1;
                    $jobs[$cameraKey]['pollErrors'] = $errors;
                    $this->WriteEvidenceActiveJobs($jobs);

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

                    $jobs[$cameraKey]['pollErrors'] = 0;
                    $this->SetEvidenceStringVariable($cameraId, 'Evidence Status', $status);
                    $this->SetEvidenceReadyVariable($cameraId, $status === 'done');

                    $file = trim((string) ($result['file'] ?? ''));
                    if ($file !== '') {
                        $this->SetEvidenceStringVariable($cameraId, 'Evidence File', $file);
                    }

                    $serviceUrl = rtrim(trim((string) ($result['serviceUrl'] ?? ($active['serviceUrl'] ?? ''))), '/');
                    $videoUrl = $this->ResolveEvidenceUrl($serviceUrl, (string) ($result['videoUrl'] ?? ''));
                    if ($videoUrl !== '') {
                        $this->SetEvidenceStringVariable($cameraId, 'Evidence URL', $videoUrl);
                    }

                    if ($status === 'done' || $status === 'failed') {
                        unset($jobs[$cameraKey]);

                        if ($this->ReadPropertyBoolean('debug')) {
                            $logMessage = 'Evidence job ' . $jobId . ' for camera "' . IPS_GetName($cameraId) . '" reached terminal status ' . $status . '.';
                            $logLevel = KL_DEBUG;
                        }
                    }

                    $this->WriteEvidenceActiveJobs($jobs);
                }
            }
        } finally {
            IPS_SemaphoreLeave($semaphore);
            $this->RefreshEvidencePollingTimer();
        }

        if (!$staleCallback && $logMessage !== '') {
            $this->LogMessage($logMessage, $logLevel);
        }
    }

    private function EnsureEvidenceReadyVariables(): void
    {
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

    private function ReadEvidenceActiveJobs(): array
    {
        $jobs = json_decode($this->ReadAttributeString('EvidenceActiveJobs'), true);
        return is_array($jobs) ? $jobs : [];
    }

    private function WriteEvidenceActiveJobs(array $jobs): void
    {
        $encoded = json_encode($jobs);
        $this->WriteAttributeString('EvidenceActiveJobs', $encoded === false ? '{}' : $encoded);
    }

    private function RefreshEvidencePollingTimer(): void
    {
        if (!$this->ReadPropertyBoolean('EnableEvidenceRecording')) {
            $this->SetTimerInterval('EvidenceStatusPoll', 0);
            return;
        }

        $jobs = $this->ReadEvidenceActiveJobs();
        $this->SetTimerInterval('EvidenceStatusPoll', count($jobs) > 0 ? 10000 : 0);
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
