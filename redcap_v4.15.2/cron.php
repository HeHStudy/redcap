<?php
/*****************************************************************************************
**  REDCap is only available through a license agreement with Vanderbilt University
******************************************************************************************/

// Disable authentication
define("NOAUTH", true);

// Set flag to designate this as the cron job
define("CRON", true);

// Config for non-project pages
require_once dirname(__FILE__) . "/Config/init_global.php";

// Include the Jobs class, which has all jobs defined to be run in the cron
require_once APP_PATH_CLASSES . 'Jobs.php';

// Include the Cron class, which has the logic for running the cron
require_once APP_PATH_CLASSES . "Cron.php";

// Instantiate the class
$cron = new Cron();

// Execute the jobs
$cron->execute();
