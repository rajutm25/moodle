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

namespace aiplacement_assignfeedback;

use core\hook\output\before_standard_head_html_generation;

/**
 * Hook callbacks for the Assignment Feedback placement
 *
 * @package    aiplacement_assignfeedback
 * @copyright  2025 Raju Thummoji <rajutummoji@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Bootstrap the course assist UI.
     *
     * @param before_footer_html_generation $hook
     */
    public static function before_standard_head_html_generation(before_standard_head_html_generation $hook): void {
        global $COURSE, $PAGE;
        $available = \aiplacement_assignfeedback\utils::is_assignfeedback_available($PAGE->context);
        if (!$available) {
            return; // No need to inject if the feature is not available.
        }
        if (
            $PAGE->context->contextlevel === CONTEXT_COURSE ||
            $PAGE->context->contextlevel === CONTEXT_MODULE
        ) {
            // Check capabilities.
            $capabilities = [
                'feedback' => has_capability('aiplacement/assignfeedback:usefeedback', $PAGE->context),
                'grade' => has_capability('aiplacement/assignfeedback:usegrade', $PAGE->context),
                'contentdetector' => has_capability('aiplacement/assignfeedback:usecontentdetector', $PAGE->context),
            ];
            // Only proceed if user has at least one capability.
            if (array_filter($capabilities)) {
                // Add required JavaScript.
                $PAGE->requires->jquery();
                $PAGE->requires->js_call_amd('aiplacement_assignfeedback/module', 'init', [
                    $COURSE->id,
                    $capabilities,
                ]);
                // Add required strings.
                $PAGE->requires->strings_for_js([
                    'feedback',
                    'grade',
                    'contentdetector',
                    'loading',
                    'error',
                    'poweredby',
                ], 'aiplacement_assignfeedback');
            }
        }
    }
}
