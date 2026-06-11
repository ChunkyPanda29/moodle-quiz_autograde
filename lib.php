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
define('QUIZ_AUTOGRADE_DEFAULT_RETRIES', 1);

/**
 * Default delay in seconds between API calls to avoid rate limiting.
 */
define('QUIZ_AUTOGRADE_DEFAULT_THROTTLE_SECONDS', 3);

/**
 * Grades all student answers for a single question in one AI call.
 *
 * Builds a batch prompt containing all student answers for the same question,
 * sends one AI request, and parses the JSON array response.
 *
 * @param array $students Array of student attempt objects, each with:
 *               - student (string): Student name
 *               - answer (string): Their answer text
 *               - maxmark (float): Max mark for this question
 *               - attemptid (int): Quiz attempt ID
 *               - slot (int): Question slot
 * @param object $questioninfo Question context with:
 *               - questiontext (string): The question text
 *               - graderinfo (string): Teacher's grading criteria
 *               - questionname (string): Question display name
 * @param int $contextid The context ID for the AI action.
 * @return array Array of results, indexed by student position (0-based), each:
 *               - success (bool)
 *               - grade (float|null)
 *               - comment (string|null)
 *               - error (string|null)
 */
function quiz_autograde_grade_question_batch(array $students, object $questioninfo, int $contextid): array {
    global $USER;

    $maxmark = $students[0]->maxmark ?? 0;
    $count = count($students);

    // Build the student answers section.
    $answerssection = '';
    foreach ($students as $i => $s) {
        $idx = $i + 1;
        $answertext = strlen($s->answer) > 0 ? $s->answer : '(No answer provided)';
        $answerssection .= "[{$idx}] {$s->student}:\n---\n{$answertext}\n---\n\n";
    }

    $prompt = <<<EOD
You are a helpful and precise assistant for grading student answers to an essay question.

This is the question:
---------------------
{$questioninfo->questiontext}
---------------------

This is the model answer and grading information, provided by the teacher:
---------------------
{$questioninfo->graderinfo}
---------------------

The maximum mark is {$maxmark}. You are grading {$count} student answers.

Student answers:
{$answerssection}
Grade each student's answer. Return a JSON array with exactly {$count} objects in order:

[
  {"index": 1, "grade": <number between 0 and {$maxmark}>, "comment": "<brief explanation>"},
  {"index": 2, "grade": <number between 0 and {$maxmark}>, "comment": "<brief explanation>"},
  ...]

Only return the JSON array. No additional text. Make sure the JSON is valid.
EOD;

    $lasterror = '';

    for ($retry = 0; $retry <= QUIZ_AUTOGRADE_DEFAULT_RETRIES; $retry++) {
        if ($retry > 0) {
            sleep(QUIZ_AUTOGRADE_DEFAULT_THROTTLE_SECONDS * $retry);
        }

        try {
            $action = new \core_ai\aiactions\generate_text($contextid, $USER->id, $prompt);
            $manager = \core\di::get(\core_ai\manager::class);
            $response = $manager->process_action($action);

            if (!$response->get_success()) {
                $errorcode = $response->get_errorcode();
                $error = $response->get_errormessage();
                $lasterror = "Error {$errorcode}: {$error}";

                // Auth or server errors — retry. Rate limit — won't help, skip.
                if (!in_array($errorcode, [401, 403, 500, 503])) {
                    break;
                }
                continue;
            }

            $generatedcontent = $response->get_response_data()['generatedcontent'];

            // Strip markdown code fences if present.
            $generatedcontent = trim($generatedcontent);
            if (preg_match('/^```(?:json)?\s*\n?/i', $generatedcontent)) {
                $generatedcontent = preg_replace('/^```(?:json)?\s*\n?/i', '', $generatedcontent);
                $generatedcontent = preg_replace('/\n?```\s*$/i', '', $generatedcontent);
                $generatedcontent = trim($generatedcontent);
            }

            $data = json_decode($generatedcontent);

            if (!is_array($data)) {
                $lasterror = 'AI did not return a JSON array. Response: ' . substr($generatedcontent, 0, 200);
                break;
            }

            // Map results back to students by index.
            $results = [];
            foreach ($data as $item) {
                $idx = (int)($item->index ?? 0) - 1; // Convert 1-based to 0-based.
                if ($idx < 0 || $idx >= $count) {
                    continue;
                }
                if (!isset($item->grade) || !is_numeric($item->grade)) {
                    $results[$idx] = (object) [
                        'success' => false,
                        'grade' => null,
                        'comment' => null,
                        'error' => 'Invalid grade in response',
                    ];
                    continue;
                }
                $results[$idx] = (object) [
                    'success' => true,
                    'grade' => max(0, min($maxmark, (float)$item->grade)),
                    'comment' => $item->comment ?? get_string('noexplanation', 'quiz_autograde'),
                    'error' => null,
                ];
            }

            // Fill in any students that were missing from the response.
            for ($i = 0; $i < $count; $i++) {
                if (!isset($results[$i])) {
                    $results[$i] = (object) [
                        'success' => false,
                        'grade' => null,
                        'comment' => null,
                        'error' => 'No result returned by AI for this student',
                    ];
                }
            }

            return $results;

        } catch (\Exception $e) {
            $lasterror = $e->getMessage();
            continue;
        }
    }

    // All retries failed — return failures for all students.
    $results = [];
    for ($i = 0; $i < $count; $i++) {
        $results[$i] = (object) [
            'success' => false,
            'grade' => null,
            'comment' => null,
            'error' => $lasterror,
        ];
    }
    return $results;
}

/**
 * Generates a grade for a single essay attempt using an AI service, with retry support.
 *
 * @param object $attempt The essay attempt object containing necessary information for grading.
 * @param int $contextid The ID of the context.
 * @param int $maxretries Maximum number of retry attempts on failure.
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

                // If auth error (401/403), retry with fallback key.
                // Rate limit (429) is per-project so retrying won't help.
                // Server errors (500/503) may be transient, retry.
                if (!in_array($errorcode, [401, 403, 500, 503])) {
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
