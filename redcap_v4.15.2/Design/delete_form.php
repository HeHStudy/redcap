<?php
/*****************************************************************************************
**  REDCap is only available through a license agreement with Vanderbilt University
******************************************************************************************/

require_once dirname(dirname(__FILE__)) . '/Config/init_project.php';
require_once APP_PATH_DOCROOT . 'Design/functions.php';

if (isset($_GET['form_name']) && preg_match("/[a-z_0-9]/", $_GET['form_name'])) 
{	
	// If project is in production, do not allow instant editing (draft the changes using metadata_temp table instead)
	$metadata_table = ($status > 0) ? "redcap_metadata_temp" : "redcap_metadata";
	
	// Check if randomization has been enabled and prevent deletion of the rand field and any strata fields
	if ($randomization && Randomization::setupStatus()) 
	{
		// Get randomization attributes
		$randAttr = Randomization::getRandomizationAttributes();
		// If the randomization field or strata fields are on this form, then stop here
		$sql = "select 1 from $metadata_table where project_id = $project_id and form_name = '{$_GET['form_name']}'
				and field_name in ('{$randAttr['targetField']}', '" . implode("', '", array_keys($randAttr['strata'])) . "')";
		$q = mysql_query($sql);
		if (mysql_num_rows($q) > 0) {
			// One or more fields are on this form, so return error code
			exit("3");
		}
	}
	
	// Get name of first form to compare in the end to see if it was moved
	$firstFormBefore = getFirstForm();

	$sql_all = array();
	
	// Before deleting form, get number of fields on form and field_order of first field (for reordering later)
	$sql = "select count(1) from $metadata_table where project_id = $project_id and form_name = '{$_GET['form_name']}'";
	$field_count = mysql_result(mysql_query($sql), 0);
	$sql = "select field_order from $metadata_table where project_id = $project_id and form_name = '{$_GET['form_name']}' limit 1";
	$first_field_order = mysql_result(mysql_query($sql), 0);
	
	//remove standard code mappings
	//note: standards and standard codes are not removed since the audit trail may still be pointing to them.  Only the mapping is removed.
	$getFieldsSql = "select field_name from $metadata_table where project_id = $project_id and form_name = '{$_GET['form_name']}'";
	$getFieldsQuery = mysql_query($getFieldsSql);
	while($getFieldsRow = mysql_fetch_array($getFieldsQuery)) {
		$removeMappingSql = "delete from redcap_standard_map where project_id = $project_id and field_name = '{$getFieldsRow['field_name']}'";
		$removeMappingQuery = mysql_query($removeMappingSql);
	}
			
	// If edoc_id exists for any fields on this form, then set all as "deleted" in edocs_metadata table
	$sql = "update redcap_edocs_metadata set delete_date = '".NOW."' where project_id = $project_id and delete_date is null and doc_id in
			(select edoc_id from $metadata_table where project_id = $project_id and form_name = '{$_GET['form_name']}' and edoc_id is not null)";
	if (mysql_query($sql)) $sql_all[] = $sql;
	
	// Delete this form's fields from the metadata table
	$sql = "delete from $metadata_table where project_id = $project_id and form_name = '{$_GET['form_name']}'";
	if (mysql_query($sql)) {
		$sql_all[] = $sql;	
		// Now adjust all field orders to compensate for missing form
		$sql = "update $metadata_table set field_order = field_order - $field_count where project_id = $project_id 
				and field_order > $first_field_order";
		if (mysql_query($sql)) $sql_all[] = $sql;
	}	
	
	// If in Development, delete all form-level rights associated with the form
	if ($status < 1) 
	{
		// Catch all 3 possible instances of form-level rights to delete them from user rights table
		$sql = "update redcap_user_rights set data_entry = replace(data_entry,'[{$_GET['form_name']},0]',''),
				data_entry = replace(data_entry,'[{$_GET['form_name']},1]',''), data_entry = replace(data_entry,'[{$_GET['form_name']},2]','')
				where project_id = $project_id";
		if (mysql_query($sql)) $sql_all[] = $sql;
		// Delete form from all tables EXCEPT metadata tables and user_rights table
		deleteFormFromTables($_GET['form_name']);
	}
	
	// Logging
	log_event(implode(";\n", $sql_all), $metadata_table, "MANAGE", $_GET['form_name'], "form_name = '{$_GET['form_name']}'", "Delete data collection instrument");
	
	// Send successful response (1 = OK, 2 = OK but first form was moved, i.e. PK was changed)
	print (getFirstForm() == $firstFormBefore ? "1" : "2");
	
}
