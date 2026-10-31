<?php

// Read-only production gate. Never run migrations, provider requests or business writes here.
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

try {
    require __DIR__.'/../vendor/autoload.php';
    require __DIR__.'/ReleaseMigrationPlan.php';
    $app = require __DIR__.'/../bootstrap/app.php';
    $app->bootstrapWith([LoadEnvironmentVariables::class, LoadConfiguration::class]);
    if (! $app->environment('production') || config('app.debug')
        || ! in_array(config('mail.default'), ['log', 'array'], true)
        || config('commercial_documents.real_mail_enabled', false)
        || config('product_configurator.purchase_enabled', false)
        || filled(config('services.wompi.public_key'))
        || filled(config('services.wompi.integrity_secret'))
        || rtrim((string) config('services.wompi.base_url'), '/') !== 'https://production.wompi.co/v1'
        || rtrim((string) config('services.wompi.checkout_url'), '/') !== 'https://checkout.wompi.co/p'
        || config('services.wompi.redirect_url') !== 'https://upgradecolombia.com/checkout/payment/return'
        || config('cors.allowed_origins') !== ['https://upgradecolombia.com']) {
        throw new RuntimeException('Release requires production/debug-off, SMTP documents off, configurator off and live Wompi checkout disabled.');
    }
    $name = config('database.default');
    $db = config('database.connections.'.$name, []);
    if ($name !== 'mysql' || filled($db['url'] ?? null)
        || blank($db['database'] ?? null)
        || in_array($db['database'], ['upgrade', 'upgrade_test'], true)
        || filled($db['read'] ?? null) || filled($db['write'] ?? null)
        || filled($db['prefix'] ?? null)) {
        throw new RuntimeException('Unexpected production database configuration; review privately before release.');
    }
    $connection = (new ConnectionFactory($app))->make($db, $name);
    $pdo = $connection->getPdo();
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $db['database']) {
        throw new RuntimeException('Effective database mismatch.');
    }
    $applied = $pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
    $files = array_map(fn ($file) => basename($file, '.php'), glob(__DIR__.'/../database/migrations/*.php'));
    sort($files);
    $columns = $pdo->query("SELECT IS_NULLABLE, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'configuration_context'")->fetchAll(PDO::FETCH_ASSOC);
    $report = ReleaseMigrationPlan::verify($files, $applied, $columns, in_array('--require-current', $argv, true));
    echo json_encode($report, JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $exception) {
    // Never echo PDO exceptions, credentials, SQL bindings or a stack trace.
    fwrite(STDERR, 'Release preflight refused: '.get_class($exception).'. Review environment/schema privately.'.PHP_EOL);
    exit(1);
}
