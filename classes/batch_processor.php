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

namespace quiz_autograde;

use core\http_client;
use GuzzleHttp\RequestOptions;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles Gemini Batch API communication for bulk essay grading.
 *
 * Uses the aiprovider_gemini plugin's API keys and model configuration.
 * Flow: build inline requests → create batch job → poll status → retrieve results.
 *
 * @package    quiz_autograde
 * @copyright  2026 Kristen Goliath
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class batch_processor {

    /** @var string Primary Gemini API key from aiprovider_gemini */
    private string $apikey;

    /** @var string Fallback API key (used on auth errors only) */
    private string $apikeyfallback;

    /** @var string Gemini model to use */
    private string $model;

    /** @var string Base URL for Gemini API */
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta';

    /**
     * Constructor. Reads config from aiprovider_gemini.
     */
    public function __construct() {
        $this->apikey = get_config('aiprovider_gemini', 'apikey') ?? '';
        $this->apikeyfallback = get_config('aiprovider_gemini', 'apikey_fallback') ?? '';

        // Read model from aiprovider_gemini (default: gemini-3.1-flash-lite).
        $this->model = get_config('aiprovider_gemini', 'action_generate_text_model');
        if (empty($this->model)) {
            $this->model = 'gemini-3.1-flash-lite';
        }
    }

    /**
     * Check if batch mode is available (has API keys configured).
     *
     * @return bool
     */
    public function is_available(): bool {
        return !empty($this->apikey);
    }

    /**
     * Build the grading prompt for a single essay attempt.
     *
     * @param object $attempt The essay attempt data.
     * @return string The prompt text.
     */
    public static function build_grading_prompt(object $attempt): string {
        return <<<EOD
You are a helpful and precise assistant for grading student answers to essay questions.

This is the question:
---------------------
{$attempt->questiontext}
---------------------
This is the model answer and grading information for the question, provided by the teacher:
---------------------
{$attempt->graderinfo}
---------------------
This is the student's answer that you need to grade:
---------------------
{$attempt->answer}
---------------------
Suggest an accurate grade for the student's answer to the given question considering the grading information,
and provide a concise explanation for the grade. The grade should be a number between 0 and {$attempt->maxmark}.

Give your answer in the following JSON format:

---------------------
{
    "grade": (the grade as a number),
    "comment": (the explanation for the grade)
}
---------------------

Only return valid JSON in the specified format, without any additional text. Make sure the JSON is properly formatted.
EOD;
    }

    /**
     * Create a batch grading job using Gemini's inline Batch API.
     *
     * @param array $essayattempts Array of essay attempt objects to grade.
     * @param int $quizid The quiz ID.
     * @param int $courseid The course ID.
     * @return object Result with 'success', 'job_name', 'request_map', 'error'.
     */
    public function create_batch_job(array $essayattempts, int $quizid, int $courseid): object {
        global $DB, $USER;

        if (empty($this->apikey)) {
            return (object) [
                'success' => false,
                'error' => 'No Gemini API key configured. Configure keys in aiprovider_gemini settings.',
            ];
        }

        // Build inline requests and mapping.
        $inlinedrequests = [];
        $requestmap = []; // Maps index → attempt info for DB storage.
        $index = 0;

        foreach ($essayattempts as $attemptdata) {
            $attempt = (object) $attemptdata;

            // Skip already graded or no grader info.
            if ($attempt->graded || strlen($attempt->graderinfo) == 0) {
                continue;
            }

            // Skip empty answers (will be handled separately with grade 0).
            if (strlen($attempt->answer) == 0) {
                // Record as auto-graded 0.
                $this->record_batch_item($quizid, $courseid, 'pending', $attempt, null, 0, get_string('noanswer', 'quiz_autograde'));
                continue;
            }

            $prompt = self::build_grading_prompt($attempt);

            $inlinedrequests[] = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [['text' => $prompt]],
                    ],
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                ],
            ];

            // Store mapping for this index.
            $requestmap[$index] = [
                'attemptid' => $attempt->attemptid,
                'slot' => $attempt->slot,
                'maxmark' => $attempt->maxmark,
                'student' => $attempt->student,
                'questionname' => $attempt->questionname,
            ];

            $index++;
        }

        if (empty($inlinedrequests)) {
            return (object) [
                'success' => false,
                'error' => 'No essays to grade (all already graded or no grading info).',
            ];
        }

        // Create the batch job via REST API.
        // Endpoint: POST /v1beta/models/{model}:batchGenerateContent
        $client = \core\di::get(http_client::class);

        // Wrap each request in the InlinedRequest envelope.
        $wrappedrequests = [];
        foreach ($inlinedrequests as $req) {
            $wrappedrequests[] = ['request' => $req];
        }

        $requestbody = [
            'model' => 'models/' . $this->model,
            'displayName' => "quiz_autograde_quiz{$quizid}_" . date('Ymd_His'),
            'inputConfig' => [
                'requests' => [
                    'requests' => $wrappedrequests,
                ],
            ],
        ];

        $lasterror = '';

        // Try primary key, then fallback on auth errors only.
        $keystotry = [];
        if (!empty($this->apikey)) {
            $keystotry[] = ['key' => $this->apikey, 'label' => 'primary'];
        }
        if (!empty($this->apikeyfallback)) {
            $keystotry[] = ['key' => $this->apikeyfallback, 'label' => 'fallback'];
        }

        foreach ($keystotry as $keyinfo) {
            try {
                $response = $client->post(self::API_BASE . '/models/' . $this->model . ':batchGenerateContent', [
                    'headers' => [
                        'x-goog-api-key' => $keyinfo['key'],
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $requestbody,
                    RequestOptions::HTTP_ERRORS => false,
                ]);

                $status = $response->getStatusCode();
                $body = json_decode($response->getBody()->getContents());

                if ($status === 200 || $status === 201) {
                    $jobname = $body->name;

                    // Store all batch items in DB for later retrieval.
                    foreach ($requestmap as $idx => $map) {
                        $this->record_batch_item(
                            $quizid, $courseid, $jobname,
                            (object) $map, $idx
                        );
                    }

                    // Also grade any empty answers immediately.
                    $this->process_empty_answers($quizid, $courseid);

                    return (object) [
                        'success' => true,
                        'job_name' => $jobname,
                        'total_requests' => count($inlinedrequests),
                        'key_used' => $keyinfo['label'],
                    ];
                }

                // Auth error — try fallback key if available.
                if (in_array($status, [401, 403]) && count($keystotry) > 1) {
                    $lasterror = "{$keyinfo['label']}: HTTP {$status} - " . ($body->error->message ?? 'Auth error');
                    continue;
                }

                // Rate limit or other error — don't try another key.
                return (object) [
                    'success' => false,
                    'error' => "Gemini API error {$status}: " . ($body->error->message ?? $response->getReasonPhrase()),
                ];

            } catch (\Exception $e) {
                $lasterror = "{$keyinfo['label']}: " . $e->getMessage();
                continue;
            }
        }

        return (object) [
            'success' => false,
            'error' => 'Both API keys failed. Last error: ' . $lasterror,
        ];
    }

    /**
     * Poll a batch job's status.
     *
     * @param string $jobname The batch job name (e.g., "batches/123456").
     * @return object Result with 'status', 'done', and optionally results or error.
     */
    public function poll_batch_status(string $jobname): object {
        $client = \core\di::get(http_client::class);

        // Try primary key, then fallback on auth errors.
        $keystotry = [];
        if (!empty($this->apikey)) {
            $keystotry[] = $this->apikey;
        }
        if (!empty($this->apikeyfallback)) {
            $keystotry[] = $this->apikeyfallback;
        }

        foreach ($keystotry as $key) {
            try {
                $response = $client->get(self::API_BASE . '/' . $jobname, [
                    'headers' => [
                        'x-goog-api-key' => $key,
                    ],
                    RequestOptions::HTTP_ERRORS => false,
                ]);

                $status = $response->getStatusCode();
                if ($status === 200) {
                    $body = json_decode($response->getBody()->getContents());

                    $jobstate = $body->state ?? ($body->response->state ?? 'UNKNOWN');
                    $done = $body->done ?? false;

                    // Normalise state (API may return BATCH_STATE_* or JOB_STATE_*).
                    $statelabel = $jobstate;
                    $statelabel = str_replace(['BATCH_STATE_', 'JOB_STATE_'], '', $statelabel);
                    $statelabel = strtolower($statelabel);

                    // If not done yet, report current state.
                    if (!$done) {
                        return (object) [
                            'success' => true,
                            'status' => $statelabel,
                            'done' => false,
                            'job_name' => $jobname,
                        ];
                    }

                    $result = (object) [
                        'success' => true,
                        'status' => $statelabel,
                        'done' => true,
                        'job_name' => $jobname,
                    ];

                    // Job completed — check state.
                    $issucceeded = in_array($jobstate, ['BATCH_STATE_SUCCEEDED', 'JOB_STATE_SUCCEEDED']);
                    $isfailed = in_array($jobstate, ['BATCH_STATE_FAILED', 'JOB_STATE_FAILED']);

                    if ($issucceeded) {
                        // Try multiple response paths for inline results.
                        $responses = null;
                        // Path 1: Operation wrapper → response.output.inlinedResponses.inlinedResponses[].
                        if (isset($body->response->output->inlinedResponses->inlinedResponses)) {
                            $responses = $body->response->output->inlinedResponses->inlinedResponses;
                        }
                        // Path 2: response.inlinedResponses (flat).
                        if (!$responses && isset($body->response->inlinedResponses)) {
                            $responses = $body->response->inlinedResponses;
                        }
                        // Path 3: top-level output.inlinedResponses.inlinedResponses[].
                        if (!$responses && isset($body->output->inlinedResponses->inlinedResponses)) {
                            $responses = $body->output->inlinedResponses->inlinedResponses;
                        }
                        // Path 4: top-level dest.inlinedResponses (Python SDK naming).
                        if (!$responses && isset($body->dest->inlinedResponses)) {
                            $responses = $body->dest->inlinedResponses;
                        }
                        if ($responses) {
                            $result->responses = $responses;
                        }
                        // File-based output.
                        if (isset($body->response->output->responsesFile)) {
                            $result->result_file = $body->response->output->responsesFile;
                        }
                        if (isset($body->dest->file_name)) {
                            $result->result_file = $body->dest->file_name;
                        }
                    }

                    if ($isfailed) {
                        $result->error = $body->error->message ?? 'Batch job failed with no error details.';
                    }

                    return $result;
                }

                // Auth error — try fallback key.
                if (in_array($status, [401, 403]) && count($keystotry) > 1) {
                    continue;
                }

                return (object) [
                    'success' => false,
                    'error' => "HTTP {$status} polling job status.",
                ];

            } catch (\Exception $e) {
                continue;
            }
        }

        return (object) [
            'success' => false,
            'error' => 'Could not poll batch job status with any API key.',
        ];
    }

    /**
     * Process completed batch results and apply grades.
     *
     * @param string $jobname The batch job name.
     * @param array $responses Array of inline response objects from Gemini.
     * @return object Summary with 'graded', 'failed', 'details'.
     */
    public function process_batch_results(string $jobname, array $responses): object {
        global $DB;

        // Load all batch items for this job.
        $items = $DB->get_records('quiz_autograde_batch', ['batch_job' => $jobname, 'status' => 'pending']);

        if (empty($items)) {
            return (object) [
                'graded' => 0,
                'failed' => 0,
                'error' => 'No pending items found for this batch job.',
            ];
        }

        $graded = 0;
        $failed = 0;
        $failuredetails = [];

        // Map items by request_index for O(1) lookup.
        $itemsbyindex = [];
        foreach ($items as $item) {
            $itemsbyindex[$item->request_index] = $item;
        }

        foreach ($responses as $idx => $responseobj) {
            if (!isset($itemsbyindex[$idx])) {
                continue;
            }

            $item = $itemsbyindex[$idx];

            // InlinedResponse has: response (GenerateContentResponse) or error (Status).
            if (isset($responseobj->error)) {
                $failed++;
                $errormsg = is_object($responseobj->error) ? ($responseobj->error->message ?? json_encode($responseobj->error)) : strval($responseobj->error);
                $failuredetails[] = [
                    'student' => $item->student_name,
                    'question' => $item->question_name,
                    'error' => $errormsg,
                ];
                $this->update_batch_item($item->id, 'failed', null, null, $errormsg);
                continue;
            }

            // Extract generated text from the GenerateContentResponse.
            $generatedtext = '';
            if (isset($responseobj->response->candidates[0]->content->parts[0]->text)) {
                $generatedtext = $responseobj->response->candidates[0]->content->parts[0]->text;
            }

            if (empty($generatedtext)) {
                $failed++;
                $failuredetails[] = [
                    'student' => $item->student_name,
                    'question' => $item->question_name,
                    'error' => get_string('emptyresponse', 'quiz_autograde'),
                ];
                $this->update_batch_item($item->id, 'failed', null, null, 'Empty response from API');
                continue;
            }

            // Parse the JSON response.
            $data = json_decode($generatedtext);

            if ($data === null || !isset($data->grade) || !is_numeric($data->grade)) {
                $failed++;
                $failuredetails[] = [
                    'student' => $item->student_name,
                    'question' => $item->question_name,
                    'error' => get_string('invalidresponse', 'quiz_autograde', substr($generatedtext, 0, 200)),
                ];
                $this->update_batch_item($item->id, 'failed', null, null, 'Invalid JSON: ' . substr($generatedtext, 0, 200));
                continue;
            }

            // Apply the grade.
            $grade = max(0, min((float) $item->maxmark, (float) $data->grade));
            $comment = $data->comment ?? get_string('noexplanation', 'quiz_autograde');

            $attempt = (object) [
                'attemptid' => $item->attemptid,
                'slot' => $item->slot,
                'maxmark' => $item->maxmark,
            ];

            try {
                quiz_autograde_set_grade($attempt, $grade, $comment);
                $graded++;
                $this->update_batch_item($item->id, 'graded', $grade, $comment);
            } catch (\Exception $e) {
                $failed++;
                $failuredetails[] = [
                    'student' => $item->student_name,
                    'question' => $item->question_name,
                    'error' => $e->getMessage(),
                ];
                $this->update_batch_item($item->id, 'failed', null, null, $e->getMessage());
            }
        }

        return (object) [
            'graded' => $graded,
            'failed' => $failed,
            'failures' => $failuredetails,
        ];
    }

    /**
     * Process empty answers (auto-grade as 0) for a quiz.
     *
     * @param int $quizid The quiz ID.
     * @param int $courseid The course ID.
     */
    private function process_empty_answers(int $quizid, int $courseid): void {
        global $DB;

        $emptyitems = $DB->get_records('quiz_autograde_batch', [
            'quizid' => $quizid,
            'status' => 'pending',
            'grade' => null,
        ]);

        foreach ($emptyitems as $item) {
            if (empty($item->attemptid)) {
                continue;
            }

            $attempt = (object) [
                'attemptid' => $item->attemptid,
                'slot' => $item->slot,
                'maxmark' => $item->maxmark,
            ];

            try {
                quiz_autograde_set_grade($attempt, 0, get_string('noanswer', 'quiz_autograde'));
                $this->update_batch_item($item->id, 'graded', 0, get_string('noanswer', 'quiz_autograde'));
            } catch (\Exception $e) {
                $this->update_batch_item($item->id, 'failed', null, null, $e->getMessage());
            }
        }
    }

    /**
     * Record a batch item for later result mapping.
     *
     * @param int $quizid
     * @param int $courseid
     * @param string $jobname
     * @param object $map Mapping object with attemptid, slot, maxmark, student, questionname.
     * @param int $requestindex The index of this request in the batch.
     */
    private function record_batch_item(int $quizid, int $courseid, string $jobname, object $map, int $requestindex): void {
        global $DB, $USER;

        $record = (object) [
            'batch_job' => $jobname,
            'quizid' => $quizid,
            'courseid' => $courseid,
            'userid' => $USER->id,
            'request_index' => $requestindex,
            'attemptid' => $map->attemptid ?? null,
            'slot' => $map->slot ?? null,
            'maxmark' => $map->maxmark ?? 0,
            'student_name' => $map->student ?? '',
            'question_name' => $map->questionname ?? '',
            'status' => 'pending',
            'timecreated' => time(),
        ];

        $DB->insert_record('quiz_autograde_batch', $record);
    }

    /**
     * Update a batch item's status and grade.
     *
     * @param int $id The record ID.
     * @param string $status New status.
     * @param float|null $grade The grade (if graded).
     * @param string|null $comment The comment (if graded).
     * @param string|null $error Error message (if failed).
     */
    private function update_batch_item(int $id, string $status, ?float $grade = null, ?string $comment = null, ?string $error = null): void {
        global $DB;

        $update = (object) [
            'id' => $id,
            'status' => $status,
            'timecompleted' => time(),
        ];

        if ($grade !== null) {
            $update->grade = $grade;
        }
        if ($comment !== null) {
            $update->comment = $comment;
        }
        if ($error !== null) {
            $update->error = $error;
        }

        $DB->update_record('quiz_autograde_batch', $update);
    }
}
