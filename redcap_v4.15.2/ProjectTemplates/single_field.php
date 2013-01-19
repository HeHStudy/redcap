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

$metadata['My First Instrument'] = array(
		array("record_id", "text", "Record ID", "", "", "")
);