<?php
/*****************************************************************************************
**  REDCap is only available through a license agreement with Vanderbilt University
******************************************************************************************/


/**
 * SET UP METADATA TEMPLATE FOR PRE-FILLING NEW PROJECT
 */

## EXAMPLE ROW
// $metadata['Form Name'] = array(
//		array("variable_name", "field_type", "Field Label", "Choices or Calculations", "Validation Type", "Section Header"),
//		...
// );

$metadata['Demographics'] = array(
		array("study_id", "text", "Study ID", "", "", ""),
		array("first_name", "text", "First Name", "", "", "Demographics Information"),
		array("last_name", "text", "Last Name", "", "", ""),
		array("dob", "text", "Date of Birth", "", "date", ""),
		array("sex", "select", "Gender", "0, Female | 1, Male", "", ""),
		array("address", "textarea", "Street, City, State, ZIP", "", "", ""),
		array("phone_number", "text", "Phone number", "", "phone", "")
);


/* 
$metadata['My First Form'] = array(
		array("study_id", "text", "Study ID", "", "", ""),
		array("sex", "select", "Gender", "0, Female | 1, Male", "", ""),
		array("age", "text", "Age", "", "int", "")
);
*/