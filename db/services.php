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
    'quiz_autograde_run_autograde' => [
        'classname'   => 'quiz_autograde\external\run_autograde',
        'description' => 'Grades essay answers grouped by question using AI (provider-agnostic).',
        'type'        => 'write',
        'ajax'        => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
];
