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
 * AutoGrade feature
 *
 * @package    quiz_autograde
 * @copyright  2026 Christian Grévisse <christian.grevisse@uni.lu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_quiz\local\reports\attempts_report;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/mod/quiz/report/autograde/lib.php');

/**
 * Report class for AutoGrade.
 */
class quiz_autograde_report extends attempts_report {
    /**
     * Displays the report.
     *
     * @param object $quiz The quiz object.
     * @param object $cm The course module object.
     * @param object $course The course object.
     * @return bool True on success, false on failure.
     */
    public function display($quiz, $cm, $course) {
        global $OUTPUT, $PAGE;

        $context = \context_course::instance($course->id);
        require_all_capabilities(quiz_autograde_required_capabilities(), $context, null, false);

        parent::print_header_and_tabs($cm, $course, $quiz, 'autograde');

        $essayattempts = quiz_autograde_get_essay_attempts($quiz->id);
        $stats = quiz_autograde_get_essay_stats($essayattempts);

        if (empty($stats)) {
            echo $OUTPUT->notification(get_string('noessayanswers', 'quiz_autograde'));
            return true;
        }

        $table = new html_table();
        $table->head = [
            get_string('question', 'quiz'),
            get_string('graderinfoheader', 'qtype_essay'),
            get_string('answers', 'question'),
            get_string('alreadygraded', 'quiz_grading'),
        ];
        $table->data = $stats;

        echo html_writer::table($table);

        echo html_writer::tag('button', get_string('runautograde', 'quiz_autograde'), ["class" => "btn btn-primary mt-3",
        "id" => "id_autogradebutton"]);

        echo html_writer::tag('div', '', ["class" => "mt-3 alert", "id" => "id_autograderesult"]);

        $PAGE->requires->js_call_amd('quiz_autograde/autograde', 'init', [$quiz->id, $course->id]);

        return true;
    }
}
