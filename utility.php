<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once APP_PATH_DOCROOT . '../redcap_connect.php';

function makePDF($project_id, $record, $instrument, $event_id, $repeat_instance) {
  $filename = "project" . $project_id .
              "_record" . $record .
              "_instrument" . $instrument .
              "_event" . $event_id .
              "_instance" . $repeat_instance .
              "_" . date("Ymd") . ".pdf";
  $pdf_content = REDCap::getPDF($record, $instrument, $event_id, false, $repeat_instance);
  $full_path_to_temp_file = APP_PATH_TEMP . $filename;
  file_put_contents($full_path_to_temp_file, $pdf_content);
  return $full_path_to_temp_file;
}



 ?>
