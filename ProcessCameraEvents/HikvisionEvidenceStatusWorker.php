<?php

/**
 * Isolated HTTP worker for polling one Hikvision evidence job status.
 *
 * The worker performs one bounded GET against the evidence service and calls
 * back into the module with the normalized status. No waiting or retry loop is
 * performed here; the module timer decides when another poll is scheduled.
 */
final class HikvisionEvidenceStatusWorker
{
    public static function Run(string $workerDataJson): void
    {
        $config = json_decode($workerDataJson, true);
        if (!is_array($config)) {
            IPS_LogMessage('Hikvision Evidence Status Worker', 'Invalid worker JSON data.');
            return;
        }

        $instanceId = (int) ($config['instanceId'] ?? 0);
        $cameraId = (int) ($config['cameraId'] ?? 0);
        $jobId = trim((string) ($config['jobId'] ?? ''));
        $callback = (string) ($config['callback'] ?? '');

        $result = [
            'success' => false,
            'message' => 'Unknown evidence status worker error.'
        ];

        try {
            $statusUrl = trim((string) ($config['statusUrl'] ?? ''));
            $serviceUrl = rtrim(trim((string) ($config['serviceUrl'] ?? '')), '/');

            if ($instanceId <= 0 || $cameraId <= 0 || $jobId === '' || $statusUrl === '') {
                throw new RuntimeException('Evidence status worker data is incomplete.');
            }

            if (!preg_match('#^https?://#i', $statusUrl)) {
                throw new RuntimeException('Evidence status URL is invalid.');
            }

            $ch = curl_init($statusUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER    => true,
                CURLOPT_HTTPGET           => true,
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
                throw new RuntimeException('Evidence status connection failed (' . $curlErrno . '): ' . $curlError);
            }

            $response = json_decode($body, true);
            if ($httpCode !== 200 || !is_array($response)) {
                throw new RuntimeException('Evidence status request failed. HTTP ' . $httpCode . '.');
            }

            $returnedJob = trim((string) ($response['job'] ?? $jobId));
            if ($returnedJob !== '' && $returnedJob !== $jobId) {
                throw new RuntimeException('Evidence service returned a different job ID.');
            }

            $status = strtolower(trim((string) ($response['status'] ?? '')));
            if ($status === 'pending') {
                $status = 'waiting';
            }

            if (!in_array($status, ['accepted', 'waiting', 'running', 'done', 'failed'], true)) {
                throw new RuntimeException('Evidence service returned an unknown status: ' . ($status === '' ? '(empty)' : $status));
            }

            $result = [
                'success'    => true,
                'job'        => $jobId,
                'status'     => $status,
                'file'       => (string) ($response['file'] ?? ''),
                'videoUrl'   => (string) ($response['videoUrl'] ?? ''),
                'statusUrl'  => $statusUrl,
                'serviceUrl' => $serviceUrl
            ];
        } catch (Throwable $e) {
            $result = [
                'success' => false,
                'job'     => $jobId,
                'message' => $e->getMessage()
            ];
        } finally {
            $resultJson = json_encode($result);
            if ($resultJson === false) {
                $resultJson = '{"success":false,"message":"Unable to encode evidence status worker result."}';
            }

            if ($callback !== '' && is_callable($callback)) {
                call_user_func($callback, $instanceId, $cameraId, $jobId, $resultJson);
            } else {
                IPS_LogMessage('Hikvision Evidence Status Worker', 'Completion callback is unavailable.');
            }
        }
    }
}
