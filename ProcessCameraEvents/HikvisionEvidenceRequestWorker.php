<?php

/**
 * Isolated HTTP worker for optional NVR evidence capture.
 *
 * The worker performs one bounded POST to the evidence service and then calls
 * back into the module briefly with the accepted job metadata. It never handles
 * camera credentials and never performs NVR/RTSP work itself.
 *
 * EvidenceAfterSeconds is retained as the internal property name for backwards
 * compatibility, but the configuration form exposes it as the total clip
 * length. From that value the worker derives the post-event recording time and
 * waits long enough for that part of the NVR recording to exist, plus a small
 * safety margin. The wait happens only in this isolated worker and therefore
 * does not delay the alarm webhook path itself.
 */
final class HikvisionEvidenceRequestWorker
{
    private const DEFAULT_CLIP_LENGTH_SECONDS = 20;
    private const MAX_CLIP_LENGTH_SECONDS = 60;
    private const NVR_INDEX_SAFETY_SECONDS = 30;

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
            $before = max(0, min(self::MAX_CLIP_LENGTH_SECONDS, (int) ($config['before'] ?? 15)));

            // The existing "after" transport field now carries the requested
            // total clip length. A stored value of 0 is treated as the new
            // default so existing installations continue to work after update.
            $requestedClipLength = max(
                0,
                min(self::MAX_CLIP_LENGTH_SECONDS, (int) ($config['after'] ?? 0))
            );
            if ($requestedClipLength <= 0) {
                $requestedClipLength = self::DEFAULT_CLIP_LENGTH_SECONDS;
            }

            // A clip cannot be shorter than its requested pre-event portion.
            $clipLength = max($before, $requestedClipLength);
            $after = max(0, $clipLength - $before);

            // Do not query historical playback until the complete requested
            // post-event section should have been recorded, then allow the NVR
            // a further thirty seconds to finalize/index the segment.
            $delaySeconds = $after + self::NVR_INDEX_SAFETY_SECONDS;

            if ($instanceId <= 0 || $cameraId <= 0 || $serviceUrl === '' || $cameraName === '' || $track <= 0 || $eventTime === '') {
                throw new RuntimeException('Evidence worker data is incomplete.');
            }

            if ($delaySeconds > 0) {
                sleep($delaySeconds);
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
