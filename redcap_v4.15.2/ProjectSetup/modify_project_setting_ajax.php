<?php
/*****************************************************************************************
**  REDCap is only available through a license agreement with Vanderbilt University
******************************************************************************************/

require_once dirname(dirname(__FILE__)) . '/Config/init_project.php';
 
// Default response
$response = "0";

// Make sure the "name" setting is a real one that we can change
$viableSettingsToChange = array('auto_inc_set','scheduling','randomization','repeatforms','surveys_enabled');
if (!empty($_POST['name']) && $_POST['value'] != "" && in_array($_POST['name'], $viableSettingsToChange)) 
{
	// Modify setting in table
	$sql = "update redcap_projects set {$_POST['name']} = '" . prep(label_decode($_POST['value'])). "' 
			where project_id = $project_id";
	if (mysql_query($sql)) {
		$response = "1";
		// Logging
		log_event($sql,"redcap_projects","MANAGE",$project_id,"project_id = $project_id","Modify project settings");
	}
}

// Send response
print $response;
