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
 * AMD module for the AutoGrade feature with batch API support.
 *
 * @module quiz_autograde/autograde
 * @copyright 2026 Kristen Goliath
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Selectors from 'quiz_autograde/selectors';

const POLL_INTERVAL_MS = 15000; // Poll every 15 seconds.
const MAX_POLL_ATTEMPTS = 120;   // Max 30 minutes of polling (120 × 15s).

class AutoGrade {
    constructor(quizID, courseID) {
        this.quizID = quizID;
        this.courseID = courseID;
        this.pollAttempts = 0;
        this.registerEventListeners();
        this.hideMessage();
    }

    registerEventListeners() {
        const autogradeButton = document.querySelector(Selectors.ELEMENTS.AUTOGRADEBUTTON);
        if (autogradeButton) {
            autogradeButton.addEventListener('click', async() => {
                this.hideMessage();
                autogradeButton.setAttribute('disabled', 'disabled');
                this.showStatus('Submitting batch grading job...');

                try {
                    await this.runBatchGrading();
                } catch (error) {
                    this.showMessage(error.message || 'Unknown error occurred.', true);
                    autogradeButton.removeAttribute('disabled');
                }
            });
        }
    }

    /**
     * Main batch grading flow: create → poll → process.
     */
    async runBatchGrading() {
        const autogradeButton = document.querySelector(Selectors.ELEMENTS.AUTOGRADEBUTTON);

        // Step 1: Create the batch job.
        const createResult = await this.ajaxCall('quiz_autograde_create_batch', {
            quizID: this.quizID,
            courseID: this.courseID,
        });

        if (!createResult.success) {
            // Fall back to single-request mode if batch fails.
            this.showMessage(createResult.error || 'Failed to create batch job. Try single-request mode.', true);
            autogradeButton.removeAttribute('disabled');
            return;
        }

        const jobName = createResult.job_name;
        const totalRequests = createResult.total_requests || 0;

        this.showStatus(
            `Batch job submitted (${totalRequests} essays). Waiting for Gemini to process...`
        );

        // Step 2: Poll until complete.
        this.pollAttempts = 0;
        const pollResult = await this.pollUntilDone(jobName);

        if (!pollResult.success) {
            this.showMessage(pollResult.error || 'Batch job polling failed.', true);
            autogradeButton.removeAttribute('disabled');
            return;
        }

        // Step 3: Process the results.
        this.showStatus('Batch complete! Applying grades...');

        const processResult = await this.ajaxCall('quiz_autograde_process_batch', {
            jobName: jobName,
            courseID: this.courseID,
        });

        autogradeButton.removeAttribute('disabled');

        if (processResult.success) {
            this.handleGradingResult(processResult);
        } else {
            this.showMessage(processResult.error || 'Failed to process batch results.', true);
        }
    }

    /**
     * Poll the batch job status until it's done or we give up.
     *
     * @param {string} jobName The batch job name.
     * @return {Promise<object>} The final poll result.
     */
    async pollUntilDone(jobName) {
        return new Promise((resolve) => {
            const poll = async() => {
                this.pollAttempts++;

                if (this.pollAttempts > MAX_POLL_ATTEMPTS) {
                    resolve({
                        success: false,
                        error: 'Batch job timed out after 30 minutes. Check Site Administration for results.',
                    });
                    return;
                }

                const result = await this.ajaxCall('quiz_autograde_poll_batch', {
                    jobName: jobName,
                    courseID: this.courseID,
                });

                if (!result.success) {
                    resolve({success: false, error: result.error || 'Polling failed.'});
                    return;
                }

                if (result.done) {
                    resolve(result);
                    return;
                }

                // Update status with elapsed time.
                const elapsed = Math.round(this.pollAttempts * (POLL_INTERVAL_MS / 1000));
                const mins = Math.floor(elapsed / 60);
                const secs = elapsed % 60;
                this.showStatus(
                    `Processing... (${mins}m ${secs}s elapsed). Status: ${result.status || 'running'}`
                );

                // Schedule next poll.
                setTimeout(poll, POLL_INTERVAL_MS);
            };

            // Start polling after a short initial delay.
            setTimeout(poll, 5000);
        });
    }

    /**
     * Make an AJAX call to a Moodle external function.
     *
     * @param {string} method The external function name.
     * @param {object} args The arguments.
     * @return {Promise<object>} Parsed JSON result.
     */
    async ajaxCall(method, args) {
        const request = {
            methodname: method,
            args: args,
        };

        try {
            const response = await Ajax.call([request])[0];
            if (typeof response === 'object' && response.error) {
                return {success: false, error: response.error.exception?.message || response.error};
            }
            // Response is a JSON string from our external functions.
            const parsed = typeof response === 'string' ? JSON.parse(response) : response;
            return parsed;
        } catch (error) {
            return {success: false, error: error.message || 'AJAX call failed.'};
        }
    }

    /**
     * Handle the grading result, showing graded/failed/skipped breakdown.
     *
     * @param {object} result The grading result object.
     */
    handleGradingResult(result) {
        const hasFailures = (result.failed || 0) > 0;
        const parts = [];

        parts.push(M.util.get_string('questionsgraded', 'quiz_autograde', result.graded || 0));

        if (hasFailures) {
            parts.push(M.util.get_string('questionsfailed', 'quiz_autograde', result.failed));

            if (result.failures && result.failures.length > 0) {
                let failureHtml = '<ul class="mt-2 mb-0" style="font-size: 0.9em;">';
                for (const fail of result.failures) {
                    failureHtml += `<li><strong>${this.escapeHtml(fail.student)}</strong>
                        — ${this.escapeHtml(fail.question)}:
                        <em>${this.escapeHtml(fail.error)}</em></li>`;
                }
                failureHtml += '</ul>';
                parts.push(failureHtml);
            }
        }

        const message = parts.join('<br>');
        this.showMessage(message, hasFailures);
    }

    /**
     * Show a status message (not success or error, just info).
     *
     * @param {string} text The status text.
     */
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
        if (!text) return '';
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

export const init = (quizID, courseID) => {
    new AutoGrade(quizID, courseID);
};
