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

namespace quiz_autograde\privacy;

use core_privacy\local\metadata\collection;

/**
 * Privacy Subsystem for quiz_autograde.
 *
 * @package    quiz_autograde
 * @copyright  2026 Christian Grévisse <christian.grevisse@uni.lu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_privacy\local\metadata\provider {
    /**
     * Returns meta data about this system.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection A listing of user data stored through this system.
     */
    public static function get_metadata(collection $collection): collection {

        $collection->add_external_location_link('ai_provider', [
            'questiontext' => 'privacy:metadata:ai_provider:questiontext',
            'graderinfo' => 'privacy:metadata:ai_provider:graderinfo',
            'answer' => 'privacy:metadata:ai_provider:answer',
            'maxmark' => 'privacy:metadata:ai_provider:maxmark',
        ], 'privacy:metadata:ai_provider');

        return $collection;
    }
}
