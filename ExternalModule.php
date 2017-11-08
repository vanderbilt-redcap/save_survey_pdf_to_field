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

      //check if we can write to $target_upload_field else check other base name variations
      $writable = false;
      for($count = 1; $count <= ATTEMPT_LIMIT + 1; $count++) {
        if(doesFieldExist($target_upload_field . $extension) && !fieldHasValue($project_id, $record, $target_upload_field . $extension, $event)) {
          $target_upload_field = $target_upload_field . $extension;
          $writable = true;
          break;
        }

        $extension = "_" . $count;
      }

      //make pdf and store it in a temp directory
      $path_to_temp_file = makePDF($project_id, $record, $instrument, $event_id, $repeat_instance);

      if ($writable) {
        //upload pdf into designated upload field
        $doc_id = uploadPdfToEdocs($path_to_temp_file, $instrument);
        setUploadField($project_id, $record, $event_id, $target_upload_field, $doc_id);
      } else {
          //send error email
      }

      unlink($path_to_temp_file);
    }
}
