<?php
    $starttime = microtime();
    define('FULLME', 'cron');

    $nomoodlecookie = true;

    require_once(dirname(__FILE__) . '/../config.php');
    require_once($CFG->libdir.'/adminlib.php');

/// from restore.php
    require_once("$CFG->dirroot/lib/xmlize.php");
    require_once("$CFG->dirroot/course/lib.php");
    require_once("$CFG->dirroot/backup/lib.php");
    require_once("$CFG->dirroot/backup/restorelib.php");
    require_once("$CFG->dirroot/backup/bb/restore_bb.php");
    require_once("$CFG->libdir/blocklib.php");
    require_once("$CFG->libdir/wiki_to_markdown.php" );

/// extra safety
    @session_write_close();

/// emulate normal session
    $SESSION = new object();
    $USER = get_admin();      /// Temporarily, to provide environment for this script

/// ignore admins timezone, language and locale - use site deafult instead!
    $USER->timezone = $CFG->timezone;
    $USER->lang = '';
    $USER->theme = '';
    course_setup(SITEID);

/// send mime type and encoding
    //send proper plaintext header
    @header('Content-Type: text/plain; charset=utf-8');

/// no more headers and buffers
    while(@ob_end_flush());

/// increase memory limit (PHP 5.2 does different calculation, we need more memory now)
    @raise_memory_limit('128M');

/// Start output log

    $timenow  = time();

    mtrace("Server Time: ".date('r',$timenow)."\n\n");

    $file = $_GET['file'];
    $firstname = $_GET['firstname'];
    $lastname = $_GET['lastname'];
    $username = $_GET['username'];
    $pass = $_GET['pass'];
    $email = $_GET['email'];

    if ($user = get_record('user', 'username', $username)) {
        echo "User $username exists\n";
    } else { // User does not exist, create it
        echo "User $username does not exist, creating it.\n";
        $user = new object();
        //[id] => 9
        $user->auth = 'manual';
        $user->confirmed = 1;
        $user->policyagreed = 0;
        $user->deleted = 0;
        $user->mnethostid = $CFG->mnet_localhost_id; // always local user
        //$user->mnethostid = 4;
        $user->username = trim($username);
        $user->password = $pass;
        $user->firstname = $firstname;
        $user->lastname = $lastname;
        $user->email = $email;
        $user->emailstop = 0;
        //$user->city = Portland;
        $user->country = 'US';
        $user->lang = 'en_utf8';
        $user->timezone = '99';
        $user->firstaccess = 0;
        //$user->lastaccess = 1198029349;
        $user->lastlogin = 0;
        //$user->currentlogin = 1197935184;
        //$user->lastip = 140.211.18.56;
        $user->picture = 0;
        $user->mailformat = 1;
        $user->maildigest = 0;
        $user->maildisplay = 1;
        $user->htmleditor = 1;
        $user->ajax = 1;
        $user->autosubscribe = 1;
        $user->trackforums = 0;
        //$user->timemodified = 1196895874;
        $user->trustbitmask = 0;
        $user->screenreader = 0;

        // Create user in Moodle database
        if (!$user->id = insert_record('user', $user)) {
            error('Error creating user record!');
        }

        // Set user's role to be "course creator"
        $userid = $user->id;
        $newra = new object;
        //$roleid = get_record('roles', 'shortname', 'coursecreator');
        $roleid = '2';
        $newra->roleid = $roleid;
        $newra->contextid = '1';
        $newra->userid = $userid;
        $newra->hidden = '0';
        $newra->enrol = 'manual';
        $contextid = '1';
        $timestart = '0';
        $timeend = '0';
        $hidden = '0';
        $success = insert_record('role_assignments', $newra);
        $success = role_assign($roleid, $userid, 0, $contextid, $timestart, $timeend, $hidden);
    }


    $backup_unique_code = time();
    $restore_restoreto = '2';
    $restore->restore_restoreto = $restore_restoreto;
    $restore->backup_unique_code = $backup_unique_code;

    //echo "Precheck: $backup_unique_code\n";
    //require_once ("$CFG->dirroot/backup/restore_precheck.html");
    $errorstr = '';
    //$status = restore_precheck($id,$file,$errorstr);
    // Clear out old files
    $status = backup_delete_old_data();
    if (!$status) 
    { 
        echo "backup_delete_old_data failed!\nstatus: $status\n";
        return false;
    }
    //Now check and create the backup dir (if it doesn't exist)
    $status = check_and_create_backup_dir($backup_unique_code);
    //Empty dir
    if ($status) {
        $status = clear_backup_dir($backup_unique_code);
    }
    // Copy zip file to dataroot/temp/backup/backup_unique_code
    $srcfile = $CFG->dataroot."/../".$file;
    //$srcfile = "/var/www/vhosts/moodledata/".$file;
    echo "srcfile: $srcfile\n";
    $dest = $CFG->dataroot."/temp/backup/".$backup_unique_code."/".basename($file);
    $status = backup_copy_file($srcfile,$dest);
    if (!$status) 
    {
        echo "backup_copy_file failed!\nstatus: $status\n";
        return false;
    }
    // Unzip backup
    $status = restore_unzip($CFG->dataroot."/temp/backup/".$backup_unique_code."/".basename($file));
    if (!$status)
    {
        echo "restore_unzip failed!\nstatus: $status\n";
        return false;
    }
    // Get the XML file
    $xml_file  = $CFG->dataroot."/temp/backup/".$backup_unique_code."/moodle.xml";
    $info = "";
    $course_header = "";
    //Now read the info tag (all)
    $info = restore_read_xml_info ($xml_file);
    $course_header = restore_read_xml_course_header ($xml_file);
    // Save header and info to SESSION
    $SESSION->info = $info;
    $SESSION->course_header = $course_header;
    //print_r ($SESSION);
    //echo "Check: ";
    $currentcourseshortname = $course_header->course_shortname; //"store_ShortName";
	$currentcoursestartdate = $course_header->course_startdate;
	$coursestartdatedateoffset = time() - $currentcoursestartdate;
	$restore->course_startdateoffset = $coursestartdatedateoffset; //change to restore
        if ($allmods = get_records("modules")) {
            foreach ($allmods as $mod) {
                $modname = $mod->name;
                $var = "restore_".$modname;
                $$var = optional_param( $var,0);
                $var = "restore_user_info_".$modname;
                $$var = optional_param( $var,0);
                $instances = !empty($info->mods[$mod->name]->instances) ? $info->mods[$mod->name]->instances : NULL;
                if ($instances === NULL) {
                    continue;
                }
                foreach ($instances as $instance) {
                    $var = 'restore_'.$modname.'_instance_'.$instance->id;
                    $$var = optional_param($var,0);
                    $var = 'restore_user_info_'.$modname.'_instance_'.$instance->id;
                    $$var = optional_param($var,0);
                }
            }
        }
        //restoreto
        $restore_restoreto = '2';
        //restore_metacourse
        $restore_metacourse = '0';
        //restore_users
        $restore_users = '1';
        //restore_logs
        $restore_logs = '0';
        //restore_user_files
        $restore_user_files = '1';
        //restore_course_files
        $restore_course_files = '1';
        //restore_messages
        $restore_messages = '0';
        $restore->file = $file;
        if ($allmods = get_records("modules")) {
            foreach ($allmods as $mod) {
                $modname = $mod->name;
                $var = "restore_".$modname;
                $$var = 1;
                $restore->mods[$modname]->restore=$$var;
                $var = "restore_user_info_".$modname;
                $$var = 1;
                $restore->mods[$modname]->userinfo=$$var;
                $instances = !empty($info->mods[$mod->name]->instances) ? $info->mods[$mod->name]->instances : NULL;
                if ($instances === NULL) {
                    continue;
                }
                foreach ($instances as $instance) {
                    $var = 'restore_'.$modname.'_instance_'.$instance->id;
                    $$var = 1;
                    $restore->mods[$modname]->instances[$instance->id]->restore = $$var;
                    $var = 'restore_user_info_'.$modname.'_instance_'.$instance->id;
                    $$var = 1;
                    $restore->mods[$modname]->instances[$instance->id]->userinfo = $$var;
                }
            }
        }
        $restore->restoreto=$restore_restoreto;
        $restore->metacourse=$restore_metacourse;
        $restore->users=$restore_users;
        $restore->logs=$restore_logs;
        $restore->user_files=$restore_user_files;
        $restore->course_files=$restore_course_files;
        $restore->messages=$restore_messages;
        $restore->course_id=$course_id;
        //add new vars to restore object 
        $restore->course_startdateoffset = $coursestartdatedateoffset;
        $restore->course_shortname = $currentcourseshortname;

        // default role mapping for moodle < 1.7
        if ($defaultteacheredit = optional_param('defaultteacheredit', 0, PARAM_INT)) {
            $restore->rolesmapping['defaultteacheredit'] = $defaultteacheredit;
        }
        if ($defaultteacher = optional_param('defaultteacher', 0, PARAM_INT)) {
            $restore->rolesmapping['defaultteacher'] = $defaultteacher;
        }
        if ($defaultstudent = optional_param('defaultstudent', 0, PARAM_INT)) {
            $restore->rolesmapping['defaultstudent'] = $defaultstudent;
        }

    $restore->restore_restorecatto = '0';

    //We're creating a new course
    $restore->deleting = 0;

    // Save the $restore object in the session
    $SESSION->restore = $restore;
    
    $xml_file = $CFG->dataroot."/temp/backup/".$backup_unique_code."/moodle.xml";
    //restore_setup_for_check($SESSION->restore,$backup_unique_code);
    //echo "Execute: ";
    //$info = $SESSION->info;
    //$course_header = $SESSION->course_header;
    $restore->original_wwwroot = $info->original_wwwroot;
    $restore->backup_version = $info->backup_backup_version;
    $errorstr = '';
    $oldidnumber = $course_header->course_idnumber;
    $oldcoursefullname = $course_header->course_fullname;
    if ($lastname) {
        $newfullname = $course_header->course_fullname." - ".$lastname;
        $course_header->course_fullname = $newfullname;
        $newshortname = $course_header->course_shortname."-".$lastname;
        $course_header->course_shortname = $newshortname;
    }
    
    // Debug
    //echo '$restore: \n';
    //print_r($restore);
    //echo '\n\n';

    restore_execute($restore,$info,$course_header,$errorstr);

    $course_id = $course_header->course_id;
    echo "***** course_id $course_id *****\n";

    // Assign proper role to requesting user
    $user = get_record('user', 'username', $username);
    //print_r ($user);
    $userid = $user->id;

    //$course_id = "259";
    #$teacher_role = get_record("role", "shortname", "editingteacher");
    $teacher_role->id = '3';
    $roleid = $teacher_role->id;
    $context = get_record("context", "instanceid", $course_id);
    $contextid = $context->id;
    $timestart = '0';
    $timeend = '0';
    $hidden = '0';

    // Assign $id as an editing teacher to $course_id
    if (!$course = get_record('course', 'id', $course_id)) {
        error('Invalid course id');
    }
    $context = get_record('context', 'contextlevel', '50', 'instanceid', $course_id); 
    $contextid = $context->id;
    if (! role_assign($roleid, $userid, 0, $contextid, $timestart, $timeend, $hidden)) {
        echo "Could not add user with id $userid to this role\n";
    }

    if (!$ra = get_record('role_assignments', 'roleid', $roleid, 'contextid', $contextid, 'userid', $userid)) {
        echo "$userid not found in $contextid ... adding new entry\n";
        $newra = new object;
        $newra->roleid = $roleid;
        $newra->contextid = $contextid;
        $newra->userid = $userid;
        $newra->hidden = '0';
        $newra->enrol = 'manual';
        $timestart = '0';
        $timeend = '0';
        $hidden = '0';
        $success = insert_record('role_assignments', $newra);
	$success = role_assign($roleid, $userid, 0, $contextid, $timestart, $timeend, $hidden);
    } else {
        echo "$userid found in $contextid with $roleid ... updating entry\n";
        $newra1 = new object;
        $newra1->id = $ra->id;
        $newra1->hidden = '0';
        $newra1->enrol = 'manual';
        $newra->roleid = '3';
        $success = update_record('role_assignments', $newra1);
    }

    if ($success) {
        echo "User $userid successfully added to course\n";
        //$check =  get_record('role_assignments', 'roleid', $roleid, 'contextid', $contextid, 'userid', $userid);
        //print_r ($check);
    }




    $URL = $CFG->wwwroot . "/course/view.php?id=" . $course_id;
    echo "$URL\n";

    //Unset session variables and destroy it
    @session_unset();
    @session_destroy();
    //mtrace("Unattended course creation script completed correctly");

    $difftime = microtime_diff($starttime, microtime());
    //mtrace("Execution took ".$difftime." seconds");

	return $URL;    

?>
