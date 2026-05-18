<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule; 
use Illuminate\Support\Facades\Log;


Schedule::command('breet:sync-assets')->daily();