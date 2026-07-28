<?php

/**
 * Isolated Smart Event worker for ProcessCameraEvents v1.6.2.
 *
 * The worker performs camera cURL calls and same-camera delays outside the
 * webhook-receiving module instance. It calls the module only once, briefly,
 * to report its final result.
 */
final class HikvisionSmartAlarmWorker
{
    public static function Run(string $workerDataJson): void
    {
        $workerData = json_decode($workerDataJson, true);
        if (!is_array($workerData)) {
            IPS_LogMessage('Hikvision Smart Worker', 'Invalid worker JSON data.');
            return;
        }

        $instanceId = (int) ($workerData['instanceId'] ?? 0);
        $runId = (string) ($workerData['runId'] ?? '');
        $workerNumber = (int) ($workerData['workerNumber'] ?? 0);
        $enabled = (bool) ($workerData['enabled'] ?? false);
        $cameras = $workerData['cameras'] ?? [];
        $callback = (string) ($workerData['callback'] ?? '');
        $results = [];

        try {
            if ($instanceId <= 0 || $runId === '' || !is_array($cameras)) {
                throw new RuntimeException('Smart Event worker data is incomplete.');
            }

            foreach ($cameras as $camera) {
                if (!self::IsRunCurrent($workerData, $runId)) {
                    break;
                }

                if (!is_array($camera)) {
                    continue;
                }

                $results[] = self::ProcessSingleCamera($camera, $enabled, $runId, $workerData);
            }
        } catch (Throwable $e) {
            $results[] = [
                'ip'     => '',
                'name'   => 'Worker ' . $workerNumber,
                'status' => 'failed',
                'paths'  => [[
                    'path'     => 'Worker execution',
                    'status'   => 'failed',
                    'attempts' => 0,
                    'message'  => $e->getMessage()
                ]]
            ];
        } finally {
            $resultJson = json_encode($results);
            if ($resultJson === false) {
                $resultJson = '[]';
            }

            if ($callback !== '' && is_callable($callback)) {
                call_user_func($callback, $instanceId, $runId, $workerNumber, $resultJson);
            } else {
                IPS_LogMessage(
                    'Hikvision Smart Worker',
                    'Completion callback is unavailable for worker ' . $workerNumber . '.'
                );
            }
        }
    }

    private static function ProcessSingleCamera(
        array $camera,
        bool $enabled,
        string $runId,
        array $config
    ): array {
        $ip = trim((string) ($camera['ip'] ?? ''));
        $name = (string) ($camera['name'] ?? $ip);
        $username = (string) ($camera['username'] ?? '');
        $password = (string) ($camera['password'] ?? '');

        $cameraResult = [
            'ip'     => $ip,
            'name'   => $name,
            'status' => 'success',
            'paths'  => []
        ];

        if ($ip === '' || $username === '' || $password === '') {
            $cameraResult['status'] = 'failed';
            $cameraResult['paths'][] = [
                'path'     => 'Camera configuration',
                'status'   => 'failed',
                'attempts' => 0,
                'message'  => 'Camera IP or credentials are missing.'
            ];
            return $cameraResult;
        }

        $semaphoreName = 'HikvisionSmartCamera_' . md5($ip);
        $curlTimeout = max(1, min(60, (int) ($config['curlTimeout'] ?? 10)));
        $semaphoreTimeoutMs = max(5000, ($curlTimeout + 2) * 1000);

        if (!IPS_SemaphoreEnter($semaphoreName, $semaphoreTimeoutMs)) {
            $cameraResult['status'] = 'failed';
            $cameraResult['paths'][] = [
                'path'     => 'Camera semaphore',
                'status'   => 'failed',
                'attempts' => 0,
                'message'  => 'The camera is still being configured by another operation.'
            ];
            return $cameraResult;
        }

        try {
            $lastCommandFinishedAt = 0.0;
            $paths = [
                'Smart/FieldDetection',
                'Smart/LineDetection',
                'Smart/RegionEntrance',
                'Smart/RegionExiting'
            ];

            foreach ($paths as $path) {
                if (!self::IsRunCurrent($config, $runId)) {
                    $cameraResult['status'] = 'cancelled';
                    break;
                }

                $pathResult = self::ProcessPath(
                    $ip,
                    $username,
                    $password,
                    $path,
                    $enabled,
                    $runId,
                    $lastCommandFinishedAt,
                    $config
                );
                $cameraResult['paths'][] = $pathResult;

                if (($pathResult['status'] ?? '') === 'failed') {
                    $cameraResult['status'] = 'failed';
                    if (!empty($pathResult['stopCamera'])) {
                        break;
                    }
                }
            }
        } finally {
            IPS_SemaphoreLeave($semaphoreName);
        }

        return $cameraResult;
    }

    private static function ProcessPath(
        string $ip,
        string $username,
        string $password,
        string $path,
        bool $enabled,
        string $runId,
        float &$lastCommandFinishedAt,
        array $config
    ): array {
        $retryCount = max(0, min(5, (int) ($config['retryCount'] ?? 2)));
        $maxAttempts = $retryCount + 1;
        $detectionType = self::GetStringAfterSmart($path);
        $requestedValue = $enabled ? 'true' : 'false';
        $lastMessage = 'Unknown error.';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if (!self::IsRunCurrent($config, $runId)) {
                return [
                    'path'     => $path,
                    'status'   => 'cancelled',
                    'attempts' => $attempt - 1,
                    'message'  => 'Operation cancelled.'
                ];
            }

            $getResult = self::ExecuteRequest(
                'GET',
                $ip,
                $username,
                $password,
                $path,
                null,
                $runId,
                $lastCommandFinishedAt,
                $config
            );

            if (!$getResult['success']) {
                if ($getResult['cancelled']) {
                    return [
                        'path'     => $path,
                        'status'   => 'cancelled',
                        'attempts' => $attempt - 1,
                        'message'  => 'Operation cancelled.'
                    ];
                }

                if ($getResult['unsupported']) {
                    return [
                        'path'     => $path,
                        'status'   => 'unsupported',
                        'attempts' => $attempt,
                        'message'  => $getResult['message']
                    ];
                }

                $lastMessage = 'GET failed: ' . $getResult['message'];
                if ($getResult['temporary'] && $attempt < $maxAttempts) {
                    if (!self::WaitForRetry($attempt, $runId, $config)) {
                        break;
                    }
                    continue;
                }

                return [
                    'path'       => $path,
                    'status'     => 'failed',
                    'attempts'   => $attempt,
                    'message'    => $lastMessage,
                    'stopCamera' => self::ShouldStopCameraAfterRequestFailure($getResult)
                ];
            }

            $currentStates = self::GetDetectionEnabledStates($getResult['body'], $detectionType, 1);
            if ($currentStates === null) {
                return [
                    'path'     => $path,
                    'status'   => 'unsupported',
                    'attempts' => $attempt,
                    'message'  => 'No supported <enabled> element was found in the camera XML.'
                ];
            }

            $linkageMessage = '';
            if ($enabled) {
                $triggerId = self::GetEventTriggerIdForSmartPath($path);
                if ($triggerId === null) {
                    return [
                        'path'     => $path,
                        'status'   => 'failed',
                        'attempts' => $attempt,
                        'message'  => 'No EventTrigger ID mapping exists for this Smart Event.'
                    ];
                }

                $linkageResult = self::EnsureCenterLinkage(
                    $ip,
                    $username,
                    $password,
                    $triggerId,
                    $runId,
                    $lastCommandFinishedAt,
                    $config
                );

                if (($linkageResult['status'] ?? '') !== 'success') {
                    return [
                        'path'       => $path,
                        'status'     => ($linkageResult['status'] ?? '') === 'cancelled' ? 'cancelled' : 'failed',
                        'attempts'   => (int) ($linkageResult['attempts'] ?? $attempt),
                        'message'    => 'Notify Surveillance Center linkage failed: ' . (string) ($linkageResult['message'] ?? 'Unknown error.'),
                        'stopCamera' => !empty($linkageResult['stopCamera'])
                    ];
                }

                $linkageMessage = (string) ($linkageResult['message'] ?? 'Notify Surveillance Center verified.');
            }

            // Avoid an unnecessary PUT when all rules already have the requested state.
            if (self::AllStatesMatch($currentStates, $enabled)) {
                return [
                    'path'     => $path,
                    'status'   => 'success',
                    'attempts' => $attempt,
                    'message'  => trim($linkageMessage . ' Requested state already set.')
                ];
            }

            try {
                $modifiedXml = self::UpdateDetectionEnabled(
                    $getResult['body'],
                    $detectionType,
                    1,
                    $requestedValue
                );
            } catch (Throwable $e) {
                return [
                    'path'     => $path,
                    'status'   => 'failed',
                    'attempts' => $attempt,
                    'message'  => $e->getMessage()
                ];
            }

            $putResult = self::ExecuteRequest(
                'PUT',
                $ip,
                $username,
                $password,
                $path,
                $modifiedXml,
                $runId,
                $lastCommandFinishedAt,
                $config
            );

            if (!$putResult['success']) {
                if ($putResult['cancelled']) {
                    return [
                        'path'     => $path,
                        'status'   => 'cancelled',
                        'attempts' => $attempt,
                        'message'  => 'Operation cancelled.'
                    ];
                }

                if ($putResult['unsupported']) {
                    return [
                        'path'     => $path,
                        'status'   => 'unsupported',
                        'attempts' => $attempt,
                        'message'  => $putResult['message']
                    ];
                }

                $lastMessage = 'PUT failed: ' . $putResult['message'];
                if ($putResult['temporary'] && $attempt < $maxAttempts) {
                    if (!self::WaitForRetry($attempt, $runId, $config)) {
                        break;
                    }
                    continue;
                }

                return [
                    'path'     => $path,
                    'status'   => 'failed',
                    'attempts' => $attempt,
                    'message'  => $lastMessage
                ];
            }

            $responseStatus = self::ParseResponseStatus($putResult['body']);
            if ($responseStatus['present'] && !$responseStatus['success']) {
                if ($responseStatus['unsupported']) {
                    return [
                        'path'     => $path,
                        'status'   => 'unsupported',
                        'attempts' => $attempt,
                        'message'  => $responseStatus['message']
                    ];
                }

                $lastMessage = 'Camera rejected PUT: ' . $responseStatus['message'];
                if ($responseStatus['temporary'] && $attempt < $maxAttempts) {
                    if (!self::WaitForRetry($attempt, $runId, $config)) {
                        break;
                    }
                    continue;
                }

                return [
                    'path'     => $path,
                    'status'   => 'failed',
                    'attempts' => $attempt,
                    'message'  => $lastMessage
                ];
            }

            // Read back after the configured same-camera delay and verify the result.
            $verifyResult = self::ExecuteRequest(
                'GET',
                $ip,
                $username,
                $password,
                $path,
                null,
                $runId,
                $lastCommandFinishedAt,
                $config
            );

            if (!$verifyResult['success']) {
                $lastMessage = 'Verification GET failed: ' . $verifyResult['message'];
                if ($verifyResult['temporary'] && $attempt < $maxAttempts) {
                    if (!self::WaitForRetry($attempt, $runId, $config)) {
                        break;
                    }
                    continue;
                }

                return [
                    'path'       => $path,
                    'status'     => 'failed',
                    'attempts'   => $attempt,
                    'message'    => $lastMessage,
                    'stopCamera' => self::ShouldStopCameraAfterRequestFailure($verifyResult)
                ];
            }

            $verifiedStates = self::GetDetectionEnabledStates($verifyResult['body'], $detectionType, 1);
            if ($verifiedStates !== null && self::AllStatesMatch($verifiedStates, $enabled)) {
                self::Debug($config, "Verified $path for IP $ip on attempt $attempt.");
                return [
                    'path'     => $path,
                    'status'   => 'success',
                    'attempts' => $attempt,
                    'message'  => trim($linkageMessage . ' Requested state verified.')
                ];
            }

            $lastMessage = 'Verification mismatch: the requested state was not returned by the camera.';
            if ($attempt < $maxAttempts) {
                if (!self::WaitForRetry($attempt, $runId, $config)) {
                    break;
                }
            }
        }

        return [
            'path'     => $path,
            'status'   => 'failed',
            'attempts' => $maxAttempts,
            'message'  => $lastMessage
        ];
    }

    /**
     * Ensures that the Hikvision linkage method "Notify Surveillance Center"
     * is present for the selected Smart Event. Existing linkage methods are
     * preserved. This is called only when a Smart Event is being enabled.
     */
    private static function EnsureCenterLinkage(
        string $ip,
        string $username,
        string $password,
        string $triggerId,
        string $runId,
        float &$lastCommandFinishedAt,
        array $config
    ): array {
        $retryCount = max(0, min(5, (int) ($config['retryCount'] ?? 2)));
        $maxAttempts = $retryCount + 1;
        $triggerPath = 'Event/triggers/' . rawurlencode($triggerId);
        $lastMessage = 'Unknown linkage error.';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if (!self::IsRunCurrent($config, $runId)) {
                return [
                    'status'   => 'cancelled',
                    'attempts' => $attempt - 1,
                    'message'  => 'Operation cancelled.'
                ];
            }

            $getResult = self::ExecuteRequest(
                'GET',
                $ip,
                $username,
                $password,
                $triggerPath,
                null,
                $runId,
                $lastCommandFinishedAt,
                $config
            );

            if (!$getResult['success']) {
                if ($getResult['cancelled']) {
                    return [
                        'status'   => 'cancelled',
                        'attempts' => $attempt - 1,
                        'message'  => 'Operation cancelled.'
                    ];
                }

                $lastMessage = 'GET EventTrigger failed: ' . $getResult['message'];
                if ($getResult['temporary'] && $attempt < $maxAttempts) {
                    if (!self::WaitForRetry($attempt, $runId, $config)) {
                        break;
                    }
                    continue;
                }

                return [
                    'status'     => 'failed',
                    'attempts'   => $attempt,
                    'message'    => $lastMessage,
                    'stopCamera' => self::ShouldStopCameraAfterRequestFailure($getResult)
                ];
            }

            $hasCenter = self::HasCenterNotification($getResult['body']);
            if ($hasCenter === null) {
                return [
                    'status'   => 'failed',
                    'attempts' => $attempt,
                    'message'  => 'The EventTrigger XML contains no usable EventTriggerNotificationList.'
                ];
            }

            if ($hasCenter) {
                return [
                    'status'   => 'success',
                    'attempts' => $attempt,
                    'message'  => 'Notify Surveillance Center already configured.'
                ];
            }

            try {
                $modifiedTriggerXml = self::AddCenterNotification($getResult['body']);
            } catch (Throwable $e) {
                return [
                    'status'   => 'failed',
                    'attempts' => $attempt,
                    'message'  => $e->getMessage()
                ];
            }

            $putResult = self::ExecuteRequest(
                'PUT',
                $ip,
                $username,
                $password,
                $triggerPath,
                $modifiedTriggerXml,
                $runId,
                $lastCommandFinishedAt,
                $config
            );

            if (!$putResult['success']) {
                if ($putResult['cancelled']) {
                    return [
                        'status'   => 'cancelled',
                        'attempts' => $attempt,
                        'message'  => 'Operation cancelled.'
                    ];
                }

                $lastMessage = 'PUT EventTrigger failed: ' . $putResult['message'];
                if ($putResult['temporary'] && $attempt < $maxAttempts) {
                    if (!self::WaitForRetry($attempt, $runId, $config)) {
                        break;
                    }
                    continue;
                }

                return [
                    'status'     => 'failed',
                    'attempts'   => $attempt,
                    'message'    => $lastMessage,
                    'stopCamera' => self::ShouldStopCameraAfterRequestFailure($putResult)
                ];
            }

            $responseStatus = self::ParseResponseStatus($putResult['body']);
            if ($responseStatus['present'] && !$responseStatus['success']) {
                $lastMessage = 'Camera rejected EventTrigger PUT: ' . $responseStatus['message'];
                if ($responseStatus['temporary'] && $attempt < $maxAttempts) {
                    if (!self::WaitForRetry($attempt, $runId, $config)) {
                        break;
                    }
                    continue;
                }

                return [
                    'status'   => 'failed',
                    'attempts' => $attempt,
                    'message'  => $lastMessage
                ];
            }

            $verifyResult = self::ExecuteRequest(
                'GET',
                $ip,
                $username,
                $password,
                $triggerPath,
                null,
                $runId,
                $lastCommandFinishedAt,
                $config
            );

            if (!$verifyResult['success']) {
                $lastMessage = 'EventTrigger verification GET failed: ' . $verifyResult['message'];
                if ($verifyResult['temporary'] && $attempt < $maxAttempts) {
                    if (!self::WaitForRetry($attempt, $runId, $config)) {
                        break;
                    }
                    continue;
                }

                return [
                    'status'     => 'failed',
                    'attempts'   => $attempt,
                    'message'    => $lastMessage,
                    'stopCamera' => self::ShouldStopCameraAfterRequestFailure($verifyResult)
                ];
            }

            if (self::HasCenterNotification($verifyResult['body']) === true) {
                self::Debug($config, "Verified Notify Surveillance Center for $triggerId on IP $ip.");
                return [
                    'status'   => 'success',
                    'attempts' => $attempt,
                    'message'  => 'Notify Surveillance Center added and verified.'
                ];
            }

            $lastMessage = 'EventTrigger verification mismatch: notificationMethod=center was not returned.';
            if ($attempt < $maxAttempts) {
                if (!self::WaitForRetry($attempt, $runId, $config)) {
                    break;
                }
            }
        }

        return [
            'status'   => 'failed',
            'attempts' => $maxAttempts,
            'message'  => $lastMessage
        ];
    }

    private static function HasCenterNotification(string $xmlString): ?bool
    {
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        if (@$doc->loadXML($xmlString) === false) {
            return null;
        }

        $xpath = new DOMXPath($doc);
        $notificationLists = $xpath->query('//*[local-name()="EventTriggerNotificationList"]');
        if ($notificationLists === false || $notificationLists->length === 0) {
            return null;
        }

        $notifications = $xpath->query(
            './/*[local-name()="EventTriggerNotification"]',
            $notificationLists->item(0)
        );
        if ($notifications === false) {
            return null;
        }

        foreach ($notifications as $notification) {
            $idNodes = $xpath->query('./*[local-name()="id"]', $notification);
            $methodNodes = $xpath->query('./*[local-name()="notificationMethod"]', $notification);

            $id = $idNodes !== false && $idNodes->length > 0
                ? strtolower(trim((string) $idNodes->item(0)->nodeValue))
                : '';
            $method = $methodNodes !== false && $methodNodes->length > 0
                ? strtolower(trim((string) $methodNodes->item(0)->nodeValue))
                : '';

            if ($id === 'center' || $method === 'center') {
                return true;
            }
        }

        return false;
    }

    private static function AddCenterNotification(string $xmlString): string
    {
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        if (@$doc->loadXML($xmlString) === false) {
            throw new RuntimeException('Failed to parse EventTrigger XML.');
        }

        if (self::HasCenterNotification($xmlString) === true) {
            return $xmlString;
        }

        $xpath = new DOMXPath($doc);
        $notificationLists = $xpath->query('//*[local-name()="EventTriggerNotificationList"]');
        if ($notificationLists === false || $notificationLists->length === 0) {
            throw new RuntimeException('EventTriggerNotificationList does not exist in the EventTrigger XML.');
        }

        $notificationList = $notificationLists->item(0);
        if (!$notificationList instanceof DOMElement) {
            throw new RuntimeException('Invalid EventTriggerNotificationList node.');
        }

        $namespaceUri = $notificationList->namespaceURI ?: ($doc->documentElement->namespaceURI ?? '');
        $prefix = $notificationList->prefix;
        $qualifiedName = static function (string $localName) use ($prefix): string {
            return $prefix ? $prefix . ':' . $localName : $localName;
        };

        if ($namespaceUri !== '') {
            $notification = $doc->createElementNS(
                $namespaceUri,
                $qualifiedName('EventTriggerNotification')
            );
            $idNode = $doc->createElementNS($namespaceUri, $qualifiedName('id'), 'center');
            $methodNode = $doc->createElementNS(
                $namespaceUri,
                $qualifiedName('notificationMethod'),
                'center'
            );
            $recurrenceNode = $doc->createElementNS(
                $namespaceUri,
                $qualifiedName('notificationRecurrence'),
                'beginning'
            );
        } else {
            $notification = $doc->createElement('EventTriggerNotification');
            $idNode = $doc->createElement('id', 'center');
            $methodNode = $doc->createElement('notificationMethod', 'center');
            $recurrenceNode = $doc->createElement('notificationRecurrence', 'beginning');
        }

        $notification->appendChild($idNode);
        $notification->appendChild($methodNode);
        $notification->appendChild($recurrenceNode);
        $notificationList->appendChild($notification);

        $result = $doc->saveXML();
        if (!is_string($result)) {
            throw new RuntimeException('Failed to serialize modified EventTrigger XML.');
        }

        return $result;
    }

    private static function GetEventTriggerIdForSmartPath(string $path): ?string
    {
        $map = [
            'Smart/FieldDetection'  => 'fielddetection-1',
            'Smart/LineDetection'   => 'linedetection-1',
            'Smart/RegionEntrance'  => 'regionEntrance-1',
            'Smart/RegionExiting'   => 'regionExiting-1'
        ];

        return $map[$path] ?? null;
    }

    private static function ExecuteRequest(
        string $method,
        string $ip,
        string $username,
        string $password,
        string $path,
        ?string $xmlBody,
        string $runId,
        float &$lastCommandFinishedAt,
        array $config
    ): array {
        if (!self::WaitForNextCommand($lastCommandFinishedAt, $runId, $config)) {
            return self::CancelledRequestResult();
        }

        if (!self::IsRunCurrent($config, $runId)) {
            return self::CancelledRequestResult();
        }

        $url = "http://$ip/ISAPI/$path";
        $totalTimeout = max(1, min(60, (int) ($config['curlTimeout'] ?? 10)));
        $connectTimeout = min(3, $totalTimeout);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC | CURLAUTH_DIGEST);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $totalTimeout);
        curl_setopt($ch, CURLOPT_NOSIGNAL, true);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);

        if ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/xml; charset=UTF-8']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlBody ?? '');
        }

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        $lastCommandFinishedAt = microtime(true);
        $bodyString = is_string($body) ? $body : '';
        $responseStatus = self::ParseResponseStatus($bodyString);

        $temporaryCurlErrors = [6, 7, 28, 52, 55, 56];
        $temporaryHttpCodes = [408, 429, 500, 502, 503, 504];
        $temporary = in_array($curlErrno, $temporaryCurlErrors, true)
            || in_array($httpCode, $temporaryHttpCodes, true)
            || $responseStatus['temporary'];
        $unsupported = in_array($httpCode, [404, 405], true) || $responseStatus['unsupported'];
        $success = $curlErrno === 0 && $httpCode >= 200 && $httpCode < 300;

        if ($curlErrno !== 0) {
            $message = "cURL error $curlErrno: $curlError";
        } elseif (!$success) {
            $message = "HTTP $httpCode";
            if ($responseStatus['present']) {
                $message .= ': ' . $responseStatus['message'];
            } elseif ($bodyString !== '') {
                $message .= ': ' . trim(substr(strip_tags($bodyString), 0, 180));
            }
        } else {
            $message = 'HTTP ' . $httpCode;
        }

        self::Debug($config, "Smart Event $method $url -> $message");

        return [
            'success'     => $success,
            'cancelled'   => false,
            'temporary'   => $temporary,
            'unsupported' => $unsupported,
            'httpCode'    => $httpCode,
            'curlErrno'   => $curlErrno,
            'body'        => $bodyString,
            'message'     => $message
        ];
    }

    private static function CancelledRequestResult(): array
    {
        return [
            'success'     => false,
            'cancelled'   => true,
            'temporary'   => false,
            'unsupported' => false,
            'httpCode'    => 0,
            'curlErrno'   => 0,
            'body'        => '',
            'message'     => 'Operation cancelled.'
        ];
    }

    private static function ShouldStopCameraAfterRequestFailure(array $result): bool
    {
        $curlErrno = (int) ($result['curlErrno'] ?? 0);
        $httpCode = (int) ($result['httpCode'] ?? 0);

        if ($curlErrno !== 0 || $httpCode === 0) {
            return true;
        }

        return in_array($httpCode, [401, 403, 408, 429, 500, 502, 503, 504], true);
    }

    private static function WaitForNextCommand(float $lastCommandFinishedAt, string $runId, array $config): bool
    {
        $delayMs = max(0, min(5000, (int) ($config['delayMs'] ?? 500)));
        if ($lastCommandFinishedAt <= 0.0 || $delayMs === 0) {
            return self::IsRunCurrent($config, $runId);
        }

        $elapsedMs = (microtime(true) - $lastCommandFinishedAt) * 1000;
        $remainingMs = (int) ceil($delayMs - $elapsedMs);
        if ($remainingMs <= 0) {
            return self::IsRunCurrent($config, $runId);
        }

        return self::InterruptibleSleep($remainingMs, $runId, $config);
    }

    private static function WaitForRetry(int $failedAttempt, string $runId, array $config): bool
    {
        $retryDelayMs = min(4000, 1000 * (2 ** max(0, $failedAttempt - 1)));
        return self::InterruptibleSleep($retryDelayMs, $runId, $config);
    }

    private static function InterruptibleSleep(int $milliseconds, string $runId, array $config): bool
    {
        $remaining = max(0, $milliseconds);
        while ($remaining > 0) {
            if (!self::IsRunCurrent($config, $runId)) {
                return false;
            }

            $slice = min(250, $remaining);
            IPS_Sleep($slice);
            $remaining -= $slice;
        }

        return self::IsRunCurrent($config, $runId);
    }

    private static function IsRunCurrent(array $config, string $runId): bool
    {
        $tokenFile = (string) ($config['tokenFile'] ?? '');
        if ($runId === '' || $tokenFile === '' || !is_file($tokenFile)) {
            return false;
        }

        return trim((string) @file_get_contents($tokenFile)) === $runId;
    }

    private static function ParseResponseStatus(string $xmlString): array
    {
        $result = [
            'present'      => false,
            'success'      => false,
            'temporary'    => false,
            'unsupported'  => false,
            'statusCode'   => '',
            'subStatus'    => '',
            'statusString' => '',
            'message'      => 'No Hikvision ResponseStatus returned.'
        ];

        if (trim($xmlString) === '') {
            return $result;
        }

        $xml = @simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($xml === false) {
            return $result;
        }

        $statusCodeNodes = $xml->xpath('//*[local-name()="statusCode"]');
        if (!is_array($statusCodeNodes) || count($statusCodeNodes) === 0) {
            return $result;
        }

        $subStatusNodes = $xml->xpath('//*[local-name()="subStatusCode"]');
        $statusStringNodes = $xml->xpath('//*[local-name()="statusString"]');

        $statusCode = trim((string) $statusCodeNodes[0]);
        $subStatus = is_array($subStatusNodes) && count($subStatusNodes) > 0
            ? trim((string) $subStatusNodes[0])
            : '';
        $statusString = is_array($statusStringNodes) && count($statusStringNodes) > 0
            ? trim((string) $statusStringNodes[0])
            : '';

        $temporarySubStatus = [
            'serviceUnavailable',
            'upgrading',
            'deviceBusy',
            'reConnectIpc',
            'noMemory'
        ];
        $unsupportedSubStatus = ['notSupport', 'methodNotAllowed'];

        $result['present'] = true;
        $result['success'] = $statusCode === '1';
        $result['temporary'] = $statusCode === '2' || in_array($subStatus, $temporarySubStatus, true);
        $result['unsupported'] = in_array($subStatus, $unsupportedSubStatus, true);
        $result['statusCode'] = $statusCode;
        $result['subStatus'] = $subStatus;
        $result['statusString'] = $statusString;

        $parts = ['statusCode=' . $statusCode];
        if ($subStatus !== '') {
            $parts[] = 'subStatusCode=' . $subStatus;
        }
        if ($statusString !== '') {
            $parts[] = 'statusString=' . $statusString;
        }
        $result['message'] = implode(', ', $parts);

        return $result;
    }

    private static function GetDetectionEnabledStates(string $xmlString, string $detectionType, int $id): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $detectionType)) {
            return null;
        }

        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        if (@$doc->loadXML($xmlString) === false) {
            return null;
        }

        $xpath = new DOMXPath($doc);
        $upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lower = 'abcdefghijklmnopqrstuvwxyz';
        $typeLower = strtolower($detectionType);
        $enabledNodes = $xpath->query(
            '//*[translate(local-name(), "' . $upper . '", "' . $lower . '")="' . $typeLower . '"]'
                . '/*[local-name()="enabled"]'
        );

        if ($enabledNodes === false || $enabledNodes->length === 0) {
            $enabledNodes = $xpath->query(
                '/*[translate(local-name(), "' . $upper . '", "' . $lower . '")="' . strtolower($detectionType . 'List') . '"]'
                    . '/*[translate(local-name(), "' . $upper . '", "' . $lower . '")="' . $typeLower . '"]'
                    . '[*[local-name()="id" and text()="' . $id . '"]]'
                    . '/*[local-name()="enabled"]'
            );
        }

        if ($enabledNodes === false || $enabledNodes->length === 0) {
            return null;
        }

        $states = [];
        foreach ($enabledNodes as $enabledNode) {
            $value = strtolower(trim((string) $enabledNode->nodeValue));
            if (in_array($value, ['true', '1', 'on'], true)) {
                $states[] = true;
            } elseif (in_array($value, ['false', '0', 'off'], true)) {
                $states[] = false;
            } else {
                return null;
            }
        }

        return $states;
    }

    private static function AllStatesMatch(array $states, bool $enabled): bool
    {
        if (count($states) === 0) {
            return false;
        }

        foreach ($states as $state) {
            if ((bool) $state !== $enabled) {
                return false;
            }
        }

        return true;
    }

    private static function UpdateDetectionEnabled(
        string $xmlString,
        string $detectionType,
        int $id,
        string $newEnabledValue
    ): string {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $detectionType)) {
            throw new RuntimeException('Invalid detection type.');
        }

        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        if (@$doc->loadXML($xmlString) === false) {
            throw new RuntimeException("Failed to load XML for detection type: $detectionType.");
        }

        $xpath = new DOMXPath($doc);
        $upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lower = 'abcdefghijklmnopqrstuvwxyz';
        $typeLower = strtolower($detectionType);
        $enabledNodes = $xpath->query(
            '//*[translate(local-name(), "' . $upper . '", "' . $lower . '")="' . $typeLower . '"]'
                . '/*[local-name()="enabled"]'
        );

        if ($enabledNodes === false || $enabledNodes->length === 0) {
            $enabledNodes = $xpath->query(
                '/*[translate(local-name(), "' . $upper . '", "' . $lower . '")="' . strtolower($detectionType . 'List') . '"]'
                    . '/*[translate(local-name(), "' . $upper . '", "' . $lower . '")="' . $typeLower . '"]'
                    . '[*[local-name()="id" and text()="' . $id . '"]]'
                    . '/*[local-name()="enabled"]'
            );
        }

        if ($enabledNodes === false || $enabledNodes->length === 0) {
            throw new RuntimeException("$detectionType does not contain a supported <enabled> element.");
        }

        foreach ($enabledNodes as $enabledNode) {
            $enabledNode->nodeValue = $newEnabledValue;
        }

        $result = $doc->saveXML();
        if (!is_string($result)) {
            throw new RuntimeException("Failed to serialize XML for detection type: $detectionType.");
        }

        return $result;
    }

    private static function GetStringAfterSmart(string $inputString): string
    {
        $position = strpos($inputString, 'Smart/');
        if ($position !== false) {
            return substr($inputString, $position + strlen('Smart/'));
        }

        return $inputString;
    }

    private static function Debug(array $config, string $message): void
    {
        if (!empty($config['debug'])) {
            IPS_LogMessage('Hikvision Smart Worker', $message);
        }
    }
}
