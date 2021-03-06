<?PHP
///////////////////////////////////////////////////////////////////////////
//                                                                       //
// Moodle configuration file                                             //
//                                                                       //
// This file should be renamed "config.php" in the top-level directory   //
//                                                                       //
///////////////////////////////////////////////////////////////////////////
//                                                                       //
// NOTICE OF COPYRIGHT                                                   //
//                                                                       //
// Moodle - Modular Object-Oriented Dynamic Learning Environment         //
//          http://moodle.org                                            //
//                                                                       //
// Copyright (C) 1999 onwards  Martin Dougiamas  http://moodle.com       //
//                                                                       //
// This program is free software; you can redistribute it and/or modify  //
// it under the terms of the GNU General Public License as published by  //
// the Free Software Foundation; either version 3 of the License, or     //
// (at your option) any later version.                                   //
//                                                                       //
// This program is distributed in the hope that it will be useful,       //
// but WITHOUT ANY WARRANTY; without even the implied warranty of        //
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the         //
// GNU General Public License for more details:                          //
//                                                                       //
//          http://www.gnu.org/copyleft/gpl.html                         //
//                                                                       //
///////////////////////////////////////////////////////////////////////////
unset($CFG);  // Ignore this line
global $CFG;  // This is necessary here for PHPUnit execution
$CFG = new stdClass();

//=========================================================================
// 1. ORVSD CONFIG
//=========================================================================
// Include relevant configuration from glusterfs mount.
$orvsdcwd = explode("/", getcwd());
$orvsduser = $orvsdcwd[3];
$orvsdfqdn = $orvsdcwd[5];
require_once('/data/moodledata/' . $orvsduser . '/moodle22/' . $orvsdfqdn . '/config.php');

// Enable when using external SSL appliance for performance reasons.
// Please note that site may be accessible via https: or https:, but not both!
$CFG->sslproxy = true;

// Now you need to tell Moodle where it is located. Specify the full
// web address to where moodle has been installed.
$CFG->wwwroot   = 'https://' . $orvsdfqdn;
$CFG->dataroot  = '/data/moodledata/' . $orvsduser . '/moodle22/' . $orvsdfqdn;
$CFG->directorypermissions = 02770;

//=========================================================================
// ALL DONE!  To continue installation, visit your main page with a browser
//=========================================================================

require_once(dirname(__FILE__) . '/lib/setup.php'); // Do not edit

// There is no php closing tag in this file,
// it is intentional because it prevents trailing whitespace problems!
