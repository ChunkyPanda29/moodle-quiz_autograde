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
 * Process results from a completed Gemini batch job and apply grades.
 *
 * @package    quiz_autograde
 * @copyright  2026 Kristen Goliath
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_batch_results extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'jobName' => new external_value(PARAM_TEXT, 'Gemini batch job name'),
            'courseID' => new external_value(PARAM_INT, 'Course ID for capability check'),
        ]);
    }

    public static function execute_returns() {
        return new external_value(PARAM_TEXT, 'JSON result with grading summary');
    }

    /**
     * Process results from a completed batch job and apply grades.
     *
     * @param string $jobname The batch job name.
     * @param int $courseid The course ID.
     * @return string JSON result with graded/failed counts.
     */
    public static function execute($jobname, $courseid) {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['jobName' => $jobname, 'courseID' => $courseid]
        );

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_all_capabilities(quiz_autograde_required_capabilities(), $context, null, false);

        $processor = new batch_processor();

        // First, fetch the latest status to get the responses.
        $status = $processor->poll_batch_status($jobname);

        if (!$status->success) {
            return json_encode([
                'success' => false,
                'error' => $status->error ?? 'Failed to fetch batch status.',
            ]);
        }

        if (!$status->done) {
            return json_encode([
                'success' => false,
                'error' => get_string('batchnotready', 'quiz_autograde'),
            ]);
        }

        if ($status->status !== 'JOB_STATE_SUCCEEDED') {
            return json_encode([
                'success' => false,
                'error' => get_string('batchfailed', 'quiz_autograde') . ' ' . ($status->error ?? ''),
            ]);
        }

        // Process inline responses.
        $responses = $status->responses ?? [];
        if (empty($responses)) {
            return json_encode([
                'success' => false,
                'error' => get_string('noresponses', 'quiz_autograde'),
            ]);
        }

        $result = $processor->process_batch_results($jobname, $responses);

        return json_encode([
            'success' => true,
            'graded' => $result->graded,
            'failed' => $result->failed,
            'failures' => $result->failures ?? [],
        ]);
    }
}
