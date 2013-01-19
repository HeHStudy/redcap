<?php
/*****************************************************************************************
**  REDCap is only available through a license agreement with Vanderbilt University
******************************************************************************************/


/**
 * RECORDS Class
 */
class Records
{
	// Return count of all records in project
	static public function getCount($project_id=null)
	{
		// Verify project_id as numeric
		if (!is_numeric($project_id)) return false;
		// Query to get resources from table
		$sql = "select count(distinct(record)) from redcap_data where project_id = $project_id 
				and field_name = '" . prep(self::getTablePK($project_id)) . "'";
		$q = mysql_query($sql);
		if (!$q) return false;
		// Return count
		return mysql_result($q, 0);
	}
	
	
	// Return list of all record names as an "array" or as a "csv" string.
	// Can also set ordering by record and whether or not to sql-escape each record name surrounding by apostrophes.
	static public function getRecordList($project_id=null, $returnFormat='array', $orderByRecord=true, $sqlEscapeWithApos=false)
	{
		// Verify project_id as numeric
		if (!is_numeric($project_id)) return false;
		// Check return format
		$returnFormat = ($returnFormat != 'csv') ? 'array' : 'csv';
		// Set "order by"
		$orderBy = ($orderByRecord) ? "order by abs(record), record" : "";
		// Put list in array
		$records = array();
		// Query to get resources from table
		$sql = "select distinct record from redcap_data where project_id = $project_id 
				and field_name = '" . prep(self::getTablePK($project_id)) . "' $orderBy";
		$q = mysql_query($sql);
		if (!$q) return false;
		if (mysql_num_rows($q) > 0) {
			while ($row = mysql_fetch_assoc($q)) {
				// Un-html-escape record name (just in case)
				$row['record'] = html_entity_decode($row['record'], ENT_QUOTES);
				// Sql-escape record name
				if ($sqlEscapeWithApos) {
					$row['record'] = "'" . prep($row['record']) . "'";
				}
				// Add record name to array
				$records[] = $row['record'];
			}
		}
		// Convert to comma-delimited string, if specified
		if ($returnFormat == 'csv') {
			$records = implode(",", $records);
		}
		// Return record list
		return $records;
	}
	
	
	// Return name of record identifier variable (i.e. "table_pk") in a given project
	static public function getTablePK($project_id=null)
	{
		// First, if project-level variables are defined, then there's no need to query the database table
		if (defined('PROJECT_ID')) {
			// Get table_pk from global scope variable
			global $table_pk;
			return $table_pk;
		}
		// Verify project_id as numeric
		if (!is_numeric($project_id)) return false;
		// Query metadata table
		$sql = "select field_name from redcap_metadata where project_id = $project_id 
				order by field_order limit 1";
		$q = mysql_query($sql);
		if ($q && mysql_num_rows($q) > 0) {
			// Return field name
			return mysql_result($q, 0);
		} else {
			// Return false is query fails or doesn't exist
			return false;
		}
	}
	
	
	// Get list of all records (or specific ones) with their Form Status for all forms/events
	static public function getFormStatus($project_id=null, $records=array())
	{
		// Verify project_id as numeric
		if (!is_numeric($project_id)) return false;
		// Create "where" clause for records provided, if provided
		$recordSql = (is_array($records) && !empty($records)) ? "and d.record in (" . prep_implode($records) . ")" : "";
		// Get array list of form_names
		$allForms = self::getFormNames($project_id);
		// Query to get resources from table
		$sql = "select d.record, d.event_id, m.field_name, m.form_name, d.value
				from redcap_data d, redcap_metadata m where d.project_id = $project_id 
				and d.project_id = m.project_id and d.field_name = m.field_name $recordSql 
				and m.field_name in ('" . prep(self::getTablePK($project_id)) . "', 
				'" . implode("_complete', '", $allForms) . "_complete') 
				order by abs(d.record), d.record, m.field_order";
		$q = mysql_query($sql);
		if (!$q) return false;
		// Array to collect the record data
		$data = array();
		while ($row = mysql_fetch_assoc($q)) {
			// If record is not in the array yet, prefill forms with 0s
			if (!isset($data[$row['record']][$row['event_id']])) {
				foreach ($allForms as $this_form) {
					$data[$row['record']][$row['event_id']][$this_form] = 0;
				}
			}
			// Add the form values to array (ignore table_pk value since it was only used as a record placeholder anyway)
			if ($row['field_name'] != self::getTablePK($project_id)) {
				$data[$row['record']][$row['event_id']][$row['form_name']] = $row['value'];
			}
		}
		// Return array of form status data for records
		return $data;		
	}
	
	
	// Return form_names as array of all instruments in a given project
	static public function getFormNames($project_id=null)
	{
		// First, if project-level variables are defined, then there's no need to query the database table
		if (defined('PROJECT_ID')) {
			// Get table_pk from global scope variable
			global $Proj;
			return array_keys($Proj->forms);
		}
		// Verify project_id as numeric
		if (!is_numeric($project_id)) return false;
		// Query metadata table
		$sql = "select distinct form_name from redcap_metadata where project_id = $project_id 
				order by field_order";
		$q = mysql_query($sql);
		if (!$q) return false;
		// Return form_names
		$forms = array();
		while ($row = mysql_fetch_assoc($q)) {
			$forms[] = $row['form_name'];
		}
		return $forms;
	}
	
}
