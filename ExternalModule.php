<?php
/**
 * @file
 * Provides ExternalModule class for Multi-DET module.
 */

namespace SaveSurveyPdfToFieldModule\ExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;

require_once "utility.php";
define("ATTEMPT_LIMIT", 30);

/**
 * ExternalModule class for save_survey_pdf_to_field.
 */
class ExternalModule extends AbstractExternalModule {

    private static $recordCache = false;

    function redcap_survey_complete($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
        $this->updatePdf($project_id,$record,$instrument,$event_id,$repeat_instance);
    }

    /**
     * @inheritdoc.
     */
    function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
        $surveyComplete = $this->getFieldValue($project_id,$record,$instrument."_complete",$event_id);

        if($surveyComplete == "2") {
            $this->updatePdf($project_id,$record,$instrument,$event_id,$repeat_instance,true);
        }
    }

    function updatePdf($project_id,$record,$instrument,$event_id,$repeat_instance,$onDataEntryForm = false) {
        global $Proj, $redcap_version;

        //get source instrument and target upload field from config
        $target_fields = $this->getProjectSetting('ssptf_target_upload_field');
        $source_instruments = $this->getProjectSetting('ssptf_source_instrument');
        $dataEntrySave = $this->getProjectSetting('ssptf_data_entry_save');
        $cascadeSkip = $this->getProjectSetting('ssptf_dont_cascade');

        $fieldsToPush = [];
        foreach($source_instruments as $fieldKey => $tempInstrument) {
            if($tempInstrument == $instrument) {
                ## Only push to this field from data entry form if $dataEntrySave is Yes
                ## Only push to this field from survey hook complete if $dataEntrySave is No
                ## This ensures that the PDF isn't saved twice when a survey is submitted
                if(($dataEntrySave[$fieldKey] == "1" && $onDataEntryForm) || !$onDataEntryForm) {
                    $fieldsToPush[$fieldKey] = $target_fields[$fieldKey];
                }
            }
        }

        //abort hook if no instruments match current instrument
        if(count($fieldsToPush) == 0) {
            return 0;
        }

        foreach($fieldsToPush as $fieldKey => $target_upload_field) {
            //make pdf and store it in a temp directory
            $path_to_temp_file = makePDF($project_id, $record, $instrument, $event_id, $repeat_instance);

            $writable = false;
            $lastFilledField = false;
            if(empty($this->getFieldValue($project_id, $record, $target_upload_field, $event_id))) {
                $writable = true;
            }
            else {
                $lastFilledField = $target_upload_field;
            }

            if($cascadeSkip[$fieldKey] != "1") {
                if(!$writable) {
                    $index = 1;
                    $matches = array();
                    if (preg_match('#^(.+)_([0-9]+)$#', $target_upload_field, $matches)) {
                        $target_upload_field = $matches[1];
                        $index = (int)$matches[2];
                    }

                    //check if we can write to $target_upload_field_name else check other base name variations
                    for($count = $index; $count <= ATTEMPT_LIMIT + 1; $count++) {
                        $field = $target_upload_field . "_" . $count;

                        if(isset($Proj->metadata[$field]) && $Proj->metadata[$field]['element_type'] == 'file' &&
                                empty($this->getFieldValue($project_id, $record, $field, $event_id)))
                        {
                            $target_upload_field = $field;
                            $writable = true;
                            break;
                        } else if (isset($Proj->metadata[$field]) && $Proj->metadata[$field]['element_type'] == 'file' &&
                                !empty($this->getFieldValue($project_id, $record, $field, $event_id))) {
                            $lastFilledField = $field;
                        }
                    }
                }
            }

            if ($writable) {
                //upload pdf into designated upload field
                $doc_id = uploadPdfToEdocs($path_to_temp_file, $instrument);
                setUploadField($project_id, $record, $event_id, $target_upload_field, $doc_id);

                REDCap::logEvent("save_survey_pdf_to_field", "save_survey_pdf_to_field uploaded a new PDF to a field.\n$target_upload_field = $doc_id", null, $record, $event_id, $project_id);
            } else if (!$writable and !empty($lastFilledField)) {
                //upload pdf into last field in sequence even though it has a value
                $doc_id = uploadPdfToEdocs($path_to_temp_file, $instrument);
                setUploadField($project_id, $record, $event_id, $lastFilledField, $doc_id);

                REDCap::logEvent("save_survey_pdf_to_field", "save_survey_pdf_to_field uploaded a new PDF to a field.\n$lastFilledField = $doc_id", null, $record, $event_id, $project_id);
            } else {
                //log failure
                REDCap::logEvent("save_survey_pdf_to_field alert", "save_survey_pdf_to_field failed to save a PDF from the '$instrument' instrument to the '$target_upload_field' field.", null, $record, $event_id, $project_id);

                //send error email
                $receiver_addr = AbstractExternalModule::getProjectSetting('ssptf_receiver_address');
                $sender_addr = AbstractExternalModule::getSystemSetting('ssptf_sender_address');
                $cc = AbstractExternalModule::getSystemSetting('ssptf_cc');
                $subject = "ERROR: PDF of REDCap instrument could not be saved.";
                $url = APP_PATH_WEBROOT_FULL . 'redcap_v' . $redcap_version . "/DataEntry/record_home.php?pid=" . $project_id . "&id=" . $record . "&arm=" . getArm();
                $body = "ERROR: REDCap failed to save a PDF of an instrument for this
              research subject: " . "<a href=\"". $url . "\">" . $url . "</a>" .
                        " That document is attached to this message. Please review this REDCap
              project's configuration, make changes as needed,and upload this PDF to
              this research subject's record.";
                $sent = sendEmail($receiver_addr, $sender_addr, $cc, $subject, $body, $path_to_temp_file);

                //notify user if email failed to send
                if (!$sent) {
                    REDCap::logEvent("save_survey_pdf_to_field alert", "save_survey_pdf_to_field could not send email containing the PDF from the '$instrument' instrument.", null, $record, $event_id, $project_id);
                } else {
                    REDCap::logEvent("save_survey_pdf_to_field alert", "save_survey_pdf_to_field sent an email containing the PDF from the '$instrument' instrument.", null, $record, $event_id, $project_id);
                }
            }
        }
        unlink($path_to_temp_file);
    }

    /**
     * Checks if the given field has a value in that specific project and record
     *
     * @param $project
     *  project id
     *
     * @param $record
     *  record id
     *
     * @param $field
     *  field name
     *
     * @param $event
     *  event id
     *
     * @return boolean
     *  returns true if it has a value, false otherwise
     */
    function getFieldValue($project, $record, $field, $event) {
        if(self::$recordCache === false) {
            self::$recordCache = REDCap::getData($project, 'array', $record, [], $event);
        }
        return !empty(self::$recordCache[$record][$event][$field]);
    }
}
