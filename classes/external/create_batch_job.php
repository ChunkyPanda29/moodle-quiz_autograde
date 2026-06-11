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
use quiz_autograde\batch_processor;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/report/autograde/lib.php');

/**
 * Create a Gemini batch grading job.
 *
 * @package    quiz_autograde
 * @copyright  2026 Kristen Goliath
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_batch_job extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'quizID' => new external_value(PARAM_INT, 'Quiz ID'),
            'courseID' => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    public static function execute_returns() {
        return new external_value(PARAM_TEXT, 'JSON result with job details');
    }

    /**
     * Create a batch grading job for essay questions in a quiz.
     *
     * @param int $quizid
     * @param int $courseid
     * @return string JSON result
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

        $processor = new batch_processor();

        if (!$processor->is_available()) {
            return json_encode([
                'success' => false,
                'error' => get_string('noapikey', 'quiz_autograde'),
            ]);
        }

        $essayattempts = quiz_autograde_get_essay_attempts($quizid);
        $result = $processor->create_batch_job($essayattempts, $quizid, $courseid);

        return json_encode($result);
    }
}
