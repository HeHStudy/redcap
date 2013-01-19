<?php
/*****************************************************************************************
**  REDCap is only available through a license agreement with Vanderbilt University
******************************************************************************************/

require_once dirname(dirname(__FILE__)) . "/Config/init_project.php";
require_once APP_PATH_DOCROOT . 'Design/functions.php';

// Default response
$response = '0';

## Validate the fields in the logic
if (isset($_POST['logic']))
{
	// Obtain array of error fields that are not real fields
	$error_fields = validateBranchingCalc($_POST['logic'], true);	
	// Return list of fields that do not exist (i.e. were entered incorrectly), else continue.
	if (!empty($error_fields))
	{
		$response = "{$lang['dataqueries_47']} {$lang['dataqueries_45']}\n\n{$lang['dataqueries_46']}\n- " 
				  . implode("\n- ", $error_fields);
	}
	
	// Check for any formatting issues or illegal functions used
	else
	{
		// Instantiate DataQuality object
		$dq = new DataQuality();
		// Check the logic
		$illegalFunctions = $dq->checkRuleLogic($_POST['logic']);
		if ($illegalFunctions === false) {
			// Contains syntax errors
			$response = $lang['dataqueries_99'];
		} elseif (is_array($illegalFunctions) && !empty($illegalFunctions)) {
			// Contains illegal functions
			$response = "{$lang['dataqueries_47']} {$lang['dataqueries_49']}\n\n{$lang['dataqueries_48']}\n- " 
					  . implode("\n- ",  $illegalFunctions);
		} else {
			// All is good (no errors)
			$response = '1';
		}
	}
}

// Send response
exit($response);
