<?php
/*****************************************************************************************
**  REDCap is only available through a license agreement with Vanderbilt University
******************************************************************************************/

require_once dirname(dirname(__FILE__)) . '/Config/init_project.php';

// Required files
require_once APP_PATH_DOCROOT . 'Design/functions.php';
require_once APP_PATH_DOCROOT . 'ProjectGeneral/form_renderer_functions.php';

// Increase memory limit



// Kick out if project is not in production status yet
if ($status < 1) 
{
	redirect(APP_PATH_WEBROOT . "index.php?pid=$project_id");
	exit;
}

// Header
include APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

// Super User Instructions
if ($super_user && $draft_mode == "2") 
{	
	renderPageTitle("<img src='".APP_PATH_IMAGES."find.png'> {$lang['database_mods_01']}");
	?>
	<p style='margin:20px 0 5px;'>
		<b><?php echo $lang['global_24'] . $lang['colon'] ?></b><br>
		<?php echo $lang['database_mods_03'] ?>
	</p>
	<?php
} 
// Normal User Instructions
elseif (!$super_user && $draft_mode == "1") 
{
	renderPageTitle("<img src='".APP_PATH_IMAGES."find.png'> {$lang['database_mods_04']}");
	?>
	<p style='margin:20px 0 5px;'>
		<?php echo $lang['database_mods_05'] ?> 
	</p>
	<?php
}
// Should not be here
elseif ($draft_mode == "0")
{
	renderPageTitle("<img src='".APP_PATH_IMAGES."find.png'> {$lang['database_mods_01']}");
	?>
	<div class="yellow" style="margin:20px 0;">
		<b><?php echo $lang['global_01'] ?>:</b> <?php echo $lang['database_mods_06'] ?>  
	</div>
	<?php
	renderPrevPageBtn('Design/online_designer.php');
	include APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
	exit;
}

// Link to return to design page
print "<p style='margin:20px 0;'>";
renderPrevPageBtn();
print "</p>";

// Get counts of fields added/deleted and HTML for metadata diff table
list ($num_records, $fields_added, $field_deleted, $count_new, $count_existing) = renderCountFieldsAddDel2();
list ($newFields, $delFields, $fieldsAddDelText) = renderFieldsAddDel();
list ($num_metadata_changes, $num_fields_changed, $num_critical_issues, $metadataDiffTable) = getMetadataDiff();
// See if auto changes can be made (if enabled)
$willBeAutoApproved = (
		// If the ONLY changes are that new fields were added
		($auto_prod_changes == '2' && $num_fields_changed == 0 && $field_deleted == 0 && $num_critical_issues == 0)
		// If the ONLY changes are that new fields were added OR if there is no data
		|| ($auto_prod_changes == '3' && ($num_records == 0 || ($num_fields_changed == 0 && $field_deleted == 0 && $num_critical_issues == 0)))
		// OR if there are no critical issues AND no fields deleted (regardless of whether or not project has data)
		|| ($auto_prod_changes == '4' && $field_deleted == 0 && $num_critical_issues == 0) 
		// OR if there are (no critical issues AND no fields deleted) OR if there is no data
		|| ($auto_prod_changes == '1' && ($num_records == 0 || ($field_deleted == 0 && $num_critical_issues == 0))) 
	) 
	? "<span style='color:green;font-size:13px;'>{$lang['design_100']}</span> <img src='".APP_PATH_IMAGES."tick.png' class='imgfix2'>" 
	: "<span style='color:red;'>{$lang['design_292']}</span>";

// Render descriptive summary text about field changes
print "<p>
			<u><b>{$lang['database_mods_131']}</b></u><br>
			&nbsp;&nbsp;&nbsp;&nbsp;&bull; {$lang['index_22']}{$lang['colon']} <b>$num_records</b><br>
			&nbsp;&nbsp;&nbsp;&nbsp;&bull; <span style='color:green;'>{$lang['database_mods_88']} <b>$fields_added</b></span><br>
			&nbsp;&nbsp;&nbsp;&nbsp;&bull; <span style='color:brown;'>{$lang['database_mods_112']} <b>$num_fields_changed</b></span><br>
			&nbsp;&nbsp;&nbsp;&nbsp;&bull; <span style='color:red;'>{$lang['database_mods_130']} <b>".($field_deleted+$num_critical_issues)."</b></span><br>
			&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; - <span style='color:red;font-size:11px;'>{$lang['database_mods_90']} <b>$field_deleted</b></span><br>
			&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; - <span style='color:red;font-size:11px;'>{$lang['database_mods_129']} <b>$num_critical_issues</b></span><br>
			&nbsp;&nbsp;&nbsp;&nbsp;&bull; {$lang['database_mods_111']} <b>$count_existing</b><br>
			&nbsp;&nbsp;&nbsp;&nbsp;&bull; {$lang['database_mods_110']} <b>$count_new</b><br>";
if ($auto_prod_changes > 0 && $draft_mode == '1') {
	print "	&nbsp;&nbsp;&nbsp;&nbsp;&bull; <b>{$lang['database_mods_114']}&nbsp; $willBeAutoApproved</b><br>";
}
print "</p>";

// Display fields to be added and deleted
 print  "<table cellpadding='0' cellspacing='0'>
			<tr>
				<td valign='top'>$fieldsAddDelText</td>";
// Display key for metadata changes
print  "		<td valign='bottom'>";
renderMetadataCompareKey();
print  "		</td>
			</tr>
		</table>";

		
## DTS: Check for any field changes that would cause DTS to break
if ($dts_enabled_global && $dts_enabled) 
{
	// Get fields used by DTS
	$dtsFields = array_keys(getDtsFields());
	// Get fields used by DTS that are being deleted
	$dtsDelFields = array_intersect($dtsFields, $delFields);
	// Get fields used by DTS that have had their field type changed to invalid type (i.e. not text or textarea)
	$dtsFieldsTypeChange = array();
	$sql = "select m.field_name from redcap_metadata m, redcap_metadata_temp t where m.project_id = t.project_id 
			and m.field_name = t.field_name and m.element_type in ('text', 'textarea') 
			and t.element_type not in ('text', 'textarea') and m.project_id = " . PROJECT_ID . "
			and m.field_name in ('" . implode("', '", $dtsFields) . "')";
	$q = mysql_query($sql);
	while ($row = mysql_fetch_assoc($q))
	{
		$dtsFieldsTypeChange[] = $row['field_name'];
	}	
	// Give warning message if DTS fields are being deleted or have their field type changed or (if longitudinal) moved to different form
	if (!empty($dtsDelFields) || !empty($dtsFieldsTypeChange))
	{
		?>
		<div class="red" style="margin:20px 0;">
			<img src="<?php echo APP_PATH_IMAGES ?>exclamation.png" class="imgfix"> 
			<b><?php echo $lang['define_events_64'] ?></b><br>
			<?php 
			echo $lang['database_mods_101']; 
			if (!empty($dtsDelFields)) {
				echo "<br><br>" . $lang['database_mods_102'] . " <b>" . implode("</b>, <b>", $dtsDelFields) . "</b>";
			}
			if (!empty($dtsFieldsTypeChange)) {
				echo "<br><br>" . $lang['database_mods_103'] . " <b>" . implode("</b>, <b>", $dtsFieldsTypeChange) . "</b>";
			}
			?>
		</div>
		<?php
	}
}

		
// SURVEY QUESTION NUMBERING (DEV ONLY): Detect if any forms are a survey, and if so, if has any branching logic. 
// If so, disable question auto numbering.
foreach (array_keys($Proj->surveys) as $this_survey_id)
{
	$this_form = $Proj->surveys[$this_survey_id]['form_name'];
	if ($Proj->surveys[$this_survey_id]['question_auto_numbering'] && Design::checkSurveyBranchingExists($this_form,"redcap_metadata_temp"))
	{
		// Give user a prompt as notice of this change
		?>
		<div class="yellow" style="margin:20px 0;">
			<img src="<?php echo APP_PATH_IMAGES ?>exclamation_orange.png" class="imgfix">
			<?php echo "<b>{$lang['survey_08']} \"<span style='color:#800000;'>".strip_tags(label_decode($Proj->surveys[$this_survey_id]['title']))."</span>\"</b><br>{$lang['survey_07']} {$lang['survey_10']}" ?>
		</div>
		<?php
	}
}



// Render table to display metadata changes
print $metadataDiffTable;
 
// Buttons for committing/undoing changes
if ($super_user && $status > 0 && $draft_mode == 2) 
{
	print  "<div class='blue' style='font-weight:bold;margin-bottom:50px;padding-bottom:15px;'>
				{$lang['database_mods_07']}<br><br>
				<input id='btn_commit' type='button' value='COMMIT CHANGES' onclick=\"
					if (confirm('" . remBr($lang['database_mods_08']). "\\n\\n" . remBr($lang['database_mods_09']) . "\\n" . remBr($lang['database_mods_10']) . "')) {
						$('#btn_commit').prop('disabled',true);
						$('#btn_reject').prop('disabled',true);
						$('#btn_reset').prop('disabled',true);
						window.location.href = '" . APP_PATH_WEBROOT . "Design/draft_mode_approve.php?pid=$project_id'+addGoogTrans();
					}
				\">
				&nbsp;&nbsp;
				<input id='btn_reject' type='button' value='Reject Changes' onclick=\"
					if(confirm('" . remBr($lang['database_mods_11']) . "\\n\\n" . remBr($lang['database_mods_12']) . "')) {
						$('#btn_commit').prop('disabled',true);
						$('#btn_reject').prop('disabled',true);
						$('#btn_reset').prop('disabled',true);
						window.location.href = '" . APP_PATH_WEBROOT . "Design/draft_mode_reject.php?pid=$project_id'+addGoogTrans();
					}
				\">
				&nbsp;&nbsp;
				<input id='btn_reset' type='button' value='Reset Changes' onclick=\"
					if(confirm('" . remBr($lang['database_mods_13']) . "\\n\\n" . remBr($lang['database_mods_14']) . "')) {
						$('#btn_commit').prop('disabled',true);
						$('#btn_reject').prop('disabled',true);
						$('#btn_reset').prop('disabled',true);
						window.location.href = '" . APP_PATH_WEBROOT . "Design/draft_mode_reset.php?pid=$project_id'+addGoogTrans();
					}
				\">
			</div>";
}


// Link to return to design page (don't show if no changes - real short page doesn't need two buttons to go back)
if ($num_metadata_changes > 0) 
{
	print "<p style='margin:20px 0;'>";
	renderPrevPageBtn();
	print "</p>";
}

include APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
