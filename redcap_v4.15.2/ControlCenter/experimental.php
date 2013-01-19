<?php
/*****************************************************************************************
**  REDCap is only available through a license agreement with Vanderbilt University
******************************************************************************************/

include 'header.php';

//If user is not a super user, go back to Home page
if (!$super_user) { redirect(APP_PATH_WEBROOT); }


## SET TIME PERIODS TO VIEW
// Default time period: Past day
if (!isset($_GET['plottime'])) $_GET['plottime'] = "1d";
// Past day
if ($_GET['plottime'] == "1d") {
	$date_label = $lang['dashboard_89'];
	$timeWindowHours = 24;
// Past week
} elseif ($_GET['plottime'] == "1w") {
	$date_label = $lang['dashboard_07'];
	$timeWindowHours = 24*7;
// Past month
} elseif ($_GET['plottime'] == "1m") {
	$date_label = $lang['dashboard_08'];
	$timeWindowHours = 24*30;
// Past three months
} elseif ($_GET['plottime'] == "3m") {
	$date_label = $lang['dashboard_09'];
	$timeWindowHours = 24*30*3;
// Past six months
} elseif ($_GET['plottime'] == "6m") {
	$date_label = $lang['dashboard_10'];
	$timeWindowHours = 24*30*6;
// Past year
} elseif ($_GET['plottime'] == "12m") {
	$date_label = $lang['dashboard_11'];
	$timeWindowHours = 24*30*6;
// All
} elseif ($_GET['plottime'] == "all") {
	$date_label = $lang['dashboard_12'];
	$timeWindowHours = 24*365*10;
}


// Check if web services have been contacted before (i.e. are tables empty)
$sql = "select 1 from redcap_dashboard_ip_location_cache limit 1";
$ip_table_count = mysql_num_rows(mysql_query($sql));
$sql = "select 1 from  redcap_dashboard_concept_codes limit 1";
$cui_table_count = mysql_num_rows(mysql_query($sql));
$promptToPingWebService = (($ip_table_count+$cui_table_count) == 0 && $googlemap_key == "");


?>
<style type="text/css">
.data, .label { padding: 3px 6px; font-weight: normal; }
.blue, .yellow, .red { max-width:750px; }
</style> 


<h3 style="margin-top: 0;"><?php echo $lang['control_center_126'] ?></h3>


<?php if ($ip_table_count+$cui_table_count > 0) { ?>
<!-- Give details of page if already being used -->
<p>
	This page contains features that are still being evaluated for use within REDCap
	with regard to the extent of their usefulness and the precision of the data represented,
	and thus they are not yet permanent or official features in REDCap.
	<span style="color:#800000;"><b>NOTE:</b> All data represented below are specific only to your REDCap installation at your institution.
	Third-party services are utilized for the functionality below, but no actual project data from REDCap
	has been sent to those services.</span>
</p>
<?php } ?>


<?php
// If page has never been used, perform initial test to contact web services
if ($promptToPingWebService && !isset($_GET['ping']))
{
	?>
	<div class="green" style="margin:30px 0 50px;">
		<b>Setup and configuration:</b><br>
		It seems that you may not have used any of the experimental services yet that will be displayed on this page. 
		To make sure they will work successfully
		with your server configuration, it is recommended that an initial test be performed. (NOTE: If your
		web server is behind a firewall and cannot contact outside websites on the World Wide Web, then the services
		below will not work, as they require the use of third-party web services.)
		<br><br>
		<button onclick="this.disabled=true;$('#ping_progress').show();pingIpService();">Test web service</button> &nbsp;
		<img id="ping_progress" style="display:none;" src="<?php echo APP_PATH_IMAGES ?>progress_circle.gif" class="imgfix">
		<span id="ping_notice" style="display:none;font-weight:bold;">Failed!</span>
	</div>
	<script type="text/javascript">
	// Ping the IP web service to determine if
	function pingIpService() {
		var receivedResponse = false;
		$.get(app_path_webroot+'ControlCenter/dashboard_ip_location.php', { ip_action: 'ping' }, function(data){
			receivedResponse = true;
			$('#ping_progress').hide();
			alert("Success!\n\nThe web service was successfully contacted. The page will now refresh to allow you to utilize the services.");
			window.location.href = app_path_webroot+page+'?ping=success';
		});
		// After 15 seconds, check response in input field (in case the call to web service hangs)
		setTimeout(function(){
			if (!receivedResponse) {
				$('#ping_progress').hide();
				$('#ping_notice').show();
				alert("Failed!\n\nUnfortunately, your server was not able to contact the web service, so you will not "
					+ "be able to utilize the services on this page. The reason for this might be because the server "
					+ "is behind a firewall.");
			}
		}, 15000);
	}
	</script>
	<?php
	// End page
	$objHtmlPage->PrintFooter();
	exit;
}
?>


<!-- Google Map -->
<?php
$_GET['ip_action']		 = 'getAllMarkers';
$_GET['timeWindowHours'] = $timeWindowHours;
include APP_PATH_DOCROOT . "ControlCenter/dashboard_ip_location.php";
$googleMapsURL = (SSL ? 'https://maps-api-ssl.google.com' : 'http://maps.google.com');
?>


<!-- Concept code table -->
<div id="cui_table" style="margin-top:50px;max-width:750px;">
<?php include APP_PATH_DOCROOT . "ControlCenter/dashboard_concept_codes.php"; ?>
</div>

<?php if ($googlemap_key != "") { ?>
	<!-- Google Map (call over SSL if using SSL for REDCap) -->
	<script src="<?php echo $googleMapsURL ?>/maps?file=api&amp;v=2&amp;sensor=false&amp;key=<?php echo $googlemap_key ?>" type="text/javascript"></script>
	<script type='text/javascript'>
	// Instantiate map
	var map = new GMap2(document.getElementById('gps_map'));
	map.addControl(new GLargeMapControl());
	map.addControl(new GMapTypeControl());
	var center = new GLatLng(30, 0);
	map.setCenter(center, 1, G_NORMAL_MAP);
	if (window.attachEvent) {
		window.attachEvent('onresize', function() {this.map.onResize()} );
	} else {
		window.addEventListener('resize', function() {this.map.onResize()} , false);
	}
	// Initialize icons				
	var tinyIcon = new GIcon();
	tinyIcon.image = "<?php echo APP_PATH_IMAGES ?>mm_20_blue.png";
	tinyIcon.shadow = "<?php echo APP_PATH_IMAGES ?>mm_20_shadow.png";
	tinyIcon.iconSize = new GSize(12, 20);
	tinyIcon.shadowSize = new GSize(22, 20);
	tinyIcon.iconAnchor = new GPoint(6, 20);
	tinyIcon.infoWindowAnchor = new GPoint(5, 1);
	markerOptions = { icon:tinyIcon };
	// Add map markers
	<?php 
	foreach ($gps as $ip=>$attr) { 
		if ($attr[0] != 38 && $attr[1] != -97) { // Ignore generic US location (in Kansas) 
			?>addMapMarker(<?php echo $attr[0] ?>, <?php echo $attr[1] ?>, '<?php echo cleanHtml($attr[2]) ?>'); <?php 
		}
	}
	?>	
	// Get all markers via ajax for new time-frame
	function getAllMarkers(timeWindowHours) {
		$.get(app_path_webroot+'ControlCenter/dashboard_ip_location.php', { timeWindowHours: timeWindowHours, ip_action: 'getAllMarkers' }, function(data){
			try {
				map.clearOverlays();
				if (data.length > 0) eval(data);
			} catch(e) {
				alert(woops);
			}
		});
	}
	// Get next batch of markers via ajax
	function getMarkersNextBatch() {
		$('#ip_progress_update').css('visibility','visible');
		var timeWindowHours = <?php echo $timeWindowHours ?>;
		$.get(app_path_webroot+'ControlCenter/dashboard_ip_location.php', { timeWindowHours: timeWindowHours, ip_action: 'getNextBatch' }, function(data){
			eval(data);
			if (numIPsNotProcessed > 0) {
				$('#numIpsNotProcessed').html(numIPsNotProcessed);
				// If more need to be processed, then do again
				getMarkersNextBatch(timeWindowHours);
			} else {
				$('#ip_progress_update_div').hide();
			}
		});
	}	
	// Add single marker on map
	function addMapMarker(latitude,longitude,label) {
		var point = new GLatLng(latitude,longitude);	
		var marker = new GMarker(point);
		GEvent.addListener(marker, "click", function() {
			marker.openInfoWindowHtml(label);
		});
		var myPlacemark = new GMarker(point, markerOptions);
		map.addOverlay(myPlacemark);
		myPlacemark.bindInfoWindowHtml(label);
	}	
	</script>
<?php } else { ?>
	<script type='text/javascript'>
	$('#gps_map').hide();
	</script>
<?php } ?>

<script type='text/javascript'>
// Update the CUI table by sending new requests to web service (keep looping till done)
function updateCuiTable() {
	$('#process_icon').css('visibility','visible');
	$.get(app_path_webroot+'ControlCenter/dashboard_concept_codes.php', { action: 'getNextBatch' }, function(data){
		// Update the table on the page
		$.get(app_path_webroot+'ControlCenter/dashboard_concept_codes.php', { }, function(data2){
			$('#cui_table').html(data2);
		});
		// If more need to be processed, then do again
		if ((data*1) > 0) {
			updateCuiTable();
		}
	});
}
// Retrieve a list of project titles for a given CUI
function getProjectsFromCui(cui) {
	var cuiRowId = 'cui-row-'+cui;
	var cuiRowIdOpened = 'cui-row-open-'+cui;
	if ($('#'+cuiRowIdOpened).length) {
		$('#'+cuiRowIdOpened).remove();
	} else {
		// Get project list and place in table cell
		$.get(app_path_webroot+'ControlCenter/dashboard_concept_codes.php', { cui: cui, action: 'getProjList' }, function(data){
			$('#'+cuiRowId).after('<tr id="'+cuiRowIdOpened+'"><td class="label"></td><td valign="top" class="data" colspan="3">'+data+'</td></tr>');
		});
	}
}
// Reload page with new time window set
function setTimeWindow(plottime) {
	$('#ip_progress').css('visibility','visible');
	window.location.href = app_path_webroot+page+'?plottime='+plottime+'<?php if (isset($_GET['ping'])) echo "&ping=".$_GET['ping']; ?>';
}
// Store the Google Maps API key in the config table
function saveGMAPIkey() {
	$.get(app_path_webroot+'ControlCenter/dashboard_ip_location.php', { ip_action: 'save_key', googlemap_key: $('#googlemap_key').val() }, function(data){
		if (data != '1') {
			alert(woops);
		} else {
			alert("Success!\n\nThe key was saved. The page will now reload.\n\n(For future reference, the key will be stored in the "
				+ "redcap_config database table and can be edited there in case it ever needs to be changed.)");
			window.location.reload();
		}
	});
}
$(function(){
	<?php if ($ip_table_count > 0 && $numIPsNotProcessed > 0) { ?>
	// If ip location service has already been used once AND some IPs need to be processed, 
	// then auto update the map upon pageload.
	$('#updatemap_btn').click();
	<?php } ?>
	<?php if ($cui_table_count > 0 && $num_projects_not_processed > 0) { ?>
	// If Knowledge Map web service has already been used once AND some projects need to be processed, 
	// then auto update the table upon pageload.
	$('#updatetable_btn').click();
	<?php } ?>
});
</script>


<?php include 'footer.php'; ?>