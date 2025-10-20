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
            'moduleid' => new \external_value(PARAM_INT, 'module ID'),
        ]);
    }

    /**
     * Process text with Moodle AI Provider
     * @param string $text The text to process
     * @param string $action The action to perform
     * @param int $moduleid The module ID
     * @return array
     */
    public static function process_text($text, $action, $moduleid) {
        global $USER,$OUTPUT;

        // Parameter validation.
        $params = self::validate_parameters(self::process_text_parameters(), [
            'text' => $text,
            'action' => $action,
            'moduleid' => $moduleid,
        ]);

        // Context validation.
        $context = \context_module::instance($params['moduleid']);
        self::validate_context($context);

        $assignment=utils::is_assignfeedback_available($context); 
        // Capability checks.
        if (!$assignment) {
            throw new \moodle_exception('notavailable', 'aiplacement_assignfeedback');
        }

        $capability = "aiplacement/assignfeedback:use{$action}";
        require_capability($capability, $context);

        // Prepare prompt based on action.
        switch ($params['action']) {
            case 'feedback':
                $prompt = "You are an experienced teaching assistant with a strong background in providing educational support and feedback to students. 

                            Your task is to provide concise, constructive feedback for the following student assignment submission. 

                            Here are the details of the assignment:  
                            - Assignment Title:{$assignment['assignment']}  
                            - Student Submission:{$params['text']}  

                            ---

                            The feedback should highlight the strengths of the submission, identify areas for improvement, and provide 2-3 actionable suggestions to enhance the student's work. 

                            ---

                            Please ensure your feedback is polite and educational in tone, aiming to encourage the student’s learning and growth.

                            ---

                            Constraints to keep in mind:  
                            - Avoid overly critical language; focus on constructive criticism.  
                            - Ensure that your suggestions are specific and feasible for the student to implement.  
                            - Maintain clarity and precision in your feedback to facilitate understanding.

                            ---

                            Example of feedback structure: 
                            - AI Feedback: [Title of content]
                            - Strengths: [Highlight what the student did well]  
                            - Improvements: [Identify areas that need work]  
                            - Suggestions: [List actionable steps for improvement]";

                $action = new \core_ai\aiactions\generate_text(
                    contextid: $context->id,
                    userid: $USER->id,
                    prompttext: $prompt,
                );
                break;
            case 'grade':
                $grade = $assignment['maxgrade']; // dynamically calculated or fetched
                $prompt = "You are an experienced Moodle teacher with extensive knowledge in evaluating student assignments and providing constructive feedback. 

                            Your task is to assess a student assignment submission and provide a detailed, constructive grade. The suggested grade for this assignment is {$grade}. 

                            ---

                            Please include a numeric or letter grade if applicable, along with thorough explanations highlighting the strengths of the submission and specific areas for improvement. Maintain a professional and educational tone throughout your feedback. 

                            ---

                            SUBMISSION:  
                            {$params['text']}  

                            ---

                            Be mindful of the following details:  
                            - Focus on clarity and specificity in your feedback.  
                            - Aim to encourage the student while also pointing out areas that need development.  
                            - Ensure that your comments are actionable, so the student knows how to improve.  

                            ---  

                            Example of feedback structure: 
                            - AI Grade: [Title of content] 
                            Grade: A (95/100)  
                            Strengths: [Specific strengths of the submission]  
                            Areas for Improvement: [Specific suggestions for improvement]  

                            ---

                            Avoid vague comments and ensure your feedback is tailored to the specific submission provided.";

                $action = new \core_ai\aiactions\generate_text(
                    contextid: $context->id,
                    userid: $USER->id,
                    prompttext: $prompt,
                );
                break;
            case 'contentdetector':
                $prompt = "You are an experienced Moodle teacher with a deep understanding of evaluating student work for authenticity and originality. 

                            Your task is to analyze a student assignment submission for potential AI-generated content. Here is the submission you need to review:  
                            SUBMISSION:  
                            {$params['text']}  
                            ---
                            Example of feedback structure: 
                            - AI Content Detector: [Title of content]   
                                    
                            The analysis should be comprehensive, clearly stating the risk level of AI-generated content on a scale from 0 to 100, with 0 indicating no risk and 100 indicating complete certainty of AI generation. Please provide reasoning for your assessment and any specific indicators you identified.";

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

            $responsedata=$response->get_response_data()['generatedcontent'];

            if($responsedata){

                $formatted = preg_replace(
                            [
                                '/\bAI Feedback:/i',
                                '/\bStrengths:/i',
                                '/\bImprovements:/i',
                                '/\bSuggestions:/i',
                                '/\bAI Grade:/i',
                                '/\bGrade:/i',
                                '/\bAreas for Improvement:/i',
                                '/\bAI Content Detector:/i',
                            ],
                            [
                                '<strong>AI Feedback:</strong><br>',
                                '<strong>Strengths:</strong><br>',
                                '<br><br><strong>Improvements:</strong><br>',
                                '<br><br><strong>Suggestions:</strong><br>',
                                '<strong>AI Grade:</strong><br>',
                                 '<strong>Grade:</strong><br>',
                                '<strong>Areas for Improvement:</strong><br>',
                                '<strong>AI Content Detector:</strong><br>',
                            ],
                            nl2br(trim($responsedata)));

                $formatteddata =  \html_writer::div($formatted, 'alert alert-info p-3 rounded');

            }else{
                $formatteddata ="";
            }

            return [
                'result' => $formatteddata,
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
            'result' => new \external_value(PARAM_RAW, 'The processed text result'),
        ]);
    }
}
