<?php
/*****************************************************************************************
**  REDCap is only available through a license agreement with Vanderbilt University
******************************************************************************************/

require_once dirname(dirname(__FILE__)) . "/Config/init_project.php";
require_once APP_PATH_DOCROOT . 'Design/functions.php';


## Validate the fields in the calc equation
if (isset($_POST['eq']))
{
	// Obtain array of error fields that are not real fields
	$error_fields = validateBranchingCalc($_POST['eq']);
	
	// Return list of fields that do not exist (i.e. were entered incorrectly), else continue.
	if (!empty($error_fields))
	{
		print implode("\n- ", $error_fields);
		exit;
	}

}

// ERROR
else
{
	exit('0');
}
