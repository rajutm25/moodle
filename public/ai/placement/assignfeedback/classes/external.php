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
 * External Web Service
 *
 * @package    aiplacement_assignfeedback
 * @copyright  2025 Raju Thummoji <rajutummoji@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_assignfeedback;

defined('MOODLE_INTERNAL') || die();

require_once("$CFG->libdir/externallib.php");

use aiplacement_assignfeedback\utils;

/**
 * This is the external API for this component.
 *
 * @package    aiplacement_assignfeedback
 * @copyright  2025 Raju Thummoji <rajutummoji@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class external extends \external_api {
    /**
     * Returns description of process_text parameters
     * @return \external_function_parameters
     */
    public static function process_text_parameters() {
        return new \external_function_parameters([
            'text' => new \external_value(PARAM_RAW, 'The text to process'),
            'action' => new \external_value(PARAM_ALPHA, 'Action to perform (feedback/grade/contentdetector)'),
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    /**
     * Process text with Moodle AI Provider
     * @param string $text The text to process
     * @param string $action The action to perform
     * @param int $courseid The course ID
     * @return array
     */
    public static function process_text($text, $action, $courseid) {
        global $USER;

        // Parameter validation.
        $params = self::validate_parameters(self::process_text_parameters(), [
            'text' => $text,
            'action' => $action,
            'courseid' => $courseid,
        ]);

        // Context validation.
        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);

        // Capability checks.
        if (!utils::is_assignfeedback_available($context)) {
            throw new \moodle_exception('notavailable', 'aiplacement_assignfeedback');
        }

        $capability = "aiplacement/assignfeedback:use{$action}";
        require_capability($capability, $context);

        // Prepare prompt based on action.
        switch ($params['action']) {
            case 'feedback':
                $prompt = "You are an experienced teaching assistant.\n" .
                        "Provide concise, constructive feedback for the following student assignment submission. " .
                        "Include strengths, improvements, and 2-3 actionable suggestions.\n" .
                        "Keep it polite and educational.\n\n" .
                        "SUBMISSION:\n{$params['text']}";

                $action = new \core_ai\aiactions\generate_text(
                    contextid: $context->id,
                    userid: $USER->id,
                    prompttext: $prompt,
                );
                break;
            case 'grade':
                $grade = 100; // dynamically calculated or fetched
                $prompt = "You are an experienced Moodle teacher.\n" .
                        "Provide a detailed, constructive grade out of 100 for the following student assignment submission. " .
                        "The suggested grade is {$grade}.\n" .
                        "Include a numeric or letter grade if applicable, along with explanations for strengths and areas of improvement.\n" .
                        "Keep it professional and educational.\n\n" .
                        "SUBMISSION:\n{$params['text']}";

                $action = new \core_ai\aiactions\generate_text(
                    contextid: $context->id,
                    userid: $USER->id,
                    prompttext: $prompt,
                );
                break;
            case 'contentdetector':
                $riskThreshold = 70; // example: AI content risk threshold
                $prompt = "You are an experienced Moodle teacher.\n" .
                        "Analyze the following student assignment submission for AI-generated content.\n" .
                        "If the likelihood of AI content is above {$riskThreshold}%, flag it clearly.\n" .
                        "Provide a detailed assessment, highlighting any suspicious sections and suggestions for improvement.\n\n" .
                        "SUBMISSION:\n{$params['text']}";

                $action = new \core_ai\aiactions\generate_text(
                    contextid: $context->id,
                    userid: $USER->id,
                    prompttext: $prompt,
                );
                break;
            default:
                throw new \moodle_exception('invalidaction', 'aiplacement_assignfeedback');
        }

        try {
            $manager = \core\di::get(\core_ai\manager::class);
            $response = $manager->process_action($action);
            if ($response->get_errorcode()) {
                throw new \moodle_exception('aierror', 'aiplacement_assignfeedback');
            }
            return [
                'result' => $response->get_response_data()['generatedcontent'] ?? '',
            ];
        } catch (\Throwable $e) {
            throw new \moodle_exception('aierror', 'aiplacement_assignfeedback', '', $e->getMessage());
        }
    }

    /**
     * Returns description of process_text return values
     * @return \external_single_structure
     */
    public static function process_text_returns() {
        return new \external_single_structure([
            'result' => new \external_value(PARAM_TEXT, 'The processed text result'),
        ]);
    }
}
