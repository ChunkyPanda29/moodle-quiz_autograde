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
 * External functions and service declaration for AutoGrade
 *
 * @package    quiz_autograde
 * @category   webservice
 * @copyright  2026 Christian Grévisse <christian.grevisse@uni.lu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'quiz_autograde_run' => [
        'classname'   => 'quiz_autograde\external\run_autograde',
        'description' => 'Sends answers to essay questions to an AI service for grading (single-request mode).',
        'type'        => 'write',
        'ajax'        => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'quiz_autograde_create_batch' => [
        'classname'   => 'quiz_autograde\external\create_batch_job',
        'description' => 'Creates a Gemini batch job for bulk essay grading.',
        'type'        => 'write',
        'ajax'        => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'quiz_autograde_poll_batch' => [
        'classname'   => 'quiz_autograde\external\poll_batch_status',
        'description' => 'Polls the status of a Gemini batch grading job.',
        'type'        => 'read',
        'ajax'        => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'quiz_autograde_process_batch' => [
        'classname'   => 'quiz_autograde\external\process_batch_results',
        'description' => 'Processes results from a completed batch job and applies grades.',
        'type'        => 'write',
        'ajax'        => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
];
