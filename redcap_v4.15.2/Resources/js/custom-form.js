/*
CUSTOM FORM ELEMENTS

Created by Ryan Fait
www.ryanfait.com

The only things you may need to change in this file are the following
variables: checkboxHeight, radioHeight and selectWidth (lines 24, 25, 26)

The numbers you set for checkboxHeight and radioHeight should be one quarter
of the total height of the image want to use for checkboxes and radio
buttons. Both images should contain the four stages of both inputs stacked
on top of each other in this order: unchecked, unchecked-clicked, checked,
checked-clicked.

You may need to adjust your images a bit if there is a slight vertical
movement during the different stages of the button activation.

The value of selectWidth should be the width of your select list image.

http://ryanfait.com/resources/custom-checkboxes-and-radio-buttons/
*/

var checkboxHeight = "24";
var radioHeight = "24";


/* No need to change anything after this */


document.write('<style type="text/css">input.styled { display: none; }  .disabled { opacity: 0.5; filter: alpha(opacity=50); }</style>');

var Custom = {
	init: function() {
		var inputs = document.getElementsByTagName("input"), span = Array(), textnode, option, active;
		for(a = 0; a < inputs.length; a++) {
			if((inputs[a].type == "checkbox" || inputs[a].type == "radio") && inputs[a].className == "styled") {
				span[a] = document.createElement("span");
				span[a].className = inputs[a].type;

				if(inputs[a].checked == true) {
					if(inputs[a].type == "checkbox") {
						position = "0 -" + (checkboxHeight*2) + "px";
						span[a].style.backgroundPosition = position;
					} else {
						position = "0 -" + (radioHeight*2) + "px";
						span[a].style.backgroundPosition = position;
					}
				}
				inputs[a].parentNode.insertBefore(span[a], inputs[a]);
				inputs[a].onchange = Custom.clear;
				if(!inputs[a].getAttribute("disabled")) {
					span[a].onmousedown = Custom.pushed;
					span[a].onmouseup = Custom.check;
				} else {
					span[a].className = span[a].className += " disabled";
				}
			}
		}
		//using document.onlick instead of document.onmouseup. This seems to solve the problem of needing to click reset twice two clear all.
		document.onclick = Custom.clear; 
		
	},
	pushed: function() {
		element = this.nextSibling;
		if(element.checked == true && element.type == "checkbox") {
			this.style.backgroundPosition = "0 -" + checkboxHeight*3 + "px";
		} else if(element.checked == true && element.type == "radio") {
			this.style.backgroundPosition = "0 -" + radioHeight*3 + "px";
		} else if(element.checked != true && element.type == "checkbox") {
			this.style.backgroundPosition = "0 -" + checkboxHeight + "px";
		} else {
			this.style.backgroundPosition = "0 -" + radioHeight + "px";
		}

		//Integrate REDCap's treatment of onclick for radio buttons and checkboxes. This preserves the skip patterns.
		
		// <input type="radio" name="alc_bev_last_year___radio" 
		// onclick="document.forms['form'].alc_bev_last_year.value=this.value;setTimeout(function(){doBranching();},50);" value="1" class="styled">
		// <input type="radio" name="alc_bev_last_year___radio" 
		// onclick="document.forms['form'].alc_bev_last_year.value=this.value;setTimeout(function(){doBranching();},50);" value="2" class="styled">
		if (element.type =="radio") {
			var radioname=element.name.replace("___radio","");
			document.getElementsByName(radioname)[0].value=element.value;
			setTimeout(function(){doBranching();},50);
		}
		
		//<input type="checkbox" name="__chkn__type_alc_good" code="1" 
		//onclick="document.forms['form'].elements['__chk__type_alc_good_1'].value=(this.checked)?'1':'';calculate();doBranching();" class="styled">
		//<input type="checkbox" name="__chkn__type_alc_good" code="2"
		//onclick="document.forms['form'].elements['__chk__type_alc_good_2'].value=(this.checked)?'2':'';calculate();doBranching();" class="styled">	
		if (element.type =="checkbox") {
			var code = element.attributes['code'].value;
			var chk=element.name.replace("__chkn__","__chk__") + '_' + code;
			//alert(chk);
			document.forms['form'].elements[chk].value=(element.checked)?code:'';
			calculate();
			doBranching();
		}
	
	},
	check: function() {
		element = this.nextSibling;
		if(element.checked == true && element.type == "checkbox") {
			this.style.backgroundPosition = "0 0";
			element.checked = false;
		} else {
			if(element.type == "checkbox") {
				this.style.backgroundPosition = "0 -" + checkboxHeight*2 + "px";
			} else {
				this.style.backgroundPosition = "0 -" + radioHeight*2 + "px";
				group = this.nextSibling.name;
				inputs = document.getElementsByTagName("input");
				for(a = 0; a < inputs.length; a++) {
					if(inputs[a].name == group && inputs[a] != this.nextSibling) {
						inputs[a].previousSibling.style.backgroundPosition = "0 0";
					}
				}
			}
			element.checked = true;
		}
	},
	clear: function() {
		inputs = document.getElementsByTagName("input");
		for(var b = 0; b < inputs.length; b++) {
			if(inputs[b].type == "checkbox" && inputs[b].checked == true && inputs[b].className == "styled") {
				inputs[b].previousSibling.style.backgroundPosition = "0 -" + checkboxHeight*2 + "px";
			} else if(inputs[b].type == "checkbox" && inputs[b].className == "styled") {
				inputs[b].previousSibling.style.backgroundPosition = "0 0";
			} else if(inputs[b].type == "radio" && inputs[b].checked == true && inputs[b].className == "styled") {
				inputs[b].previousSibling.style.backgroundPosition = "0 -" + radioHeight*2 + "px";
			} else if(inputs[b].type == "radio" && inputs[b].className == "styled") {
				inputs[b].previousSibling.style.backgroundPosition = "0 0";
			}
		}
	},

}
window.onload = Custom.init;