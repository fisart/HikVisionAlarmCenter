# ProcessCameraEvents Module for IP-Symcon

This module integrates HIKVISION camera events into the IP-Symcon home automation system. It processes motion detection alerts, downloads snapshots, and manages variables and media objects within IP-Symcon for automation and visualization purposes.

---

## Table of Contents

- [Introduction](#introduction)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
  - [Module Configuration Form](#module-configuration-form)
  - [Camera Setup](#camera-setup)
- [Usage](#usage)
  - [Motion Detection Handling](#motion-detection-handling)
  - [Activating/Deactivating Motion Detection](#activatingdeactivating-motion-detection)
- [Methods](#methods)
  - [Public Methods](#public-methods)
  - [Private Methods](#private-methods)
- [Troubleshooting](#troubleshooting)
- [Security Considerations](#security-considerations)
- [Contributing](#contributing)
- [License](#license)

---

## Introduction

The `ProcessCameraEvents` module is designed to receive and process events from HIKVISION and ANNKE cameras. It listens for motion detection events via a webhook, processes the event data, downloads snapshots, and updates IP-Symcon variables and media objects accordingly.

## Features

- **Webhook Integration:** Registers a webhook to receive motion detection events from HIKVISION and ANNKE cameras.
- **Event Parsing:** Parses XML event notifications and extracts relevant data.
- **Snapshot Downloading:** Downloads camera snapshots upon motion detection.
- **Variable Management:** Dynamically creates and updates variables and media objects in IP-Symcon.
- **Motion Detection Control:** Enables or disables motion detection on all cameras via a single switch.
- **Egg Timer Integration:** Uses the Egg Timer module to manage motion active states.

## Requirements

- **IP-Symcon:** Version supporting modules.
- **HIKVISION or ANNKE Cameras:** Cameras capable of sending event notifications.
- **Egg Timer Module:** Installable from the IP-Symcon Module Store.
- **Network Access:** Proper network configuration to allow communication between IP-Symcon and cameras.

## Installation

1. **Clone or Download the Repository:**

   ```bash
   git clone https://github.com/yourusername/ProcessCameraEvents.git
   ```

2. **Install the Module in IP-Symcon:**

   - Open the IP-Symcon Management Console.
   - Navigate to **Modules**.
   - Click **Add** and enter the path to the cloned repository.

3. **Install Dependencies:**

   - **Egg Timer Module:**
     - Open the Module Store in IP-Symcon.
     - Search for **Egg Timer**.
     - Install the module.

## Configuration

### Module Configuration Form

The module provides a configuration form within IP-Symcon for setting up necessary parameters. Below is a detailed explanation of each element in the configuration form:

#### Labels and Notes

- **Compatibility Notice:**
  - *This module works only with HIKVISION and ANNKE cameras. First, you need to configure Smart Events in the camera setup.*
- **Authentication Settings:**
  - *Please note: In your camera configuration, the `System/Security/Web Authentication` needs to be set to `digest/basic`.*
- **Webhook Configuration:**
  - *Then, you need to configure the webhook call in the camera alarm server (extended network settings).*
- **Variable Creation:**
  - *After a HIKVISION camera has sent an event to the Symcon webhook, a set of variables will be created for each camera.*
- **Variable Structure:**
  - *The top-level variable is the camera name. Below it, you will find the IP address, date and time of the event, password, username, and picture of the last event.*
- **Camera Credentials:**
  - *If your cameras have different passwords/user IDs, you need to enter the correct data into the relevant variable.*
- **Egg Timer Module Requirement (Important):**
  - **Please note: For this module to work, you need to first install the Egg Timer module from the IP-Symcon Module Store.**

#### Configuration Parameters

- **Webhook Name:** (`WebhookName`, default: `HIKVISION_EVENTS`)
  - The name of the webhook endpoint that will receive events from the cameras.
- **Channel ID:** (`ChannelId`, default: `101`)
  - The camera channel ID used for snapshot retrieval.
- **Save Path:** (`SavePath`, default: `/user/`)
  - The relative path where snapshots will be saved within the IP-Symcon directory.
- **Subnet:** (`Subnet`, default: `192.168.50.`)
  - The subnet of your camera network. Used to discover cameras and manage IP addresses.
- **User Name:** (`UserName`, default: `NotSet`)
  - The default username for accessing the cameras. If individual cameras have different credentials, set them in the variables created after event processing.
- **Password:** (`Password`, default: `NotSet`)
  - The default password for accessing the cameras.
- **Duration the Motion Event Stays Active in Seconds:** (`MotionActive`, default: `30`)
  - The duration in seconds for which the motion event remains active.
- **Debug Messages:** (`debug`, default: `false`)
  - Enable or disable debug logging for the module.

#### Status Indicators

The module provides status codes to indicate its current state:

- **102:** Instance is active.
- **104:** Instance is inactive.
- **101:** Instance is being created.
- **103:** Instance is being deleted.
- **105:** Instance was not created.
- **200:** Instance is in error state.

### Camera Setup

1. **Configure Smart Events in Cameras:**

   - Access your HIKVISION or ANNKE camera's web interface.
   - Navigate to the **Smart Events** section.
   - Enable and configure the desired smart events (e.g., motion detection).

2. **Set Web Authentication to Digest/Basic:**

   - In your camera settings, navigate to `System > Security > Web Authentication`.
   - Set the authentication mode to `digest/basic`.

3. **Configure the Webhook in Camera Alarm Server:**

   - In the camera's extended network settings, locate the **Alarm Server** configuration.
   - Set the webhook call to point to your IP-Symcon server:

     ```
     http://<ip-symcon-server-address>/hook/<WebhookName>
     ```

4. **Set Individual Camera Credentials (if necessary):**

   - If your cameras have different usernames or passwords, these can be set in the variables created for each camera after the first event is received.

5. **Install Egg Timer Module (Mandatory):**

   - Before using this module, install the **Egg Timer** module from the IP-Symcon Module Store.
   - The Egg Timer is essential for managing motion active states and timing.

## Usage

### Motion Detection Handling

When a motion event occurs:

- The camera sends an XML notification to the webhook.
- The module processes the incoming data in `ProcessHookData()`.
- Variables and media objects are created or updated:
  - **Camera Name (Top-Level):**
    - Represents the name of the camera.
  - **Event Description:**
    - Details about the motion event.
  - **Date and Time of the Event:**
    - Timestamp when the motion event occurred.
  - **IP Address:**
    - The IP address of the camera.
  - **Password:**
    - Camera password (if different from the default).
  - **User Name:**
    - Camera username (if different from the default).
  - **Picture of the Last Event:**
    - Snapshot image captured during the motion event.

- An Egg Timer is started to manage the motion active state.
- Motion status is reset after the specified duration.

### Activating/Deactivating Motion Detection

- Use the `Activate_all_Cameras` switch variable to enable or disable motion detection on all cameras.
- Changing this variable triggers `RequestAction()`, which calls `ExecuteMotionDetectionAPI()` to update camera settings via their API.

## Methods

### Public Methods

#### `Create()`

Initializes the module, registers properties, variables, and ensures the webhook is set up.

#### `ApplyChanges()`

Re-registers the webhook when properties change.

#### `ProcessHookData()`

Handles incoming webhook data:

- Reads the input stream.
- Determines if data is file or POST data.
- Parses XML and extracts motion event information.
- Calls `handleMotionData()`.

#### `RequestAction($Ident, $Value)`

Handles actions on interactive variables:

- For `Activate_all_Cameras`, it updates the motion detection status on all cameras.

#### `Destroy()`

Cleans up resources and unregisters the webhook when the module is destroyed.

### Private Methods

#### `RegisterHook($WebHook)`

Registers or updates the webhook in IP-Symcon.

#### `handleMotionData($motionData, $source)`

Processes motion event data:

- Manages variables and media objects.
- Downloads snapshots.
- Initiates the Egg Timer.

#### `parseEventNotificationAlert($xmlString)`

Parses XML event notifications into an associative array.

#### `downloadHikvisionSnapshot($cameraIp, $channelId, $username, $password, $relativePath)`

Downloads a snapshot image from the specified camera.

#### `manageVariable($parent, $name, $type, $profile, $logging, $aggregationType, $initialValue)`

Creates or updates a variable with the given parameters.

#### `manageMedia($parent, $name, $imageFile)`

Creates or updates a media object for displaying images.

#### `handle_egg_timer($source, $kamera_name, $kameraId)`

Manages the Egg Timer instance for motion timing.

#### `ExecuteMotionDetectionAPI($status)`

Enables or disables motion detection on all cameras:

- Retrieves camera IPs based on the subnet.
- Calls `callMotionDetectionAPI()` for each camera.
- Updates motion detection settings via the camera's API.

#### `callMotionDetectionAPI($ip, $username, $password, $path)`

Retrieves current motion detection settings from a camera.

#### `sendModifiedXML($ip, $username, $password, $path, $modifiedXml)`

Sends modified XML configuration back to the camera.

#### `updateDetectionEnabled($xmlString, $detectionType, $id, $newEnabledValue)`

Updates the `<enabled>` value in the camera's configuration XML.

#### `getStringAfterSmart($inputString)`

Extracts the detection type from a given path.

#### `GetAllObjectIDsByTypeAndName($rootID, $objectType, $objectName, $matchType, $caseSensitive)`

Retrieves object IDs matching specific criteria.

## Troubleshooting

- **Webhook Not Triggered:**

  - Ensure the webhook URL is correctly configured in the camera settings.
  - Verify that the webhook is registered in IP-Symcon.
  - Check that the camera's `Web Authentication` is set to `digest/basic`.

- **Snapshots Not Downloaded:**

  - Check that the username and password are correct.
  - Ensure the camera's snapshot URL is accessible.
  - Verify that the `Channel ID` and `Save Path` are correctly configured.

- **Variables Not Updated:**

  - Verify that the module has the necessary permissions.
  - Check for errors in the IP-Symcon logs if `debug` is enabled.
  - Confirm that the camera has sent an event to the webhook.

- **Motion Detection Not Controlled:**

  - Ensure the `Activate_all_Cameras` variable is set correctly.
  - Confirm that cameras accept API commands for motion detection settings.
  - Verify network connectivity between IP-Symcon and the cameras.

- **Egg Timer Not Working:**

  - Make sure the Egg Timer module is installed from the Module Store.
  - Verify that the Egg Timer is properly initialized and associated with the camera variables.

## Security Considerations

- **Credential Storage:**

  - Credentials are stored in IP-Symcon variables.
  - Secure access to IP-Symcon to prevent unauthorized access.
  - Avoid using default credentials; update usernames and passwords.

- **Network Security:**

  - Use HTTPS if supported by the cameras to encrypt API communication.
  - Secure your network to prevent interception.
  - Implement firewall rules to limit access to the cameras and IP-Symcon server.

- **Access Control:**

  - Limit access to the webhook endpoint.
  - Implement IP filtering or authentication if necessary.
  - Regularly update and patch your IP-Symcon system and cameras.

## Contributing

Contributions are welcome! Please submit issues or pull requests for enhancements or bug fixes.

## License

This project is licensed under the MIT License.

---

**Note:** Ensure that all paths, module IDs, and class names are correctly adjusted based on your actual implementation details.
