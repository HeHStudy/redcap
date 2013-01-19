<?php

/**
 * SurveyScheduler
 * This class is used for setup and execution of the survey scheduler.
 */
class SurveyScheduler
{
	// Array of schedules from the surveys_scheduler table
	public $schedules = null;
	// Array with PK from surveys_scheduler table (ss_id) as array key and survey_id=>event_id as array subkeys.
	// Can be used to link directly to $schedules array using ss_id instead of survey_id-event_id.
	private $schedulePkLink = null;
	// Array of survey invitations already queued to be sent for this project
	private $surveyInvitationQueueList = null;
	// Array of survey invitations already sent for this project
	private $surveyInvitationSentList = null;
	// Set default limit for number of emails to send in one batch per cron job instance.
	// This will be used if cannot be determined from values in redcap_surveys_emails_send_rate table.
	// (ideal batch = 5 minutes long to send, so default to ~3 emails/sec)
	const MAX_EMAILS_PER_BATCH = 1000;
	// Set minimum emails per batch
	const MIN_EMAILS_PER_BATCH = 100;
	// Set the ideal length of time for a full email batch to send
	const BATCH_LENGTH_MINUTES = 5;
	// Set the minimum number of emails sent in a batch that would constitute its email rate getting added to 
	// the redcap_surveys_emails_send_rate table to thus be used in future calculations for determining email batch size.
	const MIN_RECORD_EMAILS_SENT = 20;
	
	
	
	// Determine the number of emails to send per batch (optimally 5-min worth) based upon values
	// of previously sent emails in redcap_surveys_emails_send_rate table.
	private static function determineEmailsPerBatch()
	{
		// Get average emails_per_minute from last 20 batches
		$sql = "select round(avg(emails_per_minute)*" . self::BATCH_LENGTH_MINUTES . ") 
				from redcap_surveys_emails_send_rate order by esr_id desc limit 20";
		$q = mysql_query($sql);
		if ($q && mysql_num_rows($q) > 0) {
			// Return average send time for last 20 batches
			$emails_per_minute = mysql_result($q, 0);
			// If calculated value is less than minimum, then use minimum instead
			return ($emails_per_minute < self::MIN_EMAILS_PER_BATCH ? self::MIN_EMAILS_PER_BATCH : $emails_per_minute);
		} else {
			// If could not determine from table, then use hard-coded default
			return self::MAX_EMAILS_PER_BATCH;
		}
	}
	
	
	// Output HTML table for setting up the conditional survey invitation schedule for a given survey/event
	public function renderConditionalInviteSetupTable($survey_id, $event_id)
	{
		// Set variables needed
		global $Proj, $longitudinal, $lang, $user_firstname, $user_lastname, $user_email;
		// Includes
		require_once APP_PATH_DOCROOT . "ProjectGeneral/form_renderer_functions.php";
		// Fill up $schedules array with schedules
		$this->setSchedules();
		// Add days of the week + work day + weekend day as drop-down list options
		$daysOfWeekDD = array(''=>'-- select day --', 'DAY'=>'Day', 'WEEKDAY'=>'Weekday', 'WEEKENDDAY'=>'Weekend Day', 'SUNDAY'=>'Sunday', 
							  'MONDAY'=>'Monday', 'TUESDAY'=>'Tuesday', 'WEDNESDAY'=>'Wednesday', 'THURSDAY'=>'Thursday', 'FRIDAY'=>'Friday',
							  'SATURDAY'=>'Saturday');
							  
		// Create list of all surveys/event instances as array to use for looping below and also to feed a drop-down
		$surveyEvents = array();
		$surveyDD = array();
		// Loop through all events (even for classic)
		foreach ($Proj->eventsForms as $this_event_id=>$forms)
		{
			// Go through each form and see if it's a survey
			foreach ($forms as $form)
			{
				// Get survey_id
				$this_survey_id = isset($Proj->forms[$form]['survey_id']) ? $Proj->forms[$form]['survey_id'] : null;
				// Only display surveys, so ignore if does not have survey_id
				if (!is_numeric($this_survey_id)) continue;
				// Add form, event_id, and survey_id to drop-down array
				$title = $Proj->surveys[$this_survey_id]['title'];
				$event = $Proj->eventInfo[$this_event_id]['name_ext'];
				if ($survey_id != $this_survey_id) {
					$surveyDD["$this_survey_id-$this_event_id"] = ($longitudinal ? "[$event] " : "") . $title;
				}
				// Add values to array
				$surveyEvents[] = array('event_id'=>$this_event_id, 'event_name'=>$event, 'form'=>$form, 
										'survey_id'=>$this_survey_id, 'survey_title'=>$title);
			}
		}
		
		
		// Check if survey_id/event_id have a saved schedule
		$savedSchedule = isset($this->schedules[$survey_id][$event_id]) ? $this->schedules[$survey_id][$event_id] : false;
		// Set row attributes
		$emailSubject = label_decode($savedSchedule['email_subject']);
		$emailContent = label_decode($savedSchedule['email_content']);
		$emailSender = label_decode($savedSchedule['email_sender']);
		$conditionSurveyId = $savedSchedule['condition_surveycomplete_survey_id'];
		$conditionEventId = $savedSchedule['condition_surveycomplete_event_id'];
		$conditionSurveyCompSelected = (is_numeric($conditionSurveyId) && is_numeric($conditionEventId)) ? "$conditionSurveyId-$conditionEventId" : '';
		$conditionSurveyCompChecked = (is_numeric($conditionSurveyId) && is_numeric($conditionEventId)) ? 'checked' : '';
		$conditionAndOr = (isset($savedSchedule['condition_andor']) && $savedSchedule['condition_andor'] != '') ? label_decode($savedSchedule['condition_andor']) : 'AND';
		$conditionLogic = (isset($savedSchedule['condition_logic']) && $savedSchedule['condition_logic'] != '') ? label_decode($savedSchedule['condition_logic']) : '';
		$conditionLogicChecked = (isset($savedSchedule['condition_logic']) && $savedSchedule['condition_logic'] != '') ? 'checked' : '';
		$conditionSendTimeSelectedImmediately = (isset($savedSchedule['condition_send_time_option']) && $savedSchedule['condition_send_time_option'] == 'IMMEDIATELY') ? 'checked' : '';
		$conditionSendTimeSelectedTimeLag = (isset($savedSchedule['condition_send_time_option']) && $savedSchedule['condition_send_time_option'] == 'TIME_LAG') ? 'checked' : '';
		$conditionSendTimeSelectedNextOccur = (isset($savedSchedule['condition_send_time_option']) && $savedSchedule['condition_send_time_option'] == 'NEXT_OCCURRENCE') ? 'checked' : '';
		$conditionSendTimeSelectedExactTime = (isset($savedSchedule['condition_send_time_option']) && $savedSchedule['condition_send_time_option'] == 'EXACT_TIME') ? 'checked' : '';
		$conditionSendTimeLagDays = '';
		$conditionSendTimeLagHours = '';
		$conditionSendTimeLagMinutes = '';			
		$conditionSendNextDayType = '';
		$conditionSendNextTime = '';
		$conditionExactTimeValue = '';
		if (isset($savedSchedule['condition_send_time_option']) && $savedSchedule['condition_send_time_option'] == 'TIME_LAG') {
			$conditionSendTimeLagDays = (isset($savedSchedule['condition_send_time_lag_days']) && $savedSchedule['condition_send_time_lag_days'] != '') ? $savedSchedule['condition_send_time_lag_days'] : 0;
			$conditionSendTimeLagHours = (isset($savedSchedule['condition_send_time_lag_hours']) && $savedSchedule['condition_send_time_lag_hours'] != '') ? $savedSchedule['condition_send_time_lag_hours'] : 0;
			$conditionSendTimeLagMinutes = (isset($savedSchedule['condition_send_time_lag_minutes']) && $savedSchedule['condition_send_time_lag_minutes'] != '') ? $savedSchedule['condition_send_time_lag_minutes'] : 0;
		} elseif (isset($savedSchedule['condition_send_time_option']) && $savedSchedule['condition_send_time_option'] == 'NEXT_OCCURRENCE') {			
			$conditionSendNextDayType = (isset($savedSchedule['condition_send_next_day_type']) && $savedSchedule['condition_send_next_day_type'] != '') ? $savedSchedule['condition_send_next_day_type'] : '';
			$conditionSendNextTime = (isset($savedSchedule['condition_send_next_time']) && $savedSchedule['condition_send_next_time'] != '') ? substr($savedSchedule['condition_send_next_time'], 0, 5) : '';
		} elseif (isset($savedSchedule['condition_send_time_option']) && $savedSchedule['condition_send_time_option'] == 'EXACT_TIME' && $savedSchedule['condition_send_time_exact'] != '') {
			list ($this_date, $this_time) = explode(" ", $savedSchedule['condition_send_time_exact']);
			$conditionExactTimeValue = trim(date_ymd2mdy($this_date) . " " . substr($this_time, 0, 5));
		}
		if (isset($savedSchedule['active'])) {
			$scheduleActiveSelected = ($savedSchedule['active'] == '1') ? 'checked' : '';
			$scheduleInactiveSelected = ($savedSchedule['active'] == '0') ? 'checked' : '';
			$scheduleActiveClass = ($savedSchedule['active'] == '1') ? 'darkgreen' : 'red';
		} else {
			$scheduleActiveSelected = $scheduleInactiveSelected = '';
			$scheduleActiveClass = 'gray';
		}
		
		
		// Create HTML content
		$html = RCView::table(array('cellspacing'=>'0','border'=>'0','style'=>'table-layout:fixed;'),
					RCView::tr('',
						RCView::td(array('valign'=>'top','style'=>'width:380px;padding:6px 10px 0 0;'),
							## INFO
							RCView::fieldset(array('style'=>'padding-left:8px;background-color:#FFFFD3;border:1px solid #FFC869;margin-bottom:10px;'),
								RCView::legend(array('style'=>'font-weight:bold;color:#333;'),
									RCView::img(array('src'=>'txt.gif','class'=>'imgfix')) . 
									$lang['survey_340']
								) .
								RCView::div(array('style'=>'padding:3px 8px 8px 2px;'),
									// Survey title
									RCView::div(array('style'=>'color:#800000;'),
										RCView::b($lang['survey_310']) . 
										RCView::span(array('style'=>'font-size:13px;margin-left:8px;'), 
											RCView::escape($Proj->surveys[$_GET['survey_id']]['title'])
										)				
									) .
									// Event name (if longitudinal)
									RCView::div(array('style'=>'color:#000066;padding-top:3px;' . ($longitudinal ? '' : 'display:none;')),
										RCView::b($lang['bottom_23']) . 
										RCView::span(array('style'=>'font-size:13px;margin-left:8px;'), 
											RCView::escape($Proj->eventInfo[$_GET['event_id']]['name_ext'])
										) 
									)
								)
							) . 
							## COMPOSE EMAIL SUBJECT AND MESSAGE
							RCView::fieldset(array('style'=>'padding-left:8px;background-color:#FFFFD3;border:1px solid #FFC869;'),
								RCView::legend(array('style'=>'font-weight:bold;color:#333;'),
									RCView::img(array('src'=>'email.png','class'=>'imgfix')) . 
									$lang['survey_339']
								) .
								RCView::div(array('style'=>'padding:10px 0 10px 2px;'),							
									RCView::table(array('cellspacing'=>'0','border'=>'0','width'=>'100%'),
										// From 
										RCView::tr('',
											RCView::td(array('style'=>'vertical-align:top;width:50px;padding-top:2px;'),
												$lang['global_37']
											) .
											RCView::td(array('style'=>'vertical-align:top;color:#555;'),
												User::emailDropDownListAllUsers($emailSender, true, 'email_sender', 'email_sender') .
												RCView::div(array('style'=>'padding:2px 0 0 2px;font-size:11px;color:#777;'), 
													"(select any project user to be the 'Sender')"
												)
											)
										) .
										// To
										RCView::tr('',
											RCView::td(array('style'=>'vertical-align:middle;width:50px;padding-top:10px;'),
												$lang['global_38']
											) .
											RCView::td(array('style'=>'vertical-align:middle;padding-top:10px;color:#666;font-weight:bold;'),
												$lang['survey_338']
											)
										) .
										// Subject
										RCView::tr('',
											RCView::td(array('style'=>'vertical-align:middle;padding:10px 0;width:50px;'),
												$lang['survey_103']
											) .
											RCView::td(array('style'=>'vertical-align:middle;padding:10px 0;'),
												'<input class="x-form-text x-form-field" style="font-family:arial;width:280px;" type="text" id="sssubj-'."$survey_id-$event_id".'" onkeydown="if(event.keyCode == 13){return false;}" value="'.cleanHtml2(str_replace('"', '&quot;', label_decode($emailSubject))).'"/>'
											)
										) .
										// Message
										RCView::tr('',
											RCView::td(array('colspan'=>'2','style'=>'padding:5px 0 10px;'),
												'<textarea class="x-form-field notesbox" id="ssemail-'."$survey_id-$event_id".'" style="font-family:arial;height:120px;width:95%;">'.nl2br(label_decode($emailContent)).'</textarea>'
											)
										)
									)
								) . 
								// Extra instructions
								RCView::div(array('style'=>'padding:0 5px;'),
									RCView::div(array('style'=>'font-size:11px;color:#800000;padding-bottom:6px;'),
										RCView::b($lang['survey_105']) . RCView::SP . $lang['survey_104']
									) .
									RCView::div(array('style'=>'font-size:11px;color:#555;padding-bottom:6px;'),
										$lang['survey_164'] .
										'&lt;b&gt; bold, &lt;u&gt; underline, &lt;i&gt; italics, &lt;a href="..."&gt; link, etc.'
									)
								)
							)
						) .
						## SCHEDULER CONDITIONAL SETTINGS
						RCView::td(array('valign'=>'top','style'=>'padding:6px 0 0 10px;width:480px;'),
							RCView::fieldset(array('style'=>'padding-left:8px;background-color:#FFFFD3;border:1px solid #FFC869;margin-bottom: 10px;'),
								RCView::legend(array('style'=>'font-weight:bold;color:#333;'),
									RCView::img(array('src'=>'gear.png','class'=>'imgfix')) . 
									$lang['survey_341']
								) .
								RCView::div(array('style'=>'padding:10px 0 10px 2px;'), 
									// Select a condition
									RCView::div(array('style'=>'font-weight:bold;margin-bottom:2px;font-size:13px;color:#800000;'), 
										"Specify conditions for sending invitations:" .
										// "Tell me more" pop-up
										RCView::a(array('href'=>'javascript:;','style'=>'margin-left:50px;text-decoration:underline;font-weight:normal;font-size:11px;','onclick'=>"simpleDialog(null,null,'surveyCompleteTriggerExplainDialog');"), $lang['global_58'])
									) . 
									// When survey is completed
									RCView::div(array('style'=>'text-indent:-1.9em;margin-left:1.9em;padding:1px 0;'), 
										RCView::checkbox(array('id'=>"sscondoption-surveycomplete-$survey_id-$event_id",'class'=>'imgfix2',$conditionSurveyCompChecked=>$conditionSurveyCompChecked)) . 
										"When survey is completed:" . 
										RCView::br() . 
										// Drop-down of surveys/events
										RCView::select(array('id'=>"sscondoption-surveycompleteids-$survey_id-$event_id",'style'=>'font-size:11px;width:360px;max-width:360px;'), $surveyDD, $conditionSurveyCompSelected)
									) .   
									// AND/OR drop-down list for conditions
									RCView::div(array('style'=>'padding:2px 0 1px;'), 
										RCView::select(array('id'=>"sscondoption-andor-$survey_id-$event_id",'style'=>'font-size:11px;'), array('AND'=>$lang['global_87'],'OR'=>$lang['global_46']), $conditionAndOr)
									) .  
									// When logic becomes true
									RCView::div(array('style'=>'text-indent:-1.9em;margin-left:1.9em;'), 
										RCView::checkbox(array('id'=>"sscondoption-logic-$survey_id-$event_id",'class'=>'imgfix2',$conditionLogicChecked=>$conditionLogicChecked)) . 
										"When the following logic becomes true:" . RCView::br() . 
										RCView::textarea(array('id'=>"sscondlogic-$survey_id-$event_id",'class'=>'x-form-field', 'style'=>'line-height:12px;font-size:11px;width:350px;height:24px;'),
											$conditionLogic
										)
									) .  
									## When to send once condition is met
									RCView::div(array('id'=>"sscondtimes-$survey_id-$event_id",'style'=>'margin-top:25px;'), 
										RCView::div(array('style'=>'font-weight:bold;margin-bottom:2px;font-size:13px;color:#800000;'), 
											"When to send invitations after conditions are met:"
										) . 
										// Immediately
										RCView::div(array('style'=>'padding:1px 0;'), 
											RCView::radio(array('name'=>"sscondwhen-$survey_id-$event_id",'value'=>'IMMEDIATELY',$conditionSendTimeSelectedImmediately=>$conditionSendTimeSelectedImmediately)) . 
											"Send immediately"
										) .
										// Next occurrence of (e.g. Work day at 11:00am)
										RCView::div(array('style'=>'padding:1px 0;'), 
											RCView::radio(array('name'=>"sscondwhen-$survey_id-$event_id",'value'=>'NEXT_OCCURRENCE',$conditionSendTimeSelectedNextOccur=>$conditionSendTimeSelectedNextOccur)) . 
											"Send on next" . RCView::SP . RCView::SP .
											RCView::select(array('id'=>"sscond-nextdaytype-$survey_id-$event_id",'style'=>'font-size:11px;'), $daysOfWeekDD, $conditionSendNextDayType) . RCView::SP .
											"at time" . RCView::SP . RCView::SP .  
											RCView::input(array('id'=>"sscond-nexttime-$survey_id-$event_id",'type'=>'text', 'class'=>'x-form-text x-form-field time2', 'value'=>$conditionSendNextTime,
												'style'=>'height:14px;line-height:14px;text-align:right;font-size:11px;width:30px;',
												'onfocus'=>"if( $('.ui-datepicker:first').css('display')=='none'){ $(this).next('img').trigger('click');}")) . 
											RCView::span(array('class'=>'df'), 'H:M')
										).
										// Time lag of X amount of days/hours/minutes
										RCView::div(array('style'=>'padding:1px 0;'), 
											RCView::radio(array('name'=>"sscondwhen-$survey_id-$event_id",'value'=>'TIME_LAG',$conditionSendTimeSelectedTimeLag=>$conditionSendTimeSelectedTimeLag)) . 
											"Send after lapse of time:" . RCView::SP . RCView::SP . 
											RCView::span(array('style'=>'font-size:11px;'), 
												RCView::input(array('id'=>"sscond-timelagdays-$survey_id-$event_id",'type'=>'text', 'class'=>'x-form-text x-form-field', 'style'=>'height:14px;line-height:14px;text-align:center;font-size:11px;width:17px;', 'value'=>$conditionSendTimeLagDays, 'maxlength'=>'3')) . 
												"days" . RCView::SP . RCView::SP .  
												RCView::input(array('id'=>"sscond-timelaghours-$survey_id-$event_id",'type'=>'text', 'class'=>'x-form-text x-form-field', 'style'=>'height:14px;line-height:14px;text-align:center;font-size:11px;width:12px;', 'value'=>$conditionSendTimeLagHours, 'maxlength'=>'2')) . 
												"hours" . RCView::SP . RCView::SP .  
												RCView::input(array('id'=>"sscond-timelagminutes-$survey_id-$event_id",'type'=>'text', 'class'=>'x-form-text x-form-field', 'style'=>'height:14px;line-height:14px;text-align:center;font-size:11px;width:12px;', 'value'=>$conditionSendTimeLagMinutes, 'maxlength'=>'2')) . 
												"minutes"
											)
										) .
										// Exact time
										RCView::div(array('style'=>'padding:1px 0;'), 
											RCView::radio(array('name'=>"sscondwhen-$survey_id-$event_id",'value'=>'EXACT_TIME', $conditionSendTimeSelectedExactTime=>$conditionSendTimeSelectedExactTime)) . 
											"Send at exact date/time:" . RCView::SP . RCView::SP . 
											RCView::input(array('id'=>"ssdt-$survey_id-$event_id", 'type'=>'text', 'class'=>'x-form-text x-form-field datetime_mdy', 
												'value'=>$conditionExactTimeValue, 'style'=>'width:92px;height:14px;line-height:14px;font-size:11px;padding-bottom:1px;', 
												'onkeydown'=>"if(event.keyCode==13){return false;}", 
												'onfocus'=>"this.value=trim(this.value); if(this.value.length == 0 && $('.ui-datepicker:first').css('display')=='none'){ $(this).next('img').trigger('click');}" ,
												'onblur'=>"redcap_validate(this,'','','soft_typed','datetime_mdy',1,0)")) . 
											RCView::span(array('class'=>'df'), 'M-D-Y H:M')
										)
									)
								)
							) .
							// Is schedule activated?
							RCView::fieldset(array('id'=>'condSurvPopupActiveBox','class'=>$scheduleActiveClass,'style'=>'padding:0 0 0 8px;'),
								RCView::legend(array('style'=>'font-weight:bold;color:#333;'),
									RCView::img(array('src'=>'email_check.png','class'=>'imgfix')) . 
									"Activated?"
								) .
								RCView::div(array('style'=>'padding:6px 4px 10px 2px;'),
									RCView::div(array('style'=>'padding-bottom:6px;font-size:11px;'),
										"Activate these automated invitations? In order for automated survey invitations to be sent using these specified
										conditions, it must be set to Active. You may make them Not Active (and vice versa) at any point in the future."
									) .
									RCView::div(array('style'=>''),
										RCView::radio(array('name'=>"ssactive-$survey_id-$event_id",'onclick'=>"$('#condSurvPopupActiveBox').removeClass('gray').removeClass('red').addClass('darkgreen');",'value'=>'1',$scheduleActiveSelected=>$scheduleActiveSelected)) . 
										"Active" . RCView::SP . RCView::SP .
										RCView::radio(array('name'=>"ssactive-$survey_id-$event_id",'onclick'=>"$('#condSurvPopupActiveBox').removeClass('gray').removeClass('darkgreen').addClass('red');",'value'=>'0',$scheduleInactiveSelected=>$scheduleInactiveSelected)) . 
										"Not Active"
									)
								)
							)
						)
					)
				);
		
		// Return the HTML 
		return $html;
	}
	
	
	// Fill up array with the survey schedules for this project
	private function setSchedules() 
	{
		// Set $schedules as array
		if ($this->schedules == null)
		{
			// Set these as arrays
			$this->schedules = array();
			$this->schedulePkLink = array();
			// Query to get schedules for project and put in array
			$sql = "select r.* from redcap_surveys_scheduler r, redcap_surveys s 
					where s.survey_id = r.survey_id and s.project_id = " . PROJECT_ID;
			$q = mysql_query($sql);
			while ($row = mysql_fetch_assoc($q))
			{
				// Use survey_id and event_id for array keys
				$survey_id = $row['survey_id'];
				$event_id = $row['event_id'];
				$ss_id = $row['ss_id'];
				// Remove unnecessary items
				unset($row['survey_id'], $row['event_id'], $row['ss_id']);
				// Add to arrays
				$this->schedules[$survey_id][$event_id] = $row;
				$this->schedulePkLink[$ss_id][$survey_id] = $event_id;
			}
		}
	}
	
	
	// Display a report of the error check for the survey scheduler
	public function renderProjectScheduleErrorTable()
	{
		// Fill up $schedules array with schedules
		$this->setSchedules();
		print_array($this->schedules);
		
		// CHECK SCHEDULE ATTRIBUTES: Make sure all attributes are accounted for (nothing missing)
		$errors = $this->checkScheduleAttr();		
		
		// WORKFLOW LOGIC CHECK: Find starting-point surveys (exact time for survey invite)
		$startingPoints = array();
		foreach ($this->schedules as $survey_id=>$events) {
			foreach ($events as $event_id=>$attr) {			
				// Where to start w/o exact time?
				$startingPoints[$survey_id][$event_id] = $attr;
			}
		}
		print_array($startingPoints);
	
	}
	
	
	### NOT COMPLETE
	// Check attributes of survey schedule to make sure nothing is missing
	private function checkScheduleAttr()
	{
		// Initialize vars
		$errors = array();
		// Fill up $schedules array with schedules
		$this->setSchedules();
		// Loop through schedules and check attributes of each
		foreach ($this->schedules as $survey_id=>$events) 
		{
			foreach ($events as $event_id=>$attr) 
			{
				// Check email attrs
				if ($attr['email_subject'] == '') $errors[] = "Email invitation has no subject.";
				if ($attr['email_content'] == '') $errors[] = "Email invitation has no content.";
				// Make sure we have a trigger (logic and/or survey completion)
				if (!($attr['condition_logic'] != '' 
					|| (is_numeric($attr['condition_surveycomplete_survey_id']) && is_numeric($attr['condition_surveycomplete_event_id'])))) 
				{
					$errors[] = "A condition has not been specified for when to send email invitations.";
				}
				// Check temporal settings
				else {
					// Check is has temporal component set
					if ($attr['condition_send_time_option'] == '') {
						$errors[] = "The time component denoting when to send email invitations has not been set.";
					}
					// Check if have values for NextOccurrence 
					elseif ($attr['condition_send_time_option'] == 'NEXT_OCCURRENCE') {
						if ($attr['condition_send_next_day_type'] == '') $errors[] = "The day component is missing for when to send email invitations after conditions are met.";
						if ($attr['condition_send_next_time'] == '') $errors[] = "The time value is missing for when to send email invitations after conditions are met.";
					} 
					// Check if have values for TimeLag
					elseif ($attr['condition_send_time_option'] == 'TIME_LAG') {
						if ($attr['email_subject'] == '') $errors[] = "Email invitation has no subject.";
						if ($attr['email_content'] == '') $errors[] = "Email invitation has no content.";
					}	
					// Check if has exact_time date/time
					elseif ($attr['condition_send_time_option'] == 'EXACT_TIME') {
						if ($attr['email_subject'] == '') $errors[] = "Email invitation has no subject.";
						if ($attr['email_content'] == '') $errors[] = "Email invitation has no content.";
					}					
				}
			}
		}
		
		
		
		
		
		
		// Return 
		return $errors;
	}
	
	

			
	
	// Return array of drop-down options of ALL surveys and, if longitudinal, the events for which they're designated
	public function getInvitationLogSurveyList($includeAllEventsOptionsForLongitudinal=true)
	{
		global $lang, $Proj, $longitudinal;
		$surveyEventOptions = array();
		// If longitudinal, then first display list of all surveys for ALL events (set 0 for event in drop-down value)
		if ($includeAllEventsOptionsForLongitudinal && $longitudinal) {
			foreach ($Proj->surveys as $this_survey_id=>$survey_attr) {
				// Add this survey/event as drop-down option
				$surveyEventOptions["$this_survey_id-0"] = "\"{$survey_attr['title']}\" (all events)";
			}
		}
		// Loop through each arm
		foreach ($Proj->events as $this_arm=>$arm_attr) 
		{
			// Loop through each instrument
			foreach ($Proj->forms as $form_name=>$form_attr) 
			{
				// Ignore if not a survey
				if (!isset($form_attr['survey_id'])) continue;
				// Get survey_id
				$this_survey_id = $form_attr['survey_id'];
				// Loop through each event and output each where this form is designated
				foreach ($Proj->eventsForms as $this_event_id=>$these_forms) 
				{
					// If event does not belong to the current arm OR the form has not been designated for this event, then go to next loop
					if (!($arm_attr['events'][$this_event_id] && in_array($form_name, $these_forms))) continue; 
					// If longitudinal, add event name
					$event_name = ($longitudinal) ? " - ".$Proj->eventInfo[$this_event_id]['name_ext'] : "";
					// Truncate survey title if too long
					$survey_title = $Proj->surveys[$this_survey_id]['title'];
					if (strlen($survey_title.$event_name) > 70) {
						$survey_title = substr($survey_title, 0, 67-strlen($event_name)) . "...";
					}
					// Add this survey/event as drop-down option
					$surveyEventOptions["$this_survey_id-$this_event_id"] = "\"$survey_title\"$event_name";
				}
			}
		}
		// Return the array of surveys
		return $surveyEventOptions;
	}			
	
	// Display a table listing all survey invitations (past, present, and future) with filters and paging
	public function renderSurveyInvitationLog()
	{
		// Initialize vars
		global $Proj, $longitudinal, $table_pk_label, $lang;
		
		// Get list of participant_ids/records (if record exists) - will use later to insert record name into log table
		$participantRecordsComplete = $participantRecords = array();
		$sql = "select r.participant_id, r.record, if (r.first_submit_time is null, 0, if (r.completion_time is null, 1, 2)) as completed 
				from redcap_surveys s, redcap_surveys_emails e, redcap_surveys_emails_recipients er, 
				redcap_surveys_response r where s.project_id = ".PROJECT_ID." and s.survey_id = e.survey_id and e.email_id = er.email_id 
				and r.participant_id = er.participant_id";
		$q = mysql_query($sql);
		// Loop through all rows
		while ($row = mysql_fetch_assoc($q))
		{
			$participantRecordsComplete[$row['participant_id']] = $row['completed'];
			$participantRecords[$row['participant_id']] = label_decode($row['record']);
		}
		
		// Get invitation log info for table
		$rows = $invitationLog = $recordsNoEmail = array();
		$sql = "select if (e.email_sent is not null, e.email_sent, if (q.time_sent is not null, q.time_sent, q.scheduled_time_to_send)) as send_time, 
				if (e.email_sent is not null or q.status = 'SENT', 1, if (q.reason_not_sent is null, 0, -1)) as was_sent, 
				p.participant_id, p.survey_id, p.event_id, p.hash, er.email_recip_id, p.participant_email, p.participant_identifier, er.static_email, 
				q.status as scheduled_status, q.reason_not_sent 
				from redcap_surveys s, redcap_surveys_emails e, redcap_surveys_participants p, redcap_surveys_emails_recipients er 
				left outer join redcap_surveys_scheduler_queue q on q.email_recip_id = er.email_recip_id 
				where s.project_id = ".PROJECT_ID." and s.survey_id = e.survey_id and e.email_id = er.email_id and p.participant_id = er.participant_id 
				order by if (e.email_sent is not null, e.email_sent, if (q.time_sent is not null, q.time_sent, q.scheduled_time_to_send)) desc";
		$q = mysql_query($sql);
		// Loop through all rows and store values in array
		$rownum = 0;
		while ($row = mysql_fetch_assoc($q))
		{
			// Merge recipient emails
			if ($row['participant_email'] == "" && $row['static_email'] != "") {
				$row['participant_email'] = $row['static_email'];
			}
			// Add record name and completed status (if record exists)
			if (isset($participantRecords[$row['participant_id']])) {
				$row['record'] = $participantRecords[$row['participant_id']];
				$row['completed'] = $participantRecordsComplete[$row['participant_id']];
			} else {
				$row['record'] = $row['completed'] = "";
			}
			// If has a record name but is missing email/identifer, then add to array to obtain email/identifier in next section
			if ($row['record'] != "" && $row['participant_email'] == "") {
				$recordsNoEmail[$rownum] = $row['record'];
			}
			// Unset some values we don't need
			unset($row['static_email'], $participantRecords[$row['participant_id']], $participantRecordsComplete[$row['participant_id']]);
			// Add this invitation to array
			$invitationLog[] = $row;
			// Increment counter
			$rownum++;
		}		
		
		// For existing records, get participant identifier and email (if don't have them - i.e. because this is a follow-up survey)
		if (!empty($recordsNoEmail))
		{
			// Get emails/identifiers
			$recordsEmail = getResponsesEmailsIdentifiers($recordsNoEmail);
			// Loop through those that are missing and add those to $invitationLog from $recordsEmail
			foreach ($recordsNoEmail as $logkey=>$this_record)
			{
				if ($invitationLog[$logkey]['participant_email'] == "") {
					$invitationLog[$logkey]['participant_email'] = $recordsEmail[$this_record]['email'];
				}
				if ($invitationLog[$logkey]['participant_identifier'] == "") {
					$invitationLog[$logkey]['participant_identifier'] = $recordsEmail[$this_record]['identifier'];
				}
			}
			unset($recordsNoEmail, $recordsEmail);
		}
		
		//print_array($invitationLog);
		
		
		## FILTERING
		// Set defaults
		if (!isset($_GET['pagenum'])) 			$_GET['pagenum'] = 1;
		if (!isset($_GET['filterBeginTime'])) 	$_GET['filterBeginTime'] = '';
		if (!isset($_GET['filterEndTime'])) 	$_GET['filterEndTime'] = date('m-d-Y')." 23:59";
		if (!isset($_GET['filterInviteType'])) 	$_GET['filterInviteType'] = '';
		if (!isset($_GET['filterResponseType'])) $_GET['filterResponseType'] = '';
		if (!isset($_GET['filterSurveyEvent'])) $_GET['filterSurveyEvent'] = '0-0';
		// Santize all filter inputs
		
		
		
		// Now filter $invitationLog by filters defined
		
		
		
		
		
		## BUILD THE DROP-DOWN FOR PAGING THE INVITATIONS
		// Get participant count
		$invite_count = count($invitationLog);		
		// Section the Participant List into multiple pages
		$num_per_page = 50;
		$limit_begin  = 0;
		if (isset($_GET['pagenum']) && is_numeric($_GET['pagenum']) && $_GET['pagenum'] > 1) {
			$limit_begin = ($_GET['pagenum'] - 1) * $num_per_page;
		}
		## Build the paging drop-down for participant list
		$pageDropdown = "<select id='pageNumInviteLog' onchange='loadInvitationLog(this.value)' style='vertical-align:middle;font-size:11px;'>";
		//Calculate number of pages of for dropdown
		$num_pages = ceil($invite_count/$num_per_page);		
		//Loop to create options for dropdown
		for ($i = 1; $i <= $num_pages; $i++) {
			$end_num   = $i * $num_per_page;
			$begin_num = $end_num - $num_per_page + 1;
			$value_num = $end_num - $num_per_page;
			if ($end_num > $invite_count) $end_num = $invite_count;
			$pageDropdown .= "<option value='$i' " . ($_GET['pagenum'] == $i ? "selected" : "") . ">$begin_num - $end_num</option>";
		}
		if ($num_pages == 0) {
			$pageDropdown .= "<option value=''>0</option>";
		}
		$pageDropdown .= "</select>";
		$pageDropdown  = "<span style='margin-right:25px;'>{$lang['survey_45']} $pageDropdown {$lang['survey_133']} $invite_count</span>";
		
		
		
		// Loop through all invitations for THIS PAGE and build table
		$rownum = 0;
		foreach (array_slice($invitationLog, $limit_begin, $num_per_page) as $row)
		{
			// Set color of timestamp (green if already sent, red if failed) and icon
			$tsColor = ($row['was_sent'] == '0') ? "gray" : ($row['was_sent'] == '1' ? "green" : "red");
			$tsIcon  = ($row['was_sent'] == '0') ? "clock_small.png" : ($row['was_sent'] == '1' ? "tick_small_circle.png" : "bullet_delete.png");
				
			// Get the form name of this survey_id
			$form = $Proj->surveys[$row['survey_id']]['form_name'];
			
			// Send time (and icon)
			$rows[$rownum][] = 	RCView::span(array('style'=>"color:$tsColor;"), 
									RCView::img(array('src'=>$tsIcon,'style'=>'margin-right:2px;')) . 
									format_ts_mysql($row['send_time'])
								);
								
			// View email
			$rows[$rownum][] = 	RCView::a(array('href'=>'javascript:;','onclick'=>"viewEmail({$row['email_recip_id']});"), 
									RCView::img(array('src'=>'mail_open_document.png','title'=>$lang['survey_391']))
								);
			
			// Email address
			if ($row['participant_email'] != "") {
				$rows[$rownum][] = $row['participant_email'];
			} else {
				$rows[$rownum][] = RCView::span(array('style'=>"color:#777;"), $lang['survey_284']);
			}
			
			// Participant Identifier
			$rows[$rownum][] = $row['participant_identifier'];
			
			// Survey title (and event)
			$rows[$rownum][] = 	RCView::div(array('style'=>"color:#800000;"), 
									// Survey title
									$Proj->surveys[$row['survey_id']]['title']
								) .
								RCView::div(array('style'=>"color:#777;"), 
									// Display event (if longitudinal)
									(!$longitudinal ? "" : $Proj->eventInfo[$row['event_id']]['name_ext'])
								);
			
			// Display "open survey" link (if not completed yet)
			if ($row['completed'] == "2") {
				$rows[$rownum][] = "-";
			} else {
				$rows[$rownum][] = 	RCView::a(array('target'=>'_blank','href'=>APP_PATH_SURVEY_FULL."index.php?s={$row['hash']}"),
										RCView::img(array('src'=>'link.png','style'=>'','title'=>$lang['survey_246']))
									);
			}
									
			## Response-completed status
			if ($row['completed'] == "1") {
				// Partial response
				$completedIcon = "minus_circle_green.png";
			} elseif ($row['completed'] == "2") {
				// Completed response
				$completedIcon = "tick_circle_frame.png";
			} else {
				// Response doesn't exist yet (not started survey)
				$completedIcon = "stop_gray.png";
			}
			// If record exists and has an identifier, then make icon a link to the record
			if ($row['completed'] != "" && $row['participant_identifier'] != "") {
				$rows[$rownum][] = 	RCView::a(array('href'=>APP_PATH_WEBROOT."DataEntry/index.php?pid=".PROJECT_ID."&page=$form&event_id={$row['event_id']}&id={$row['record']}",'target'=>'_blank'), 
										RCView::img(array('src'=>$completedIcon,'title'=>$lang['survey_245'],'class'=>'viewresponse'))
									);
			} 
			// Display only icon with no link
			else {
				$rows[$rownum][] = RCView::img(array('src'=>$completedIcon,'class'=>'noviewresponse'));
			}
								
			// Reason not sent
			$rows[$rownum][] = ($row['reason_not_sent'] == "") ? $row['reason_not_sent'] : RCView::span(array('class'=>'wrap'), $row['reason_not_sent']);
			
			// Increment counter
			$rownum++;
		}
		
		// Give message if no invitations were sent
		if (empty($rows)) {
			$rows[$rownum] = array(RCView::div(array('class'=>'wrap','style'=>'color:#800000;'), "No invitations to list"),"","","");
		}
		
		// Define table headers
		$headers = array();
		$headers[] = array(120, "Invitation send time", "center");
		$headers[] = array(28,  RCView::span(array('class'=>'wrap'), $lang['survey_390']), "center");
		$headers[] = array(160, $lang['survey_392']);
		$headers[] = array(100, $lang['survey_250']);
		$headers[] = array(200, "Survey");
		$headers[] = array(34,  RCView::span(array('class'=>'wrap'), $lang['global_90']), "center");
		$headers[] = array(58,  $lang['survey_47'], "center");
		$headers[] = array(103, RCView::span(array('class'=>'wrap'), "Errors (if any)"));
		// Define title
		$title =	RCView::div(array('style'=>'font-family:arial;'),
						RCView::div(array('style'=>'padding:2px 0 0 5px;float:left;font-size:14px;width:220px;'),
							RCView::img(array('src'=>'mails_stack.png','class'=>'imgfix')) .
							$lang['survey_350'] . RCView::br() . 
							RCView::span(array('style'=>'line-height:20px;color:#666;font-size:11px;font-weight:normal;'), 
								"(in descending order by time sent)"
							) . RCView::br() . RCView::br() . 
							RCView::span(array('style'=>'color:#555;font-size:11px;font-weight:normal;'), 							
								$pageDropdown
							)
						) .
						## FILTERS
						RCView::div(array('style'=>'font-weight:normal;float:left;font-size:11px;'),
							// Date/time range
							"Begin time: " .
							RCView::text(array('id'=>'filterBeginTime','value'=>$_GET['filterBeginTime'],'class'=>'x-form-text x-form-field filter_datetime_mdy','style'=>'margin-right:8px;width:92px;height:14px;line-height:14px;font-size:11px;')) . 
							"End time: " .
							RCView::text(array('id'=>'filterEndTime','value'=>$_GET['filterEndTime'],'class'=>'x-form-text x-form-field filter_datetime_mdy','style'=>'width:92px;height:14px;line-height:14px;font-size:11px;')) . 
							RCView::span(array('class'=>'df','style'=>'color:#777;'), '(M-D-Y H:M)') . RCView::br() .
							// Display invitations types and responses status types
							"Display " .
							RCView::select(array('id'=>'filterInviteType','style'=>'font-size:11px;'), 
								array(''=>'All invitation types', '1'=>'Only sent invitations', '-1'=>'Only scheduled invitations'),$_GET['filterInviteType']) .
							" and " .
							RCView::select(array('id'=>'filterResponseType','style'=>'font-size:11px;'), 
								array(''=>'All response statuses', '0'=>'Unresponded', '1'=>'Partial responses', '2'=>'Completed responses'),$_GET['filterResponseType']) .
							RCView::br() . 
							// Display specific surveys
							"Display " .
							RCView::select(array('id'=>'filterSurveyEvent','style'=>'font-size:11px;'), 
								array_merge(array('0-0'=>'All surveys'), self::getInvitationLogSurveyList()),$_GET['filterSurveyEvent'],300) .
							RCView::br() . 
							RCView::button(array('class'=>'jqbuttonsm','style'=>'color:#800000;','onclick'=>"loadInvitationLog($('#pageNumInviteLog').val())"), "Apply filters")
						) .
						RCView::div(array('class'=>'clear'), '')
					);
					
		// Build Participant List
		return renderGrid("email_log_table", $title, 900, 'auto', $headers, $rows);
	}
	
	
	// Return true if a record's survey invitation is already scheduled for a given survey/event
	static public function checkIfRecordScheduled($survey_id, $event_id, $record)
	{
		$sql = "select 1 from redcap_surveys_scheduler s, redcap_surveys_scheduler_queue q 
				where s.ss_id = q.ss_id and s.survey_id = $survey_id and s.event_id = $event_id 
				and q.record = '" . prep($record) . "' limit 1";
		$q = mysql_query($sql);
		// Return true if has been scheduled
		return (mysql_num_rows($q) > 0);
	}
	
	
	// Return true if a record's Form Status value for a given survey/event is Complete (=2)
	static public function isFormStatusCompleted($survey_id, $event_id, $record)
	{
		global $Proj;
		// Get field name of Form Status field for this survey
		$formStatusField = $Proj->surveys[$survey_id]['form_name'].'_complete';
		// Query data table for value of 2
		$sql = "select 1 from redcap_data where project_id = ".PROJECT_ID." and event_id = $event_id 
				and record = '" . prep($record) . "' and field_name = '" . prep($formStatusField) . "' 
				and value = '2' limit 1";
		$q = mysql_query($sql);
		// Return true if has been scheduled
		return (mysql_num_rows($q) > 0);
	}
	
	
	// Determine if this record needs to have a survey invitation scheduled
	public function checkConditionsOfRecordToSchedule($survey_id, $event_id, $record)
	{
		// Fill up $schedules array with schedules
		$this->setSchedules();
		// Check the schedule's attributes
		if (!isset($this->schedules[$survey_id][$event_id])) return false;
		$thisSchedule = $this->schedules[$survey_id][$event_id];
		// If conditional upon survey completion, check if completed survey
		$conditionsPassedSurveyComplete = ($thisSchedule['condition_andor'] == 'AND'); // Initial true value if using AND (false if using OR)
		if (is_numeric($thisSchedule['condition_surveycomplete_survey_id']) && is_numeric($thisSchedule['condition_surveycomplete_event_id']))
		{
			// Is it a completed response?
			$conditionsPassedSurveyComplete = isResponseCompleted($thisSchedule['condition_surveycomplete_survey_id'], $record, $thisSchedule['condition_surveycomplete_event_id']);
			// If not listed as a completed response, then also check Form Status (if entered as plain record data instead of as response), just in case
			if (!$conditionsPassedSurveyComplete) {
				$conditionsPassedSurveyComplete = self::isFormStatusCompleted($thisSchedule['condition_surveycomplete_survey_id'], $thisSchedule['condition_surveycomplete_event_id'], $record);
			}
		}
		// If conditional upon custom logic
		$conditionsPassedLogic = ($thisSchedule['condition_andor'] == 'AND'); // Initial true value if using AND (false if using OR)
		if ($thisSchedule['condition_logic'] != '' 
			// If using AND and $conditionsPassedSurveyComplete is false, then no need to waste time checking evaluateConditionLogic().
			// If using OR and $conditionsPassedSurveyComplete is true, then no need to waste time checking evaluateConditionLogic().
			&& (($thisSchedule['condition_andor'] == 'OR' && !$conditionsPassedSurveyComplete) 
				|| ($thisSchedule['condition_andor'] == 'AND' && $conditionsPassedSurveyComplete)))
		{
			// Does the logic evaluate as true?
			$conditionsPassedLogic = self::evaluateConditionLogic($thisSchedule['condition_logic'], $record);
		}
		// Check pass/fail values and return boolean if record is ready to have its invitation for this survey/event
		if ($thisSchedule['condition_andor'] == 'OR') {
			// OR
			return ($conditionsPassedSurveyComplete || $conditionsPassedLogic);
		} else {
			// AND (default)
			return ($conditionsPassedSurveyComplete && $conditionsPassedLogic);
		}
	}
	
	
	// Get all schedules where record has already had invitations scheduled. Return array with survey_id and event_id as key/subkey
	public function getAlreadyScheduledForRecord($record)
	{
		// Initial empty array
		$alreadyScheduled = array();
		// Query surveys_scheduler_queue table to find any previous invitations already scheduled
		$sql = "select ss_id from redcap_surveys_scheduler_queue where record = '" . prep($record) . "' 
				and ss_id in (" . implode(",", array_keys($this->schedulePkLink)) . ")";
		$q = mysql_query($sql);
		while ($row = mysql_fetch_assoc($q)) {
			foreach ($this->schedulePkLink[$row['ss_id']] as $survey_id=>$event_id) {
				$alreadyScheduled[$survey_id][$event_id] = true;
			}
		}
		// Return array
		return $alreadyScheduled;	
	}
	
	
	// Check if we're ready to schedule the participant's survey invitation to be sent. Return boolean regarding if was scheduled.
	public function checkToScheduleParticipantInvitation($record)
	{
		// Call survey_functions
		require_once APP_PATH_DOCROOT . "Surveys/survey_functions.php";	
		
		// Set initial return value as 0
		$numInvitationsScheduled = 0;
		
		// Fill up $schedules array with schedules
		$this->setSchedules();
		
		// Find available schedules by removing all irrelevant ones 
		// (e.g. exact time schedules, any that are already scheduled, schedules dependent upon other available schedules)
		$availableSchedules = $this->getAvailableSchedulesForRecord($record);
		
		// Loop through all relevant schedules
		foreach ($availableSchedules as $survey_id=>$events) 
		{
			foreach (array_keys($events) as $event_id) 
			{
				// Determine if this record needs to have a survey invitation scheduled
				$readyToSchedule = $this->checkConditionsOfRecordToSchedule($survey_id, $event_id, $record);
				if ($readyToSchedule) {
					// Schedule the participant's survey invitation to be sent by adding it to the scheduler_queue table
					$invitationWasScheduled = $this->scheduleParticipantInvitation($survey_id, $event_id, $record);		
					if ($invitationWasScheduled) {
						// Increment number of invitations scheduled just now
						$numInvitationsScheduled++;
					}
				}
			}
		}
		
		// Return count of invitation scheduled, if any
		return $numInvitationsScheduled;
	}
	

	// Find available schedules by removing all irrelevant ones 
	// (e.g. exact time schedules, any that are already scheduled, schedules dependent upon other available schedules)
	private function getAvailableSchedulesForRecord($record)
	{
		// Get all schedules where record has already had invitations scheduled
		$alreadyScheduled = $this->getAlreadyScheduledForRecord($record);
		
		// First, get only Conditional schedules to put in array AND remove all schedules already scheduled for this record
		$availableSchedules = $this->schedules;
		foreach ($availableSchedules as $survey_id=>$events) {
			foreach ($events as $event_id=>$attr) {
				// Ignore if invitations have already been scheduled for this survey/event
				// OR if the schedule is set as Inactive.
				if (isset($alreadyScheduled[$survey_id][$event_id]) || !$attr['active']) 
				{
					unset($availableSchedules[$survey_id][$event_id]);
					// If survey_id sub-array is not empty them remove it
					if (empty($availableSchedules[$survey_id])) unset($availableSchedules[$survey_id]);
				}
				// If it's dependent upon another survey being completed, then check if participant has completed it. If so, then we can remove it
				elseif (is_numeric($attr['condition_surveycomplete_survey_id']) && is_numeric($attr['condition_surveycomplete_event_id'])
					// Check if they've completed this survey
					&& isResponseCompleted($attr['condition_surveycomplete_survey_id'], $record, $attr['condition_surveycomplete_event_id']))
				{
					unset($availableSchedules[$attr['condition_surveycomplete_survey_id']][$attr['condition_surveycomplete_event_id']]);
					// If survey_id sub-array is now empty them remove it
					if (empty($availableSchedules[$attr['condition_surveycomplete_survey_id']])) unset($availableSchedules[$attr['condition_surveycomplete_survey_id']]);
				}
			}
		}
		
		// Now remove all schedules that are dependent upon other completed surveys in this schedule 
		// and put them in dependentAvailableSchedules array (e.g. if Week 2 requires that Week 1 be finished first).
		$dependentAvailableSchedules = array();
		do {
			// Initial value
			$removedSchedule = false;
			// Loop through all available schedules and remove those that are dependent upon other available ones (cascading issue)
			foreach ($availableSchedules as $survey_id=>$events) {
				foreach ($events as $event_id=>$attr) {
					// If schedule is dependent upon an available schedule OR is dependent upon another dependent schedule, then remove
					if (isset($availableSchedules[$attr['condition_surveycomplete_survey_id']][$attr['condition_surveycomplete_event_id']])
						|| isset($dependentAvailableSchedules[$attr['condition_surveycomplete_survey_id']][$attr['condition_surveycomplete_event_id']])) 
					{
						// Set flag so that we'll know to loop over this whole survey again
						$removedSchedule = true;
						// Remove schedule from array
						unset($availableSchedules[$survey_id][$event_id]);
						// Add schedule that was removed to the dependentAvailableSchedules array
						$dependentAvailableSchedules[$survey_id][$event_id] = $attr;
					}
				}
				// If survey_id sub-array is now empty them remove it
				if (empty($availableSchedules[$survey_id])) unset($availableSchedules[$survey_id]);
			}
		} while ($removedSchedule);
		
		// Return array of available schedules
		return $availableSchedules;
	}
	
	
	// Calculate the date/time when the survey invitation should be send to this participant
	private function calculateParticipantInvitationTime($survey_id, $event_id)
	{
		// Get this schedule's attributes
		$attr = $this->schedules[$survey_id][$event_id];
		
		// SEND AT EXACT TIME 
		if ($attr['condition_send_time_option'] == 'EXACT_TIME') 
		{
			// Set invitation time as the "exact date/time" specified
			$invitationTime = $attr['condition_send_time_exact'];
		}
		
		// IMMEDIATELY SEND
		elseif ($attr['condition_send_time_option'] == 'IMMEDIATELY')
		{
			// Set invitation time as current time right now
			$invitationTime = NOW;
		}
		
		// SEND AFTER SPECIFIED LAPSE OF TIME
		elseif ($attr['condition_send_time_option'] == 'TIME_LAG')
		{
			// Get temporal components
			$days = $attr['condition_send_time_lag_days'];
			$hours = $attr['condition_send_time_lag_hours'];
			$minutes = $attr['condition_send_time_lag_minutes'];
			// Calculate invitation time by adding time lag to current time
			$invitationTime = date("Y-m-d H:i:s", mktime(date("H")+$hours,date("i")+$minutes,date("s"),date("m"),date("d")+$days,date("Y")));
		}
		
		// SEND ON NEXT SPECIFIED DAY/TIME
		elseif ($attr['condition_send_time_option'] == 'NEXT_OCCURRENCE')
		{
			// Set time component of the timestamp
			$timeTS = $attr['condition_send_next_time'];
			// Set the date component of the timestamp
			// If day type is "WEEKEND DAY"
			if ($attr['condition_send_next_day_type'] == 'WEEKENDDAY') {
				// If today is Saturday, then next weekend day = next Sunday (i.e. tomorrow)
				if (date('D') == 'Sat') {
					$dateTS = date('Y-m-d', strtotime('NEXT SUNDAY'));
				}
				// If today is any day other than Saturday, then next weekend day is next Saturday
				else {
					$dateTS = date('Y-m-d', strtotime('NEXT SATURDAY'));
				}
			} 
			// Any other day type (can use strtotime to parse into date)
			else {
				$dateTS = date('Y-m-d', strtotime('NEXT '.$attr['condition_send_next_day_type']));
			}
			// Combine date and time components
			$invitationTime = "$dateTS $timeTS";
		}
		
		// Validate the date/time with regex (in case components are missing or are calculated incorrectly)
		$datetime_regex = '/^(\d{4})([-\/.])?(0[1-9]|1[012])\2?(0[1-9]|[12][0-9]|3[01])\s([0-9]|[0-1][0-9]|[2][0-3]):([0-5][0-9]):([0-5][0-9])$/';
		if (!preg_match($datetime_regex, $invitationTime)) $invitationTime = false;
		
		// Return invitation date/time
		return $invitationTime;
	}
	
	
	// Schedule the participant's survey invitation to be sent by adding it to the scheduler_queue table. Return boolean
	public function scheduleParticipantInvitation($survey_id, $event_id, $record)
	{
		// Default return value
		$invitationWasScheduled = false;
		
		// Fill up $schedules array with schedules
		$this->setSchedules();	
	
		// First, make sure that there is a placeholder set in the participants table for this record-survey-event
		list ($participant_id, $hash) = getFollowupSurveyParticipantIdHash($survey_id, $record, $event_id);
		
		// Calculate the date/time when the survey invitation should be send to this participant
		$invitationTime = $this->calculateParticipantInvitationTime($survey_id, $event_id);
		if ($invitationTime === false) return false;
				
		// Add to surveys_emails table
		$sql = "insert into redcap_surveys_emails (survey_id, email_subject, email_content, email_account, email_static)
				select '$survey_id', email_subject, email_content, NULL, email_sender from redcap_surveys_scheduler
				where survey_id = $survey_id and event_id = $event_id";
		if (mysql_query($sql)) 
		{
			// Get email_id
			$email_id = mysql_insert_id();
			// Now add to surveys_emails_recipients table
			$sql = "insert into redcap_surveys_emails_recipients (email_id, participant_id) values ($email_id, $participant_id)";
			if (mysql_query($sql)) 
			{
				// Get email_recip_id
				$email_recip_id = mysql_insert_id();		
				// Add scheduled survey invitation to table
				$sql = "insert into redcap_surveys_scheduler_queue (ss_id, email_recip_id, record, scheduled_time_to_send) 
						(select ss_id, '$email_recip_id', '" . prep($record) . "', '$invitationTime' from redcap_surveys_scheduler
						where survey_id = $survey_id and event_id = $event_id)
						on duplicate key update scheduled_time_to_send = '$invitationTime'";
				$invitationWasScheduled = mysql_query($sql);		
				// Get ssq_id from insert
				$ssq_id = mysql_insert_id();			
				// If need to send the invite right now, then send it here
				if ($invitationTime == NOW) {
					self::emailInvitations($ssq_id);
				}
			}
		}
		
		// Return true if was scheduled successfully
		return $invitationWasScheduled;
	}
	
	// Format a single rule's logic
	static private function formatRuleLogic($logic)
	{
		// Replace operators in equation with PHP equivalents and removing line breaks
		$orig = array("\r\n", "\n", "<"  , "=" , "===", "====", "> ==", "< ==", ">==" , "<==" , "< >", "<>", " and ", " AND ", " or ", " OR ");
		$repl = array(" "   , " " , " < ", "==", "==" , "=="  , ">="  , "<="  , ">="  , "<="  , "<>" , "!=", " && " , " && " , " || ", " || ");
		$logic = str_replace($orig, $repl, $logic);
		// Now reformat any exponential formating in the logic
		$logic = self::replaceExponents($logic);
		// Now reformat any IF statements into PHP ternary operator format
		$logic = convertIfStatement($logic);
		// Return the resulting string
		return $logic;
	}	
		
	// When parsing DQ rules or branching logic (for missing values rule), replace ^ exponential form with PHP equivalent
	static private function replaceExponents($string) 
	{
		//Find all ^ and locate outer parenthesis for its number and exponent
		$caret_pos = strpos($string, "^");
		// Make sure it has at least 2 left and right parentheses (otherwise not in correct format)
		$num_paren_left = substr_count($string, "(");
		$num_paren_right = substr_count($string, ")");
		// Loop through string
		$num_loops = 0;
		while ($caret_pos !== false && $num_paren_left >= 2 && $num_paren_right >= 2 && $num_loops < 1000) 
		{
			$num_loops++;
			//For first half of string
			$found_end = false;
			$rpar_count = 0;
			$lpar_count = 0;
			$i = $caret_pos;
			while ($i >= 0 && !$found_end) 
			{
				$i--;
				//Keep count of left/right parentheses
				if (substr($string, $i, 1) == "(") {
					$lpar_count++;
				} elseif (substr($string, $i, 1) == ")") {
					$rpar_count++;
				}
				//If found the parentheses boundary, then end loop
				if ($rpar_count > 0 && $lpar_count > 0 && $rpar_count == $lpar_count) {
					$found_end = true;
				}
			}
			//Completed first half of string
			$string = substr($string, 0, $i). "pow(" . substr($string, $i);
			$caret_pos += 4; // length of "pow("
			
			//For last half of string
			$last_char = strlen($string);
			$found_end = false;
			$rpar_count = 0;
			$lpar_count = 0;
			$i = $caret_pos;
			while ($i <= $last_char && !$found_end) {
				$i++;
				//Keep count of left/right parentheses
				if (substr($string, $i, 1) == "(") {
					$lpar_count++;
				} elseif (substr($string, $i, 1) == ")") {
					$rpar_count++;
				}
				//If found the parentheses boundary, then end loop
				if ($rpar_count > 0 && $lpar_count > 0 && $rpar_count == $lpar_count) {
					$found_end = true;
				}
			}
			//Completed last half of string
			$string = substr($string, 0, $caret_pos) . "," . substr($string, $caret_pos + 1, $i - $caret_pos) . ")" . substr($string, $i + 1);
			
			//Set again for checking in next loop
			$caret_pos = strpos($string, "^");
			
		}
		return $string;
	}
	
	// Check a single rule's logic for any errors/issues
	static private function checkRuleLogic($logic)
	{
		global $Proj;
		
		// Trim the logic
		$logic = trim($logic);
		
		// Make sure that it has length
		if (strlen($logic) < 1) return false;
		
		## Check for basic syntax errors (odd number of parentheses, brackets, etc.)
		// Check symmetry of "
		if (substr_count($logic, '"')%2 > 0) {
			return false;
		}
		// Check symmetry of '
		if (substr_count($logic, "'")%2 > 0) {
			return false;
		}
		// Check symmetry of [ with ]
		if (substr_count($logic, "[") != substr_count($logic, "]")) {
			return false;
		}
		// Check symmetry of ( with )
		if (substr_count($logic, "(") != substr_count($logic, ")")) {
			return false;
		}
		// Make sure does not contain $ dollar signs
		if (strpos($logic, '$') !== false) {
			return false;
		}
		// Make sure does not contain ` backtick character
		if (strpos($logic, '`') !== false) {
			return false;
		}
		
		/* 
		// Make sure there does not exist any dangerous PHP functions in the logic		
		foreach ($this->dangerousPhpFunctions as $function)
		{
			// Run the value through the regex pattern. If found a match, then return false
			if (!preg_match("/($function)(\s*)(\()/", $logic)) return false;
		}
		*/
		
		## Make sure there ONLY exists functions in the logic that are allowed
		$illegalFunctionsUsed = array();
		// Run the value through the regex pattern
		preg_match_all("/([a-zA-Z0-9_]{2,})(\s*)(\()/", $logic, $regex_matches);
		if (isset($regex_matches[1]) && !empty($regex_matches[1]))
		{
			// Loop through each matched function name and make sure it's an allowed function and not a checkbox fieldname
			foreach ($regex_matches[1] as $this_func)
			{
				// Make sure it's not a PHP function AND that it's not a checkbox field
				if (!in_array($this_func, $this->allowedFunctions) && !(isset($Proj->metadata[$this_func]) && $Proj->metadata[$this_func]['element_type'] == 'checkbox'))
				{
					$illegalFunctionsUsed[] = $this_func;
				}
			}
		}
		// If illegal functions are being used, return array of function names
		if (!empty($illegalFunctionsUsed)) {
			return $illegalFunctionsUsed;
		}
				
		// Return true if passed check
		return true;
	}
	
	// Evaluate the schedule's condition logic for a given record
	static private function evaluateConditionLogic($raw_logic, $record) 
	{
		global $Proj;
		// Parse the logic
		$checkRuleLogic = self::checkRuleLogic($raw_logic);
		if ($checkRuleLogic === false || (is_array($checkRuleLogic) && !empty($checkRuleLogic))) {
			return false;
		}		
		// Format the logic into PHP format
		$logic = self::formatRuleLogic($raw_logic);		
		// Get unique event names (with event_id as key)
		$events = $Proj->getUniqueEventNames();
		// Array to collect list of all fields used in the logic
		$fields = array();
		$eventsUtilized = array();
		// Loop through fields used in the logic. Also, parse out any unique event names, if applicable
		foreach (array_keys(getBracketedFields($raw_logic, true, true, false)) as $this_field)
		{
			// Check if has dot (i.e. has event name included)
			if (strpos($this_field, ".") !== false) {
				list ($this_event_name, $this_field) = explode(".", $this_field, 2);
				// Get the event_id
				$this_event_id = array_search($this_event_name, $events);
				// Add event/field to $eventsUtilized array
				$eventsUtilized[$this_event_id][$this_field] = true;
			} else {
				// Add event/field to $eventsUtilized array
				$eventsUtilized['all'][$this_field] = true;
			}
			// Verify that the field really exists (may have been deleted). If not, stop here with an error.
			if (!isset($Proj->metadata[$this_field])) return false;
			// Add field to array
			$fields[] = $this_field;
		}		
		// Get default values for all records (all fields get value '', except Form Status and checkbox fields get value 0)
		$default_values = array();
		foreach ($fields as $this_field)
		{
			// Loop through all designated events so that each event
			foreach (array_keys($Proj->eventInfo) as $this_event_id)
			{
				// Check a checkbox or Form Status field
				if ($Proj->metadata[$this_field]['element_type'] == 'checkbox') {
					// Loop through all choices and set each as 0
					foreach (array_keys(parseEnum($Proj->metadata[$this_field]['element_enum'])) as $choice) {
						$default_values[$this_event_id][$this_field][$choice] = '0';
					}
				} elseif ($this_field == $Proj->metadata[$this_field]['form_name'] . "_complete") {
					// Set as 0
					$default_values[$this_event_id][$this_field] = '0';
				} else {
					// Set as ''
					$default_values[$this_event_id][$this_field] = '';
				}
			}
		}
		// Query the values of the logic fields for ALL records
		$sql = "select record, event_id, field_name, value from redcap_data where project_id = " . PROJECT_ID 
			 . " and field_name in ('" . implode("', '", $fields) . "') and value != '' and record = '" . prep($record) . "'"
			 . " order by event_id";
		$q = mysql_query($sql);
		// Set intial values
		$event_id = 0;
		$record = "";
		$record_data = array();
		// Loop through data one record at a time
		while ($row = mysql_fetch_assoc($q))
		{
			// Add initial default data for first loop
			if ($event_id === 0 || $row['event_id'] !== $event_id) {
				$record_data[$row['event_id']] = $default_values[$row['event_id']];
			}
			// Decode the value
			$row['value'] = label_decode($row['value']);
			// Set values for this loop
			$event_id = $row['event_id'];
			$record   = $row['record'];	
			// Add the value into the array (double check to make sure the event_id still exists)
			if (isset($events[$event_id])) // && in_array($row['field_name'], $fields))
			{
				if ($Proj->metadata[$row['field_name']]['element_type'] == 'checkbox') {
					// Add checkbox value
					$record_data[$event_id][$row['field_name']][$row['value']] = 1;
				} else {
					// Non-checkbox value
					$record_data[$event_id][$row['field_name']] = $row['value'];
				}
			}
		}
		// Replace literal values into logic to get the literal string
		$literalLogic = self::formatRuleLogicLiteral($logic, $record_data);
		// Apply the logic and return the result (0 = all conditions are true)
		return (self::applyRuleLogic($literalLogic) !== 1);
	}
		
	// Evaluates the literal conditional logic and returns boolean
	static private function applyRuleLogic($literalLogic)
	{
		// Now that the literal values have been added to the logic, test is logic is TRUE or FALSE
		try 
		{
			// Eval the logic
			@eval("\$logicCheckResult = ($literalLogic);");
			// Return 1 for true and 0 for false (only return false if procedure failed due to error)
			return ($logicCheckResult === false ? 1 : 0);	
		} 
		catch (Exception $e) 
		{
			return false;
		}
	}
	
	// Replace literal values into logic for a single record for a single rule
	static private function formatRuleLogicLiteral($logic, $record_data)
	{
		global $Proj, $longitudinal;
		
		// Get unique event names (with event_id as key)
		$events = $Proj->getUniqueEventNames();	
		
		// Loop through the data and replace the variable with its literal value
		foreach ($record_data as $event_id=>$field_name_value)
		{
			// Get unique event name for this event_id
			$unique_event_name = $events[$event_id];				
			// Replace "][" with "[]" so that event+field syntax gets replaced correctly because the normal field replacement was messing it up
			$logic = str_replace("][", "[]", $logic);
			// Now loop through each field for this record-event
			foreach ($field_name_value as $field_name=>$value)
			{
				// Replace variable with value
				if (is_array($value)) {
					// Replace checkbox logic
					foreach ($value as $chkbox_choice=>$chkbox_val) 
					{
						// If also longitudinal, then try replacing the unique event name + field with the value
						if ($longitudinal) {
							$logic = str_replace("[{$unique_event_name}[]$field_name($chkbox_choice)]", " '$chkbox_val' ", $logic);
						}
						// Replace field name
						$logic = str_replace("[$field_name($chkbox_choice)]", " '$chkbox_val' ", $logic);
					}
				} else {
					// Determine if field is a numerical value and not a string so we can know to surround it with apostrophes
					$fieldType = $Proj->metadata[$field_name]['element_type'];
					$valType   = $Proj->metadata[$field_name]['element_validation_type'];
					$isNumericField = ($fieldType == 'calc' || $fieldType == 'slider' || ($fieldType == 'text' && ($valType == 'int' || $valType == 'float')));
					$quote = ($isNumericField && is_numeric($value)) ? "" : "'"; // Doubly ensure that value is numeric to prevent crashing
					// Escape any apostrophes in the value since we around enclosing the value with apostrophes
					$value = cleanHtml($value);
					// If also longitudinal, then try replacing the unique event name + field with the value
					if ($longitudinal) {
						$logic = str_replace("[{$unique_event_name}[]$field_name]", " {$quote}{$value}{$quote} ", $logic);
					}
					// Replace field name
					$logic = str_replace("[$field_name]", " {$quote}{$value}{$quote} ", $logic);
				}
			}
			// Undo the replacement made above
			$logic = str_replace("[]", "][", $logic);
		}
		
		// In case there are some fields still left in the logic in square brackets (because they have no data),
		// then return false so that we can ignore this record-event for this rule.
		$fieldsNoValue = array_keys(getBracketedFields($logic, true, true, true));
		if (!empty($fieldsNoValue))
		{
			return false;
		}
		
		// Return the literal logic back
		return $logic;
	}	
	
	// MAILER: Send one batch of invitations (limit based on determineEmailsPerBatch())
	// or send single invitation if ssq_id is provided
	static public function emailInvitations($ssq_id=null)
	{		
		global $lang;
		// First, get ssq_id of all records for this batch to have invitations scheduled
		$ssq_ids = array();
		// If single ssq_id is provided, then limit query only to this one
		$sqlsub = (is_numeric($ssq_id)) ? "and ssq_id = $ssq_id" : "";
		$sql = "select ssq_id from redcap_surveys_scheduler_queue where time_sent is null 
				and scheduled_time_to_send <= '" . NOW . "' and status = 'QUEUED' $sqlsub 
				order by scheduled_time_to_send limit " . self::determineEmailsPerBatch();
		$q = mysql_query($sql);
		if (mysql_num_rows($q) > 0)
		{
			## Get all ssq_id's and put in array
			while ($row = mysql_fetch_assoc($q)) {
				$ssq_ids[] = $row['ssq_id'];
			}
			
			## Set all those ssq_id's status as SENDING
			$sql = "update redcap_surveys_scheduler_queue set status = 'SENDING' 
					where ssq_id in (" . implode(",", $ssq_ids) . ")";
			$q = mysql_query($sql);

			## GET EMAIL ADDRESSES
			$ssq_ids_emails = array();
			// First, get any emails connected to a Participant List for all the records involved here.
			// The first part of union query gets emails for initial surveys (i.e. record is null, doesn't exist yet),
			// while second part of query gets emails of followup surveys (i.e. existing records).
			$sql = "(select q.ssq_id, p.participant_email 
					from redcap_surveys_scheduler_queue q, redcap_surveys_emails_recipients e, redcap_surveys_participants p 
					where q.record is null and q.email_recip_id = e.email_recip_id and p.participant_id = e.participant_id 
					and p.participant_email is not null and p.participant_email != '' and q.ssq_id in (" . implode(",", $ssq_ids) . "))
					union
					(select q.ssq_id, p2.participant_email from redcap_surveys_scheduler_queue q, 
					redcap_surveys_emails_recipients e, redcap_surveys_participants p, redcap_surveys s, redcap_surveys s2, 
					redcap_surveys_participants p2, redcap_surveys_response r where q.email_recip_id = e.email_recip_id 
					and p.participant_id = e.participant_id and p.survey_id = s.survey_id and s.project_id = s2.project_id 
					and s2.survey_id = p2.survey_id and p2.participant_id = r.participant_id and p2.participant_email is not null 
					and p2.participant_email != '' and r.record = q.record
					and q.ssq_id in (" . implode(",", $ssq_ids) . "))";
			$q = mysql_query($sql);
			while ($row = mysql_fetch_assoc($q)) {
				$ssq_ids_emails[$row['ssq_id']] = label_decode($row['participant_email']);
			}
			// Secondly, if we are missing any emails that are NOT in a Participant List but project
			// uses special "participant email" data field, get value for that in redcap_data table.
			if (count($ssq_ids) > count($ssq_ids_emails) )
			{
				$sql = "select q.ssq_id, d.value from redcap_surveys_scheduler_queue q, redcap_surveys_emails_recipients r, 
						redcap_surveys_emails e, redcap_surveys_participants p, redcap_projects p2, redcap_surveys s, 
						redcap_data d where q.email_recip_id = r.email_recip_id and e.email_id = r.email_id 
						and p.participant_id = r.participant_id and s.survey_id = e.survey_id 
						and s.project_id = p2.project_id and p2.survey_email_participant_field is not null 
						and p2.survey_email_participant_field != '' and p2.project_id = d.project_id 
						and d.field_name = p2.survey_email_participant_field and d.record = q.record 
						and d.value != '' and q.ssq_id in (" . implode(",", array_diff($ssq_ids, array_keys($ssq_ids_emails))) . ")";
				$q = mysql_query($sql);
				while ($row = mysql_fetch_assoc($q)) {
					$ssq_ids_emails[$row['ssq_id']] = label_decode($row['value']);
				}
			}
			// Thirdly, if we are still missing any emails, look for static email value in emails_recipients table.
			if (count($ssq_ids) > count($ssq_ids_emails) )
			{
				$sql = "select q.ssq_id, r.static_email from redcap_surveys_scheduler_queue q, redcap_surveys_emails_recipients r 
						where q.email_recip_id = r.email_recip_id 
						and q.ssq_id in (" . implode(",", array_diff($ssq_ids, array_keys($ssq_ids_emails))) . ")";
				$q = mysql_query($sql);
				while ($row = mysql_fetch_assoc($q)) {
					$ssq_ids_emails[$row['ssq_id']] = label_decode($row['static_email']);
				}
			}
			
			
			## SEND EMAILS
			// Initialize email
			$email = new Message();
			// Initialize counter of number of emails sent
			$numEmailsSent = 0;
			// Now loop though all ssq_id's and send email for each
			$sql = "select q.ssq_id, e.email_id, e.email_subject, e.email_content, 
					if (e.email_static is null, (select if (e.email_account=1, u.user_email, if (e.email_account=2, u.user_email2, u.user_email3)) 
						from redcap_user_information u where u.ui_id = e.email_sender), e.email_static) as email_static,
					p.hash, p.participant_id, s.title, s.survey_id 
					from redcap_surveys_scheduler_queue q, redcap_surveys_emails_recipients r, redcap_surveys_emails e, 
					redcap_surveys_participants p, redcap_surveys s where q.email_recip_id = r.email_recip_id 
					and e.email_id = r.email_id and p.participant_id = r.participant_id and s.survey_id = e.survey_id 
					and q.ssq_id in (" . implode(",", $ssq_ids) . ") 
					order by q.scheduled_time_to_send";
			$q = mysql_query($sql);
			// Enable email time sent tracker to count time it takes to send all emails
			$mtime = explode(" ", microtime()); 
			$starttime = $mtime[1] + $mtime[0];
			// Loop through all emails to be sent and send them
			while ($row = mysql_fetch_assoc($q))
			{
				// Set variables for this loop
				$emailSent = false;
				$ssq_id = $row['ssq_id'];
				$email_id = $row['email_id'];
				$participantEmail = isEmail($ssq_ids_emails[$ssq_id]) ? $ssq_ids_emails[$ssq_id] : null;
				// If fromEmail is missing, then have it sent from the recipient themself
				$fromEmail = empty($row['email_static']) ? $participantEmail : $row['email_static'];
				// Set default query (failure to send because email address doesn't exist)
				$sql = "update redcap_surveys_scheduler_queue set status = 'DID NOT SEND',
						reason_not_sent = 'EMAIL ADDRESS NOT FOUND' where ssq_id = $ssq_id";
				// If we have an email for this ssq_id, then send it. Otherwise, mark it as DID NOT SEND
				if (isset($ssq_ids_emails[$ssq_id]) && $email != null) 
				{
					// Decode subject/content (just in case)
					$row['email_content'] = label_decode($row['email_content']);
					$row['email_subject'] = strip_tags(label_decode($row['email_subject']));
					// Build email message content	
					$emailContents = '<html><body style="font-family:Arial;font-size:10pt;">
						'.nl2br($row['email_content']).'<br /><br />	
						'.$lang['survey_134'].'<br />
						<a href="' . APP_PATH_SURVEY_FULL . '?s=' . $row['hash'] . '">'
						.strip_tags(label_decode($row['title'])).'</a><br /><br />
						'.$lang['survey_135'].'<br />
						' . APP_PATH_SURVEY_FULL . '?s=' . $row['hash'] . '<br /><br />	
						'.$lang['survey_137'].'
						</body></html>';
					// Construct email components
					$email->setTo($participantEmail);
					$email->setFrom($fromEmail);
					$email->setSubject($row['email_subject']);
					$email->setBody($emailContents);
					// Send email
					$emailSent = $email->send();
					if ($emailSent) {
						// Set query to update as SENT with timestamp when sent
						$sql = "update redcap_surveys_scheduler_queue set status = 'SENT', time_sent = '".NOW."' 
								where ssq_id = $ssq_id";
					} else {
						// Mark as DID NOT SEND with reason why
						// if (php_sapi_name() != "cli") print $email->getSendError();
						$sql = "update redcap_surveys_scheduler_queue set status = 'DID NOT SEND',
								reason_not_sent = 'EMAIL ATTEMPT FAILED' where ssq_id = $ssq_id";
					}
				}
				// Execute query after email was sent or did not send
				$q2 = mysql_query($sql);
				// If email was sent successfully, then also add it to surveys_emails table to "log" it
				if ($q2 && $emailSent) 
				{
					// Update surveys_emails table
					$sql = "update redcap_surveys_emails set email_sent = '".NOW."' 
							where email_id = $email_id and email_sent is null";
					mysql_query($sql);
				}
				// Increment counter
				$numEmailsSent++;
			}
			// Free up memory
			mysql_free_result($q);
			unset($ssq_ids, $ssq_ids_emails);
			
			// Now that all emails have been sent, record in table how long it took to send them (to use rate in future batches)
			if ($numEmailsSent >= self::MIN_RECORD_EMAILS_SENT) 
			{
				// Stop the email time sent tracker to count time it takes to send all emails
				$mtime = explode(" ", microtime()); 
				$endtime = $mtime[1] + $mtime[0];
				$totalTimeEmailsSent = ($endtime - $starttime);
				// Calculate the email sending rate in emails/minute
				$emailsSentPerMinuteCalculated = round(($numEmailsSent / $totalTimeEmailsSent) * 60);
				// Now add this value to the redcap_surveys_emails_send_rate table
				$sql = "insert into redcap_surveys_emails_send_rate (sent_begin_time, emails_per_batch, emails_per_minute)
						values ('" . NOW . "', $numEmailsSent, $emailsSentPerMinuteCalculated)";
				mysql_query($sql);
			}
		}
	}
	
	
	// If this was a survey response, and it was just completed BEFORE an invitation was sent out (when it was alrady queued to send), 
	// then remove it from the scheduler_queue table (if already in there).
	static public function deleteInviteIfCompletedSurvey($survey_id, $event_id, $record)
	{
		// Make sure the response is completed first
		if (!isResponseCompleted($survey_id, $record, $event_id)) return false;		
		// Initialize vars
		$ssq_ids = $email_recip_ids = array();
		$wasDeleted = false;
		// If invitation is already queued, then set it as DID NOT SEND with reason_not_sent of SURVEY ALREADY COMPLETED
		$sql = "select q.ssq_id, e.email_recip_id from redcap_surveys_participants p, redcap_surveys_response r, 
				redcap_surveys_scheduler_queue q, redcap_surveys_emails_recipients e where p.survey_id = $survey_id 
				and p.event_id = $event_id and r.participant_id = p.participant_id and p.participant_email is not null 
				and q.email_recip_id = e.email_recip_id and p.participant_id = e.participant_id
				and q.status = 'QUEUED' and r.record = '" . prep($record) . "'";
		$q = mysql_query($sql);
		if (mysql_num_rows($q) > 0)
		{
			while ($row = mysql_fetch_assoc($q)) {
				$ssq_ids[] = $row['ssq_id'];
				$email_recip_ids[] = $row['email_recip_id'];
			}
			// Remove from scheduler queue and recipient list
			$sql  = "delete from redcap_surveys_emails_recipients where email_recip_id in (0, ".prep_implode($email_recip_ids).")";
			$sql2 = "delete from redcap_surveys_scheduler_queue where ssq_id in (0, ".prep_implode($ssq_ids).")";
			if (mysql_query($sql) && mysql_query($sql2)) {
				$wasDeleted = true;
			}
		}
		// Return true if removed it from queue table
		return $wasDeleted;
	}
	
}