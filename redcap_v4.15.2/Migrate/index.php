<?php
/*****************************************************************************************
**  REDCap is only available through a license agreement with Vanderbilt University
******************************************************************************************/


// Config
require_once dirname(dirname(__FILE__)) . '/Config/init_global.php';

// Change initial server value to account for a lot of processing and memory
ini_set('max_execution_time', '10000');
ini_set('memory_limit', '768M');

// Set how many surveys will have their migration script generated per batch
define("BATCH", 10);

// Start clock to calculate script execution time
$mtime = microtime(); 
$mtime = explode(" ",$mtime); 
$mtime = $mtime[1] + $mtime[0]; 
$starttime = $mtime;

// Must be a super user
if (!SUPER_USER) exit("Sorry, but you must be a REDCap super user in order to access this page!");


/**
 * FUNCTIONS
 */
// Add leading zeroes inside version number (keep dots)
function getLeadZeroVersion($dotVersion) {
	list ($one, $two, $three) = explode(".", $dotVersion);
	return $one . "." . sprintf("%02d", $two) . "." . sprintf("%02d", $three);
}
// Remove leading zeroes inside version number (keep dots)
function removeLeadZeroVersion($leadZeroVersion) {
	list ($one, $two, $three) = explode(".", $leadZeroVersion);
	return $one . "." . ($two + 0) . "." . ($three + 0);
}
// Add leading zeroes inside version number (remove dots)
function getDecVersion($dotVersion) {
	list ($one, $two, $three) = explode(".", $dotVersion);
	return $one . sprintf("%02d", $two) . sprintf("%02d", $three);
}
// End the page and give footer
function endpage($text) {
	global $objHtmlPage;
	if (isset($text) && !empty($text)) print $text;
	$objHtmlPage->PrintFooter();
	exit;
}
// De-encode values first to ensure we don't double encode them
function prep2($val) {
	$val = html_entity_decode($val, ENT_QUOTES);
	return prep($val);
}
// CheckNull using prep2()
function checkNull2($value) {
	if ($value != "") {
		return "'" . prep2($value) . "'";
	} else {
		return "NULL";
	}
}
/* 
// Build and maintain list of all survey hashes created to ensure uniqueness
function getSurveyHash($rc_db, $rc_conn)
{
	mysql_select_db($rc_db, $rc_conn);
	do {
		// Generate a new random hash
		$hash = generateRandomHash();
		// Check generated hash against table
		$q = mysql_query("select 1 from redcap_surveys_participants where hash = '$hash'", $rc_conn);
	} 
	while (mysql_num_rows($q) > 0);
	return $hash;	
}
 */
// DB connection for both REDCap and RS
function db_connect_both()
{
	// Connect to RS
	$rs_conn_file = dirname(dirname(dirname(__FILE__))) . DS . "database_rs.php";
	include $rs_conn_file;
	$rs_conn = mysql_connect($hostname, $username, $password, true);
	mysql_select_db($db, $rs_conn);
	$rs_db = $db;
	// Connect to REDCap
	$rc_conn_file = dirname(dirname(dirname(__FILE__))) . DS . "database.php";
	include $rc_conn_file;
	$rc_conn = mysql_connect($hostname, $username, $password, true);
	mysql_select_db($db, $rc_conn);
	$rc_db = $db;
	// Return both resources
	return array($rc_conn, $rs_conn, $rc_db, $rs_db);
}
// Formats field names to correct format
function formatVar($val) 
{
	$val = preg_replace("/[^a-z_0-9]/", "_", trim(strtolower($val)));
	// Remove any double underscores, beginning numerals, and beginning/ending underscores
	while (strpos($val, "__") !== false) 	$val = str_replace("__", "_", $val);
	while (substr($val, 0, 1) == "_") 		$val = substr($val, 1);
	while (substr($val, -1) == "_") 		$val = substr($val, 0, -1);
	while (is_numeric(substr($val, 0, 1)))  $val = substr($val, 1);
	return $val;
}
// Build ALL the scripts for migratin RS data into REDCap
function renderMigrationScript($num_batch=null,$numSurveys=1)
{
	//DB connect
	list ($rc_conn, $rs_conn, $rc_db, $rs_db) = db_connect_both();
	
	$migString = "";
	
	## Set query for setting project defaults
	// First, get listing of field names from redcap_projects table to use for inserting project defaults
	$rp_fields = array();
	$config_fields = array();
	mysql_select_db($rc_db, $rc_conn);
	$q = mysql_query("SHOW COLUMNS FROM redcap_projects", $rc_conn);
	while ($row = mysql_fetch_assoc($q)) {
		$rp_fields[$row['Field']] = '';
	}
	// Get project defaults and put into array to build the query
	$q = mysql_query("select * from redcap_config", $rc_conn);
	while ($row = mysql_fetch_assoc($q)) 
	{
		if ($row['field_name'] == 'auth_meth_global') $row['field_name'] = 'auth_meth'; // Change this field_name since auth_meth was removed from config table in 4.14.5
		if (isset($rp_fields[$row['field_name']])) {
			$config_fields[] = $row['field_name'] . " = '" . prep2($row['value']) . "'";
		}
	}
	$project_defaults_sql = "update redcap_projects set " . implode(", ", $config_fields) . " where project_id = @project_id;";
		
	// Set up array of RS=>REDCap equivalent field types
	$field_types = array("instruction"=>"section_header", "mc"=>"radio", "drop-down"=>"select", "yn"=>"yesno", "tf"=>"truefalse",
						 "spec"=>"descriptive", "download"=>"descriptive", "date"=>"text", "ma"=>"checkbox");
						 
	// Set up array as list of REDCap multiple choice fields
	$mc_field_types = array("radio", "checkbox", "select", "yesno", "truefalse");
	
	// Set up limiters for doing surveys in bunches
	$survey_limit = "";
	if (is_numeric($num_batch)) {
		$survey_limit = "limit " . (($num_batch-1)*BATCH) . ", " . BATCH;
		// Set the counter for surveys processed
		$surveyCount = (BATCH*($num_batch-1))+1;	
	}
	
	// Array to keep email_survey_md5 in case of duplicates (really old RS issue that was fixed early on but still exists in data maybe)
	$email_md5 = array();
	
	## Loop through all surveys
	mysql_select_db($rs_db, $rs_conn);
	$sql = "select * from rs_survey order by survey_id $survey_limit";
	$q = mysql_query($sql, $rs_conn);
	while ($row = mysql_fetch_assoc($q))
	{
		// Set unique project_name based on survey_title
		$project_name = preg_replace("/[^a-z0-9]/", "", strtolower($row['survey_title']));
		if (strlen($project_name) > 20) $project_name = substr($project_name, 0, 20);
		while (is_numeric(substr($project_name, 0, 1))) $project_name = substr($project_name, 1);
		if (strlen($project_name) < 1)  $project_name = "survey";
		$project_name .= "_" . substr(md5(rand()), 0, 15);
		// Set status
		$status = $row['survey_status'];
		if ($status == 4) $status = 2; //Inactive
		if ($status == 5) $status = 3; //Archived
		// Get username of created_by so we can transfer into new ui_id
		$q3 = mysql_query("select username from rs_user_info where ui_id = ".$row['created_by'], $rs_conn);
		$created_by_username = mysql_result($q3, 0);
		// Begin script for this survey
		$migString .= "-- IMPORTING SURVEY ".$surveyCount++." of $numSurveys (RS survey_id {$row['survey_id']}) --\r\n";
		// Project-level
		$migString .= "-- Project-level\r\n";
		$migString .= "insert into redcap_projects (project_name, created_by, auto_inc_set, surveys_enabled, imported_from_rs, app_title, status, draft_mode, creation_time, production_time, purpose, purpose_other) "
			. "values ('$project_name', (select ui_id from redcap_user_information where username = '".prep2($created_by_username)."' limit 1), 1, 2, 1, '".prep2($row['survey_title'])."', $status, {$row['confirm_edit']}, ".checkNull2($row['creation_time']).", ".checkNull2($row['production_time']).", ".checkNull2($row['purpose']).", ".checkNull2(prep2($row['purpose_other'])).");\r\n";
		$migString .= "set @project_id = LAST_INSERT_ID();\r\n";
		$migString .= "$project_defaults_sql\r\n";
		// Add 1 arm and 1 event
		$migString .= "-- Arm-level\r\n";
		$migString .= "insert into redcap_events_arms (project_id) values (@project_id);\r\n";
		$migString .= "set @arm_id = LAST_INSERT_ID();\r\n";
		$migString .= "insert into redcap_events_metadata (arm_id) values (@arm_id);\r\n";
		$migString .= "set @event_id = LAST_INSERT_ID();\r\n";
		// Copy RS edocs table info into REDCap edocs table	
		$migString .= "-- Edocs-level\r\n";
		$q3 = mysql_query("select * from rs_edocs where survey_id = {$row['survey_id']} order by doc_id", $rs_conn);
		while ($row3 = mysql_fetch_assoc($q3))
		{
			// To prevent "deleted" RS files from being removed from the server immediately, set their deleted time as NOW, 
			// which will give 1 month before they're really deleted'
			if ($row3['delete_date'] != "") $row3['delete_date'] = NOW;
			// Add to redcap_edocs table
			$migString .= "insert into redcap_edocs_metadata (stored_name, mime_type, doc_name, doc_size, file_extension, project_id, stored_date, delete_date) "
				. "values ('".prep2($row3['stored_name'])."', '".prep2($row3['mime_type'])."', '".prep2($row3['doc_name'])."', '".prep2($row3['doc_size'])."', '".prep2($row3['file_extension'])."', @project_id, ".checkNull2($row3['stored_date']).", ".checkNull2($row3['delete_date']).");\r\n";
			// Add to edoc temp table
			$migString .= "insert into redcap_temp_edocs values (LAST_INSERT_ID(), {$row3['doc_id']});\r\n";
		}
		// If survey has a logo, get its edoc_id
		$logo = "NULL";
		if (!empty($row['logo'])) 
		{
			$logo = "(select redcap_edoc_id from redcap_temp_edocs where rs_edoc_id = {$row['logo']} limit 1)";
		}
		// Survey-level
		$form_name = "survey";
		$migString .= "-- Survey-level\r\n";
		$row['question_numbering'] = ($row['question_numbering'] == '1') ? '0' : '1';
		$migString .= "insert into redcap_surveys (project_id, logo, form_name, title, instructions, acknowledgement, question_by_section, question_auto_numbering, survey_enabled, save_and_return, hide_title) ";
		$migString .= "values (@project_id, $logo, '$form_name', '".prep2($row['survey_title'])."', '".prep2($row['survey_description'])."', '".prep2($row['survey_acknowledgement'])."', {$row['question_by_section']}, {$row['question_numbering']}, 1, {$row['save_and_return']}, {$row['hide_title']});\r\n";
		$migString .= "set @survey_id = LAST_INSERT_ID();\r\n";
		## Metadata-level
		// Draft Mode Only: Set up array to collect our given variable name of "download"/attachment fields in order to maintain its variable name
		// so that it does not get changed when prod changes are approved after the survey import, which will lose its attachment.
		$descriptive_match = array();
		$descriptive_var   = array();
		if ($row['survey_status'] > 0 && $row['confirm_edit'] > 0) 
		{
			$sql = "select q.sq_id, a.sq_id as draft_sq_id, a.file_download_id as edoc_id 
					from rs_survey_question q, rs_survey_question_archive a where q.survey_id = {$row['survey_id']} 
					and q.survey_id = a.survey_id and a.file_download_id = q.file_download_id and a.file_download_id is not null 
					and a.file_download_id != '' and a.file_download_id != 0 and a.timestamp is null";
			$q3 = mysql_query($sql, $rs_conn);
			while ($row3 = mysql_fetch_assoc($q3))
			{
				$descriptive_match[$row3['draft_sq_id']] = $row3['sq_id'];
			}
		}
		// Set up some values in array to loop through metadata and metadata_temp (if in draft mode) - prevents having to copy all this code
		$metadata_tables   = array();
		$metadata_tables[] = array( 'label'=>'Metadata-level', 'metadata_table'=>'redcap_metadata', 'ext'=>'', 
									'qtimestamp'=>'', 'stimestamp'=>'', 'atimestamp'=>'', 'otimestamp'=>'', 'mtimestamp'=>'');
		if ($row['survey_status'] > 0 && $row['confirm_edit'] > 0) {
			// In production AND in draft mode
			$metadata_tables[] = array( 'label'=>'Metadata-level (Draft Mode)', 'metadata_table'=>'redcap_metadata_temp', 'ext'=>'_archive', 
										'qtimestamp'=>'and q.timestamp is null', 'stimestamp'=>'and s.timestamp is null', 'atimestamp'=>'and a.timestamp is null',
										'otimestamp'=>'and o.timestamp is null', 'mtimestamp'=>'and m.timestamp is null');
		}
		// Loop through all metadata tables
		foreach ($metadata_tables as $meta)
		{
			$migString .= "-- {$meta['label']}\r\n";
			// Defaults
			$field_order = 1;
			$section_header_text = "";
			$form_name = "survey";
			// MC Choices: Before we get down to field level, first get all MC choices for survey and put in array with variable names as keys
			$mc_choices = array();
			$checkbox_fields = array();
			$sql = "select q.question_type, s.question_label, m.mc_choice, m.mc_description from rs_survey_question{$meta['ext']} s, 
					rs_multiple_choice{$meta['ext']} m, rs_question{$meta['ext']} q 
					where q.question_id = s.question_id and q.question_id = m.question_id and s.survey_id = {$row['survey_id']} 
					{$meta['stimestamp']} {$meta['mtimestamp']} order by q.question_id, m.mc_choice";
			$q3 = mysql_query($sql, $rs_conn);
			while ($row3 = mysql_fetch_assoc($q3))
			{
				// Format question label
				$row3['question_label'] = formatVar($row3['question_label']);
				// Make sure variable is not participant_id (if so, change it to prevent confliction)
				if ($row3['question_label'] == "participant_id") $row3['question_label'] .= "_rs";
				$mc_choices[$row3['question_label']][$row3['mc_choice']] = $row3['mc_description'];
				// Put checkboxes into array
				if ($row3['question_type'] == 'ma') {
					$checkbox_fields[$row3['question_label']] = true;
				}
			}
			// Branching Logic: Before we get down to field level, first get all branching logic
			$branching_array = array();
			$branching_sqid_array = array();
			$sql = "select s.sq_id, s.question_label, if(a.operator=0,'or','and') as boolean, (select m.question_label from rs_survey_question{$meta['ext']} m 
					where m.sq_id = o.sq_id {$meta['mtimestamp']} limit 1) as field, replace(if(o.sq_operator is null or o.sq_operator = '', '=', if(left(o.sq_value, 1)='=', 
					concat(o.sq_operator, '='), o.sq_operator)), '==', '=') as operator, if(left(o.sq_value, 1)='=', 
					right(o.sq_value, length(o.sq_value)-1), o.sq_value) as value 
					from rs_survey_question{$meta['ext']} s, rs_question{$meta['ext']} q, rs_skip{$meta['ext']} a, rs_skip_operands{$meta['ext']} o 
					where q.question_id = s.question_id and s.survey_id = {$row['survey_id']} and s.skip_id != 0 
					and s.skip_id is not null and s.skip_id = a.skip_id and a.skip_id = o.skip_id {$meta['stimestamp']}
					{$meta['qtimestamp']} {$meta['atimestamp']} {$meta['otimestamp']} order by s.q_order";
			$q3 = mysql_query($sql, $rs_conn);
			while ($row3 = mysql_fetch_assoc($q3))
			{
				// Field's sq_id
				$this_sq_id = $row3['sq_id'];
				// Format source field and field in BL
				$row3['question_label'] = formatVar($row3['question_label']);
				// Make sure variable is not participant_id (if so, change it to prevent confliction)
				if ($row3['question_label'] == "participant_id") $row3['question_label'] .= "_rs";
				$row3['field'] = formatVar($row3['field']);
				// Set up this equation (do different syntax for checkbox fields)
				if (isset($checkbox_fields[$row3['field']])) {
					$this_eqn = "[{$row3['field']}({$row3['value']})] = \"1\"";
				} else {
					// Add quotes around values, unless using numerical
					if (strpos($row3['operator'], '>') === false && strpos($row3['operator'], '<') === false) {
						$row3['value'] = '"' . $row3['value'] . '"';
					}
					$this_eqn = "[{$row3['field']}] {$row3['operator']} {$row3['value']}";
				}
				// Add to branching_array
				if (!isset($branching_array[$row3['question_label']]) && $row3['question_label'] != "") {
					$branching_array[$row3['question_label']] = $this_eqn;
				} elseif ($row3['question_label'] != "") {
					$branching_array[$row3['question_label']] .= " {$row3['boolean']} $this_eqn";
				} else {
					// This is a descriptive field with no variable name, so store in separate array to retrieve later
					if (!isset($branching_sqid_array[$this_sq_id])) {
						$branching_sqid_array[$this_sq_id] = $this_eqn;
					} else {
						$branching_sqid_array[$this_sq_id] .= " {$row3['boolean']} $this_eqn";
					}
				}
			}
			// Stop Actions: Before we get down to field level, first get all stop actions
			$stop_actions_array = array();
			$sql = "select s.question_label, a.action_trigger 
					from rs_survey_question{$meta['ext']} s, rs_question{$meta['ext']} q, rs_action{$meta['ext']} a 
					where q.question_id = s.question_id and s.survey_id = {$row['survey_id']} and s.action_exist != 0 and s.sq_id = a.sq_id 
					{$meta['stimestamp']} {$meta['qtimestamp']} {$meta['atimestamp']} order by s.q_order, a.action_trigger";
			$q3 = mysql_query($sql, $rs_conn);
			while ($row3 = mysql_fetch_assoc($q3))
			{
				// Format question label
				$row3['question_label'] = formatVar($row3['question_label']);
				// Make sure variable is not participant_id (if so, change it to prevent confliction)
				if ($row3['question_label'] == "participant_id") $row3['question_label'] .= "_rs";
				if (!isset($stop_actions_array[$row3['question_label']])) {
					$stop_actions_array[$row3['question_label']] = $row3['action_trigger'];
				} else {
					$stop_actions_array[$row3['question_label']] .= ",{$row3['action_trigger']}";
				}
			}
			// Capture all edoc fields and also date fields in array for later
			$date_fields = array();
			$edoc_fields = array();
			// LOOP THROUGH ALL SURVEY QUESTIONS
			$sql = "select * from rs_survey_question{$meta['ext']} s, rs_question{$meta['ext']} q where q.question_id = s.question_id 
					and s.survey_id = {$row['survey_id']} {$meta['stimestamp']} {$meta['qtimestamp']} order by s.q_order";
			$q2 = mysql_query($sql, $rs_conn);
			$num_questions = mysql_num_rows($q2);
			$this_qnum = 0;
			$fieldsAlreadyExist = array();
			while ($row2 = mysql_fetch_assoc($q2))
			{
				// Increment counter
				$this_qnum++;
				// Keep sq_id for later
				$sq_id = $row2['sq_id'];			
				// Get field type
				$field_type = (isset($field_types[$row2['question_type']]) ? $field_types[$row2['question_type']] : $row2['question_type']);	
				// Manually provide descriptive/attachment fields with a random field_name
				if ($field_type == "descriptive") {
					// Create new variable name by default
					$field_name = "descriptive_" . substr(md5(rand()), 0, 14);
					// If we're on Draft Mode loop of metadata
					if ($meta['metadata_table'] == 'redcap_metadata') 
					{
						// Store in array
						$descriptive_var[$sq_id] = $field_name;
					}
					// If we're on Draft Mode loop of metadata
					elseif ($meta['metadata_table'] == 'redcap_metadata_temp' && isset($descriptive_match[$sq_id])) 
					{
						// Get field_name from array from last loop
						$field_name = $descriptive_var[$descriptive_match[$sq_id]];
					}
				}
				// If field is a section header, capture the text and hold it for next loop
				elseif ($field_type == "section_header") 
				{
					if ($this_qnum == $num_questions) {
						// If last field is a section header, convert it to a descriptive field and give it a variable name
						$field_name = "descriptive_" . substr(md5(rand()), 0, 14);
						$field_type = "descriptive";
						if ($section_header_text != "") {
							// Prepend any left-over section headers to the label
							$row2['question_description'] = "$section_header_text<hr>".$row2['question_description'];
							$section_header_text = "";
						}
					} else {
						if ($section_header_text == "") {
							$section_header_text = $row2['question_description'];
						} else {
							// If multiple section headers in a row, then just pile them all together and separate with <hr> tag (closest thing)
							$section_header_text .= "<hr>".$row2['question_description'];
						}
						continue; // go to next loop so we can add it to next field
					}
				}
				// Set field name
				else {
					// Make sure field name is trimmed and contains only letters, #s, and _s
					$field_name = formatVar($row2['question_label']);					
					// Add edoc fields to array for later checking
					if ($field_type == "file") {
						$edoc_fields[$field_name] = true;
					}
				}
				// Make sure field_name is not blank
				if ($field_name == '') {
					$field_name = "question_" . substr(md5(rand()), 0, 14);
				}
				// Make sure variable is not participant_id (if so, change it to prevent confliction)
				elseif ($field_name == "participant_id") {
					$field_name .= "_rs";
				}
				// Place field name in array to ensure uniqueness. If there is duplication, rename it
				if (isset($fieldsAlreadyExist[$field_name])) {
					if (strlen($field_name) > 18) $field_name = substr($field_name, 0, 18);
					$field_name .= "_" . substr(md5(rand()), 0, 7);
				} else {
					$fieldsAlreadyExist[$field_name] = true;
				}
				// Get MC choice values
				$enum = "";
				if (in_array($field_type, $mc_field_types) && isset($mc_choices[$field_name])) {
					// Build list of choices in REDCap format
					$enum_array = array();
					foreach ($mc_choices[$field_name] as $this_code=>$this_label) {
						$enum_array[] = trim($this_code) . ", " . trim($this_label);
					}
					$enum = implode(' \\n ', $enum_array);
					unset($enum_array);
				}
				// Validation type
				if ($field_type == "text") {
					$val_checktype = "'soft_typed'";
					$val_min = $row2['validation_min'];
					$val_max = $row2['validation_max'];
					// Convert validation
					switch ($row2['validation']) {
						case 1: $val_type = "'int'"; break;
						case 2: $val_type = "'float'"; break;
						case 3: $val_type = "'zipcode'"; break;
						case 4: $val_type = "'phone'"; break;
						case 5: $val_type = "'email'"; break;
						default: $val_type = "NULL";
					}
					// Convert date field validation
					if ($row2['question_type'] == "date") {
						$val_type = "'date'";
						// Put field in array
						$date_fields[$field_name] = true;
					}
				} elseif ($field_type == "slider") {
					$val_type = ($row2['slider_val_disp']) ? "'number'" : "NULL";
					$val_checktype = "NULL";
					$val_min = "";
					$val_max = "";
					// Slider labels
					$enum = $row2['slider_min'];
					if ($row2['slider_mid'] != "") $enum .= " | " . $row2['slider_mid'];
					if ($row2['slider_max'] != "") $enum .= " | " . $row2['slider_max'];
				} else {
					$val_type = "NULL";
					$val_checktype = "NULL";
					$val_min = "";
					$val_max = "";
				}
				// Make sure no checkbox fields are set as required (could happen because of bug)
				if ($field_type == "checkbox") $row2['required_field'] = 0;
				// Question layout
				$custom_alignment = ($row2['question_layout'] == "1" || $field_type == "textarea") ? "LH" : "";			
				// Branching logic
				if ($field_type == "descriptive") {
					$branching_logic = isset($branching_sqid_array[$sq_id]) ? $branching_sqid_array[$sq_id] : (isset($branching_array[$field_name]) ? $branching_array[$field_name] : "");
				} else {
					$branching_logic = isset($branching_array[$field_name]) ? $branching_array[$field_name] : "";
				}
				// Stop actions
				$stop_actions = isset($stop_actions_array[$field_name]) ? $stop_actions_array[$field_name] : "";			
				// Edoc image/file attachment
				$edoc_id = "NULL";
				if ($row2['question_type'] == "download" && $row2['file_download_id'] != "") {
					// Get edoc id
					$edoc_id = "(select redcap_edoc_id from redcap_temp_edocs where rs_edoc_id = {$row2['file_download_id']} limit 1)";
				}			
				// Form menu name
				if ($field_order == 1) 
				{
					// Add participant_id field (tacked on as first field)
					$migString .= "insert into {$meta['metadata_table']} (project_id, field_name, form_name, form_menu_description, field_order, element_type, element_label, element_validation_checktype) "
						. "values (@project_id, 'participant_id', '$form_name', ".checkNull2($row['survey_title']).", ".($field_order++).", 'text', 'Participant ID', 'soft_typed');\r\n";
				}
				// Insert field into table
				$migString .= "insert into {$meta['metadata_table']} (project_id, field_name, form_name, field_order, element_preceding_header, element_type, element_label, element_enum, element_validation_type, element_validation_min, element_validation_max, element_validation_checktype, branching_logic, field_req, edoc_id, edoc_display_img, custom_alignment, stop_actions, question_num) "
					. "values (@project_id, '$field_name', '$form_name', ".($field_order++).", ".checkNull2($section_header_text).", '$field_type', '".prep2($row2['question_description'])."', ".checkNull2($enum).", $val_type, ".checkNull2($val_min).", ".checkNull2($val_max).", $val_checktype, ".checkNull2($branching_logic).", {$row2['required_field']}, $edoc_id, {$row2['file_download_img_display']}, ".checkNull2($custom_alignment).", ".checkNull2($stop_actions).", ".checkNull2($row2['question_num']).");\r\n";
				// Reset for next loop
				$section_header_text = "";		
			}
			// Add form status field
			$migString .= "insert into {$meta['metadata_table']} (project_id, field_name, form_name, field_order, element_type, element_label, element_enum, element_preceding_header) "
				. "values (@project_id, '{$form_name}_complete', '$form_name', ".($field_order++).", 'select', 'Complete?', '0, Incomplete \\\\n 1, Unverified \\\\n 2, Complete', 'Form Status');\r\n";
		}
		// User-rights-level
		$migString .= "-- User-rights-level\r\n";
		$q3 = mysql_query("select * from rs_permissions where survey_id = {$row['survey_id']}", $rs_conn);
		while ($row3 = mysql_fetch_assoc($q3))
		{
			// Since the rights values are '2' for full access ('1' is not used), set them all as boolean by dividing all by 2
			$row3['level_s'] = $row3['level_s']/2;
			$row3['level_p'] = $row3['level_p']/2;
			$row3['level_a'] = $row3['level_a']/2;
			$row3['level_r'] = $row3['level_r']/2;
			// Set rights for non-RS modules
			$rights_reports = ($row3['level_p'] || $row3['level_r']) ? '1' : '0';
			$rights_data_entry = ($row3['level_p'] || $row3['level_r']) ? '1' : '0';
			$rights_logging = ($row3['level_a'] || $row3['level_r']) ? '1' : '0';
			// Insert into user_rights table using REDCap equivalent rights (more or less)
			$migString .= "insert into redcap_user_rights (project_id, username, participants, data_export_tool, data_import_tool, data_comparison_tool, data_logging, file_repository, user_rights, data_access_groups, graphical, reports, design, calendar, data_entry, record_create, data_quality_design, data_quality_execute) "
				. "values (@project_id, '".prep2($row3['userid'])."', {$row3['level_p']}, {$row3['level_r']}, {$row3['level_p']}, 0, $rights_logging, {$row3['level_r']}, {$row3['level_a']}, {$row3['level_a']}, {$row3['level_r']}, $rights_reports, {$row3['level_s']}, 1, '[$form_name,$rights_data_entry]', {$row3['level_p']}, {$row3['level_a']}, {$row3['level_a']});\r\n";
			// Set end-survey email triggers
			if ($row3['level_e']) {
				$migString .= "insert into redcap_actions (project_id, survey_id, action_trigger, action_response, recipient_id) "
					. "values (@project_id, @survey_id, 'ENDOFSURVEY', 'EMAIL', (select ui_id from redcap_user_information where username = '".prep2($row3['userid'])."' limit 1));\r\n";
			}
		}
		// Survey-participants level (add row for public survey)
		$migString .= "set @hash1 = left(md5(rand()),6);\r\n";
		$migString .= "set @hash2 = left(md5(rand()),6);\r\n";
		$migString .= "set @hash1exists = (select hash from redcap_surveys_participants where hash = @hash1 limit 1);\r\n";
		$migString .= "insert into redcap_surveys_participants (survey_id, event_id, hash, legacy_hash) values (@survey_id, @event_id, if(@hash1exists is null,@hash1,@hash2), '{$row['survey_md5']}');\r\n";
		$migString .= "set @participant_id_public = LAST_INSERT_ID();\r\n";
		// Get participants
		mysql_select_db($rs_db, $rs_conn);
		$sql = "select p.participant_id, p.email, if(p.identifier='click here to add',NULL,p.identifier) as identifier, s.email_survey_md5 
				from rs_participants p, rs_survey_participants s 
				where s.participant_id = p.participant_id and s.survey_id = {$row['survey_id']} order by p.email, p.email_num";
		$q3 = mysql_query($sql, $rs_conn);
		while ($row3 = mysql_fetch_assoc($q3))
		{
			// Make sure email_survey_md5 is not duplicated
			if (isset($email_md5[$row3['email_survey_md5']])) {
				$row3['email_survey_md5'] = md5(rand());
			}
			$migString .= "set @hash1 = left(md5(rand()),6);\r\n";
			$migString .= "set @hash2 = left(md5(rand()),6);\r\n";
			$migString .= "set @hash1exists = (select hash from redcap_surveys_participants where hash = @hash1 limit 1);\r\n";
			$migString .= "insert into redcap_surveys_participants (survey_id, event_id, hash, legacy_hash, participant_email, participant_identifier) "
				. "values (@survey_id, @event_id, if(@hash1exists is null,@hash1,@hash2), '{$row3['email_survey_md5']}', ".checkNull2($row3['email']).", ".checkNull2($row3['identifier']).");\r\n";
			// Add to participant temp table
			$migString .= "insert into redcap_temp_participants values (LAST_INSERT_ID(), {$row3['participant_id']});\r\n";
			// Add to array 
			$email_md5[$row3['email_survey_md5']] = true;
		}
		// Get response times from all completed surveys (issue with joining tables, so must do separate)	
		$response_status_time = array();	
		mysql_select_db($rs_db, $rs_conn);
		$sql = "select participant_id, response_date from rs_response_status where survey_id = {$row['survey_id']}";
		$q3 = mysql_query($sql, $rs_conn);
		while ($row3 = mysql_fetch_assoc($q3))
		{
			$response_status_time[$row3['participant_id']] = $row3['response_date'];			
		}
		// Survey-response/answers level
		// $sql = "select t.rt_id, p.participant_id, s.question_label, a.answer, p.email from rs_answers a, rs_survey_question s, 
				// rs_participants p, rs_response_track t where p.participant_id = t.participant_id and t.survey_id = s.survey_id 
				// and t.survey_id = {$row['survey_id']} and t.rt_id = a.rt_id and a.sq_id = s.sq_id order by t.rt_id, p.email, p.email_num";
		$sql = "select t.rt_id, t.participant_id, s.question_label, a.answer, p.email 
				from rs_answers a, rs_survey_question s, rs_response_track t 
				left join rs_participants p on p.participant_id = t.participant_id 
				where t.survey_id = s.survey_id and t.survey_id = {$row['survey_id']} and t.rt_id = a.rt_id 
				and a.sq_id = s.sq_id order by t.rt_id, p.email, p.email_num";
		$q3 = mysql_query($sql, $rs_conn);
		$prev_rt_id = "0";
		$record = 0;
		$allReturnCodes = array(); // Collect all return codes in array to ensure uniqueness
		while ($row3 = mysql_fetch_assoc($q3))
		{
			// Get participant_id
			$participant_id = $row3['participant_id'];
			// Format question label
			$row3['question_label'] = formatVar($row3['question_label']);
			// Make sure variable is not participant_id (if so, change it to prevent confliction)
			if ($row3['question_label'] == "participant_id") $row3['question_label'] .= "_rs";
			// Get rt_id
			$rt_id = $row3['rt_id'];
			// See if we're beginning a new participant response
			if ($rt_id != $prev_rt_id)
			{	
				// Increment record name
				$record++;
				$migString .= "-- Importing record $record\r\n";
				// Get response date
				$row3['response_date'] = (isset($response_status_time[$participant_id]) ? $response_status_time[$participant_id] : "");
				// Default blank return code
				$return_code = "";
				// Get participant_id
				if (strpos($row3['email'], "@") !== false) {
					// Response from participant list
					$migString .= "set @participant_id = (select redcap_participant_id from redcap_temp_participants where rs_participant_id = $participant_id order by redcap_participant_id limit 1);\r\n";
					// Get return code, if applicable
					if ($row['save_and_return'] && $row3['response_date'] == "") {
						// For one-time surveys (via participant list), use substring of md5 of hash
						$q4 = mysql_query("select email_survey_md5 from rs_survey_participants where participant_id = $participant_id limit 1", $rs_conn);
						if (mysql_num_rows($q4) > 0) {
							$return_code = substr(md5(mysql_result($q4, 0)), 4, 8);
						}
					}
				} else {
					// Response from public survey
					$migString .= "set @participant_id = @participant_id_public;\r\n";
					// Get return code, if applicable
					if ($row['save_and_return'] && $row3['response_date'] == "") {
						// For public surveys, code is the "email" for the "participant"
						$return_code = $row3['email'];
					}
				}
				// Ensure return code is unique
				if ($return_code != "") {
					if (isset($allReturnCodes[$return_code])) {
						// Create new one
						do {
							// Generate a new random hash
							$return_code = strtolower(generateRandomHash(8));
						} while (isset($allReturnCodes[$return_code]));
					}
					// Put in array
					$allReturnCodes[$return_code] = true;
				}
				// Add to response table
				$migString .= "insert into redcap_surveys_response (participant_id, record, first_submit_time, completion_time, return_code) "
					. "values (@participant_id, '$record', ".checkNull2($row3['response_date']).", ".checkNull2($row3['response_date']).", ".checkNull2($return_code).");\r\n";
				// Add participant_id field in data table
				$migString .= "insert into redcap_data values (@project_id, @event_id, '$record', 'participant_id', '$record');\r\n";
				// Add form status value
				$form_status = ($row3['response_date'] == "") ? "0" : "2";
				$migString .= "insert into redcap_data values (@project_id, @event_id, '$record', '{$form_name}_complete', '$form_status');\r\n";
			}
			// Add current data point to data table
			if (isset($date_fields[$row3['question_label']])) {
				// If a date field, then convert format
				if (substr_count($row3['answer'], '/') == 2) {
					list ($month, $day, $year) = explode('/', $row3['answer']);
					if (strlen($year) == 2 && is_numeric($year)) {
						$year = (($year > 20) ? "19" : "20").$year;
					}
					$row3['answer'] = "'".sprintf("%04d-%02d-%02d", $year, $month, $day)."'";
				} elseif (substr_count($row3['answer'], '-') == 2) {
					list ($month, $day, $year) = explode('-', $row3['answer']);
					if (strlen($year) == 2 && is_numeric($year)) {
						$year = (($year > 20) ? "19" : "20").$year;
					}
					$row3['answer'] = "'".sprintf("%04d-%02d-%02d", $year, $month, $day)."'";
				} elseif (is_numeric($row3['answer'])) {
					if (strlen($row3['answer']) == 4) {
						$month = substr($row3['answer'], 0, 1);
						$day   = substr($row3['answer'], 1, 1);
						$year  = substr($row3['answer'], 2, 2);
						$year = (($year > 20) ? "19" : "20").$year;
						$row3['answer'] = "'".sprintf("%04d-%02d-%02d", $year, $month, $day)."'";
					} elseif (strlen($row3['answer']) == 5) {
						$month = substr($row3['answer'], 0, 1);
						$day   = substr($row3['answer'], 1, 2);
						$year  = substr($row3['answer'], 3, 2);
						$year = (($year > 20) ? "19" : "20").$year;
						$row3['answer'] = "'".sprintf("%04d-%02d-%02d", $year, $month, $day)."'";
					} elseif (strlen($row3['answer']) == 6) {
						$month = substr($row3['answer'], 0, 2);
						$day   = substr($row3['answer'], 2, 2);
						$year  = substr($row3['answer'], 4, 2);
						$year = (($year > 20) ? "19" : "20").$year;
						$row3['answer'] = "'".sprintf("%04d-%02d-%02d", $year, $month, $day)."'";
					} elseif (strlen($row3['answer']) == 7) {
						$month = substr($row3['answer'], 0, 1);
						$day   = substr($row3['answer'], 1, 2);
						$year  = substr($row3['answer'], 3, 4);
						$row3['answer'] = "'".sprintf("%04d-%02d-%02d", $year, $month, $day)."'";
					} elseif (strlen($row3['answer']) == 8) {
						$month = substr($row3['answer'], 0, 2);
						$day   = substr($row3['answer'], 2, 2);
						$year  = substr($row3['answer'], 4, 4);
						$row3['answer'] = "'".sprintf("%04d-%02d-%02d", $year, $month, $day)."'";
					}
				} else {
					$row3['answer'] = "'".prep2($row3['answer'])."'";
				}
			} elseif (isset($edoc_fields[$row3['question_label']])) {
				// If a "file" field value, get new redcap edoc_id
				$row3['answer'] = "(select redcap_edoc_id from redcap_temp_edocs where rs_edoc_id = {$row3['answer']} limit 1)";
			} else {
				$row3['answer'] = "'".prep2($row3['answer'])."'";
			}
			$migString .= "insert into redcap_data values (@project_id, @event_id, '$record', '{$row3['question_label']}', {$row3['answer']});\r\n";
			// Set for next loop
			$prev_rt_id = $rt_id;
		}
		// Survey-email level
		$migString .= "-- Survey-email-level\r\n";
		$sql = "select t.emt_id, p.participant_id, t.email_title, t.email_content, t.email_sender, t.email_sent 
				from rs_participants p, rs_survey_participants s, rs_email_recipients r, rs_email_track t 
				where s.participant_id = p.participant_id and s.survey_id = {$row['survey_id']} and t.survey_id = s.survey_id 
				and t.emt_id = r.emt_id and r.email_sentto = p.email and r.email_num = p.email_num order by r.ier_id";
		$q3 = mysql_query($sql, $rs_conn);
		$prev_email_id = "";
		while ($row3 = mysql_fetch_assoc($q3))
		{
			// Get email_id
			$email_id = $row3['emt_id'];
			// See if we're beginning a new email
			if ($email_id != $prev_email_id)
			{
				$migString .= "insert into redcap_surveys_emails (survey_id, email_subject, email_content, email_sender, email_sent) "
					. "values (@survey_id, ".checkNull2($row3['email_title']).", ".checkNull2($row3['email_content']).", (select ui_id from redcap_user_information where username = '".prep2($row3['email_sender'])."' limit 1), ".checkNull2($row3['email_sent']).");\r\n";
				$migString .= "set @email_id = LAST_INSERT_ID();\r\n";
			}		
			// Survey-email-participant level
			$migString .= "insert into redcap_surveys_emails_recipients (email_id, participant_id) values (@email_id, (select redcap_participant_id from redcap_temp_participants where rs_participant_id = {$row3['participant_id']} limit 1));\r\n";
			// Set for next loop
			$prev_email_id = $email_id;
		}
		// Project checklist
		$migString .= "-- Add project checklist items\r\n";
		$migString .= "insert into redcap_project_checklist (project_id, name) values (@project_id, 'modify_project');\r\n";
		$migString .= "insert into redcap_project_checklist (project_id, name) values (@project_id, 'setup_survey');\r\n";
		if ($status > 0) {
			// For those in production, set some other checklist items as checked off
			$migString .= "insert into redcap_project_checklist (project_id, name) values (@project_id, 'triggers_notifications');\r\n";
			$migString .= "insert into redcap_project_checklist (project_id, name) values (@project_id, 'user_rights');\r\n";			
		}
		// Extra line to separate projects
		$migString .= "\r\n";
	}
	
	return $migString;
}
















// Check if downloading one of the PHP redirection files
if (isset($_GET['file']) && !empty($_GET['file']))
{
	header('Pragma: anytextexeptno-cache', true);
	header("Content-type: application/octet-stream");
	if ($_GET['file'] == "indexrs") {
		header("Content-Disposition: attachment; filename=index.php");
		print '<?php header("HTTP/1.1 301 Moved Permanently"); header("Location: '.APP_PATH_WEBROOT_FULL.'");';
	} elseif ($_GET['file'] == "core") {
		header("Content-Disposition: attachment; filename=core.php");
		print '<?php header("HTTP/1.1 301 Moved Permanently"); header("Location: '.APP_PATH_WEBROOT_FULL.'");';	
	} elseif ($_GET['file'] == "indexsurveys") {
		header("Content-Disposition: attachment; filename=index.php");
		print '<?php header("HTTP/1.1 301 Moved Permanently"); header("Location: '.APP_PATH_WEBROOT_FULL.'surveys/?hash=".$_GET[\'hash\']);';	
	} elseif ($_GET['file'] == "migration_script") {
		$filename = "Survey_Migration_SQL_Scripts_".date('YmdHis').".txt";
		header("Content-Disposition: attachment; filename=$filename");
		// DB connect
		list ($rc_conn, $rs_conn, $rc_db, $rs_db) = db_connect_both();		
		$sql = "select script from redcap_migration_script where username = '".USERID."' order by id";
		mysql_select_db($rc_db, $rc_conn);
		$q = mysql_query($sql, $rc_conn);
		while ($row = mysql_fetch_assoc($q)) {
			print $row['script'];
		}
		mysql_free_result($q);
		unset($row);
	}
	exit;
}


// If requesting the migration script via ajax
if (isset($_GET['action']) && $_GET['action'] == 'ajax' && $isAjax && isset($_GET['num_batch']) && is_numeric($_GET['num_batch']))
{
	// DB connect
	list ($rc_conn, $rs_conn, $rc_db, $rs_db) = db_connect_both();
	// Get number of surveys
	mysql_select_db($rs_db, $rs_conn);
	$q = mysql_query("select count(1) from rs_survey", $rs_conn);
	$numSurveys = mysql_result($q, 0);
	// If on first batch, empty the table and add the beginning SQL as first row
	if ($_GET['num_batch'] == '1') {
		// Empty table
		mysql_select_db($rc_db, $rc_conn);
		mysql_query("delete from redcap_migration_script where username = '".USERID."'", $rc_conn);
		## Add first SQL
		// Get delimited list of project_ids that were previously imported
		$prevImportedSurveys = pre_query("select project_id from redcap_projects where imported_from_rs = 1", $rc_conn);
		// Begin rendering script
		$script  = "-- --- REDCAP SURVEY MIGRATION SCRIPT - IMPORTING $numSurveys SURVEYS --- --\r\n\r\n";
		$script .= "-- Delete any previous migrations (will cause conflict if not removed first) --\r\n";
		if ($prevImportedSurveys != "''")
		{
			$script .= "delete from redcap_data where project_id in ($prevImportedSurveys);\r\n";
			$script .= "delete from redcap_edocs_metadata where project_id in ($prevImportedSurveys);\r\n";
			$script .= "delete from redcap_log_view where project_id in ($prevImportedSurveys);\r\n";
			$script .= "delete from redcap_log_event where project_id in ($prevImportedSurveys);\r\n";
		}
		$script .= "delete from redcap_projects where imported_from_rs = 1;\r\n";
		if (isVanderbilt() || SERVER_NAME == '10.151.18.250') {
			// Get max project_id (excluding any already imported surveys) for testing purposes only (makes testing easier)
			$q = mysql_query("select max(project_id)+1 from redcap_projects where imported_from_rs = 0", $rc_conn);
			$next_project_id = mysql_result($q, 0);
			$script .= "alter table redcap_projects auto_increment = $next_project_id;\r\n";
		}
		$script .= "\r\n-- Set up temp tables\r\n";
		$script .= "drop table if exists redcap_temp_participants; create table redcap_temp_participants (`redcap_participant_id` int( 10 ) null, `rs_participant_id` int( 10 ) null) engine = innodb character set utf8 collate utf8_unicode_ci; alter table `redcap_temp_participants` add unique (`redcap_participant_id`); alter table `redcap_temp_participants` add index (`rs_participant_id`);\r\n";
		$script .= "drop table if exists redcap_temp_edocs; create table redcap_temp_edocs (`redcap_edoc_id` int( 10 ) null, `rs_edoc_id` int( 10 ) null) engine = innodb character set utf8 collate utf8_unicode_ci; alter table `redcap_temp_edocs` add unique (`redcap_edoc_id`); alter table `redcap_temp_edocs` add unique (`rs_edoc_id`);\r\n";
		$script .= "\r\n";
		## User-level
		$script .= "-- User-level\r\n";
		// Get list of super users in RS
		$rs_super_users = array();
		mysql_select_db($rs_db, $rs_conn);
		$q = mysql_query("select userid from rs_superadmin", $rs_conn);
		while ($row3 = mysql_fetch_assoc($q))
		{
			$row3['userid'] = trim(strtolower($row3['userid']));
			$rs_super_users[$row3['userid']] = true;
		}
		// Import rs_auth table
		$script .= "-- Copy table-based users (ignore users already in redcap_auth table)\r\n";
		$redcap_auth_users = array();
		mysql_select_db($rc_db, $rc_conn);
		$q = mysql_query("select username from redcap_auth", $rc_conn);
		while ($row3 = mysql_fetch_assoc($q))
		{
			$row3['username'] = trim(strtolower($row3['username']));
			$redcap_auth_users[] = $row3['username'];
		}
		$rs_auth_users = array();
		mysql_select_db($rs_db, $rs_conn);
		$q = mysql_query("select * from rs_auth where username not in ('".implode("','",$redcap_auth_users)."')", $rs_conn);
		while ($row3 = mysql_fetch_assoc($q))
		{
			$row3['username'] = trim(strtolower($row3['username']));
			$rs_auth_users[] = $row3['username'];
			$script .= "insert into redcap_auth (username, password, temp_pwd) values ('".prep2($row3['username'])."', '".prep2($row3['password'])."', '".prep2($row3['temp_pwd'])."');\r\n";		
		}
		// Import rs_user_info table
		$script .= "-- Copy user information (ignore users already in redcap_user_information table)\r\n";
		$redcap_user_info = array();
		mysql_select_db($rc_db, $rc_conn);
		$q = mysql_query("select username from redcap_user_information", $rc_conn);
		while ($row3 = mysql_fetch_assoc($q))
		{
			$row3['username'] = trim(strtolower($row3['username']));
			$redcap_user_info[] = $row3['username'];
		}
		mysql_select_db($rs_db, $rs_conn);
		$q = mysql_query("select * from rs_user_info where username not in ('".implode("','",$redcap_user_info)."')", $rs_conn);
		while ($row3 = mysql_fetch_assoc($q))
		{
			$row3['username'] = trim(strtolower($row3['username']));
			$superUser = (isset($rs_super_users[$row3['username']]) ? '1' : '0');
			if ($auth_meth == "ldap_table") {
				// If using ldap+table, don't give project_create rights to table users
				$allowCreateDb = (!in_array($row3['username'], $rs_auth_users) && !in_array($row3['username'], $redcap_auth_users)) ? '1' : '0';
			} else {
				$allowCreateDb = 1;
			}
			$script .= "insert into redcap_user_information (username, user_email, user_firstname, user_lastname, super_user, allow_create_db) "
					. "values ('".prep2($row3['username'])."', '".prep2($row3['user_email'])."', '".prep2($row3['user_firstname'])."', '".prep2($row3['user_lastname'])."', $superUser, $allowCreateDb);\r\n";		
		}
		unset($redcap_user_info);
		unset($redcap_auth_users);
		$script .= "\r\n";	
		// Insert beginning SQL into table
		mysql_select_db($rc_db, $rc_conn);
		$q = mysql_query("insert into redcap_migration_script (script, username) values ('".prep($script)."', '".USERID."')", $rc_conn);
	}
	
	// Get count of any already-imported surveys, which will be deleted if script is re-run
	mysql_select_db($rc_db, $rc_conn);
	$q1 = mysql_query("insert into redcap_migration_script (script, username) values ('".prep(renderMigrationScript($_GET['num_batch'],$numSurveys))."', '".USERID."')", $rc_conn);
	$q1_error = mysql_error($rc_conn);
	
	// If on last batch, empty the table and add the beginning SQL as first row
	if ($_GET['num_batch'] == $_GET['total_batches']) 
	{
		$end_script = "-- Remove all temp tables and do clean-up\r\n"
					. "delete from redcap_surveys_response where participant_id is null;\r\n"
					. "drop table if exists redcap_temp_participants;\r\n"
					. "drop table if exists redcap_temp_edocs;\r\n"
					. "delete from redcap_migration_script where username = '".USERID."';\r\n";
		mysql_select_db($rc_db, $rc_conn);
		$q = mysql_query("insert into redcap_migration_script (script, username) values ('".prep($end_script)."', '".USERID."')", $rc_conn);
	}
	if ($q1) {
		print '1';
	} else {
		print $q1_error."\n\nOTHER INFO:\nREDCap db: $rc_db, conn: $rc_conn\nRS db: $rs_db, conn: $rs_conn";
	}
	exit;
}


// Get timestamp
$timestamp = date("_Y_m_d_H_i_s");
// Get time of auto logout from redcap_config
if ($autologout_timer == "" || $autologout_timer == "0" || $autologout_timer == "1440") {
	$autologout_timer = "30";
}

// Initialize page display object
$objHtmlPage = new HtmlPage();
$objHtmlPage->addExternalJS(APP_PATH_JS . "base.js");
$objHtmlPage->addStylesheet("smoothness/jquery-ui-".JQUERYUI_VERSION.".custom.css", 'screen,print');
$objHtmlPage->addStylesheet("style.css", 'screen,print');
$objHtmlPage->addStylesheet("home.css", 'screen,print');
$objHtmlPage->PrintHeader();
/******************************************************************************/


?>
<table width=100% cellpadding=0 cellspacing=0>
	<tr>
		<td valign="top" style="padding:20px 0;font-size:20px;font-weight:bold;color:#800000;">
			REDCap Module for Migrating Surveys from REDCap Survey
		</td>
		<td valign="top" style="text-align:right;padding-top:5px;">
			<img src="<?php echo APP_PATH_IMAGES ?>redcaplogo_small.gif">
		</td>
	</tr>
</table>
<?php


// Make sure parameter 'step' is numeric, if exists
if (isset($_GET['step']) && !is_numeric($_GET['step'])) unset($_GET['step']);



if (!isset($_GET['step']))
{
	?>

	<p>
		This module can be run any time after upgrading to REDCap 4.0. It allows you to easily migrate existing surveys
		from a REDCap Survey installation into your REDCap 4.0 installation. Once completed, you will want to keep your
		REDCap Survey installation offline but keep all its files in the same place on the web server, which will allow
		existing survey participants to be redirected from the old installation to REDCap 4.0.<br><br>
		<b>Dependencies:</b> REDCap 4.0, REDCap Survey 1.3.10
	</p>

	<p style="margin:25px 0;padding-top:12px;border-top:1px solid #aaa;">
		<b>1.) PREPARATION:</b><br>
		Approximately <?php echo $autologout_timer ?> minutes before performing the survey migration, go to the Control Center 
		<b>in both REDCap and REDCap Survey</b> and set both of their System Status as "System Offline", 
		which will take both systems offline and allow users 
		to save any data before exiting. (When done with the migration, you will need to bring REDCap back online again.)
	</p>

	<p style="margin:25px 0;padding-top:12px;border-top:1px solid #aaa;">
		<b>2.) CONNECTING TO REDCAP SURVEY:</b><br>
		After REDCap and REDCap Survey (RS) have both been offline for <?php echo $autologout_timer ?> minutes, you are ready to begin
		setting up the migration process. REDCap needs to communicate with the MySQL database server where the RS tables
		are stored. To easily do this, A) find the "database.php" file on the web server of your RS installation 
		(located at .../redcap_survey/database.php), B) copy it and rename it to "database_rs.php", and then 
		C) place it in the main REDCap directory on your REDCap web server (i.e. <?php echo dirname(dirname(dirname(__FILE__))) . DS ?>).
		<b>Do NOT overwrite your existing "database.php" file for REDCap when doing this.</b>
		<br><br>
		If your database connection values are not
		found inside the "database_rs.php" file, you may be including the values from another file (look for the word "include" inside
		"database_rs.php"), in which case you'll need to retrieve them from the included file and copy and paste directly into
		"database_rs.php".
		<br><br>
		<b>Looking for "database_rs.php"... </b>
		<?php
		unset($hostname);
		unset($username);
		unset($password);
		unset($db);
		$rs_conn_file = dirname(dirname(dirname(__FILE__))) . DS . "database_rs.php";
		if (include dirname(dirname(dirname(__FILE__))) . DS . "database_rs.php") {
			print "Found!";
		} else {
			print "NOT FOUND! Please make sure you placed the file at the location " . dirname(dirname(dirname(__FILE__))) . DS;
			endpage();
		}
		?>
		<br><br>
		<b>Attempting connection to REDCap Survey's MySQL database... </b>
		<?php
		if ((!isset($hostname) || !isset($db) || !isset($username) || !isset($password))) {
			endpage("FAILED! There is not a valid hostname ($hostname) / database ($db) / username ($username) / 
				password (XXXXXX) combination in your database connection file [$rs_conn_file].");
		}
		$rs_conn = mysql_connect($hostname, $username, $password);
		if (!$rs_conn) {
			endpage("FAILED! The hostname ($hostname) / username ($username) / password (XXXXXX) 
				combination in your database connection file [$rs_conn_file] could not connect to the server. Please check their values."); 
		}
		if (!mysql_select_db($db, $rs_conn)) {
			endpage("FAILED! The hostname ($hostname) / database ($db) / username ($username) / 
				password (XXXXXX) combination in your database connection file [$rs_conn_file] could not connect to the server. Please check their values."); 
		}
		$rs_db = $db;		
		// Connect to REDCap
		$rc_conn_file = dirname(dirname(dirname(__FILE__))) . DS . "database.php";
		include $rc_conn_file;
		$rc_conn = mysql_connect($hostname, $username, $password, true);
		mysql_select_db($db, $rc_conn);
		$rc_db = $db;
		?>
		Success!
		<br><br>
		<b>Determining REDCap Survey version... </b>
		<?php
		mysql_select_db($rs_db, $rs_conn);
		if (empty($rs_conn) || !$rs_conn) endpage("Somehow lost connection to REDCap Survey's MySQL database.");
		$q = mysql_query("select rs_version from rs_config", $rs_conn);
		if (!$q) {
			print " (MySQL error: ".mysql_error($rs_conn).") ";
			endpage("Failed! Could not find the 'rs_config' table.");
		}
		$rs_version = mysql_result($q, 0);	
		print $rs_version;
		// Determine if version is 1.3.10 or higher
		if ((getDecVersion($rs_version)*1) < 10310) endpage(" Failed! REDCap Survey must be on version 1.3.10 or higher. Please upgrade it and then return here to refresh this page.");
		?>
		Success!
	</p>

	<p style="margin:25px 0;padding-top:12px;border-top:1px solid #aaa;">
		<b>3.) CHECKING FOR USER DUPLICATES AND CONFLICTS:</b><br>
		All users in REDCap Survey will be migrated into REDCap with their username as it currently is in REDCap Survey.
		If they exist in both applications already, then their values in REDCap will be maintained and will not be overwritten
		by their values from REDCap Survey,	e.g. their login password (for table-based users only) or email address from REDCap
		will be kept.
	</p>
	<?php
	## Get list of table-based duplicates and compare their passwords
	$password_conflicts = array();
	$email_conflicts    = array();
	// Get table-based uses in both apps
	$redcap_auth_users = array();
	mysql_select_db($rc_db, $rc_conn);
	$q = mysql_query("select * from redcap_auth", $rc_conn);
	while ($row3 = mysql_fetch_assoc($q))
	{
		$row3['username'] = strtolower($row3['username']);
		$redcap_auth_users[$row3['username']] = $row3['password'];
	}		
	$rs_auth_users = array();
	mysql_select_db($rs_db, $rs_conn);
	$q = mysql_query("select * from rs_auth", $rs_conn);
	while ($row3 = mysql_fetch_assoc($q))
	{
		$row3['username'] = strtolower($row3['username']);
		$rs_auth_users[$row3['username']] = $row3['password'];
	}
	// Loop through RS users and note any password conflicts
	foreach ($rs_auth_users as $user=>$pass)
	{
		if (isset($redcap_auth_users[$user]) && $pass != $redcap_auth_users[$user])
		{
			$password_conflicts[] = $user;
		}		
	}
	// Get user_info
	$redcap_user_info = array();
	
	mysql_select_db($rc_db, $rc_conn);
	$q = mysql_query("select * from redcap_user_information where username != 'site_admin'", $rc_conn);
	while ($row3 = mysql_fetch_assoc($q))
	{
		$row3['username'] = strtolower($row3['username']);
		$redcap_user_info[$row3['username']] = strtolower($row3['user_email']);
	}
	$rs_user_info = array();	
	mysql_select_db($rs_db, $rs_conn);
	$q = mysql_query("select * from rs_user_info where username != 'site_admin'", $rs_conn);
	while ($row3 = mysql_fetch_assoc($q))
	{
		$row3['username'] = strtolower($row3['username']);
		$rs_user_info[$row3['username']] = strtolower($row3['user_email']);
	}
	// Loop through RS users and note any email conflicts
	foreach ($rs_user_info as $user=>$email)
	{
		if (isset($redcap_user_info[$user]) && $email != $redcap_user_info[$user])
		{
			$email_conflicts[] = $user;
		}		
	}
	// Give warning if any password conflicts exist
	if (!empty($password_conflicts))
	{
		?>
		<p class="yellow" style="margin:5px;">
			<img src="<?php echo APP_PATH_IMAGES ?>exclamation_orange.png" class="imgfix">
			<b>PASSWORD CONFLICTS:</b><br>The usernames listed below have password conflicts, in which their REDCap password for logging in
			is different from their REDCap Survey password. You may want to notify these users
			to remind them that their REDCap Survey password will no longer be used and to simply use their existing
			REDCap password for logging in to access their surveys from now on.<br><br>
			Users with password conflicts: <span style="font-size:11px;">
			<?php 
			$user_email_string = array();
			foreach ($password_conflicts as $user)
			{
				$string = "<b>$user</b> (<span style='font-size:10px;color:#800000;padding:0 2px;'>";
				if (!isset($redcap_user_info[$user]) && !isset($rs_user_info[$user])) {
					$string .= "No email listed";
				}
				if (isset($redcap_user_info[$user])) {
					$string .= "{$redcap_user_info[$user]}</span>";
				}
				if (isset($redcap_user_info[$user]) && isset($rs_user_info[$user])) {
					$string .= "/<span style='font-size:10px;color:#800000;padding:0 2px;'>";
				}
				if (isset($rs_user_info[$user])) {
					$string .= "{$rs_user_info[$user]}";
				}
				$string .= "</span>)";
				$user_email_string[] = $string;
			}
			print implode(", ", $user_email_string);
			?></span>
		</p>
		<?php
	}
	// Give warning if any password conflicts exist
	if (!empty($email_conflicts))
	{
		?>
		<p class="red" style="margin:5px;">
			<img src="<?php echo APP_PATH_IMAGES ?>exclamation.png" class="imgfix">
			<b>POSSIBLE USERNAME DUPLICATION:</b><br>The usernames listed below have email conflicts, in which their 
			email address listed in REDCap is different from their email address from REDCap Survey. 
			This may imply that these are actually different users who by happenstance have the same username.
			You may want to investigate these to ensure that they are indeed the same user <u>before you continue the REDCap Survey migration</u>.
			If they are actually different users with the same username, you should probably create one of them a different username
			and delete their current one in order to eliminate this duplication. If you are having trouble,
			you may contact <a href="mailto:rob.taylor@vanderbilt.edu">rob.taylor@vanderbilt.edu</a> for assistance.<br><br>
			Possible duplicates: <span style="font-size:11px;">
			<?php 
			$user_email_string = array();
			foreach ($email_conflicts as $user)
			{
				$user_email_string[] = "<b>$user</b> (<span style='font-size:10px;color:#333;padding:0 2px;'>{$redcap_user_info[$user]}</span>/<span style='font-size:10px;color:#333;padding:0 2px;'>{$rs_user_info[$user]})</span>";
			}
			print implode(", ", $user_email_string);
			?></span>
		</p>
		<?php
	}
	// Found nothing
	if (empty($email_conflicts) && empty($password_conflicts))
	{
		?>
		<p style="font-weight:bold;color:green;margin:5px;">
			No conflicts or duplicates were found.
		</p>
		<?php
	}
	?>

	<p style="margin:25px 0;padding-top:12px;border-top:1px solid #aaa;">
		<b>4.) READY TO BEGIN BUILDING MIGRATION SCRIPTS:</b><br>
		<?php if (!empty($email_conflicts) || !empty($password_conflicts)) { ?>
			<u>If you have resolved the issues from step 3</u>, then
		<?php } else { ?>
			All is good, so 
		<?php } ?>
		you are now ready to being the migration process. Once you click the button below, 
		this module will begin processing data from REDCap Survey and will build the migration scripts that you will then execute on the
		REDCap MySQL server	to migrate the REDCap Survey data into REDCap. NOTE: Clicking the button will NOT migrate the surveys automatically;
		you will have to execute the script that it provides before ANY survey data is merged into REDCap.
		ALSO, this process may take many minutes to complete, as the script it compiles may be very long, so please be patient until it finishes.
		<p style="text-align:center;">
			<button class="jqbutton" onclick="window.location.href=app_path_webroot+page+'?step=5';">Build survey migration scripts</button>
		</p>
	</p>
	
	<?php
}









// PAGE 2
if ($_GET['step'] == 5)
{
	// DB connect
	list ($rc_conn, $rs_conn, $rc_db, $rs_db) = db_connect_both();
	// Get count of any already-imported surveys, which will be deleted if script is re-run
	mysql_select_db($rc_db, $rc_conn);
	$q = mysql_query("select count(1) from redcap_projects where imported_from_rs = 1", $rc_conn);
	$count_surveys_already_imported = mysql_result($q, 0);	
	## Set up all temp tables and initialization scripts
	mysql_select_db($rs_db, $rs_conn);
	$q = mysql_query("select count(1) from rs_survey", $rs_conn);
	$numSurveys = mysql_result($q, 0);
	// Back button
	renderPrevPageBtn(PAGE,"START OVER");
	?>
	
	<p style="margin:10px 0 25px;padding-top:12px;border-top:1px solid #aaa;">
		<b>5.) EXECUTING THE MIGRATION SCRIPTS</b><br>
		This page will build the SQL migration scripts you will need for importing the REDCap Survey data into REDCap.
		Once the process has completed below, you will need to download the file containing the migration scripts,
		and execute the scripts on your MySQL database server in the same MySQL database where your REDCap tables are kept 
		(i.e. <b><?php echo $db ?></b>).<br><br>
		<b style="color:#800000;">IMPORTANT NOTICE:</b><br>
		<b>All imported surveys will be given the authentication method "<u><?php echo $auth_meth ?></u>"</b>, 
		which is your current default authentication method for new REDCap projects. 
		<?php if ($auth_meth == "ldap_table") { ?>
			Also, since you are using LDAP+Table-based authentication, please be aware that by default <b>all non-LDAP users (i.e. table-based)
			will <u>NOT</u> have the right to create new projects</b> in REDCap 4.0, although this setting can be reversed for each user
			individually on the Control Center's User Controls page after the migration process is completed.
		<?php } ?>
	</p>
	<?php if ($count_surveys_already_imported > 0) { ?>
		<p class="red">
			<b>NOTICE:</b> It appears that <?php echo $count_surveys_already_imported ?> surveys from REDCap Survey have already been imported
			at a previous time. Please note that <b>re-running the migration scripts provided here will DELETE ALL previously imported surveys</b>. 
		</p>
	<?php } ?>
	
	<script type="text/javascript">
	// Count surveys processed
	var survey_count = 0;
	// Function to make ajax requests to build migration SQL by using bunches of projects in multiple requests
	function migrateAjax(num_batch,total_batches) {
		$.get(app_path_webroot+page, { action: 'ajax', num_batch: num_batch, total_batches: total_batches }, function(data) {
			if (data != '1') {
				alert('ERROR!\n\nAn error occurred for unknown reasons. Please refresh this page to start the process over again.\n\nERROR RETURNED:\n'+data);
				return;
			}
			num_batch++;
			survey_count += <?php echo BATCH ?>;
			if (survey_count > <?php echo $numSurveys ?>) survey_count = <?php echo $numSurveys ?>;
			$('#send_progress').html(survey_count);
			$('#send_progress_percent').html(round((survey_count/<?php echo $numSurveys ?>*100),1));
			if (num_batch <= total_batches) {
				migrateAjax(num_batch,total_batches);
			} else {
				$('#progress_done').html('<img src="<?php echo APP_PATH_IMAGES ?>accept.png" class="imgfix"> <font color="green">The SQL migration scripts are ready for download! (click the button below to download)</font>');
				$('#download_script_btn').prop('disabled',false);
				$("#download_script_btn").removeClass('ui-button-disabled');
				$("#download_script_btn").removeClass('ui-state-disabled');
				$('#endtime').html(getLocalTime());
				alert('SUCCESS!\n\nThe scripts have successfully generated! Click the "Download migration scripts" button on the page to download the file. Then execute the contents of the file on your MySQL server.');
			}
		});
	}
	// Get the local time
	function getLocalTime() {
		var d = new Date();
		var curr_hour = d.getHours();
		var curr_min = d.getMinutes();
		return checkTime(curr_hour) + ":" + checkTime(curr_min);
	}
	function checkTime(i) {
		if (i<10) {
			i="0" + i;
		}
		return i;
	}
	$(function(){
		$('#starttime').html(getLocalTime());
		migrateAjax(1,<?php echo ceil($numSurveys/BATCH) ?>);
	});
	</script>
	
	<p style="text-align:right;margin:0;">
		Script start time: <span id="starttime"></span><br>
		Script end time: <span id="endtime">&nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; </span>
	</p>
	
	<!-- "Loading..." message -->
	<p style="font-weight:bold;">
		Generating SQL for survey <span id="send_progress">0</span> of <?php echo $numSurveys ?> 
		<span style="padding-left:8px;font-weight:normal;color:#800000;">(<span id="send_progress_percent">0</span>% Done)</span>
	</p>
	<div id="progress_done" style="font-weight:bold;color:#444;">
		<img src="<?php echo APP_PATH_IMAGES ?>progress_circle.gif" class="imgfix"> 
		Please wait... (this may take a while)
	</div>
			
	<p style="margin:20px 0 40px;">
		<button disabled id="download_script_btn" class="jqbutton" onclick="
			$('#nextstep_btn').prop('disabled',false);
				$('#nextstep_btn').removeClass('ui-button-disabled');
				$('#nextstep_btn').removeClass('ui-state-disabled');
				window.location.href=app_path_webroot+page+'?file=migration_script';
		">Download migration scripts</button>
	</p>
			
	<p style="text-align:center;">
		<button disabled id="nextstep_btn" class="jqbutton" onclick="
			if (confirm('DID YOU EXECUTE THE SQL?\r\n\r\nIf you have not executed the SQL in the box above, '
				+ 'please do so now. You should not proceed until it has been completed. Proceed to the next step?')) {
				window.location.href=app_path_webroot+page+'?step=6';
			}
		">Go to next step</button>
	</p>
	
	<?php
}


// PAGE 3
if ($_GET['step'] == 6)
{
	// DB connect
	list ($rc_conn, $rs_conn, $rc_db, $rs_db) = db_connect_both();
	// Get edoc count for RS
	mysql_select_db($rs_db, $rs_conn);
	$q = mysql_query("select count(1) from rs_edocs", $rs_conn);
	$num_rs_edocs = mysql_result($q, 0);
	// If at least 1 edoc file exists in RS
	if ($num_rs_edocs > 0)
	{
		// Get edoc path for RS
		$q = mysql_query("select * from rs_config", $rs_conn);
		$rs_configs = mysql_fetch_assoc($q);
		// Check is using WebDAV method or not
		if (!$rs_configs['edoc_storage_option']) {
			// Upload to "edocs" folder (use default or custom path for storage)
			$rs_configs['edoc_path'] = trim($rs_configs['edoc_path']);
			if ($rs_configs['edoc_path'] == "") {
				//Use default edocs folder
				$rs_edoc_path_full = "..." . DS . "redcap_survey" . DS . "edocs" . DS;
			} else {
				//Use custom edocs folder (set in Control Center)
				$rs_edoc_path_full = $rs_configs['edoc_path'] . ((substr($rs_configs['edoc_path'], -1) == "/" || substr($rs_configs['edoc_path'], -1) == "\\") ? "" : DS);
			}
		} else {
			// Using WebDAV storage method
			$rs_edoc_path_full = "check the values of \$webdav_hostname and \$webdav_path in the REDCap Survey file .../redcap_survey/webtools/webdav/webdav_connection.php";
		}
		// Calculate total new space needed for RS files (including those needing to be duplicated after being copied)
		$q = mysql_query("select round(sum(doc_size)/1024/1024,1) from rs_edocs", $rs_conn);
		$file_space_needed = mysql_result($q, 0);
		// Get REDCap edoc path (webdav or not)
		if (!$edoc_storage_option) {
			$redcap_edoc_path_full = "<b style='padding:0 3px;'>" . EDOC_PATH . "</b> (on REDCap web server)";
		} else {
			include APP_PATH_WEBTOOLS . 'webdav/webdav_connection.php';
			if (substr($webdav_path,-1) != '/') {
				$webdav_path .= '/';
			}
			$redcap_edoc_path_full = "<b style='padding:0 3px;'>{$webdav_hostname}{$webdav_path}</b> (on web server accessed via WebDAV method)";
		}
		?>	
		
		<p style="margin:25px 0;padding-top:12px;border-top:1px solid #aaa;">
			<b>6.) COPY ALL UPLOADED USER FILES</b><br>
			In REDCap Survey, users can upload files to be used as survey logos and question attachments, and participants can upload files for
			certain survey questions. These files exist on the web server that hosts REDCap Survey and must now be copied from their present
			location and placed into the file upload directory used by REDCap. <b>Follow steps A and B below</b>, and once done, click the button
			to proceed to the next page, where this module will check to see if the files
			were copied successfully and if any extra actions need to be taken.<br><br>		
			A.) Copy ALL the files from: <b style="padding:0 3px;"><?php echo $rs_edoc_path_full ?></b> (on REDCap Survey web server)<br><br>
			B.) Copy ALL the files to: <?php echo $redcap_edoc_path_full ?> 	
		</p>	
		<div class="yellow" style="margin-bottom:20px;">
			Please take note that <b><?php echo $file_space_needed ?> MB of space will be required</b> for the files being copied.
			You will need to first ensure that you have this much space that can be allocated to the location above for your REDCap web server.
		</div>	
		<p style="text-align:center;">
			<button class="jqbutton" onclick="window.location.href=app_path_webroot+page+'?step=7';">Check integrity of copied files</button>
		</p>
		
		<?php
	}
	// No edocs exist, so skip to step 9
	else
	{
		?>
		<p style="margin:25px 0;padding-top:12px;border-top:1px solid #aaa;">
			<b>6.) COPY ALL UPLOADED USER FILES</b><br>
			Since it appears that you do not have any uploaded files listed in your "rs_edocs" table, you can go ahead
			and move on to step 9.
		</p>
		<p style="text-align:center;">
			<button class="jqbutton" onclick="window.location.href=app_path_webroot+page+'?step=9';">Advance to step 9</button>
		</p>
		<?php
	}
}







// PAGE 3
if ($_GET['step'] == 7)
{
	// DB connect
	list ($rc_conn, $rs_conn, $rc_db, $rs_db) = db_connect_both();
	// Back button
	renderPrevPageBtn(PAGE,"START OVER");
	?>
	-- OR --
	<button class="jqbutton" onclick="window.location.href='/redcap/redcap_v4.0.0/Migrate/index.php?step=6';">
		<img src="<?php echo APP_PATH_IMAGES ?>arrow_left.png" class="imgfix"> 
		Go back to last step
	</button>
	<?php
	// Check first 10 files that were copied (if more than 10 exist)
	mysql_select_db($rc_db, $rc_conn);
	$sql = "select distinct m.stored_name from redcap_projects p, redcap_edocs_metadata m 
			where p.project_id = m.project_id and p.imported_from_rs = 1 order by doc_id desc limit 5";
	$q = mysql_query($sql, $rc_conn);
	$num_files = mysql_num_rows($q);
	$num_files_text = ($num_files == 5) ? "the <b>last 5 files</b>" : "<b>all $num_files files</b>";
	?>
	<p style="margin:25px 0 10px;padding-top:12px;border-top:1px solid #aaa;">
		<b>7.) CHECKING FILES COPIED FROM REDCAP SURVEY</b><br>
		This module will now check to ensure that the files were copied correctly in the last step.
		Below, it will check <?php echo $num_files_text ?> copied from REDCap Survey (as a quick sanity check) to ensure they can now 
		be found on the REDCap server.
	</p>
	<p>
		<?php
		// Loop through all files and check to see if we can find them
		if ($edoc_storage_option) {
			// WebDAV
			include_once(APP_PATH_CLASSES . "WebdavClient.php");
			include (APP_PATH_WEBTOOLS . 'webdav/webdav_connection.php');
			$wdc = new WebdavClient();
			$wdc->set_server($webdav_hostname);
			$wdc->set_port($webdav_port); $wdc->set_ssl($webdav_ssl);
			$wdc->set_user($webdav_username);
			$wdc->set_pass($webdav_password);
			$wdc->set_protocol(1); // use HTTP/1.1
			$wdc->set_debug(false); // enable debugging?
			if (!$wdc->open()) {
				sleep(1);
				endpage("<b>ERROR: Could not connect to WebDAV host ($webdav_hostname)!</b>");
			}
			if (substr($webdav_path,-1) != '/') {
				$webdav_path .= '/';
			}				
			print "Searching the directory <b>$webdav_path</b> on the server <b>$webdav_hostname</b> (connecting via WebDAV) ...<br><br>";
		} else {
			// Use local directory
			print "Searching the directory <b>".EDOC_PATH."</b> ...<br><br>";
		}
		$i = 1;
		$files_not_found = 0;
		while ($row = mysql_fetch_assoc($q))
		{
			print $i++.") Looking for file <b>{$row['stored_name']}</b>: ";
			if ($edoc_storage_option) {
				// WebDAV
				$file = $webdav_path . $row['stored_name'];
				if ($wdc->is_file($file)) {
					print "&nbsp;<span style='color:green;font-weight:bold;'>Found!</span>";
				} else {
					print "&nbsp;<span style='color:red;font-weight:bold;'>Count NOT find!</span>";
					$files_not_found++;
				}
			} else {
				// Use local directory
				$file = EDOC_PATH . DS . $row['stored_name'];
				if (is_file($file) && file_exists($file)) {
					print "&nbsp;<span style='color:green;font-weight:bold;'>Found!</span>";
				} else {
					print "&nbsp;<span style='color:red;font-weight:bold;'>Count NOT find!</span>";
					$files_not_found++;
				}
			}		
			print "<br>";
		}
		?>
	</p>
	<?php
	if ($files_not_found > 0) {
		print  "<p class='red'>
					<img src='".APP_PATH_IMAGES."exclamation.png' class='imgfix'>
					<b>NOTICE: Not all the files were found!</b><br>
					You may want to check to ensure that they all got copied over correctly.
					<u>If the files not found did not originally exist in your REDCap Survey directory (i.e. in the collection of files
					you just copied over to REDCap), then there isn't a problem, so you can go ahead to the next step</u>.
					If there was a file copying issue and you have resolved it, simply refresh this page, and it will check for the files again.
					If you cannot resolve this, you may still continue to the next step; however, if the files cannot be
					found on the server, then this may cause issues with some of the surveys imported since they will be
					missing those files, which may be used as question attachments or were uploaded by survey participants.
				</p>";
	} else {
		print  "<p class='darkgreen'>
					<img src='".APP_PATH_IMAGES."tick.png' class='imgfix'>
					<b>The files checked above were found!</b><br>
					It appears that the files from REDCap Survey got copied over correctly.
				</p>";
	}
	?>
	<p style="text-align:center;">
		<button class="jqbutton" onclick="window.location.href=app_path_webroot+page+'?step=8';">Go to next step</button>
	</p>
	<?php	
}


// PAGE 4
if ($_GET['step'] == 8)
{
	// DB connect
	list ($rc_conn, $rs_conn, $rc_db, $rs_db) = db_connect_both();
	// Back button
	renderPrevPageBtn(PAGE,"START OVER");
	?>
	-- OR --
	<button class="jqbutton" onclick="window.location.href='/redcap/redcap_v4.0.0/Migrate/index.php?step=7';">
		<img src="<?php echo APP_PATH_IMAGES ?>arrow_left.png" class="imgfix"> 
		Go back to last step
	</button>
	<p style="margin:25px 0 10px;padding-top:12px;border-top:1px solid #aaa;">
		<b>8.) CHECKING FOR UPLOADED FILES USED MORE THAN ONCE IN REDCAP SURVEY</b><br>
		This module will now check if there are any files that were copied from REDCap Survey that are
		being used for more than one survey or for more than one survey question at a time. (In REDCap Survey, if a survey
		question or entire survey is copied, the uploaded file associated with that survey or question does NOT get copied on the web server,
		but only the reference to the file in a database table is copied. However, in REDCap 4.0, if a field or entire project is copied, the file gets 
		actually copied on the server, resulting in two files. This allows each file to be deleted independently, if needed.) <br><br>
		If there are any files from REDCap Survey that were used more than once, they will need to
		be copied right now so that each file is a separate instance. This is already being done automatically. The results are below.		
	</p>
	<?php	
	// Check for any files being used more than once in edocs table
	$sql = "select m.doc_id, m.stored_name, p.project_id from redcap_projects p, redcap_edocs_metadata m where p.project_id = m.project_id 
			and p.imported_from_rs = 1 and m.stored_name in (" .  
				pre_query("select x.stored_name from (select m.stored_name, count(m.stored_name) as thiscount 
				from redcap_projects p, redcap_edocs_metadata m where p.project_id = m.project_id and p.imported_from_rs = 1 
				group by m.stored_name) as x where x.thiscount > 1", $rc_conn) . "
			) order by m.stored_name";
	$files_to_copy = array();
	mysql_select_db($rc_db, $rc_conn);
	$q = mysql_query($sql, $rc_conn);
	if (mysql_num_rows($q) > 0)
	{
		$i = 1;
		$file_copy_errors = 0;
		$manual_copy = array();
		while ($row = mysql_fetch_assoc($q))
		{
			// For each file, get their edoc_id's and (except for the first instance), copy the file on the server 
			// and update its stored_name with the new filename generated after being copied.
			if (!isset($files_to_copy[$row['stored_name']]))
			{
				// Ignore this first instance of the file and place name in array
				$files_to_copy[$row['stored_name']] = true;
			}
			// Copy the file
			else
			{
				// Copy the file, which creates a new row in edoc table
				$edoc_id = copyFile($row['doc_id'], $row['project_id']);
				$isError = true; //default
				if (is_numeric($edoc_id))
				{
					// Now get new filename and update original doc_id				
					$q2 = mysql_query("select stored_name from redcap_edocs_metadata where doc_id = $edoc_id", $rc_conn);
					$new_stored_name = mysql_result($q2, 0);
					if ($q2 && $new_stored_name != '')
					{
						$q3 = mysql_query("update redcap_edocs_metadata set stored_name = '".prep($new_stored_name)."' where doc_id = {$row['doc_id']}", $rc_conn);
						// Now delete the new edoc_id row, since it's no longer needed
						if ($q3) {
							$q4 = mysql_query("delete from redcap_edocs_metadata where doc_id = $edoc_id", $rc_conn);
							if ($q4) {
								print "<div style='color:green;'>".$i++.") The file <b>{$row['stored_name']}</b> was successfully copied and renamed <b>$new_stored_name</b></div>";
								$isError = false;
							}
						}
					}
				}
				if ($isError)
				{
					// File did not copy
					print "<div style='color:red;'>".$i++.") The file <b>{$row['stored_name']}</b> could NOT be copied!</div>";
					$file_copy_errors++;
					// Set values for manual copy instructions
					$dest_filename = date('YmdHis') . "_pid" . $row['project_id'] . "_" . generateRandomHash(6) . getFileExt($row['stored_name'], true);
					$manual_copy[] = array('oldfile'=>$row['stored_name'], 'newfile'=>$dest_filename, 'edoc_id'=>$row['doc_id']);					
				}
				
			}
		}
	}
	else
	{
		// No files need to be copied
		print "<p style='color:green;font-weight:bold;'><img src='".APP_PATH_IMAGES."tick.png' class='imgfix'> No files need to be copied!</p>";
	}
	if ($file_copy_errors > 0) 
	{		
		if (!$edoc_storage_option) {
			// Upload to "edocs" folder (use default or custom path for storage)
			$rs_edoc_path_full = "<b>".EDOC_PATH."</b>";
		} else {
			// Using WebDAV storage method
			include (APP_PATH_WEBTOOLS . 'webdav/webdav_connection.php');
			$rs_edoc_path_full = "<b>$webdav_path</b> (on the server <b>$webdav_hostname</b>)";
		}
		print  "<p class='red'>
					<img src='".APP_PATH_IMAGES."exclamation.png' class='imgfix'>
					<b>NOTICE: Not all the files were copied!</b><br>
					For some unknown reason, this module was NOT able to automatically copy some of the files.
					Thus, you will need to do this manually. Follow ALL the steps below to do this manually. Once you
					have done it, refresh this page to make sure all was done correctly and that nothing more
					needs to be done.<br><br>
					<span style='font-family:arial;'>For the directory $rs_edoc_path_full ... <br>";
		$k = 1;
		$sql_string = "<br><br><b>Now copy and execute the SQL below:</b>";
		foreach ($manual_copy as $attr)
		{
			print "<br>".$k++.") Copy the file <b>{$attr['oldfile']}</b> and rename the copy to <b>{$attr['newfile']}</b>";
			$sql_string .= "<br>UPDATE redcap_edocs_metadata SET stored_name = '".prep($attr['newfile'])."' WHERE doc_id = {$attr['edoc_id']};";
		}
		print  $sql_string;
		print  "</span></p>";
	}
	?>
	<p style="text-align:center;">
		<button class="jqbutton" onclick="window.location.href=app_path_webroot+page+'?step=9';">Go to next step</button>
	</p>
	<?php	
}



// PAGE 5
if ($_GET['step'] == 9)
{
	// DB connect
	list ($rc_conn, $rs_conn, $rc_db, $rs_db) = db_connect_both();
	// Back button
	renderPrevPageBtn(PAGE,"START OVER");
	// Get rs_version for RS
	mysql_select_db($rs_db, $rs_conn);
	$q = mysql_query("select * from rs_config", $rs_conn);
	$rs_configs = mysql_fetch_assoc($q);
	$rs_path = $rs_configs['redcap_survey_docroot'] . $rs_configs['rs_version'] . "/";
	$rs_path_surveys = $rs_configs['surveys_docroot'];
	?>
	<p style="margin:25px 0 30px;padding-top:12px;border-top:1px solid #aaa;">
		<b>9.) SET UP REDIRECT SCRIPTS IN YOUR OLD REDCAP SURVEY INSTALLATION</b><br>
		Now that all the data and user-uploaded documents have been migrated from REDCap Survey, we now need to put some redirect PHP files in place
		in your old REDCap Survey installation so that REDCap Survey users and survey participants will get automatically redirected
		to the REDCap installation from now on if they happen to go to the old location in their web browser. Follow the steps below.
		<b>IMPORTANT: When downloading the files below, you <u>MUST</u> make sure they do not get renamed to another filename (some browsers
		may do this automatically).</b> Please ensure that their filename stays as index.php, core.php, and index.php, respectively.
		To minimize confusion and error, it actually <b>might be best to do each step one at a time</b> (download one and place it on the server), rather than downloading all
		three at once and moving them all to the server in a single batch.
	</p>
	<p>
		<b>A.) Download the file "index.php"</b> and use it to replace the one located inside <b><?php echo $rs_configs['redcap_survey_docroot'] ?></b> 
		on your REDCap Survey web server. This file will redirect users who go to REDCap Survey to your 
		REDCap installation located at <a style="text-decoration:underline;" target="_blank" href="<?php echo APP_PATH_WEBROOT_FULL ?>"><?php echo APP_PATH_WEBROOT_FULL ?></a>.<br>
		<button class="jqbuttonmed" onclick="window.location.href=app_path_webroot+page+'?file=indexrs';"><img src="<?php echo APP_PATH_IMAGES ?>page_white_php.png" class="imgfix"> Download index.php</button>
	</p>
	<p>
		<b>B.) Download the file "core.php"</b> and use it to replace the one located inside <b><?php echo $rs_path . "config/" ?></b> 
		on your REDCap Survey web server. This file will redirect users who go to REDCap Survey to your 
		REDCap installation located at <a style="text-decoration:underline;" target="_blank" href="<?php echo APP_PATH_WEBROOT_FULL ?>"><?php echo APP_PATH_WEBROOT_FULL ?></a>.<br>
		<button class="jqbuttonmed" onclick="window.location.href=app_path_webroot+page+'?file=core';"><img src="<?php echo APP_PATH_IMAGES ?>page_white_php.png" class="imgfix"> Download core.php</button>
	</p>
	<p>
		<b>C.) Download the file "index.php"</b> and use it to replace the one located inside <b><?php echo $rs_path_surveys ?></b> 
		on your REDCap Survey web server. (Do NOT confuse this RS directory with the REDCap directory named "surveys" located at
		<?php echo dirname(dirname(dirname(__FILE__))) . DS . "surveys" . DS ?>", which is similarly named. 
		<b>NOTE: Make sure this file does NOT get confused with the other index.php from step A.</b>
		<b style="color:#800000;">ALSO, if your /surveys directory for REDCap Survey exists in the same file location where
		the REDCap 4.0 /surveys directory should be, then ignore this step and make sure that the /surveys folder for 
		REDCap 4.0 replaces the old /surveys folder for REDCap Survey (this should have already been done during the upgrade to 4.0).</b>
		<br>
		<button class="jqbuttonmed" onclick="window.location.href=app_path_webroot+page+'?file=indexsurveys';"><img src="<?php echo APP_PATH_IMAGES ?>page_white_php.png" class="imgfix"> Download index.php</button>
	</p>
	<p style="text-align:center;">
		<button class="jqbutton" onclick="window.location.href=app_path_webroot+page+'?step=10';">Go to next step</button>
	</p>
	<?php
}


// PAGE 6
if ($_GET['step'] == 10)
{
	// DB connect
	list ($rc_conn, $rs_conn, $rc_db, $rs_db) = db_connect_both();
	// Back button
	renderPrevPageBtn(PAGE,"START OVER");
	?>
	<p style="margin:25px 0 40px;padding-top:12px;border-top:1px solid #aaa;">
		<b>10.) MIGRATION COMPLETE!</b><br>
		Congratulations! You have now successfully completed the migration of your REDCap Survey surveys into REDCap.
		You may now go and access all your imported survey projects and all other existing projects in REDCap 4.0.
		<b>Don't forget to set REDCap back to System Online status on the 
		<a style="text-decoration:underline;" href="<?php echo APP_PATH_WEBROOT ?>ControlCenter/general_settings.php">General Config page</a>.</b> ENJOY!
	</p>
	<p style="text-align:center;">
		<button class="jqbutton" onclick="window.location.href='<?php echo APP_PATH_WEBROOT_FULL ?>';">Return to REDCap</button>
	</p>
	<?php
}



// Display script execution time
$mtime = microtime(); 
$mtime = explode(" ",$mtime); 
$mtime = $mtime[1] + $mtime[0]; 
$endtime = $mtime; 
$totaltime = round(($endtime - $starttime),2); 
//echo "<div style='color:#888;padding-top:40px;'>Page execution time: $totaltime seconds</div>";

// Page footer
$objHtmlPage->PrintFooter();
