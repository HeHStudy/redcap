<?php
/*****************************************************************************************
**  REDCap is only available through a license agreement with Vanderbilt University
******************************************************************************************/

// Config for non-project pages
require_once dirname(dirname(__FILE__)) . "/Config/init_global.php";

// Give Josh Denny access to this page (and no other page)
if (isVanderbilt() && USERID == "dennyjc") { $super_user = true; }

//If user is not a super user, go back to Home page
if (!$super_user) { redirect(APP_PATH_WEBROOT); }
	
// Call web service to retrieve concept codes (parse XML returned from servcie and return as array)
function getConceptCodes($strings=array()) 
{
	// URL base for web service to be called
	$service_url = "https://redcap.vanderbilt.edu/knowledgemap.php?exact=1&sty_limit=T001|T004|T005|T006|T009|T018|T019"
				 . "|T020|T028|T046|T047|T048|T049|T050|T058|T059|T060|T063|T103|T104|T109|T1[123]\d|T184|T190|T19[567]|T200&sentence=";
	// Store concept codes in array and track occurrences
	$concepts = array();
	// Loop through all strings
	foreach ($strings as $this_project_id=>$string)
	{
		// Pre-fill sub-array with project_id regardless of response so that it does not keep sending 
		$concepts[$this_project_id] = array();
		// Make request
		$url = $service_url . rawurlencode($string);
		if (function_exists('curl_init')) 
		{
			// Use cURL
			$curlget = curl_init();
			curl_setopt($curlget, CURLOPT_SSL_VERIFYPEER, FALSE);
			curl_setopt($curlget, CURLOPT_VERBOSE, 1);
			curl_setopt($curlget, CURLOPT_URL, $url);
			curl_setopt($curlget, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($curlget, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($curlget, CURLOPT_MAXREDIRS, 10);
			curl_setopt($curlget, CURLOPT_PROXY, PROXY_HOSTNAME); // If using a proxy
			$km_xml = curl_exec($curlget);
			curl_close($curlget);
		} else {
			$km_xml = http_get($url);
			if ($km_xml === false) {
				$km_xml = implode("", $http_response_header);
			}
		}
		// If fail request, then return
		if (empty($km_xml)) 
		{
			return $concepts;
		}
		// Create XML object and parse the codes
		else {
			//print "// $url\n$km_xml\n";
			preg_match_all("/(<concepts>)(.*?)(<\/concepts>)/", $km_xml, $matches, PREG_SET_ORDER);
			// Place all CUIs in a delimited string to parse
			$cuis = "";
			foreach ($matches as $val) {
				$cuis .= strip_tags($val[0]) . "|";
			}
			// Loop through all CUIs and add to array
			foreach (explode("|", $cuis) as $this_cui)
			{
				if (trim($this_cui) == "") continue;
				// Prepend code with "C" and leading zeroes
				$this_cui = "C" . sprintf("%07d", $this_cui);
				$concepts[$this_project_id][$this_cui] = true;
			}
		}
	}
	// Now do descending sort of codes by count
	return $concepts;
}

// Count how many projects have been processed and had their CUIs fetched
function numProjectsProcessed()
{
	// Count how many projects have been processed thus far
	$sql = "select count(distinct(project_id)) from redcap_dashboard_concept_codes";
	$q = mysql_query($sql);
	return mysql_result($q, 0);

}

// Count how many projects have NOT been processed and had their CUIs fetched
function numProjectsNotProcessed()
{
	// Check if any new project titles have not been process by Knowledge Map yet
	$sql = "select count(1) from redcap_projects where purpose = 2 and project_id not in 
			(" . pre_query("select distinct p.project_id from redcap_dashboard_concept_codes c, redcap_projects p where p.project_id = c.project_id") . ")";
	$q = mysql_query($sql);
	return mysql_result($q, 0);
}









// Perform any actions before displaying the CUI table
if (isset($_GET['action']))
{
	switch ($_GET['action'])
	{
		// Get list of projects for given CUI
		case 'getProjList':
			if (isset($_GET['cui'])) {
				$sql = "select distinct trim(p.app_title) as title, p.project_id, p.status
						from redcap_dashboard_concept_codes c, redcap_projects p 
						where p.project_id = c.project_id and c.cui = '".prep($_GET['cui'])."' order by p.status, trim(p.app_title)";
				$q = mysql_query($sql);
				$this_status = "";
				while ($row = mysql_fetch_assoc($q))
				{
					// Statify by status
					if ($this_status != $row['status'])
					{
						print "<b>";
						switch ($row['status']) {
							case '0':
								print "Development:";
								break;
							case '1':
								print "Production:";
								break;
							case '2':
								print "Inactive:";
								break;
							case '3':
								print "Archived:";
								break;
						}
						print "</b><br>";
					}
					// Display project title
					print " &nbsp; &bull; <a target='_blank' href='".APP_PATH_WEBROOT."index.php?pid={$row['project_id']}'>".strip_tags(label_decode($row['title']))."</a><br>";
					// Set for next loop
					$this_status = $row['status'];
				}
			} else {
				print "<b>ERROR!</b>";
			}
			break;
		// Obtain CUIs for a single batch of projects
		case 'getNextBatch':
			// Set number of projects per batch
			$projects_per_batch = 10;
			// Get project titles for this batch
			$proj_titles = array();
			$sql = "select project_id, app_title from redcap_projects where purpose = 2 and project_id not in 
					(" . pre_query("select distinct p.project_id from redcap_dashboard_concept_codes c, redcap_projects p where p.project_id = c.project_id") . ")
					order by project_id limit $projects_per_batch";
			$q = mysql_query($sql);
			$proj_count = mysql_num_rows($q);
			while ($row = mysql_fetch_assoc($q))
			{
				// Santize title and place into array
				$proj_titles[$row['project_id']] = strtolower(filter_tags(str_replace(array("<br>", "<br/>", "<br />"), array(" ", " ", " "), label_decode($row['app_title']))));
			}
			// Get concept codes (CUIs) by sending project titles to web service
			foreach (getConceptCodes($proj_titles) as $this_project_id=>$cuis)
			{
				// Now add new CUIs to table for each project
				$sql = "insert into redcap_dashboard_concept_codes (project_id, cui) values ($this_project_id, '"
					 . implode("'), ($this_project_id, '", array_keys($cuis)) . "');";
				mysql_query($sql);
			}
			// Return how many projects are left to be processed
			exit( numProjectsNotProcessed() );
			break;
	}
}


// Display the CUI table (no actions done here)
else
{
	// First, check if table redcap_umls_conditions already exists. If not, prompt to download it (it is large).
	$sql = "show tables like 'redcap_umls_conditions'";
	$q = mysql_query($sql);
	if (mysql_num_rows($q) < 1)
	{
		// Need to create the database table first
		?>
		<div class="blue" style="padding:10px;font-size:14px;">
			<table cellspacing=0 width=100%>
				<tr>
					<td valign="top">
						<b>UMLS Concepts represented in<br>your REDCap projects</b><br>
						<span style="font-size:11px;">(representing 0 projects - "research" purpose only)</span>
					</td>
					<td valign="top" style="width:190px;text-align:right;color:#000;font-family:tahoma;" width=190>								
						Powered by:<br>
						<img src="<?php echo APP_PATH_IMAGES ?>vu_knowledgemap_logo.gif" style="border:1px solid #ccc;">
					</td>
				</tr>
			</table>
		</div>
		<div style="padding:5px;">
			NOTICE: Before using this service, the database table named 'redcap_umls_conditions' must first be created. You will need to 
			<a target="_blank" style="text-decoration:underline;" href="https://redcap.vanderbilt.edu/consortium/resources/docs/redcap_umls_conditions.txt">download this file</a> (or view the file in your browser and copy its contents), 
			and then execute the SQL contained within the file on your MySQL database where
			your REDCap tables are stored. Once done, reload this page.
		</div>
		<?php
	}
	else
	{
		// Query table to find the preferred name of each code (ignore CUI C0808270 "Time 2" because it throws false positives)
		$conceptTable = array();
		$sql = "select * from (select u.cui, u.preferred_name, count(u.cui) as cui_count 
				from redcap_dashboard_concept_codes c, redcap_umls_conditions u where u.cui = c.cui and u.cui != 'C0808270' group by u.cui) as x 
				order by cui_count desc, preferred_name";
		$q = mysql_query($sql);
		while ($row = mysql_fetch_assoc($q))
		{
			// Add the preferred name, cui, and cui count to the array
			$conceptTable[$row['cui']] = array('pn' => $row['preferred_name'], 'cui_count' => $row['cui_count']);
		}

		// Check if any new project titles have not been process by Knowledge Map yet
		$num_projects_not_processed = numProjectsNotProcessed();

		// Count how many projects have been processed thus far
		$num_projects_processed = numProjectsProcessed();

		// Display CUI table
		?>
		<table cellspacing=0 class="form_border" width=100%>
			<tr>
				<td valign="top" class="context_msg" colspan="4">
					<div class="blue" style="padding:10px;font-size:14px;">
						<table cellspacing=0 width=100%>
							<tr>
								<td valign="top">
									<b>UMLS Concepts represented in<br>your REDCap projects</b><br>
									<span style="font-size:11px;">(representing <?php echo $num_projects_processed ?> projects - "research" purpose only)</span>
								</td>
								<td valign="top" style="width:190px;text-align:right;color:#000;font-family:tahoma;" width=190>								
									Powered by:<br>
									<img src="<?php echo APP_PATH_IMAGES ?>vu_knowledgemap_logo.gif" style="border:1px solid #ccc;">
								</td>
							</tr>
						</table>
					</div>
					<?php
					// Show note about any unprocessed projects or that it's still processing
					if ($num_projects_not_processed > 0) 
					{
						?>
						<div class="yellow" style="font-family:arial;">
							<img src="<?php echo APP_PATH_IMAGES ?>exclamation_orange.png" class="imgfix">
							<?php echo $num_projects_not_processed ?> projects have not been processed yet.
							<button id="updatetable_btn" onclick="this.disabled=true;updateCuiTable();" style="margin:0 10px;" <?php if ($isAjax) echo "disabled"; ?>>Update table</button>
							<img id="process_icon" src="<?php echo APP_PATH_IMAGES ?>progress_circle.gif" class="imgfix" <?php if (!$isAjax) echo 'style="visibility:hidden;"'; ?>>
						</div>
						<?php 
					}
					?>
				</td>
			</tr>
			<tr>
				<td valign="top" class="header">
					CUI
				</td>
				<td valign="top" class="header">
					Preferred Name
				</td>
				<td valign="top" class="header" style="text-align:center;">
					Occurrences
				</td>
				<td valign="top" class="header">
				</td>
			</tr>
			<?php 
			foreach ($conceptTable as $cui=>$arr) { 
				if ($arr['pn'] == '') continue;
				?>
				<tr id="cui-row-<?php echo $cui ?>">
					<td valign="top" class="label">
						<?php echo $cui ?>
					</td>
					<td valign="top" class="data">
						<?php echo $arr['pn'] ?>
					</td>
					<td valign="top" class="data" style="text-align:center;">
						<?php echo $arr['cui_count'] ?>
					</td>
					<td valign="top" class="data" style="text-align:center;width:70px;">
						<a href="javascript:;" style="text-decoration:underline;font-size:10px;margin-left:10px;font-family:tahoma;color:" onclick="getProjectsFromCui('<?php echo $cui ?>');">view projects</a>
					</td>
				</tr>
				<?php 
			}
			if (count($conceptTable) == 0) {
				?>
				<tr>
					<td valign="top" class="data" colspan="4">
						No CUIs have been found yet.
					</td>
				</tr>
				<?php 
			}
			?>
		</table>
		<?php
	}
}





