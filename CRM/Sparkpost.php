<?php
/**
 * This extension allows CiviCRM to send emails and process bounces through
 * the SparkPost service.
 *
 * Copyright (c) 2016 IT Bliss, LLC
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Support: https://github.com/cividesk/com.cividesk.email.sparkpost/issues
 * Contact: info@cividesk.com
 */

class CRM_Sparkpost {
  const SPARKPOST_EXTENSION_SETTINGS = 'SparkPost Extension Settings';

  static function setSetting($setting, $value) {
    return CRM_Core_BAO_Setting::setItem(
      $value,
      CRM_Sparkpost::SPARKPOST_EXTENSION_SETTINGS,
      $setting);
  }

  static function getSetting($setting = NULL) {
    return CRM_Core_BAO_Setting::getItem(
      CRM_Sparkpost::SPARKPOST_EXTENSION_SETTINGS,
      $setting);
  }

  /**
   * Calls the SparkPost REST API v1
   * @param $path    Method path
   * @param $params  Method paramaters (translated as GET arguments)
   * @param $content Method content (translated as POST arguments)
   *
   * @see https://developers.sparkpost.com/api/
   */
  static function call($path, $params = array(), $content = array()) {
    // Get the API key from the settings
    $authorization = CRM_Sparkpost::getSetting('apiKey');
    if (empty($authorization)) {
      throw new Exception('No API key defined for SparkPost');
    }

    // Deal with the campaign setting
    if (($path =='transmissions') && ($campaign = CRM_Sparkpost::getSetting('campaign'))) {
      $content['campaign_id'] = $campaign;
    }

    // Initialize connection and set headers
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_VERBOSE, true);
    $verbose = fopen('php://temp', 'w+');
    curl_setopt($ch, CURLOPT_STDERR, $verbose);
    curl_setopt($ch, CURLINFO_HEADER_OUT);

    curl_setopt($ch, CURLOPT_URL, "https://api.sparkpost.com/api/v1/$path");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    $request_headers = array();
    $request_headers[] = 'Content-Type: application/json';
    $request_headers[] = 'Authorization: ' . $authorization;
    $request_headers[] = 'User-Agent: CiviCRM SparkPost extension (com.cividesk.email.sparkpost)';
    curl_setopt($ch, CURLOPT_HEADER, FALSE);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);

    if (!empty($content)) {
      if (strpos($path, '/') !== false) {
        // ie. webhook/id
        // This is a modify operation so use PUT
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
      } else {
        // ie. webhook, transmission
        // This is a create operation so use POST
        curl_setopt($ch, CURLOPT_POST, TRUE);
      }
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($content, JSON_UNESCAPED_SLASHES));
    }
    $data = curl_exec($ch);
    if (curl_errno($ch)) {
      throw new Exception('Sparkpost curl error: ', curl_getinfo($ch, CURLINFO_HTTP_CODE));
    }

    rewind($verbose);
    safe_dump(stream_get_contents($verbose), curl_getinfo($ch));

    curl_close($ch);

    // Treat errors if any in the response ...
    $response = json_decode($data);
    safe_dump($response);
    if ($response->errors) {
      $error = reset($response->errors);
      switch($error->message) {
        // First the trivial cases
        case 'Forbidden.' :
          throw new Exception("Sparkpost error: $error->message Check that the API key is valid.");
        case 'Unauthorized.' :
          throw new Exception("Sparkpost error: $error->message Check that the API key is authorized for url: $path");
        default:
          // Else aggregate all error messages
          $messages = array();
          foreach ($response->errors as $error) {
            $messages[] = $error->message;
          }
          throw new Exception('Sparkpost error(s): ' . implode(' ; ', $messages));
      }
    }

    // Return (valid) response
    return $response;
  }
}