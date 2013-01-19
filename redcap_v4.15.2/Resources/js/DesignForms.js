$(function(){

	// If table is not disabled, then add dynamic elements for making edits to instruments (reordering, popup tooltips)
	if (!disable_instrument_table) {
		var i = 1;
		$("#table-forms_surveys tr").each(function() {
			$(this.cells[0]).addClass('dragHandle');
			$(this).prop("id","row_"+i);
			i++;
		});
		// Modify form order: Enable drag-n-drop on table
		$('#table-forms_surveys').tableDnD({
			onDrop: function(table, row) {
				// Remove "add form" button rows, if displayed
				$('.addNewInstrRow').remove();
				// Loop through table
				var i = 1;
				var forms = "";
				var this_form = trim($(row.cells[0]).text());
				$("#table-forms_surveys tr").each(function() {			
					// Restripe table
					$(this).removeClass('erow');
					if (i%2 == 0) $(this).addClass('erow');
					i++;
					// Gather form_names
					forms += trim($(this.cells[0]).text()) + ",";
				});
				// Show success
				$('#savedMove-'+this_form).show();
				setTimeout(function(){
					$('#savedMove-'+this_form).hide();
				},2500);
				// Save form order
				$.post(app_path_webroot+'Design/update_form_order.php?pid='+pid, { forms: forms }, function(data){
					if (data != '1' && data != '2') {
						alert(woops);
					}
					// Give conformation and reload page to update the left-hand menu
					else if (status < 1 && !longitudinal) {
						setTimeout(function(){
							simpleDialog(form_moved_msg,null,'','','window.location.reload();');
						},500);
					}
				});
			},
			dragHandle: "dragHandle"
		});
		// Create mouseover image for drag-n-drop action and enable button fading on row hover
		$("#table-forms_surveys tr").hover(function() {
			$(this.cells[0]).css('background','#ffffff url("'+app_path_images+'updown.gif") no-repeat center');
			$(this.cells[0]).css('cursor','move');
		}, function() {
			$(this.cells[0]).css('background','');
			$(this.cells[0]).css('cursor','');
		});
		// Set up drag-n-drop pop-up tooltip
		$("#forms_surveys .hDiv .hDivBox tr").find("th:first").each(function() {
			$(this).prop('title',langDrag);
			$(this).tooltip({ tipClass: 'tooltip4sm', position: 'top center', offset: [25,0], predelay: 100, delay: 0, effect: 'fade' });			
		});
		$('.dragHandle').hover(function() {
			$("#forms_surveys .hDiv .hDivBox tr").find("th:first").trigger('mouseover');
		}, function() {
			$("#forms_surveys .hDiv .hDivBox tr").find("th:first").trigger('mouseout');
		});
		// Set up formname mouseover pop-up tooltip
		$("#forms_surveys .hDiv .hDivBox tr").find("th:eq(1)").each(function() {
			$(this).prop('title','<b>'+langClickRowMod+'</b><br>'+langAddNewFlds);
			$(this).tooltip({ tipClass: 'tooltip4', position: 'top center', offset: [25,0], predelay: 100, delay: 0, effect: 'fade' });
		});
		$('.formLink').hover(function() {
			$(this).find(".instrEdtIcon").show();
			$("#forms_surveys .hDiv .hDivBox tr").find("th:eq(1)").trigger('mouseover');
		}, function() {
			$(this).find(".instrEdtIcon").hide();
			$("#forms_surveys .hDiv .hDivBox tr").find("th:eq(1)").trigger('mouseout');
		});
	}	
	
	// Set up "modify survey settings" pop-up tooltip
	if (surveys_enabled > 0) {
		$("#forms_surveys .hDiv .hDivBox tr").find("th:eq(4)").each(function() {
			$(this).prop('title',langModSurvey);
			$(this).tooltip({ tipClass: 'tooltip4sm', position: 'top center', offset: [12,0], predelay: 100, delay: 0, effect: 'fade' });			
		});
		$('.modsurvstg').hover(function() {
			$("#forms_surveys .hDiv .hDivBox tr").find("th:eq(4)").trigger('mouseover');
			$(this).parent().css({'background-image':'url("'+app_path_images+'pencil_small2.png")','background-repeat':'no-repeat',
				'background-position':'50px center'});
		}, function() {
			$("#forms_surveys .hDiv .hDivBox tr").find("th:eq(4)").trigger('mouseout');
			$(this).parent().css({'background-image':''});
		});
	}
	
	// Set up "download PDF" pop-up tooltip
	$("#forms_surveys .hDiv .hDivBox tr").find("th:eq(3)").each(function() {
		$(this).prop('title',langDownloadPdf);
		$(this).tooltip({ tipClass: 'tooltip4sm', position: 'top center', offset: [12,0], predelay: 100, delay: 0, effect: 'fade' });			
	});
	$('.pdficon').hover(function() {
		$("#forms_surveys .hDiv .hDivBox tr").find("th:eq(3)").trigger('mouseover');
	}, function() {
		$("#forms_surveys .hDiv .hDivBox tr").find("th:eq(3)").trigger('mouseout');
	});
});



// Displays "add here" button to add new forms in Online Form Editor
function showAddForm() {
	if ($('.addNewInstrRow').length) {
		$('.addNewInstrRow').remove();
	} else {
		// Check to make sure at least one form exists
		var colCount = $("#table-forms_surveys tr:first td").length;
		var rowCount = $("#table-forms_surveys tr").length;
		if (rowCount > 0) {
			$("#table-forms_surveys tr").each(function() {
				var form_name = trim($(this.cells[0]).text());
				$(this).after("<tr class='addNewInstrRow' style='display:none;'><td id='new-"+form_name+"' class='darkgreen' colspan='"+colCount+"' style='font-size:11px;border:0;border-bottom:1px solid #A5CC7A;border-top:1px solid #A5CC7A;padding:5px;'>"
							+ "<button onclick=\"addNewFormReveal('"+form_name+"')\" style='margin-left:120px;font-size:11px;'>"+langAddInstHere+"</button>"
							+ "</td></td></tr>");
			});
			$('.addNewInstrRow').show("", { direction: "vertical" }, 1000);
		} else {
			$("#table-forms_surveys").html("<tr class='addNewInstrRow'><td id='new-' style='border:0;border-bottom:1px solid #ccc;border-top:1px solid #ccc;padding:5px;background-color:#E8ECF0;width:720px;'></td></tr>");
			addNewFormReveal('');
		}
	}
}

// Navigate user to Design page when adding new data entry form via Online Form Builder
function addNewFormReveal(form_name) {
	$('#new-'+form_name).html('<span style="margin-left:25px;font-weight:bold;">'+langNewInstName+'</span>&nbsp; '
		+ '<input type="text" class="x-form-text x-form-field" id="new_form-'+form_name+'"> '
		+ '<input type="button" value="'+langCreate+'" style="font-size:11px;" onclick=\'addNewForm("'+form_name+'")\'>'
		+ '<span style="padding-left:10px;"><a href="javascript:;" style="font-size:10px;text-decoration:underline;" onclick="showAddForm()">'+langCancel+'</a></span>');
	setCaretToEnd(document.getElementById('new_form-'+form_name));
}	
function addNewForm(form_name) {
	var newForm = $('#new_form-'+form_name).val();
	if (checkIsTwoByte(newForm)) {
		simpleDialog(langRemove2Bchar);
		return;
	}
	// Remove unwanted characters
	$('#new_form-'+form_name).val(newForm.replace(/^\s+|\s+$/g,'')); 
	if (newForm.length < 1) {
		simpleDialog(langProvideInstName);
		return;
	}
	// Make sure first number is not numeric
	if (isNumeric(newForm.substring(0,1))) {
		simpleDialog(langInstrCannotBeginNum);
		return;
	}
	var temp = newForm;
	temp = temp.toLowerCase();
	temp = temp.replace(/[^a-z0-9]/ig,'_');										
	temp = temp.replace(/___/g,'_');
	temp = temp.replace(/__/g,'_');
	// Redirect
	window.location.href = app_path_webroot+'Design/online_designer.php?pid='+pid+'&formlocation=after&formplace='+form_name+'&page='+temp+'&newform='+escape(newForm)+addGoogTrans();
}

// Create new instrument with only Form Status field as a placeholder
// function createForm(form_name) {
	// var newForm = $('#new_form-'+form_name).val();
	// if (checkIsTwoByte(newForm)) {
		// simpleDialog(langRemove2Bchar);
		// return;
	// }
	// $.post(app_path_webroot+'Design/create_form.php?pid='+pid, { formLabel: newForm },function(data) {
		// window.location.reload();
	// }
// }

//For editing the menu description of a form name
function setFormMenuDescription(form_name) {
	if ($('#form_menu_description_input-'+form_name).val().length < 1) {
		alert('Please enter a value for the form name');
		return false;
	} else if (checkIsTwoByte($('#form_menu_description_input-'+form_name).val())) {
		alert('Please remove two-byte characters');
		return false;
	}
	document.getElementById('progress-'+form_name).style.visibility = 'visible';
	var this_value = document.getElementById('form_menu_description_input-'+form_name).value;
	document.getElementById('form_menu_description_input-'+form_name).disabled = true;
	document.getElementById('form_menu_save_btn-'+form_name).disabled = true;
	$.get(app_path_webroot+'Design/set_form_name.php', { pid: pid, page: form_name, action: 'set_menu_name', menu_description: this_value },
		function(data) {
			var formArray = data.split("\n");
			var new_form = formArray[0];
			var new_form_menu = formArray[1];
			document.getElementById('formlabel-'+form_name).innerHTML = new_form_menu;
			document.getElementById('formlabel-'+form_name).style.display = '';
			document.getElementById('form_menu_description_input_span-'+form_name).style.display = 'none';
			document.getElementById('progress-'+form_name).style.visibility = 'hidden';
			document.getElementById('form_menu_description_input-'+form_name).disabled = false;
			document.getElementById('form_menu_save_btn-'+form_name).disabled = false;
			// Change menu label and reload page (if in development only)
			if (status == 0) {
				if (document.getElementById('form['+form_name+']') != null) {
					document.getElementById('form['+form_name+']').innerHTML = new_form_menu;
				}
				window.location.reload();
			}
		}
	);
}

// Open dialog to set up conditional invitation settings for this survey/event
function setUpConditionalInvites(survey_id, event_id, form) {
	// Set URL for ajax request
	var url = app_path_webroot+'Surveys/edit_conditional_schedule.php?pid='+pid+'&survey_id='+survey_id+'&event_id='+event_id;	
	// If longitudinal and event_id=0, then prompt user to select events first
	if (event_id == 0 && longitudinal) {
		automatedInvitesSelectEvent(survey_id, event_id, form);
		return;
	}	
	// Ajax request
	$.post(url, { action: 'view' },function(data){
		if (data == "0") { alert(woops); return; }
		// Set dialog title/content
		var json_data = jQuery.parseJSON(data);
		if (json_data.response == "0") { alert(woops); return; }
		var dialogId = 'popupSetUpCondInvites';
		initDialog(dialogId);
		var dialogOb = $('#'+dialogId);
		dialogOb.prop("title",json_data.popupTitle).html(json_data.popupContent);
		$('#'+dialogId+' #ssemail-'+survey_id+'-'+event_id).val( $('#'+dialogId+' #ssemail-'+survey_id+'-'+event_id).val().replace(/<br\s*[\/]?>\s?/gi,"\n") ); // add line breaks back to message
		initWidgets();
		// Open dialog
		dialogOb.dialog({ bgiframe: true, modal: true, width: 850, buttons: [
			{ text: "Cancel", click: function () { $(this).dialog('destroy'); } },
			{ text: "Save", click: function () {
				// Check values and save via ajax
				saveCondInviteSetup(survey_id,event_id,form);
			}
		}] });
	});
}

// Auto survey invites: save settings via ajax
function saveCondInviteSetup(survey_id,event_id,form) {
	// Set survey_id-event_id pair
	var se_id = survey_id+'-'+event_id;
	// Set initial values
	$('#sscondlogic-'+se_id).val( trim($('#sscondlogic-'+se_id).val()) );
	$('#sssubj-'+se_id).val( trim($('#sssubj-'+se_id).val()) );
	$('#ssemail-'+se_id).val( trim($('#ssemail-'+se_id).val()) );
	var condition_send_time_option = $('input[name="sscondwhen-'+se_id+'"]:checked').val();
	var condition_send_time_exact = '';
	var condition_surveycomplete_survey_id = '';
	var condition_surveycomplete_event_id = '';
	var condition_andor = '';
	var condition_logic = '';
	var condition_send_next_day_type = '';
	var condition_send_next_time = '';
	var condition_send_time_lag_days = '';
	var condition_send_time_lag_hours = '';
	var condition_send_time_lag_minutes = '';
	var condition_andor = $('#sscondoption-andor-'+se_id).val();
	// Error checking to make sure all elements in row have been set
	if ($('#sssubj-'+se_id).val() == '' || $('#ssemail-'+se_id).val() == '') {
		simpleDialog('Please specify both a subject and message for the email.');
		return;
	}
	if (!$('#sscondoption-surveycomplete-'+se_id).prop('checked') && !$('#sscondoption-logic-'+se_id).prop('checked')) {
		simpleDialog('Please specify a condition for sending the invitations.');
		return;
	} else if ($('#sscondoption-logic-'+se_id).prop('checked') && $('#sscondlogic-'+se_id).val() == '') {
		simpleDialog('Please specify the conditional logic in the text box, or else uncheck its checkbox.');
		return;
	}
	if (condition_send_time_option == null) {
		simpleDialog('Please specify the time when to send invitations after the conditions are met.');
		return;
	} else if (condition_send_time_option == 'NEXT_OCCURRENCE' &&
		($('#sscond-nextdaytype-'+se_id).val() == '' || $('#sscond-nexttime-'+se_id).val() == '')) {
		simpleDialog('Please specify next day and time to send the survey invitations.');
		return;	
	} else if (condition_send_time_option == 'TIME_LAG' &&
		$('#sscond-timelagdays-'+se_id).val() == '' && $('#sscond-timelaghours-'+se_id).val() == '' && $('#sscond-timelagminutes-'+se_id).val() == '') {
		simpleDialog('Please specify the lapse of time after which to send the survey invitations.');
		return;
	} else if (condition_send_time_option == 'EXACT_TIME' && $('#ssdt-'+se_id).val() == '') {
		simpleDialog('Please specify the exact date/time to send the survey invitations.');
		return;
	}
	if ($('input[name="ssactive-'+se_id+'"]:checked').val() == null) {
		simpleDialog('Please set the automatic invitations for this survey as either Active or Not Active.');
		return;
	}
	
	// Collect values needed for ajax save
	if ($('#sscondoption-surveycomplete-'+se_id).prop('checked')) {
		var condSurvEvtIds = $('#sscondoption-surveycompleteids-'+se_id).val().split('-');
		condition_surveycomplete_survey_id = condSurvEvtIds[0];
		condition_surveycomplete_event_id = condSurvEvtIds[1];
	}
	if ($('#sscondoption-logic-'+se_id).prop('checked')) {
		condition_logic = $('#sscondlogic-'+se_id).val();
	}
	if (condition_send_time_option == 'NEXT_OCCURRENCE') {
		condition_send_next_day_type = $('#sscond-nextdaytype-'+se_id).val();
		condition_send_next_time = $('#sscond-nexttime-'+se_id).val();
	} else if (condition_send_time_option == 'TIME_LAG') {
		condition_send_time_lag_days = ($('#sscond-timelagdays-'+se_id).val() == '') ? '0' : $('#sscond-timelagdays-'+se_id).val();
		condition_send_time_lag_hours = ($('#sscond-timelaghours-'+se_id).val() == '') ? '0' : $('#sscond-timelaghours-'+se_id).val();
		condition_send_time_lag_minutes = ($('#sscond-timelagminutes-'+se_id).val() == '') ? '0' : $('#sscond-timelagminutes-'+se_id).val();
	} else if (condition_send_time_option == 'EXACT_TIME') {
		condition_send_time_exact = ($('#ssdt-'+se_id).val() == '') ? '' : $('#ssdt-'+se_id).val();
	}	
	var active = ($('input[name="ssactive-'+se_id+'"]:checked').val() == '0') ? '0' : '1';
	
	// Save via ajax
	$.post(app_path_webroot+'Surveys/edit_conditional_schedule.php?pid='+pid+'&event_id='+event_id+'&survey_id='+survey_id, { 
		action: 'save', email_subject: $('#sssubj-'+se_id).val(), email_content: $('#ssemail-'+se_id).val(), 
		email_sender: $('#email_sender').val(), active: active,
		condition_send_time_exact: condition_send_time_exact, condition_surveycomplete_survey_id: condition_surveycomplete_survey_id,
		condition_surveycomplete_event_id: condition_surveycomplete_event_id, condition_logic: condition_logic,
		condition_send_time_option: condition_send_time_option, condition_send_next_day_type: condition_send_next_day_type,
		condition_send_next_time: condition_send_next_time, condition_send_time_lag_days: condition_send_time_lag_days,
		condition_send_time_lag_hours: condition_send_time_lag_hours, condition_send_time_lag_minutes: condition_send_time_lag_minutes,
		condition_andor: condition_andor
		}, function(data){
		var json_data = jQuery.parseJSON(data);
		if (json_data.response == '1') {
			// Hide dialog (if displayed)
			$('#popupSetUpCondInvites').dialog('destroy');
			// Display popup (if specified)
			if (json_data.popupContent.length > 0) {
				// Set the onclose javascript to reload the event list for longitudinal projects
				var oncloseJS = (longitudinal) ? "$('#autoInviteBtn-"+form+"').click();" : "";
				// Simple dialog to display confirmation
				simpleDialog(json_data.popupContent,json_data.popupTitle,null,600,oncloseJS);
			}
		} else {
			// Error
			alert(woops);
		}
	});
}

// When click Automated Invite button for longitudinal projects, open pop-up box to list events to choose from
function automatedInvitesSelectEvent(survey_id,event_id,form) {
	// Set popup object
	var popup = $('#choose_event_div');
	// Redisplay "loading" text and remove any exist events listed from previous opening
	$('#choose_event_div_loading').show();
	$('#choose_event_div_list').html('').hide();
	// Make user pop-up appear
	popup.hide();
	// Determine where to put the box and then display it
	var cell = $('#'+form+'-btns').parent().parent();
	var cellpos = cell.offset();
	popup.css({ 'left': cellpos.left - (popup.outerWidth(true) - cell.outerWidth(true))/2 - 50, 
				'top': cellpos.top + cell.outerHeight(true) - 6 });
	popup.fadeIn('slow');
	// Get pop-up content via ajax before displaying
	$.post(app_path_webroot+'Design/get_events_auto_invites_for_form.php?pid='+pid+'&page='+form+'&survey_id='+survey_id,{ },function(data){
		// Add response data to div
		$('#choose_event_div_loading').hide();
		$('#choose_event_div_list').html(data);
		initWidgets();
		$('#choose_event_div_list').show();
	});
}

// Rename selected data entry form on Design page
function setupRenameForm(form) {
	document.getElementById('formlabel-'+form).style.display = 'none';
	document.getElementById('form_menu_description_input_span-'+form).style.display = '';
	setCaretToEnd(document.getElementById('form_menu_description_input-'+form));
}