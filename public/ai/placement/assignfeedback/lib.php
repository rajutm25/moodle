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
 * Library functions
 *
 * @package    aiplacement_assignfeedback
 * @copyright  2025 Raju Thummoji <rajutummoji@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


function aiplacement_assignfeedback_fetch_assignment_details(\context $context) {
    global $CFG,$DB;

    require_once($CFG->dirroot . '/mod/assign/locallib.php');

    $returnarray=array();

    if ($context->contextlevel == CONTEXT_MODULE) {
        $cm = get_coursemodule_from_id('', $context->instanceid, 0, false, MUST_EXIST);
        if ($cm->modname === 'assign') {

            $assignrecord = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);
            $assignname = $assignrecord->name;

            $gradeitem = $DB->get_record('grade_items', [
                'iteminstance' => $assignrecord->id,
                'itemmodule' => 'assign',
                'courseid' => $cm->course
            ], 'grademax');
            $maxgrade = $gradeitem->grademax ?? null;

            $sql = "SELECT 1
                    FROM {assign_plugin_config}
                    WHERE assignment = :assignment
                    AND " . $DB->sql_compare_text('plugin') . " = :plugin
                    AND " . $DB->sql_compare_text('subtype') . " = :subtype
                    AND name = :name
                    AND value = :value";

            $params = [
                'assignment' => $assignrecord->id,
                'plugin' => 'onlinetext',
                'subtype' => 'assignsubmission',
                'name' => 'enabled',
                'value' => 1
            ];

            $plugin_enabled = $DB->record_exists_sql($sql, $params);

            $returnarray['moduleinstanceid']=$cm->instance;
            $returnarray['assignment']=$assignname;
            $returnarray['maxgrade']=$maxgrade;
            $returnarray['onlinetext_enabled']=$plugin_enabled ? 1 : 0;

        } 
    }

    return $returnarray;

}
