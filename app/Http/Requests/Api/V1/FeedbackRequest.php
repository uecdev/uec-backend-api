<?php

namespace App\Http\Requests\Api\V1;

class FeedbackRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        // Multipart booleans arrive as strings.
        if ($this->has('contact_consent')) {
            $this->merge(['contact_consent' => filter_var($this->input('contact_consent'), FILTER_VALIDATE_BOOLEAN)]);
        }
        if (is_string($this->input('type'))) {
            $this->merge(['type' => strtolower(trim($this->input('type')))]);
        }
    }

    public function rules(): array
    {
        $maxKb = (int) config('uec.feedback.max_file_kb');
        $mimes = implode(',', config('uec.feedback.mimes'));

        return [
            // The design does not enumerate feedback types; accept any short slug the app sends.
            'type' => ['required', 'string', 'max:40', 'alpha_dash'],
            'message' => ['required', 'string', 'min:3', 'max:5000'],
            'contact_consent' => ['sometimes', 'boolean'],
            'attachments' => ['sometimes', 'array', 'max:'.config('uec.feedback.max_files')],
            // mimetypes inspects file content (finfo), not the extension (I.3).
            'attachments.*' => ['file', "max:{$maxKb}", "mimetypes:{$mimes}"],
        ];
    }
}
