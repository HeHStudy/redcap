<?php
/*****************************************************************************************
**  REDCap is only available through a license agreement with Vanderbilt University
******************************************************************************************/

// Config for non-project pages
require_once dirname(dirname(__FILE__)) . "/Config/init_global.php";

//If user is not a super user, go back to Home page
if (!$super_user) { redirect(APP_PATH_WEBROOT); }

## Set location of the web service to call
//$service_url = "http://freegeoip.appspot.com/csv/"; // 3rd party website
$service_url = "https://redcap.vanderbilt.edu/ip2coordinates.php?ips="; // Vanderbilt hosting Data Science Toolkit (http://www.datasciencetoolkit.org/)
// Set limit for batch
$ips_per_batch = 100;
	
## Get GPS locations for IP addresses of users from a web service and store in a cache table
function getIpLocation($timeWindow, $ips_per_batch) 
{
	global $service_url;
	// Collect lat/long into array
	$gps = array();
	// Collect IPs
	$ips = array();
	// Query to get all IP addresses in log for given period of time (ignore private IP ranges beginning with 10, 172, and 192.168)
	$sql = "select distinct ip from redcap_log_view where ts > '$timeWindow' and ip is not null and ip not like '10.%' 
			and ip not like '172.1_.%' and ip not like '172.2_.%' and ip not like '172.3_.%' and ip not like '192.168.%' 
			and length(ip) > 6 and user != 'site_admin'
			and ip not in (" . pre_query("select ip from redcap_dashboard_ip_location_cache") . ") 
			order by ip limit $ips_per_batch";
	$q = mysql_query($sql);
	while ($row = mysql_fetch_assoc($q))
	{
		// IP address 
		$ip = $ip_sent_to_service = $row['ip'];
		// Make sure IP sent to web service doesn't contain a comma (if double IP separated by comma, only use first IP)
		$comma_location = strpos($ip_sent_to_service, ",");
		if ($comma_location !== false) {
			$ip_sent_to_service = substr($ip_sent_to_service, 0, $comma_location);
		}
		// Add to array
		$ips[$ip_sent_to_service] = $ip;	
	}
	// Make the API request	
	$url = $service_url . implode(",", array_keys($ips));
	if (function_exists('curl_init')) 
	{
		// Use cURL
		$curlget = curl_init();
		curl_setopt($curlget, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($curlget, CURLOPT_VERBOSE, 1);
		curl_setopt($curlget, CURLOPT_URL, $url);
		curl_setopt($curlget, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curlget, CURLOPT_PROXY, PROXY_HOSTNAME); // If using a proxy
		$ip_csv = curl_exec($curlget);
		curl_close($curlget);
	} else {
		$ip_csv = http_get($url);
	}
	if (!empty($ip_csv))
	{
		foreach (explode("\n", $ip_csv) as $ip_return)
		{
			// Parse CSV into array
			$ip_array = explode(",", $ip_return);
			// Get original IP (may be different than one sent to service if had multiple IPs together)
			$ip = $ips[$ip_array[1]];
			// Put latitude, longitude, and city/state/country in table
			$gps[$ip] = array($ip_array[8], $ip_array[9], $ip_array[6], $ip_array[5], $ip_array[3]);
		}
	}
	// Return IPs with their locations as an array
	return $gps;
}

// Count how many IPs have NOT been processed and had their location fetched
function numIPsNotProcessed($timeWindow)
{
	$sql = "select count(distinct(ip)) from redcap_log_view where ts > '$timeWindow' and ip is not null 
			and ip not like '10.%' and ip not like '172.1_.%' and ip not like '172.2_.%' and ip not like '172.3_.%' 
			and ip not like '192.168.%' and length(ip) > 6 and user != 'site_admin' and ip not in 
			(" . pre_query("select ip from redcap_dashboard_ip_location_cache") . ")";
	$q = mysql_query($sql);
	return mysql_result($q, 0);
}

// Use number of hours to determine timestamp in the past
function getTimeWindow($timeWindowHours)
{
	if (!is_numeric($timeWindowHours)) $timeWindowHours = 24;
	return date("Y-m-d H:i:s", mktime(date("H")-$timeWindowHours,date("i"),date("s"),date("m"),date("d"),date("Y")));
}




// Perform any actions before displaying the CUI table
if (isset($_GET['ip_action']))
{
	switch ($_GET['ip_action'])
	{
		// Store Google Maps API key for server
		case 'save_key':
			if (isset($_GET['googlemap_key']) && trim($_GET['googlemap_key']) != "")
			{
				$sql = "update redcap_config set value = '" . prep($_GET['googlemap_key']) . "' where field_name = 'googlemap_key'";
				print (mysql_query($sql) ? '1' : '0');
				exit;
			}
			exit('0');
			break;
			
		// Ping the web service
		case 'ping':
			// Make the API request	
			$url = $service_url . "72.14.247.141"; // Send static IP we know
			if (function_exists('curl_init')) 
			{
				// Use cURL
				$curlget = curl_init();
				curl_setopt($curlget, CURLOPT_SSL_VERIFYPEER, FALSE);
				curl_setopt($curlget, CURLOPT_VERBOSE, 1);
				curl_setopt($curlget, CURLOPT_URL, $url);
				curl_setopt($curlget, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($curlget, CURLOPT_PROXY, PROXY_HOSTNAME); // If using a proxy
				$response = curl_exec($curlget);
				curl_close($curlget);
			} else {
				$response = http_get($url);
			}
			// Return the response
			exit($response);
			break;
			
		// Obtain locations of IP for a single batch for given time period and add to the cache table
		case 'getNextBatch':
			// Put all cached lat/long into array
			$gps = array();
			// Get timestamp for window set
			$timeWindow = getTimeWindow($_GET['timeWindowHours']);
			foreach (getIpLocation($timeWindow, $ips_per_batch) as $ip=>$ip_array)
			{
				// Add to table
				$sql = "insert into redcap_dashboard_ip_location_cache (ip, latitude, longitude, city, region, country) values
						('".prep($ip)."', '".prep($ip_array[0])."', '".prep($ip_array[1])."', '".prep($ip_array[2])."', '".prep($ip_array[3])."', '".prep($ip_array[4])."')";
				mysql_query($sql);
				// Add IP to array
				if (!empty($ip_array)) {
					$gps[] = $ip;
				}
			}
			// Return how many projects are left to be processed
			print "var numIPsNotProcessed = " . numIPsNotProcessed($timeWindow) . ";\n";
			// Also return the javascript to add the new markers we just cached
			$gps2 = array();
			$sql = "select c.*, i.username, i.user_email, i.user_firstname, i.user_lastname from 
					(select distinct ip, user from redcap_log_view where ts > '$timeWindow' 
					and ip in ('" . implode("', '", $gps) . "') and user != 'site_admin') v, 
					redcap_dashboard_ip_location_cache c, redcap_user_information i 
					where v.ip = c.ip and i.username = v.user";
			$q = mysql_query($sql);
			while ($row = mysql_fetch_assoc($q))
			{
				$gps_label = "<b>{$row['city']}" . (($row['region'] != "" && !is_numeric($row['region'])) ? ", " . $row['region'] : "") . ", {$row['country']}</b>"
						   . "<br>{$row['username']} (<a style='text-decoration:underline;' href='mailto:{$row['user_email']}'>{$row['user_firstname']} {$row['user_lastname']}</a>)"
						   . " - <a style='color:#800000;text-decoration:underline;font-family:tahoma;font-size:11px;' target='_blank' href='view_projects.php?view=all_projects&userid={$row['username']}'>view projects</a>";
				if ($row['latitude'] != "" && $row['longitude'] != "") {
					$gps2[$row['ip']] = array($row['latitude'], $row['longitude'], $gps_label);
				}
			}
			// Loop through all locations
			foreach ($gps2 as $attr) {
				if ($attr[0] != 38 && $attr[1] != -97) { // Ignore generic US location (in Kansas)
					print "addMapMarker({$attr[0]}, {$attr[1]}, '" . cleanHtml($attr[2]) . "');\n";
				}
			}
			break;
			
		// Retrieve all marker info from the IP location cache table for given time period
		case 'getAllMarkers':
			// Put all cached lat/long into array
			$gps = array();
			// Get timestamp for window set
			$timeWindow = getTimeWindow($_GET['timeWindowHours']);
			$sql = "select c.*, i.username, i.user_email, i.user_firstname, i.user_lastname from 
					(select distinct ip, user from redcap_log_view where ts > '$timeWindow' and ip is not null 
					and ip not like '10.%' and ip not like '172.1_.%' and ip not like '172.2_.%' and ip not like '172.3_.%' 
					and ip not like '192.168.%' and length(ip) > 6 and user != 'site_admin'
					) v, redcap_dashboard_ip_location_cache c, redcap_user_information i 
					where v.ip = c.ip and i.username = v.user";
			$q = mysql_query($sql);
			while ($row = mysql_fetch_assoc($q))
			{
				$gps_label = "<b>" . ($row['city'] != "" ? $row['city'] . ", " : "")
						   . (($row['region'] != "" && !is_numeric($row['region'])) ? $row['region'] . ", " : "") 
						   . $row['country'] . "</b>"
						   . "<br>{$row['username']} (<a style='text-decoration:underline;' href='mailto:{$row['user_email']}'>{$row['user_firstname']} {$row['user_lastname']}</a>)"
						   . " - <a style='color:#800000;text-decoration:underline;font-family:tahoma;font-size:11px;' target='_blank' href='view_projects.php?view=all_projects&userid={$row['username']}'>view projects</a>";
				if ($row['latitude'] != "" && $row['longitude'] != "") {
					$gps[$row['ip']] = array($row['latitude'], $row['longitude'], $gps_label);
				}
			}
			// If an ajax call, then return the $gps array to be parsed by javascript
			if ($isAjax) {
				// Loop through all locations
				foreach ($gps as $attr) {
					if ($attr[0] != 38 && $attr[1] != -97) { // Ignore generic US location (in Kansas)
						print "addMapMarker({$attr[0]}, {$attr[1]}, '" . cleanHtml($attr[2]) . "');\n";
					}
				}
				// Set number of IP addresses representing in html
				print "document.getElementById('ip_count').innerHTML = '" . count($gps) . "';\n";
			}
			break;
	}
}




// If not an ajax request, display the 
if (!$isAjax)
{
	?>	
	<div class="blue" style="margin-top:20px;padding:10px;font-size:14px;">
		<img src="<?php echo APP_PATH_IMAGES ?>mm_20_blue.png" class="imgfix"> 
		<b>Locations of users accessing REDCap</b> in the past
		<select id="ip_timewindowhours" class="x-form-text x-form-field" style="padding-right:0;height:22px;" onchange="setTimeWindow(this.value);">
			<option value="1d" <?php if ($_GET['plottime'] == "1d") echo "selected"; ?>>24 hours</option>
			<option value="1w" <?php if ($_GET['plottime'] == "1w") echo "selected"; ?>>week</option>
			<option value="1m" <?php if ($_GET['plottime'] == "1m") echo "selected"; ?>>month</option>
			<option value="3m" <?php if ($_GET['plottime'] == "3m") echo "selected"; ?>>3 months</option>
			<option value="6m" <?php if ($_GET['plottime'] == "6m") echo "selected"; ?>>6 months</option>
			<option value="12m" <?php if ($_GET['plottime'] == "12m") echo "selected"; ?>>year</option>
		</select>
		<span id="ip_progress" style="margin-left:10px;visibility:hidden;color:#444;">
			<img src="<?php echo APP_PATH_IMAGES ?>progress_circle.gif" class="imgfix">
		</span>
		<div style="font-size:11px;margin-left:17px;padding-top:4px;">
			Uses IP address of users to determine location, excluding survey participants<br>
			(representing <span id="ip_count"><?php echo count($gps) ?></span> unique IP addresses)
		</div>	
	</div>
	<?php
	// Get count of IPs not been cached yet
	$numIPsNotProcessed = numIPsNotProcessed(getTimeWindow($_GET['timeWindowHours']));
	// Check if we have a key for the Google Maps API stored yet
	if ($googlemap_key == "")
	{
		?>
		<div class="red" style="font-family:arial;padding-bottom:20px;">
			<img src="<?php echo APP_PATH_IMAGES ?>exclamation.png" class="imgfix">
			<b>Need Google Maps API key:</b><br>
			To use the user location mapping feature that utilizes the Google Maps API, you must first obtain
			a Google Maps API key, which will be unique to your server (specifically, your server's domain name).
			Follow the link below to obtain one for your server domain or IP: <i><?php echo SERVER_NAME ?></i>.
			<br><br>
			<b>STEP #1:</b> <a href="http://code.google.com/apis/maps/signup.html" target="_blank">Sign up here for the Google Maps API</a>
			<br><br>
			<b>STEP #2:</b> Once you have obtained the Google Maps API key for your server, enter it below to save it in REDCap.<br>
			<b>Google Map API key:</b>
			<input type="text" style="width:200px" id="googlemap_key">
			<button onclick="if (trim($('#googlemap_key').val()) != '') { this.disabled=true; saveGMAPIkey(); } else { alert('Enter a key!'); }">Save</button>
		</div>
		<?php	
	} 
	// Display Update button to fetch new IP locations
	elseif ($numIPsNotProcessed > 0)
	{
		?>
		<div id="ip_progress_update_div" class="yellow" style="font-family:arial;">
			<img src="<?php echo APP_PATH_IMAGES ?>exclamation_orange.png" class="imgfix">
			<span id="numIpsNotProcessed"><?php echo $numIPsNotProcessed ?></span> IP addresses have not been processed yet and are thus not displayed.
			<button id="updatemap_btn" onclick="this.disabled=true;getMarkersNextBatch();" style="margin:0 10px;">Update map</button>
			<img id="ip_progress_update" style="visibility:hidden;" src="<?php echo APP_PATH_IMAGES ?>progress_circle.gif" class="imgfix">
		</div>
		<?php
	}
	?>	
	<div id="gps_map" style="height:350px;"></div>
	<?php
}