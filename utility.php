<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once APP_PATH_DOCROOT . '../redcap_connect.php';
require_once APP_PATH_DOCROOT . 'Classes/Files.php';

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
  $_FILE['name'] = $filename . date('Y-m-d_Hi') . ".pdf";
  $_FILE['tmp_name'] = $file_path;
  $_FILE['size'] = filesize($_FILE['tmp_name']);
  $doc_id = Files::uploadFile($_FILE);
  return $doc_id;
}

 ?>
