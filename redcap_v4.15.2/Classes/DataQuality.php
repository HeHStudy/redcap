<?php
/*****************************************************************************************
**  REDCap is only available through a license agreement with Vanderbilt University
******************************************************************************************/


/**
 * DATA QUALITY
 */
class DataQuality
{
	// Set max amount of results that can be returned when executing a rule (for both server memory and browser memory issues)
	public $resultLimit = 10000;
	// Array with the Data Quality rules defined by the user
	private $rules = null;
	// Results from running the logic from the rules
	public $logicCheckResults = array();
	// Array of discrepancy count for individual DAGs
	public $dag_discrepancies = array();
	// Array of status labels
	private $status_labels = array();
	// Array of default status value for pre-defined rules and user-defined rules
	private $default_status = array();
	// Array of pre-defined rules
	private	$predefined_rules = array();
	// Array to store association of records with DAGs
	private $dag_records = array();
	// Set list of dangerous PHP functions to filter out of the logic
	// private $dangerousPhpFunctions = array(
		// "apache_child_terminate", "apache_setenv", "define_syslog_variables", "escapeshellarg", "escapeshellcmd", 
		// "eval", "exec", "fp", "fput", "ftp_connect", "ftp_exec", "ftp_get", "ftp_login", "ftp_nb_fput", "ftp_put", 
		// "ftp_raw", "ftp_rawlist", "highlight_file", "ini_alter", "ini_get_all", "ini_restore", "inject_code", 
		// "mysql_pconnect", "openlog", "passthru", "php_uname", "phpAds_remoteInfo", "phpAds_XmlRpc", "phpAds_xmlrpcDecode", 
		// "phpAds_xmlrpcEncode", "popen", "posix_getpwuid", "posix_kill", "posix_mkfifo", "posix_setpgid", "posix_setsid", 
		// "posix_setuid", "posix_setuid", "posix_uname", "proc_close", "proc_get_status", "proc_nice", "proc_open", 
		// "proc_terminate", "shell_exec", "syslog", "system", "xmlrpc_entity_decode", "mysql_query"
	// );
	// Set list of allowed functions to be used in user-defined logic
	private $allowedFunctions = array(
		"if", "datediff", "round", "roundup", "rounddown", "sqrt", "abs", "min", "max", 
		"mean", "median", "sum", "stdev", "isnumber", "isinteger",
		// Add "and" and "or" even though they're not functions, but they look like it when they come before parentheses.
		"and", "or"
	);
	
    // Construct
	public function __construct() 
	{
		global $lang;
		// Define status labels
		$this->status_labels = array(
			0 => $lang['dataqueries_51'],
			1 => $lang['dataqueries_52'],
			2 => $lang['dataqueries_53'],
			3 => $lang['dataqueries_54'],
			4 => $lang['dataqueries_55'],
			5 => $lang['dataqueries_56'],
			6 => $lang['dataqueries_57'],
			7 => $lang['dataqueries_58'],
			8 => $lang['dataqueries_59'],
			9 => $lang['dataqueries_60'],
			10=> $lang['dataqueries_61'],
		);
		// Define default status for rules
		$this->default_status = array(
			'num'  => 0, // This is an umbrella for all user-defined rules
			'pd-1' => 2,
			'pd-2' => 2,
			'pd-3' => 2,
			'pd-4' => 3,
			'pd-5' => 4,
			'pd-6' => 2,
			'pd-7' => 6,
			'pd-8' => 6,
			'pd-9' => 7
		);
		// Define pre-defined rules (will be named pd-#)
		$this->predefined_rules = array(
			// 1 => 'Any missing values',
			// 2 => 'Any missing values (required fields only)',
			3 => $lang['dataqueries_62'].'*',
			6 => $lang['dataqueries_62'].'* '.$lang['dataqueries_63'],
			4 => $lang['dataqueries_64'].' '.$lang['dataqueries_65'],
			9 => $lang['dataqueries_64'].' '.$lang['dataqueries_66'],
			5 => $lang['dataqueries_67'].'<br>'.$lang['dataqueries_68'],
			7 => $lang['dataqueries_69'].'**',
			8 => $lang['dataqueries_70']
		);
    }
	
	// Convert a number to a character (1=A, 2=B, etc.)
	private function numtochars($num,$start=65,$end=90)
	{
		$sig = ($num < 0);
		$num = abs($num);
		$str = "";
		$cache = ($end-$start);
		while($num != 0)
		{
			$str = chr(($num%$cache)+$start-1).$str;
			$num = ($num-($num%$cache))/$cache;
		}
		if($sig)
		{
			$str = "-".$str;
		}
		return $str;
	}
	
	// Load rules defined. If provide rule_id, then only return that rule.
	public function loadRules($rule_id=null)
	{
		## First, load the pre-defined rules
		foreach ($this->predefined_rules as $pd_rule_id=>$name)
		{
			$this->rules['pd-'.$pd_rule_id] = array(
				'name' => "<span class='pd-rule'>$name</span>", 
				'logic' => "<span class='pd-rule'>&nbsp;-</span>", 
				'order' => 'pd-'.$pd_rule_id
			);
		}		
		
		## Now, load the user-defined rules
		// If rule_id is defined, then add it to sql
		$sql_rule_id = (is_numeric($rule_id) ? "and rule_id = $rule_id" : "");
		// Query to get rules from table
		$sql = "select * from redcap_data_quality_rules where project_id = " . PROJECT_ID 
			 . " $sql_rule_id order by rule_order";
		$q = mysql_query($sql);
		while ($row = mysql_fetch_assoc($q))
		{
			// Add rule to array
			$this->rules[$row['rule_id']] = array(	
				'name'  => strip_tags(label_decode($row['rule_name'])), 
				'logic' => html_entity_decode($row['rule_logic'], ENT_QUOTES), 
				'order' => $row['rule_order']
			);		
		}
		// Do a quick check to make sure the rule are in the right order (if not, will fix it)
		if ($rule_id == null) $this->checkOrder();
	}
	
	// Retrieve all rules defined. Return as array.
	public function getRules()
	{
		// Load the rules
		if ($this->rules == null) $this->loadRules();
		// Return the rules
		return $this->rules;
	}
	
	// Retrieve a single rule defined. Return as array.
	public function getRule($rule_id)
	{
		// Load the rule
		if ($this->rules == null) $this->loadRules($rule_id);
		// Return the rule
		return (isset($this->rules[$rule_id]) ? $this->rules[$rule_id] : false);
	}
	
	// Check a single rule's logic for any errors/issues
	public function checkRuleLogic($logic)
	{
		global $Proj;
		
		// Trim the logic
		$logic = trim($logic);
		
		// Make sure that it has length
		if (strlen($logic) < 1) return false;
		
		## Check for basic syntax errors (odd number of parentheses, brackets, etc.)
		// Check symmetry of "
		if (substr_count($logic, '"')%2 > 0) {
			return false;
		}
		// Check symmetry of '
		if (substr_count($logic, "'")%2 > 0) {
			return false;
		}
		// Check symmetry of [ with ]
		if (substr_count($logic, "[") != substr_count($logic, "]")) {
			return false;
		}
		// Check symmetry of ( with )
		if (substr_count($logic, "(") != substr_count($logic, ")")) {
			return false;
		}
		// Make sure does not contain $ dollar signs
		if (strpos($logic, '$') !== false) {
			return false;
		}
		// Make sure does not contain ` backtick character
		if (strpos($logic, '`') !== false) {
			return false;
		}
		
		/* 
		// Make sure there does not exist any dangerous PHP functions in the logic		
		foreach ($this->dangerousPhpFunctions as $function)
		{
			// Run the value through the regex pattern. If found a match, then return false
			if (!preg_match("/($function)(\s*)(\()/", $logic)) return false;
		}
		*/
		
		## Make sure there ONLY exists functions in the logic that are allowed
		$illegalFunctionsUsed = array();
		// Run the value through the regex pattern
		preg_match_all("/([a-zA-Z0-9_]{2,})(\s*)(\()/", $logic, $regex_matches);
		if (isset($regex_matches[1]) && !empty($regex_matches[1]))
		{
			// Loop through each matched function name and make sure it's an allowed function and not a checkbox fieldname
			foreach ($regex_matches[1] as $this_func)
			{
				// Make sure it's not a PHP function AND that it's not a checkbox field
				if (!in_array($this_func, $this->allowedFunctions) && !(isset($Proj->metadata[$this_func]) && $Proj->metadata[$this_func]['element_type'] == 'checkbox'))
				{
					$illegalFunctionsUsed[] = $this_func;
				}
			}
		}
		// If illegal functions are being used, return array of function names
		if (!empty($illegalFunctionsUsed)) {
			return $illegalFunctionsUsed;
		}
				
		// Return true if passed check
		return true;
	}
	
	// Format a single rule's logic
	public function formatRuleLogic($logic)
	{
		// Replace operators in equation with PHP equivalents and removing line breaks
		$orig = array("\r\n", "\n", "<"  , "=" , "===", "====", "> ==", "< ==", ">==" , "<==" , "< >", "<>", " and ", " AND ", " or ", " OR ");
		$repl = array(" "   , " " , " < ", "==", "==" , "=="  , ">="  , "<="  , ">="  , "<="  , "<>" , "!=", " && " , " && " , " || ", " || ");
		$logic = str_replace($orig, $repl, $logic);
		// Now reformat any exponential formating in the logic
		$logic = $this->replaceExponents($logic);
		// Now reformat any IF statements into PHP ternary operator format
		$logic = convertIfStatement($logic);
		// Return the resulting string
		return $logic;
	}
	
	// Replace literal values into logic for a single record for a single rule
	public function formatRuleLogicLiteral($logic, $record_data)
	{
		global $Proj, $longitudinal;
		
		// Get unique event names (with event_id as key)
		$events = $Proj->getUniqueEventNames();	
		
		// Loop through the data and replace the variable with its literal value
		foreach ($record_data as $event_id=>$field_name_value)
		{
			// Get unique event name for this event_id
			$unique_event_name = $events[$event_id];				
			// Replace "][" with "[]" so that event+field syntax gets replaced correctly because the normal field replacement was messing it up
			$logic = str_replace("][", "[]", $logic);
			// Now loop through each field for this record-event
			foreach ($field_name_value as $field_name=>$value)
			{
				// Replace variable with value
				if (is_array($value)) {
					// Replace checkbox logic
					foreach ($value as $chkbox_choice=>$chkbox_val) 
					{
						// If also longitudinal, then try replacing the unique event name + field with the value
						if ($longitudinal) {
							$logic = str_replace("[{$unique_event_name}[]$field_name($chkbox_choice)]", " '$chkbox_val' ", $logic);
						}
						// Replace field name
						$logic = str_replace("[$field_name($chkbox_choice)]", " '$chkbox_val' ", $logic);
					}
				} else {
					// Determine if field is a numerical value and not a string so we can know to surround it with apostrophes
					$fieldType = $Proj->metadata[$field_name]['element_type'];
					$valType   = $Proj->metadata[$field_name]['element_validation_type'];
					$isNumericField = ($fieldType == 'calc' || $fieldType == 'slider' || ($fieldType == 'text' && ($valType == 'int' || $valType == 'float')));
					$quote = ($isNumericField && is_numeric($value)) ? "" : "'"; // Doubly ensure that value is numeric to prevent crashing
					// Escape any apostrophes in the value since we around enclosing the value with apostrophes
					$value = cleanHtml($value);
					// If also longitudinal, then try replacing the unique event name + field with the value
					if ($longitudinal) {
						$logic = str_replace("[{$unique_event_name}[]$field_name]", " {$quote}{$value}{$quote} ", $logic);
					}
					// Replace field name
					$logic = str_replace("[$field_name]", " {$quote}{$value}{$quote} ", $logic);
				}
			}
			// Undo the replacement made above
			$logic = str_replace("[]", "][", $logic);
		}
		
		// In case there are some fields still left in the logic in square brackets (because they have no data),
		// then return false so that we can ignore this record-event for this rule.
		$fieldsNoValue = array_keys(getBracketedFields($logic, true, true, true));
		if (!empty($fieldsNoValue))
		{
			return false;
		}
		
		// Return the literal logic back
		return $logic;
	}
	
	// Execute a single PRE-DEFINED rule
	private function executePredefinedRule($rule_id, $dag_discrep)
	{
		global $Proj, $table_pk, $user_rights, $lang, $longitudinal;
		
		// Get the rule and its attributes
		$rule_attr = $this->getRule($rule_id);
		
		// EXCLUDED: Get a list of any record-event-field's for this rule that have been excluded (so we know what to exclude)
		$excluded = array();
		$sql = "select record, event_id, field_name from redcap_data_quality_status 
				where pd_rule_id = " . substr($rule_id, 3) . " and exclude = 1 and project_id = " . PROJECT_ID;
		$q = mysql_query($sql);
		while ($row = mysql_fetch_assoc($q))
		{
			$excluded[$row['record']][$row['event_id']][$row['field_name']] = true;
		}
		
		// Get deliminted list of all available events for use in queries (this is done to ignore orphaned data)
		$eventIdsSql = implode(", ", array_keys($Proj->eventInfo));
		
		// Which pre-defined rule are we running?
		switch ($rule_id)
		{
			// Rule: All missing values
			case 'pd-1':				
			// Rule: Missing values (required fields only)
			case 'pd-2':			
			// Rule: Missing values (excluding fields hidden by branching logic)
			case 'pd-3':			
			// Rule: Missing values (required fields only - excluding fields hidden by branching logic)
			case 'pd-6':
				// First create a fieldname array with blanks as values (default) - exclude PK, Form Status fields, desciptive text, and checkboxes
				$fields = array();
				foreach ($Proj->metadata as $field=>$attr)
				{
					if ($field != $table_pk && $field != $attr['form_name'] . "_complete" && $attr['element_type'] != 'checkbox' && $attr['element_type'] != 'descriptive')
					{
						// For pre-defined rule pd-2/pd-6, only add Required Fields
						if ((($rule_id == 'pd-2' || $rule_id == 'pd-6') && $attr['field_req']) || $rule_id == 'pd-1' || $rule_id == 'pd-3') {
							$fields[$field] = '';
						}
					}
				}
				// Limit records pulled only to those in user's Data Access Group
				$group_sql = ""; 
				if ($user_rights['group_id'] != "") {
					$group_sql  = "and record in (" . pre_query("select record from redcap_data where field_name = '__GROUPID__' and value = '{$user_rights['group_id']}' and project_id = ".PROJECT_ID).")"; 
				}					
				// Create array of all records-events in the data table and set all as blank
				$field_data_missing = array();
				$sql = "select distinct record, event_id from redcap_data where project_id = " . PROJECT_ID . " and value != '' 
						and record != '' and field_name = '$table_pk' $group_sql and event_id in ($eventIdsSql) 
						order by abs(record), record, event_id";
				$q = mysql_query($sql);
				while ($row = mysql_fetch_assoc($q))
				{
					// Set each record-event with list of blank fields by default
					$field_data_missing[$row['record']][$row['event_id']] = $fields;
				}
				mysql_free_result($q);
				// Now remove from $field_data_missing any fields that DO have data so that we're left with just the missing values
				$sql = "select record, event_id, field_name from redcap_data where project_id = " . PROJECT_ID . " and value != '' 
						and record != '' and field_name in ('" . implode("', '", array_keys($fields)) . "')  
						and event_id in ($eventIdsSql) $group_sql";
				$q = mysql_query($sql);
				while ($row = mysql_fetch_assoc($q))
				{
					// Remove from $field_data_missing array
					unset($field_data_missing[$row['record']][$row['event_id']][$row['field_name']]);
				}
				mysql_free_result($q);
				// For pd-3/pd-6 only, retrieve ALL data for fields using in branching logic fields
				if ($rule_id == 'pd-3' || $rule_id == 'pd-6')
				{
					// Store field names and data related to branching in arrays
					$branching_fields = array();
					$branching_fields_utilized = array();
					$branching_fields_ignore = array();
					$branching_data = array();
					// Loop through metadata and get all fields that have branching logic
					foreach ($Proj->metadata as $field=>$attr)
					{
						$this_branching = trim($attr['branching_logic']);
						if ($this_branching != "")
						{
							// Go ahead and decode it and format it to prep for parsing
							$this_branching = $this->formatRuleLogic(label_decode($this_branching));
							// If there is an issue in the logic, then we cannot use it, so skip it
							$checkRuleLogicBr = $this->checkRuleLogic($this_branching);
							if ($checkRuleLogicBr === false || (is_array($checkRuleLogicBr) && !empty($checkRuleLogicBr))) {
								// Add field to ignore array because it's logic is not usable
								$branching_fields_ignore[$field] = true;
							} else {
								$branching_fields[$field] = $this_branching;
								// Obtain the fields utilized in each field's branching logic
								foreach (array_keys(getBracketedFields($this_branching, true, true, true)) as $utilized_field)
								{
									$branching_fields_utilized[$utilized_field] = true;
								}
							}
						}
					}
					// Now query all records have missing values for fields used in all branching logic fields 
					// (trying to minimize the data returned here).
					$sql = "select record, event_id, field_name, value from redcap_data where project_id = " . PROJECT_ID . "
							and record in ('" . implode("', '", array_keys($field_data_missing)) . "') 
							and field_name in ('" . implode("', '", array_keys($branching_fields_utilized)) . "')
							and value != '' and event_id in ($eventIdsSql)";
					$q = mysql_query($sql);
					while ($row = mysql_fetch_assoc($q))
					{
						// Set each record-event with list of blank fields by default
						if ($Proj->metadata[$row['field_name']]['element_type'] == 'checkbox') {
							$branching_data[$row['record']][$row['event_id']][$row['field_name']][$row['value']] = '1';
						} else {
							$branching_data[$row['record']][$row['event_id']][$row['field_name']] = $row['value'];
						}
					}
					mysql_free_result($q);
				}
				// Set last_record value to be set at end of each loop
				$last_record = "";
				// Now we have an array with all missing values for all records-events, so loop through it and add to results
				foreach ($field_data_missing as $record=>$event_data)
				{
					// If we're beginning a new record, then remove the last record from arrays to conserve memory
					if ($last_record !== "" && $last_record !== $record) {
						unset($field_data_missing[$last_record], $branching_data[$last_record]);
					}
					// print round(memory_get_usage()/1024/1024,2) . " MB (record $record)\n";
					foreach ($event_data as $event_id=>$data)
					{
						foreach ($data as $field=>$value)
						{
							// Set default flag							
							$addDiscrep = true;
							// If rule pd-3/pd-6, then check branching logic to see if we should ignore this
							if (($rule_id == 'pd-3' || $rule_id == 'pd-6') && isset($branching_fields[$field]) && !isset($branching_fields_ignore[$field]))
							{
								// Get and parse the branching logic
								$branching_logic = $branching_fields[$field];
								// Replace literal values into logic to get the literal string
								$literalLogic = $this->formatRuleLogicLiteral($branching_logic, $branching_data[$record]);
								// print "$record, $field: $branching_logic\nliteral: ";var_dump($literalLogic);print "\n";				
								// Apply the logic and get the result
								if ($literalLogic === false || ($literalLogic !== false && $this->applyRuleLogic($literalLogic, $rule_attr) === 1)) 
								{
									// Ignore this hidden field, so set flag accordingly
									$addDiscrep = false;							
								}
							}
							// If we're set to ignore this field (because we can't use it's logic), then ignore it
							elseif (isset($branching_fields_ignore[$field]))
							{
								$addDiscrep = false;
							}
							// For longitudinal projects, make sure that this field's form has been designated for an event and is not orphaned.
							if ($longitudinal && !in_array($Proj->metadata[$field]['form_name'], $Proj->eventsForms[$event_id]))
							{
								$addDiscrep = false;
							}							
							// Add discrepancy
							if ($addDiscrep)
							{
								// Set the $value variable as HTML link to data entry page
								$value_html = "<a style='color:#888;' target='_blank' href='" . APP_PATH_WEBROOT . "DataEntry/index.php?pid=" . PROJECT_ID 
												. "&id=$record&event_id=$event_id&page=" . $Proj->metadata[$field]['form_name'] 
												. "&fldfocus=$field#$field-tr'>{$lang['dataqueries_71']}</a>";
								// Is this record-event excluded for this rule?
								$excludeRecEvt = (isset($excluded[$record][$event_id][$field]) ? 1 : 0);
								// Save result
								$this->saveLogicCheckResults($rule_id, $record, $event_id, $rule_attr['logic'], $literalLogic, "$field = $value_html", $excludeRecEvt, 0, $field);
								// If record is in a DAG, then get the group_id and increment the DAG discrepancy count
								if (isset($this->dag_records[$record]))
								{
									$group_id = $this->dag_records[$record];
									$dag_discrep[$group_id]++;
								}
							}
						}
					}
					// Set for next loop
					$last_record = $record;
				}
				// Set the DAG discrepancy count array for this rule
				$this->dag_discrepancies[$rule_id] = $dag_discrep;					
				break;
				
			// Rule: Field validation errors
			case 'pd-4':
				// Get array of all available validation types
				$valTypes = getValTypes();
				// Add legacy values and back-end values to valTypes (since back-end values are different for date, int, float, etc)
				$valTypes['date'] = $valTypes['date_ymd'];
				$valTypes['datetime'] = $valTypes['datetime_ymd'];
				$valTypes['datetime_seconds'] = $valTypes['datetime_seconds_ymd'];
				$valTypes['int'] = $valTypes['integer'];
				$valTypes['float'] = $valTypes['number'];
				unset($valTypes['integer']);
				unset($valTypes['number']);
				// For MDY and DMY formats, give them the YMD regex since we're parsing the raw data, which will ALWAYS be in YMD format
				$valTypes['date_mdy']['regex_php'] = $valTypes['date_ymd']['regex_php'];
				$valTypes['date_dmy']['regex_php'] = $valTypes['date_ymd']['regex_php'];
				$valTypes['datetime_mdy']['regex_php'] = $valTypes['datetime_ymd']['regex_php'];
				$valTypes['datetime_dmy']['regex_php'] = $valTypes['datetime_ymd']['regex_php'];
				$valTypes['datetime_seconds_mdy']['regex_php'] = $valTypes['datetime_seconds_ymd']['regex_php'];
				$valTypes['datetime_seconds_dmy']['regex_php'] = $valTypes['datetime_seconds_ymd']['regex_php'];
				// Set array holding just validation types
				$valTypesList = array_keys($valTypes);
				// Build array of fields that have validation
				$valFields = array();
				foreach ($Proj->metadata as $field=>$attr)
				{
					// Only looking for text fields (also include calc fields and sliders and treat them as number/integer-validated text fields)
					if ($attr['element_type'] == 'slider' || $attr['element_type'] == 'calc' || ($attr['element_type'] == 'text' && in_array($attr['element_validation_type'], $valTypesList)))
					{
						$valFields[] = $field;
					}
				}
				// FORM-LEVEL RIGHTS: Make sure user has form-level data accses to the form for ALL fields.
				// If does NOT have rights, then place fields in array so we can hide their data in the results.
				$fieldsNoAccess = $this->checkFormLevelRights($rule_id, $valFields);
				// Limit records pulled only to those in user's Data Access Group
				$group_sql = ""; 
				if ($user_rights['group_id'] != "") {
					$group_sql  = "and record in (" . pre_query("select record from redcap_data where field_name = '__GROUPID__' and value = '{$user_rights['group_id']}' and project_id = ".PROJECT_ID).")"; 
				}	
				// Now query the data table to validate the data for these fields and store in array $valErrors
				$valErrors = array();
				$sql = "select record, event_id, field_name, value from redcap_data where project_id = " . PROJECT_ID . " and value != '' 
						and record != '' and field_name in ('" . implode("', '", $valFields) . "') $group_sql
						and event_id in ($eventIdsSql) order by abs(record), record, event_id";
				$q = mysql_query($sql);
				while ($row = mysql_fetch_assoc($q))
				{
					$record = $row['record'];
					$event_id = $row['event_id'];
					$field = $row['field_name'];
					$value = html_entity_decode($row['value'], ENT_QUOTES);
					// Get the validation type of the field for this data point (also include calc fields and sliders and treat them as number-validated text fields)
					if ($Proj->metadata[$field]['element_type'] == 'text') {
						$valType = $Proj->metadata[$field]['element_validation_type'];
					} elseif ($Proj->metadata[$field]['element_type'] == 'calc') {
						$valType = 'float';
					} elseif ($Proj->metadata[$field]['element_type'] == 'slider') {
						$valType = 'int';
					}
					## Use RegEx to evaluate the value based upon validation type
					// Set regex pattern to use for this field
					$regex_pattern = $valTypes[$valType]['regex_php'];
					// Run the value through the regex pattern
					preg_match($regex_pattern, $value, $regex_matches);
					// Was it validated? (If so, will have a value in 0 key in array returned.)
					$failed_regex = (!isset($regex_matches[0]));
					// Set error message if failed regex
					if ($failed_regex)
					{
						// If a DMY or MDY date, then convert value to that format for display
						$value = $this->convertDateFormat($field, $value);
						// Set the $value variable as HTML link to data entry page
						if (in_array($field, $fieldsNoAccess)) {
							$value_html =  "<span style='color:#888;'>{$lang['dataqueries_72']}</span><br>
											<span style='color:#800000;'>{$lang['dataqueries_73']}</span>";
						} else {
							// Set the $value variable as HTML link to data entry page
							$value_html = "<a target='_blank' href='" . APP_PATH_WEBROOT . "DataEntry/index.php?pid=" . PROJECT_ID 
										. "&id=$record&event_id=$event_id&page=" . $Proj->metadata[$field]['form_name'] 
										. "&fldfocus=$field#$field-tr'>".htmlspecialchars($value, ENT_QUOTES)."</a>";
						}
						// Is this record-event excluded for this rule?
						$excludeRecEvt = (isset($excluded[$record][$event_id][$field]) ? 1 : 0);
						// Save result
						$this->saveLogicCheckResults($rule_id, $record, $event_id, $rule_attr['logic'], $literalLogic, "$field = $value_html", $excludeRecEvt, 0, $field);
						// If record is in a DAG, then get the group_id and increment the DAG discrepancy count
						if (isset($this->dag_records[$record]))
						{
							$group_id = $this->dag_records[$record];
							$dag_discrep[$group_id]++;
						}
					}
				}
				mysql_free_result($q);
				// Set the DAG discrepancy count array for this rule
				$this->dag_discrepancies[$rule_id] = $dag_discrep;	
				break;
				
			// Rule: Outliers for numerical fields
			case 'pd-5':
				// First create a fieldname array for just numerical fields (int, float, calc, slider)
				$numericalFields = array();
				foreach ($Proj->metadata as $field=>$attr)
				{
					if ($attr['element_type'] == 'calc' || $attr['element_type'] == 'slider' || 
						($attr['element_type'] == 'text' && ($attr['element_validation_type'] == 'int' || $attr['element_validation_type'] == 'float')))
					{
						$numericalFields[] = $field;
					}
				}
				// FORM-LEVEL RIGHTS: Make sure user has form-level data accses to the form for ALL fields.
				// If does NOT have rights, then place fields in array so we can hide their data in the results.
				$fieldsNoAccess = $this->checkFormLevelRights($rule_id, $numericalFields);
				// Limit records pulled only to those in user's Data Access Group
				$group_sql = ""; 
				if ($user_rights['group_id'] != "") {
					$group_sql  = "and record in (" . pre_query("select record from redcap_data where field_name = '__GROUPID__' and value = '{$user_rights['group_id']}' and project_id = ".PROJECT_ID).")"; 
				}
				// Query to pull all existing data for this form and place into array
				$fieldData = array();
				$recordData = array();
				$sql = "select record, event_id, field_name, value from redcap_data where project_id = ".PROJECT_ID." and record != ''
						and field_name in ('" . implode("', '", $numericalFields) . "') and value != '' $group_sql
						and event_id in ($eventIdsSql) order by abs(record), record, event_id";
				$q = mysql_query($sql);
				while ($row = mysql_fetch_assoc($q)) 
				{
					// If one of our number fields does not have a numerical value, then skip it. (HOW DOES THIS AFFECT MISSING THOUGH???)
					if (!is_numeric($row['value'])) continue;
					// Add value to field data array (only has values)
					$fieldData[$row['field_name']][] = $row['value'];
					// Add value to record data array (has values associated with specific record-event)
					$recordData[$row['field_name']][$row['record']][$row['event_id']][] = $row['value'];
				}
				mysql_free_result($q);
				// Now that we have all data, loop through it and determine missing value count and stats
				foreach ($recordData as $field=>$record_event)
				{	
					// If we only have 1 record with data for this field, then skip it (cannnot properly perform stdev)
					if (count($record_event) > 1)
					{
						// Setup up math constraints for this field
						$stdev = stdev($fieldData[$field]);
						$stdev_display = number_format($stdev, 2, '.', ',');
						// Make sure the stdev is not 0 (not useful if so)
						if ($stdev != 0)
						{
							$median = median($fieldData[$field]);
							$two_stdev_upper = $median + ($stdev * 2);
							$two_stdev_lower = $median - ($stdev * 2);
							// Now loop through all values and note which are outliers
							foreach ($record_event as $record=>$event_data)
							{
								foreach ($event_data as $event_id=>$value_data)
								{
									foreach ($value_data as $value)
									{
										// Is it an outlier?
										if ($value <= $two_stdev_lower || $value >= $two_stdev_upper)
										{
											// Set the $value variable as HTML link to data entry page
											if (in_array($field, $fieldsNoAccess)) {
												$value_html =  "<span style='color:#888;'>{$lang['dataqueries_72']}</span><br>
																<span style='color:#800000;'>{$lang['dataqueries_73']}</span>";
											} else {
												// Set the $value variable as HTML link to data entry page
												$value_html = "<a target='_blank' href='" . APP_PATH_WEBROOT . "DataEntry/index.php?pid=" . PROJECT_ID 
															. "&id=$record&event_id=$event_id&page=" . $Proj->metadata[$field]['form_name'] 
															. "&fldfocus=$field#$field-tr'>".htmlspecialchars($value, ENT_QUOTES)."</a>";
											}
											$data_display = "$field = $value_html<br><span style='color:gray;'>({$lang['dataqueries_74']} {$median}{$lang['dataqueries_75']} {$stdev_display})</span>";
											// Is this record-event excluded for this rule?
											$excludeRecEvt = (isset($excluded[$record][$event_id][$field]) ? 1 : 0);
											// Save result
											$this->saveLogicCheckResults($rule_id, $record, $event_id, '', '', $data_display, $excludeRecEvt, 0, $field);
											// If record is in a DAG, then get the group_id and increment the DAG discrepancy count
											if (isset($this->dag_records[$record]))
											{
												$group_id = $this->dag_records[$record];
												$dag_discrep[$group_id]++;
											}
										}
									}
								}
							}
						}
					}
				}
				// Set the DAG discrepancy count array for this rule
				$this->dag_discrepancies[$rule_id] = $dag_discrep;	
				break;	
				
			// Rule: Hidden fields that contain values
			case 'pd-7':
				// Store field names and data related to branching in arrays
				$branching_fields = array();
				$branching_fields_utilized = array();
				$branching_fields_ignore = array();
				$branching_data = array();
				// Loop through metadata and get all fields that have branching logic
				foreach ($Proj->metadata as $field=>$attr)
				{
					$this_branching = trim($attr['branching_logic']);
					if ($this_branching != "")
					{
						// Go ahead and decode it and format it to prep for parsing
						$this_branching = $this->formatRuleLogic(label_decode($this_branching));
						// If there is an issue in the logic, then we cannot use it, so skip it
						$checkRuleLogicBr = $this->checkRuleLogic($this_branching);
						if ($checkRuleLogicBr === false || (is_array($checkRuleLogicBr) && !empty($checkRuleLogicBr))) {
							// Add field to ignore array because it's logic is not usable
							$branching_fields_ignore[$field] = true;
						} else {
							$branching_fields[$field] = $this_branching;
							// Obtain the fields utilized in each field's branching logic
							foreach (array_keys(getBracketedFields($this_branching, true, true, true)) as $utilized_field)
							{
								$branching_fields_utilized[$utilized_field] = true;
							}
						}
					}
				}
				// FORM-LEVEL RIGHTS: Make sure user has form-level data accses to the form for ALL fields.
				// If does NOT have rights, then place fields in array so we can hide their data in the results.
				$fieldsNoAccess = $this->checkFormLevelRights($rule_id, array_keys($branching_fields));	
				// Limit records pulled only to those in user's Data Access Group
				$group_sql = ""; 
				if ($user_rights['group_id'] != "") {
					$group_sql  = "and record in (" . pre_query("select record from redcap_data where field_name = '__GROUPID__' and value = '{$user_rights['group_id']}' and project_id = ".PROJECT_ID).")"; 
				}
				// Create array of all records-events-field's values for branching fields
				$field_data = array();
				$sql = "select record, event_id, field_name, value from redcap_data where project_id = " . PROJECT_ID . " and value != '' 
						and record != '' and field_name in ('" . implode("', '", array_keys($branching_fields)) . "') 
						$group_sql and event_id in ($eventIdsSql) order by abs(record), record, event_id";
				$q = mysql_query($sql);
				while ($row = mysql_fetch_assoc($q))
				{
					// Set each record-event with list of blank fields by default
					$field_data[$row['record']][$row['event_id']][$row['field_name']] = $row['value'];
				}
				mysql_free_result($q);
				// Now query all records for fields used in all branching logic fields (trying to minimize the data returned here).
				$sql = "select record, event_id, field_name, value from redcap_data where project_id = " . PROJECT_ID . "
						and field_name in ('" . implode("', '", array_keys($branching_fields_utilized)) . "')
						and event_id in ($eventIdsSql) and value != ''";
				$q = mysql_query($sql);
				while ($row = mysql_fetch_assoc($q))
				{
					// Set each record-event-field's value
					$branching_data[$row['record']][$row['event_id']][$row['field_name']] = $row['value'];
				}
				mysql_free_result($q);				
				// Now we have an array with all values for all records-events for all branching fields, so loop through it and add to results
				foreach ($field_data as $record=>$event_data)
				{
					foreach ($event_data as $event_id=>$data)
					{
						foreach ($data as $field=>$value)
						{
							// Check branching logic to see if we should ignore this
							if (isset($branching_fields[$field]) && !isset($branching_fields_ignore[$field]))
							{
								// Get and parse the branching logic
								$branching_logic = $branching_fields[$field];
								// Replace literal values into logic to get the literal string
								$literalLogic = $this->formatRuleLogicLiteral($branching_logic, $branching_data[$record]);
								// print "$record, $field: $branching_logic\nliteral: ";var_dump($literalLogic);print "\n";				
								// Apply the logic to determine if the field is hidden or not
								$isHidden = ($literalLogic === false || ($literalLogic !== false && $this->applyRuleLogic($literalLogic, $rule_attr) === 1));
								// If field is hidden BUT contains value, then place in results as a discrepancy
								if ($isHidden)
								{	
									// If a DMY or MDY date, then convert value to that format for display
									$value = $this->convertDateFormat($field, $value);
									// Set the $value variable as HTML link to data entry page
									if (in_array($field, $fieldsNoAccess)) {
										$value_html =  "<span style='color:#888;'>{$lang['dataqueries_72']}</span><br>
														<span style='color:#800000;'>{$lang['dataqueries_73']}</span>";
									} else {
										// Set the $value variable as HTML link to data entry page
										$value_html = "<a target='_blank' href='" . APP_PATH_WEBROOT . "DataEntry/index.php?pid=" . PROJECT_ID 
													. "&id=$record&event_id=$event_id&page=" . $Proj->metadata[$field]['form_name'] 
													. "&fldfocus=$field#$field-tr'>".htmlspecialchars($value, ENT_QUOTES)."</a>";
									}
									// Is this record-event excluded for this rule?
									$excludeRecEvt = (isset($excluded[$record][$event_id][$field]) ? 1 : 0);
									// Save result
									$this->saveLogicCheckResults($rule_id, $record, $event_id, '', '', "$field = $value_html", $excludeRecEvt, 0, $field);
									// If record is in a DAG, then get the group_id and increment the DAG discrepancy count
									if (isset($this->dag_records[$record]))
									{
										$group_id = $this->dag_records[$record];
										$dag_discrep[$group_id]++;
									}
								}
							}
						}
					}
				}
				// Set the DAG discrepancy count array for this rule
				$this->dag_discrepancies[$rule_id] = $dag_discrep;	
				break;
				
			// Rule: Multiple choice fields with invalid values
			case 'pd-8':
				// First create a fieldname array containing vars of all multiple choice fields with fieldname as key and their options as element
				$mc_fields = array();
				$mc_fieldtypes = array('radio', 'select', 'advcheckbox', 'checkbox', 'yesno', 'truefalse', 'sql');
				foreach ($Proj->metadata as $field=>$attr)
				{
					// Only get MC fields
					if (in_array($attr['element_type'], $mc_fieldtypes))
					{
						// Convert sql field types' query result to an enum format
						if ($attr['element_type'] == "sql")
						{
							$attr['element_enum'] = getSqlFieldEnum($attr['element_enum']);
						}
						// Load status yesno choices
						elseif ($attr['element_type'] == "yesno")
						{
							$attr['element_enum'] = YN_ENUM;
						}
						// Load status truefalse choices
						elseif ($attr['element_type'] == "truefalse")
						{
							$attr['element_enum'] = TF_ENUM;
						}
						// Add field and it's MC options to array
						$mc_fields[$field] = parseEnum($attr['element_enum']);
					}
				}
				// FORM-LEVEL RIGHTS: Make sure user has form-level data accses to the form for ALL fields.
				// If does NOT have rights, then place fields in array so we can hide their data in the results.
				$fieldsNoAccess = $this->checkFormLevelRights($rule_id, array_keys($mc_fields));	
				// Limit records pulled only to those in user's Data Access Group
				$group_sql = ""; 
				if ($user_rights['group_id'] != "") {
					$group_sql  = "and record in (" . pre_query("select record from redcap_data where field_name = '__GROUPID__' and value = '{$user_rights['group_id']}' and project_id = ".PROJECT_ID).")"; 
				}
				// Create array of all records-events in the data table with their value
				$mc_invalid_data = array();
				$sql = "select record, event_id, field_name, value from redcap_data where project_id = " . PROJECT_ID . " and value != '' 
						and record != '' $group_sql and field_name in ('" . implode("', '", array_keys($mc_fields)) . "') 
						and event_id in ($eventIdsSql) order by abs(record), record, event_id";
				$q = mysql_query($sql);
				while ($row = mysql_fetch_assoc($q))
				{
					// If value isn't a valid value, then put in array
					if (!isset($mc_fields[$row['field_name']][$row['value']]))
					{
						$record = $row['record'];
						$event_id = $row['event_id'];
						$field = $row['field_name'];
						$value = $row['value'];
						// Set each record-event with list of blank fields by default
						if ($Proj->metadata['element_type'] == 'checkbox') {
							$mc_invalid_data[$row['record']][$row['event_id']][] = $row['value'];
						} else {
							$mc_invalid_data[$row['record']][$row['event_id']] = $row['value'];
						}
						// Set the $value variable as HTML link to data entry page
						if (in_array($field, $fieldsNoAccess)) {
							$value_html =  "<span style='color:#888;'>{$lang['dataqueries_72']}</span><br>
											<span style='color:#800000;'>{$lang['dataqueries_73']}</span>";
						} else {
							// Set the $value variable as HTML link to data entry page
							$value_html = "<a target='_blank' href='" . APP_PATH_WEBROOT . "DataEntry/index.php?pid=" . PROJECT_ID 
										. "&id=$record&event_id=$event_id&page=" . $Proj->metadata[$field]['form_name'] 
										. "&fldfocus=$field#$field-tr'>".htmlspecialchars($value, ENT_QUOTES)."</a>";
						}
						// Is this record-event excluded for this rule?
						$excludeRecEvt = (isset($excluded[$record][$event_id][$field]) ? 1 : 0);
						// Save result
						$this->saveLogicCheckResults($rule_id, $record, $event_id, '', '', "$field = $value_html", $excludeRecEvt, 0, $field);
						// If record is in a DAG, then get the group_id and increment the DAG discrepancy count
						if (isset($this->dag_records[$record]))
						{
							$group_id = $this->dag_records[$record];
							$dag_discrep[$group_id]++;
						}
					}
				}
				mysql_free_result($q);
				// Set the DAG discrepancy count array for this rule
				$this->dag_discrepancies[$rule_id] = $dag_discrep;	
				break;
				
			// Rule: Field validation errors - out of range
			case 'pd-9':				
				// First create a fieldname array for just fields that have min/max validation
				$fields = array();
				foreach ($Proj->metadata as $field=>$attr)
				{
					if ($attr['element_validation_min'] != '')
					{
						$fields[$field]['min'] = $attr['element_validation_min'];
					}
					if ($attr['element_validation_max'] != '')
					{
						$fields[$field]['max'] = $attr['element_validation_max'];
					}
				}
				// FORM-LEVEL RIGHTS: Make sure user has form-level data accses to the form for ALL fields.
				// If does NOT have rights, then place fields in array so we can hide their data in the results.
				$fieldsNoAccess = $this->checkFormLevelRights($rule_id, array_keys($fields));	
				// Limit records pulled only to those in user's Data Access Group
				$group_sql = ""; 
				if ($user_rights['group_id'] != "") {
					$group_sql  = "and record in (" . pre_query("select record from redcap_data where field_name = '__GROUPID__' and value = '{$user_rights['group_id']}' and project_id = ".PROJECT_ID).")"; 
				}
				// Query to pull all existing data for this form and place into array
				$recordData = array();
				$sql = "select record, event_id, field_name, value from redcap_data where project_id = ".PROJECT_ID." and record != ''
						and field_name in ('" . implode("', '", array_keys($fields)) . "') and value != '' $group_sql
						and event_id in ($eventIdsSql) order by abs(record), record, event_id";
				$q = mysql_query($sql);
				while ($row = mysql_fetch_assoc($q)) 
				{
					// Add value to record data array (has values associated with specific record-event-field)
					$recordData[$row['record']][$row['event_id']][$row['field_name']] = $row['value'];
				}		
				mysql_free_result($q);		
				// Now we have an array with all values for all records-events for all fields with min/max validation, so loop through data
				foreach ($recordData as $record=>$event_data)
				{
					foreach ($event_data as $event_id=>$data)
					{
						foreach ($data as $field=>$value)
						{
							// Set default flag for out-of-range error
							$outOfRange = false;
							// Check min, if exists
							if (isset($fields[$field]['min']) && $value < $fields[$field]['min'])
							{
								$outOfRange = true;
							}
							// Check max, if exists
							if (!$outOfRange && isset($fields[$field]['max']) && $value > $fields[$field]['max'])
							{
								$outOfRange = true;
							}
							// If out of range, then output to results
							if ($outOfRange)
							{
								// If a DMY or MDY date, then convert value to that format for display
								$value = $this->convertDateFormat($field, $value);
								// Set the $value variable as HTML link to data entry page
								if (in_array($field, $fieldsNoAccess)) {
									$value_html =  "<span style='color:#888;'>{$lang['dataqueries_72']}</span><br>
													<span style='color:#800000;'>{$lang['dataqueries_73']}</span>";
								} else {
									// Set the $value variable as HTML link to data entry page
									$value_html = "<a target='_blank' href='" . APP_PATH_WEBROOT . "DataEntry/index.php?pid=" . PROJECT_ID 
												. "&id=$record&event_id=$event_id&page=" . $Proj->metadata[$field]['form_name'] 
												. "&fldfocus=$field#$field-tr'>".htmlspecialchars($value, ENT_QUOTES)."</a>";
								}
								// Set label for min/max display next to value
								$data_display = "$field = $value_html<br><span style='color:gray;'>(";
								if (isset($fields[$field]['min'])) {
									$data_display .= "min: " . $this->convertDateFormat($field, $fields[$field]['min']);
								}
								if (isset($fields[$field]['min']) && isset($fields[$field]['max'])) {
									$data_display .= ", ";
								}
								if (isset($fields[$field]['max'])) {
									$data_display .= "max: " . $this->convertDateFormat($field, $fields[$field]['max']);
								}
								$data_display .= ")</span>";
								// Is this record-event excluded for this rule?
								$excludeRecEvt = (isset($excluded[$record][$event_id][$field]) ? 1 : 0);
								// Save result
								$this->saveLogicCheckResults($rule_id, $record, $event_id, '', '', $data_display, $excludeRecEvt, 0, $field);
								// If record is in a DAG, then get the group_id and increment the DAG discrepancy count
								if (isset($this->dag_records[$record]))
								{
									$group_id = $this->dag_records[$record];
									$dag_discrep[$group_id]++;
								}
							}
						}
					}
				}
				// Set the DAG discrepancy count array for this rule
				$this->dag_discrepancies[$rule_id] = $dag_discrep;	
				break;
		}
		
		// If no discrepancies exist for this rule, then add it as empty results
		if (empty($this->logicCheckResults[$rule_id]))
		{
			$this->logicCheckResults[$rule_id] = array();
		}
	
	}
	
	// Execute a single USER-DEFINED rule. Check all records' values for this rule.
	public function executeRule($rule_id)
	{
		global $lang, $Proj, $longitudinal, $user_rights;
		
		// Check if any DAGs exist. If so, create a new column in the table for each DAG.
		$dags = $Proj->getGroups();
		// If DAGs exist, then set up arrays to collect which records are in which DAGs and a count of discrepancies for each DAG
		$dag_discrep = array();
		if (!empty($dags) && $user_rights['group_id'] == "")
		{
			// Set initial discrepancy count as 0 for each DAG
			foreach (array_keys($dags) as $group_id)
			{
				$dag_discrep[$group_id] = 0;
			}
			// Get a list of records in a DAG
			$sql = "select record, value from redcap_data where project_id = " . PROJECT_ID . " and field_name = '__GROUPID__'";
			$q = mysql_query($sql);
			while ($row = mysql_fetch_assoc($q))
			{
				$this_group_id = $row['value'];
				// Make sure the DAG actually exists (in case was deleted but value remained in redcap_data)
				if (isset($dags[$this_group_id])) {
					$this->dag_records[$row['record']] = $this_group_id;
				}
			}
		}
		
		// Check if this is a PRE-DEFINED RULE (will not be a number)
		if (!is_numeric($rule_id))
		{
			$this->executePredefinedRule($rule_id, $dag_discrep);
			return;
		}
		
		// Get the rule and its attributes
		$rule_attr = $this->getRule($rule_id);
		
		// Set the logic variable
		$logic = $rule_attr['logic'];
		// If there is an issue in the logic, then return an error message and stop processing
		$checkRuleLogic = $this->checkRuleLogic($logic);
		if ($checkRuleLogic === false || (is_array($checkRuleLogic) && !empty($checkRuleLogic))) {
			// Send back error message
			$this->logicHasErrors();
		}
		
		// Format the logic into PHP format
		$logic = $this->formatRuleLogic($logic);
		
		// Get unique event names (with event_id as key)
		$events = $Proj->getUniqueEventNames();			
		
		// Array to collect list of all fields used in the logic
		$fields = array();
		$eventsUtilized = array();
		// Loop through fields used in the logic. Also, parse out any unique event names, if applicable
		foreach (array_keys(getBracketedFields($rule_attr['logic'], true, true, false)) as $this_field)
		{
			// Check if has dot (i.e. has event name included)
			if (strpos($this_field, ".") !== false) {
				list ($this_event_name, $this_field) = explode(".", $this_field, 2);
				// Get the event_id
				$this_event_id = array_search($this_event_name, $events);
				// Add event/field to $eventsUtilized array
				$eventsUtilized[$this_event_id][$this_field] = true;
			} else {
				// Add event/field to $eventsUtilized array
				$eventsUtilized['all'][$this_field] = true;
			}
			// Add field to array
			$fields[] = $this_field;
			// Verify that the field really exists (may have been deleted). If not, stop here with an error.
			if (!isset($Proj->metadata[$this_field])) $this->logicHasErrors();
		}
		
		// FORM-LEVEL RIGHTS: Make sure user has form-level data accses to the form for ALL fields.
		// If does NOT have rights, then show nothing and give error message.
		$this->checkFormLevelRights($rule_id, $fields);
		
		// Get default values for all records (all fields get value '', except Form Status and checkbox fields get value 0)
		$default_values = array();
		foreach ($fields as $this_field)
		{
			// Loop through all designated events so that each event
			foreach (array_keys($Proj->eventInfo) as $this_event_id)
			{			
				// If is a real field or not
				if (isset($Proj->metadata[$this_field])) {
					// Check a checkbox or Form Status field
					if ($Proj->metadata[$this_field]['element_type'] == 'checkbox') {
						// Loop through all choices and set each as 0
						foreach (array_keys(parseEnum($Proj->metadata[$this_field]['element_enum'])) as $choice) {
							$default_values[$this_event_id][$this_field][$choice] = '0';
						}
					} elseif ($this_field == $Proj->metadata[$this_field]['form_name'] . "_complete") {
						// Set as 0
						$default_values[$this_event_id][$this_field] = '0';
					} else {
						// Set as ''
						$default_values[$this_event_id][$this_field] = '';
					}
				}
			}
		}
		
		// STATUS & EXCLUDED: Get a list of any record-event's for this rule that have been excluded (so we know what to exclude)
		// and the status for ALL.
		$excluded = array();
		$statuses = array();
		$sql = "select record, event_id, exclude, status from redcap_data_quality_status where rule_id = $rule_id
				and project_id = " . PROJECT_ID;
		$q = mysql_query($sql);
		while ($row = mysql_fetch_assoc($q))
		{
			// Add status
			$statuses[$row['record']][$row['event_id']] = $row['status'];
			// If excluded
			if ($row['exclude']) {
				$excluded[$row['record']][$row['event_id']] = true;
			}
		}
			
		// Limit records pulled only to those in user's Data Access Group
		$group_sql = ""; 
		if ($user_rights['group_id'] != "") {
			$group_sql  = "and record in (" . pre_query("select record from redcap_data where field_name = '__GROUPID__' and value = '{$user_rights['group_id']}' and project_id = ".PROJECT_ID).")"; 
		}
		// Query the values of the logic fields for ALL records
		$sql = "select record, event_id, field_name, value from redcap_data where project_id = " . PROJECT_ID 
			 . " and field_name in ('" . implode("', '", $fields) . "') and value != '' and record != '' $group_sql"
			 . " order by abs(record), record, event_id";
		$q = mysql_query($sql);
		// Set intial values
		$event_id = 0;
		$record = "";
		$record_data = array();
		//print mysql_num_rows($q) . " rows\n";
		//print $sql."\n\n";
		// Loop through data one record at a time
		while ($row = mysql_fetch_assoc($q))
		{
			// Decode the value
			$row['value'] = label_decode($row['value']);
			// Add initial default data for first loop
			if ($event_id === 0)
			{
				$record_data[$row['event_id']] = $default_values[$row['event_id']];
			}
			// Check if beginning new record. If so, then apply the rule logic to the record.
			elseif (($record != "" && $row['record'] !== $record) || ($event_id !== 0 && $row['event_id'] !== $event_id))
			{
				// print "\n".$record."\n";print_r($record_data);
				// Only process this record-event if the event in question is being utilized
				if (!empty($eventsUtilized['all']) || !empty($eventsUtilized[$event_id]))
				{
					// Replace literal values into logic to get the literal string
					$literalLogic = $this->formatRuleLogicLiteral($logic, $record_data);
					// print "\n$logic\n$literalLogic\n";
					// If the literal logic returns false, then skip this record-event for this rule
					if ($literalLogic !== false)
					{
						// Apply the logic and get the result
						$logicCheckResult = $this->applyRuleLogic($literalLogic, $rule_attr);
						// Save the results of logic check
						if ($logicCheckResult !== 1) 
						{
							// Set the display for the fields/values used in the logic to display in the results table
							$data_display = $this->setResultTableDataDisplay($fields, $record, $record_data);
							// If record is in a DAG, then get the group_id and increment the DAG discrepancy count
							if (isset($this->dag_records[$record]))
							{
								$group_id = $this->dag_records[$record];
								$dag_discrep[$group_id]++;
							}
							// Is this record-event excluded for this rule?
							$excludeRecEvt = (isset($excluded[$record][$event_id]) ? 1 : 0);
							// Get the status of this record-event (default is 0)
							$status = (isset($statuses[$record][$event_id]) ? $statuses[$record][$event_id] : 0);
							// Store results in array
							$this->saveLogicCheckResults($rule_id, $record, $event_id, $rule_attr['logic'], $literalLogic, $data_display, $excludeRecEvt, $status);
						}
					}
				}
				// Now empty the record data for the next record, but ONLY IF we are 
				// starting a new record (OR starting a new event in same record) AND only using a single event's data for this logic
				// (i.e. do not empty the record data here IF using multi-event logic).
				// if ($record != "" && $row['record'] !== $record)
				if (((isset($eventsUtilized['all']) && count($eventsUtilized) == 1) || (!isset($eventsUtilized['all']) && count($eventsUtilized,1) == 2)) && 
					(($record != "" && $row['record'] !== $record) || ($event_id !== 0 && $row['event_id'] !== $event_id))) 
				{
					$record_data = array();
				}
				$record_data[$row['event_id']] = $default_values[$row['event_id']];
			}
			// Set values for this loop
			$event_id = $row['event_id'];
			$record   = $row['record'];	
			// Add the value into the array (double check to make sure the event_id still exists)
			if (isset($events[$event_id]) && in_array($row['field_name'], $fields))
			{
				if ($Proj->metadata[$row['field_name']]['element_type'] == 'checkbox') {
					// Add checkbox value
					$record_data[$event_id][$row['field_name']][$row['value']] = 1;
				} else {
					// Non-checkbox value
					$record_data[$event_id][$row['field_name']] = $row['value'];
				}
			}
		}
		// Apply the logic for the LAST RECORD
		if (($record != "" && $row['record'] !== $record) || ($event_id !== 0 && $row['event_id'] !== $event_id))
		{
			// print "\n".$record."\n";print_r($record_data);
			// Replace literal values into logic to get the literal string
			$literalLogic = $this->formatRuleLogicLiteral($logic, $record_data);
			// print "\n$logic\n$literalLogic\n";
			// If the literal logic returns false, then skip this record-event for this rule
			if ($literalLogic !== false)
			{
				// Apply the logic and get the result
				$logicCheckResult = $this->applyRuleLogic($literalLogic, $rule_attr);
				// Save the results of logic check
				if ($logicCheckResult !== 1) 
				{
					// Set the display for the fields/values used in the logic to display in the results table
					$data_display = $this->setResultTableDataDisplay($fields, $record, $record_data);
					// If record is in a DAG, then get the group_id and increment the DAG discrepancy count
					if (isset($this->dag_records[$record]))
					{
						$group_id = $this->dag_records[$record];
						$dag_discrep[$group_id]++;
					}
					// Is this record-event excluded for this rule?
					$excludeRecEvt = (isset($excluded[$record][$event_id]) ? 1 : 0);
					// Get the status of this record-event (default is 0)
					$status = (isset($statuses[$record][$event_id]) ? $statuses[$record][$event_id] : 0);
					// Store results in array
					$this->saveLogicCheckResults($rule_id, $record, $event_id, $rule_attr['logic'], $literalLogic, $data_display, $excludeRecEvt, $status);
				}
			}
		}
		// If no discrepancies exist for this rule, then add it as empty results
		if (empty($this->logicCheckResults[$rule_id]))
		{
			$this->logicCheckResults[$rule_id] = array();
		}
		// Set the DAG discrepancy count array for this rule
		$this->dag_discrepancies[$rule_id] = $dag_discrep;
	}
		
	// Applies the literal logic and returns boolean
	public function applyRuleLogic($literalLogic, $rule_attr=null)
	{
		global $lang;
		// Now that the literal values have been added to the logic, test is logic is TRUE or FALSE
		try 
		{
			// Set values in $_GET array so we can retrieve them easily if an error occurs for the error message
			if ($rule_attr != null) {
				$_GET['error_rule_name'] = $lang['dataqueries_14'] . (is_numeric($rule_attr['order']) ? " #{$rule_attr['order']}" : "") . ": {$rule_attr['name']}";
			}
			// Eval the logic
			@eval("\$logicCheckResult = ($literalLogic);");
			// Return 1 for true and 0 for false (only return false if procedure failed due to error)
			return ($logicCheckResult === false ? 1 : 0);	
		} 
		catch (Exception $e) 
		{
			return false;
		}
	}
	
	// For date[time][_seconds] fields, return the format set for the field. (If not a date field or is YMD formatted date, then will ignore.)
	private function convertDateFormat($field, $value)
	{
		global $Proj;
		// Get field validation type, if exists
		$valType = $Proj->metadata[$field]['element_validation_type'];
		// If field is a date[time][_seonds] field with MDY or DMY formatted, then reformat the displayed date for consistency
		if (substr($valType, 0, 4) == 'date' && (substr($valType, -4) == '_mdy' || substr($valType, -4) == '_dmy'))
		{
			// Dates
			if ($valType == 'date_mdy') {
				$value = date_ymd2mdy($value);
			} elseif ($valType == 'date_dmy') {
				$value = date_ymd2dmy($value);
			} else {
				// Datetime and Datetime seconds
				list ($this_date, $this_time) = explode(" ", $value);
				if ($valType == 'datetime_mdy' || $valType == 'datetime_seconds_mdy') {
					$value = trim(date_ymd2mdy($this_date) . " " . $this_time);
				} elseif ($valType == 'datetime_dmy' || $valType == 'datetime_seconds_dmy') {
					$value = trim(date_ymd2dmy($this_date) . " " . $this_time);
				}
			}
		}
		// Return the value
		return $value;
	}
	
	// Set the display for the fields/values used in the logic to display in the results table as HTML
	private function setResultTableDataDisplay($fields, $record, $record_data)
	{
		global $Proj;
		// Capture the fields and values as an HTML array, then output as a string
		$html_array = array();
		// Loop through the fields and data
		foreach ($record_data as $event_id=>$event_data)
		{
			foreach ($event_data as $field=>$thisvalue)
			{
				if (is_array($thisvalue)) {
					foreach ($thisvalue as $choice=>$value) {
						// Set the $value variable as HTML link to data entry page
						$value_html = "<a target='_blank' href='" . APP_PATH_WEBROOT . "DataEntry/index.php?pid=" . PROJECT_ID 
									. "&id=$record&event_id=$event_id&page=" . $Proj->metadata[$field]['form_name'] 
									. "&fldfocus=$field#$field-tr'>".htmlspecialchars($value, ENT_QUOTES)."</a>";
						// Add html to array
						$html_array[] = "$field($choice): $value_html";
					}
				} else {
					$value = $thisvalue;
					// If a DMY or MDY date, then convert value to that format for display
					$value = $this->convertDateFormat($field, $value);
					// Set the $value variable as HTML link to data entry page
					$value_html = "<a target='_blank' href='" . APP_PATH_WEBROOT . "DataEntry/index.php?pid=" . PROJECT_ID 
								. "&id=$record&event_id=$event_id&page=" . $Proj->metadata[$field]['form_name'] 
								. "&fldfocus=$field#$field-tr'>".htmlspecialchars($value, ENT_QUOTES)."</a>";
					// Add html to array
					$html_array[] = "$field: $value_html";
				}
				// Remove the field from the $fields array (so we'll know to display blank values - it's not in data because of EAV model)
				$fields_key = array_search($field, $fields);
				unset($fields[$fields_key]);
			}
		}
		// Now loop through any fields left over that have no values
		foreach ($fields as $field)
		{
			$html_array[] = "$field:";
		}
		// Return as HTML string
		return implode("<br>", $html_array);
	}
		
	// Get the saved results of a logic check
	public function getLogicCheckResults()
	{
		return $this->logicCheckResults;
	}
		
	// Save the results of logic check
	private function saveLogicCheckResults($rule_id, $record, $event_id, $logic, $literalLogic, $data_display, $exclude, $status, $field_name='')
	{
		// Add info to the results array
		$this->logicCheckResults[$rule_id][] = array(
			'record' => $record,
			'event_id' => $event_id,
			// 'logic_original' => $logic,
			// 'logic_executed' => $literalLogic,
			'data_display' => $data_display,
			'exclude' => $exclude,
			'status' => $status,
			'field_name' => $field_name // Only used for pre-defined rules, which are specific to single fields
		);
	}
		
	// Load the table data for displaying the rules
	private function loadRulesTable()
	{
		global $Proj, $lang, $user_rights, $isIE;
		// Check if any DAGs exist. If so, create a new column in the table for each DAG.
		$dags = $Proj->getGroups();
		// Create the table for displaying the rules
		$rulesTableData = array();
		$counter = 1;
		$counterPdRule = 1;
		foreach ($this->getRules() as $rule_id=>$rule_attr)
		{
			// Do not show order number for pre-defined rules but instead show letters
			if (!is_numeric($rule_attr['order'])) {
				$rule_attr['order'] = "<span style='color:#888;'>" . strtolower($this->numtochars($counterPdRule++)) . "</span>";
			}
			// Add rule as row
			$rulesTableData[$counter] = array(
				"<div id='ruleid_{$rule_id}'><span style='display:none;'>{$rule_id}</span></div>", 
				"<div id='ruleorder_{$rule_id}' class='rulenum'>{$rule_attr['order']}</div>", 
				"<div id='rulename_{$rule_id}' rid='{$rule_id}' class='editname'>{$rule_attr['name']}</div>", 
				"<div id='rulelogic_{$rule_id}' rid='{$rule_id}' class='editlogic'>{$rule_attr['logic']}</div>",
				($user_rights['data_quality_execute'] ? "<div id='ruleexe_{$rule_id}' class='exebtn' style='height:22px;vertical-align:middle;'><button style='".($isIE ? "font-size:11px;" : "")."vertical-align:middle;' onclick=\"preExecuteRulesAjax('$rule_id',0);\">{$lang['dataqueries_80']}</button></div>" : "")
			);
			// If DAGs exist, add each as new column
			if (!empty($dags) && $user_rights['group_id'] == "")
			{
				foreach (array_keys($dags) as $group_id)
				{
					$rulesTableData[$counter][] = "<div id='ruleexe_{$rule_id}-{$group_id}' class='exegroup dagr_{$rule_id}'>&nbsp;</div>";
				}
			}
			// Add delete button (if have design rights)
			$rulesTableData[$counter][] = (is_numeric($rule_id) && $user_rights['data_quality_design']) ? "<div id='ruledel_{$rule_id}'><a href='javascript:;' onclick=\"deleteRule($rule_id);\"><img src='".APP_PATH_IMAGES."cross.png'></a></div>" : "";
			// Increment counter
			$counter++;
		}
		// Add extra row to add new rule (if have design rights)
		if ($user_rights['data_quality_design']) {
			$rulesTableData[$counter] = array(
				"", 
				"<button style='vertical-align:middle;' onclick='addNewRule();'>{$lang['design_171']}</button>", 
				"<div class='newname'>
					<textarea class='x-form-field notesbox' id='input_rulename_id_0' style='height:40px;margin:4px 0;width:95%;'></textarea>
					<div style='padding:0;'><b>{$lang['dataqueries_76']}</b></div>
					<div style='padding:5px 0 20px;color:#666;'>{$lang['dataqueries_77']}</div>
				 </div>", 
				"<div class='newlogic'>
					<textarea onblur=\"if(!checkLogicErrors(this.value,1)){validate_logic(this.value,'',0,'');}\" class='x-form-field notesbox' id='input_rulelogic_id_0' style='height:40px;margin:4px 0;width:95%;'></textarea>
					<div style='padding:0;'><b>{$lang['dataqueries_78']}</b></div>
					<div style='padding:5px 0 0;color:#666;'>(e.g. [age] < 18)</div>
					<div style='padding:5px 0 0;'><a href='javascript:;' style='font-size:10px;' onclick=\"helpPopup('DataQuality')\">{$lang['dataqueries_79']}</a></div>
				 </div>",
				"",
				""
			);
		} else {
			$rulesTableData[$counter] = array("", "", "", "", "", "");
		}
		// Set up the table headers
		$rulesTableHeaders = array(
								array(30, "", "center"),
								array(48, "<b>{$lang['dataqueries_14']} #</b>", "center"),
								array(251, "<b>{$lang['dataqueries_15']}</b>"),
								array(210, "<b>{$lang['dataqueries_16']}</b>&nbsp; {$lang['dataqueries_17']}"),
								array(100, ($user_rights['data_quality_execute'] ? "<div style='white-space:normal;word-wrap:normal;padding:0;'><b>{$lang['dataqueries_18']}</b></div>" : ""), "center")
							);
		// If DAGs exist, add each as new header column and also add new columns to "new rule" row at bottom
		if (!empty($dags) && $user_rights['group_id'] == "")
		{
			foreach ($dags as $group_id=>$group_name)
			{
				$rulesTableHeaders[] = array(50, "<div class='grouphdr'>$group_name</div>", "center");
				$rulesTableData[$counter][] = "";
			}
		}
		// Add column for delete button
		$rulesTableHeaders[] = array(30, ($user_rights['data_quality_design'] ? "<div style='font-size:10px;white-space:normal;word-wrap:normal;padding:0;'>{$lang['dataqueries_28']}</div>" : ""), "center");
		// Return the table headers and data
		return array($rulesTableHeaders, $rulesTableData);
	}
		
	// Display the table data for displaying the rules
	public function displayRulesTable()
	{	
		global $Proj, $user_rights, $lang;
		// Check if any DAGs exist. If so, create a new column in the table for each DAG.
		$dags = $Proj->getGroups();
		// Set the table width
		$width = 742 + ((!empty($dags) && $user_rights['group_id'] == "") ? count($dags)*62 : 0);
		// Load the rules table data
		list ($rulesTableHeaders, $rulesTableData) = $this->loadRulesTable();
		// Set table "title" with the execute button
		$title =   "<div style='width:620px'>
						<div style='float:left;font-size:13px;padding:5px 0 0 10px;'>
							{$lang['dataqueries_81']}
						</div>";
		if ($user_rights['data_quality_execute']) 
		{
			// "Execute All Rules" button
			$title .=  "<div style='float:right;padding:2px 0 0;text-align:right;font-weight:bold;'>
							<span id='execRuleProgress' style='display:none;color:#444;padding-right:10px;'>
								<img src='".APP_PATH_IMAGES."progress_circle.gif' class='imgfix'> 
								{$lang['dataqueries_82']} <span id='rule_num_progress'>0</span> {$lang['dataqueries_83']} <span id='rule_num_total'>0</span>
							</span>
							<span id='execRuleComplete' style='display:none;color:green;padding-left:5px;padding-right:10px;'>
								<img src='".APP_PATH_IMAGES."tick.png' class='imgfix'> 
								{$lang['dataqueries_84']}
							</span>
							<button id='execRuleBtn' onclick=\"this.disabled=true;$('#dq_results').html('');preExecuteRulesAjax(rule_ids,0);\">{$lang['dataqueries_85']}</button>
							&nbsp;<button id='clearBtn' disabled onclick=\"window.location.href=app_path_webroot+page+'?pid='+pid;\">{$lang['dataqueries_86']}</button>
						</div>";
		}
		$title .=  "	<div style='clear:both;height:0;padding:0;'></div>
					</div>";
		// Get html for the rules table
		$table_html = renderGrid("rules", $title, $width, "auto", $rulesTableHeaders, $rulesTableData, true, false, false);
		// Return the html
		return $table_html;
	}
		
	// Load the table data for the results of the rules check
	private function loadResultsTable()
	{
		global $longitudinal, $Proj, $lang, $user_rights;
		// Check if any DAGs exist. If so, create a new column in the table for each DAG.
		$dags = $Proj->getGroups();
		// Count exclusions
		$exclusion_count = 0;
		// Create the table for displaying the results of the rules check
		$resultsTableData = array();
		// Get logic results
		$logicResults = $this->getLogicCheckResults();
		// If DAGs exist, then reorder results grouped by DAG
		if (!empty($dags) && $user_rights['group_id'] == "")
		{
			// Add group_id, record, and event_id to arrays so we can do a multisort to sort them by DAG
			$group_ids = array();
			$records   = array();
			$events    = array();
			// Loop though all results
			foreach ($logicResults as $rule_id=>$results_list)
			{
				foreach ($results_list as $results)
				{
					$records[] = $results['record'];
					$events[]  = $results['event_id'];	
					$group_ids[] = (isset($this->dag_records[$results['record']])) ? $this->dag_records[$results['record']] : "";
				}
			}
			// Now sort the results by DAG, thus grouping them by DAG in the list
			array_multisort($group_ids, SORT_NUMERIC, $logicResults[$rule_id]);
			unset($records, $events, $group_ids, $results_list);
		}
		// Loop through results
		foreach ($logicResults as $rule_id=>$results_list)
		{
			// First, get comment counts for each record-event
			$comment_counts = $this->getCommentCount($rule_id);
			// Check how many results we have and limit it if too many (memory issues + just cannnot display that many rows in a browser)
			$resultCount = count($results_list);
			// Loop through all results
			foreach ($results_list as $result_key=>$results)
			{
				// EXCLUDED? Determine if need to show it if it's excluded
				$excludeAction = "";
				if ($results['exclude'] && isset($_POST['show_exclusions']) && !$_POST['show_exclusions']) {
					$exclusion_count++;
					continue;
				} elseif (!$results['exclude']) {
					// Show link to exclude this result
					$excludeAction = "<a href='javascript:;' style='font-size:10px;' onclick=\"excludeResult(this,'$rule_id',1,'{$results['record']}',{$results['event_id']},'{$results['field_name']}');\">{$lang['dataqueries_87']}</a>";
				} else {
					// Show link to remove the exclusion for this result
					$excludeAction = "<a href='javascript:;' style='font-size:10px;color:#800000;' onclick=\"excludeResult(this,'$rule_id',0,'{$results['record']}',{$results['event_id']},'{$results['field_name']}');\">{$lang['dataqueries_88']}</a>";
				}
				/* 
				## COMMENT LOG
				// Determine the comments count for this record and event
				if (isset($comment_counts[$results['record']][$results['event_id']])) {
					$this_comment_count = ($results['field_name'] != '') ? $comment_counts[$results['record']][$results['event_id']][$results['field_name']] : $comment_counts[$results['record']][$results['event_id']];
					$this_comment_color = '';
					$this_comment_text = ($this_comment_count > 1) ? $lang['dataqueries_02'] : $lang['dataqueries_01'];
					if (empty($this_comment_count)) {
						$this_comment_count = 0;
					}
				} else {
					$this_comment_count = 0;
				}
				if ($this_comment_count == 0) {
					$this_comment_color = 'color:#777';
					$this_comment_text = $lang['dataqueries_02'];
				}
				// Build the text for comment count
				$commentary = "<a href='javascript:;' title=\"".cleanHtml2($lang['dataqueries_03'])."\" style='text-decoration:underline;font-size:11px;$this_comment_color;' onclick=\"showComLog('$rule_id','{$results['record']}',{$results['event_id']},'{$results['field_name']}');\">$this_comment_count $this_comment_text</a>";
				*/
				// For longitudinal projects, add arm/event name to record display
				$record_eventname = $results['record'] 
								  . (($longitudinal && isset($Proj->eventInfo[$results['event_id']])) ? "<div class='dq_evtlabel'>" . $Proj->eventInfo[$results['event_id']]['name_ext'] : "");
				// Show label if this row is excluded
				if ($results['exclude']) {
					$record_eventname .= "<div class='dq_excludelabel'>{$lang['dataqueries_89']}</div>";
				}
				// Show DAG label if record is in a DAG
				if (isset($this->dag_records[$results['record']]) && $user_rights['group_id'] == "")
				{
					$group_id = $this->dag_records[$results['record']];
					$group_name = $dags[$group_id];
					$record_eventname .= "<div class='dq_daglabel'>($group_name)</div>";
				}
				// Set status label
				$status_label = (!is_numeric($this->rules[$rule_id]['order']) ? $this->status_labels[$this->default_status[$this->rules[$rule_id]['order']]] : $this->status_labels[$results['status']]);
				// Add rule as row
				$resultsTableData[] = array
				(
					$record_eventname, 
					$results['data_display'],// . "<br><br>".$results['logic_executed'],
					$status_label,
					$excludeAction
					// , $commentary
				);				
				// Free up memory as we go by deleting the result set as it is converted into the HTML table array form
				unset($logicResults[$rule_id][$result_key], $results_list[$result_key]);
				// If we have exceeded the max limit of results, then stop looping
				if ($result_key >= $this->resultLimit-1) break;
			}
		}
		// Free up memory
		unset($logicResults, $results_list, $this->logicCheckResults[$rule_id]);
		// Set up the table headers
		$resultsTableHeaders = array(
								array(140, "<b>{$lang['global_49']}</b>" . ((!empty($dags) && $user_rights['group_id'] == "") ? " " . $lang['dataqueries_26'] : "")),
								array(260, "<b>{$lang['dataqueries_25']}</b>"),
								array(110, "<b>{$lang['calendar_popup_08']}</b>", "center"),
								array(84, "{$lang['dataqueries_29']} <a href='javascript:;' onclick='explainExclude();'><img src='".APP_PATH_IMAGES."help.png' style='vertical-align:middle;'></a>", "center")
								// , array(120, "<b>{$lang['dataqueries_04']}</b>", "center")
							);
		// Return the table headers and data
		return array($resultsTableHeaders, $resultsTableData, $rule_id, $exclusion_count);
	}
		
	// Display the table data for displaying the results of the rules check
	public function displayResultsTable($rule_info)
	{
		global $lang;
		// Load the results table data
		list ($resultsTableHeaders, $resultsTableData, $rule_id, $exclusion_count) = $this->loadResultsTable();
		// Get count of discrepanies
		$num_discrepancies = count($resultsTableData);
		// If exclusions exist, then display message for the count
		$exclusionText = "";
		if ($exclusion_count > 0)
		{
			$exclusionWord = ($exclusion_count == 1) ? $lang['dataqueries_12'] : $lang['dataqueries_13'];
			$exclusionText = "<div id='excl_reload_{$rule_id}' style='padding:5px 0 0;font-size:11px;'>
							 (<b style='color:#800000;'>$exclusion_count $exclusionWord</b> - 
							  <a href='javascript:;' style='font-size:11px;text-decoration:underline;' onclick=\"reloadRuleAjax('$rule_id',1,'$rule_id')\">{$lang['dataqueries_92']}</a>)
							  <span style='padding-left:6px;display:none;' id='reload_dq_{$rule_id}'><img src='".APP_PATH_IMAGES."progress_circle.gif' style='vertical-align:middle;'> {$lang['dataqueries_90']}</span>
							  </div>";
		}
		// Set formatting of discrepancy count
		$num_discrepancies_formatted = number_format($num_discrepancies, 0, '.', ',');
		if ($num_discrepancies >= $this->resultLimit) {
			$num_discrepancies_formatted = "$num_discrepancies_formatted+<br>"
										 . "<span style='font-weight:normal;font-size:11px;'>{$lang['dataqueries_97']} $num_discrepancies_formatted {$lang['dataqueries_98']}</span>";
		}
		// Set the table title
		$resultsTableTitle = "<div style='padding:2px;font-weight:normal;'>
								{$lang['dataqueries_14']}" . (is_numeric($rule_info['order']) ? " #{$rule_info['order']}" : "") . ": 
								<b style='color:#800000;'>" . strip_tags($rule_info['name']) . "</b>
							  </div>
							  <div style='padding:2px;font-weight:normal;'>
								{$lang['dataqueries_91']} <b style='color:#800000;'>$num_discrepancies_formatted</b>
								$exclusionText
							  </div>";
		// Obtain the html for the results table
		$table_html = renderGrid("results_table_" . $rule_id, "", "auto", "auto", $resultsTableHeaders, $resultsTableData, (!empty($resultsTableData)), false, false);
		// Return the html and count of discrepancies
		return array($num_discrepancies, $table_html, $resultsTableTitle);
	}
	
	// Obtain the number of comments stored for a particular rule (stratified by record and event)
	private function getCommentCount($rule_id=null)
	{		
		global $Proj;
		// If rule_id is not numeric, then return false
		if (!is_numeric($rule_id) && !preg_match("/pd-\d{1,2}/", $rule_id)) return false;
		// Store counts in an array with record name as key and event_id as sub-array key with count as sub-array value
		$comment_count = array();
		// Determine if a pre-defined rule or not, then add to query
		$ruleid_sql = is_numeric($rule_id) ? "s.rule_id = $rule_id" : "s.pd_rule_id = '".substr($rule_id, 3)."'";
		// Query to get comments
		$sql = "select s.record, s.event_id, s.field_name, count(1) as total from 
				redcap_data_quality_status s, redcap_data_quality_changelog c 
				where $ruleid_sql and c.status_id = s.status_id and s.project_id = " . PROJECT_ID . "
				group by s.record, s.event_id, s.field_name";
		$q = mysql_query($sql);
		while ($row = mysql_fetch_assoc($q))
		{
			if ($row['field_name'] == '') {
				$comment_count[$row['record']][$row['event_id']] = $row['total'];
			} else {
				$comment_count[$row['record']][$row['event_id']][$row['field_name']] = $row['total'];
			}
		}
		// Return the array of comment counts
		return $comment_count;	
	}
	
	// Output the HTML for the communicate log for a given rule-record-event
	public function displayComLog($rule_id=null, $record=null, $event_id=null, $field_name)
	{
		global $lang, $isIE, $Proj, $longitudinal;
		// Verify rule_id, record, and event_id
		if ((is_numeric($rule_id) || preg_match("/pd-\d{1,2}/", $rule_id)) && isset($event_id) && is_numeric($event_id) && $record != null)
		{
			// Determine if a pre-defined rule or not, then add to query
			$ruleid_sql = is_numeric($rule_id) ? "s.rule_id = $rule_id" : "s.pd_rule_id = '".substr($rule_id, 3)."'";
			// If field_name is included in POST (i.e. for pre-defined rules), then add to query
			$field_sql = (!is_numeric($rule_id) && $field_name != '' && isset($Proj->metadata[$field_name])) ? "and s.field_name = '$field_name'" : "";
			// Query to get comments
			$comments = array();
			$sql = "select c.comment, c.change_time, u.username, s.status, c.new_status 
					from redcap_data_quality_status s, redcap_data_quality_changelog c 
					left join redcap_user_information u on u.ui_id = c.user_id 
					where $ruleid_sql $field_sql and c.status_id = s.status_id 
					and s.event_id = $event_id and s.record = '" . prep($record) . "' 
					and s.project_id = " . PROJECT_ID . "
					order by c.change_time";
			$q = mysql_query($sql);
			while ($row = mysql_fetch_assoc($q))
			{
				// Set current status in first loop
				if (!isset($current_status)) {
					$current_status = $last_status = $row['status'];
				}
				// Determine status of this loop (if no change, then assume the last status noted)
				if ($row['new_status'] == "") $row['new_status'] = $last_status;
				// If username is not found, then assume user was deleted from REDCap
				if ($row['username'] == "") $row['username'] = "<span style='color:#777;font-size:10px;'>[{$lang['dataqueries_11']}]</span>";
				// Add to array
				$comments[] = array('time'=>format_ts_mysql($row['change_time']), 
									'comment'=>filter_tags(label_decode($row['comment'])),
									'username'=>$row['username'],
									'new_status'=>$row['new_status']);
				// Set last status (to track as we loop through comlog changes
				$last_status = $row['new_status'];			
			}
			// Now reverse the array so that it is displayed chronologically in descending order (don't do this in SQL for a reason)
			$comments = array_reverse($comments);
			// Get info about this rule
			$rule_info = $this->getRule($rule_id);
			// Set current status (if not set yet) based upon default status value
			if (!isset($current_status)) {
				$current_status = is_numeric($rule_id) ? $this->default_status['num'] : $this->default_status[$rule_id];
			}
			// Output the table
			?>
			<p><?php echo $lang['dataqueries_10'] ?></p>
			<p style="font-size:13px;font-family:verdana;margin:20px 0;">
				<?php echo $lang['dataqueries_14'] . (is_numeric($rule_info['order']) ? " #".$rule_info['order'] : "") ?>: <b style="color:#800000;"><?php echo $rule_info['name'] ?></b><br/>
				<?php echo $lang['dataqueries_93'] ?> <b><?php echo $record ?></b> <?php echo ($longitudinal ? "&nbsp;(<b>" . $Proj->eventInfo[$event_id]['name_ext'] . "</b>)" : "") ?><br/>
				<?php echo $lang['dataqueries_94'] ?>
				<select id="currentStatusEdit" onchange="$('#currentStatusEditBtn').attr('disabled',false);" class="x-form-text x-form-field" style="padding-right: 0px; height: 22px;">
				<?php foreach ($this->status_labels as $this_status=>$this_label) { ?>
					<option value="<?php echo $this_status ?>" <?php if ($this_status == $current_status) echo "selected" ?>><?php echo $this_label ?></option>
				<?php } ?>
				</select>
				<input id="currentStatusEditBtn" type="button" onclick="this.disabled=true;changeStatus('<?php echo $rule_id ?>','<?php echo $record ?>',<?php echo $event_id ?>,'<?php echo $field_name ?>');" value="<?php echo cleanHtml2($lang['dataqueries_95']) ?>" disabled>
			</p>
			<table class='form_border' style='width:<?php echo $isIE ? "95%" : "100%" ?>;text-align:left;'>
				<tr>
					<td class='label_header' style='border: 1px solid #aaa;padding:5px 8px;text-align:left;width:120px;'>
						<?php echo $lang['dataqueries_06'] ?>
					</td>
					<td class='label_header' style='border: 1px solid #aaa;padding:5px 8px;text-align:left;width:120px;'>
						<?php echo $lang['global_17'] ?>
					</td>
					<td class='label_header' style='border: 1px solid #aaa;padding:5px 8px;text-align:left;width:80px;'>
						<?php echo $lang['dataqueries_23'] ?>
					</td>
					<td class='label_header' style='border: 1px solid #aaa;padding:5px 8px;text-align:left;'>
						<?php echo $lang['dataqueries_07'] ?>
					</td>
				</tr>
			<?php foreach ($comments as $attr) { ?>
				<tr>
					<td class='data' style='border-top: 1px #ccc;padding:3px 8px;width:120px;'>
						<?php echo $attr['time'] ?>
					</td>
					<td class='data' style='border-top: 1px #ccc;padding:3px 8px;text-align:left;width:120px;'>
						<?php echo $attr['username'] ?>
					</td>
					<td class='data' style='border-top: 1px #ccc;padding:3px 8px;text-align:left;color:#000066;width:80px;'>
						<?php echo $this->status_labels[$attr['new_status']] ?>
					</td>
					<td class='data' style='border-top: 1px #ccc;padding:3px 8px;text-align:left;'>
						<?php echo nl2br($attr['comment']) ?>
					</td>
				</tr>
			<?php } 
			if (empty($comments))
			{
				?>
				<tr>
					<td class='data' colspan='4' style='border-top: 1px #ccc;padding:6px 8px;text-align:center;'>
						<?php echo $lang['dataqueries_05'] ?>
					</td>
				</tr>
				<?php
			}	
			?>
			</table>
			<?php
		}
		// Parameters are missing/incorrect, so send back failed response msg
		else
		{
			print '0';
		}
	}
	
	// Save comment or change status for the communicate log for a given rule-record-event
	public function modifyChangeLog($rule_id=null, $record=null, $event_id=null, $comment=null, $status=null, $field_name='', $exclude=null)
	{
		global $lang, $Proj;
		// Default response
		$response = 0;
		// Verify rule_id, record, and event_id
		if ((is_numeric($rule_id) || preg_match("/pd-\d{1,2}/", $rule_id)) && isset($event_id) && is_numeric($event_id) && $record != null)
		{
			// Determine if a pre-defined rule or not
			if (is_numeric($rule_id)) {
				$ruleid_val = $rule_id;
				$pdruleid_val = "";
				$ruleid_sql = "rule_id = $rule_id";
				// Determine default status value for this rule
				$default_status = ($status == null) ? $this->default_status['num'] : $status;
			} else {
				$ruleid_val = "";
				$pdruleid_val = substr($rule_id, 3);
				$ruleid_sql = "pd_rule_id = '$pdruleid_val'";
				// Determine default status value for this rule
				$default_status = ($status == null) ? $this->default_status[$rule_id] : $status;
			}
			// If field_name is included in POST (i.e. for pre-defined rules), then add to query
			$field_sql = (!is_numeric($rule_id) && $field_name != '' && isset($Proj->metadata[$field_name])) ? "and field_name = '$field_name'" : "";
			// Get the status id and see if already exists in DQ status table
			$sql = "select status_id from redcap_data_quality_status where $ruleid_sql and record = '" . prep($record) . "' 
					and event_id = $event_id and project_id = " . PROJECT_ID . " $field_sql limit 1";
			$q = mysql_query($sql);
			if (mysql_num_rows($q) < 1) {
				// Insert with new status_id
				$sql = "insert into redcap_data_quality_status (rule_id, pd_rule_id, project_id, record, event_id, field_name, status, exclude)
						values (" . checkNull($ruleid_val) . ", " . checkNull($pdruleid_val) . ", " . PROJECT_ID . ", '" . prep($record) . "', $event_id,
						" . checkNull($field_name) . ", $default_status, $exclude)";
				$q = mysql_query($sql);
				// Get status_id
				$status_id = mysql_insert_id();
				// If we're only exluding this record-event-field, then stop after updating the status
				if ($exclude != null) {
					return ($q ? '1' : '0');
				}
			} else {
				// Get status_id
				$status_id = mysql_result($q, 0);
				// If we're only exluding this record-event-field, then stop after updating the status
				if ($exclude != null) {
					// Change exclude value for this $status_id
					$sql = "update redcap_data_quality_status set exclude = $exclude where status_id = $status_id
							and project_id = " . PROJECT_ID;
					return (mysql_query($sql) ? '1' : '0');
				}
			}
			// Now add the comment/status to the changelog table, if applicable
			if ($comment != null && $status != null)
			{
				$sql = "insert into redcap_data_quality_changelog (status_id, user_id, change_time, comment, new_status)
						values ($status_id, (select ui_id from redcap_user_information where username = '" . prep(USERID) . "' limit 1), 
						'" . NOW . "', " . checkNull($comment) . ", " . checkNull($status) . ")";
				if (mysql_query($sql)) {			
					// If status was changed, then also change it in status table
					if ($status != null) {
						$sql = "update redcap_data_quality_status set status = $status where status_id = $status_id
								and project_id = " . PROJECT_ID;
						if (mysql_query($sql)) $response = '1';
					} else {			
						$response = '1';
					}
				}
			}
		}
		// Return response
		return $response;
	}
	
	// Check the order of the rules for rule_order to make sure they're not out of order
	public function checkOrder()
	{
		// Store the sum of the rule_order's and count of how many there are
		$sum   = 0;
		$count = 0;
		// Loop through existing resources
		foreach ($this->getRules() as $rule_id=>$attr)
		{
			// Ignore pre-defined rules
			if (!is_numeric($rule_id)) continue;
			// Add to sum
			$sum += $attr['order']*1;
			// Increment count
			$count++;
		}
		// Now perform check (use simple math method)
		if ($count*($count+1)/2 != $sum)
		{
			// Out of order, so reorder
			$this->reorder();
		}
	}
	
	// Reset the order of the rules for rule_order in the table
	public function reorder()
	{
		// Initial value
		$order = 1;
		// Loop through existing resources
		foreach (array_keys($this->getRules()) as $rule_id)
		{
			// Ignore pre-defined rules
			if (!is_numeric($rule_id)) continue;
			// Save to table
			$sql = "update redcap_data_quality_rules set rule_order = $order where project_id = " . PROJECT_ID . " and rule_id = $rule_id";
			$q = mysql_query($sql);
			// Increment the order
			$order++;
		}
	}	
		
	// When parsing DQ rules or branching logic (for missing values rule), replace ^ exponential form with PHP equivalent
	private function replaceExponents($string) 
	{
		//Find all ^ and locate outer parenthesis for its number and exponent
		$caret_pos = strpos($string, "^");
		// Make sure it has at least 2 left and right parentheses (otherwise not in correct format)
		$num_paren_left = substr_count($string, "(");
		$num_paren_right = substr_count($string, ")");
		// Loop through string
		$num_loops = 0;
		while ($caret_pos !== false && $num_paren_left >= 2 && $num_paren_right >= 2 && $num_loops < 1000) 
		{
			$num_loops++;
			//For first half of string
			$found_end = false;
			$rpar_count = 0;
			$lpar_count = 0;
			$i = $caret_pos;
			while ($i >= 0 && !$found_end) 
			{
				$i--;
				//Keep count of left/right parentheses
				if (substr($string, $i, 1) == "(") {
					$lpar_count++;
				} elseif (substr($string, $i, 1) == ")") {
					$rpar_count++;
				}
				//If found the parentheses boundary, then end loop
				if ($rpar_count > 0 && $lpar_count > 0 && $rpar_count == $lpar_count) {
					$found_end = true;
				}
			}
			//Completed first half of string
			$string = substr($string, 0, $i). "pow(" . substr($string, $i);
			$caret_pos += 4; // length of "pow("
			
			//For last half of string
			$last_char = strlen($string);
			$found_end = false;
			$rpar_count = 0;
			$lpar_count = 0;
			$i = $caret_pos;
			while ($i <= $last_char && !$found_end) {
				$i++;
				//Keep count of left/right parentheses
				if (substr($string, $i, 1) == "(") {
					$lpar_count++;
				} elseif (substr($string, $i, 1) == ")") {
					$rpar_count++;
				}
				//If found the parentheses boundary, then end loop
				if ($rpar_count > 0 && $lpar_count > 0 && $rpar_count == $lpar_count) {
					$found_end = true;
				}
			}
			//Completed last half of string
			$string = substr($string, 0, $caret_pos) . "," . substr($string, $caret_pos + 1, $i - $caret_pos) . ")" . substr($string, $i + 1);
			
			//Set again for checking in next loop
			$caret_pos = strpos($string, "^");
			
		}
		return $string;
	}
	
	// FORM-LEVEL RIGHTS: Make sure user has form-level data accses to the form for ALL fields.
	// If does NOT have rights, then show nothing and give error message.
	private function checkFormLevelRights($rule_id, $fields=array())
	{
		global $Proj, $user_rights, $lang;
		// Put all forbidden fields in an array
		$fieldsNoAccess = array();
		// Loop through all fields used in this logic string
		foreach ($fields as $this_field)
		{
			// Get form of field
			$this_field_form = $Proj->metadata[$this_field]['form_name'];
			if (!(isset($user_rights['forms'][$this_field_form]) && $user_rights['forms'][$this_field_form] > 0))
			{
				// Place field in array
				$fieldsNoAccess[] = $this_field;
				// If this is a user-defined rule, then stop here and throw error
				if (is_numeric($rule_id))
				{
					// Get list of upcoming rules to be processed after this one
					list ($rule_id, $rule_ids) = explode(",", $_POST['rule_ids'], 2);
					// Get current full name of rule
					$rule_attr = $this->getRule($rule_id);
					$error_rule_name = $lang['dataqueries_14'] . (is_numeric($rule_attr['order']) ? " #{$rule_attr['order']}" : "") . ": {$rule_attr['name']}";
					// Set error message
					$msg = "<div id='results_table_{$rule_id}'>
								<p class='red'>
									<b>{$lang['global_01']}{$lang['colon']}</b> {$lang['dataqueries_32']} 
									<b>$error_rule_name</b>{$lang['period']} {$lang['dataqueries_44']}
								</p>
							</div>";
					// Send back JSON
					print '{"rule_id":"' . $rule_id . '",'
						. '"next_rule_ids":"' . $rule_ids . '",'
						. '"discrepancies":"1",'
						. '"discrepancies_formatted":"<span style=\"font-size:12px;\">'.$lang['global_01'].'</span>",'
						. '"dag_discrepancies":[],'
						. '"title":"' . cleanJson($error_rule_name) . '",'
						. '"payload":"' . cleanJson($msg)  .'"}';
					exit;
				}
			}
		}
		// Return an array of fields that user cannot access
		return $fieldsNoAccess;
	}
	
	// LOGIC WITH ERRORS: If user-defined logic has syntax errors when the logic is executed, then send back an error message to user.
	private function logicHasErrors()
	{
		global $lang;
		// Get list of upcoming rules to be processed after this one
		list ($rule_id, $rule_ids) = explode(",", $_POST['rule_ids'], 2);
		// Get current full name of rule
		$rule_attr = $this->getRule($rule_id);
		$error_rule_name = $lang['dataqueries_14'] . (is_numeric($rule_attr['order']) ? " #{$rule_attr['order']}" : "") . ": {$rule_attr['name']}";
		// Set error message
		$msg = "<div id='results_table_{$rule_id}'>
					<p class='red'>
						<b>{$lang['global_01']}{$lang['colon']}</b> {$lang['dataqueries_32']} 
						<b>$error_rule_name</b>{$lang['period']} {$lang['dataqueries_50']}
					</p>
				</div>";
		// Send back JSON
		print '{"rule_id":"' . $rule_id . '",'
			. '"next_rule_ids":"' . $rule_ids . '",'
			. '"discrepancies":"1",'
			. '"discrepancies_formatted":"<span style=\"font-size:12px;\">'.$lang['global_01'].'</span>",'
			. '"dag_discrepancies":[],'
			. '"title":"' . cleanJson($error_rule_name) . '",'
			. '"payload":"' . cleanJson($msg)  .'"}';
		exit;
	}
}
