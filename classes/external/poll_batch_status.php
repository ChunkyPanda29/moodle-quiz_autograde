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
 * Poll a Gemini batch job's status.
 *
 * @package    quiz_autograde
 * @copyright  2026 Kristen Goliath
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class poll_batch_status extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'jobName' => new external_value(PARAM_TEXT, 'Gemini batch job name'),
            'courseID' => new external_value(PARAM_INT, 'Course ID for capability check'),
        ]);
    }

    public static function execute_returns() {
        return new external_value(PARAM_TEXT, 'JSON result with job status');
    }

    /**
     * Poll the status of a batch grading job.
     *
     * @param string $jobname The batch job name.
     * @param int $courseid The course ID.
     * @return string JSON result
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
        $result = $processor->poll_batch_status($jobname);

        return json_encode($result);
    }
}
