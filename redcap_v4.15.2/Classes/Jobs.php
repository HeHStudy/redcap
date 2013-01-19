<?php

/**
 * JOBS
 * This class will be instatiated by the Cron class. 
 * All functions listed in this class correspond to a specific job to be run.
 */
class Jobs
{
	/**
	 * REMOVE TEMP/DELETED FILES
	 * Removes any old files in /temp directory and removes from server any files marked for deletion
	 */
	public function RemoveTempAndDeletedFiles() 
	{
		remove_temp_deleted_files(true);
	}	
	
	/**
	 * PUBMED AUTHOR
	 * Send web service request to PubMed to get PubMed IDs for an author within a time period
	 */
	public function PubMed() 
	{
		// Determine if this functionality is enabled
		global $pub_matching_enabled, $pub_matching_emails;
		if (!$pub_matching_enabled) return;
		// Instantiate the class to interface with PubMed
		$PubMed = new PubMedRedcap();
		// Query PubMed for all project PIs in REDCap
		$PubMed->searchPubMedByAuthors();
		// Fill in article details/authors for articles that are missing such things
		$PubMed->updateArticleDetails();
		// Update MeSH terms for *all* articles
		$PubMed->updateAllMeshTerms();
		// Update the last time this publication source was crawled
		$db = new RedCapDB();
		$db->updatePubCrawlTime(RedCapDB::PUBSRC_PUBMED);
		// If enabled, email the PIs about their publications
		if ($pub_matching_emails) $PubMed->emailPIs();
		// Output details of job execution
		echo "Added $PubMed->articlesAdded new pubs; " .
			 "Added $PubMed->matchesAdded new project-pub matches; " .
			 "Added $PubMed->meshTermsAdded new MeSH terms.";
	}
	
	/**
	 * DB CLEANUP
	 * Due to some perplexing issues where things might get "out of sync" on the back-end, run some queries to fix any known issues.
	 */
	public function DbCleanup() 
	{
		// Fix survey responses marked with Form Status="incomplete" even though they are really completed responses
		$sql = "select d.* from redcap_surveys s, redcap_surveys_participants p, redcap_surveys_response r, redcap_data d, redcap_projects x
				where s.survey_id = p.survey_id and s.project_id = x.project_id and d.project_id = s.project_id 
				and p.event_id = d.event_id and x.status != 3 and x.surveys_enabled > 0 and p.participant_id = r.participant_id 
				and r.completion_time is not null and d.field_name = concat(s.form_name,'_complete') and r.record = d.record 
				and d.value = '0'";
		$q = mysql_query($sql);
		while ($row = mysql_fetch_assoc($q))
		{
			$sql = "update redcap_data set value = '2' where project_id = {$row['project_id']} and event_id = {$row['event_id']} 
					and record = '".prep($row['record'])."' and field_name = '".prep($row['field_name'])."'";
			mysql_query($sql);
		}
		mysql_free_result($q);
	}	
	
	/**
	 * EXPIRE SURVEYS
	 * For any surveys where an expiration timestamp is set, if the timestamp <= NOW, then make the survey inactive.
	 */
	public function ExpireSurveys() 
	{
		// Fix survey responses marked with Form Status="incomplete" even though they are really completed responses
		$sql = "update redcap_surveys set survey_enabled = 0 where survey_enabled = 1 
				and survey_expiration is not null and survey_expiration <= '" . date('Y-m-d H:i') . "'";
		mysql_query($sql);
		mysql_free_result($q);
	}	
	
	/**
	 * SURVEY INVITATION EMAILER
	 * For any surveys having survey invitations that have been scheduled, send any invitations that are ready to be sent.
	 */
	public function SurveyInvitationEmailer() 
	{
		SurveyScheduler::emailInvitations();
	}
	
}
