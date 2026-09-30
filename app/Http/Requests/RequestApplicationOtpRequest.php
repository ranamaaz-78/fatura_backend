<?php

namespace App\Http\Requests;

use Illuminate\Support\Arr;

/** The application details, before the applicant has a code. */
class RequestApplicationOtpRequest extends StoreApplicationRequest
{
    public function rules(): array
    {
        return Arr::except(parent::rules(), 'otp');
    }
}
