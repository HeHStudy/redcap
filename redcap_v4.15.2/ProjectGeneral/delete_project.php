<?php
/*****************************************************************************************
**  REDCap is only available through a license agreement with Vanderbilt University
******************************************************************************************/

require_once dirname(dirname(__FILE__)) . '/Config/init_project.php';

// Default response
$response = "0";

// Only those with Design rights can delete a project when in development, and super users can always delete
if (isset($_POST['action']) && !empty($_POST['action']) && (($user_rights['design'] && $status < 1) || $super_user))
{
	// Give text to display in the pop-up
	if ($_POST['action'] == "prompt")
	{
		// Output html
		$response = "<p style='color:#800000;font-size:14px;margin:20px 0;'>
						<img src='".APP_PATH_IMAGES."delete.png' class='imgfix'> 
						{$lang['edit_project_51']} <b>".filter_tags(label_decode($app_title))."</b>{$lang['period']}
					</p>
					 <p>{$lang['edit_project_45']} \"{$lang['edit_project_48']}\" {$lang['edit_project_46']}</p>
					 <p style='font-family:verdana;font-weight:bold;margin:20px 0;'>
						{$lang['edit_project_47']} \"{$lang['edit_project_48']}\" {$lang['edit_project_49']}<br>
						<input type='text' id='delete_project_confirm' class='x-form-text x-form-field' style='border:2px solid red;width:170px;'>
					 </p>";
	}
	
	// Delete the project
	elseif ($_POST['action'] == "delete")
	{
		// Log the event (do this first because the project_id will get added for logging, and it needs to be set as null)
		log_event("", "redcap_projects", "MANAGE", $project_id, "project_id = $project_id", "Delete project");
		// Delete revision history first due to FK constraints on redcap_projects table
		mysql_query("delete FROM redcap_metadata_prod_revisions WHERE project_id = $project_id");
		// For uploaded edoc files, set delete_date so they'll later be auto-deleted from the server
		mysql_query("update redcap_edocs_metadata set delete_date = '".NOW."' where project_id = $project_id and delete_date is null");
		// Delete all project data and related info from ALL tables (most will be done by foreign keys automatically)
		mysql_query("delete from redcap_projects where project_id = $project_id");
		// Do other deletions manually because some tables don't have foreign key cascade deletion set
		mysql_query("delete from redcap_data where project_id = $project_id");
		// Don't actually delete these because they are logs, but simply remove any data-related info
		mysql_query("update redcap_log_view set event_id = null, record = null, form_name = null, miscellaneous = null where project_id = $project_id");
		mysql_query("update redcap_log_event set event_id = null, sql_log = null, data_values = null, pk = null where project_id = $project_id
					 and description != 'Delete project'");
		// Set response
		$response = "1";
	}
}

print $response;