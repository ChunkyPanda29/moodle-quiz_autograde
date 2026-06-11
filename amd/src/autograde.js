/**
 * AMD module for the AutoGrade feature.
 *
 * Groups all student answers by question and sends one AI request per question
 * (instead of one per essay). Provider-agnostic — works with any Moodle AI provider.
 *
 * @module quiz_autograde/autograde
 * @copyright 2026 Kristen Goliath
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Selectors from 'quiz_autograde/selectors';

export const init = (quizID, courseID) => {
    new AutoGrade(quizID, courseID);
};

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
            autogradeButton.addEventListener('click', async () => {
                this.hideMessage();
                autogradeButton.setAttribute('disabled', 'disabled');
                this.showStatus('Grading essays... this may take a few minutes.');

                try {
                    await this.runGrading();
                } catch (error) {
                    this.showMessage(error.message || 'Unknown error occurred.', true);
                }
                autogradeButton.removeAttribute('disabled');
            });
        }
    }

    async runGrading() {
        try {
            const response = await Ajax.call([{
                methodname: 'quiz_autograde_run',
                args: {
                    quizID: this.quizID,
                    courseID: this.courseID,
                },
            }])[0];

            // Check for Moodle exception.
            if (typeof response === 'object' && response.error) {
                const msg = response.error.exception
                    ? response.error.exception.message
                    : JSON.stringify(response.error);
                this.showMessage(msg, true);
                return;
            }

            const result = typeof response === 'string' ? JSON.parse(response) : response;
            this.handleGradingResult(result);

        } catch (error) {
            this.showMessage(error.message || 'AJAX call failed.', true);
        }
    }

    handleGradingResult(result) {
        const hasFailures = (result.failed || 0) > 0;
        const parts = [];

        parts.push(M.util.get_string('questionsgraded', 'quiz_autograde', result.graded || 0));

        if (result.questions) {
            parts.push(`Processed across ${result.questions} question(s)`);
        }

        if (hasFailures) {
            parts.push(M.util.get_string('questionsfailed', 'quiz_autograde', result.failed));

            if (result.failures && result.failures.length > 0) {
                let failureHtml = '<ul class="mt-2 mb-0" style="font-size: 0.9em;">';
                for (const fail of result.failures) {
                    failureHtml += `<li><strong>${this.escapeHtml(fail.student)}</strong> — ` +
                        `${this.escapeHtml(fail.question)}: <em>${this.escapeHtml(fail.error)}</em></li>`;
                }
                failureHtml += '</ul>';
                parts.push(failureHtml);
            }
        }

        const message = parts.join('<br>');
        this.showMessage(message, hasFailures);
    }

    showStatus(text) {
        const resultField = document.querySelector(Selectors.ELEMENTS.AUTOGRADERESULT);
        if (resultField) {
            resultField.innerHTML = `<i class="fa fa-spinner fa-spin"></i> ${this.escapeHtml(text)}`;
            resultField.style.display = 'block';
            resultField.classList.remove('alert-success', 'alert-danger');
            resultField.classList.add('alert-info');
        }
    }

    escapeHtml(text) {
        if (!text) {
            return '';
        }
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    showMessage(message, error) {
        const resultField = document.querySelector(Selectors.ELEMENTS.AUTOGRADERESULT);
        if (resultField) {
            resultField.innerHTML = message;
            resultField.style.display = 'block';
            resultField.classList.remove('alert-info');
            resultField.classList.add(error ? 'alert-danger' : 'alert-success');
        }
    }

    hideMessage() {
        const resultField = document.querySelector(Selectors.ELEMENTS.AUTOGRADERESULT);
        if (resultField) {
            resultField.innerHTML = '';
            resultField.style.display = 'none';
            resultField.classList.remove('alert-info', 'alert-success', 'alert-danger');
        }
    }
}
