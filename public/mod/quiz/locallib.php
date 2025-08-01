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
 * Library of functions used by the quiz module.
 *
 * This contains functions that are called from within the quiz module only
 * Functions that are also called by core Moodle are in {@link lib.php}
 * This script also loads the code in {@link questionlib.php} which holds
 * the module-indpendent code for handling questions and which in turn
 * initialises all the questiontype classes.
 *
 * @package    mod_quiz
 * @copyright  1999 onwards Martin Dougiamas and others {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/lib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/questionlib.php');

use core\di;
use core\hook;
use core\exception\coding_exception;
use core_question\local\bank\condition;
use mod_quiz\access_manager;
use mod_quiz\event\attempt_submitted;
use mod_quiz\grade_calculator;
use mod_quiz\hook\attempt_state_changed;
use mod_quiz\local\override_manager;
use mod_quiz\question\bank\qbank_helper;
use mod_quiz\question\display_options;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;
use mod_quiz\structure;
use qbank_previewquestion\question_preview_options;

/**
 * @var int We show the countdown timer if there is less than this amount of time left before the
 * the quiz close date. (1 hour)
 */
define('QUIZ_SHOW_TIME_BEFORE_DEADLINE', '3600');

/**
 * @var int If there are fewer than this many seconds left when the student submits
 * a page of the quiz, then do not take them to the next page of the quiz. Instead
 * close the quiz immediately.
 */
define('QUIZ_MIN_TIME_TO_CONTINUE', '2');

/**
 * @var int We show no image when user selects No image from dropdown menu in quiz settings.
 */
define('QUIZ_SHOWIMAGE_NONE', 0);

/**
 * @var int We show small image when user selects small image from dropdown menu in quiz settings.
 */
define('QUIZ_SHOWIMAGE_SMALL', 1);

/**
 * @var int We show Large image when user selects Large image from dropdown menu in quiz settings.
 */
define('QUIZ_SHOWIMAGE_LARGE', 2);


// Functions related to attempts ///////////////////////////////////////////////

/**
 * Creates an object to represent a new attempt at a quiz
 *
 * Creates an attempt object to represent an attempt at the quiz by the current
 * user starting at the current time. The ->id field is not set. The object is
 * NOT written to the database.
 *
 * @param quiz_settings $quizobj the quiz object to create an attempt for.
 * @param int $attemptnumber the sequence number for the attempt.
 * @param stdClass|false $lastattempt the previous attempt by this user, if any. Only needed
 *         if $attemptnumber > 1 and $quiz->attemptonlast is true.
 * @param int $timenow the time the attempt was started at.
 * @param bool $ispreview whether this new attempt is a preview.
 * @param int|null $userid  the id of the user attempting this quiz.
 *
 * @return stdClass the newly created attempt object.
 */
function quiz_create_attempt(quiz_settings $quizobj, $attemptnumber, $lastattempt, $timenow, $ispreview = false, $userid = null) {
    global $USER;

    if ($userid === null) {
        $userid = $USER->id;
    }

    $quiz = $quizobj->get_quiz();
    if ($quiz->sumgrades < grade_calculator::ALMOST_ZERO && $quiz->grade > grade_calculator::ALMOST_ZERO) {
        throw new moodle_exception('cannotstartgradesmismatch', 'quiz',
                new moodle_url('/mod/quiz/view.php', ['q' => $quiz->id]),
                    ['grade' => quiz_format_grade($quiz, $quiz->grade)]);
    }

    if ($attemptnumber == 1 || !$quiz->attemptonlast) {
        // We are not building on last attempt so create a new attempt.
        $attempt = new stdClass();
        $attempt->quiz = $quiz->id;
        $attempt->userid = $userid;
        $attempt->preview = 0;
        $attempt->layout = '';
    } else {
        // Build on last attempt.
        if (empty($lastattempt)) {
            throw new \moodle_exception('cannotfindprevattempt', 'quiz');
        }
        $attempt = $lastattempt;
    }

    $attempt->attempt = $attemptnumber;
    $attempt->timefinish = 0;
    $attempt->timemodified = $timenow;
    $attempt->timemodifiedoffline = 0;
    $attempt->currentpage = 0;
    $attempt->sumgrades = null;
    $attempt->gradednotificationsenttime = null;
    $attempt->timecheckstate = null;

    // If this is a preview, mark it as such.
    if ($ispreview) {
        $attempt->preview = 1;
    }

    return $attempt;
}
/**
 * Start a normal, new, quiz attempt.
 *
 * @param quiz_settings $quizobj        the quiz object to start an attempt for.
 * @param question_usage_by_activity $quba
 * @param stdClass    $attempt
 * @param integer   $attemptnumber      starting from 1
 * @param integer   $timenow            the attempt start time
 * @param array     $questionids        slot number => question id. Used for random questions, to force the choice
 *                                      of a particular actual question. Intended for testing purposes only.
 * @param array     $forcedvariantsbyslot slot number => variant. Used for questions with variants,
 *                                        to force the choice of a particular variant. Intended for testing
 *                                        purposes only.
 * @return stdClass   modified attempt object
 */
function quiz_start_new_attempt($quizobj, $quba, $attempt, $attemptnumber, $timenow,
                                $questionids = [], $forcedvariantsbyslot = []) {

    // Usages for this user's previous quiz attempts.
    $qubaids = new \mod_quiz\question\qubaids_for_users_attempts(
            $quizobj->get_quizid(), $attempt->userid);

    // Partially load all the questions in this quiz.
    $quizobj->preload_questions();

    // First load all the non-random questions.
    $randomfound = false;
    $slot = 0;
    $questions = [];
    $maxmark = [];
    $page = [];
    foreach ($quizobj->get_questions(null, false) as $questiondata) {
        $slot += 1;
        $maxmark[$slot] = $questiondata->maxmark;
        $page[$slot] = $questiondata->page;
        if ($questiondata->status == \core_question\local\bank\question_version_status::QUESTION_STATUS_DRAFT) {
            throw new moodle_exception('questiondraftonly', 'mod_quiz', '', $questiondata->name);
        }
        if ($questiondata->qtype == 'random') {
            $randomfound = true;
            continue;
        }
        $questions[$slot] = question_bank::load_question($questiondata->questionid, $quizobj->get_quiz()->shuffleanswers);
    }

    // Then find a question to go in place of each random question.
    if ($randomfound) {
        $slot = 0;
        $usedquestionids = [];
        foreach ($questions as $question) {
            if ($question->id && isset($usedquestions[$question->id])) {
                $usedquestionids[$question->id] += 1;
            } else {
                $usedquestionids[$question->id] = 1;
            }
        }
        $randomloader = new \core_question\local\bank\random_question_loader($qubaids, $usedquestionids);

        foreach ($quizobj->get_questions(null, false) as $questiondata) {
            $slot += 1;
            if ($questiondata->qtype != 'random') {
                continue;
            }

            $tagids = qbank_helper::get_tag_ids_for_slot($questiondata);

            // Deal with fixed random choices for testing.
            if (isset($questionids[$quba->next_slot_number()])) {
                $filtercondition = $questiondata->filtercondition;
                $filters = $filtercondition['filter'] ?? [];
                if ($randomloader->is_filtered_question_available($filters, $questionids[$quba->next_slot_number()])) {
                    $questions[$slot] = question_bank::load_question(
                            $questionids[$quba->next_slot_number()], $quizobj->get_quiz()->shuffleanswers);
                    continue;
                } else {
                    throw new coding_exception('Forced question id not available.');
                }
            }

            // Normal case, pick one at random.
            $filtercondition = $questiondata->filtercondition;
            $filters = $filtercondition['filter'] ?? [];
            $questionid = $randomloader->get_next_filtered_question_id($filters);

            if ($questionid === null) {
                throw new moodle_exception('notenoughrandomquestions', 'quiz',
                                           $quizobj->view_url(), $questiondata);
            }

            $questions[$slot] = question_bank::load_question($questionid,
                    $quizobj->get_quiz()->shuffleanswers);
        }
    }

    // Finally add them all to the usage.
    ksort($questions);
    foreach ($questions as $slot => $question) {
        $newslot = $quba->add_question($question, $maxmark[$slot]);
        if ($newslot != $slot) {
            throw new coding_exception('Slot numbers have got confused.');
        }
    }

    // Start all the questions.
    $variantstrategy = new core_question\engine\variants\least_used_strategy($quba, $qubaids);

    if (!empty($forcedvariantsbyslot)) {
        $forcedvariantsbyseed = question_variant_forced_choices_selection_strategy::prepare_forced_choices_array(
            $forcedvariantsbyslot, $quba);
        $variantstrategy = new question_variant_forced_choices_selection_strategy(
            $forcedvariantsbyseed, $variantstrategy);
    }

    $quba->start_all_questions($variantstrategy, question_attempt_step::TIMECREATED_ON_FIRST_RENDER, $attempt->userid);

    // Work out the attempt layout.
    $sections = $quizobj->get_sections();
    foreach ($sections as $i => $section) {
        if (isset($sections[$i + 1])) {
            $sections[$i]->lastslot = $sections[$i + 1]->firstslot - 1;
        } else {
            $sections[$i]->lastslot = count($questions);
        }
    }

    $layout = [];
    foreach ($sections as $section) {
        if ($section->shufflequestions) {
            $questionsinthissection = [];
            for ($slot = $section->firstslot; $slot <= $section->lastslot; $slot += 1) {
                $questionsinthissection[] = $slot;
            }
            shuffle($questionsinthissection);
            $questionsonthispage = 0;
            foreach ($questionsinthissection as $slot) {
                if ($questionsonthispage && $questionsonthispage == $quizobj->get_quiz()->questionsperpage) {
                    $layout[] = 0;
                    $questionsonthispage = 0;
                }
                $layout[] = $slot;
                $questionsonthispage += 1;
            }

        } else {
            $currentpage = $page[$section->firstslot];
            for ($slot = $section->firstslot; $slot <= $section->lastslot; $slot += 1) {
                if ($currentpage !== null && $page[$slot] != $currentpage) {
                    $layout[] = 0;
                }
                $layout[] = $slot;
                $currentpage = $page[$slot];
            }
        }

        // Each section ends with a page break.
        $layout[] = 0;
    }
    $attempt->layout = implode(',', $layout);

    return $attempt;
}

/**
 * Start a subsequent new attempt, in each attempt builds on last mode.
 *
 * @param question_usage_by_activity    $quba         this question usage
 * @param stdClass                        $attempt      this attempt
 * @param stdClass                        $lastattempt  last attempt
 * @return stdClass                       modified attempt object
 *
 */
function quiz_start_attempt_built_on_last($quba, $attempt, $lastattempt) {
    $oldquba = question_engine::load_questions_usage_by_activity($lastattempt->uniqueid);

    $oldnumberstonew = [];
    foreach ($oldquba->get_attempt_iterator() as $oldslot => $oldqa) {
        $question = $oldqa->get_question(false);
        if ($question->status == \core_question\local\bank\question_version_status::QUESTION_STATUS_DRAFT) {
            throw new moodle_exception('questiondraftonly', 'mod_quiz', '', $question->name);
        }
        $newslot = $quba->add_question($question, $oldqa->get_max_mark());

        $quba->start_question_based_on($newslot, $oldqa);

        $oldnumberstonew[$oldslot] = $newslot;
    }

    // Update attempt layout.
    $newlayout = [];
    foreach (explode(',', $lastattempt->layout) as $oldslot) {
        if ($oldslot != 0) {
            $newlayout[] = $oldnumberstonew[$oldslot];
        } else {
            $newlayout[] = 0;
        }
    }
    $attempt->layout = implode(',', $newlayout);
    return $attempt;
}

/**
 * Create or update the quiz attempt record, and the question usage.
 *
 * If the attempt already exists in the database with the NOT_STARTED state, it will be transitioned
 * to IN_PROGRESS and the timestart updated. If it does not already exist, a new record will be created
 * already in the IN_PROGRESS state.
 *
 * @param quiz_settings $quizobj
 * @param question_usage_by_activity $quba
 * @param stdClass                     $attempt
 * @param ?int $timenow The time to use for the attempt's timestart property. Defaults to time().
 * @return stdClass                    attempt object with uniqueid and id set.
 */
function quiz_attempt_save_started(
    quiz_settings $quizobj,
    question_usage_by_activity $quba,
    \stdClass $attempt,
    ?int $timenow = null,
): stdClass {
    global $DB;

    $attempt->timestart = $timenow ?? time();

    $timeclose = $quizobj->get_access_manager($attempt->timestart)->get_end_time($attempt);
    if ($timeclose === false || $attempt->preview) {
        $attempt->timecheckstate = null;
    } else {
        $attempt->timecheckstate = $timeclose;
    }

    $originalattempt = null;
    if (isset($attempt->id) && $attempt->state === quiz_attempt::NOT_STARTED) {
        $originalattempt = clone $attempt;
        // In case questions have been edited since attempts were pre-created, update questions now.
        quiz_attempt::create($attempt->id)->update_questions_to_new_version_if_changed();
        // Update the attempt's state.
        $attempt->state = quiz_attempt::IN_PROGRESS;
        $DB->update_record('quiz_attempts', $attempt);
    } else {
        // Save the attempt in the database.
        question_engine::save_questions_usage_by_activity($quba);
        $attempt->uniqueid = $quba->get_id();
        $attempt->state = quiz_attempt::IN_PROGRESS;
        $attempt->id = $DB->insert_record('quiz_attempts', $attempt);
    }

    // Params used by the events below.
    $params = [
        'objectid' => $attempt->id,
        'relateduserid' => $attempt->userid,
        'courseid' => $quizobj->get_courseid(),
        'context' => $quizobj->get_context()
    ];
    // Decide which event we are using.
    if ($attempt->preview) {
        $params['other'] = [
            'quizid' => $quizobj->get_quizid()
        ];
        $event = \mod_quiz\event\attempt_preview_started::create($params);
    } else {
        $event = \mod_quiz\event\attempt_started::create($params);

    }

    // Trigger the event.
    $event->add_record_snapshot('quiz', $quizobj->get_quiz());
    $event->add_record_snapshot('quiz_attempts', $attempt);
    $event->trigger();

    di::get(hook\manager::class)->dispatch(new attempt_state_changed($originalattempt, $attempt));

    return $attempt;
}

/**
 * Create the quiz attempt record, and the question usage.
 *
 * This saves an attempt in the NOT_STARTED state, and is designed for use when pre-creating attempts
 * ahead of the quiz start time to spread out the processing load.
 *
 * @param question_usage_by_activity $quba
 * @param stdClass $attempt
 * @return stdClass attempt object with uniqueid and id set.
 */
function quiz_attempt_save_not_started(question_usage_by_activity $quba, stdClass $attempt): stdClass {
    global $DB;
    // Save the attempt in the database.
    question_engine::save_questions_usage_by_activity($quba);
    $attempt->uniqueid = $quba->get_id();
    $attempt->state = quiz_attempt::NOT_STARTED;
    $attempt->id = $DB->insert_record('quiz_attempts', $attempt);
    di::get(hook\manager::class)->dispatch(new attempt_state_changed(null, $attempt));
    return $attempt;
}

/**
 * Returns an unfinished attempt (if there is one) for the given
 * user on the given quiz. This function does not return preview attempts.
 *
 * @param int $quizid the id of the quiz.
 * @param int $userid the id of the user.
 *
 * @return mixed the unfinished attempt if there is one, false if not.
 */
function quiz_get_user_attempt_unfinished($quizid, $userid) {
    $attempts = quiz_get_user_attempts($quizid, $userid, 'unfinished', true);
    if ($attempts) {
        return array_shift($attempts);
    } else {
        return false;
    }
}

/**
 * Delete a quiz attempt.
 * @param mixed $attempt an integer attempt id or an attempt object
 *      (row of the quiz_attempts table).
 * @param stdClass $quiz the quiz object.
 */
function quiz_delete_attempt($attempt, $quiz) {
    global $DB;
    if (is_numeric($attempt)) {
        if (!$attempt = $DB->get_record('quiz_attempts', ['id' => $attempt])) {
            return;
        }
    }

    if ($attempt->quiz != $quiz->id) {
        debugging("Trying to delete attempt $attempt->id which belongs to quiz $attempt->quiz " .
                "but was passed quiz $quiz->id.");
        return;
    }

    if (!isset($quiz->cmid)) {
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course);
        $quiz->cmid = $cm->id;
    }

    question_engine::delete_questions_usage_by_activity($attempt->uniqueid);
    $DB->delete_records('quiz_attempts', ['id' => $attempt->id]);

    // Log the deletion of the attempt if not a preview.
    if (!$attempt->preview) {
        $params = [
            'objectid' => $attempt->id,
            'relateduserid' => $attempt->userid,
            'context' => context_module::instance($quiz->cmid),
            'other' => [
                'quizid' => $quiz->id
            ]
        ];
        $event = \mod_quiz\event\attempt_deleted::create($params);
        $event->add_record_snapshot('quiz_attempts', $attempt);
        $event->trigger();

        di::get(hook\manager::class)->dispatch(new attempt_state_changed($attempt, null));
    }

    // Search quiz_attempts for other instances by this user.
    // If none, then delete record for this quiz, this user from quiz_grades
    // else recalculate best grade.
    $userid = $attempt->userid;
    $gradecalculator = quiz_settings::create($quiz->id)->get_grade_calculator();
    if (!$DB->record_exists('quiz_attempts', ['userid' => $userid, 'quiz' => $quiz->id])) {
        $DB->delete_records('quiz_grades', ['userid' => $userid, 'quiz' => $quiz->id]);
    } else {
        $gradecalculator->recompute_final_grade($userid);
    }

    quiz_update_grades($quiz, $userid);
}

/**
 * Delete all the preview attempts at a quiz, or possibly all the attempts belonging
 * to one user.
 * @param stdClass $quiz the quiz object.
 * @param int $userid (optional) if given, only delete the previews belonging to this user.
 */
function quiz_delete_previews($quiz, $userid = null) {
    global $DB;
    $conditions = ['quiz' => $quiz->id, 'preview' => 1];
    if (!empty($userid)) {
        $conditions['userid'] = $userid;
    }
    $previewattempts = $DB->get_records('quiz_attempts', $conditions);
    foreach ($previewattempts as $attempt) {
        quiz_delete_attempt($attempt, $quiz);
    }
}

/**
 * @param int $quizid The quiz id.
 * @return bool whether this quiz has any (non-preview) attempts.
 */
function quiz_has_attempts($quizid) {
    global $DB;
    return $DB->record_exists('quiz_attempts', ['quiz' => $quizid, 'preview' => 0]);
}

// Functions to do with quiz layout and pages //////////////////////////////////

/**
 * Repaginate the questions in a quiz
 * @param int $quizid the id of the quiz to repaginate.
 * @param int $slotsperpage number of items to put on each page. 0 means unlimited.
 */
function quiz_repaginate_questions($quizid, $slotsperpage) {
    global $DB;
    $trans = $DB->start_delegated_transaction();

    $sections = $DB->get_records('quiz_sections', ['quizid' => $quizid], 'firstslot ASC');
    $firstslots = [];
    foreach ($sections as $section) {
        if ((int)$section->firstslot === 1) {
            continue;
        }
        $firstslots[] = $section->firstslot;
    }

    $slots = $DB->get_records('quiz_slots', ['quizid' => $quizid],
            'slot');
    $currentpage = 1;
    $slotsonthispage = 0;
    foreach ($slots as $slot) {
        if (($firstslots && in_array($slot->slot, $firstslots)) ||
            ($slotsonthispage && $slotsonthispage == $slotsperpage)) {
            $currentpage += 1;
            $slotsonthispage = 0;
        }
        if ($slot->page != $currentpage) {
            $DB->set_field('quiz_slots', 'page', $currentpage, ['id' => $slot->id]);
        }
        $slotsonthispage += 1;
    }

    $trans->allow_commit();

    // Log quiz re-paginated event.
    $cm = get_coursemodule_from_instance('quiz', $quizid);
    $event = \mod_quiz\event\quiz_repaginated::create([
        'context' => \context_module::instance($cm->id),
        'objectid' => $quizid,
        'other' => [
            'slotsperpage' => $slotsperpage
        ]
    ]);
    $event->trigger();

}

// Functions to do with quiz grades ////////////////////////////////////////////
// Note a lot of logic related to this is now in the grade_calculator class.

/**
 * Convert the raw grade stored in $attempt into a grade out of the maximum
 * grade for this quiz.
 *
 * @param float $rawgrade the unadjusted grade, fof example $attempt->sumgrades
 * @param stdClass $quiz the quiz object. Only the fields grade, sumgrades and decimalpoints are used.
 * @param bool|string $format whether to format the results for display
 *      or 'question' to format a question grade (different number of decimal places.
 * @return float|string the rescaled grade, or null/the lang string 'notyetgraded'
 *      if the $grade is null.
 */
function quiz_rescale_grade($rawgrade, $quiz, $format = true) {
    if (is_null($rawgrade)) {
        $grade = null;
    } else if ($quiz->sumgrades >= grade_calculator::ALMOST_ZERO) {
        $grade = $rawgrade * $quiz->grade / $quiz->sumgrades;
    } else {
        $grade = 0;
    }
    if ($format === 'question') {
        $grade = quiz_format_question_grade($quiz, $grade);
    } else if ($format) {
        $grade = quiz_format_grade($quiz, $grade);
    }
    return $grade;
}

/**
 * Get the feedback object for this grade on this quiz.
 *
 * @param float $grade a grade on this quiz.
 * @param stdClass $quiz the quiz settings.
 * @return false|stdClass the record object or false if there is not feedback for the given grade
 * @since  Moodle 3.1
 */
function quiz_feedback_record_for_grade($grade, $quiz) {
    global $DB;

    // With CBM etc, it is possible to get -ve grades, which would then not match
    // any feedback. Therefore, we replace -ve grades with 0.
    $grade = max($grade, 0);

    $feedback = $DB->get_record_select('quiz_feedback',
            'quizid = ? AND mingrade <= ? AND ? < maxgrade', [$quiz->id, $grade, $grade]);

    return $feedback;
}

/**
 * Get the feedback text that should be show to a student who
 * got this grade on this quiz. The feedback is processed ready for diplay.
 *
 * @param float $grade a grade on this quiz.
 * @param stdClass $quiz the quiz settings.
 * @param context_module $context the quiz context.
 * @return string the comment that corresponds to this grade (empty string if there is not one.
 */
function quiz_feedback_for_grade($grade, $quiz, $context) {

    if (is_null($grade)) {
        return '';
    }

    $feedback = quiz_feedback_record_for_grade($grade, $quiz);

    if (empty($feedback->feedbacktext)) {
        return '';
    }

    // Clean the text, ready for display.
    $formatoptions = new stdClass();
    $formatoptions->noclean = true;
    $feedbacktext = file_rewrite_pluginfile_urls($feedback->feedbacktext, 'pluginfile.php',
            $context->id, 'mod_quiz', 'feedback', $feedback->id);
    $feedbacktext = format_text($feedbacktext, $feedback->feedbacktextformat, $formatoptions);

    return $feedbacktext;
}

/**
 * @param stdClass $quiz the quiz database row.
 * @return bool Whether this quiz has any non-blank feedback text.
 */
function quiz_has_feedback($quiz) {
    global $DB;
    static $cache = [];
    if (!array_key_exists($quiz->id, $cache)) {
        $cache[$quiz->id] = quiz_has_grades($quiz) &&
                $DB->record_exists_select('quiz_feedback', "quizid = ? AND " .
                    $DB->sql_isnotempty('quiz_feedback', 'feedbacktext', false, true),
                [$quiz->id]);
    }
    return $cache[$quiz->id];
}

/**
 * Return summary of the number of settings override that exist.
 *
 * To get a nice display of this, see the quiz_override_summary_links()
 * quiz renderer method.
 *
 * @param stdClass $quiz the quiz settings. Only $quiz->id is used at the moment.
 * @param cm_info|stdClass $cm the cm object. Only $cm->course, $cm->groupmode and
 *      $cm->groupingid fields are used at the moment.
 * @param int $currentgroup if there is a concept of current group where this method is being called
 *      (e.g. a report) pass it in here. Default 0 which means no current group.
 * @return array like 'group' => 3, 'user' => 12] where 3 is the number of group overrides,
 *      and 12 is the number of user ones.
 */
function quiz_override_summary(stdClass $quiz, cm_info|stdClass $cm, int $currentgroup = 0): array {
    global $DB;

    if ($currentgroup) {
        // Currently only interested in one group.
        $groupcount = $DB->count_records('quiz_overrides', ['quiz' => $quiz->id, 'groupid' => $currentgroup]);
        $usercount = $DB->count_records_sql("
                SELECT COUNT(1)
                  FROM {quiz_overrides} o
                  JOIN {groups_members} gm ON o.userid = gm.userid
                 WHERE o.quiz = ?
                   AND gm.groupid = ?
                    ", [$quiz->id, $currentgroup]);
        return ['group' => $groupcount, 'user' => $usercount, 'mode' => 'onegroup'];
    }

    $quizgroupmode = groups_get_activity_groupmode($cm);
    $accessallgroups = ($quizgroupmode == NOGROUPS) ||
            has_capability('moodle/site:accessallgroups', context_module::instance($cm->id));

    if ($accessallgroups) {
        // User can see all groups.
        $groupcount = $DB->count_records_select('quiz_overrides',
                'quiz = ? AND groupid IS NOT NULL', [$quiz->id]);
        $usercount = $DB->count_records_select('quiz_overrides',
                'quiz = ? AND userid IS NOT NULL', [$quiz->id]);
        return ['group' => $groupcount, 'user' => $usercount, 'mode' => 'allgroups'];

    } else {
        // User can only see groups they are in.
        $groups = groups_get_activity_allowed_groups($cm);
        if (!$groups) {
            return ['group' => 0, 'user' => 0, 'mode' => 'somegroups'];
        }

        list($groupidtest, $params) = $DB->get_in_or_equal(array_keys($groups));
        $params[] = $quiz->id;

        $groupcount = $DB->count_records_select('quiz_overrides',
                "groupid $groupidtest AND quiz = ?", $params);
        $usercount = $DB->count_records_sql("
                SELECT COUNT(1)
                  FROM {quiz_overrides} o
                  JOIN {groups_members} gm ON o.userid = gm.userid
                 WHERE gm.groupid $groupidtest
                   AND o.quiz = ?
               ", $params);

        return ['group' => $groupcount, 'user' => $usercount, 'mode' => 'somegroups'];
    }
}

/**
 * Efficiently update check state time on all open attempts
 *
 * @param array $conditions optional restrictions on which attempts to update
 *                    Allowed conditions:
 *                      courseid => (array|int) attempts in given course(s)
 *                      userid   => (array|int) attempts for given user(s)
 *                      quizid   => (array|int) attempts in given quiz(s)
 *                      groupid  => (array|int) quizzes with some override for given group(s)
 *
 */
function quiz_update_open_attempts(array $conditions) {
    global $DB;

    foreach ($conditions as &$value) {
        if (!is_array($value)) {
            $value = [$value];
        }
    }

    $params = [];
    $wheres = ["quiza.state IN ('inprogress', 'overdue')"];
    $iwheres = ["iquiza.state IN ('inprogress', 'overdue')"];

    if (isset($conditions['courseid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['courseid'], SQL_PARAMS_NAMED, 'cid');
        $params = array_merge($params, $inparams);
        $wheres[] = "quiza.quiz IN (SELECT q.id FROM {quiz} q WHERE q.course $incond)";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['courseid'], SQL_PARAMS_NAMED, 'icid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "iquiza.quiz IN (SELECT q.id FROM {quiz} q WHERE q.course $incond)";
    }

    if (isset($conditions['userid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['userid'], SQL_PARAMS_NAMED, 'uid');
        $params = array_merge($params, $inparams);
        $wheres[] = "quiza.userid $incond";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['userid'], SQL_PARAMS_NAMED, 'iuid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "iquiza.userid $incond";
    }

    if (isset($conditions['quizid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['quizid'], SQL_PARAMS_NAMED, 'qid');
        $params = array_merge($params, $inparams);
        $wheres[] = "quiza.quiz $incond";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['quizid'], SQL_PARAMS_NAMED, 'iqid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "iquiza.quiz $incond";
    }

    if (isset($conditions['groupid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['groupid'], SQL_PARAMS_NAMED, 'gid');
        $params = array_merge($params, $inparams);
        $wheres[] = "quiza.quiz IN (SELECT qo.quiz FROM {quiz_overrides} qo WHERE qo.groupid $incond)";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['groupid'], SQL_PARAMS_NAMED, 'igid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "iquiza.quiz IN (SELECT qo.quiz FROM {quiz_overrides} qo WHERE qo.groupid $incond)";
    }

    // SQL to compute timeclose and timelimit for each attempt:
    $quizausersql = quiz_get_attempt_usertime_sql(
            implode("\n                AND ", $iwheres));

    // SQL to compute the new timecheckstate
    $timecheckstatesql = "
          CASE WHEN quizauser.usertimelimit = 0 AND quizauser.usertimeclose = 0 THEN NULL
               WHEN quizauser.usertimelimit = 0 THEN quizauser.usertimeclose
               WHEN quizauser.usertimeclose = 0 THEN quiza.timestart + quizauser.usertimelimit
               WHEN quiza.timestart + quizauser.usertimelimit < quizauser.usertimeclose THEN quiza.timestart + quizauser.usertimelimit
               ELSE quizauser.usertimeclose END +
          CASE WHEN quiza.state = 'overdue' THEN quiz.graceperiod ELSE 0 END";

    // SQL to select which attempts to process
    $attemptselect = implode("\n                         AND ", $wheres);

   /*
    * Each database handles updates with inner joins differently:
    *  - mysql does not allow a FROM clause
    *  - postgres and mssql allow FROM but handle table aliases differently
    *
    * Different code for each database.
    */

    $dbfamily = $DB->get_dbfamily();
    if ($dbfamily == 'mysql') {
        $updatesql = "UPDATE {quiz_attempts} quiza
                        JOIN {quiz} quiz ON quiz.id = quiza.quiz
                        JOIN ( $quizausersql ) quizauser ON quizauser.id = quiza.id
                         SET quiza.timecheckstate = $timecheckstatesql
                       WHERE $attemptselect";
    } else if ($dbfamily == 'postgres') {
        $updatesql = "UPDATE {quiz_attempts} quiza
                         SET timecheckstate = $timecheckstatesql
                        FROM {quiz} quiz, ( $quizausersql ) quizauser
                       WHERE quiz.id = quiza.quiz
                         AND quizauser.id = quiza.id
                         AND $attemptselect";
    } else if ($dbfamily == 'mssql') {
        $updatesql = "UPDATE quiza
                         SET timecheckstate = $timecheckstatesql
                        FROM {quiz_attempts} quiza
                        JOIN {quiz} quiz ON quiz.id = quiza.quiz
                        JOIN ( $quizausersql ) quizauser ON quizauser.id = quiza.id
                       WHERE $attemptselect";
    } else {
        throw new \core\exception\coding_exception("Unsupported database family: {$dbfamily}");
    }

    $DB->execute($updatesql, $params);
}

/**
 * Returns SQL to compute timeclose and timelimit for every attempt, taking into account user and group overrides.
 * The query used herein is very similar to the one in function quiz_get_user_timeclose, so, in case you
 * would change either one of them, make sure to apply your changes to both.
 *
 * @param string $redundantwhereclauses extra where clauses to add to the subquery
 *      for performance. These can use the table alias iquiza for the quiz attempts table.
 * @return string SQL select with columns attempt.id, usertimeclose, usertimelimit.
 */
function quiz_get_attempt_usertime_sql($redundantwhereclauses = '') {
    if ($redundantwhereclauses) {
        $redundantwhereclauses = 'WHERE ' . $redundantwhereclauses;
    }
    // The multiple qgo JOINS are necessary because we want timeclose/timelimit = 0 (unlimited) to supercede
    // any other group override
    $quizausersql = "
          SELECT iquiza.id,
           COALESCE(MAX(quo.timeclose), MAX(qgo1.timeclose), MAX(qgo2.timeclose), iquiz.timeclose) AS usertimeclose,
           COALESCE(MAX(quo.timelimit), MAX(qgo3.timelimit), MAX(qgo4.timelimit), iquiz.timelimit) AS usertimelimit

           FROM {quiz_attempts} iquiza
           JOIN {quiz} iquiz ON iquiz.id = iquiza.quiz
      LEFT JOIN {quiz_overrides} quo ON quo.quiz = iquiza.quiz AND quo.userid = iquiza.userid
      LEFT JOIN {groups_members} gm ON gm.userid = iquiza.userid
      LEFT JOIN {quiz_overrides} qgo1 ON qgo1.quiz = iquiza.quiz AND qgo1.groupid = gm.groupid AND qgo1.timeclose = 0
      LEFT JOIN {quiz_overrides} qgo2 ON qgo2.quiz = iquiza.quiz AND qgo2.groupid = gm.groupid AND qgo2.timeclose > 0
      LEFT JOIN {quiz_overrides} qgo3 ON qgo3.quiz = iquiza.quiz AND qgo3.groupid = gm.groupid AND qgo3.timelimit = 0
      LEFT JOIN {quiz_overrides} qgo4 ON qgo4.quiz = iquiza.quiz AND qgo4.groupid = gm.groupid AND qgo4.timelimit > 0
          $redundantwhereclauses
       GROUP BY iquiza.id, iquiz.id, iquiz.timeclose, iquiz.timelimit";
    return $quizausersql;
}

/**
 * @return array int => lang string the options for calculating the quiz grade
 *      from the individual attempt grades.
 */
function quiz_get_grading_options() {
    return [
        QUIZ_GRADEHIGHEST => get_string('gradehighest', 'quiz'),
        QUIZ_GRADEAVERAGE => get_string('gradeaverage', 'quiz'),
        QUIZ_ATTEMPTFIRST => get_string('attemptfirst', 'quiz'),
        QUIZ_ATTEMPTLAST  => get_string('attemptlast', 'quiz')
    ];
}

/**
 * @param int $option one of the values QUIZ_GRADEHIGHEST, QUIZ_GRADEAVERAGE,
 *      QUIZ_ATTEMPTFIRST or QUIZ_ATTEMPTLAST.
 * @return the lang string for that option.
 */
function quiz_get_grading_option_name($option) {
    $strings = quiz_get_grading_options();
    return $strings[$option];
}

/**
 * @return array string => lang string the options for handling overdue quiz
 *      attempts.
 */
function quiz_get_overdue_handling_options() {
    return [
        'autosubmit'  => get_string('overduehandlingautosubmit', 'quiz'),
        'graceperiod' => get_string('overduehandlinggraceperiod', 'quiz'),
        'autoabandon' => get_string('overduehandlingautoabandon', 'quiz'),
    ];
}

/**
 * Get the choices for what size user picture to show.
 * @return array string => lang string the options for whether to display the user's picture.
 */
function quiz_get_user_image_options() {
    return [
        QUIZ_SHOWIMAGE_NONE  => get_string('shownoimage', 'quiz'),
        QUIZ_SHOWIMAGE_SMALL => get_string('showsmallimage', 'quiz'),
        QUIZ_SHOWIMAGE_LARGE => get_string('showlargeimage', 'quiz'),
    ];
}

/**
 * Return an user's timeclose for all quizzes in a course, hereby taking into account group and user overrides.
 *
 * @param int $courseid the course id.
 * @return stdClass An object with of all quizids and close unixdates in this course, taking into account the most lenient
 * overrides, if existing and 0 if no close date is set.
 */
function quiz_get_user_timeclose($courseid) {
    global $DB, $USER;

    // For teacher and manager/admins return timeclose.
    if (has_capability('moodle/course:update', context_course::instance($courseid))) {
        $sql = "SELECT quiz.id, quiz.timeclose AS usertimeclose
                  FROM {quiz} quiz
                 WHERE quiz.course = :courseid";

        $results = $DB->get_records_sql($sql, ['courseid' => $courseid]);
        return $results;
    }

    $sql = "SELECT q.id,
  COALESCE(v.userclose, v.groupclose, q.timeclose, 0) AS usertimeclose
  FROM (
      SELECT quiz.id as quizid,
             MAX(quo.timeclose) AS userclose, MAX(qgo.timeclose) AS groupclose
       FROM {quiz} quiz
  LEFT JOIN {quiz_overrides} quo on quiz.id = quo.quiz AND quo.userid = :userid
  LEFT JOIN {groups_members} gm ON gm.userid = :useringroupid
  LEFT JOIN {quiz_overrides} qgo on quiz.id = qgo.quiz AND qgo.groupid = gm.groupid
      WHERE quiz.course = :courseid
   GROUP BY quiz.id) v
       JOIN {quiz} q ON q.id = v.quizid";

    $results = $DB->get_records_sql($sql, ['userid' => $USER->id, 'useringroupid' => $USER->id, 'courseid' => $courseid]);
    return $results;

}

/**
 * Get the choices to offer for the 'Questions per page' option.
 * @return array int => string.
 */
function quiz_questions_per_page_options() {
    $pageoptions = [];
    $pageoptions[0] = get_string('neverallononepage', 'quiz');
    $pageoptions[1] = get_string('everyquestion', 'quiz');
    for ($i = 2; $i <= QUIZ_MAX_QPP_OPTION; ++$i) {
        $pageoptions[$i] = get_string('everynquestions', 'quiz', $i);
    }
    return $pageoptions;
}

/**
 * Get the human-readable name for a quiz attempt state.
 * @param string $state one of the state constants like {@see quiz_attempt::IN_PROGRESS}.
 * @return string The lang string to describe that state.
 */
function quiz_attempt_state_name($state) {
    switch ($state) {
        case quiz_attempt::NOT_STARTED:
            return get_string('statenotstarted', 'quiz');
        case quiz_attempt::IN_PROGRESS:
            return get_string('stateinprogress', 'quiz');
        case quiz_attempt::OVERDUE:
            return get_string('stateoverdue', 'quiz');
        case quiz_attempt::SUBMITTED:
            return get_string('statesubmitted', 'quiz');
        case quiz_attempt::FINISHED:
            return get_string('statefinished', 'quiz');
        case quiz_attempt::ABANDONED:
            return get_string('stateabandoned', 'quiz');
        default:
            throw new coding_exception('Unknown quiz attempt state.');
    }
}

// Other quiz functions ////////////////////////////////////////////////////////

/**
 * @param stdClass $quiz the quiz.
 * @param int $cmid the course_module object for this quiz.
 * @param stdClass $question the question.
 * @param string $returnurl url to return to after action is done.
 * @param int $variant which question variant to preview (optional).
 * @return string html for a number of icons linked to action pages for a
 * question - preview and edit / view icons depending on user capabilities.
 */
function quiz_question_action_icons($quiz, $cmid, $question, $returnurl, $variant = null) {
    $html = '';
    if ($question->qtype !== 'random') {
        $html = quiz_question_preview_button($quiz, $question, false, $variant);
    }
    $html .= quiz_question_edit_button($cmid, $question, $returnurl);
    return $html;
}

/**
 * @param int $cmid the course_module.id for this quiz.
 * @param stdClass $question the question.
 * @param string $returnurl url to return to after action is done.
 * @param string $contentbeforeicon some HTML content to be added inside the link, before the icon.
 * @return the HTML for an edit icon, view icon, or nothing for a question
 *      (depending on permissions).
 */
function quiz_question_edit_button($cmid, $question, $returnurl, $contentaftericon = '') {
    global $CFG, $OUTPUT;

    // Minor efficiency saving. Only get strings once, even if there are a lot of icons on one page.
    static $stredit = null;
    static $strview = null;
    if ($stredit === null) {
        $stredit = get_string('edit');
        $strview = get_string('view');
    }

    // What sort of icon should we show?
    $action = '';
    if (!empty($question->id) &&
            (question_has_capability_on($question, 'edit') ||
                    question_has_capability_on($question, 'move'))) {
        $action = $stredit;
        $icon = 't/edit';
    } else if (!empty($question->id) &&
            question_has_capability_on($question, 'view')) {
        $action = $strview;
        $icon = 'i/info';
    }

    // Build the icon.
    if ($action) {
        if ($returnurl instanceof moodle_url) {
            $returnurl = $returnurl->out_as_local_url(false);
        }
        $questionparams = ['returnurl' => $returnurl, 'cmid' => $cmid, 'id' => $question->id];
        $questionurl = new moodle_url("$CFG->wwwroot/question/bank/editquestion/question.php", $questionparams);
        return '<a title="' . $action . '" href="' . $questionurl->out() . '" class="questioneditbutton">' .
                $OUTPUT->pix_icon($icon, $action) . $contentaftericon .
                '</a>';
    } else if ($contentaftericon) {
        return '<span class="questioneditbutton">' . $contentaftericon . '</span>';
    } else {
        return '';
    }
}

/**
 * @param stdClass $quiz the quiz settings
 * @param stdClass $question the question
 * @param int $variant which question variant to preview (optional).
 * @param int $restartversion version of the question to use when restarting the preview.
 * @return moodle_url to preview this question with the options from this quiz.
 */
function quiz_question_preview_url($quiz, $question, $variant = null, $restartversion = null) {
    // Get the appropriate display options.
    $displayoptions = display_options::make_from_quiz($quiz,
            display_options::DURING);

    $maxmark = null;
    if (isset($question->maxmark)) {
        $maxmark = $question->maxmark;
    }

    // Work out the correcte preview URL.
    return \qbank_previewquestion\helper::question_preview_url($question->id, $quiz->preferredbehaviour,
            $maxmark, $displayoptions, $variant, null, null, $restartversion);
}

/**
 * @param stdClass $quiz the quiz settings
 * @param stdClass $question the question
 * @param bool $label if true, show the preview question label after the icon
 * @param int $variant which question variant to preview (optional).
 * @param bool $random if question is random, true.
 * @return string the HTML for a preview question icon.
 */
function quiz_question_preview_button($quiz, $question, $label = false, $variant = null, $random = null) {
    global $PAGE;
    if (!question_has_capability_on($question, 'use')) {
        return '';
    }
    $structure = quiz_settings::create($quiz->id)->get_structure();
    if (!empty($question->slot)) {
        $requestedversion = $structure->get_slot_by_number($question->slot)->requestedversion
                ?? question_preview_options::ALWAYS_LATEST;
    } else {
        $requestedversion = question_preview_options::ALWAYS_LATEST;
    }
    return $PAGE->get_renderer('mod_quiz', 'edit')->question_preview_icon(
            $quiz, $question, $label, $variant, $requestedversion);
}

/**
 * @param stdClass $attempt the attempt.
 * @param stdClass $context the quiz context.
 * @return int whether flags should be shown/editable to the current user for this attempt.
 */
function quiz_get_flag_option($attempt, $context) {
    global $USER;
    if (!has_capability('moodle/question:flag', $context)) {
        return question_display_options::HIDDEN;
    } else if ($attempt->userid == $USER->id) {
        return question_display_options::EDITABLE;
    } else {
        return question_display_options::VISIBLE;
    }
}

/**
 * Work out what state this quiz attempt is in - in the sense used by
 * quiz_get_review_options, not in the sense of $attempt->state.
 * @param stdClass $quiz the quiz settings
 * @param stdClass $attempt the quiz_attempt database row.
 * @return int one of the display_options::DURING,
 *      IMMEDIATELY_AFTER, LATER_WHILE_OPEN or AFTER_CLOSE constants.
 */
function quiz_attempt_state($quiz, $attempt) {
    if ($attempt->state == quiz_attempt::IN_PROGRESS) {
        return display_options::DURING;
    } else if ($quiz->timeclose && time() >= $quiz->timeclose) {
        return display_options::AFTER_CLOSE;
    } else if (time() < $attempt->timefinish + quiz_attempt::IMMEDIATELY_AFTER_PERIOD) {
        return display_options::IMMEDIATELY_AFTER;
    } else {
        return display_options::LATER_WHILE_OPEN;
    }
}

/**
 * The appropriate display_options object for this attempt at this quiz right now.
 *
 * @param stdClass $quiz the quiz instance.
 * @param stdClass $attempt the attempt in question.
 * @param context $context the quiz context.
 *
 * @return display_options
 */
function quiz_get_review_options($quiz, $attempt, $context) {
    $options = display_options::make_from_quiz($quiz, quiz_attempt_state($quiz, $attempt));

    $options->readonly = true;
    $options->flags = quiz_get_flag_option($attempt, $context);
    if (!empty($attempt->id)) {
        $options->questionreviewlink = new moodle_url('/mod/quiz/reviewquestion.php',
                ['attempt' => $attempt->id]);
    }

    // Show a link to the comment box only for closed attempts.
    if (!empty($attempt->id) && $attempt->state == quiz_attempt::FINISHED && !$attempt->preview &&
            !is_null($context) && has_capability('mod/quiz:grade', $context)) {
        $options->manualcomment = question_display_options::VISIBLE;
        $options->manualcommentlink = new moodle_url('/mod/quiz/comment.php',
                ['attempt' => $attempt->id]);
    }

    if (!is_null($context) && !$attempt->preview &&
            has_capability('mod/quiz:viewreports', $context) &&
            has_capability('moodle/grade:viewhidden', $context)) {
        // People who can see reports and hidden grades should be shown everything,
        // except during preview when teachers want to see what students see.
        $options->attempt = question_display_options::VISIBLE;
        $options->correctness = question_display_options::VISIBLE;
        $options->marks = question_display_options::MARK_AND_MAX;
        $options->feedback = question_display_options::VISIBLE;
        $options->numpartscorrect = question_display_options::VISIBLE;
        $options->manualcomment = question_display_options::VISIBLE;
        $options->generalfeedback = question_display_options::VISIBLE;
        $options->rightanswer = question_display_options::VISIBLE;
        $options->overallfeedback = question_display_options::VISIBLE;
        $options->history = question_display_options::VISIBLE;
        $options->userinfoinhistory = $attempt->userid;

    }

    return $options;
}

/**
 * Combines the review options from a number of different quiz attempts.
 * Returns an array of two ojects, so the suggested way of calling this
 * funciton is:
 * list($someoptions, $alloptions) = quiz_get_combined_reviewoptions(...)
 *
 * @param stdClass $quiz the quiz instance.
 * @param array $attempts an array of attempt objects.
 *
 * @return array of two options objects, one showing which options are true for
 *          at least one of the attempts, the other showing which options are true
 *          for all attempts.
 */
function quiz_get_combined_reviewoptions($quiz, $attempts) {
    $fields = ['feedback', 'generalfeedback', 'rightanswer', 'overallfeedback'];
    $someoptions = new stdClass();
    $alloptions = new stdClass();
    foreach ($fields as $field) {
        $someoptions->$field = false;
        $alloptions->$field = true;
    }
    $someoptions->marks = question_display_options::HIDDEN;
    $alloptions->marks = question_display_options::MARK_AND_MAX;

    // This shouldn't happen, but we need to prevent reveal information.
    if (empty($attempts)) {
        return [$someoptions, $someoptions];
    }

    foreach ($attempts as $attempt) {
        $attemptoptions = display_options::make_from_quiz($quiz,
                quiz_attempt_state($quiz, $attempt));
        foreach ($fields as $field) {
            $someoptions->$field = $someoptions->$field || $attemptoptions->$field;
            $alloptions->$field = $alloptions->$field && $attemptoptions->$field;
        }
        $someoptions->marks = max($someoptions->marks, $attemptoptions->marks);
        $alloptions->marks = min($alloptions->marks, $attemptoptions->marks);
    }
    return [$someoptions, $alloptions];
}

// Functions for sending notification messages /////////////////////////////////

/**
 * Sends a confirmation message to the student confirming that the attempt was processed.
 *
 * @param stdClass $recipient user object for the recipient.
 * @param stdClass $a lots of useful information that can be used in the message
 *      subject and body.
 * @param bool $studentisonline is the student currently interacting with Moodle?
 *
 * @return int|false as for {@link message_send()}.
 */
function quiz_send_confirmation($recipient, $a, $studentisonline) {

    // Add information about the recipient to $a.
    // Don't do idnumber. we want idnumber to be the submitter's idnumber.
    $a->username     = fullname($recipient);
    $a->userusername = $recipient->username;

    // Prepare the message.
    $eventdata = new \core\message\message();
    $eventdata->courseid          = $a->courseid;
    $eventdata->component         = 'mod_quiz';
    $eventdata->name              = 'confirmation';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = core_user::get_noreply_user();
    $eventdata->userto            = $recipient;
    $eventdata->subject           = get_string('emailconfirmsubject', 'quiz', $a);

    if ($studentisonline) {
        $eventdata->fullmessage = get_string('emailconfirmbody', 'quiz', $a);
    } else {
        $eventdata->fullmessage = get_string('emailconfirmbodyautosubmit', 'quiz', $a);
    }

    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailconfirmsmall', 'quiz', $a);
    $eventdata->contexturl        = $a->quizurl;
    $eventdata->contexturlname    = $a->quizname;
    $eventdata->customdata        = [
        'cmid' => $a->quizcmid,
        'instance' => $a->quizid,
        'attemptid' => $a->attemptid,
    ];

    // ... and send it.
    return message_send($eventdata);
}

/**
 * Sends notification messages to the interested parties that assign the role capability
 *
 * @param stdClass $recipient user object of the intended recipient
 * @param stdClass $submitter user object for the user who submitted the attempt.
 * @param stdClass $a associative array of replaceable fields for the templates
 *
 * @return int|false as for {@link message_send()}.
 */
function quiz_send_notification($recipient, $submitter, $a) {
    global $PAGE;

    // Recipient info for template.
    $a->useridnumber = $recipient->idnumber;
    $a->username     = fullname($recipient);
    $a->userusername = $recipient->username;

    // Prepare the message.
    $eventdata = new \core\message\message();
    $eventdata->courseid          = $a->courseid;
    $eventdata->component         = 'mod_quiz';
    $eventdata->name              = 'submission';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = $submitter;
    $eventdata->userto            = $recipient;
    $eventdata->subject           = get_string('emailnotifysubject', 'quiz', $a);
    $eventdata->fullmessage       = get_string('emailnotifybody', 'quiz', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailnotifysmall', 'quiz', $a);
    $eventdata->contexturl        = $a->quizreviewurl;
    $eventdata->contexturlname    = $a->quizname;
    $userpicture = new user_picture($submitter);
    $userpicture->size = 1; // Use f1 size.
    $userpicture->includetoken = $recipient->id; // Generate an out-of-session token for the user receiving the message.
    $eventdata->customdata        = [
        'cmid' => $a->quizcmid,
        'instance' => $a->quizid,
        'attemptid' => $a->attemptid,
        'notificationiconurl' => $userpicture->get_url($PAGE)->out(false),
    ];

    // ... and send it.
    return message_send($eventdata);
}

/**
 * Send all the requried messages when a quiz attempt is submitted.
 *
 * @param stdClass $course the course
 * @param stdClass $quiz the quiz
 * @param stdClass $attempt this attempt just finished
 * @param stdClass $context the quiz context
 * @param stdClass $cm the coursemodule for this quiz
 * @param bool $studentisonline is the student currently interacting with Moodle?
 *
 * @return bool true if all necessary messages were sent successfully, else false.
 */
function quiz_send_notification_messages($course, $quiz, $attempt, $context, $cm, $studentisonline) {
    global $CFG, $DB;

    // Do nothing if required objects not present.
    if (empty($course) or empty($quiz) or empty($attempt) or empty($context)) {
        throw new coding_exception('$course, $quiz, $attempt, $context and $cm must all be set.');
    }

    $submitter = $DB->get_record('user', ['id' => $attempt->userid], '*', MUST_EXIST);

    // Check for confirmation required.
    $sendconfirm = false;
    $notifyexcludeusers = '';
    if (has_capability('mod/quiz:emailconfirmsubmission', $context, $submitter, false)) {
        $notifyexcludeusers = $submitter->id;
        $sendconfirm = true;
    }

    // Check for notifications required.
    $notifyfields = 'u.id, u.username, u.idnumber, u.email, u.emailstop, u.lang,
            u.timezone, u.mailformat, u.maildisplay, u.auth, u.suspended, u.deleted, ';
    $userfieldsapi = \core_user\fields::for_name();
    $notifyfields .= $userfieldsapi->get_sql('u', false, '', '', false)->selects;
    $groups = groups_get_all_groups($course->id, $submitter->id, $cm->groupingid);
    if (is_array($groups) && count($groups) > 0) {
        $groups = array_keys($groups);
    } else if (groups_get_activity_groupmode($cm, $course) != NOGROUPS) {
        // If the user is not in a group, and the quiz is set to group mode,
        // then set $groups to a non-existant id so that only users with
        // 'moodle/site:accessallgroups' get notified.
        $groups = -1;
    } else {
        $groups = '';
    }
    $userstonotify = get_users_by_capability($context, 'mod/quiz:emailnotifysubmission',
            $notifyfields, '', '', '', $groups, $notifyexcludeusers, false, false, true);

    if (empty($userstonotify) && !$sendconfirm) {
        return true; // Nothing to do.
    }

    $a = new stdClass();
    // Course info.
    $a->courseid        = $course->id;
    $a->coursename      = format_string($course->fullname, true, ['context' => $context]);
    $a->courseshortname = format_string($course->shortname, true, ['context' => $context]);
    // Quiz info.
    $a->quizname        = format_string($quiz->name, true, ['context' => $context]);
    $a->quizreporturl   = $CFG->wwwroot . '/mod/quiz/report.php?id=' . $cm->id;
    $a->quizreportlink  = '<a href="' . $a->quizreporturl . '">' . $a->quizname . ' report</a>';
    $a->quizurl         = $CFG->wwwroot . '/mod/quiz/view.php?id=' . $cm->id;
    $a->quizlink        = '<a href="' . $a->quizurl . '">' . $a->quizname . '</a>';
    $a->quizid          = $quiz->id;
    $a->quizcmid        = $cm->id;
    // Attempt info.
    $a->submissiontime  = userdate($attempt->timefinish);
    $a->timetaken       = format_time($attempt->timefinish - $attempt->timestart);
    $a->quizreviewurl   = $CFG->wwwroot . '/mod/quiz/review.php?attempt=' . $attempt->id;
    $a->quizreviewlink  = '<a href="' . $a->quizreviewurl . '">' . $a->quizname . ' review</a>';
    $a->attemptid       = $attempt->id;
    // Student who sat the quiz info.
    $a->studentidnumber = $submitter->idnumber;
    $a->studentname     = fullname($submitter);
    $a->studentusername = $submitter->username;

    $allok = true;

    // Send notifications if required.
    if (!empty($userstonotify)) {
        foreach ($userstonotify as $recipient) {
            $allok = $allok && quiz_send_notification($recipient, $submitter, $a);
        }
    }

    // Send confirmation if required. We send the student confirmation last, so
    // that if message sending is being intermittently buggy, which means we send
    // some but not all messages, and then try again later, then teachers may get
    // duplicate messages, but the student will always get exactly one.
    if ($sendconfirm) {
        $allok = $allok && quiz_send_confirmation($submitter, $a, $studentisonline);
    }

    return $allok;
}

/**
 * Send the notification message when a quiz attempt becomes overdue.
 *
 * @param quiz_attempt $attemptobj all the data about the quiz attempt.
 */
function quiz_send_overdue_message($attemptobj) {
    global $CFG, $DB;

    $submitter = $DB->get_record('user', ['id' => $attemptobj->get_userid()], '*', MUST_EXIST);

    if (!$attemptobj->has_capability('mod/quiz:emailwarnoverdue', $submitter->id, false)) {
        return; // Message not required.
    }

    if (!$attemptobj->has_response_to_at_least_one_graded_question()) {
        return; // Message not required.
    }

    // Prepare lots of useful information that admins might want to include in
    // the email message.
    $quizname = format_string($attemptobj->get_quiz_name());

    $deadlines = [];
    if ($attemptobj->get_quiz()->timelimit) {
        $deadlines[] = $attemptobj->get_attempt()->timestart + $attemptobj->get_quiz()->timelimit;
    }
    if ($attemptobj->get_quiz()->timeclose) {
        $deadlines[] = $attemptobj->get_quiz()->timeclose;
    }
    $duedate = min($deadlines);
    $graceend = $duedate + $attemptobj->get_quiz()->graceperiod;

    $a = new stdClass();
    // Course info.
    $a->courseid           = $attemptobj->get_course()->id;
    $a->coursename         = format_string($attemptobj->get_course()->fullname);
    $a->courseshortname    = format_string($attemptobj->get_course()->shortname);
    // Quiz info.
    $a->quizname           = $quizname;
    $a->quizurl            = $attemptobj->view_url()->out(false);
    $a->quizlink           = '<a href="' . $a->quizurl . '">' . $quizname . '</a>';
    // Attempt info.
    $a->attemptduedate     = userdate($duedate);
    $a->attemptgraceend    = userdate($graceend);
    $a->attemptsummaryurl  = $attemptobj->summary_url()->out(false);
    $a->attemptsummarylink = '<a href="' . $a->attemptsummaryurl . '">' . $quizname . ' review</a>';
    // Student's info.
    $a->studentidnumber    = $submitter->idnumber;
    $a->studentname        = fullname($submitter);
    $a->studentusername    = $submitter->username;

    // Prepare the message.
    $eventdata = new \core\message\message();
    $eventdata->courseid          = $a->courseid;
    $eventdata->component         = 'mod_quiz';
    $eventdata->name              = 'attempt_overdue';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = core_user::get_noreply_user();
    $eventdata->userto            = $submitter;
    $eventdata->subject           = get_string('emailoverduesubject', 'quiz', $a);
    $eventdata->fullmessage       = get_string('emailoverduebody', 'quiz', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailoverduesmall', 'quiz', $a);
    $eventdata->contexturl        = $a->quizurl;
    $eventdata->contexturlname    = $a->quizname;
    $eventdata->customdata        = [
        'cmid' => $attemptobj->get_cmid(),
        'instance' => $attemptobj->get_quizid(),
        'attemptid' => $attemptobj->get_attemptid(),
    ];

    // Send the message.
    return message_send($eventdata);
}

/**
 * Handle the quiz_attempt_submitted event.
 *
 * This sends the confirmation and notification messages, if required.
 *
 * @param attempt_submitted $event the event object.
 */
function quiz_attempt_submitted_handler($event) {
    $course = get_course($event->courseid);
    $attempt = $event->get_record_snapshot('quiz_attempts', $event->objectid);
    $quiz = $event->get_record_snapshot('quiz', $attempt->quiz);
    $cm = get_coursemodule_from_id('quiz', $event->get_context()->instanceid, $event->courseid);
    $eventdata = $event->get_data();

    if (!($course && $quiz && $cm && $attempt)) {
        // Something has been deleted since the event was raised. Therefore, the
        // event is no longer relevant.
        return true;
    }

    // Update completion state.
    $completion = new completion_info($course);
    if ($completion->is_enabled($cm) &&
        ($quiz->completionattemptsexhausted || $quiz->completionminattempts)) {
        $completion->update_state($cm, COMPLETION_COMPLETE, $event->userid);
    }
    return quiz_send_notification_messages($course, $quiz, $attempt,
            context_module::instance($cm->id), $cm, $eventdata['other']['studentisonline']);
}

/**
 * Send the notification message when a quiz attempt has been manual graded.
 *
 * @param quiz_attempt $attemptobj Some data about the quiz attempt.
 * @param stdClass $userto
 * @return int|false As for message_send.
 */
function quiz_send_notify_manual_graded_message(quiz_attempt $attemptobj, object $userto): ?int {
    global $CFG;

    $quizname = format_string($attemptobj->get_quiz_name());

    $a = new stdClass();
    // Course info.
    $a->courseid           = $attemptobj->get_courseid();
    $a->coursename         = format_string($attemptobj->get_course()->fullname);
    // Quiz info.
    $a->quizname           = $quizname;
    $a->quizurl            = $CFG->wwwroot . '/mod/quiz/view.php?id=' . $attemptobj->get_cmid();

    // Attempt info.
    $a->attempttimefinish  = userdate($attemptobj->get_attempt()->timefinish);
    // Student's info.
    $a->studentidnumber    = $userto->idnumber;
    $a->studentname        = fullname($userto);

    $eventdata = new \core\message\message();
    $eventdata->component = 'mod_quiz';
    $eventdata->name = 'attempt_grading_complete';
    $eventdata->userfrom = core_user::get_noreply_user();
    $eventdata->userto = $userto;

    $eventdata->subject = get_string('emailmanualgradedsubject', 'quiz', $a);
    $eventdata->fullmessage = get_string('emailmanualgradedbody', 'quiz', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml = '';

    $eventdata->notification = 1;
    $eventdata->contexturl = $a->quizurl;
    $eventdata->contexturlname = $a->quizname;

    // Send the message.
    return message_send($eventdata);
}


/**
 * Logic to happen when a/some group(s) has/have been deleted in a course.
 *
 * @param int $courseid The course ID.
 * @return void
 */
function quiz_process_group_deleted_in_course($courseid) {
    $affectedquizzes = override_manager::delete_orphaned_group_overrides_in_course($courseid);

    if (!empty($affectedquizzes)) {
        quiz_update_open_attempts(['quizid' => $affectedquizzes]);
    }
}

/**
 * Get the information about the standard quiz JavaScript module.
 * @return array a standard jsmodule structure.
 */
function quiz_get_js_module() {
    global $PAGE;

    return [
        'name' => 'mod_quiz',
        'fullpath' => '/mod/quiz/module.js',
        'requires' => ['base', 'dom', 'event-delegate', 'event-key',
                'core_question_engine'],
        'strings' => [
            ['cancel', 'moodle'],
            ['flagged', 'question'],
            ['functiondisabledbysecuremode', 'quiz'],
            ['startattempt', 'quiz'],
            ['timesup', 'quiz'],
            ['show', 'moodle'],
            ['hide', 'moodle'],
        ],
    ];
}


/**
 * Creates a textual representation of a question for display.
 *
 * @param stdClass $question A question object from the database questions table
 * @param bool $showicon If true, show the question's icon with the question. False by default.
 * @param bool $showquestiontext If true (default), show question text after question name.
 *       If false, show only question name.
 * @param bool $showidnumber If true, show the question's idnumber, if any. False by default.
 * @param core_tag_tag[]|bool $showtags if array passed, show those tags. Else, if true, get and show tags,
 *       else, don't show tags (which is the default).
 * @param bool $displaytaglink Indicates whether the tag should be displayed as a link.
 * @return string HTML fragment.
 */
function quiz_question_tostring($question, $showicon = false, $showquestiontext = true,
        $showidnumber = false, $showtags = false, $displaytaglink = true) {
    global $OUTPUT;
    $result = '';

    // Question name.
    $name = shorten_text(format_string($question->name), 200);
    if ($showicon) {
        $name .= print_question_icon($question) . ' ' . $name;
    }
    $result .= html_writer::span($name, 'questionname');

    // Question idnumber.
    if ($showidnumber && $question->idnumber !== null && $question->idnumber !== '') {
        $result .= ' ' . html_writer::span(
                html_writer::span(get_string('idnumber', 'question'), 'accesshide') .
                ' ' . s($question->idnumber), 'badge bg-primary text-white');
    }

    // Question tags.
    if (is_array($showtags)) {
        $tags = $showtags;
    } else if ($showtags) {
        $tags = core_tag_tag::get_item_tags('core_question', 'question', $question->id);
    } else {
        $tags = [];
    }
    if ($tags) {
        $result .= $OUTPUT->tag_list($tags, null, 'd-inline', 0, null, true, $displaytaglink);
    }

    // Question text.
    if ($showquestiontext) {
        $questiontext = question_utils::to_plain_text($question->questiontext,
                $question->questiontextformat, ['noclean' => true, 'para' => false, 'filter' => false]);
        $questiontext = shorten_text($questiontext, 50);
        if ($questiontext) {
            $result .= ' ' . html_writer::span(s($questiontext), 'questiontext');
        }
    }

    return $result;
}

/**
 * Verify that the question exists, and the user has permission to use it.
 * Does not return. Throws an exception if the question cannot be used.
 * @param int $questionid The id of the question.
 */
function quiz_require_question_use($questionid) {
    global $DB;
    $question = $DB->get_record('question', ['id' => $questionid], '*', MUST_EXIST);
    question_require_capability_on($question, 'use');
}

/**
 * Add a question to a quiz
 *
 * Adds a question to a quiz by updating $quiz as well as the
 * quiz and quiz_slots tables. It also adds a page break if required.
 * @param int $questionid The id of the question to be added
 * @param stdClass $quiz The extended quiz object as used by edit.php
 *      This is updated by this function
 * @param int $page Which page in quiz to add the question on. If 0 (default),
 *      add at the end
 * @param float $maxmark The maximum mark to set for this question. (Optional,
 *      defaults to question.defaultmark.
 * @return bool false if the question was already in the quiz
 */
function quiz_add_quiz_question($questionid, $quiz, $page = 0, $maxmark = null) {
    global $DB;

    if (!isset($quiz->cmid)) {
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course);
        $quiz->cmid = $cm->id;
    }

    // Make sue the question is not of the "random" type.
    $questiontype = $DB->get_field('question', 'qtype', ['id' => $questionid]);
    if ($questiontype == 'random') {
        throw new coding_exception(
                'Adding "random" questions via quiz_add_quiz_question() is deprecated. '.
                'Please use mod_quiz\structure::add_random_questions().'
        );
    }

    // If the question type is invalid, we cannot add it to the quiz. It shouldn't be possible to get to this
    // point without fiddling with the DOM so we can just throw an exception.
    if (!\question_bank::is_qtype_installed($questiontype)) {
        throw new coding_exception('Invalid question type: ' . $questiontype);
    }

    $trans = $DB->start_delegated_transaction();

    $sql = "SELECT qbe.id
              FROM {quiz_slots} slot
              JOIN {question_references} qr ON qr.itemid = slot.id
              JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
             WHERE slot.quizid = ?
               AND qr.component = ?
               AND qr.questionarea = ?
               AND qr.usingcontextid = ?";

    $questionslots = $DB->get_records_sql($sql, [$quiz->id, 'mod_quiz', 'slot',
            context_module::instance($quiz->cmid)->id]);

    $currententry = get_question_bank_entry($questionid);

    if (array_key_exists($currententry->id, $questionslots)) {
        $trans->allow_commit();
        return false;
    }

    $sql = "SELECT slot.slot, slot.page, slot.id
              FROM {quiz_slots} slot
             WHERE slot.quizid = ?
          ORDER BY slot.slot";

    $slots = $DB->get_records_sql($sql, [$quiz->id]);

    $maxpage = 1;
    $numonlastpage = 0;
    foreach ($slots as $slot) {
        if ($slot->page > $maxpage) {
            $maxpage = $slot->page;
            $numonlastpage = 1;
        } else {
            $numonlastpage += 1;
        }
    }

    // Add the new instance.
    $slot = new stdClass();
    $slot->quizid = $quiz->id;

    if ($maxmark !== null) {
        $slot->maxmark = $maxmark;
    } else {
        $slot->maxmark = $DB->get_field('question', 'defaultmark', ['id' => $questionid]);
    }

    if (is_int($page) && $page >= 1) {
        // Adding on a given page.
        $lastslotbefore = 0;
        foreach (array_reverse($slots) as $otherslot) {
            if ($otherslot->page > $page) {
                $DB->set_field('quiz_slots', 'slot', $otherslot->slot + 1, ['id' => $otherslot->id]);
            } else {
                $lastslotbefore = $otherslot->slot;
                break;
            }
        }
        $slot->slot = $lastslotbefore + 1;
        $slot->page = min($page, $maxpage + 1);

        quiz_update_section_firstslots($quiz->id, 1, max($lastslotbefore, 1));

    } else {
        $lastslot = end($slots);
        if ($lastslot) {
            $slot->slot = $lastslot->slot + 1;
        } else {
            $slot->slot = 1;
        }
        if ($quiz->questionsperpage && $numonlastpage >= $quiz->questionsperpage) {
            $slot->page = $maxpage + 1;
        } else {
            $slot->page = $maxpage;
        }
    }

    $slotid = $DB->insert_record('quiz_slots', $slot);

    // Update or insert record in question_reference table.
    $sql = "SELECT DISTINCT qr.id, qr.itemid
              FROM {question} q
              JOIN {question_versions} qv ON q.id = qv.questionid
              JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              JOIN {question_references} qr ON qbe.id = qr.questionbankentryid AND qr.version = qv.version
              JOIN {quiz_slots} qs ON qs.id = qr.itemid
             WHERE q.id = ?
               AND qs.id = ?
               AND qr.component = ?
               AND qr.questionarea = ?";
    $qreferenceitem = $DB->get_record_sql($sql, [$questionid, $slotid, 'mod_quiz', 'slot']);

    if (!$qreferenceitem) {
        // Create a new reference record for questions created already.
        $questionreferences = new stdClass();
        $questionreferences->usingcontextid = context_module::instance($quiz->cmid)->id;
        $questionreferences->component = 'mod_quiz';
        $questionreferences->questionarea = 'slot';
        $questionreferences->itemid = $slotid;
        $questionreferences->questionbankentryid = get_question_bank_entry($questionid)->id;
        $questionreferences->version = null; // Always latest.
        $DB->insert_record('question_references', $questionreferences);

    } else if ($qreferenceitem->itemid === 0 || $qreferenceitem->itemid === null) {
        $questionreferences = new stdClass();
        $questionreferences->id = $qreferenceitem->id;
        $questionreferences->itemid = $slotid;
        $DB->update_record('question_references', $questionreferences);
    } else {
        // If the reference record exits for another quiz.
        $questionreferences = new stdClass();
        $questionreferences->usingcontextid = context_module::instance($quiz->cmid)->id;
        $questionreferences->component = 'mod_quiz';
        $questionreferences->questionarea = 'slot';
        $questionreferences->itemid = $slotid;
        $questionreferences->questionbankentryid = get_question_bank_entry($questionid)->id;
        $questionreferences->version = null; // Always latest.
        $DB->insert_record('question_references', $questionreferences);
    }

    $trans->allow_commit();

    // Log slot created event.
    $cm = get_coursemodule_from_instance('quiz', $quiz->id);
    $event = \mod_quiz\event\slot_created::create([
        'context' => context_module::instance($cm->id),
        'objectid' => $slotid,
        'other' => [
            'quizid' => $quiz->id,
            'slotnumber' => $slot->slot,
            'page' => $slot->page
        ]
    ]);
    $event->trigger();
}

/**
 * Move all the section headings in a certain slot range by a certain offset.
 *
 * @param int $quizid the id of a quiz
 * @param int $direction amount to adjust section heading positions. Normally +1 or -1.
 * @param int $afterslot adjust headings that start after this slot.
 * @param int|null $beforeslot optionally, only adjust headings before this slot.
 */
function quiz_update_section_firstslots($quizid, $direction, $afterslot, $beforeslot = null) {
    global $DB;
    $where = 'quizid = ? AND firstslot > ?';
    $params = [$direction, $quizid, $afterslot];
    if ($beforeslot) {
        $where .= ' AND firstslot < ?';
        $params[] = $beforeslot;
    }
    $firstslotschanges = $DB->get_records_select_menu('quiz_sections',
            $where, $params, '', 'firstslot, firstslot + ?');
    update_field_with_unique_index('quiz_sections', 'firstslot', $firstslotschanges, ['quizid' => $quizid]);
}

/**
 * Mark the activity completed (if required) and trigger the course_module_viewed event.
 *
 * @param  stdClass $quiz       quiz object
 * @param  stdClass $course     course object
 * @param  stdClass $cm         course module object
 * @param  stdClass $context    context object
 * @since Moodle 3.1
 */
function quiz_view($quiz, $course, $cm, $context) {

    $params = [
        'objectid' => $quiz->id,
        'context' => $context
    ];

    $event = \mod_quiz\event\course_module_viewed::create($params);
    $event->add_record_snapshot('quiz', $quiz);
    $event->trigger();

    // Completion.
    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}

/**
 * Validate permissions for creating a new attempt and start a new preview attempt if required.
 *
 * @param  quiz_settings $quizobj quiz object
 * @param  access_manager $accessmanager quiz access manager
 * @param  bool $forcenew whether was required to start a new preview attempt
 * @param  int $page page to jump to in the attempt
 * @param  bool $redirect whether to redirect or throw exceptions (for web or ws usage)
 * @return array an array containing the attempt information, access error messages and the page to jump to in the attempt
 * @since Moodle 3.1
 */
function quiz_validate_new_attempt(quiz_settings $quizobj, access_manager $accessmanager, $forcenew, $page, $redirect) {
    global $DB, $USER;
    $timenow = time();

    if ($quizobj->is_preview_user() && $forcenew) {
        $accessmanager->current_attempt_finished();
    }

    // Check capabilities.
    if (!$quizobj->is_preview_user()) {
        $quizobj->require_capability('mod/quiz:attempt');
    }

    // Check to see if a new preview was requested.
    if ($quizobj->is_preview_user() && $forcenew) {
        // To force the creation of a new preview, we mark the current attempt (if any)
        // as abandoned. It will then automatically be deleted below.
        $DB->set_field('quiz_attempts', 'state', quiz_attempt::ABANDONED,
                ['quiz' => $quizobj->get_quizid(), 'userid' => $USER->id]);
    }

    // Look for an existing attempt.
    $attempts = quiz_get_user_attempts($quizobj->get_quizid(), $USER->id, 'all', true);
    $lastattempt = end($attempts);

    $attemptnumber = null;
    if (
        $lastattempt
        && in_array($lastattempt->state, [quiz_attempt::NOT_STARTED, quiz_attempt::IN_PROGRESS, quiz_attempt::OVERDUE])
    ) {
        // If an in-progress or not-started attempt exists, check password then redirect to it.
        $currentattemptid = $lastattempt->id;

        $messages = $accessmanager->prevent_access();

        // If the attempt is now overdue, deal with that.
        $quizobj->create_attempt_object($lastattempt)->handle_if_time_expired($timenow, true);

        // And, if the attempt is now no longer in progress, redirect to the appropriate place.
        if ($lastattempt->state == quiz_attempt::ABANDONED || $lastattempt->state == quiz_attempt::FINISHED) {
            if ($redirect) {
                redirect($quizobj->review_url($lastattempt->id));
            } else {
                throw new moodle_exception('attemptalreadyclosed', 'quiz', $quizobj->view_url());
            }
        }

        // If the page number was not explicitly in the URL, go to the current page.
        if ($page == -1) {
            $page = $lastattempt->currentpage;
        }

    } else {
        while ($lastattempt && $lastattempt->preview) {
            $lastattempt = array_pop($attempts);
        }

        // Get number for the next or unfinished attempt.
        if ($lastattempt) {
            $attemptnumber = $lastattempt->attempt + 1;
        } else {
            $lastattempt = false;
            $attemptnumber = 1;
        }
        $currentattemptid = null;

        $messages = $accessmanager->prevent_access() +
            $accessmanager->prevent_new_attempt(count($attempts), $lastattempt);

        if ($page == -1) {
            $page = 0;
        }
    }
    return [$currentattemptid, $attemptnumber, $lastattempt, $messages, $page];
}

/**
 * Prepare and start a new attempt deleting the previous preview attempts.
 *
 * @param quiz_settings $quizobj quiz object
 * @param int $attemptnumber the attempt number
 * @param stdClass $lastattempt last attempt object
 * @param bool $offlineattempt whether is an offline attempt or not
 * @param array $forcedrandomquestions slot number => question id. Used for random questions,
 *      to force the choice of a particular actual question. Intended for testing purposes only.
 * @param array $forcedvariants slot number => variant. Used for questions with variants,
 *      to force the choice of a particular variant. Intended for testing purposes only.
 * @param int $userid Specific user id to create an attempt for that user, null for current logged in user
 * @return stdClass the new attempt
 * @since  Moodle 3.1
 */
function quiz_prepare_and_start_new_attempt(quiz_settings $quizobj, $attemptnumber, $lastattempt,
        $offlineattempt = false, $forcedrandomquestions = [], $forcedvariants = [], $userid = null) {
    global $DB, $USER;

    if ($userid === null) {
        $userid = $USER->id;
        $ispreviewuser = $quizobj->is_preview_user();
    } else {
        $ispreviewuser = has_capability('mod/quiz:preview', $quizobj->get_context(), $userid);
    }
    // Delete any previous preview attempts belonging to this user.
    quiz_delete_previews($quizobj->get_quiz(), $userid);

    $quba = question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
    $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);

    $attempt = $DB->get_record(
        'quiz_attempts',
        [
            'quiz' => $quizobj->get_quizid(),
            'userid' => $userid,
            'preview' => 0,
            'state' => quiz_attempt::NOT_STARTED,
        ],
    );
    if (!$attempt) {
        // Create the new attempt and initialize the question sessions.
        $timenow = time(); // Update time now, in case the server is running really slowly.
        $attempt = quiz_create_attempt($quizobj, $attemptnumber, $lastattempt, $timenow, $ispreviewuser, $userid);

        if (!($quizobj->get_quiz()->attemptonlast && $lastattempt)) {
            $attempt = quiz_start_new_attempt(
                $quizobj,
                $quba,
                $attempt,
                $attemptnumber,
                $timenow,
                $forcedrandomquestions,
                $forcedvariants,
            );
        } else {
            $attempt = quiz_start_attempt_built_on_last($quba, $attempt, $lastattempt);
        }
    }

    $transaction = $DB->start_delegated_transaction();

    // Init the timemodifiedoffline for offline attempts.
    if ($offlineattempt) {
        $attempt->timemodifiedoffline = $attempt->timemodified;
    }
    $attempt = quiz_attempt_save_started($quizobj, $quba, $attempt);

    $transaction->allow_commit();

    return $attempt;
}

/**
 * Check if the given calendar_event is either a user or group override
 * event for quiz.
 *
 * @param calendar_event $event The calendar event to check
 * @return bool
 */
function quiz_is_overriden_calendar_event(\calendar_event $event) {
    global $DB;

    if (!isset($event->modulename)) {
        return false;
    }

    if ($event->modulename != 'quiz') {
        return false;
    }

    if (!isset($event->instance)) {
        return false;
    }

    if (!isset($event->userid) && !isset($event->groupid)) {
        return false;
    }

    $overrideparams = [
        'quiz' => $event->instance
    ];

    if (isset($event->groupid)) {
        $overrideparams['groupid'] = $event->groupid;
    } else if (isset($event->userid)) {
        $overrideparams['userid'] = $event->userid;
    }

    return $DB->record_exists('quiz_overrides', $overrideparams);
}

/**
 * Get quiz attempt and handling error.
 *
 * @param int $attemptid the id of the current attempt.
 * @param int|null $cmid the course_module id for this quiz.
 * @return quiz_attempt all the data about the quiz attempt.
 */
function quiz_create_attempt_handling_errors($attemptid, $cmid = null) {
    try {
        $attempobj = quiz_attempt::create($attemptid);
    } catch (moodle_exception $e) {
        if (!empty($cmid)) {
            list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'quiz');
            $continuelink = new moodle_url('/mod/quiz/view.php', ['id' => $cmid]);
            $context = context_module::instance($cm->id);
            if (has_capability('mod/quiz:preview', $context)) {
                throw new moodle_exception('attempterrorcontentchange', 'quiz', $continuelink);
            } else {
                throw new moodle_exception('attempterrorcontentchangeforuser', 'quiz', $continuelink);
            }
        } else {
            throw new moodle_exception('attempterrorinvalid', 'quiz');
        }
    }
    if (!empty($cmid) && $attempobj->get_cmid() != $cmid) {
        throw new moodle_exception('invalidcoursemodule');
    } else {
        return $attempobj;
    }
}
