<?php 
/*****************************************************************************************
**  REDCap is only available through a license agreement with Vanderbilt University
******************************************************************************************/

// Need to call survey functions file to utilize a function
require_once APP_PATH_DOCROOT . "Surveys/survey_functions.php";

// Begin HTML
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">
<html>
<head>
	<meta http-equiv="content-type" content="text/html; charset=UTF-8">
	<title><?php echo strip_tags(remBr(br2nl($app_title))) ?> | REDCap</title>
	<meta name="googlebot" content="noindex, noarchive, nofollow, nosnippet">
	<meta name="robots" content="noindex, noarchive, nofollow">
	<meta name="slurp" content="noindex, noarchive, nofollow, noodp, noydir">
	<meta name="msnbot" content="noindex, noarchive, nofollow, noodp">
	<meta http-equiv="Cache-Control" content="no-cache">
	<meta http-equiv="Pragma" content="no-cache">
	<meta http-equiv="expires" content="0">
	<meta http-equiv="X-UA-Compatible" content="chrome=1">
	<link rel="shortcut icon" href="<?php echo APP_PATH_IMAGES ?>favicon.ico" type="image/x-icon">
	<link rel="stylesheet" type="text/css" href="<?php echo APP_PATH_CSS ?>smoothness/jquery-ui-<?php echo JQUERYUI_VERSION ?>.custom.css" media="screen,print">
	<link rel="stylesheet" type="text/css" href="<?php echo APP_PATH_CSS ?>style.css" media="screen,print">
	<script type="text/javascript" src="<?php echo APP_PATH_JS ?>base.js"></script>
	<script type="text/javascript" src="<?php echo APP_PATH_JS ?>underscore-min.js"></script>
	<script type="text/javascript" src="<?php echo APP_PATH_JS ?>backbone-min.js"></script>
	<script type="text/javascript" src="<?php echo APP_PATH_JS ?>RedCapUtil.js"></script>
</head>
<body>
<noscript>
	<div class="red" style="margin-top:50px;">
		<img src="<?php echo APP_PATH_IMAGES ?>exclamation.png" class="imgfix"> <b>WARNING: JavaScript Disabled</b><br><br>
		It has been determined that your web browser currently does not have JavaScript enabled, 
		which prevents this webpage from functioning correctly. You CANNOT use this page until JavaScript is enabled. 
		You will find instructions for enabling JavaScript for your web browser by 
		<a href="http://www.google.com/support/bin/answer.py?answer=23852" target="_blank" style="text-decoration:underline;">clicking here</a>. 
		Once you have enabled JavaScript, you may refresh this page or return back here to begin using this page.
	</div>
</noscript>
<?php

// IE CSS Hack - Render the following CSS if using IE
if ($isIE) {
	?>
	<style type="text/css">
	input[type="radio"],input[type="checkbox"] { margin: 0 }
	/* Fix IE's fieldset background issue */
	fieldset { position: relative; }
	legend {
		position:absolute;
		top: -1em;
	}
	fieldset {
		position: relative;
		margin-top:1.5em;
		padding-top:0.5em;
	}
	</style>
	<?php
}

// Render Javascript variables needed on all pages for various JS functions
renderJsVars();

// STATS: Check if need to report institutional stats to REDCap consortium 
checkReportStats();

// Do CSRF token check (using PHP with jQuery)
createCsrfToken();

// Initialize auto-logout popup timer and logout reset timer listener
initAutoLogout();

// Display the Google Translation widget (unless disabled)
renderGoogleTranslateWidget();
	
// Render divs holding javascript form-validation text (when error occurs), so they get translated on the page
renderValidationTextDivs();

// Display notice that password will expire soon (if utilizing $password_reset_duration for Table-based authentication)
Authentication::displayPasswordExpireWarningPopup();

// Check if need to display pop-up dialog to SET UP SECURITY QUESTION for table-based users
Authentication::checkSetUpSecurityQuestion();

// If project is Inactive or Archived, do not show full menus in order to give limited functionality
if ($status > 1 && PAGE == 'index.php') 
{
	?>
	<div id="status_note" title="<?php echo $lang['global_03'] ?>" style="display:none;">
		<p style=""><?php echo $lang['bottom_50'] ?></p>
	</div>				
	<script type="text/javascript">
	$(function(){
		$('#status_note').dialog({ bgiframe: true, modal: true, width: 550, buttons: { Okay: function() {$(this).dialog('close'); }}, open: function(){fitDialog(this)}});
	});
	</script>
	<?php
}


// Project status label
$statusLabel = '<div>'.$lang['edit_project_58'].'&nbsp; ';	
// Set icon/text for project status
if ($status == '1') {
	$statusLabel .= '<b style="color:green;">'.$lang['global_30'].'</b></div>';
} elseif ($status == '2') {
	$statusLabel .= '<b style="color:#800000;">'.$lang['global_31'].'</b></div>';
} elseif ($status == '3') {
	$statusLabel .= '<b style="color:#800000;">'.$lang['global_26'].'</b></div>';
} else {
	$statusLabel .= '<b style="color:#555;">'.$lang['global_29'].'</b></div>';
}


/**
 * LOGO & LOGOUT
 */
$logoHtml = "<div id='menu-div'>
				<div class='menubox' style='text-align:center;padding:7px 10px 0px 7px;'>
					<a href='".APP_PATH_WEBROOT_PARENT."index.php?action=myprojects" . (($auth_meth == "none" && $auth_meth != $auth_meth_global && $auth_meth_global != "shibboleth") ? "&logout=1" : "") . "'><img src='".APP_PATH_IMAGES."redcaplogo_small.gif' title='REDCap' style='height:54px;'></a>
					<div style='text-align:left;font-size:10px;font-family:tahoma;color:#888;margin:10px -10px 5px -7px;border-top:1px solid #ddd;padding:0 0 6px 5px;'>
						<img src='".APP_PATH_IMAGES."lock_small_disable.gif' class='imgfix' style='top:5px;'> 
						{$lang['bottom_01']} <span style='font-weight:bold;color:#555;'>$userid</span>
						" . ($auth_meth == "none" ? "" : ((strlen($userid) < 14 && $auth_meth != "none") ? " &nbsp;|&nbsp; <span>" : "<br><span style='padding:1px 0 0;'><img src='".APP_PATH_IMAGES."cross_small_circle_gray.png' class='imgfix' style='top:5px;'> ")."<a href='".PAGE_FULL."?".$_SERVER['QUERY_STRING']."&logout=1' style='font-size:10px;font-family:tahoma;'>{$lang['bottom_02']}</a>") . "
					</div>
					<div class='hang'>
						<img src='".APP_PATH_IMAGES."redcap_icon.png' class='imgfix2'>&nbsp;&nbsp;<a href='".APP_PATH_WEBROOT_PARENT."index.php?action=myprojects" . (($auth_meth == "none" && $auth_meth != $auth_meth_global && $auth_meth_global != "shibboleth") ? "&logout=1" : "") . "'>{$lang['bottom_03']}</a><br>
					</div>
					<div class='hang'>
						<img src='".APP_PATH_IMAGES."house.png' class='imgfix'>&nbsp;&nbsp;<a href='".APP_PATH_WEBROOT."index.php?" . ((isset($_GET['child']) && $_GET['child'] != "") ? "pnid=".$_GET['child'] : "pid=$project_id") . "'>{$lang['bottom_44']}</a><br>
					</div>
					<div class='hang'>
						<img src='".APP_PATH_IMAGES."clipboard_task.png' class='imgfix'>&nbsp;&nbsp;<a href='".APP_PATH_WEBROOT."ProjectSetup/index.php?" . ((isset($_GET['child']) && $_GET['child'] != "") ? "pnid=".$_GET['child'] : "pid=$project_id") . "'>{$lang['app_17']}</a><br>
					</div>
					<div style='text-align:left;font-size:10px;font-family:tahoma;color:#666;padding:5px 0 3px 23px;'>
						$statusLabel
					</div>
				</div>
			</div>";


// ONLY for DATA ENTRY FORMS, get record information
list ($fetched, $hidden_edit, $entry_num) = getRecordAttributes();


// Build data entry form list
if ($status < 2)
{
	$dataEntry = "<div class='menubox'>";
	// Set text for Invite Participants link
	$invitePart = "";
	if ($surveys_enabled > 0 && $user_rights['participants']) {
		$invitePart = "<div class='hang'><img src='".APP_PATH_IMAGES."send.png' class='imgfix'>&nbsp;&nbsp;<a href='".APP_PATH_WEBROOT."Surveys/invite_participants.php?pid=$project_id'>".(isDev() ? $lang['app_22'] : $lang['app_15'])."</a></div>";
		if ($status < 1) {		
			$invitePart .=  "<div class='menuboxsub'>- ".$lang['invite_participants_01']."</div>";
		}
	}
	// Set panel title text
	if ($status < 1 && $user_rights['design']) {
		$dataEntryTitle = "<table cellspacing='0' width='100%'>
							<tr>
								<td>{$lang['bottom_47']}</td>
								<td id='menuLnkEditInstr' class='opacity50' style='text-align:right;padding-right:10px;'>"
									. RCView::img(array('src'=>'pencil_small2.png','class'=>'imgfix1 '.($isIE ? 'opacity50' : '')))
									. RCView::a(array('href'=>APP_PATH_WEBROOT."Design/online_designer.php?pid=$project_id",'style'=>'font-family:arial;font-size:11px;text-decoration:underline;color:#000066;font-weight:normal;'), "Edit instruments") . "
								</td>
							</tr>
						   </table>";
	} else {
		$dataEntryTitle = $lang['bottom_47'];
	}
	// Single-survey project only
	if ($surveys_enabled == '2')
	{		
		// Invite Participants
		$dataEntry .= $invitePart;
		// View/Edit Survey Responses
		$dataEntry .= "<div class='hang'><img src='".APP_PATH_IMAGES."blog.png' class='imgfix'>&nbsp;&nbsp;<a href='".APP_PATH_WEBROOT."DataEntry/index.php?pid=$project_id&page=".$Proj->firstForm."'>".$lang['bottom_45']."</a></div>";
		if ($status < 1) {		
			$dataEntry .=  "<div class='menuboxsub'>- ".$lang['bottom_46']."</div>";
		}
		// Get survey id and URL
		$survey_id = $Proj->forms[$Proj->firstForm]['survey_id'];
		$survey_url = APP_PATH_SURVEY_FULL . "?s=" . getSurveyHash($survey_id, getEventId());
		$dataEntry .=  "<div style='padding:8px 6px 2px 22px;'>
							<button class='jqbuttonsm' style='vertical-align:middle;' onclick=\"surveyOpen('$survey_url',0);\"> {$lang['survey_220']}</button>&nbsp;
							<button class='jqbuttonsm' style='vertical-align:middle;' onclick=\"window.open('mailto:?subject=".cleanHtml($lang['survey_222'])."&body=".cleanHtml($lang['survey_222'].$lang['colon'])." $survey_url','_self');\">{$lang['survey_221']}</button>
						</div>";
		$dataEntry .= "</div>";
	}
	// Typical list of instruments
	else {
		
		// Invite Participants
		$dataEntry .= $invitePart;
		
		// Scheduling
		if ($repeatforms && $scheduling) {
			$dataEntry .= "<div class='hang'><img src='".APP_PATH_IMAGES."calendar_plus.png' class='imgfix'>&nbsp;&nbsp;<a href='".APP_PATH_WEBROOT."Calendar/scheduling.php?pid=$project_id'>".$lang['global_25']."</a></div>";
			if ($status < 1) {		
				$dataEntry .=  "<div class='menuboxsub'>- ".$lang['bottom_19']."</div>";
			}
		}

		## Display link for manage page if using multiple time-points (Longitudinal Module)
		if ($longitudinal) 
		{
			// If user is on grid page or data entry page and record is selected, make grid icon a link back to grid page 
			$gridicon = "<img src='".APP_PATH_IMAGES."application_view_tile.png' class='imgfix'>";
			if (PAGE == "DataEntry/grid.php" || PAGE == "DataEntry/index.php") {
				$gridlink = "<a href='".APP_PATH_WEBROOT."DataEntry/grid.php?pid=$project_id" . (isset($fetched) ? "&id=".RCView::escape($fetched) : "") . ((isset($_GET['arm']) || isset($_GET['event_id'])) ? "&arm=".getArm() : "") . "'>$gridicon</a>";
			} else {
				$gridlink = $gridicon;
			}
			
			$dataEntry .=  "<div class='hang' style='margin-bottom:5px;'>
							$gridlink&nbsp;&nbsp;<a href='".APP_PATH_WEBROOT."DataEntry/grid.php?pid=$project_id' style='color:#800000'>".$lang['bottom_20']."</a>
							</div>";
			//Get all info for determining which forms to show on menu
			if (isset($fetched)) 
			{
				foreach ($Proj->eventsForms[$_GET['event_id']] as $this_form) {
					$visit_forms[$this_form] = "";
				}
			}	
		}
		
		// If showing Scheduling OR Invite Participant links OR viewing a record in longitudinal...
		if ((!$longitudinal && ($scheduling || $surveys_enabled > 0)) || (isset($fetched) && PAGE == "DataEntry/index.php")) 
		{
			// Show record name on left-hand menu (if a record is pulled up)
			$record_label = "";
			if (isset($fetched) && PAGE == "DataEntry/index.php" && isset($_GET['event_id']) && is_numeric($_GET['event_id']))
			{	
				require_once APP_PATH_DOCROOT . 'ProjectGeneral/form_renderer_functions.php';
				$record_display = "<b>$fetched</b>";
				// Append secondary id, if set
				if ($secondary_pk != '')
				{
					$secondary_pk_val = getSecondaryIdVal($fetched);
					if ($secondary_pk_val != '') {
						$record_display .= "&nbsp; (" . $Proj->metadata[$secondary_pk]['element_label'] . " <b>$secondary_pk_val</b>)";
					}
				}
				// Append custom_record_label, if set
				if ($custom_record_label != '') 
				{
					$record_display .= "&nbsp; " . filter_tags(getCustomRecordLabels($custom_record_label, $Proj->getFirstEventIdArm(getArm()), $fetched));
				}
				// Set full string for record name with prepended label (e.g. Study ID 202)
				$record_display = strip_tags(label_decode($table_pk_label)) . " " . $record_display;
				// Render record name
				$record_label = "<div style='padding:0 0 4px;color:#800000;font-size:11px;'>$record_display</div>";
			}
			
			// Get event description for this event
			$event_label = "";
			if ($longitudinal && isset($_GET['event_id']) && is_numeric($_GET['event_id'])) 
			{
				//print_array($Proj->events);
				$foundEvt = false;
				//Get arm num and event name
				foreach ($Proj->events as $arm=>$arm_attr)
				{
					foreach ($arm_attr['events'] as $this_event_id=>$event_attr)
					{
						if ($this_event_id == $_GET['event_id'])
						{
							$arm_name = $arm_attr['name'];
							$event_name = $event_attr['descrip'];
							if ($multiple_arms) {
								$event_name .= " ({$lang['global_08']} $arm: $arm_name)";
							}		
							$event_label = "<div style='padding:0 0 4px;'>
												{$lang['bottom_23']} &nbsp;<span style='color:#800000;font-weight:bold;'>$event_name</span>
											</div>";
							$foundEvt = true;
							break;
						}
					}
					if ($foundEvt) break;
				}
			}
			
			// Show dashed line above form names?
			$dashedLine = (($repeatforms && $scheduling) || $surveys_enabled > 0) ? "margin:12px 0 2px;border-top:1px dashed #aaa;" : "margin:0;";
			
			$dataEntry .=  "<div class='menuboxsub' style='$dashedLine text-indent:0;padding-top:5px;font-size:10px;'>
								$record_label
								$event_label
								" . $lang['global_57'] . $lang['colon'] . "
							</div>";				
		}

		//If project is parent demographics project, then show menu as if this is child project.
		if (isset($_GET['child']) && $_GET['child'] != "") {
			$is_child_of = $app_name;
			$app_name = $_GET['child'];	
			//Fix rights also so that menu reflects rights for child (not parent)
			check_user_rights($app_name);
		}

		// Initialize
		$locked_forms = array();

		//If project is a child or parent project, show the forms from the parent project first.
		if ($is_child || (isset($_GET['child']) && $_GET['child'] != "")) 
		{
			//Render the form list for the shared "parent" project
			list ($form_count_parent,$formStringParent) = renderFormMenuList($is_child_of,$fetched,$locked_forms,$hidden_edit,$entry_num,$visit_forms);
			$dataEntry .= $formStringParent;
			//Now that we've used the parent rights to load the parent forms, switch back to the child user_rights for this user
			check_user_rights($app_name);
		}


		//For lock/unlock records and e-signatures, show locks by any forms that are locked (if a record is pulled up on data entry page)
		if (PAGE == "DataEntry/index.php" && isset($fetched)) 
		{
			$entry_num = isset($entry_num) ? $entry_num : "";
			// Lock records
			$sql = "select form_name, timestamp from redcap_locking_data where project_id = $project_id and event_id = {$_GET['event_id']} 
					and record = '" . prep($fetched.$entry_num). "'";
			$q = mysql_query($sql);
			while ($row = mysql_fetch_array($q)) 
			{
				$locked_forms[$row['form_name']] = " <img id='formlock-{$row['form_name']}' src='".APP_PATH_IMAGES."lock_small.png' title='".cleanHtml($lang['bottom_59'])." " . format_ts_mysql($row['timestamp']) . "'>";	
			}
			// E-signatures
			$sql = "select form_name, timestamp from redcap_esignatures where project_id = $project_id and event_id = {$_GET['event_id']} 
					and record = '" . prep($fetched.$entry_num). "'";
			$q = mysql_query($sql);
			while ($row = mysql_fetch_array($q)) 
			{
				$this_esignts = " <img src='".APP_PATH_IMAGES."tick_shield_small.png' title='E-signed on " . format_ts_mysql($row['timestamp']) . "'>";	
				if (isset($locked_forms[$row['form_name']])) {
					$locked_forms[$row['form_name']] .= $this_esignts;
				} else {
					$locked_forms[$row['form_name']] = $this_esignts;
				}
			}
		}

		## Render the form list for this project
		list ($form_count,$formString) = renderFormMenuList($app_name,$fetched,$locked_forms,$hidden_edit,$entry_num,$visit_forms);
		$dataEntry .= $formString;

		## LOCK / UNLOCK RECORDS
		//If user has ability to lock a record, give option to lock it for all forms (if record is pulled up on data entry page)
		if ($user_rights['lock_record_multiform'] && $user_rights['lock_record'] > 0 && PAGE == "DataEntry/index.php" && isset($fetched)) 
		{
			//Adjust if double data entry for display in pop-up
			if ($double_data_entry && $user_rights['double_data'] != '0') {
				$fetched2 = $fetched . '--' . $user_rights['double_data'];
			//Normal
			} else {
				$fetched2 = $fetched;
			}
			//Determine when to show which link
			if (count($locked_forms) == $form_count) {
				$show_unlocked_link = true;
				$show_locked_link = false;
			} elseif (count($locked_forms) == 0) {
				$show_unlocked_link = false;
				$show_locked_link = true;
			} else {
				$show_locked_link = true;
				$show_unlocked_link = true;
			}
			//Show link "Lock all forms"
			if ($show_locked_link) {
				$dataEntry .=  "<div style='text-align:left;padding: 6px 0px 2px 0px;'>
									<img src='".APP_PATH_IMAGES."lock.png' class='imgfix'> 
									<a style='color:#A86700;font-weight:bold;font-size:12px' href='javascript:;' onclick=\"
										lockUnlockForms('$fetched2','$fetched','{$_GET['event_id']}','0','0','lock');
										return false;
									\">{$lang['bottom_40']}</a>
								</div>";
			}
			//Show link "Unlock all forms"
			if ($show_unlocked_link) {
				$dataEntry .=  "<div style='text-align:left;padding: 6px 0px 2px 0px;'>
									<img src='".APP_PATH_IMAGES."lock_open.png' class='imgfix'> 
									<a style='color:#666;font-weight:bold;font-size:12px' href='javascript:;' onclick=\"
										lockUnlockForms('$fetched2','$fetched','{$_GET['event_id']}','0','0','unlock');
										return false;
									\">{$lang['bottom_41']}</a>
								</div>";
			}
			
		}

		$dataEntry .= "</div>";

	}
}
	

/**
 * APPLICATIONS MENU
 * Show function links based on rights level (Don't allow designated Double Data Entry people to see pages displaying other user's data.)
 */
$appsMenuTitle = $lang['bottom_25'];
$appsMenu = "<div class='menubox'>";
//Calendar
if ($status < 2 && $user_rights['calendar']) { 
	$appsMenu .= "<div class='hang2'><img src='".APP_PATH_IMAGES."date.png' class='imgfix'>&nbsp;&nbsp;<a href='".APP_PATH_WEBROOT."Calendar/index.php?pid=$project_id'>{$lang['app_08']}</a></div>";
}
//Mobile Data Synchronization
if ($status < 2 && $user_rights['data_export_tool'] != "0" && isset($mobile_project) && $mobile_project != "0") { 
	$appsMenu .= "<div class='hang2'><img src='".APP_PATH_IMAGES."arrow_circle_double_135.png' class='imgfix'>&nbsp;&nbsp;<a href=\"" . APP_PATH_WEBROOT . "stuff.php?pid=$project_id\">{$lang['app_09']}</a></div>";
}
//Data Export Tool
if ($user_rights['data_export_tool'] != "0") { 
	$appsMenu .= "<div class='hang2'><img src='".APP_PATH_IMAGES."application_go.png' class='imgfix'>&nbsp;&nbsp;<a href=\"" . APP_PATH_WEBROOT . "DataExport/data_export_tool.php?pid=$project_id&view=simple_advanced\">{$lang['app_03']}</a></div>";
}
//Data Import Tool
if ($status < 2 && $user_rights['data_import_tool']) { 
	$appsMenu .= "<div class='hang2'><img src='".APP_PATH_IMAGES."table_row_insert.png' class='imgfix'>&nbsp;&nbsp;<a href=\"" . APP_PATH_WEBROOT . "DataImport/index.php?pid=$project_id\">{$lang['app_01']}</a></div>";
}
//Data Comparison Tool
if ($status < 2 && $user_rights['data_comparison_tool'] && isset($mobile_project) && $mobile_project != "2") { 
	$appsMenu .= "<div class='hang2'><img src='".APP_PATH_IMAGES."page_copy.png' class='imgfix'>&nbsp;&nbsp;<a href=\"" . APP_PATH_WEBROOT . "DataComparisonTool/index.php?pid=$project_id\">{$lang['app_02']}</a></div>";
}
//Data Logging
if ($user_rights['data_logging']) { 
	$appsMenu .= "<div class='hang2'><img src='".APP_PATH_IMAGES."report.png' class='imgfix'>&nbsp;&nbsp;<a href=\"" . APP_PATH_WEBROOT . "Logging/index.php?pid=$project_id\">".$lang['app_07']."</a></div>";
}
//File Repository
if ($user_rights['file_repository']) { 
	$appsMenu .= "<div class='hang2'><img src='".APP_PATH_IMAGES."page_white_stack.png' class='imgfix'>&nbsp;&nbsp;<a href=\"" . APP_PATH_WEBROOT . "FileRepository/index.php?pid=$project_id\">{$lang['app_04']}</a></div>";
}
//User Rights
if ($user_rights['user_rights']) { 
	$appsMenu .= "<div class='hang2'><img src='".APP_PATH_IMAGES."user.png' class='imgfix'>&nbsp;&nbsp;<a href=\"" . APP_PATH_WEBROOT . "UserRights/index.php?pid=$project_id\">{$lang['app_05']}</a></div>";
}
//Lock Record advanced setup
if ($user_rights['lock_record_customize'] > 0) {
	$appsMenu .= "<div class='hang2'><img src='".APP_PATH_IMAGES."lock_plus.png' class='imgfix'>&nbsp;&nbsp;<a href=\"" . APP_PATH_WEBROOT . "Locking/locking_customization.php?pid=$project_id\">{$lang['app_11']}</a></div>";
}
//E-signature and Locking Management
if ($status < 2 && $user_rights['lock_record'] > 0) {
	$appsMenu .= "<div class='hang2'><img src='".APP_PATH_IMAGES."tick_shield_lock.png' class='imgfix'>&nbsp;&nbsp;<a href=\"" . APP_PATH_WEBROOT . "Locking/esign_locking_management.php?pid=$project_id\">{$lang['app_12']}</a></div>";
}
// Randomization
if ($randomization && $status < 2 && ($user_rights['random_setup'] || $user_rights['random_dashboard'])) {
	$rpage = ($user_rights['random_setup']) ? "index.php" : "dashboard.php";
	$appsMenu .= "<div class='hang2'><img src='".APP_PATH_IMAGES."arrow_switch.png' class='imgfix'>&nbsp;&nbsp;<a href=\"" . APP_PATH_WEBROOT . "Randomization/$rpage?pid=$project_id\">{$lang['app_21']}</a></div>";
}
//Graphical Data View & Stats (only display link if Data Cleaner module is enabled)
if ($status < 2 && (($enable_plotting > 0 && $user_rights['graphical']) || ($enable_plotting < 1 && $super_user))) {
	$appsMenu .= "<div class='hang2'><img src='".APP_PATH_IMAGES."chart_curve.png' class='imgfix'>&nbsp;&nbsp;<a href=\"" . APP_PATH_WEBROOT . "Graphical/index.php?pid=$project_id\">{$lang['app_13']}</a></div>";
}
// Data Quality
if ($status < 2 && ($user_rights['data_quality_design'] || $user_rights['data_quality_execute'])) {
	$appsMenu .= "<div class='hang2'><img src='".APP_PATH_IMAGES."checklist.png' class='imgfix'>&nbsp;&nbsp;<a href=\"" . APP_PATH_WEBROOT . "DataQuality/index.php?pid=$project_id\">{$lang['app_20']}</a></div>";
}
// API
if ($status < 2 && $api_enabled && ($user_rights['api_export'] || $user_rights['api_import'])) {
	$appsMenu .= "<div class='hang2'><img src='".APP_PATH_IMAGES."computer.png' class='imgfix'>&nbsp;&nbsp;<a href=\"" . APP_PATH_WEBROOT . "API/project_api.php?pid=$project_id\">{$lang['setup_77']}</a></div>";
}
//Report Builder
if ($status < 2 && $user_rights['reports']) {
	$appsMenu .= "<div class='hang2'><img src='".APP_PATH_IMAGES."layout.png' class='imgfix'>&nbsp;&nbsp;<a href=\"" . APP_PATH_WEBROOT . "Reports/report_builder.php?pid=$project_id\">{$lang['app_14']}</a></div>";
}
$appsMenu .= "</div>";




/*
 ** REPORTS
 */
//Check to see if custom reports are specified for this project. If so, print the appropriate links.
//Build menu item for each separate report
$reportsListTitle = $lang['app_06'];
$reportsList = "";
if ($user_rights['reports'] && ($custom_reports != "" || $report_builder != "")) {	
	
	$reportsList .= "<div class='menubox notranslate'>";
	
	$i = 1;
	//Old custom reports
	if ($custom_reports != "") {
		foreach ($custom_report_menu as $key => $this_menu_item) {
			$reportsList .= "<div class='hang'><span style='font-size:7pt;color:#808080'>$i)</span> <a href='" . APP_PATH_WEBROOT . "Reports/report.php?pid=$project_id&id=$key'>$this_menu_item</a></div>";
			$i++;
		}
	}
	//Report Builder reports
	if ($report_builder != "") {
		foreach ($query_array as $key => $this_array) {
			$this_menu_item = $query_array[$key]['__TITLE__'];
			$reportsList .= "<div class='hang' valign='top' style='vertical-align:top;'><span class='reportnum'>$i)</span> <a href='" . APP_PATH_WEBROOT . "Reports/report.php?pid=$project_id&query_id=$key'>$this_menu_item</a></div>";
			$i++;
		}
	}
	
	$reportsList .= "</div>";
}


/**
 * HELP MENU
 */
$helpMenuTitle = '<div style="margin-top:-3px;"><img src="'.APP_PATH_IMAGES.'help.png" class="imgfix"> <span style="color:#3E72A8;">'.$lang['bottom_42'].'</span></div>';
$helpMenu = "<div class='menubox' style='font-size:11px;color:#444;'>
				
				<!-- Help & FAQ -->
				<div class='hang'>
					<img src='" . APP_PATH_IMAGES . "bullet_toggle_minus.png' class='imgfix'>
					<a style='color:#444;' href='" . APP_PATH_WEBROOT_PARENT . "index.php?action=help'>".$lang['bottom_27']."</a>
				</div>
				
				<!-- Video Tutorials -->
				<div class='hang'>
					<img src='" . APP_PATH_IMAGES . "bullet_toggle_plus.png' class='imgfix'>
					<a style='color:#444;' href='javascript:;' onclick=\"
						$('#menuvids').toggle('blind',{},500,
							function(){
								var objDiv = document.getElementById('west');
								objDiv.scrollTop = objDiv.scrollHeight;
							}
						);
					\">".$lang['bottom_28']."</a>
				</div>
				
				<div id='menuvids' style='display:none;line-height:1.2em;padding:2px 0 0 16px;'>
					<div class='menuvid'>
						&bull; <a onclick=\"popupvid('redcap_overview_brief01.flv')\" style='color:#3E72A8;font-size:11px;' href='javascript:;'>".$lang['bottom_58']."</a>
					</div>
					<div class='menuvid'>
						&bull; <a onclick=\"popupvid('redcap_overview02.flv')\" style='color:#3E72A8;font-size:11px;' href='javascript:;'>".$lang['bottom_57']."</a>
					</div>
					<div class='menuvid'>
						&bull; <a onclick=\"popupvid('redcap_survey_basics02.flv')\" style='color:#3E72A8;font-size:11px;' href='javascript:;'>".$lang['bottom_51']."</a>
					</div>
					<div class='menuvid'>
						&bull; <a onclick=\"popupvid('data_entry_overview_01.flv')\" style='color:#3E72A8;font-size:11px;' href='javascript:;'>".$lang['bottom_56']."</a>
					</div>
					<div class='menuvid'>
						&bull; <a onclick=\"popupvid('form_editor_upload_dd02.flv')\" style='color:#3E72A8;font-size:11px;' href='javascript:;'>".$lang['bottom_31']."</a>
					</div>
					<div class='menuvid'>
						&bull; <a onclick=\"popupvid('redcap_db_applications_menu02.flv')\" style='color:#3E72A8;font-size:11px;' href='javascript:;'>".$lang['bottom_32']."</a>
					</div>
				</div>
				
				<!-- Suggest a New Feature -->
				<div class='hang'>
					<img src='" . APP_PATH_IMAGES . "star_small.png' class='imgfix'>
					<a style='color:#444;' target='_blank' href='https://redcap.vanderbilt.edu/enduser_survey_redirect.php?redcap_version=$redcap_version&server_name=".SERVER_NAME."'>".$lang['bottom_52']."</a>
				</div>
				
				<!-- Google Chrome Frame install -->
				<div class='hang' style='margin-top:5px;display:" . (($isIE && vIE() < 8 && strpos($_SERVER['HTTP_USER_AGENT'], 'chromeframe') === false) ? 'block' : 'none') . ";'>
					<img src='" . APP_PATH_IMAGES . "snail.png' class='imgfix'>
					<a style='color:#800000;font-size:10px;font-family:tahoma;' href='javascript:;' onclick='displayChromeFrameInstallPopup();return false;'>".$lang['bottom_53']."</a>
				</div>
				
				<div style='padding-top:10px;'>
					".$lang['bottom_38']." <a href='mailto:$project_contact_email' style='color:#333;font-size:11px;text-decoration:underline;'>".$lang['bottom_39']."</a>".$lang['period']."
				</div>
				
			</div>";
			
/**
 * EXTERNAL PAGE LINKAGE
 */
if (defined("USERID") && isset($ExtRes)) {
	$externalLinkage = $ExtRes->renderHtmlPanel();
}


// Build the HTML panels for the left-hand menu
// Make sure that 'pid' in URL is defined (otherwise, we shouldn't be including this file)
if (isset($_GET['pid']) && is_numeric($_GET['pid']))
{
	$westHtml = renderPanel('', $logoHtml)
			  . renderPanel($dataEntryTitle, $dataEntry)
			  . renderPanel($appsMenuTitle, $appsMenu, 'app_panel');
	if ($externalLinkage != "") {
		$westHtml .= $externalLinkage;
	}
	if ($reportsList != "") {
		$westHtml .= renderPanel($reportsListTitle, $reportsList);
	}
	$westHtml .= renderPanel($helpMenuTitle, $helpMenu);
}
else
{
	// Since no 'pid' is in URL, then give warning that header/footer will not display properly
	$westHtml = renderPanel("&nbsp;", "<div style='padding:20px 15px;'><img src='".APP_PATH_IMAGES."exclamation.png' class='imgfix'> <b style='color:#800000;'>{$lang['bottom_54']}</b><br>{$lang['bottom_55']}</div>");
}


/**
 * PAGE CONTENT
 */
?>
<table border=0 cellspacing=0 style="width:100%;">
	<tr>
		<td valign="top" id="west" style="width:250px;">
			<div id="west_inner" style="width:250px;"><?php echo $westHtml ?></div>
		</td>
		<td valign="top" id="westpad">&nbsp;</td>
		<td valign="top" id="center">
			<div id="center_inner">
				<div id="subheader" class="notranslate">
					<?php if ($display_project_logo_institution) { ?>
						<?php if (trim($headerlogo) != "") echo "<img src='$headerlogo' title='".cleanHtml($institution)."' alt='".cleanHtml($institution)."' style='max-width:700px; expression(this.width > 700 ? 700 : true);'>"; ?>
						<div id="subheaderDiv1">
							<?php echo $institution . (($site_org_type == "") ? "" : "<br><span style='font-family:tahoma;font-size:13px;'>$site_org_type</span>") ?>
						</div>
					<?php } ?>
					<div id="subheaderDiv2" <?php if (!$display_project_logo_institution) echo 'style="border:0;padding-top:0;"'; ?>>
						<div style="max-width:700px;"><?php echo filter_tags($app_title) ?></div>
					</div>
				</div>

