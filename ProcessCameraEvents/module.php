<?php
// Version 1.3
class ProcessCameraEvents extends IPSModule {
    
    public function Create() {
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
        $this->RegisterAttributeInteger('counter', '0');
        $this->RegisterAttributeString('EggTimerModuleId', '{17843F0A-BFC8-A4BA-E219-A2D10FC8E5BE}');
        
        // Ensure the webhook is registered
        $this->RegisterHook($this->ReadPropertyString('WebhookName'));

        // Register a boolean status variable
        $this->RegisterVariableBoolean("Status", "Status", "~Switch", 0);
        $this->SetValue("Status",true);
        $this->EnableAction("Status");

    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        // Ensure the webhook is registered
        $this->RegisterHook($this->ReadPropertyString('WebhookName'));
    }

    private function RegisterHook($WebHook)
    {
        $debug = $this->ReadPropertyBoolean('debug');
        if($debug) $this->LogMessage("Register Hook Called", KL_DEBUG);
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        $find_Hook = '/hook/'.$WebHook;
        if (count($ids) > 0) {
            if($debug) $this->LogMessage("Webhooks vorhanden", KL_DEBUG);
            $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
            $hook_connected_to_script = false;
            $correct_hook_installed = false;
            $correct_hook_with_wrong_name_installed = false;
            foreach ($hooks as $index => $hook) {
                if ($hook['TargetID'] == $this->InstanceID) {
                    if($debug) $this->LogMessage("Webhook bereits mit Instanz verbunden", KL_DEBUG);
                    $hook_connected_to_script = true;
                    if  ($hook['Hook'] == $find_Hook) {
                        $correct_hook_installed = true;
                        $hooks[$index]['TargetID'] = $this->InstanceID;
                        if($debug) $this->LogMessage("Webhook bereits mit der Instanz verbunden und hat den korrekten Namen", KL_DEBUG);
                        break;
                    }
                    else{
                        $correct_hook_with_wrong_name_installed = true; 
                        $hooks[$index]['TargetID'] = $this->InstanceID;
                        if($debug) $this->LogMessage("Webhook bereits mit Instanz verbunden aber der neue Name muss eingetragen werden", KL_DEBUG);
                        break;                 
                    }
                }
            }
            if ($correct_hook_with_wrong_name_installed) {
                    if($debug) $this->LogMessage("Webhook Name wird jetzt korrigiert", KL_DEBUG);
                    $hooks[$index] = ['Hook' => $WebHook, 'TargetID' => $this->InstanceID];
                    IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
                    IPS_ApplyChanges($ids[0]);
            }  
            if(!$hook_connected_to_script ){
                if($debug) $this->LogMessage("Neuer Webhook wird jetzt für die Instanz installiert und verbunden", KL_DEBUG);
                $hooks[] = ['Hook' => $WebHook, 'TargetID' => $this->InstanceID];
                IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
                IPS_ApplyChanges($ids[0]);
            }
        }
        else{
            if($debug) $this->LogMessage("Keine Webhooks vorhanden", KL_DEBUG);
        }
    }

    public function ProcessHookData() {
        $counter = $this->ReadAttributeInteger('counter');
        $counter = $counter + 1;
        $this->WriteAttributeInteger('counter',$counter);
        $debug = $this->ReadPropertyBoolean('debug');
        if($debug) $this->LogMessage("=======================Start of Script Webhook Processing============================".$counter, KL_DEBUG); 
               
        $eggTimerModuleId = $this->ReadAttributeString('EggTimerModuleId');
        if (!IPS_GetModule($eggTimerModuleId)) {
            if($debug) $this->LogMessage("Bitte erst das Egg Timer Modul aus dem Modul Store installieren", KL_ERROR);
            return;
        }
        
        $webhookData = file_get_contents("php://input", true);
        if ($webhookData !== "") {
            if($debug) $this->LogMessage("Webhook has delivered File Data", KL_DEBUG);
            $motionData = $this->parseEventNotificationAlert($webhookData);
            if (is_array($motionData)) {
                if($debug) $this->LogMessage("File Data".$counter." XML Parser hat ein Array zurückgegeben. Weitere Verarbeitung möglich", KL_DEBUG);
                if($debug) $this->LogMessage("File Data".$counter." Hier ist das Array ".implode(" ",$motionData), KL_DEBUG);
                $this->handleMotionData($motionData,"File Data". $counter);
            }
            else{
                if($debug) $this->LogMessage("File Data".$counter." XML Parser hat kein Array zurückgeliefert, daher keine weitere Verarbeitung möglich ".implode(" ",$motionData), KL_DEBUG);
            }
        } elseif (is_array($_POST)) {
            if($debug) $this->LogMessage("Post Data".$counter." Webhook has delivered Post Data", KL_DEBUG);
            if($debug) $this->LogMessage("Post Data".$counter." Array ".implode(" ",$_POST), KL_DEBUG);
            if(implode(" ",$_POST) == "")
            {
                if($debug) $this->LogMessage("Post Data".$counter." Array Empty", KL_DEBUG);
            }
            else{
                foreach ($_POST as $value => $content) {
                        if($debug) $this->LogMessage("Post Data".$counter." Value : ".$value, KL_DEBUG);
                        if($debug) $this->LogMessage("Post Data".$counter." Content : ".$content, KL_DEBUG);
                        $motionData = $this->parseEventNotificationAlert($content);
                        $this->handleMotionData($motionData, "Post Data". $counter);
                        
                        if(array_key_exists('channelName',$motionData)){ 
                            if($motionData['channelName'] != "")
                            { 
                                $this->handleMotionData($motionData, "Post Data". $counter);
                            }
                            else{
                                if($debug) $this->LogMessage("Post Data".$counter." Array Key Channel Name is empty", KL_DEBUG);
                            }
                        }
                        else{
                            if($debug) $this->LogMessage("Post Data".$counter." No Array Key Channel Name", KL_DEBUG);
                        }
                        
                    }
            }
        }
        else{
            if($debug) $this->LogMessage("Error Not expected Webhook Data", KL_ERROR);
        }
        if($debug) $this->LogMessage("=======================END of Script Webhook Processing============================".$counter, KL_DEBUG);         
    }

    private function handleMotionData($motionData,$source) {
        $debug = $this->ReadPropertyBoolean('debug');
        if($debug) $this->LogMessage($source."--------------------------------Start of Script Motion Data -------------------".$motionData['channelName'], KL_DEBUG);
        $notSetYet = 'NotSet';
        $parent = $this->InstanceID;
        $channelId = $this->ReadPropertyString('ChannelId');
        $savePath = $this->ReadPropertyString('SavePath');
        $username = $this->ReadPropertyString('UserName');
        $password= $this->ReadPropertyString('Password');
        $kamera_name = $motionData['channelName'];
        $semaphore_process_name = $kamera_name."10";

        if (IPS_SemaphoreEnter($semaphore_process_name ,5000)) 
        {
            if($debug) $this->LogMessage("Semaphore process wurde betreten  ".$semaphore_process_name, KL_DEBUG);

            $kameraId = $this->manageVariable($parent, $kamera_name , 0, 'Motion', true, 0, ""); 
            $event_descriptionvar_id = $this->manageVariable($kameraId, $motionData['eventDescription'], 3, '~TextBox', true, 0, "");
 
            $username = GetValueString($this->manageVariable($kameraId, "User Name", 3, '~TextBox', true, 0, $username));
            $password = GetValueString($this->manageVariable($kameraId, "Password", 3, '~TextBox', true, 0, $password ));

            if ($username != $notSetYet && $password != $notSetYet) {
                $savePath .= $motionData['eventDescription'].$motionData['ipAddress'] . ".jpg";
                $this->downloadHikvisionSnapshot($motionData['ipAddress'], $channelId, $username, $password, $savePath);
                $this->manageMedia($event_descriptionvar_id, $motionData['eventDescription']."Last_Picture", $savePath);
            } else {
                if($debug) $this->LogMessage("Please set UserName and Password in Variable", KL_WARNING);
            }

            $dateTime_id = $this->manageVariable($event_descriptionvar_id, "Date and Time", 3, '~TextBox', true, 0, "");
            SetValueString($dateTime_id, $motionData['dateTime']);
            SetValueBoolean($kameraId, true);
            $kamera_IP_var_id = $this->manageVariable($kameraId, "IP-".$motionData['ipAddress'], 3, '~TextBox', true, 0, "");      
            SetValueString($kamera_IP_var_id,$motionData['ipAddress']);

            $this->handle_egg_timer($source,$kamera_name,$kameraId);

            if($debug) $this->LogMessage("Leave process Semaphore  ".$semaphore_process_name, KL_DEBUG);
            IPS_SemaphoreLeave($semaphore_process_name);
        }
        else
        {
            if($debug) $this->LogMessage("Process Semaphore Active. No execution for this Data ".$semaphore_process_name, KL_DEBUG);
        }  
        if($debug) $this->LogMessage($source."--------------------------------End of Script Motion Data -------------------".$kamera_name, KL_DEBUG );
    }

    private function parseEventNotificationAlert($xmlString) {
        $xml = @simplexml_load_string($xmlString, "SimpleXMLElement", LIBXML_NOCDATA);
        if ($xml === false) {
            return false;
        }

        $json = json_encode($xml);
        $array = json_decode($json, true);
        return $array;
    }

    private function handle_egg_timer($source,$kamera_name,$kameraId){ 
        $motion_active = $this->ReadPropertyInteger('MotionActive');
        $debug = $this->ReadPropertyBoolean('debug');
        $active = $this->Translate('Active');
        $time_in_seconds = $this->Translate('Time in Seconds');
        $semaphore_egg_timer_name = $kamera_name."EggTimer1";
        if($debug) $this->LogMessage("Lokalisierte Variablen Namen des Egg Timers. Status : ".$active ."  Zeitdauer : ".$time_in_seconds, KL_DEBUG);

        if (IPS_SemaphoreEnter($semaphore_egg_timer_name,1000)) 
        {
            if($debug) $this->LogMessage("Habe Semaphore gesetzt um zu verhindern das mehrere Egg Timer installiert werden   ".$semaphore_egg_timer_name, KL_DEBUG );
            $eggTimerId = @IPS_GetObjectIDByName("Egg Timer", $kameraId);
            if ($eggTimerId) {
                if($debug) $this->LogMessage("Der Egg Timer existiert bereits und wird aktiviert  ".$kameraId, KL_DEBUG);
                $activ_id = @IPS_GetObjectIDByName($active,  $eggTimerId );
                SetValueInteger(IPS_GetObjectIDByName($time_in_seconds, $eggTimerId), $motion_active);
                RequestAction(IPS_GetObjectIDByName($active, $eggTimerId), true);
            } else {
                if($debug) $this->LogMessage("Egg Timer existiert NICHT und wird installiert  ".$kameraId, KL_DEBUG);
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
                if($debug) $this->LogMessage("Event wurde installiert Event ID ".$eid." Egg Timer ID ".$insId, KL_DEBUG);
            }
            IPS_SemaphoreLeave($semaphore_egg_timer_name );
        }
        else
        {
            if($debug) $this->LogMessage("Es wird bereits ein Egg Timer installiert Semaphore war gesetzt ".$semaphore_egg_timer_name, KL_DEBUG);
        }  
    }

    private function manageVariable($parent, $name, $type, $profile, $logging, $aggregationType, $initialValue) {
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

    private function manageMedia($parent, $name, $imageFile) {
        $mediaId = @IPS_GetMediaIDByName($name, $parent);
        if ($mediaId === false) {
            $mediaId = IPS_CreateMedia(1);
            IPS_SetName($mediaId, $name);
            IPS_SetParent($mediaId, $parent);
        }
        IPS_SetMediaFile($mediaId, $imageFile, true);
   
        
        return $mediaId;
    }

    private function downloadHikvisionSnapshot($cameraIp, $channelId, $username, $password, $relativePath) {
        $snapshotUrl = "http://$cameraIp/ISAPI/Streaming/channels/$channelId/picture";
        $retryCount = 3;
        
        for ($i = 0; $i < $retryCount; $i++) {
            $ch = curl_init($snapshotUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    
            $imageData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
    
            if ($httpCode == 200 && $imageData !== false) {
                $savePath = IPS_GetKernelDir() . DIRECTORY_SEPARATOR . $relativePath;
                $fileHandle = fopen($savePath, 'w');
                if ($fileHandle !== false) {
                    fwrite($fileHandle, $imageData);
                    fclose($fileHandle);
                    return true;
                } else {
                    return false;
                }
            }
        }
    
        // If all retries fail, return false
        return false;
    }


    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case "Status":
                // Update the value of the status variable
                $this->SetValue($Ident, $Value);

                // Execute your custom function when the status changes
                $this->ExecuteMotionDetectionAPI($Value);
                break;

            // Handle other variables or actions if necessary
            default:
                throw new Exception("Invalid Ident");
        }
    }


    private function ExecuteMotionDetectionAPI($status)
    {
        
        //$username = $this->ReadPropertyString('UserName');
        //$password = $this->ReadPropertyString('Password');
        $subnet   = "IP-".$this->ReadPropertyString('Subnet');
        // Convert search terms to an array in case multiple terms are provided
        //$searchTerms = explode(",", $this->ReadPropertyString("SearchTerms"));
        
        $searchTerms = array($subnet);
        //$location = $this->ReadPropertyString("Location");

        $pathArray = ["Smart/FieldDetection", "Smart/LineDetection", "Smart/RegionEntrance", "Smart/RegionExiting"];
        $newEnabledValue = $status ? 'true' : 'false';



        $rootID = $this->InstanceID;           // Replace with your actual root object ID
        $objectType = 2;           // Replace with the desired object type (e.g., 2 for Variable)
        $objectName =  $subnet;// Replace with the desired object name
        $matchType = 'partial';    // 'exact' or 'partial'
        $caseSensitive = true;    // true or false

        $filteredObjects = getAllObjectIDsByTypeAndName(
            $rootID,
            $objectType,
            $objectName,
            $matchType,
            $caseSensitive
        );

/*
        $location = 'ProcessCameraEvents';
        $type = 2; // Variable Object Type
        // Get all objects in IP-Symcon
        $allObjects = IPS_GetObjectList();

        // Filter objects based on search terms and location
        $filteredObjects = array_filter($allObjects, function ($objectId) use ($searchTerms, $location, $type) {
            $name = IPS_GetName($objectId);
            $locationStr = IPS_GetLocation($objectId);
            $objectType = IPS_GetObject($objectId)['ObjectType'];

            // Check if any of the search terms are present in the name
            $nameMatch = false;
            foreach ($searchTerms as $searchStr) {
                if (strpos($name, trim($searchStr)) !== false) {
                    $nameMatch = true;
                    break;
                }
            }

            // Check if the location matches
            $locationMatch = strpos($locationStr, $location) !== false;

            // Return true if all conditions are met
            return $nameMatch && $locationMatch && ($objectType == $type);
        });
*/
        // Iterate over the filtered IP variables
        foreach ($filteredObjects as $ipVarId) {
            $ip = GetValueString($ipVarId);
            $parent = IPS_GetParent ($ipVarId);
            $username = GetValueString(IPS_GetObjectIDByName ("User Name",$parent ));
            $password = GetValueString(IPS_GetObjectIDByName ("Password",$parent ));
            IPS_LogMessage("CameraMotionDetectionModule", "Processing IP: $ip");

            foreach ($pathArray as $path) {
                $response = $this->callMotionDetectionAPI($ip, $username, $password, $path);

                try {
                    // Update detection enabled value
                    $modifiedXml = $this->updateDetectionEnabled($response, $this->getStringAfterSmart($path), 1, $newEnabledValue);
                } catch (Exception $e) {
                    IPS_LogMessage("CameraMotionDetectionModule", 'Error: ' . $e->getMessage());
                    continue;
                }

                // Send modified XML back to the API
                $sendResponse = $this->sendModifiedXML($ip, $username, $password, $path, $modifiedXml);
                IPS_LogMessage("CameraMotionDetectionModule", "Response from $ip: $sendResponse");
            }
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


    private function callMotionDetectionAPI($ip, $username, $password, $path)
    {
        $url = "http://$ip/ISAPI/$path";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            IPS_LogMessage("CameraMotionDetectionModule", 'Error: ' . curl_error($ch));
        }

        curl_close($ch);
        return $response;
    }

    private function sendModifiedXML($ip, $username, $password, $path, $modifiedXml)
    {
        $url = "http://$ip/ISAPI/$path";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/xml'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $modifiedXml);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            IPS_LogMessage("CameraMotionDetectionModule", 'Error: ' . curl_error($ch));
        }

        curl_close($ch);
        return $response;
    }

    private function updateDetectionEnabled($xmlString, $detectionType, $id, $newEnabledValue)
    {
        // Load the XML string into a DOMDocument
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        $doc->loadXML($xmlString);

        // Create a DOMXPath object
        $xpath = new DOMXPath($doc);

        // Extract the namespace URI from the root element
        $rootNamespace = $doc->documentElement->namespaceURI;

        // Register the default namespace with a prefix
        $xpath->registerNamespace('ns', $rootNamespace);

        // Build the XPath expression dynamically based on the detection type
        $xpathExpression = "/ns:{$detectionType}List/ns:{$detectionType}[ns:id='{$id}']/ns:enabled";

        // Query for the <enabled> node
        $enabledNodeList = $xpath->query($xpathExpression);

        // Check if the node exists and update its value
        if ($enabledNodeList->length > 0) {
            $enabledNode = $enabledNodeList->item(0);
            $enabledNode->nodeValue = $newEnabledValue;
        } else {
            // Optionally handle the case where the <id> is not found
            throw new Exception("{$detectionType} with id {$id} not found or does not have an <enabled> tag.");
        }

        // Return the modified XML as a string
        return $doc->saveXML();
    }

    private function getStringAfterSmart($inputString)
    {
        // Use strpos to find the position of "Smart/" in the string
        $position = strpos($inputString, 'Smart/');

        // Check if "Smart/" is found in the string
        if ($position !== false) {
            // Calculate the starting position of the substring after "Smart/"
            $startPosition = $position + strlen('Smart/');

            // Use substr to extract the substring from the starting position to the end
            return substr($inputString, $startPosition);
        } else {
            // If "Smart/" is not found, return the original string or handle as needed
            return $inputString;
        }
    }




    public function Destroy() {
        parent::Destroy();
        // Add your custom code here

        if (!IPS_InstanceExists($this->InstanceID)) 
        { 
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
                if ( $correct_hook_found  ) {
                    //Webhook wird jetzt gelöscht
        
                    // Remove the specific webhook from the hooks array
                    unset($hooks[$index]);
                
                    // Re-index the array to prevent gaps in the keys
                    $hooks = array_values($hooks);
                
                    // Update the hooks property with the modified array
                    IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
                    IPS_ApplyChanges($ids[0]);
                }  
                else
                {
                    //Webhook not found
                }
            }
            else{
                //Keine Webhooks vorhanden
            }
            // Call the parent destroy to ensure the instance is properly destroyed
        }
        else{
            //Instanz wurde nicht gelöscht daher bleibt der Webhook bestehen           
        }
    }
}
?>
