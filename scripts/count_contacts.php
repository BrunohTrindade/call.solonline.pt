<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Contact;

$count = Contact::count();
echo "contacts_count=" . $count . PHP_EOL;
$latest = Contact::orderByDesc('id')->limit(3)->get(['id','email','numero','created_at'])->toArray();
echo json_encode(['latest' => $latest], JSON_PRETTY_PRINT) . PHP_EOL;
