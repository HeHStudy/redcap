<?php
/*****************************************************************************************
**  REDCap is only available through a license agreement with Vanderbilt University
******************************************************************************************/

include 'header.php';

$changesSaved = false;

// If project default values were changed, update redcap_config table with new values
if ($_SERVER['REQUEST_METHOD'] == 'POST')
{
	$changes_log = array();
	$sql_all = array();
	foreach ($_POST as $this_field=>$this_value) {
		// Save this individual field value
		$sql = "UPDATE redcap_config SET value = '".prep($this_value)."' WHERE field_name = '$this_field'";
		$q = mysql_query($sql);
		
		// Log changes (if change was made)
		if ($q && mysql_affected_rows() > 0) {
			$sql_all[] = $sql;
			$changes_log[] = "$this_field = '$this_value'";
		}
	}

	// Log any changes in log_event table
	if (count($changes_log) > 0) {
		log_event(implode(";\n",$sql_all),"redcap_config","MANAGE","",implode(",\n",$changes_log),"Modify system configuration");
	}

	$changesSaved = true;
}

// Retrieve data to pre-fill in form
$element_data = array();

$q = mysql_query("select * from redcap_config");
while ($row = mysql_fetch_array($q)) {
		$element_data[$row['field_name']] = $row['value'];
}
// Make sure redcap_base_url has slash on end
if (substr($element_data['redcap_base_url'], -1) != '/') $element_data['redcap_base_url'] .= '/';

?>

<?php
if ($changesSaved)
{
	// Show user message that values were changed
	print  "<div class='yellow' style='margin-bottom: 20px; text-align:center'>
			<img src='".APP_PATH_IMAGES."exclamation_orange.png' class='imgfix'>
			{$lang['control_center_19']}
			</div>";
}
?>

<h3 style="margin-top: 0;"><?php echo $lang['control_center_125'] ?></h3>

<form action='general_settings.php' enctype='multipart/form-data' target='_self' method='post' name='form' id='form'>
<?php
// Go ahead and manually add the CSRF token even though jQuery will automatically add it after DOM loads.
// (This is done in case the page is very long and user submits form before the DOM has finished loading.)
print "<input type='hidden' name='redcap_csrf_token' value='".getCsrfToken()."'>";
?>
<table style="border: 1px solid #ccc; background-color: #f0f0f0;">

<tr  id="system_offline-tr" sq_id="system_offline">
	<td class="cc_label"><?php echo $lang['system_config_02'] ?></td>
	<td class="cc_data">
		<select class="x-form-text x-form-field" style="padding-right:0; height:22px;" name="system_offline">
			<option value='0' <?php echo ($element_data['system_offline'] == 0) ? "selected" : "" ?>><?php echo $lang['system_config_05'] ?></option>
			<option value='1' <?php echo ($element_data['system_offline'] == 1) ? "selected" : "" ?>><?php echo $lang['system_config_04'] ?></option>
		</select><br/>
		<div class="cc_info">
			<?php echo $lang['system_config_03'] ?>
		</div>
	</td>
</tr>

<tr>
	<td class="cc_label"><?php echo $lang['pub_105'] ?></td>
	<td class="cc_data">
		<input class='x-form-text x-form-field ' type='text' name='redcap_base_url' value='<?php echo $element_data['redcap_base_url'] ?>' size="60" /><br/>
		<div class="cc_info">
			<?php echo $lang['pub_110'] ?>
		</div>
		<?php if ($element_data['redcap_base_url'] != APP_PATH_WEBROOT_FULL) { ?>
		<div class="cc_info" style="color:red;">
			<b><?php echo $lang['global_02'].$lang['colon'] ?></b> <?php echo $lang['control_center_318'] ?>
			<b><?php echo APP_PATH_WEBROOT_FULL ?></b><br><?php echo $lang['control_center_319'] ?>
		</div>
		<?php } ?>
	</td>
</tr>

<tr>
	<td class="cc_label"><?php echo $lang['system_config_187'] ?></td>
	<td class="cc_data">
		<input class='x-form-text x-form-field ' type='text' name='proxy_hostname' value='<?php echo $element_data['proxy_hostname'] ?>' size="60" /><br/>
		<div class="cc_info">
			<?php echo $lang['system_config_188'] ?><br>(e.g. https://10.151.18.250:211)
		</div>
	</td>
</tr>

<tr  id="language_global-tr" sq_id="language_global">
	<td class="cc_label"><?php echo $lang['system_config_112'] ?></td>
	<td class="cc_data">
		<select class="x-form-text x-form-field" style="padding-right:0; height:22px;" name="language_global"
			onchange="alert('<?php echo $lang['global_02'] ?>:\n<?php echo cleanHtml($lang['system_config_113']) ?>');">
			<?php
			$languages = getLanguageList();
			foreach ($languages as $language) {
				$selected = ($element_data['language_global'] == $language) ? "selected" : "";
				echo "<option value='$language' $selected>$language</option>";
			}
			?>
		</select><br/>
		<div class="cc_info">
			<?php echo $lang['system_config_107'] ?>
			<a href="<?php echo APP_PATH_WEBROOT ?>LanguageUpdater/" target='_blank' style='text-decoration:underline;'>Language File Creator/Updater</a>
			<?php echo $lang['system_config_108'] ?>
			<a href='https://iwg.devguard.com/trac/redcap/wiki/Languages' target='_blank' style='text-decoration:underline;'>REDCap wiki Language Center</a>.
			<br/><br/><?php echo $lang['system_config_109']." ".dirname(APP_PATH_DOCROOT).DS."languages".DS ?>
		</div>
	</td>
</tr>

<tr>
	<td class="cc_label">
		<?php echo $lang['system_config_226'] ?>
		<div class="cc_info">
			<?php echo $lang['system_config_227'] ?>
		</div>
	</td>
	<td class="cc_data">
		<textarea class='x-form-field notesbox' name='helpfaq_custom_text'><?php echo $element_data['helpfaq_custom_text'] ?></textarea><br/>
		<div id='helpfaq_custom_text-expand' style='text-align:right;'>
			<a href='javascript:;' style='font-weight:normal;text-decoration:none;color:#999;font-family:tahoma;font-size:10px;'
				onclick="growTextarea('helpfaq_custom_text')">Expand</a>&nbsp;
		</div>
	</td>
</tr>

<tr  id="certify_text_create-tr" sq_id="certify_text_create">
	<td class="cc_label"><?php echo $lang['system_config_38'] ?></td>
	<td class="cc_data">
		<textarea class='x-form-field notesbox' id='certify_text_create' name='certify_text_create'><?php echo $element_data['certify_text_create'] ?></textarea><br/>
		<div id='certify_text_create-expand' style='text-align:right;'>
			<a href='javascript:;' style='font-weight:normal;text-decoration:none;color:#999;font-family:tahoma;font-size:10px;'
				onclick="growTextarea('certify_text_create')">Expand</a>&nbsp;
		</div>
		<div class="cc_info">
			<?php echo $lang['system_config_39'] ?>
		</div>
	</td>
</tr>
<tr  id="certify_text_prod-tr" sq_id="certify_text_prod">
	<td class="cc_label"><?php echo $lang['system_config_40'] ?></td>
	<td class="cc_data">
		<textarea class='x-form-field notesbox' id='certify_text_prod' name='certify_text_prod'><?php echo $element_data['certify_text_prod'] ?></textarea><br/>
		<div id='certify_text_prod-expand' style='text-align:right;'>
			<a href='javascript:;' style='font-weight:normal;text-decoration:none;color:#999;font-family:tahoma;font-size:10px;'
				onclick="growTextarea('certify_text_prod')">Expand</a>&nbsp;
		</div>
		<div class="cc_info">
			<?php echo $lang['system_config_41'] ?>
		</div>
	</td>
</tr>
<tr  id="identifier_keywords-tr" sq_id="identifier_keywords">
	<td class="cc_label"><img src="<?php echo APP_PATH_IMAGES ?>find.png" class="imgfix"> <?php echo "{$lang['identifier_check_01']} - {$lang['system_config_115']}" ?></td>
	<td class="cc_data">
		<textarea class='x-form-field notesbox' id='identifier_keywords' name='identifier_keywords'><?php echo $element_data['identifier_keywords'] ?></textarea><br/>
		<div id='identifier_keywords-expand' style='text-align:right;'>
			<a href='javascript:;' style='font-weight:normal;text-decoration:none;color:#999;font-family:tahoma;font-size:10px;'
				onclick="growTextarea('identifier_keywords')">Expand</a>&nbsp;
		</div>
		<div class="cc_info">
			<?php echo "{$lang['system_config_116']} {$lang['identifier_check_01']}{$lang['period']}
				{$lang['system_config_117']}<br><br>
				<b>{$lang['system_config_64']}</b><br>$identifier_keywords_default" ?>
		</div>
	</td>
</tr>
</table><br/>
<div style="text-align: center;"><input type='submit' name='' value='Save Changes' /></div><br/>
</form>

<?php include 'footer.php'; ?>