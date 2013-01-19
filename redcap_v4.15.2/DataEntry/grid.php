<?php
/*****************************************************************************************
**  REDCap is only available through a license agreement with Vanderbilt University
******************************************************************************************/

require_once dirname(dirname(__FILE__)) . '/Config/init_project.php';


//Required files
require_once APP_PATH_DOCROOT . 'ProjectGeneral/form_renderer_functions.php';
require_once APP_PATH_DOCROOT  . 'Surveys/survey_functions.php';

// Auto-number logic (pre-submission of new record)
if ($auto_inc_set) {
	// If the auto-number record selected has already been created by another user, fetch the next one to prevent overlapping data
	if (isset($_GET['id']) && isset($_GET['auto'])) {
		$q = mysql_query("select 1 from redcap_data where project_id = $project_id and record = '".prep($_GET['id'])."' limit 1");
		if (mysql_num_rows($q) > 0) {
			// Record already exists, so redirect to new page with this new record value
			redirect(PAGE_FULL . "?pid=$project_id&page={$_GET['page']}&id=" . getAutoId());
		}
	}
}

//Get arm number from URL var 'arm'
$arm = getArm();

// Reload page if id is a blank value
if (isset($_GET['id']) && trim($_GET['id']) == "")
{
	redirect(PAGE_FULL . "?pid=" . PROJECT_ID . "&page=" . $_GET['page'] . "&arm=" . $arm);
	exit;
}

// Clean id
if (isset($_GET['id'])) {
	$_GET['id'] = strip_tags(label_decode($_GET['id']));
}

include APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

// Header
renderPageTitle("<img src='".APP_PATH_IMAGES."application_view_tile.png' class='imgfix2'> {$lang['grid_01']}" . 
	(isset($_GET['id']) ? ": {$lang['grid_02']}" : ""));

//Custom page header note
if (trim($custom_data_entry_note) != '') {
	print "<br><div class='green' style='font-size:11px;'>" . str_replace("\n", "<br>", $custom_data_entry_note) . "</div>";
}

//A child demographics project cannot be using the Double Data Entry or Longitudinal modules. Give warning if it is.
if ($is_child && ($double_data_entry || $longitudinal)) {
	if ($double_data_entry) 				 $module = $lang['global_04'];
	if ($longitudinal) 						 $module = $lang['grid_06'];
	if ($double_data_entry && $longitudinal) $module = $lang['grid_07'];
	print "<table width=480><tr><td class=\"red\"><font color=#800000><b>{$lang['global_48']}{$lang['colon']}</b><br>
		{$lang['grid_08']} $module {$lang['grid_09']}<br><br>
		{$lang['grid_10']} $module {$lang['grid_11']}
		</font></td></tr></table>";
	include APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';			
	exit;
}


//Alter how records are saved if project is Double Data Entry (i.e. add --# to end of Study ID)
if ($double_data_entry && $user_rights['double_data'] != 0) {
	$entry_num = "--" . $user_rights['double_data'];
} else {
	$entry_num = "";
}


## GRID
if (isset($_GET['id'])) 
{
	## If study id has been entered or selected, display grid.

	//Adapt for Double Data Entry module
	if ($entry_num == "") {
		//Not Double Data Entry or this is Reviewer of Double Data Entry project
		$id = $_GET['id'];
	} else {
		//This is #1 or #2 Double Data Entry person
		$id = $_GET['id'] . $entry_num;
	}
	
	$sql = "select d.record from redcap_events_metadata m, redcap_events_arms a, redcap_data d where a.project_id = $project_id 
			and a.project_id = d.project_id and m.event_id = d.event_id and a.arm_num = $arm and a.arm_id = m.arm_id 
			and d.record = '$id' limit 1";
	$q = mysql_query($sql);
	$row_num = mysql_num_rows($q);
	$existing_record = ($row_num > 0);
	
	//Check if record exists in another group, if user is in a DAG
	if ($user_rights['group_id'] != "" && $existing_record) 
	{
		$q = mysql_query("select 1 from redcap_data where project_id = $project_id and record = '".prep($_GET['id'])."' and 
						  field_name = '__GROUPID__' and value = '{$user_rights['group_id']}' limit 1");
		if (mysql_num_rows($q) < 1) {
			//Record is not in user's DAG
			print  "<div class='red'>
						<img src='".APP_PATH_IMAGES."exclamation.png' class='imgfix'> 
						<b>{$lang['global_49']} ".$_GET['id']." {$lang['grid_13']}</b><br><br>
						{$lang['grid_14']}<br><br>
						<a href='".APP_PATH_WEBROOT."DataEntry/grid.php?pid=$project_id' style='text-decoration:underline'><< {$lang['grid_15']}</a>
						<br><br>
					</div>";
			include APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
			exit;
		}
	}
	
	## If new study id, give some brief instructions above normal instructions.			
	if (!$existing_record) {	
		print  "<p style='margin-top:15px;color:#800000;'>
					<b>\"{$_GET['id']}\" {$lang['grid_16']} <span class='notranslate'>$table_pk_label</span>.</b> 
					{$lang['grid_17']} <span class='notranslate'>$table_pk_label</span> {$lang['grid_18']}
				</p>
				<hr size=1>";	
	}
	
	## General instructions for grid.
	print "<p>{$lang['grid_19']} $table_pk_label {$lang['grid_20']} ";
	
	## LOCK RECORDS & E-SIGNATURES
	// For lock/unlock records feature, show locks by any forms that are locked (if a record is pulled up on data entry page)
	$locked_forms = array();
	$qsql = "select event_id, form_name, timestamp from redcap_locking_data where project_id = $project_id and record = '" . prep($id). "'";
	$q = mysql_query($qsql);
	while ($row = mysql_fetch_array($q)) {
		$locked_forms[$row['event_id'].",".$row['form_name']] = " <img src='".APP_PATH_IMAGES."lock_small.png' title='Locked on ".format_ts_mysql($row['timestamp'])."'>";	
	}
	// E-signatures
	$qsql = "select event_id, form_name, timestamp from redcap_esignatures where project_id = $project_id and record = '" . prep($id). "'";
	$q = mysql_query($qsql);
	while ($row = mysql_fetch_array($q)) {
		$this_esign_ts = " <img src='".APP_PATH_IMAGES."tick_shield_small.png' title='E-signed on ".format_ts_mysql($row['timestamp'])."'>";
		if (isset($locked_forms[$row['event_id'].",".$row['form_name']])) {
			$locked_forms[$row['event_id'].",".$row['form_name']] .= $this_esign_ts;
		} else {
			$locked_forms[$row['event_id'].",".$row['form_name']] = $this_esign_ts;
		}
	}
	
	
	
	print  "{$lang['grid_21']} 
			<a href='".APP_PATH_WEBROOT."Design/define_events.php?pid=$project_id&edit' 
				style='text-decoration:underline;'>{$lang['global_16']}</a> {$lang['global_14']}{$lang['period']}";
	
	// Check if record exists for other arms, and if so, notify the user (only for informational purposes)		
	if (recordExistOtherArms($id, $arm))
	{
		// Record exists in other arms, so give message
		print  "<p class='red' style='font-family:arial;'>
					<b>{$lang['global_03']}</b>{$lang['colon']} {$lang['grid_36']} $table_pk_label \"<b>$id</b>\" {$lang['grid_37']}
				</p>";
	}
	
		

	
	
	/***************************************************************
	** EVENT-FORM GRID
	***************************************************************/
			
	//Determine if any visits have been defined yet
	$sql = "select m.event_id, m.descrip from redcap_events_metadata m, redcap_events_arms a where a.project_id = $project_id "
		 . "and a.arm_num = $arm and a.arm_id = m.arm_id order by m.day_offset, m.descrip";
	$q = mysql_query($sql);
	while ($row = mysql_fetch_assoc($q)) {
		//Collect event description to render as labels in grid at bottom
		$event_descrip[$row['event_id']] = $row['descrip'];
	}

	//Determine if any forms have been assigned to events and display grid
	$q = mysql_query("select m.event_id, f.form_name from redcap_events_metadata m, redcap_events_forms f, redcap_events_arms a 
					  where a.project_id = '$project_id' and a.arm_num = '$arm' and a.arm_id = m.arm_id and m.event_id = f.event_id order by m.day_offset, m.descrip");				
	while ($row = mysql_fetch_assoc($q)) {	
		//Add form-event info to array
		$form_events[$row['event_id']][$row['form_name']] = "";
	}
	
	
	//Get form names and their menu display text.
	$QQuery2 = mysql_query("SELECT form_name, form_menu_description FROM redcap_metadata WHERE form_menu_description IS NOT NULL 
							AND project_id = '$project_id' ORDER BY field_order");
	$num_forms = mysql_num_rows($QQuery2);
	$i = 1;
	while ($row2 = mysql_fetch_array($QQuery2)) {		
		$forms_array[$i] = $row2['form_name'];
		$i++;
	}
	//Query to get all Form Status values for all forms across all time-points. Put all into array for later retrieval.
	//Acomodate if double data entry person
	if ($double_data_entry && $user_rights['double_data'] != 0) {
		$qsql = "select substring(record,1,locate('--',record)-1) as record, field_name, value, event_id from redcap_data 
				 where record = '$id' and project_id = '$project_id' 
				 and field_name in ('".implode("_complete','",$forms_array)."_complete')";
	//Normal
	} else {
		$qsql = "select record, field_name, value, event_id from redcap_data where record = '$id' and project_id = '$project_id' 
				 and field_name in ('".implode("_complete','",$forms_array)."_complete')";
	}		
	$q = mysql_query($qsql);
	while ($row = mysql_fetch_array($q)) {	
		//Put time-point + , + form name as array key
		$grid_form_status[$row['event_id'].",".substr($row['field_name'],0,-9)] = $row['value'];		
	}
	
	// If secondary pk exists, retrieve it and display next to record name
	$secondary_pk_val = '';
	if ($existing_record && $secondary_pk != '')
	{
		$secondary_pk_val = getSecondaryIdVal($id);
		if ($secondary_pk_val != '') {
			$secondary_pk_val = "&nbsp; <span style='color:#800000;'>(" . $Proj->metadata[$secondary_pk]['element_label'] . " <b>$secondary_pk_val</b>)</span>";
		}
	}
	
	// If Custom Record Label is specified (such as "[last_name], [first_name]", then parse and display)
	$custom_record_label_current = '';
	if ($existing_record && !empty($custom_record_label)) 
	{
		// Add to context message
		$custom_record_label_current = "&nbsp; <span style='color:#800000;'>"
									 . getCustomRecordLabels($custom_record_label, $Proj->getFirstEventIdArm(getArm()), $id) 
									 . "</span>";
	}
	
	
	// GRID
	$grid_disp_change = "";
	print  "<table class='form_border'>";
	
	// Display record id number above grid
	print  "<tr class='notranslate'>
				<td style='color:#000066;text-align:center;font-size:16px;' colspan='".(count($event_descrip)+1)."'>
					<div style='max-width:700px;padding:25px 5px 0;'>
						" . (!$existing_record ? "<span style='font-weight:bold;'>{$lang['grid_30']}</span> " : "") . "
						$table_pk_label <b>{$_GET['id']}</b> $secondary_pk_val $custom_record_label_current
					</div>
				</td>
			</tr>";
			
	// Begin table proper
	print  "<tr>
				<td class='header' rowspan='2' style='text-align:center;padding:5px;'>{$lang['global_35']}</td>
				<td class='header' colspan='".count($event_descrip)."' style='text-align:center;padding:5px;'>{$lang['global_45']}";
	if ($multiple_arms)
	{
		// If has multiple arms, then display this arm's name			
		print  " {$lang['grid_26']} <span class='notranslate' style='color:#000066;'>{$lang['global_08']} " . $_GET['arm'] . ": " . $Proj->events[$_GET['arm']]['name'] . "</span>";
	}
	print  "	</td>
			</tr>
			<tr class='notranslate'>";
	//Render table headers
	$i = 1;
	foreach ($event_descrip as $this_event) {
		print  "<td class='header' style='text-align:center;width:25px;color:#800000;padding:5px;white-space:normal;vertical-align:bottom;'>
				<div style='font-family:Arial;'>$this_event</div>
				<div style='font-weight:normal;font-size:10px;'>($i)</div>
				</td>";
		$i++;
	}
	print "</tr>";
	//Render table rows
	$q = mysql_query("select e.event_id, e.descrip, m.form_name, m.form_menu_description from redcap_events_metadata e, redcap_metadata m, 
					  redcap_events_arms a where a.project_id = $project_id and m.project_id = a.project_id and a.arm_id = e.arm_id and 
					  m.form_menu_description is not null and a.arm_num = $arm order by m.field_order, e.day_offset, e.descrip");
	$this_form = "";
	while ($row = mysql_fetch_assoc($q)) 
	{
		// Make sure user has access to this form. If not, then do not display this form's row.
		if ($user_rights['forms'][$row['form_name']] == '0') continue;		
		//Deterine if we are starting new row	
		if ($this_form != $row['form_name']) 
		{
			if ($this_form != "") print "</tr>";	
			print "<tr><td class='data notranslate'>{$row['form_menu_description']}</td>";
		}
		//Render cell
		print "<td class='data' style='text-align:center;height:20px;'>";
		if (isset($form_events[$row['event_id']][$row['form_name']])) {
			//Form status
			if (isset($grid_form_status[$row['event_id'].",".$row['form_name']])) {
				switch ($grid_form_status[$row['event_id'].",".$row['form_name']]) {
					case 1: 	$this_color = "yellow"; break;
					case 2: 	$this_color = "green";  break;
					default: 	$this_color = "red";
				}
			} else {
					//No Form Status exists yet in data table, so give it Incomplete status
					$this_color = "red"; 
			}
			//Determine record id (will be different for each time-point). Configure if Double Data Entry
			if ($entry_num == "") {
				$displayid = $id;
			} else {
				//User is Double Data Entry person
				$displayid = $_GET['id'];				
			}
			//Set button HTML, but don't make clickable if color is gray
			$this_button = "<img src='".APP_PATH_IMAGES."$this_color.gif' style='height:11px;width:11px'>";
			print "<a href='".APP_PATH_WEBROOT."DataEntry/index.php?pid=$project_id&id=".urlencode($displayid)."&event_id={$row['event_id']}&page={$row['form_name']}'>$this_button</a>";
			//Display lock icon for any forms that are locked for this record
			if ($this_color != "gray" && isset($locked_forms[$row['event_id'].",".$row['form_name']])) {
				print $locked_forms[$row['event_id'].",".$row['form_name']];
			}
		}
		print "</td>";
		//Set for next loop
		$this_form = $row['form_name'];
	}
	
	print  "</tr>";
	
	## LOCK / UNLOCK RECORDS
	//If user has ability to lock a record, give option to lock it for all forms and all time-points (but ONLY if the record exists)
	if ($existing_record && $user_rights['lock_record_multiform'] && $user_rights['lock_record'] > 0) 
	{
		print  "<tr>
					<td style='color:#800000;text-align:center;font-size:16px;font-weight:bold;padding:25px 5px 15px;' colspan='".(count($event_descrip)+1)."'>";
		//Show link "Lock all forms"
		print  "	<div style='text-align:center;padding: 10px 0px 2px 0px;max-width:700px;'>
					<img src='".APP_PATH_IMAGES."lock.png' class='imgfix'> 
					<a style='color:#A86700;font-weight:bold;font-size:12px' href='javascript:;' onclick=\"
						lockUnlockForms('$id','".RCView::escape($_GET['id'])."','','".getArm()."','1','lock');
					\">{$lang['grid_28']} &nbsp;&nbsp;&nbsp;</a>
					</div>";						
		//Show link "Unlock all forms"
		print  "	<div style='text-align:center;padding: 6px 0px 12px 0px;max-width:700px;'>
					<img src='".APP_PATH_IMAGES."lock_open.png' class='imgfix'> 
					<a style='color:#666;font-weight:bold;font-size:12px' href='javascript:;' onclick=\"
						lockUnlockForms('$id','".RCView::escape($_GET['id'])."','','".getArm()."','1','unlock');
					\">{$lang['grid_29']}</a>
					</div>";
		print  "	</td>
				</tr>";
	}
	print  "</table>
			<br>";
			
	## FORM LOCKING POP-UP FOR E-SIGNATURE
	if ($user_rights['lock_record'] > 1) 
	{
		include APP_PATH_DOCROOT . "Locking/esignature_popup.php";
	}	
		
		
		
}






################################################################################
## PAGE WITH RECORD ID DROP-DOWN
else 
{
	// Get total record count
	$num_records = Records::getCount($project_id);
		
	// Get extra record count in user's data access group, if they are in one
	if ($user_rights['group_id'] != "") 
	{
		$sql  = "select count(distinct(record)) from redcap_data where project_id = " . PROJECT_ID . " and field_name = '$table_pk'"
			  . " and record != '' and record in (" . pre_query("select record from redcap_data where project_id = " . PROJECT_ID 
			  . " and field_name = '__GROUPID__' and value = '{$user_rights['group_id']}'") . ")";
		$num_records_group = mysql_result(mysql_query($sql),0);
	}
		
	// If a SURVEY and surveys are ENABLED, then append timestamp of all responses to record name in drop-down list of records
	if (($surveys_enabled == '1' || $surveys_enabled == '2') && isset($Proj->forms[$Proj->firstForm]['survey_id']))
	{
		$sql = "select r.record, r.completion_time from redcap_surveys_participants p, redcap_surveys_response r, redcap_events_metadata m 
				where survey_id = " . $Proj->forms[$Proj->firstForm]['survey_id'] . " and r.participant_id = p.participant_id and 
				r.completion_time is not null and m.event_id = p.event_id and m.event_id = " . $Proj->firstEventId;
		$q = mysql_query($sql);
		// Count responses
		$num_survey_responses = mysql_num_rows($q);
	}		
		
	// If more records than a set number exist, do not render the drop-downs due to slow rendering.
	$search_text_label = $lang['grid_35'] . " " . $table_pk_label;
	if ($num_records > $maxNumRecordsHideDropdowns)
	{
		// If using auto-numbering, then bring back text box so users can auto-suggest to find existing records	.
		// The negative effect of this is that it also allows users to [accidentally] bypass the auto-numbering feature.
		if ($auto_inc_set) {
			$search_text_label = $lang['data_entry_121'] . " $table_pk_label";
		}
		// Give extra note about why drop-down is not being displayed
		$search_text_label .= RCView::div(array('style'=>'padding:10px 0 0;font-size:10px;font-weight:normal;color:#555;'), 
								$lang['global_03'] . $lang['colon'] . " " . $lang['data_entry_172'] . " " . 
								number_format($maxNumRecordsHideDropdowns, 0, '.', ',') . " " . 
								$lang['data_entry_173'] . $lang['period']
							);
	}
	
	/**
	 * ARM SELECTION DROP-DOWN (if more than one arm exists)
	 */
	//Loop through each ARM and display as a drop-down choice
	$arm_dropdown_choices = "";
	$q = mysql_query("select arm_id, arm_num, arm_name from redcap_events_arms where project_id = $project_id order by arm_num");
	if (mysql_num_rows($q) > 1) {
		while ($row = mysql_fetch_assoc($q)) {
			//Render tab
			$arm_dropdown_choices .= "<option";
			//If this tab is the current arm, make it selected
			if ($row['arm_num'] == $arm) {
				$arm_dropdown_choices .= " selected ";
			}				
			$arm_dropdown_choices .= " value='{$row['arm_num']}'>{$lang['global_08']} {$row['arm_num']}{$lang['colon']} {$row['arm_name']}</option>";
		}
	}
	
	// Page instructions and record selection table with drop-downs
	?>
	<p style="margin-bottom:20px;">
		<?php echo $lang['grid_38'] ?>
		<?php echo ($auto_inc_set) ? $lang['data_entry_96'] : $lang['data_entry_97']; ?>
	</p>
	
	<style type="text/css">
	.data { padding: 7px; width: 400px; }
	</style>
		
	<table class="form_border" style="width:700px;">
		<!-- Header displaying record count -->
		<tr>
			<td class="header" colspan="2" style="font-weight:normal;padding:10px 5px;color:#800000;font-size:12px;">
				<?php echo $lang['graphical_view_22'] ?> <b><?php echo number_format($num_records) ?></b>
					<?php if (isset($num_records_group)) { ?>
						&nbsp;/&nbsp; <?php echo $lang['data_entry_104'] ?> <b><?php echo number_format($num_records_group) ?></b>
					<?php } ?>
				<?php if (isset($num_survey_responses)) { ?>
					&nbsp;/&nbsp; <?php echo $lang['data_entry_102'] ?> <b><?php echo number_format($num_survey_responses) ?></b>
				<?php } ?>
			</td>
		</tr>
	<?php
	
	/***************************************************************
	** DROP-DOWNS
	***************************************************************/
	if ($num_records <= $maxNumRecordsHideDropdowns)
	{
		print  "<tr>
					<td class='label'>{$lang['grid_31']} $table_pk_label</td>
					<td class='data'>";
		
		// [Retrieval of ALL records] If Custom Record Label is specified (such as "[last_name], [first_name]"), then parse and display
		// ONLY get data from FIRST EVENT
		$dropdownid_disptext = array();
		if (!empty($custom_record_label)) 
		{
			foreach (getCustomRecordLabels($custom_record_label, $Proj->getFirstEventIdArm(getArm())) as $this_record=>$this_custom_record_label)
			{
				$dropdownid_disptext[$this_record] .= " " . $this_custom_record_label;
			}
		}	

		// Customize the Record ID pulldown menus using the SECONDARY_PK appended on end, if set
		$secondary_pk_disptext = array();
		if ($secondary_pk != '')
		{
			$sql = "select record, value from redcap_data where project_id = $project_id and field_name = '$secondary_pk' 
					and event_id = " . $Proj->firstEventId;
			$q = mysql_query($sql);
			while ($row = mysql_fetch_assoc($q)) 
			{
				$secondary_pk_disptext[$row['record']] = " (" . $Proj->metadata[$secondary_pk]['element_label'] . " " . $row['value'] . ")";
			}
		}
		
		/**
		 * RECORD SELECTION DROP-DOWN
		 */
		print  "<select id='record' class='x-form-text x-form-field notranslate' style='padding-right:0;height:22px;' onchange=\"
					window.location.href = app_path_webroot+page+'?pid='+pid+'&arm=$arm&id=' + this.value + addGoogTrans();
				\">";
		print  "	<option value=''>{$lang['data_entry_91']}</option>";
		// Limit records pulled only to those in user's Data Access Group
		if ($user_rights['group_id'] == "") {
			$group_sql  = ""; 
		} else {
			$group_sql  = "and record in (" . pre_query("select record from redcap_data where field_name = '__GROUPID__' and 
				value = '{$user_rights['group_id']}' and project_id = $project_id") . ")"; 
		}
		//If a Double Data Entry project, only look for entry-person-specific records by using SQL LIKE
		if ($double_data_entry && $user_rights['double_data'] != 0) {
			//If a designated entry person
			$qsql = "select distinct substring(record,1,locate('--',record)-1) as record FROM redcap_data 
					 where project_id = $project_id and record in (" . pre_query("select distinct record from redcap_data where 
					 project_id = $project_id and record like '%--{$user_rights['double_data']}'"). ") $group_sql order by abs(record), record";
		} else {
			//If NOT a designated entry person OR not double data entry project
			$qsql = "select distinct d.record FROM redcap_data d, redcap_events_metadata m, redcap_events_arms a 
					where d.project_id = $project_id and a.project_id = d.project_id and a.arm_id = m.arm_id and m.event_id = d.event_id 
					and a.arm_num = $arm and d.field_name = '$table_pk' $group_sql order by abs(d.record), record";
		}	
		$QQuery = mysql_query($qsql);	
		while ($row = mysql_fetch_array($QQuery)) 
		{
			// Check for custom labels
			$secondary_pk_text  = isset($secondary_pk_disptext[$row['record']]) ? $secondary_pk_disptext[$row['record']] : "";
			$custom_record_text = isset($dropdownid_disptext[$row['record']])   ? $dropdownid_disptext[$row['record']]   : "";
			//Render drop-down options
			print "<option value='{$row['record']}'>{$row['record']}{$secondary_pk_text}{$dropdownid_disptext[$row['record']]}</option>";
			$study_id_array[] = $row['record'];
		}
		
		print  "</select>";
		
		/**
		 * ARM SELECTION DROP-DOWN (if more than one arm exists)
		 */
		//Loop through each ARM and display as a drop-down choice
		if ($arm_dropdown_choices != "")
		{
			print  "<span style='padding:0 6px 0 10px;font-weight:bold;'>from</span>
					<select id='arm_name' class='x-form-text x-form-field notranslate' style='padding-right:0;height:22px;' onchange=\"
						if ($('#record').val().length > 0) {
							window.location.href = app_path_webroot+'DataEntry/grid.php?pid=$project_id&id='+$('#record').val()+'&arm='+$('#arm_name').val()+addGoogTrans();
						} else {
							showProgress(1);
							setTimeout(function(){
								window.location.href = app_path_webroot+'DataEntry/grid.php?pid=$project_id&arm='+$('#arm_name').val()+addGoogTrans();
							},500);
						}
					\">
					$arm_dropdown_choices
					</select>";
		}
		
		print  "</td></tr>";
	}
	
	//User defines the Record ID	
	if ((!$auto_inc_set && $user_rights['record_create']) || ($auto_inc_set && $num_records > $maxNumRecordsHideDropdowns))
	{			
		// Check if record ID field should have validation
		$text_val_string = "";
		if ($Proj->metadata[$table_pk]['element_type'] == 'text' && $Proj->metadata[$table_pk]['element_validation_type'] != '') 
		{
			// Apply validation function to field
			$text_val_string = "if(redcap_validate(this,'{$Proj->metadata[$table_pk]['element_validation_min']}','{$Proj->metadata[$table_pk]['element_validation_max']}','hard','".convertLegacyValidationType($Proj->metadata[$table_pk]['element_validation_type'])."',1)) ";
		}
		//Text box for next records
		?>
		<tr>
			<td class="label">
				<?php echo $search_text_label ?>
			</td>
			<td class="data" style="width:400px;">
				<input id="inputString" type="text" class="x-form-text x-form-field" style="position:relative;">
			</td>
		</tr>
		<?php
	}
	
	// Auto-number button(s) - if option is enabled
	if ($auto_inc_set)// && $num_records <= $maxNumRecordsHideDropdowns) 
	{ 	
		$autoIdBtnText = $lang['data_entry_46'];
		if ($multiple_arms) {
			$autoIdBtnText .= $lang['data_entry_99'];
		}
		?>
		<tr>
			<td class="label">&nbsp;</td>
			<td class="data">
				<?php if (!isDev() && $surveys_enabled > 0) { ?>
					<!-- New survey response button -->
					<div id="explainNewRespDiff" style="padding:5px;border:1px solid #ccc;display:none;color:#800000;margin-bottom:15px;">
						<?php echo $lang['data_entry_115'] ?><br><br><?php echo $lang['data_entry_116'] ?>
					</div>
					<div style="float:left;">
						<button onclick="surveyOpen('<?php echo APP_PATH_SURVEY_FULL . "?s=" . getSurveyHash(getSurveyId(), getEventId()) ?>');"><?php echo $lang['data_entry_90'] ?></button>
					</div>
					<div style="float:right;" onclick="$('#explainNewRespDiff').toggle('blind',{},500,function(){if($(this).css('display')!='none'){$(this).effect('highlight',{},1500);}});">
						<a href="javascript:;" style="color:#999;font-family:tahoma;font-size:10px;text-decoration:underline;"><?php echo $lang['data_entry_114'] ?></a>
					</div>
					<div style="clear:both;padding:3px 15px;font-weight:bold;">
						&#8212; <?php echo $lang['global_46'] ?> &#8212;
					</div>
				<?php } ?>
				<!-- New record button -->
				<button onclick="window.location.href=app_path_webroot+page+'?pid='+pid+'&id=<?php echo getAutoId() ?>&auto&arm='+($('#arm_name_newid').length ? $('#arm_name_newid').val() : '<?php echo getArm() ?>');return false;"><?php echo $autoIdBtnText ?></button>
			</td>
		</tr>
	<?php }
	
	print "</table>";
			
	// Display search utility
	renderSearchUtility();
	
	?>
	<br><br>
	
	<script type="text/javascript">
	// Enable validation and redirecting if hit Tab or Enter
	$(function(){
		$('#inputString').keypress(function(e) {
			if (e.which == 13) {
				 $('#inputString').trigger('blur');
				return false;
			}
		});
		$('#inputString').blur(function() {
			var refocus = false;
			var idval = trim($('#inputString').val()); 
			if (idval.length < 1) {
				refocus = true;
				$('#inputString').val('');
			}
			if (idval.length > 50) {
				refocus = true;
				alert('<?php echo remBr($lang['data_entry_44']) ?>'); 
			}
			if (refocus) {
				setTimeout(function(){document.getElementById('inputString').focus();},10);
			} else {
				$('#inputString').val(idval);
				<?php echo $text_val_string ?>
				setTimeout(function(){ 
					idval = $('#inputString').val();
					idval = idval.replace(/&quot;/g,''); // HTML char code of double quote
					// Don't allow pound signs in record names
					if (/#/g.test(idval)) {
						$('#inputString').val('');
						alert("Pound signs (#) are not allowed in record names! Please enter another record name.");
						$('#inputString').focus();
						return false;
					}
					// Don't allow apostrophes in record names
					if (/'/g.test(idval)) {
						$('#inputString').val('');
						alert("Apostrophes (') are not allowed in record names! Please enter another record name.");
						$('#inputString').focus();
						return false;
					}
					// Don't allow ampersands in record names
					if (/&/g.test(idval)) {
						$('#inputString').val('');
						alert("Ampersands (&) are not allowed in record names! Please enter another record name.");
						$('#inputString').focus();
						return false;
					}
					// Don't allow plus signs in record names
					if (/\+/g.test(idval)) {
						$('#inputString').val('');
						alert("Plus signs (+) are not allowed in record names! Please enter another record name.");
						$('#inputString').focus();
						return false;
					}
					window.location.href = app_path_webroot+page+'?pid='+pid+'&arm=<?php echo (($arm_dropdown_choices != "") ? "'+ $('#arm_name_newid').val() +'" : $arm) ?>&id=' + idval + addGoogTrans(); 
				},200);
			}
		});
	});
	</script>
	<?php
	
	
	//Using double data entry and auto-numbering for records at the same time can mess up how REDCap saves each record. 
	//Give warning to turn one of these features off if they are both turned on.
	if ($double_data_entry && $auto_inc_set) {
		print "<div class='red' style='margin-top:20px;'><b>{$lang['global_48']}</b><br>{$lang['data_entry_56']}</div>";
	}
	
	// If multiple Arms exist, use javascript to pop in the drop-down listing the Arm names to choose from for new records
	if ($arm_dropdown_choices != "" && ((!$auto_inc_set && $user_rights['record_create']) 
		|| ($auto_inc_set && $num_records > $maxNumRecordsHideDropdowns)))
	{
		print  "<script type='text/javascript'>
				$(function(){
					var inputStringParentId = document.getElementById('inputString').parentNode;
					$(inputStringParentId).append('".cleanHtml("<span style='padding:0 6px 0 6px;font-weight:bold;'>{$lang['grid_26']}</span> <select id='arm_name_newid' onchange=\"if (!$('select#arm_name').length){ window.location.href=window.location.href+'&arm='+this.value; return; } editAutoComp(autoCompObj,this.value);\" class='x-form-text x-form-field notranslate' style='padding-right:0;height:22px;'>$arm_dropdown_choices</select>")."');
				});
				</script>";
	}
	
	//If project is a prototype, display notice for users telling them that no real data should be entered yet.
	if ($status < 1) {
		print  "<br>
				<div class='yellow' style='font-family:arial;width:550px;'>
					<img src='".APP_PATH_IMAGES."exclamation_orange.png' class='imgfix'> 
					<b style='font-size:14px;'>{$lang['global_03']}:</b><br>
					{$lang['data_entry_28']}
				</div>";
	}
	
}
		
//Div that says "Working..." to show progress
print  '<div id="working" class="white_content_overlay" style="z-index:9999;display:none;position:absolute;padding-right:18px;text-align:center;border:2px solid #aaa;top:40%;left:40%;width:200px;font-size:20px;font-weight:bold;color:#666;">
			<img src="'.APP_PATH_IMAGES.'progress_circle.gif">&nbsp; Working...
		</div>
		<div id="fade" style="display:none;"></div>';


// Render JavaScript for record selecting auto-complete/auto-suggest
?>
<script type="text/javascript">
var autoCompObj;
$(function(){
	if ($('#inputString').length) {
		autoCompObj = $('#inputString').autocomplete({ serviceUrl: app_path_webroot+'DataEntry/auto_complete.php?pid='+pid+'&arm='+($('#arm_name_newid').length ? $('#arm_name_newid').val() : '<?php echo getArm() ?>') });
	}
});
function editAutoComp(autoCompObj,val) {
	autoCompObj.disable();
	var autoCompObj = $('#inputString').autocomplete({ serviceUrl: app_path_webroot+'DataEntry/auto_complete.php?pid='+pid+'&arm='+val });
}
</script>
<?php

include APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
