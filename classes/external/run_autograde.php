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

namespace quiz_autograde\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/report/autograde/lib.php');

/**
 * Class run_autograde
 *
 * @package    quiz_autograde
 * @copyright  2026 Christian Grévisse <christian.grevisse@uni.lu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_autograde extends external_api {
    /**
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'quizID' => new external_value(PARAM_INT, 'Quiz ID'),
            'courseID' => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    /**
     * Returns description of method result value
     * @return external_value
     */
    public static function execute_returns() {
        return new external_value(PARAM_TEXT, 'Result of the grading');
    }

    /**
     * Grade essay questions of a quiz attempt using an AI service.
     *
     * Continues processing on individual failures and returns a summary
     * of graded and failed questions.
     *
     * @param int $quizid The ID of the quiz
     * @param int $courseid The ID of the course
     * @return string The result summary (JSON with graded, failed, details)
     */
    public static function execute($quizid, $courseid) {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['quizID' => $quizid, 'courseID' => $courseid]
        );

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_all_capabilities(quiz_autograde_required_capabilities(), $context, null, false);

        quiz_autograde_require_text_generation();

        $essayattempts = quiz_autograde_get_essay_attempts($quizid);

        $questionsgraded = 0;
        $questionsfailed = 0;
        $questionsskipped = 0;
        $failuredetails = [];

        foreach ($essayattempts as $attempt) {
            $attempt = (object) $attempt;

            $alreadygraded = $attempt->graded;
            $nograderinfo = strlen($attempt->graderinfo) == 0;

            // Skip if already graded or no grader info.
            if ($alreadygraded || $nograderinfo) {
                $questionsskipped++;
                continue;
            }

            if (strlen($attempt->answer) == 0) {
                // No answer provided, set grade to 0.
                quiz_autograde_set_grade($attempt, 0, get_string('noanswer', 'quiz_autograde'));
                $questionsgraded++;
            } else {
                // Grade by LLM (with retry support).
                $result = quiz_autograde_generate_grade($attempt, $context->id);

                if ($result->success) {
                    $grade = max(0, min($attempt->maxmark, $result->grade));
                    $comment = $result->comment;
                    quiz_autograde_set_grade($attempt, $grade, $comment);
                    $questionsgraded++;
                } else {
                    // Don't throw — record the failure and continue.
                    $questionsfailed++;
                    $failuredetails[] = [
                        'student' => $attempt->student,
                        'question' => $attempt->questionname,
                        'error' => $result->error,
                    ];
                }
            }

            // Throttle between API calls to reduce rate limit pressure.
            if (QUIZ_AUTOGRADE_DEFAULT_THROTTLE_SECONDS > 0) {
                sleep(QUIZ_AUTOGRADE_DEFAULT_THROTTLE_SECONDS);
            }
        }

        // Build a JSON result for the frontend to parse.
        $result = [
            'graded' => $questionsgraded,
            'failed' => $questionsfailed,
            'skipped' => $questionsskipped,
            'total' => count($essayattempts),
        ];

        if ($questionsfailed > 0) {
            $result['failures'] = $failuredetails;
        }

        return json_encode($result);
    }
}
