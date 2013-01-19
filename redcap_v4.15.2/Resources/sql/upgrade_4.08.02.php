<?php

/**
 * Determine if column 'append_pid' exists in redcap_external_links table. If not, add it.
 * (to fix bug created in 4.8.1 - although later patched in 4.8.1 zips prior to 4.8.2 release)
 */
 
// Get table info
$sql = "SHOW CREATE TABLE redcap_external_links";
$q = mysql_query($sql);
if ($q && mysql_num_rows($q) == 1)
{
	// Get the 'create table' statement to parse
	$result = mysql_fetch_array($q);	
	// Set as lower case to prevent case sensitivity issues
	$createTableStatement = strtolower($result[1]);
	// Do regex
	if (!preg_match("/(append_pid)/", $createTableStatement))
	{
		// Since column doesn't exist, add it
		print "ALTER TABLE  `redcap_external_links` ADD  `append_pid` INT( 1 ) NOT NULL DEFAULT  '0' "
			. "COMMENT  'Append project_id to URL' AFTER  `append_record_info`;\n";
	}
}
