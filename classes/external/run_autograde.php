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
 * Grades all essay answers grouped by question. Each question's student answers
 * are sent to the AI in a single batch prompt, reducing API calls from N (per essay)
 * to Q (per question). Provider-agnostic — works with any Moodle AI provider.
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
     * Grade essay questions of a quiz, grouped by question for efficiency.
     *
     * Groups all student answers by question slot, sends one AI request per
     * question containing all student answers, then splits and applies grades.
     *
     * @param int $quizid The ID of the quiz.
     * @param int $courseid The ID of the course.
     * @return string JSON result summary.
     */
    public static function execute($quizid, $courseid) {
        // Extend time limit — grading with API calls can take several minutes.
        set_time_limit(0);

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['quizID' => $quizid, 'courseID' => $courseid]
        );

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_all_capabilities(quiz_autograde_required_capabilities(), $context, null, false);

        quiz_autograde_require_text_generation();

        $essayattempts = quiz_autograde_get_essay_attempts($quizid);

        // Group ungraded attempts by question slot.
        $byquestion = [];
        $questioninfo = [];
        $alreadyskipped = 0;
        $emptygraded = 0;

        foreach ($essayattempts as $attemptdata) {
            $attempt = (object) $attemptdata;

            // Skip already graded.
            if ($attempt->graded) {
                $alreadyskipped++;
                continue;
            }

            // Skip no grader info.
            if (strlen($attempt->graderinfo) === 0) {
                $alreadyskipped++;
                continue;
            }

            // Empty answers get grade 0 immediately.
            if (strlen($attempt->answer) === 0) {
                quiz_autograde_set_grade($attempt, 0, get_string('noanswer', 'quiz_autograde'));
                $emptygraded++;
                continue;
            }

            $slot = $attempt->slot;

            if (!isset($byquestion[$slot])) {
                $byquestion[$slot] = [];
                $questioninfo[$slot] = (object) [
                    'questiontext' => $attempt->questiontext,
                    'graderinfo' => $attempt->graderinfo,
                    'questionname' => $attempt->questionname,
                ];
            }

            $byquestion[$slot][] = (object) [
                'student' => $attempt->student,
                'answer' => $attempt->answer,
                'maxmark' => $attempt->maxmark,
                'attemptid' => $attempt->attemptid,
                'slot' => $attempt->slot,
            ];
        }

        // If nothing to grade, return early.
        if (empty($byquestion) && $emptygraded === 0) {
            return json_encode([
                'graded' => 0,
                'failed' => 0,
                'skipped' => $alreadyskipped,
                'total' => count($essayattempts),
                'questions' => 0,
            ]);
        }

        $questionsgraded = $emptygraded;
        $questionsfailed = 0;
        $failuredetails = [];
        $questioncount = count($byquestion);
        $qprocessed = 0;

        // Process each question group.
        foreach ($byquestion as $slot => $students) {
            $qprocessed++;
            $qinfo = $questioninfo[$slot];
            $studentcount = count($students);

            // Send one AI request for all students on this question.
            $results = quiz_autograde_grade_question_batch($students, $qinfo, $context->id);

            // Apply grades.
            foreach ($students as $i => $s) {
                $r = $results[$i] ?? (object) ['success' => false, 'error' => 'No result from AI'];

                if ($r->success) {
                    $attempt = (object) [
                        'attemptid' => $s->attemptid,
                        'slot' => $s->slot,
                        'maxmark' => $s->maxmark,
                    ];
                    try {
                        quiz_autograde_set_grade($attempt, $r->grade, $r->comment);
                        $questionsgraded++;
                    } catch (\Exception $e) {
                        $questionsfailed++;
                        $failuredetails[] = [
                            'student' => $s->student,
                            'question' => $qinfo->questionname,
                            'error' => $e->getMessage(),
                        ];
                    }
                } else {
                    $questionsfailed++;
                    $failuredetails[] = [
                        'student' => $s->student,
                        'question' => $qinfo->questionname,
                        'error' => $r->error ?? 'Unknown error',
                    ];
                }
            }

            // Throttle between question groups.
            if ($qprocessed < $questioncount && QUIZ_AUTOGRADE_DEFAULT_THROTTLE_SECONDS > 0) {
                sleep(QUIZ_AUTOGRADE_DEFAULT_THROTTLE_SECONDS);
            }
        }

        $result = [
            'graded' => $questionsgraded,
            'failed' => $questionsfailed,
            'skipped' => $alreadyskipped,
            'total' => count($essayattempts),
            'questions' => $questioncount,
        ];

        if ($questionsfailed > 0) {
            $result['failures'] = $failuredetails;
        }

        return json_encode($result);
    }
}
