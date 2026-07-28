<?php
// Version 1.6.2 (adds Notify Surveillance Center linkage when enabling Smart Events)
class ProcessCameraEvents extends IPSModule
{

    public function Create()
    {
        parent::Create();

        // Register properties
        $this->RegisterPropertyString('WebhookName', 'HIKVISION_EVENTS');
        $this->RegisterPropertyString('ChannelId', '101');
        $this->RegisterPropertyString('SavePath', '/user/');
        $this->RegisterPropertyString('Subnet', '192.168.50.');
        $this->RegisterPropertyString('UserName', 'NotSet');
        $this->RegisterPropertyString('Password', 'NotSet');
        $this->RegisterPropertyInteger('MotionActive', '30');
        $this->RegisterPropertyBoolean('debug', false);
        // Configurable cURL timeout
        $this->RegisterPropertyInteger('CurlTimeout', 10); // Default to 10 seconds
        // Configurable number of retries for snapshots
        $this->RegisterPropertyInteger('SnapshotRetryCount', 3); // Default to 3 retries
        // Optional handling of Hikvision generic duration events
        $this->RegisterPropertyBoolean('ProcessDurationEvents', true);
        // Smart Event processing: bounded parallel external workers and per-camera pacing
        $this->RegisterPropertyInteger('MaxParallelCameras', 16);
        $this->RegisterPropertyInteger('SmartCommandDelayMs', 500);
        $this->RegisterPropertyInteger('SmartCommandRetryCount', 2);
        $this->RegisterAttributeInteger('counter', '0');
        $this->RegisterAttributeString('EggTimerModuleId', '{17843F0A-BFC8-A4BA-E219-A2D10FC8E5BE}');

        // Ensure the webhook is registered
        $this->RegisterHook($this->ReadPropertyString('WebhookName'));

        // Register a boolean status variable
        $this->RegisterVariableBoolean("Activate_all_Cameras", "Activate_all_Cameras", "~Switch", 0);
        $this->SetValue("Activate_all_Cameras", true);
        $this->EnableAction("Activate_all_Cameras");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Ensure the webhook is registered
        $this->RegisterHook($this->ReadPropertyString('WebhookName'));
    }

    private function RegisterHook($WebHook)
    {
        $debug = $this->ReadPropertyBoolean('debug');
        if ($debug) $this->LogMessage("Register Hook Called", KL_DEBUG);
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        $find_Hook = '/hook/' . $WebHook;
        if (count($ids) > 0) {
            if ($debug) $this->LogMessage("Webhooks vorhanden", KL_DEBUG);
            $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
            $hook_connected_to_script = false;
            $correct_hook_installed = false; // Unused, but kept as per original structure
            $correct_hook_with_wrong_name_installed = false;
            foreach ($hooks as $index => $hook) {
                if ($hook['TargetID'] == $this->InstanceID) {
                    if ($debug) $this->LogMessage("Webhook bereits mit Instanz verbunden", KL_DEBUG);
                    $hook_connected_to_script = true;
                    if ($hook['Hook'] == $find_Hook) {
                        $correct_hook_installed = true;
                        $hooks[$index]['TargetID'] = $this->InstanceID;
                        if ($debug) $this->LogMessage("Webhook bereits mit der Instanz verbunden und hat den korrekten Namen", KL_DEBUG);
                        break;
                    } else {
                        $correct_hook_with_wrong_name_installed = true;
                        $hooks[$index]['TargetID'] = $this->InstanceID;
                        if ($debug) $this->LogMessage("Webhook bereits mit Instanz verbunden aber der neue Name muss eingetragen werden", KL_DEBUG);
                        break;
                    }
                }
            }
            if ($correct_hook_with_wrong_name_installed) {
                if ($debug) $this->LogMessage("Webhook Name wird jetzt korrigiert", KL_DEBUG);
                // The hook might already be correctly associated; this overwrites if it has the wrong 'Hook' name
                $hooks[$index] = ['Hook' => $WebHook, 'TargetID' => $this->InstanceID];
                IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
                IPS_ApplyChanges($ids[0]);
            }
            if (!$hook_connected_to_script) {
                if ($debug) $this->LogMessage("Neuer Webhook wird jetzt für die Instanz installiert und verbunden", KL_DEBUG);
                $hooks[] = ['Hook' => $WebHook, 'TargetID' => $this->InstanceID];
                IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
                IPS_ApplyChanges($ids[0]);
            }
        } else {
            if ($debug) $this->LogMessage("Keine Webhooks vorhanden", KL_DEBUG);
        }
    }

    public function ProcessHookData()
    {
        $counter = $this->ReadAttributeInteger('counter');
        $counter = $counter + 1;
        $this->WriteAttributeInteger('counter', $counter);
        $debug = $this->ReadPropertyBoolean('debug');
        if ($debug) $this->LogMessage("=======================Start of Script Webhook Processing============================" . $counter, KL_DEBUG);

        $eggTimerModuleId = $this->ReadAttributeString('EggTimerModuleId');
        if (!IPS_GetModule($eggTimerModuleId)) {
            if ($debug) $this->LogMessage("Bitte erst das Egg Timer Modul aus dem Modul Store installieren", KL_ERROR);
            return;
        }

        $webhookData = file_get_contents("php://input", true);
        if ($webhookData !== "") {
            if ($debug) $this->LogMessage("Webhook has delivered File Data", KL_DEBUG);
            $motionData = $this->parseEventNotificationAlert($webhookData);
            if (is_array($motionData)) {
                if ($debug) $this->LogMessage("File Data" . $counter . " XML Parser hat ein Array zurückgegeben. Weitere Verarbeitung möglich", KL_DEBUG);
                if ($debug) $this->LogMessage("File Data" . $counter . " Hier ist das Array " . implode(" ", $motionData), KL_DEBUG);
                if (($motionData['eventType'] ?? '') === 'duration' && !$this->ReadPropertyBoolean('ProcessDurationEvents')) {
                    if ($debug) $this->LogMessage("File Data" . $counter . " Duration event ignored by configuration", KL_DEBUG);
                } else {
                    $this->handleMotionData($motionData, "File Data" . $counter);
                }
            } else {
                if ($debug) $this->LogMessage("File Data" . $counter . " XML Parser hat kein Array zurückgeliefert, daher keine weitere Verarbeitung möglich ", KL_DEBUG);
            }
        } elseif (is_array($_POST)) {
            if ($debug) $this->LogMessage("Post Data" . $counter . " Webhook has delivered Post Data", KL_DEBUG);
            if ($debug) $this->LogMessage("Post Data" . $counter . " Array " . implode(" ", $_POST), KL_DEBUG);
            if (implode(" ", $_POST) == "") {
                if ($debug) $this->LogMessage("Post Data" . $counter . " Array Empty", KL_DEBUG);
            } else {
                foreach ($_POST as $value => $content) {
                    if ($debug) $this->LogMessage("Post Data" . $counter . " Value : " . $value, KL_DEBUG);
                    if ($debug) $this->LogMessage("Post Data" . $counter . " Content : " . $content, KL_DEBUG);
                    $motionData = $this->parseEventNotificationAlert($content);
                    // The original code called handleMotionData twice, consolidating to once
                    // if(array_key_exists('channelName',$motionData)){ if($motionData['channelName'] != ""){ $this->handleMotionData($motionData, "Post Data". $counter);}}
                    if (!is_array($motionData)) {
                        if ($debug) $this->LogMessage("Post Data" . $counter . " XML Parser hat kein Array zurückgeliefert, daher keine weitere Verarbeitung möglich", KL_DEBUG);
                    } elseif (($motionData['eventType'] ?? '') === 'duration' && !$this->ReadPropertyBoolean('ProcessDurationEvents')) {
                        if ($debug) $this->LogMessage("Post Data" . $counter . " Duration event ignored by configuration", KL_DEBUG);
                    } else {
                        $this->handleMotionData($motionData, "Post Data" . $counter);
                    }
                }
            }
        } else {
            if ($debug) $this->LogMessage("Error Not expected Webhook Data", KL_ERROR);
        }
        if ($debug) $this->LogMessage("=======================END of Script Webhook Processing============================" . $counter, KL_DEBUG);
    }

    private function handleMotionData($motionData, $source)
    {
        $debug = $this->ReadPropertyBoolean('debug');
        if ($debug) $this->LogMessage($source . "--------------------------------Start of Script Motion Data -------------------" . $motionData['channelName'], KL_DEBUG);
        $notSetYet = 'NotSet';
        $parent = $this->InstanceID;
        $channelId = $this->ReadPropertyString('ChannelId');
        $initialSavePath = $this->ReadPropertyString('SavePath'); // Use a different var name to avoid confusion with $savePath inside loop
        $username = $this->ReadPropertyString('UserName');
        $password = $this->ReadPropertyString('Password');
        $kamera_name = $motionData['channelName'];
        $semaphore_process_name = $kamera_name . "10";

        if (IPS_SemaphoreEnter($semaphore_process_name, 5000)) {
            if ($debug) $this->LogMessage("Semaphore process wurde betreten  " . $semaphore_process_name, KL_DEBUG);

            $kameraId = $this->manageVariable($parent, $kamera_name, 0, 'Motion', true, 0, "");
            $event_descriptionvar_id = $this->manageVariable($kameraId, $motionData['eventDescription'], 3, '~TextBox', true, 0, "");

            $username = GetValueString($this->manageVariable($kameraId, "User Name", 3, '~TextBox', true, 0, $username));
            $password = GetValueString($this->manageVariable($kameraId, "Password", 3, '~TextBox', true, 0, $password));

            if ($username != $notSetYet && $password != $notSetYet) {
                // Ensure the path is correct within IPS kernel directory structure
                $fullSavePath = IPS_GetKernelDir() . DIRECTORY_SEPARATOR . trim($initialSavePath, '/') . DIRECTORY_SEPARATOR . $motionData['eventDescription'] . $motionData['ipAddress'] . ".jpg";
                // Make sure the directory exists
                $directory = dirname($fullSavePath);
                if (!is_dir($directory)) {
                    if (!mkdir($directory, 0777, true)) {
                        if ($debug) $this->LogMessage("Failed to create directory: " . $directory . " for snapshot", KL_ERROR);
                        // Continue without saving snapshot if directory creation fails
                    }
                }

                if ($this->downloadHikvisionSnapshot($motionData['ipAddress'], $channelId, $username, $password, $fullSavePath)) {
                    $this->manageMedia($event_descriptionvar_id, $motionData['eventDescription'] . "Last_Picture", $fullSavePath);
                } else {
                    if ($debug) $this->LogMessage("Failed to download snapshot for IP: " . $motionData['ipAddress'], KL_WARNING);
                }
            } else {
                if ($debug) $this->LogMessage("Please set UserName and Password in Variable for camera: " . $kamera_name, KL_WARNING);
            }

            $dateTime_id = $this->manageVariable($event_descriptionvar_id, "Date and Time", 3, '~TextBox', true, 0, "");
            SetValueString($dateTime_id, $motionData['dateTime']);
            SetValueBoolean($kameraId, true);
            $kamera_IP_var_id = $this->manageVariable($kameraId, "IP-" . $motionData['ipAddress'], 3, '~TextBox', true, 0, "");
            SetValueString($kamera_IP_var_id, $motionData['ipAddress']);

            $this->handle_egg_timer($source, $kamera_name, $kameraId);

            if ($debug) $this->LogMessage("Leave process Semaphore  " . $semaphore_process_name, KL_DEBUG);
            IPS_SemaphoreLeave($semaphore_process_name);
        } else {
            if ($debug) $this->LogMessage("Process Semaphore Active. No execution for this Data " . $semaphore_process_name, KL_DEBUG);
        }
        if ($debug) $this->LogMessage($source . "--------------------------------End of Script Motion Data -------------------" . $kamera_name, KL_DEBUG);
    }

    private function parseEventNotificationAlert($xmlString)
    {
        $debug = $this->ReadPropertyBoolean('debug');
        $xml = @simplexml_load_string($xmlString, "SimpleXMLElement", LIBXML_NOCDATA);
        if ($xml === false) {
            if ($debug) $this->LogMessage("XML parsing failed. Input: " . substr($xmlString, 0, 500) . "...", KL_ERROR);
            return false;
        }

        $json = json_encode($xml);
        $array = json_decode($json, true);
        return $array;
    }

    private function handle_egg_timer($source, $kamera_name, $kameraId)
    {
        $motion_active = $this->ReadPropertyInteger('MotionActive');
        $debug = $this->ReadPropertyBoolean('debug');
        $active = $this->Translate('Active');
        $time_in_seconds = $this->Translate('Time in Seconds');
        $semaphore_egg_timer_name = $kamera_name . "EggTimer1";
        if ($debug) $this->LogMessage("Lokalisierte Variablen Namen des Egg Timers. Status : " . $active . "  Zeitdauer : " . $time_in_seconds, KL_DEBUG);

        if (IPS_SemaphoreEnter($semaphore_egg_timer_name, 1000)) {
            if ($debug) $this->LogMessage("Habe Semaphore gesetzt um zu verhindern das mehrere Egg Timer installiert werden   " . $semaphore_egg_timer_name, KL_DEBUG);
            $eggTimerId = @IPS_GetObjectIDByName("Egg Timer", $kameraId);
            if ($eggTimerId) {
                if ($debug) $this->LogMessage("Der Egg Timer existiert bereits und wird aktiviert  " . $kameraId, KL_DEBUG);
                $activ_id = @IPS_GetObjectIDByName($active,  $eggTimerId);
                SetValueInteger(IPS_GetObjectIDByName($time_in_seconds, $eggTimerId), $motion_active);
                RequestAction(IPS_GetObjectIDByName($active, $eggTimerId), true);
            } else {
                if ($debug) $this->LogMessage("Egg Timer existiert NICHT und wird installiert  " . $kameraId, KL_DEBUG);
                $insId = IPS_CreateInstance($this->ReadAttributeString('EggTimerModuleId'));
                IPS_SetName($insId, "Egg Timer");
                IPS_SetParent($insId, $kameraId);
                IPS_ApplyChanges($insId);
                RequestAction(IPS_GetObjectIDByName($active, $insId), true);
                SetValueInteger(IPS_GetObjectIDByName($time_in_seconds, $insId), $motion_active);
                $eid = IPS_CreateEvent(0);
                IPS_SetEventTrigger($eid, 4, IPS_GetObjectIDByName($active, $insId));
                IPS_SetParent($eid, $kameraId);
                IPS_SetEventAction($eid, "{75C67945-BE11-5965-C569-602D43F84269}", ["VALUE" => false]);
                IPS_SetEventActive($eid, true);
                IPS_SetEventTriggerValue($eid, false);
                if ($debug) $this->LogMessage("Event wurde installiert Event ID " . $eid . " Egg Timer ID " . $insId, KL_DEBUG);
            }
            IPS_SemaphoreLeave($semaphore_egg_timer_name);
        } else {
            if ($debug) $this->LogMessage("Es wird bereits ein Egg Timer installiert Semaphore war gesetzt " . $semaphore_egg_timer_name, KL_DEBUG);
        }
    }

    private function manageVariable($parent, $name, $type, $profile, $logging, $aggregationType, $initialValue)
    {
        $archiveId = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
        $varId = @IPS_GetVariableIDByName($name, $parent);

        if ($varId === false) {
            $varId = IPS_CreateVariable($type);
            if ($profile != "") IPS_SetVariableCustomProfile($varId, $profile);
            IPS_SetName($varId, $name);
            IPS_SetParent($varId, $parent);

            AC_SetLoggingStatus($archiveId, $varId, $logging);
            if ($logging || $type != 3) {
                AC_SetAggregationType($archiveId, $varId, $aggregationType);
            }
            IPS_ApplyChanges($archiveId);
            if ($initialValue != "") {
                SetValueString($varId, $initialValue);
            }
        }

        return $varId;
    }

    private function manageMedia($parent, $name, $imageFile)
    {
        $mediaId = @IPS_GetMediaIDByName($name, $parent);
        if ($mediaId === false) {
            $mediaId = IPS_CreateMedia(1);
            IPS_SetName($mediaId, $name);
            IPS_SetParent($mediaId, $parent);
        }
        IPS_SetMediaFile($mediaId, $imageFile, true);

        return $mediaId;
    }

    private function downloadHikvisionSnapshot($cameraIp, $channelId, $username, $password, $fullSavePath)
    {
        $debug = $this->ReadPropertyBoolean('debug');
        $snapshotUrl = "http://$cameraIp/ISAPI/Streaming/channels/$channelId/picture";
        $retryCount = $this->ReadPropertyInteger('SnapshotRetryCount'); // Read the configurable retry count
        $timeout = $this->ReadPropertyInteger('CurlTimeout'); // Read the configurable timeout

        for ($i = 0; $i < $retryCount; $i++) {
            $ch = curl_init($snapshotUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout); // Timeout for connection phase
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout + 5);     // Total timeout for the operation (connect + transfer)

            $imageData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch); // Get cURL error message
            $curlErrno = curl_errno($ch); // Get cURL error number
            curl_close($ch);

            if ($httpCode == 200 && $imageData !== false) {
                $fileHandle = @fopen($fullSavePath, 'w'); // Use @ to suppress PHP warnings if file cannot be opened
                if ($fileHandle) { // Check if fopen was successful
                    fwrite($fileHandle, $imageData);
                    fclose($fileHandle);
                    if ($debug) $this->LogMessage("Snapshot successfully downloaded for IP: $cameraIp to $fullSavePath", KL_DEBUG);
                    return true;
                } else {
                    $this->LogMessage("Failed to open/write snapshot file: $fullSavePath (IP: $cameraIp)", KL_ERROR);
                    return false; // File writing error, no need to retry
                }
            } else { // Handle cURL or HTTP errors
                $this->LogMessage("Snapshot download failed for IP: $cameraIp (Attempt " . ($i + 1) . "/$retryCount). HTTP Code: $httpCode. cURL Error ($curlErrno): $curlError", KL_WARNING);
                // RETRY CONDITION MODIFIED HERE: Now retries on timeout (28) OR HTTP 503
                if (($curlErrno === 28 /* CURLE_OPERATION_TIMEDOUT */ || $httpCode === 503) && $i < $retryCount - 1) {
                    // It's a timeout or service unavailable error, try again after a brief pause
                    if ($debug) $this->LogMessage("Retrying snapshot download for IP: $cameraIp after timeout or 503 response.", KL_DEBUG);
                    sleep(1);
                    continue;
                }
                break; // For other errors or last retry, stop trying
            }
        }
        // If all retries fail, return false
        return false;
    }


    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case "Activate_all_Cameras":
                // The variable represents the requested target state.
                $this->SetValue($Ident, (bool) $Value);
                $this->ExecuteMotionDetectionAPI((bool) $Value);
                break;

            default:
                throw new Exception("Invalid Ident: $Ident");
        }
    }

    private function ExecuteMotionDetectionAPI(bool $status): void
    {
        // A new run ID immediately invalidates workers from an older operation.
        try {
            $runId = time() . '-' . bin2hex(random_bytes(6));
        } catch (Throwable $e) {
            $runId = uniqid((string) time(), true);
        }

        $tokenDirectory = IPS_GetKernelDir() . 'user' . DIRECTORY_SEPARATOR;
        if (!is_dir($tokenDirectory) && !@mkdir($tokenDirectory, 0777, true) && !is_dir($tokenDirectory)) {
            $this->LogMessage('Unable to create the Smart Event worker token directory.', KL_ERROR);
            return;
        }

        $tokenFile = $tokenDirectory . 'hikvision_smart_run_' . $this->InstanceID . '.token';
        if (@file_put_contents($tokenFile, $runId, LOCK_EX) === false) {
            $this->LogMessage('Unable to create the Smart Event worker cancellation token.', KL_ERROR);
            return;
        }

        $rootID        = $this->InstanceID;
        $objectType    = 2; // Variable
        $objectName    = "IP-" . $this->ReadPropertyString('Subnet');
        $matchType     = 'partial';
        $caseSensitive = true;

        $filteredObjects = $this->GetAllObjectIDsByTypeAndName(
            $rootID,
            $objectType,
            $objectName,
            $matchType,
            $caseSensitive
        );

        $cameras = [];
        foreach ($filteredObjects as $ipVarId) {
            $ip = trim((string) GetValueString($ipVarId));
            $parent = IPS_GetParent($ipVarId);

            $userId = @IPS_GetObjectIDByName("User Name", $parent);
            $passwordId = @IPS_GetObjectIDByName("Password", $parent);
            $username = $userId !== false ? (string) GetValueString($userId) : '';
            $password = $passwordId !== false ? (string) GetValueString($passwordId) : '';

            if ($ip === '' || $username === '' || $password === '' || $username === 'NotSet' || $password === 'NotSet') {
                $this->LogMessage(
                    "Skipping camera because IP/username/password is not set properly (IP: $ip).",
                    KL_WARNING
                );
                continue;
            }

            // Prevent duplicate workers if duplicate IP variables exist below the instance.
            if (array_key_exists($ip, $cameras)) {
                $this->LogMessage("Duplicate camera IP ignored: $ip", KL_WARNING);
                continue;
            }

            $cameras[$ip] = [
                'ip'       => $ip,
                'name'     => IPS_GetName($parent),
                'username' => $username,
                'password' => $password
            ];
        }

        $cameras = array_values($cameras);
        if (count($cameras) === 0) {
            @file_put_contents($tokenFile, '', LOCK_EX);
            $this->LogMessage('No valid cameras found for Smart Event update.', KL_WARNING);
            return;
        }

        $configuredParallel = max(1, min(16, $this->ReadPropertyInteger('MaxParallelCameras')));
        $workerCount = min($configuredParallel, count($cameras));
        $workers = array_fill(0, $workerCount, []);

        // Round-robin assignment keeps the number of simultaneously running workers bounded.
        foreach ($cameras as $index => $camera) {
            $workers[$index % $workerCount][] = $camera;
        }

        $instanceInfo = IPS_GetInstance($this->InstanceID);
        $moduleId = $instanceInfo['ModuleInfo']['ModuleID'] ?? '';
        $moduleInfo = $moduleId !== '' ? IPS_GetModule($moduleId) : [];
        $prefix = (string) ($moduleInfo['Prefix'] ?? '');

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $prefix)) {
            @file_put_contents($tokenFile, '', LOCK_EX);
            $this->LogMessage('Unable to determine a valid module prefix for Smart Event workers.', KL_ERROR);
            return;
        }

        $workerFile = __DIR__ . DIRECTORY_SEPARATOR . 'HikvisionSmartAlarmWorker.php';
        if (!is_file($workerFile)) {
            @file_put_contents($tokenFile, '', LOCK_EX);
            $this->LogMessage('HikvisionSmartAlarmWorker.php is missing from the module directory.', KL_ERROR);
            return;
        }

        $runState = [
            'runId'            => $runId,
            'targetEnabled'    => $status,
            'workerCount'      => $workerCount,
            'completedWorkers' => 0,
            'cameraCount'      => count($cameras),
            'startedAt'        => time(),
            'tokenFile'        => $tokenFile,
            'results'          => []
        ];
        $this->SetBuffer('SmartAlarmRunState', json_encode($runState));

        if ($this->ReadPropertyBoolean('debug')) {
            $this->LogMessage(
                sprintf(
                    'Starting isolated Smart Event run %s for %d cameras with %d parallel workers.',
                    $runId,
                    count($cameras),
                    $workerCount
                ),
                KL_DEBUG
            );
        }

        // The long-running cURL/sleep work happens in a standalone worker class and does not
        // execute as a method of this webhook-receiving module instance.
        $workerScript = 'require_once (string) $_IPS["WorkerFile"]; HikvisionSmartAlarmWorker::Run((string) $_IPS["WorkerData"]);';
        $callback = $prefix . '_CompleteSmartAlarmWorker';

        foreach ($workers as $workerIndex => $workerCameras) {
            $workerData = [
                'instanceId'    => $this->InstanceID,
                'runId'         => $runId,
                'workerNumber'  => $workerIndex + 1,
                'enabled'       => $status,
                'cameras'       => $workerCameras,
                'tokenFile'     => $tokenFile,
                'callback'      => $callback,
                'delayMs'       => max(0, min(5000, $this->ReadPropertyInteger('SmartCommandDelayMs'))),
                'retryCount'    => max(0, min(5, $this->ReadPropertyInteger('SmartCommandRetryCount'))),
                'curlTimeout'   => max(1, min(60, $this->ReadPropertyInteger('CurlTimeout'))),
                'debug'         => $this->ReadPropertyBoolean('debug')
            ];

            $started = IPS_RunScriptTextEx(
                $workerScript,
                [
                    'WorkerFile' => $workerFile,
                    'WorkerData' => json_encode($workerData)
                ]
            );

            if (!$started) {
                $this->CompleteSmartAlarmWorker(
                    $runId,
                    $workerIndex + 1,
                    json_encode([[
                        'ip'     => '',
                        'name'   => 'Worker ' . ($workerIndex + 1),
                        'status' => 'failed',
                        'paths'  => [[
                            'path'     => 'Worker startup',
                            'status'   => 'failed',
                            'attempts' => 0,
                            'message'  => 'IP-Symcon did not start the worker thread.'
                        ]]
                    ]])
                );
            }
        }
    }

    /**
     * Brief completion callback from an isolated Smart Event worker.
     * No camera communication or waiting is performed in this module method.
     */
    public function CompleteSmartAlarmWorker(string $runId, int $workerNumber, string $workerResultsJson): void
    {
        $workerResults = json_decode($workerResultsJson, true);
        if (!is_array($workerResults)) {
            $workerResults = [[
                'ip'     => '',
                'name'   => 'Worker ' . $workerNumber,
                'status' => 'failed',
                'paths'  => [[
                    'path'     => 'Worker result',
                    'status'   => 'failed',
                    'attempts' => 0,
                    'message'  => 'Worker returned invalid result JSON.'
                ]]
            ]];
        }

        $semaphoreName = 'HikvisionSmartResults_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphoreName, 10000)) {
            $this->LogMessage(
                "Unable to record completion of Smart Event worker $workerNumber.",
                KL_ERROR
            );
            return;
        }

        try {
            $state = json_decode($this->GetBuffer('SmartAlarmRunState'), true);
            if (!is_array($state) || ($state['runId'] ?? '') !== $runId) {
                return;
            }

            foreach ($workerResults as $result) {
                $state['results'][] = $result;
            }
            $state['completedWorkers'] = (int) ($state['completedWorkers'] ?? 0) + 1;
            $this->SetBuffer('SmartAlarmRunState', json_encode($state));

            if ($state['completedWorkers'] < $state['workerCount']) {
                return;
            }

            $failures = [];
            $successfulCameras = 0;
            foreach ($state['results'] as $cameraResult) {
                if (($cameraResult['status'] ?? '') === 'success') {
                    $successfulCameras++;
                }

                foreach (($cameraResult['paths'] ?? []) as $pathResult) {
                    if (($pathResult['status'] ?? '') !== 'failed') {
                        continue;
                    }

                    $cameraLabel = trim((string) ($cameraResult['name'] ?? ''));
                    $cameraIp = trim((string) ($cameraResult['ip'] ?? ''));
                    if ($cameraIp !== '') {
                        $cameraLabel .= ($cameraLabel !== '' ? ' ' : '') . '(' . $cameraIp . ')';
                    }
                    if ($cameraLabel === '') {
                        $cameraLabel = 'Unknown camera';
                    }

                    $failures[] = sprintf(
                        '%s / %s: %s',
                        $cameraLabel,
                        (string) ($pathResult['path'] ?? 'Unknown path'),
                        (string) ($pathResult['message'] ?? 'Unknown error')
                    );
                }
            }

            $tokenFile = (string) ($state['tokenFile'] ?? '');
            if ($tokenFile !== '' && is_file($tokenFile)) {
                $currentToken = trim((string) @file_get_contents($tokenFile));
                if ($currentToken === $runId) {
                    @file_put_contents($tokenFile, '', LOCK_EX);
                }
            }

            if (count($failures) === 0) {
                if ($this->ReadPropertyBoolean('debug')) {
                    $this->LogMessage(
                        sprintf(
                            'Smart Event run completed successfully for %d cameras.',
                            (int) $state['cameraCount']
                        ),
                        KL_DEBUG
                    );
                }
            } else {
                $shownFailures = array_slice($failures, 0, 20);
                $suffix = count($failures) > 20
                    ? sprintf(' (+%d additional failures)', count($failures) - 20)
                    : '';
                $this->LogMessage(
                    sprintf(
                        'Smart Event run incomplete: %d of %d cameras completed without errors. %s%s',
                        $successfulCameras,
                        (int) $state['cameraCount'],
                        implode(' | ', $shownFailures),
                        $suffix
                    ),
                    KL_WARNING
                );
            }
        } finally {
            IPS_SemaphoreLeave($semaphoreName);
        }
    }

    public function GetAllObjectIDsByTypeAndName(
        int $rootID,
        int $objectType,
        string $objectName,
        string $matchType = 'exact', // 'exact' or 'partial'
        bool $caseSensitive = true
    ): array {
        if (!IPS_ObjectExists($rootID)) {
            // Root object does not exist
            return [];
        }

        // Validate matchType
        if ($matchType !== 'exact' && $matchType !== 'partial') {
            throw new InvalidArgumentException("Invalid matchType. Use 'exact' or 'partial'.");
        }

        $objectIDs = [];
        $this->GetAllObjectIDsByTypeAndNameRecursive(
            $rootID,
            $objectType,
            $objectName,
            $matchType,
            $caseSensitive,
            $objectIDs
        );

        return $objectIDs;
    }

    private function GetAllObjectIDsByTypeAndNameRecursive(
        int $objectID,
        int $objectType,
        string $objectName,
        string $matchType,
        bool $caseSensitive,
        array &$objectIDs
    ) {
        // Retrieve the object information
        $object = IPS_GetObject($objectID);

        // Check if the object type matches
        if ($object['ObjectType'] === $objectType) {
            $nameMatches = false;
            $objectNameCurrent = $object['ObjectName'];
            $searchName = $objectName;

            // Apply case sensitivity
            if (!$caseSensitive) {
                $objectNameCurrent = mb_strtolower($objectNameCurrent);
                $searchName = mb_strtolower($searchName);
            }

            // Check name matching
            if ($matchType === 'exact') {
                if ($objectNameCurrent === $searchName) {
                    $nameMatches = true;
                }
            } elseif ($matchType === 'partial') {
                if (mb_strpos($objectNameCurrent, $searchName) !== false) {
                    $nameMatches = true;
                }
            }

            if ($nameMatches) {
                // Add the current object ID to the list
                $objectIDs[] = $objectID;
            }
        }

        // Get all child IDs of the current object
        $childrenIDs = IPS_GetChildrenIDs($objectID);
        foreach ($childrenIDs as $childID) {
            // Recursively traverse each child
            $this->GetAllObjectIDsByTypeAndNameRecursive(
                $childID,
                $objectType,
                $objectName,
                $matchType,
                $caseSensitive,
                $objectIDs
            );
        }
    }

    public function Destroy()
    {
        parent::Destroy();
        // Add your custom code here

        if (!IPS_InstanceExists($this->InstanceID)) {
            //Destroy existing HIKVISION Webhook Called
            $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
            if (count($ids) > 0) {
                //Webhooks vorhanden
                $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
                $correct_hook_found = false;
                foreach ($hooks as $index => $hook) {
                    if ($hook['TargetID'] == $this->InstanceID) {
                        $correct_hook_found = true;
                        break;
                    }
                }
                if ($correct_hook_found) {
                    //Webhook wird jetzt gelöscht

                    // Remove the specific webhook from the hooks array
                    unset($hooks[$index]);

                    // Re-index the array to prevent gaps in the keys
                    $hooks = array_values($hooks);

                    // Update the hooks property with the modified array
                    IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
                    IPS_ApplyChanges($ids[0]);
                } else {
                    //Webhook not found
                }
            } else {
                //Keine Webhooks vorhanden
            }
            // Call the parent destroy to ensure the instance is properly destroyed
        } else {
            //Instanz wurde nicht gelöscht daher bleibt der Webhook bestehen
        }
    }
}
