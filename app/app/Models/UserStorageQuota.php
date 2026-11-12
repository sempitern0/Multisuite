<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserStorageQuota extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;
}
