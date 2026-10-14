<?php
$base = dirname(__DIR__, 3);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/apply.php';
