<?php
/*****************************************************************************************
**  REDCap is only available through a license agreement with Vanderbilt University
******************************************************************************************/

require_once dirname(dirname(__FILE__)) . "/Config/init_project.php";
require_once APP_PATH_DOCROOT . "Surveys/survey_functions.php";

// If not using a type of project with surveys, then don't allow user to use this page.
if ($surveys_enabled < 1) {
	redirect(APP_PATH_WEBROOT . "index.php?pid=$project_id");
}


// Header
include APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
// Tabs
require_once APP_PATH_DOCROOT . "ProjectSetup/tabs.php";

## ERROR CHECK FOR SURVEY INVITATION SCHEDULE
// Instructions
print RCView::p('', "This page may be used check for any errors that might exist in the existing survey schedule.");
// Display a report of the error check for the survey scheduler
$surveyScheduler = new SurveyScheduler();

print "<b>Nothing to see here! Move along!</b>";
//print $surveyScheduler->renderProjectScheduleErrorTable();
	
// Footer
include APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
	