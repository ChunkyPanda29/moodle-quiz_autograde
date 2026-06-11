/**
 * AMD module for the AutoGrade feature with batch API and single-request fallback.
 *
 * @module quiz_autograde/autograde
 * @copyright 2026 Kristen Goliath
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Selectors from 'quiz_autograde/selectors';

const POLL_INTERVAL_MS = 15000;
const MAX_POLL_ATTEMPTS = 120;

export const init = (quizID, courseID) => {
    new AutoGrade(quizID, courseID);
};

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
            autogradeButton.addEventListener('click', async () => {
                this.hideMessage();
                autogradeButton.setAttribute('disabled', 'disabled');
                this.showStatus('Starting batch grading...');

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
     * Try batch mode first. If it fails (e.g. free tier doesn't support batch),
     * fall back to throttled single-request mode.
     */
    async runBatchGrading() {
        const autogradeButton = document.querySelector(Selectors.ELEMENTS.AUTOGRADEBUTTON);

        // Try batch API first.
        const createResult = await this.ajaxCall('quiz_autograde_create_batch', {
            quizID: this.quizID,
            courseID: this.courseID,
        });

        if (!createResult.success) {
            // Batch failed — fall back to single-request mode.
            const reason = createResult.error || '';
            if (reason.includes('Precondition') || reason.includes('400') ||
                reason.includes('batch') || reason.includes('billing')) {
                this.showStatus('Batch API unavailable. Falling back to single-request mode (this may take a few minutes)...');
            } else {
                this.showStatus('Batch failed. Trying single-request mode...');
            }
            await this.runSingleRequestGrading();
            return;
        }

        // Batch succeeded — poll until done.
        const jobName = createResult.job_name;
        const totalRequests = createResult.total_requests || 0;
        this.showStatus(`Batch job submitted (${totalRequests} essays). Waiting for Gemini to process...`);

        this.pollAttempts = 0;
        const pollResult = await this.pollUntilDone(jobName);

        if (!pollResult.success) {
            this.showMessage(pollResult.error || 'Batch job polling failed.', true);
            autogradeButton.removeAttribute('disabled');
            return;
        }

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
     * Single-request mode: calls the synchronous run_autograde endpoint.
     * Each essay is graded individually with server-side throttling.
     */
    async runSingleRequestGrading() {
        const autogradeButton = document.querySelector(Selectors.ELEMENTS.AUTOGRADEBUTTON);

        const result = await this.ajaxCall('quiz_autograde_run_autograde', {
            quizID: this.quizID,
            courseID: this.courseID,
        });

        autogradeButton.removeAttribute('disabled');

        if (result.graded !== undefined) {
            // Direct result object from run_autograde.
            this.handleGradingResult(result);
        } else if (result.success) {
            this.handleGradingResult(result);
        } else {
            this.showMessage(result.error || 'Grading failed.', true);
        }
    }

    async pollUntilDone(jobName) {
        return new Promise((resolve) => {
            const poll = async () => {
                this.pollAttempts++;

                if (this.pollAttempts > MAX_POLL_ATTEMPTS) {
                    resolve({success: false, error: 'Batch job timed out after 30 minutes.'});
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

                const elapsed = Math.round(this.pollAttempts * (POLL_INTERVAL_MS / 1000));
                const mins = Math.floor(elapsed / 60);
                const secs = elapsed % 60;
                this.showStatus(`Processing... (${mins}m ${secs}s elapsed). Status: ${result.status || 'running'}`);

                setTimeout(poll, POLL_INTERVAL_MS);
            };

            // First poll after 5 seconds.
            setTimeout(poll, 5000);
        });
    }

    async ajaxCall(method, args) {
        const request = {
            methodname: method,
            args: args,
        };

        try {
            const response = await Ajax.call([request])[0];

            // Check for Moodle exception.
            if (typeof response === 'object' && response.error) {
                return {
                    success: false,
                    error: response.error.exception ? response.error.exception.message : response.error,
                };
            }

            // Parse if string.
            const parsed = typeof response === 'string' ? JSON.parse(response) : response;
            return parsed;
        } catch (error) {
            return {success: false, error: error.message || 'AJAX call failed.'};
        }
    }

    handleGradingResult(result) {
        const hasFailures = (result.failed || 0) > 0;
        const parts = [];

        parts.push(M.util.get_string('questionsgraded', 'quiz_autograde', result.graded || 0));

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
