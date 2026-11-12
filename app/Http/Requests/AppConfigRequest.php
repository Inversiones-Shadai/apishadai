<?php

namespace App\Http\Requests;

class AppConfigRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'appv' => ['nullable', 'string', 'max:65535'],
            'mail_alert' => ['required', 'email:rfc', 'max:255'],
            'mail_cc1' => ['required', 'email:rfc', 'max:255'],
            'mail_cc2' => ['nullable', 'email:rfc', 'max:255'],
            'mail_cc3' => ['nullable', 'email:rfc', 'max:255'],
        ];
    }

    protected function customAttributes(): array
    {
        return [
            'appv' => 'identificador de versión',
            'mail_alert' => 'remitente de alertas',
            'mail_cc1' => 'correo administrador',
            'mail_cc2' => 'correo de copia 2',
            'mail_cc3' => 'correo de copia 3',
        ];
    }
}
