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
 * AMD module for the AutoGrade feature.
 *
 * @module quiz_autograde/autograde
 * @copyright 2026 Christian Grevisse
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Selectors from 'quiz_autograde/selectors';

class AutoGrade {
    constructor(quizID, courseID) {
        this.quizID = quizID;
        this.courseID = courseID;
        this.registerEventListeners();
        this.hideMessage();
    }

    registerEventListeners() {
        const autogradeButton = document.querySelector(Selectors.ELEMENTS.AUTOGRADEBUTTON);
        if (autogradeButton) {
            autogradeButton.addEventListener('click', async() => {

                this.hideMessage();

                autogradeButton.setAttribute('disabled', 'disabled');
                const oldText = autogradeButton.innerHTML;
                autogradeButton.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';

                const request = {
                    methodname: 'quiz_autograde_run',
                    args: {
                        quizID: this.quizID,
                        courseID: this.courseID
                    }
                };

                try {
                    const responseObj = await Ajax.call([request])[0];
                    if (responseObj.error) {
                        this.showMessage(responseObj.error.exception.message, true);
                    } else {
                        this.showMessage(responseObj, false);
                    }
                } catch (error) {
                    this.showMessage(error.message, true);
                } finally {
                    autogradeButton.removeAttribute('disabled');
                    autogradeButton.innerHTML = oldText;
                }
            });
        }
    }

    showMessage(message, error) {
        const resultField = document.querySelector(Selectors.ELEMENTS.AUTOGRADERESULT);
        if (resultField) {
            resultField.innerHTML = message;
            resultField.style.display = 'block';
            resultField.classList.add(error ? 'alert-danger' : 'alert-info');
        }
    }

    hideMessage() {
        const resultField = document.querySelector(Selectors.ELEMENTS.AUTOGRADERESULT);
        if (resultField) {
            resultField.innerHTML = '';
            resultField.style.display = 'none';
            resultField.classList.remove('alert-info', 'alert-danger');
        }
    }
}

export const init = (quizID, courseID) => {
    new AutoGrade(quizID, courseID);
};
