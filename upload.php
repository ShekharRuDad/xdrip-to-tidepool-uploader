<?php

# xDrip database export to Tidepool bulk upload script.
#
# How to use this script:
#
# 1. Insert your Tidepool e-mail address, password and the default timezone 
#    into the variables at the top of the script.
#
# 2. On the main xDrip screen, go to the top right menu, select import/export
#    features and then export database. This will save a database export ZIP
#    file to the xDrip folder on your Android device.
#
# 3. Transfer the ZIP file to your computer.
#
# 4. Run the upload program from the command line:
#
#    $ php uploader.php <database ZIP file>

$tidepool_email = '';
$tidepool_password = '';
$timezone = 'America/Los_Angeles';

if (!date_default_timezone_set($timezone)) {
  echo "Could not set timezone. Did you enter a valid timezone name?\n";
  exit(1);
}

$database_zip_file = $argv[1];
$database_sqlite_file = extract_database($database_zip_file);
if (!$database_sqlite_file) {
  echo "Could not extract database ZIP file.\n";
  exit(1);
}

$db = new SQLite3($database_sqlite_file, SQLITE3_OPEN_READONLY);

$bg_readings = get_bg_readings($db);
if ($bg_readings) {
  echo "Found " . count($bg_readings) . " BG readings.\n";
} else {
  echo "Could not get BG readings.\n";
  exit(1);
}

$calibrations = get_calibrations($db);
if ($calibrations) {
  echo "Found " . count($calibrations) . " calibrations.\n";
} else {
  echo "Could not get calibrations.\n";
  exit(1);
}

echo "Logging in to Tidepool...\n";
$tidepool= tidepool_login($tidepool_email, $tidepool_password);
if ($tidepool) {
  echo "Success!\n";
} else {
  echo "Could not log in to Tidepool.\n";
  exit(1);
}

echo "Creating device...\n";
$success = create_device($tidepool['auth_token'], $tidepool['userid'], $timezone);
if ($success) {
  echo "Success!\n";
} else {
  echo "Failed to create device.\n";
  exit(1);
}

echo "Uploading BG readings...\n";
$success = upload_data($tidepool['auth_token'], $bg_readings);
if (!$success) {
  echo "Upload failed.\n";
  exit(1);
}

echo "Uploading calibrations...\n";
$success = upload_data($tidepool['auth_token'], $calibrations);
if (!$success) {
  echo "Upload failed.\n";
  exit(1);
}

// End of main program. Functions are below.

function extract_database($path) {
  $zip = new ZipArchive();
	if (!$zip->open($path)) {
	  return FALSE;
  }
  
  $filename = $zip->GetNameIndex(0);
  if (!$filename) {
    return FALSE;
  }
  
  $destination_path = sys_get_temp_dir() . '/tidepool-uploader-' . time();
  if (!$zip->extractTo($destination_path)) {
    return FALSE;
  }
  
  return $destination_path . '/' . $filename;
}

function get_bg_readings($db) {
  $readings = array();
  $result = $db->query('SELECT * FROM BgReadings ORDER BY Timestamp ASC');
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $timestamp_seconds = $row['timestamp'] / 1000;
    $value = $row['dg_mgdl'];
    if ($value <= 0 || $value >= 1000) {
      continue;
    }

    array_push($readings, array(
      'type' => 'cbg',
      'value' => $value,
      'units' => 'mg/dL',
      'deviceId' => 'xdrip',
      'deviceTime' => date('Y-m-d\TH:i:s', $timestamp_seconds),
      'time' => date(DATE_ATOM, $timestamp_seconds),
      'timezoneOffset' => intval(date('O', $timestamp_seconds)),
      'uploadId' => uniqid('xdrip-'),
    ));
  }
  return $readings;
}

function get_calibrations($db) {
  $calibrations = array();
  $result = $db->query('SELECT * FROM Calibration ORDER BY Timestamp ASC');
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $timestamp_seconds = $row['timestamp'] / 1000;
    $value = $row['bg'];
    if ($value <= 0 || $value >= 1000) {
      continue;
    }
      
    array_push($calibrations, array(
      'type' => 'smbg',
      'subType' => 'manual',
      'value' => $value,
      'units' => 'mg/dL',
      'deviceId' => 'xdrip',
      'deviceTime' => date('Y-m-d\TH:i:s', $timestamp_seconds),
      'time' => date(DATE_ATOM, $timestamp_seconds),
      'timezoneOffset' => intval(date('O', $timestamp_seconds)),
      'uploadId' => uniqid('xdrip-'),
    ));
  }
  return $calibrations;
}

function request_succeeded($ch) {
  $response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  return $response_code == 200;
}

function print_request_response($ch, $request, $response) {
  echo "\nRequest:\n";
  echo curl_getinfo($ch, CURLINFO_HEADER_OUT) . "\n";
  echo $request . "\n\n";
  echo "Response:\n";
  echo $response . "\n";
}

function tidepool_login($tidepool_email, $tidepool_password) {
  $ch = curl_init('https://api.tidepool.org/auth/login');
  curl_setopt($ch, CURLOPT_POST, TRUE);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($ch, CURLOPT_USERPWD, $tidepool_email . ':' . $tidepool_password); 
  curl_setopt($ch, CURLOPT_HEADER, TRUE);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'x-tidepool-client-name: com.github.dangerouslyamped.xdrip-to-tidepool-uploader',
    'x-tidepool-client-version: 1.0.0',
  ));
  curl_setopt($ch, CURLINFO_HEADER_OUT, TRUE);
  $response = curl_exec($ch);
  
  if (!request_succeeded($ch)) {
    print_request_response($ch, '', $response);
    return FALSE;
  }
  
  $matches = array();
  preg_match('/x-tidepool-session-token:(.*)/', $response, $matches);
  if (count($matches) == 0) {
    return FALSE;
  }
  $auth_token = trim($matches[1]);
  
  $matches = array();
  preg_match('/\{.*\}/', $response, $matches);
  if (count($matches) == 0) {
    return FALSE;
  }
  
  $tidepool = json_decode($matches[0], TRUE);
  $tidepool['auth_token'] = $auth_token;
  return $tidepool;
}

function create_device($auth_token, $userid, $timezone) {
  $data = array(
    'type' => 'upload',
    'byUser' => $userid,
    'deviceId' => 'xdrip',
    'deviceManufacturers' => array('Dexcom'),
    'deviceModel' => 'xdrip',
    'deviceSerialNumber' => 'xdrip',
    'deviceTags' => array('cgm'),
    'timeProcessing' => 'across-the-board-timezone',
    'timezone' => $timezone,
    'version' => '1.0.0',
    'clockDriftOffset' => 0,
    'conversionOffset' => 0,
    'deviceTime' => date('Y-m-d\TH:i:s'),
    'computerTime' => date('Y-m-d\TH:i:s'),
    'time' => date(DATE_ATOM),
    'timezoneOffset' => intval(date('O')),
    'uploadId' => uniqid('xdrip-'),
  );
  
  $json_data = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  
  $ch = curl_init('https://uploads.tidepool.org/data');
  curl_setopt($ch, CURLOPT_POST, TRUE);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
  curl_setopt($ch, CURLOPT_HEADER, TRUE);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'x-tidepool-session-token: ' . $auth_token,
    'x-tidepool-client-name: com.github.dangerouslyamped.xdrip-to-tidepool-uploader',
    'x-tidepool-client-version: 1.0.0',
    'Content-Type: application/json'
  ));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($ch, CURLINFO_HEADER_OUT, TRUE);
  $response = curl_exec($ch);

  if (!request_succeeded($ch)) {
    print_request_response($ch, $json_data, $response);
    return FALSE;
  }
  return TRUE;
}

function upload_data($auth_token, $data) {
  $json_data = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

  $ch = curl_init('https://uploads.tidepool.org/data');
  curl_setopt($ch, CURLOPT_POST, TRUE);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
  curl_setopt($ch, CURLOPT_HEADER, TRUE);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'x-tidepool-session-token: ' . $auth_token,
    'x-tidepool-client-name: com.github.dangerouslyamped.xdrip-to-tidepool-uploader',
    'x-tidepool-client-version: 1.0.0',
    'Content-Type: application/json'
  ));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($ch, CURLINFO_HEADER_OUT, TRUE);
  $response = curl_exec($ch);
  
  if (!request_succeeded($ch)) {
    print_request_response($ch, $json_data, $response);
    return FALSE;
  }

  $first_time = strtotime($data[0]['time']);
  $last_time = strtotime($data[count($data) - 1]['time']);
  
  echo "Uploaded " . count($data) . " values.\n";
  echo "First value: " . date(DATE_RSS, $first_time) . "\n";
  echo "Last value: " . date(DATE_RSS, $last_time) . "\n";
  return TRUE;
}

?>
