<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.


/**
 * This is the file that contains all the upgrade code
 *
 * @package    block
 * @subpackage ajax_marking
 * @copyright  2008 Matt Gibson
 * @author     Matt Gibson {@link http://moodle.org/user/view.php?id=81450}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Include constants.
global $CFG;
require_once("{$CFG->dirroot}/blocks/ajax_marking/lib.php");

/**
 * Standard upgrade function run every time the block's version number changes
 *
 * @global moodle_database $DB
 * @param int $oldversion the current version of the installed block
 * @return bool
 */
function xmldb_block_ajax_marking_upgrade($oldversion = 0) {

    global $DB;

    $dbman = $DB->get_manager();

    // TODO make this only happen on the 2.0 upgrade
    // 1.9 latest version 2010101301
    // actual point where the code was written: 2010061801 (2.0
    // This should trigger on any version of 1.9 now, provided 1.9 is not changed again.
    // If people have already installed 2.0 and the upgrade didn't work...
    // If they installed 2.0 and the upgrade did work?

    if ($oldversion < 2010101302) {

        // Define key useridkey (foreign) to be dropped from block_ajax_marking.
        $table = new xmldb_table('block_ajax_marking');
        $key = new xmldb_key('useridkey', XMLDB_KEY_FOREIGN, array('userid'), 'user', array('id'));
        $dbman->drop_key($table, $key);

        // Define table block_ajax_marking_groups to be created.
        $table = new xmldb_table('block_ajax_marking_groups');

        // Adding fields to table block_ajax_marking_groups.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL,
                          XMLDB_SEQUENCE, null, null);
        $table->add_field('configid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null,
                          null, null);
        $table->add_field('groupid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null,
                          null, null);
        $table->add_field('display', XMLDB_TYPE_INTEGER, '1', XMLDB_UNSIGNED, XMLDB_NOTNULL, null,
                          1, null);

        // Adding keys to table block_ajax_marking_groups.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_key('configid-id', XMLDB_KEY_FOREIGN, array('configid'), 'block_ajax_marking',
                        array('id'));

        // Launch create table for block_ajax_marking_groups.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);

            // Transfer all groups stuff to the new table.
            $sql = "SELECT id, groups FROM {block_ajax_marking}";
            $oldrecords = $DB->get_records_sql($sql);

            foreach ($oldrecords as $record) {

                // Expand the csv groups from the groups column.
                if (!empty($record->groups)) {
                    $groups = explode(' ', trim($record->groups));

                    foreach ($groups as $group) {
                        $data = new stdClass;
                        $data->groupid = $group;
                        $data->configid = $record->id;
                        $DB->insert_record('block_ajax_marking_groups', $data);
                    }
                }
            }

            // Drop the groups column on the old table.

            // Define field groups to be dropped from block_ajax_marking.
            drop_groups_field();
        }

        upgrade_block_savepoint(true, 2010101302, 'ajax_marking');
    }

    if ($oldversion < 2011040602) {

        // Remove the module settings from the config_plugins table.
        $DB->delete_records('config_plugins', array('plugin' => 'block_ajax_marking'));

        // Remove the display column from the groups table - not needed.
        $table = new xmldb_table('block_ajax_marking_groups');
        $field = new xmldb_field('display');

        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        // Put key in for groupid-id.
        $table = new xmldb_table('block_ajax_marking_groups');
        $key = new xmldb_key('groupid-id', XMLDB_KEY_FOREIGN, array('groupid'), 'groups',
            array('id'));
        $dbman->add_key($table, $key);

        // Put key back for userid.
        $table = new xmldb_table('block_ajax_marking');
        $key = new xmldb_key('useridkey', XMLDB_KEY_FOREIGN, array('userid'), 'user', array('id'));
        $dbman->add_key($table, $key);

        upgrade_block_savepoint(true, 2011040602, 'ajax_marking');
    }

    // Alter table structure ready for coursemodule stuff.
    if ($oldversion < 2011052301) {

        // Define field groups to be dropped from block_ajax_marking.
        drop_groups_field();

        $field = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null, null,
                                 '0', 'userid');

        // Conditionally launch add field courseid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('coursemoduleid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null,
                                 null, '0', 'courseid');

        // Conditionally launch add field coursemoduleid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('showhide', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null,
                                 '1', 'assessmentid');

        // Launch rename field showhide.
        $dbman->rename_field($table, $field, 'display');

        // Ajax_marking savepoint reached.
        upgrade_block_savepoint(true, 2011052301, 'ajax_marking');
    }

    // Put all coursemodule things into place so that data is in both places.
    if ($oldversion < 2011052303) {

        $modules = array(
            'assignment',
            'forum',
            'quiz',
            'workshop',
            'journal'
        );

        // Switch the table to using coursemodule id instead of modulename + instance id in
        // different columns. Allows for cleaner joins.
        foreach ($modules as $module) {
            // Get all current coursemodules and put their ids into place.
            $sql = "SELECT b.id, course_modules.id AS cmid
                      FROM {block_ajax_marking} b
                INNER JOIN {course_modules} course_modules
                        ON course_modules.instance = b.assessmentid
                INNER JOIN {modules} modules
                        ON (course_modules.module = modules.id
                       AND " . $DB->sql_compare_text('modules.name') . " = '{$module}')
                     WHERE b.assessmenttype = '{$module}'
                        ";
            $modids = $DB->get_records_sql($sql, array());

            foreach ($modids as $modid) {
                $row = new stdClass();
                $row->id = $modid->id;
                $row->coursemoduleid = $modid->cmid;
                $DB->update_record('block_ajax_marking', $row);
            }
        }
        // Ajax_marking savepoint reached.
        upgrade_block_savepoint(true, 2011052303, 'ajax_marking');
    }

    if ($oldversion < 2011111800) {

        change_config_to_courseid();

        // Ajax_marking savepoint reached.
        upgrade_block_savepoint(true, 2011111800, 'ajax_marking');

    }

    // Add new bits for holding groups display settings.
    if ($oldversion < 2011112300) {

        add_groups_display_field();

        upgrade_block_savepoint(true, 2011112300, 'ajax_marking');

    }

    // Add new bits for holding groups display settings per group.
    if ($oldversion < 2011112301) {

        // Add a new field for showing whether each group should be displayed. Allows
        // override of 'show this group' that may have been set at course level.
        add_display_field();

        upgrade_block_savepoint(true, 2011112301, 'ajax_marking');

    }

    // This is also at 2011112300, but due to a bug in 2.1.1, where the xml table definition didn't
    // include this field, some people installed, didn't get the field, and then found that
    // subsequent upgrades didn't add it. Duplicating it here adds it for anyone who's missing
    // it.
    if ($oldversion < 2011112505) {

        add_groups_display_field();

        upgrade_block_savepoint(true, 2011112505, 'ajax_marking');

    }

    // Other parts of the xmldb upgrade that were missed as well. Need to takes care to make this
    // conditional as it only applies to a small number of people.
    if ($oldversion < 2011112506) {

        $table = new xmldb_table('block_ajax_marking');
        // Is this an install that was only partially upgraded?
        $field = new xmldb_field('instanceid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null,
                                 null, '0', 'userid');
        // Conditionally rerun the previous bit if the field it was supposed to have is missing.
        if (!$dbman->field_exists($table, $field)) {
            change_config_to_courseid();
        }

        // Some sites missed this.
        add_display_field();

        // Ajax_marking savepoint reached.
        upgrade_block_savepoint(true, 2011112506, 'ajax_marking');

    }

    // Allow all settings to be null in case we want to have site level defaults.
    if ($oldversion < 2011112507) {

        $table = new xmldb_table('block_ajax_marking');
        $field = new xmldb_field('display', XMLDB_TYPE_INTEGER, '1', XMLDB_UNSIGNED, null,
                                 null, '1', 'tablename');

        $dbman->change_field_type($table, $field);

        upgrade_block_savepoint(true, 2011112507, 'ajax_marking');

    }

    // We need to flag whether people who do not have any group memberships should be displayed in
    // an 'orphans' node if others are being shown in their group nodes or whether they ought to
    // be hidden.
    if ($oldversion < 2012020200) {

        // Define field showorphans to be added to block_ajax_marking.
        $table = new xmldb_table('block_ajax_marking');
        $field = new xmldb_field('showorphans', XMLDB_TYPE_INTEGER, '1', XMLDB_UNSIGNED,
                                 XMLDB_NOTNULL, null, '1', 'groupsdisplay');

        // Conditionally launch add field showorphans.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Ajax_marking savepoint reached.
        upgrade_block_savepoint(true, 2012020200, 'ajax_marking');
    }

    return true;
}

/**
 * Removes the 'groups' field from the main settings table, so it can be made into a separate
 * join table.
 */
function drop_groups_field() {

    global $DB;

    $dbman = $DB->get_manager();

    $table = new xmldb_table('block_ajax_marking');
    $field = new xmldb_field('groups');

    // Launch drop field groups.
    if ($dbman->field_exists($table, $field)) {
        $dbman->drop_field($table, $field);
    }
}

/**
 * Add a new field for showing whether each group should be displayed. Allows override of.
 */
function add_display_field() {

    global $DB;

    $dbman = $DB->get_manager();

    // Show this group that may have been set at course level.
    $table = new xmldb_table('block_ajax_marking_groups');
    $field = new xmldb_field('display', XMLDB_TYPE_INTEGER, '1', XMLDB_UNSIGNED, null,
                             null, '0', 'groupid');
    // Conditionally launch add field.
    if (!$dbman->field_exists($table, $field)) {
        $dbman->add_field($table, $field);
    }
}

/**
 * Add a new field for showing whether groups should be displayed.
 */
function add_groups_display_field() {

    global $DB;

    $dbman = $DB->get_manager();

    $table = new xmldb_table('block_ajax_marking');
    $field = new xmldb_field('groupsdisplay', XMLDB_TYPE_INTEGER, '1', XMLDB_UNSIGNED, null,
                             null, '0', 'display');
    // Conditionally launch add field.
    if (!$dbman->field_exists($table, $field)) {
        $dbman->add_field($table, $field);
    }
}

/**
 * Alters the table to use coursemodule id instead of module name and module id. Separating due
 * to an xml probem that meant that some sites didn't upgrade cleanly and needed redoing.
 *
 * @return array
 */
function change_config_to_courseid() {

    global $DB;

    $dbman = $DB->get_manager();

    $existingrecords = $DB->get_records('block_ajax_marking');

    // Make the old columns go away.

    // Define field courseid to be dropped from block_ajax_marking.
    $table = new xmldb_table('block_ajax_marking');

    $fieldstodrop = array(
        'courseid',
        'coursemoduleid',
        'assessmenttype',
        'assessmentid'
    );

    foreach ($fieldstodrop as $fieldtodrop) {
        $field = new xmldb_field($fieldtodrop);
        // Conditionally launch drop field.
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }
    }

    // Add a new field for holding general ids from various tables.
    $field = new xmldb_field('instanceid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null,
                             null, '0', 'userid');
    // Conditionally launch add field.
    if (!$dbman->field_exists($table, $field)) {
        $dbman->add_field($table, $field);
    }

    // Add a new field for holding the table name.
    $field = new xmldb_field('tablename', XMLDB_TYPE_CHAR, '40', null, null, null,
                             null, 'instanceid');
    // Conditionally launch add field.
    if (!$dbman->field_exists($table, $field)) {
        $dbman->add_field($table, $field);
    }

    // Remove old data in the wrong format.
    $sql = "TRUNCATE TABLE {block_ajax_marking}";
    $DB->execute($sql);

    // Put the old data back.
    $modids = $DB->get_records('modules', array(), '', 'name, id');
    foreach ($existingrecords as $record) {
        $oldid = $record->id;
        unset($record->id);
        if (!empty($record->courseid)) {
            $record->instanceid = $record->courseid;
            $record->tablename = 'course';
        } else if (!empty($record->coursemoduleid)) {
            $record->instanceid = $record->coursemoduleid;
            $record->tablename = 'course_modules';
        } else {
            // Previous upgrade failed somehow.
            $cmid = $DB->get_field('course_modules',
                                   'id',
                                   array('module' => $modids[$record->assessmenttype]->id,
                                         'instance' => $record->assessmentid));
            $record->tablename = 'course_modules';
            $record->instanceid = $cmid;
        }

        $newid = $DB->insert_record('block_ajax_marking', $record);
        $sql = "UPDATE {block_ajax_marking_groups}
                       SET configid = :newid
                     WHERE configid = :oldid ";
        $DB->execute($sql,
                     array('oldid' => $oldid,
                           'newid' => $newid));
    }

}
