<?php

namespace App\Http\Requests\UserManagement;

use Illuminate\Foundation\Http\FormRequest;

class ImportUsersRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // .txt is allowed because some browsers report CSVs as text/plain.
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose a CSV file to import.',
            'file.mimes' => 'The import must be a .csv file.',
            'file.max' => 'The file may not be larger than 2 MB.',
        ];
    }
}
