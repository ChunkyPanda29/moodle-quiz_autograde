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
 * Callback implementations for AutoGrade
 *
 * @package    quiz_autograde
 * @copyright  2026 Christian Grévisse <christian.grevisse@uni.lu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/engine/lib.php');

use core_ai\manager;

/**
 * Returns the capabilities required to run AutoGrade.
 *
 * @return array An array of capability strings.
 */
function quiz_autograde_required_capabilities() {
    return ['mod/quiz:grade', 'mod/quiz:viewreports'];
}

/**
 * Retrieves essay attempts for a given quiz.
 *
 * @param int $quizid The ID of the quiz.
 * @return array An array of essay attempts.
 */
function quiz_autograde_get_essay_attempts($quizid) {
    global $DB;

    $essayattempts = [];

    $quizattempts = $DB->get_records_sql(
        "SELECT qa.id,
                qa.userid,
                qa.uniqueid,
                qa.attempt,
                qa.state,
                u.firstname,
                u.lastname,
                u.username,
                u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
            FROM {quiz_attempts} qa
            JOIN {user} u ON u.id = qa.userid
            WHERE qa.quiz = :quizid
            AND qa.preview = 0
            AND qa.state = 'finished'
            AND u.deleted = 0
        ORDER BY u.lastname, u.firstname, qa.attempt",
        ['quizid' => $quizid]
    );

    $dm = new question_engine_data_mapper();

    foreach ($quizattempts as $attempt) {
        $quba = $dm->load_questions_usage_by_activity($attempt->uniqueid);
        foreach ($quba->get_slots() as $slot) {
            $qa = $quba->get_question_attempt($slot);

            // Only essay questions.
            if ($qa->get_question()->get_type_name() !== 'essay') {
                continue;
            }

            // Only finished attempts.
            if (!$qa->get_state()->is_finished()) {
                continue;
            }

            $question = $qa->get_question();

            $questionlabel = get_string('question', 'quiz') . ' ' . $slot;
            $questionname = (string) $qa->get_question()->name;
            if ($questionname !== '') {
                $questionlabel .= ': ' . $questionname;
            }

            $questiontext = '';
            if (!empty($question->questiontext)) {
                $rawquestiontext = $question->format_text(
                    $question->questiontext,
                    $question->questiontextformat,
                    $qa,
                    'question',
                    'questiontext',
                    $question->id
                );
                $questiontext = trim(html_to_text($rawquestiontext, 0, false));
            }

            $graderinfo = '';
            if (property_exists($question, 'graderinfo') && !empty($question->graderinfo)) {
                $graderinfoformat = property_exists($question, 'graderinfoformat') ? $question->graderinfoformat : FORMAT_HTML;
                $rawgraderinfo = $question->format_text(
                    $question->graderinfo,
                    $graderinfoformat,
                    $qa,
                    'qtype_essay',
                    'graderinfo',
                    $question->id
                );
                $graderinfo = trim(html_to_text($rawgraderinfo, 0, false));
            }

            $answer = trim(html_to_text($qa->get_last_qt_var('answer'), 0, false));

            $isgraded = $qa->get_state()->is_graded();
            $mark = $qa->get_mark();
            $maxmark = $qa->get_max_mark();
            [$manualcomment, $manualcommentformat] = $qa->get_manual_comment();
            $gradingcomment = '';
            if (!empty($manualcomment)) {
                $rawcomment = format_text($manualcomment, $manualcommentformat, ['para' => false]);
                $gradingcomment = trim(html_to_text($rawcomment, 0, false));
            }

            $essayattempts[] = [
                'student' => fullname($attempt) . ' (' . $attempt->username . ')',
                'attemptid' => (int) $attempt->id,
                'slot' => (int) $slot,
                'attempt' => (int) $attempt->attempt,
                'state' => (string) $attempt->state,
                'questionattempt' => $qa,
                'questionid' => $question->id,
                'questionname' => $questionlabel,
                'questiontext' => $questiontext,
                'graderinfo' => $graderinfo,
                'answer' => $answer,
                'graded' => $isgraded,
                'grade' => $mark,
                'maxmark' => $maxmark,
                'gradingcomment' => $gradingcomment,
            ];
        }
    }

    return $essayattempts;
}

/**
 * Aggregates essay attempt statistics.
 *
 * @param array $essayattempts An array of essay attempts.
 * @return array An array of statistics per question.
 */
function quiz_autograde_get_essay_stats($essayattempts) {
    $questions = [];

    foreach ($essayattempts as $attempt) {
        $questionid = $attempt['questionid'];

        if (!array_key_exists($questionid, $questions)) {
            $questions[$questionid] = [
                'name' => $attempt['questionname'],
                'hasgraderinfo' => !empty($attempt['graderinfo']) ? get_string('yes') : get_string('no'),
                'total' => 0,
                'graded' => 0,
            ];
        }

        $questions[$questionid]['total']++;

        if ($attempt['graded']) {
            $questions[$questionid]['graded']++;
        }
    }

    return $questions;
}

/**
 * Checks if the necessary AI capabilities are available and enabled for AutoGrade, and throws an exception if not.
 *
 * @throws Exception If the required AI capabilities are not available or not enabled.
 */
function quiz_autograde_require_text_generation() {
    $actionclass = \core_ai\aiactions\generate_text::class;

    // In Moodle 5.0, some manager methods are no longer static.
    global $CFG;
    if ($CFG->version >= 2025041400) {
        $manager = \core\di::get(\core_ai\manager::class);
        $aichecks = $manager->is_action_available($actionclass) && $manager->is_action_enabled('quiz_autograde', $actionclass);
    } else {
        $aichecks = manager::is_action_available($actionclass) && manager::is_action_enabled('quiz_autograde', $actionclass);
    }

    if (!$aichecks) {
        throw new \Exception(get_string('notextgeneration', 'quiz_autograde'));
    }
}

/**
 * Default number of retry attempts per question when AI grading fails.
 */
define('QUIZ_AUTOGRADE_DEFAULT_RETRIES', 2);

/**
 * Default delay in seconds between API calls to avoid rate limiting.
 * Set to 0 for no delay, or a positive number for seconds between calls.
 */
define('QUIZ_AUTOGRADE_DEFAULT_THROTTLE_SECONDS', 2);

/**
 * Generates a grade for an essay attempt using an AI service, with retry support.
 *
 * @param object $attempt The essay attempt object containing necessary information for grading.
 * @param int $contextid The ID of the context.
 * @param int $maxretries Maximum number of retry attempts on failure (default: QUIZ_AUTOGRADE_DEFAULT_RETRIES).
 *
 * @return object An object containing:
 *               - success (bool): Whether grading succeeded
 *               - grade (float|null): The grade (null on failure)
 *               - comment (string|null): The comment (null on failure)
 *               - error (string|null): Error message on failure
 */
function quiz_autograde_generate_grade($attempt, $contextid, $maxretries = QUIZ_AUTOGRADE_DEFAULT_RETRIES) {
    global $USER;

    $prompt = <<<EOD
    You are a helpful and precise assistant for grading student answers to essay questions.

    This is the question:
    ---------------------
    {$attempt->questiontext}
    ---------------------
    This is the model answer and grading information for the question, provided by the teacher:
    ---------------------
    {$attempt->graderinfo}
    ---------------------
    This is the student's answer that you need to grade:
    ---------------------
    {$attempt->answer}
    ---------------------
    Suggest an accurate grade for the student's answer to the given question considering the grading information,
    and provide a concise explanation for the grade. The grade should be a number between 0 and {$attempt->maxmark}.

    Give your answer in the following JSON format:

    ---------------------
    {
        "grade": (the grade as a number),
        "comment": (the explanation for the grade)
    }
    ---------------------

    Only return valid JSON in the specified format, without any additional text. Make sure the JSON is properly formatted.
    EOD;

    $lasterror = '';

    for ($retry = 0; $retry <= $maxretries; $retry++) {
        // If retrying, add a short delay to let rate limits reset.
        if ($retry > 0) {
            $delay = QUIZ_AUTOGRADE_DEFAULT_THROTTLE_SECONDS * $retry;
            sleep($delay);
        }

        try {
            $action = new \core_ai\aiactions\generate_text($contextid, $USER->id, $prompt);
            $manager = \core\di::get(\core_ai\manager::class);
            $response = $manager->process_action($action);

            if (!$response->get_success()) {
                $errorcode = $response->get_errorcode();
                $error = $response->get_errormessage();
                $lasterror = "Error {$errorcode}: {$error}";

                // If rate limited (429), retry — the provider may have failed over to a new key.
                // If auth error (401/403), retry — same reason.
                // For other errors, don't retry.
                if (!in_array($errorcode, [429, 401, 403, 500, 503])) {
                    return (object) [
                        'success' => false,
                        'grade' => null,
                        'comment' => null,
                        'error' => $lasterror,
                    ];
                }
                continue; // Retry.
            }

            $generatedcontent = $response->get_response_data()['generatedcontent'];

            // Parse result.
            $data = json_decode($generatedcontent);

            if ($data === null || !isset($data->grade) || !is_numeric($data->grade)) {
                // Invalid JSON response — don't retry, it's a prompt/model issue.
                return (object) [
                    'success' => false,
                    'grade' => null,
                    'comment' => null,
                    'error' => get_string('invalidresponse', 'quiz_autograde', $generatedcontent),
                ];
            }

            return (object) [
                'success' => true,
                'grade' => $data->grade,
                'comment' => $data->comment ?? get_string('noexplanation', 'quiz_autograde'),
                'error' => null,
            ];

        } catch (\Exception $e) {
            $lasterror = $e->getMessage();
            continue; // Retry on exception.
        }
    }

    // All retries exhausted.
    return (object) [
        'success' => false,
        'grade' => null,
        'comment' => null,
        'error' => get_string('retryexhausted', 'quiz_autograde', $lasterror),
    ];
}

/**
 * Sets the grade and comment for an essay question attempt, and triggers necessary updates and events.
 *
 * @param object $attempt The essay attempt object.
 * @param float $grade The grade to set for the attempt.
 * @param string $comment The comment to set for the attempt.
 */
function quiz_autograde_set_grade($attempt, $grade, $comment) {
    global $DB;

    $transaction = $DB->start_delegated_transaction();

    // Set manual grade and comment.
    $attemptobj = \mod_quiz\quiz_attempt::create($attempt->attemptid);
    $quba = \question_engine::load_questions_usage_by_activity($attemptobj->get_uniqueid());
    $quba->manual_grade($attempt->slot, $comment, $grade, FORMAT_HTML);
    \question_engine::save_questions_usage_by_activity($quba);

    // Regrade attempt.
    $update = new \stdClass();
    $update->id = $attemptobj->get_attemptid();
    $update->timemodified = time();
    $update->sumgrades = $quba->get_total_mark();
    $DB->update_record('quiz_attempts', $update);
    $attemptobj->get_quizobj()->get_grade_calculator()->recompute_final_grade($attemptobj->get_userid());

    // Log this action.
    $params = [
        'objectid' => $attemptobj->get_question_attempt($attempt->slot)->get_question_id(),
        'courseid' => $attemptobj->get_courseid(),
        'context' => $attemptobj->get_quizobj()->get_context(),
        'other' => [
            'quizid' => $attemptobj->get_quizid(),
            'attemptid' => $attemptobj->get_attemptid(),
            'slot' => $attempt->slot,
        ],
    ];
    $event = \mod_quiz\event\question_manually_graded::create($params);
    $event->trigger();

    $transaction->allow_commit();
}
