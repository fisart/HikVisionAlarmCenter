<?php

/**
 * Isolated HTTP worker for optional NVR evidence capture.
 *
 * The worker performs one bounded POST to the evidence service and then calls
 * back into the module briefly with the accepted job metadata. It never handles
 * camera credentials and never performs NVR/RTSP work itself.
 */
final class HikvisionEvidenceRequestWorker
{
    public static function Run(string $workerDataJson): void
    {
        $config = json_decode($workerDataJson, true);
        if (!is_array($config)) {
            IPS_LogMessage('Hikvision Evidence Worker', 'Invalid worker JSON data.');
            return;
        }

        $instanceId = (int) ($config['instanceId'] ?? 0);
        $cameraId = (int) ($config['cameraId'] ?? 0);
        $callback = (string) ($config['callback'] ?? '');

        $result = [
            'success' => false,
            'message' => 'Unknown evidence worker error.'
        ];

        try {
            $serviceUrl = rtrim(trim((string) ($config['serviceUrl'] ?? '')), '/');
            $cameraName = trim((string) ($config['cameraName'] ?? ''));
            $track = (int) ($config['track'] ?? 0);
            $eventTime = trim((string) ($config['eventTime'] ?? ''));
            $before = max(0, min(300, (int) ($config['before'] ?? 15)));
            $after = max(0, min(300, (int) ($config['after'] ?? 0)));

            if ($instanceId <= 0 || $cameraId <= 0 || $serviceUrl === '' || $cameraName === '' || $track <= 0 || $eventTime === '') {
                throw new RuntimeException('Evidence worker data is incomplete.');
            }

            $payload = json_encode([
                'track'     => $track,
                'camera'    => $cameraName,
                'eventTime' => $eventTime,
                'before'    => $before,
                'after'     => $after
            ], JSON_UNESCAPED_SLASHES);

            if ($payload === false) {
                throw new RuntimeException('Unable to encode evidence request.');
            }

            $ch = curl_init($serviceUrl . '/capture');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER    => true,
                CURLOPT_POST              => true,
                CURLOPT_POSTFIELDS        => $payload,
                CURLOPT_HTTPHEADER        => ['Content-Type: application/json'],
                CURLOPT_CONNECTTIMEOUT_MS => 500,
                CURLOPT_TIMEOUT_MS        => 1500,
                CURLOPT_FOLLOWLOCATION    => false
            ]);

            $body = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrno = curl_errno($ch);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($body === false) {
                throw new RuntimeException('Evidence service connection failed (' . $curlErrno . '): ' . $curlError);
            }

            $response = json_decode($body, true);
            if ($httpCode !== 202 || !is_array($response) || empty($response['accepted'])) {
                throw new RuntimeException('Evidence service rejected the request. HTTP ' . $httpCode . '.');
            }

            $result = [
                'success'    => true,
                'status'     => 'accepted',
                'job'        => (string) ($response['job'] ?? ''),
                'file'       => (string) ($response['file'] ?? ''),
                'statusUrl'  => (string) ($response['statusUrl'] ?? ''),
                'videoUrl'   => (string) ($response['videoUrl'] ?? ''),
                'serviceUrl' => $serviceUrl
            ];
        } catch (Throwable $e) {
            $result = [
                'success' => false,
                'message' => $e->getMessage()
            ];
        } finally {
            $resultJson = json_encode($result);
            if ($resultJson === false) {
                $resultJson = '{"success":false,"message":"Unable to encode evidence worker result."}';
            }

            if ($callback !== '' && is_callable($callback)) {
                call_user_func($callback, $instanceId, $cameraId, $resultJson);
            } else {
                IPS_LogMessage('Hikvision Evidence Worker', 'Completion callback is unavailable.');
            }
        }
    }
}
