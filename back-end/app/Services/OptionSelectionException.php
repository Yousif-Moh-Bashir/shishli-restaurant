<?php

namespace App\Services;

use App\Enums\CartIssueCode;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OptionSelectionException extends ValidationException
{
    public function __construct(public CartIssueCode $issueCode, string $message)
    {
        $validator = Validator::make([], []);
        $validator->errors()->add('options', $message);
        parent::__construct($validator);
    }
}
