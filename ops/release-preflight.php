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
    $options = getopt('', ['project-root:', 'migration-root:', 'require-current']);
    $project = realpath($options['project-root'] ?? __DIR__.'/..');
    $migrationRoot = realpath($options['migration-root'] ?? __DIR__.'/../database/migrations');
    if ($project === false || $migrationRoot === false
        || ! is_file($project.'/vendor/autoload.php') || ! is_file($project.'/bootstrap/app.php')) {
        throw new RuntimeException('Invalid installed project or candidate migration directory.');
    }
    // Inspect candidate migrations with the INSTALLED application's private configuration.
    // This must run before checkout, Composer, maintenance or cache changes.
    require $project.'/vendor/autoload.php';
    require __DIR__.'/ReleaseMigrationPlan.php';
    require __DIR__.'/ReleaseWompiPolicy.php';
    require __DIR__.'/ReleaseConfiguratorPolicy.php';
    $app = require $project.'/bootstrap/app.php';
    $app->bootstrapWith([LoadEnvironmentVariables::class, LoadConfiguration::class]);
    if (! $app->environment('production') || config('app.debug')
        || ! in_array(config('mail.default'), ['log', 'array'], true)
        || config('commercial_documents.real_mail_enabled', false)
        || ! ReleaseConfiguratorPolicy::accepts(config('product_configurator', []),
            config('database.connections.'.config('database.default'), []))
        || rtrim((string) config('services.wompi.base_url'), '/') !== 'https://production.wompi.co/v1'
        || rtrim((string) config('services.wompi.checkout_url'), '/') !== 'https://checkout.wompi.co/p'
        || config('services.wompi.redirect_url') !== 'https://upgradecolombia.com/checkout/payment/return'
        || config('cors.allowed_origins') !== ['https://upgradecolombia.com']) {
        throw new RuntimeException('Release requires production/debug-off, SMTP documents off, reviewed configurator activation and the reviewed public URLs/CORS.');
    }
    // Production credentials were deliberately configured by the operator.
    // Deployment readiness requires the complete production set, not empty keys.
    // This read-only policy neither activates checkout nor certifies key validity.
    $wompiChecks = ReleaseWompiPolicy::checks(config('services.wompi', []));
    if (in_array(false, $wompiChecks, true)) {
        throw new RuntimeException('Incomplete or incompatible production Wompi configuration.');
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
    $files = array_map(fn ($file) => basename($file, '.php'), glob($migrationRoot.'/*.php'));
    sort($files);
    $columns = $pdo->query("SELECT IS_NULLABLE, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'configuration_context'")->fetchAll(PDO::FETCH_ASSOC);
    $report = ReleaseMigrationPlan::verify($files, $applied, $columns, in_array('--require-current', $argv, true));
    echo json_encode($report, JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $exception) {
    // Never echo PDO exceptions, credentials, SQL bindings or a stack trace.
    $failedWompiChecks = isset($wompiChecks) ? array_keys(array_filter($wompiChecks, static fn ($pass) => ! $pass)) : [];
    // Only policy-owned identifiers; never print exception messages or values.
    $hint = $failedWompiChecks !== [] ? ' Failed checks: '.implode(', ', $failedWompiChecks).'.' : '';
    fwrite(STDERR, 'Release preflight refused: '.get_class($exception).'.'.$hint.' Review environment/schema privately.'.PHP_EOL);
    exit(1);
}
