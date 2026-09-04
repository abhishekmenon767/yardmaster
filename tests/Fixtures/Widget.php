<?php

namespace Iocod\Yardmaster\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Widget extends Model
{
    protected $table = 'widgets';

    protected $guarded = [];

    public $timestamps = false;
}
