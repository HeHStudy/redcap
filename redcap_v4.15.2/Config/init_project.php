<?php
/*****************************************************************************************
**  REDCap is only available through a license agreement with Vanderbilt University
******************************************************************************************/


// Call file containing basic functions for this config file	.
require_once dirname(__FILE__) . '/init_functions.php';	
// Define all PHP constants used throughout the application.
define_constants();
// Check if the URL is pointing to the correct version of REDCap. If not, redirect to correct version.
check_version();
// Make sure we have either pnid or pid in query string. If not, then redirect to Home page.
if (!(isset($_GET['pnid']) || (isset($_GET['pid']) && is_numeric($_GET['pid'])))) redirectHome(); 
// Query redcap_projects table for project-level values and set as global variables.
setProjectVals();
// Define constants and variables for project
$app_name = $_GET['pnid'] = $project_name;
$_GET['pid'] = $project_id;
define("APP_NAME", 	 $app_name);
define("PROJECT_ID", $project_id);
// Assign other variables not explicity in redcap_projects table.
$is_child = ($is_child_of != "");
$hidden_edit = 0;
// Build reports arrays
if ($custom_reports != '') eval($custom_reports);
$query_array = array();
eval($report_builder);
// Check DTS global value. If disabled, then disable project-level value also.
$dts_enabled = ($dts_enabled_global ? $dts_enabled : false);
// Check randomization module's global value. If disabled, then disable project-level value also.
$randomization = ($randomization_global ? $randomization : 0);
// If project-level SALT does not exist yet, then create it as 10-digit random alphanum
if (empty($__SALT__ )) {
	$__SALT__ = substr(md5(rand()), 0, 10);
	mysql_query("update redcap_projects set __SALT__ = '$__SALT__' where project_id = $project_id");
}
// Object containing all project information
$Proj = new ProjectAttributes();
// Ensure that the field being used as the secondary id still exists as a field. If not, set $secondary_pk to blank.
if ($secondary_pk != '' && !isset($Proj->metadata[$secondary_pk])) {
	$secondary_pk = '';
}
// If survey_email_participant_field has a value but is no longer a real field, then reset it to blank.
if ($survey_email_participant_field != '' && !isset($Proj->metadata[$survey_email_participant_field])) {
	$survey_email_participant_field = '';
}
// Determine if longitudinal (has multiple events) and multiple arms
$longitudinal  = $Proj->longitudinal;
$multiple_arms = $Proj->multiple_arms;
// Establish the record id Field Name and its Field Label
$table_pk 	    = $Proj->table_pk;
$table_pk_phi   = $Proj->table_pk_phi;
$table_pk_label = $Proj->table_pk_label;
// Language: Call the correct language file for this project (default to English)
$lang = getLanguage($project_language);
// Authenticate the user
$userAuthenticated = Authentication::authenticate();
if (!$userAuthenticated || $userAuthenticated === '2')
{
	if (!$no_access && PAGE != 'Mobile/data_entry.php') {
		include APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
		renderPageTitle();
	}
	$noAccessMsg = ($userAuthenticated === '2') ? $lang['config_04'] . "<br><br>" : "";
	print  "<div class='red'>
				<img src='" . APP_PATH_IMAGES . "exclamation.png' class='imgfix'> 
				<b>{$lang['global_05']}</b><br><br>
				$noAccessMsg {$lang['config_02']} <a href=\"mailto:$project_contact_email\">$project_contact_name</a> {$lang['config_03']}
			</div>";
	// Show left-hand menu unless it's been flagged to hide everything to prevent user from doing anything else
	if (!$no_access && PAGE != 'Mobile/data_entry.php') {
		include APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
	}
	exit;
}
// Compensate if register_globals is "on", which can overwrite $username global variable from $_SESSION['username']
if (ini_get('register_globals') || strtolower(ini_get('register_globals')) == "on") 
{
	include dirname(dirname(dirname(__FILE__))) . '/database.php';	
}
// SURVEY: If on survey page, start the session and manually set username to [survey respondent]
if (PAGE == "surveys/index.php" || (defined("NOAUTH") && isset($_GET['s'])))
{
	// Begin a session for saving response data as participant moves from page to page
	session_name("survey"); // Give survey pages a different session name to separate it from regular REDCap user's session
	if (!session_id()) @session_start();
	// Set "username" for logging purposes (static for all survey respondents)
	defined("USERID") || define("USERID", "[survey respondent]");
}
// NON-SURVEY: Normal project page
else
{
	// Clean up any temporary files sitting on the web server (for various reasons)
	remove_temp_deleted_files();
	// Prevent CRSF attacks by checking a custom token
	checkCsrfToken();
	// Parent/Child Project Linking features
	if (isset($_GET['child']) && $_GET['child'] != "") {
		// If user is in a form from a linked parent project, make it look like child project for continuity
		$app_title = mysql_result(mysql_query("select app_title from redcap_projects where project_name = '{$_GET['child']}'"), 0);
	} elseif ($is_child) {
		// If project is a child, get project_id of parent
		$project_id_parent = mysql_result(mysql_query("select project_id from redcap_projects where project_name = '$is_child_of'"), 0);
		// Determine if parent is longitudinal (because parent/child will not work with longitudinal)
		$sql = "select count(1) from redcap_events_arms a, redcap_events_metadata m, redcap_projects p 
				where p.project_id = $project_id_parent and p.project_id = a.project_id and a.arm_id = m.arm_id and p.repeatforms = 1";
		$longitudinal_parent = (mysql_result(mysql_query($sql), 0) > 1);
		// Since records in child can only come from parent, disable ability to rename records in child to prevent conflicts
		$user_rights['record_rename'] = 0;
	}
	// Instantiate ExternalLinks object
	$ExtRes = new ExternalLinks();
}
// Check if system has been set to Offline
checkSystemStatus();
// Check Online/Offline status of project
checkOnlineStatus();
// Count this page hit
addPageHit();
// Add this page viewing to log_view table
addPageView();
// Set pre-defined multiple choice options for Yes-No and True-False fields
define("YN_ENUM", "1, {$lang['design_100']} \\n 0, {$lang['design_99']}");
define("TF_ENUM", "1, {$lang['design_186']} \\n 0, {$lang['design_187']}");
