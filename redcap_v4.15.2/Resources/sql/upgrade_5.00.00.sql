-- Set all projects with surveys_enabled=2 to a value of 1
update redcap_projects set surveys_enabled = 1 where surveys_enabled > 0;