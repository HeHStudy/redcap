<?php
/*****************************************************************************************
**  REDCap is only available through a license agreement with Vanderbilt University
******************************************************************************************/

// Build array for rendering form
$elements   = array();
	
$elements[] = array("rr_type"=>"header", "css_element_class"=>"header", "value"=>$lang['system_config_01']);
$elements[] = array("rr_type"=>"select", "name"=>"superusers_only_create_project", 
					"label"=>"{$lang['system_config_12']}<br><span class='configsub'>{$lang['system_config_13']}</span>",
					"enum"=>"1, {$lang['system_config_14']} \\n 0, {$lang['system_config_15']}");
$elements[] = array("rr_type"=>"select", "name"=>"superusers_only_move_to_prod", 
					"label"=>"{$lang['system_config_16']}<br><span class='configsub'>{$lang['system_config_17']}</span>",
					"enum"=>"1, {$lang['system_config_18']} \\n 2, {$lang['system_config_147']} \\n 0, {$lang['system_config_146']}");
$elements[] = array("rr_type"=>"select", "name"=>"auto_report_stats", 
					"label"=>"{$lang['system_config_28']}<br><span class='configsub'>{$lang['system_config_29']}</span>",
					"enum"=>"0, {$lang['system_config_30']} \\n 1, {$lang['system_config_31']}", 
					"note"=>"<span style='color:#666;'>{$lang['dashboard_94']} {$lang['dashboard_95']}</span>"
					);
					
$elements[] = array("rr_type"=>"select", "name"=>"enable_url_shortener", 
					"label"=>"{$lang['system_config_132']}",
					"enum"=>"0, {$lang['global_23']} \\n 1, {$lang['system_config_27']}", 
					"note"=>"<span style='color:#666;'>{$lang['system_config_133']}</span>");
 
// HOME PAGE VALUES
$elements[] = array("rr_type"=>"header", "css_element_class"=>"header", "value"=>$lang['system_config_72']);
$elements[] = array("rr_type"=>"text", "name"=>"homepage_contact", 
					"label"=>$lang['system_config_77']);
$elements[] = array("rr_type"=>"text", "name"=>"homepage_contact_email", 
					"label"=>$lang['system_config_78'],
					"onblur"=>"redcap_validate(this,'0','','hard','email')");

$elements[] = array("rr_type"=>"header", "css_element_class"=>"header", "value"=>$lang['system_config_88']);
$elements[] = array("rr_type"=>"text", "name"=>"project_contact_name", 
					"label"=>"{$lang['system_config_91']}<br><span class='configsub'>({$lang['system_config_92']})</span>");
$elements[] = array("rr_type"=>"text", "name"=>"project_contact_email", 
					"label"=>"{$lang['system_config_93']} {$lang['system_config_91']}",
					"onblur"=>"redcap_validate(this,'','','hard','email')");						
$elements[] = array("rr_type"=>"text", "name"=>"project_contact_prod_changes_name", 
					"label"=>"{$lang['system_config_94']}<br><span class='configsub'>({$lang['system_config_95']})</span>");
$elements[] = array("rr_type"=>"text", "name"=>"project_contact_prod_changes_email", 
					"label"=>"{$lang['system_config_93']} {$lang['system_config_96']}",
					"onblur"=>"redcap_validate(this,'','','hard','email')");
$elements[] = array("rr_type"=>"text", "name"=>"institution", 
					"label"=>$lang['system_config_97']);	
$elements[] = array("rr_type"=>"text", "name"=>"site_org_type", 
					"label"=>$lang['system_config_98']);
					
$elements[] = array("rr_type"=>"submit", "name"=>"", 
					"label"=>"", "value"=>"Save Changes");
