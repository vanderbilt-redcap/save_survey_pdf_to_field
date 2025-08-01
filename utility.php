<?php

namespace SaveSurveyPdfToFieldModule\ExternalModule;
/**
 * Creates a pdf of the given instrument.
 *
 * @param $project_id
 *  Project id
 *
 * @param $record
 *  record id
 *
 * @param $instrument
 *  instrument name
 *
 * @param $event_id
 *  event id
 *
 * @param $repeat_instance
 *  instance number of the instrument (if it is repeating)
 *
 * @return string $full_path_to_temp_file
 *  absolute path to the pdf generated by this function
*/
function makePDF($project_id, $record, $instrument, $event_id, $repeat_instance) {
  $filename = "project" . $project_id .
              "_record" . $record .
              "_instrument" . $instrument .
              "_event" . $event_id .
              "_instance" . $repeat_instance .
              "_" . date("Ymd") . ".pdf";
  $pdf_content = \REDCap::getPDF($record, $instrument, $event_id, false, $repeat_instance);
  $full_path_to_temp_file = APP_PATH_TEMP . $filename;
  file_put_contents($full_path_to_temp_file, $pdf_content);
  return $full_path_to_temp_file;
}

/**
 * uploads the pdf file at $file_path into the edocs folder and tags its stored name
 * file name.
 *
 * @param string $file_path
 *  location of the pdf file to be stored into the edocs folder
 *
 * @param string $filename
 *  name of the pdf file to be displayed to users
 *
 * @return int $doc_id
 *  doc_id of the file in the redcap_edocs_metadata table. Returns 0 on failure.
 */
function uploadPdfToEdocs($file_path, $filename) {
  $_FILE['type'] = "application/pdf";
  $_FILE['name'] = $filename . "_" . date('Y-m-d_Hi') . ".pdf";
  $_FILE['tmp_name'] = $file_path;
  $_FILE['size'] = filesize($_FILE['tmp_name']);
  $doc_id = \Files::uploadFile($_FILE);
  return $doc_id;
}

/**
 * links a file in edocs to a file upload field
 *
 * @param $project_id
 *  Project id
 *
 * @param $record
 *  record id
 *
 * @param $event
 *  event id
 *
 * @param $field
 *  field name
 *
 * @param $doc_id
 *  document id assigned to the file in edocs
 *
 * @return boolean success
 *  returns true if sucessful, false otherwise
*/
function setUploadField($project_id, $record, $event, $field, $doc_id) {
  global $conn;

  //check connection
  if($conn->connect_errno){
    return false;
  }

  $query = '
    INSERT INTO '.\REDCap::getDataTable($project_id).' (project_id, event_id, record, field_name, value, instance)
    VALUES(' . intval($project_id) . ', ' . intval($event) . ', "' . db_escape($record) . '", "' . db_escape($field) . '", ' . intval($doc_id) . ', NULL)';

  $result = $conn->query($query);
  if(!$result) {
    return false;
  }

  return true;
}



/**
 * sends an email with the given parameters. Can optionally cc and add attachments
 *
 * @param $receiver
 *  email receipient
 *
 * @param $sender
 *  email sender
 *
 * @param $cc
 *  email to send a carbon copy to
 *
 * @param $subject
 *  subject of email
 *
 * @param $body
 *  email contents
 *
 * @param $attachment_file_path
 *  full file path of a file(including its file name). this file will be
 *  attached to this email.
 *
 * @return boolean
 *  returns true if email is successfully sent, false otherwise
 */
function sendEmail($receiver, $sender, $cc = '', $subject, $body, $attachment_file_path = NULL) {
  $email = new \Message();
  $email->setTo($receiver);
  $email->setFrom($sender);
  $email->setCc($cc);
  $email->setSubject($subject);
  $email->setAttachment($attachment_file_path);
  $email->setBody($body);
  return $email->send();
}
?>
