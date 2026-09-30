<?php

namespace App\Http\Requests\Api\V1;

class CartDeliveryQuoteRequest extends DeliveryQuoteRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['branch_uuid'], $rules['subtotal']);
        $rules['address_uuid'] = ['required_without:address', 'prohibits:address', 'uuid'];
        $rules['address'] = ['required_without:address_uuid', 'prohibits:address_uuid', 'array:city,district,latitude,longitude'];
        $rules['address.city'] = ['required_with:address', 'string', 'max:100'];
        $rules['address.district'] = ['required_with:address', 'string', 'max:100'];

        return $rules;
    }

    public function messages(): array
    {
        return parent::messages() + ['required_without' => 'يرجى إرسال عنوان محفوظ أو بيانات عنوان.',
            'prohibits' => 'أرسل عنوانًا محفوظًا أو بيانات عنوان، وليس كليهما.'];
    }
}
