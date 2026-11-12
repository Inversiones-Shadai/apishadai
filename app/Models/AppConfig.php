<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppConfig extends Model
{
    protected $table = 'appconfig';

    protected $fillable = [
        'appv',
        'mail_alert',
        'mail_cc1',
        'mail_cc2',
        'mail_cc3',
    ];
}
