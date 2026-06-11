<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin strings are defined here.
 *
 * @package     quiz_autograde
 * @category    string
 * @copyright   2026 Christian Grévisse <christian.grevisse@uni.lu>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['autograde'] = 'AutoGrade';

$string['gradinginprogress'] = 'Grading in progress, please wait...';
$string['invalidresponse'] = 'Invalid response: {$a}';
$string['noanswer'] = 'No answer provided.';
$string['noessayanswers'] = 'No essay answers found for this quiz.';
$string['noexplanation'] = 'No explanation provided.';
$string['notextgeneration'] = 'Text generation action is not available or not enabled for AutoGrade.';
$string['numberofquestionsgraded'] = '{$a} questions graded.';
$string['questionsfailed'] = '{$a} questions failed (see details below).';
$string['questionsgraded'] = '{$a} questions graded successfully.';
$string['questionsskipped'] = '{$a} questions skipped (already graded or no grading info).';
$string['retryexhausted'] = 'All retry attempts failed. Last error: {$a}';

$string['pluginname'] = 'AutoGrade';

$string['privacy:metadata:ai_provider'] = 'Data sent to external AI providers';
$string['privacy:metadata:ai_provider:answer'] = 'The student\'s answer to the question';
$string['privacy:metadata:ai_provider:graderinfo'] = 'Grading information';
$string['privacy:metadata:ai_provider:maxmark'] = 'The maximum mark available for the question';
$string['privacy:metadata:ai_provider:questiontext'] = 'The question text for which AI grading is requested';

$string['runautograde'] = 'Run AutoGrade';
