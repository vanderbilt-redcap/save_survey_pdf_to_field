<?php
/**
 * @file
 * Provides ExternalModule class for Multi-DET module.
 */

namespace SaveSurveyPdfToFieldModule\ExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

require_once "utility.php";
define("ATTEMPT_LIMIT", 30);

/**
 * ExternalModule class for save_survey_pdf_to_field.
 */
class ExternalModule extends AbstractExternalModule {

    /**
     * @inheritdoc.
     */
    function hook_survey_complete($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {

      $source_instruments = AbstractExternalModule::getProjectSetting('ssptf_source_instrument');

      //check if instrument is the same one set in config
      $index = array_search($instrument, $source_instruments);

      //abort hook if not
      if($index === FALSE) {
        return 0;
      }

      //get target upload field from config
      $target_fields = AbstractExternalModule::getProjectSetting('ssptf_target_upload_field');
      $target_upload_field = $target_fields[$index];

      $matches = array();
      $index = 1;
      $target_upload_field_name = $target_upload_field;
      if (preg_match('#^(.+)_([0-9]+)$#', $target_upload_field, $matches)) {
          $target_upload_field_name = $matches[1];
          $index = (int)$matches[2];
      }

      //check if we can write to $target_upload_field_name else check other base name variations
      $writable = false;
      for($count = $index; $count <= ATTEMPT_LIMIT + 1; $count++) {
        if(doesFieldExist($target_upload_field_name . $extension) && !fieldHasValue($project_id, $record, $target_upload_field_name . $extension, $event))
        {
          $target_upload_field_name = $target_upload_field_name . $extension;
          $writable = true;
          break;
        }

        $extension = "_" . $count;
      }

      //make pdf and store it in a temp directory
      $path_to_temp_file = makePDF($project_id, $record, $instrument, $event_id, $repeat_instance);

      //create informational array to add context to log messages
      $log_info = ["record" => $record, "instrument" => $instrument];

      if ($writable) {
          //upload pdf into designated upload field
          $doc_id = uploadPdfToEdocs($path_to_temp_file, $instrument);
          setUploadField($project_id, $record, $event_id, $target_upload_field_name, $doc_id);

          $log_info["upload_field_name"] = $target_upload_field_name;
          logMessage("<font color='green'>SUCCESS</font><br>save_survey_pdf_to_field uploaded new PDF", $log_info);
      } else {
          //log failure
          logMessage("ERROR: PDF of an instrument could not be saved.");

          //send error email
          $receiver_addr = AbstractExternalModule::getProjectSetting('ssptf_receiver_address');
          $sender_addr = AbstractExternalModule::getSystemSetting('ssptf_sender_address');
          $cc = AbstractExternalModule::getSystemSetting('ssptf_cc');
          $subject = "ERROR: PDF of REDCap instrument could not be saved.";
          $url = "http://". $_SERVER["HTTP_HOST"] . "/redcap/redcap_v" . REDCAP_VERSION . "/DataEntry/record_home.php?pid=" . $project_id . "&id=" . $record . "&arm=" . getArm();
          $body = "ERROR: REDCap failed to save a PDF of an instrument for this
          research subject: " . "<a href=\"". $url . "\">" . $url . "</a>" .
          " That document is attached to this message. Please review this REDCap
          project's configuration, make changes as needed,and upload this PDF to
          this research subject's record.";
          $sent = sendEmail($receiver_addr, $sender_addr, $cc, $subject, $body, $path_to_temp_file);

          //notify user if email failed to send
          if (!$sent) {
            logMessage("ERROR: could not send email containing the unsaved PDF of an instrument.");
          } else {
            logMessage("<font color='green'>SUCCESS</font><br>save_survey_pdf_to_field sent email containing PDF", $log_info);
          }
      }

      unlink($path_to_temp_file);
    }
}
