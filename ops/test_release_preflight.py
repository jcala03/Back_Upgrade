#!/usr/bin/env python3
"""Real preflight CLI against isolated Laravel/PDO doubles: no MySQL, SSH or HTTP."""
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

BACKEND = Path(__file__).resolve().parents[1]
MIGRATION = '2026_10_28_000001_add_configuration_context_to_order_items'
SENTINEL = 'SYNTHETIC_PRIVATE_VALUE_MUST_NOT_BE_PRINTED'
AUTOLOAD = r'''<?php
namespace Illuminate\Database\Connectors {
    class ConnectionFactory {
        public function __construct($app) {}
        public function make($db, $name) { return new \FixtureConnection; }
    }
}
namespace {
    function config($key, $default = null) {
        $value = $GLOBALS['fixture']['config'];
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) return $default;
            $value = $value[$part];
        }
        return $value;
    }
    function blank($value) { return $value === null || $value === '' || $value === []; }
    function filled($value) { return !blank($value); }
    class FixtureApp {
        public function bootstrapWith($classes) {}
        public function environment($name) { return config('app.env') === $name; }
    }
    class FixtureConnection { public function getPdo() { return new FixturePdo; } }
    class FixturePdo {
        public function query($sql) {
            if ($sql === 'SELECT DATABASE()') return new FixtureStatement($GLOBALS['fixture']['effective_database']);
            if ($sql === 'SELECT migration FROM migrations ORDER BY migration') return new FixtureStatement($GLOBALS['fixture']['applied']);
            if (str_starts_with($sql, 'SELECT IS_NULLABLE, DATA_TYPE FROM information_schema.COLUMNS')) return new FixtureStatement($GLOBALS['fixture']['columns']);
            throw new \RuntimeException('FORBIDDEN_QUERY');
        }
    }
    class FixtureStatement {
        public function __construct(private $value) {}
        public function fetchColumn() { return $this->value; }
        public function fetchAll($mode) { return $this->value; }
    }
    $GLOBALS['fixture'] = json_decode(file_get_contents(__DIR__.'/../fixture.json'), true);
}
'''


class ReleasePreflightCLI(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='upgrade79-preflight-test-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / 'installed'
        self.candidate = Path(self.temp.name) / 'candidate'
        for directory in ['ops', 'vendor', 'bootstrap', 'database/migrations']:
            (self.root / directory).mkdir(parents=True)
        for directory in ['ops', 'database/migrations']:
            (self.candidate / directory).mkdir(parents=True)
        for file in ['release-preflight.php', 'ReleaseMigrationPlan.php', 'ReleaseWompiPolicy.php', 'ReleaseConfiguratorPolicy.php']:
            shutil.copyfile(BACKEND / 'ops' / file, self.candidate / 'ops' / file)
        # A deliberately blocked installed validator must NOT prevent publishing
        # the corrected candidate. The backend workflow invokes the candidate.
        (self.root / 'ops/release-preflight.php').write_text('<?php exit(91);')
        (self.root / 'vendor/autoload.php').write_text(AUTOLOAD)
        (self.root / 'bootstrap/app.php').write_text('<?php return new FixtureApp;')
        (self.candidate / 'database/migrations' / (MIGRATION + '.php')).write_text('<?php // must never execute')
        self.fixture = {
            'config': {
                'app': {'env': 'production', 'debug': False}, 'mail': {'default': 'array'},
                'commercial_documents': {'real_mail_enabled': False},
                'product_configurator': {'purchase_enabled': False},
                'services': {'wompi': {
                    'public_key': 'pub_prod_' + SENTINEL, 'private_key': 'prv_prod_' + SENTINEL,
                    'integrity_secret': 'prod_integrity_' + SENTINEL, 'events_secret': 'prod_events_' + SENTINEL,
                    'base_url': 'https://production.wompi.co/v1', 'checkout_url': 'https://checkout.wompi.co/p/',
                    'redirect_url': 'https://upgradecolombia.com/checkout/payment/return', 'reservation_minutes': 30,
                }},
                'cors': {'allowed_origins': ['https://upgradecolombia.com']},
                'database': {'default': 'mysql', 'connections': {'mysql': {'database': 'fixture_prod', 'password': SENTINEL}}},
            },
            'effective_database': 'fixture_prod', 'applied': [MIGRATION],
            'columns': [{'IS_NULLABLE': 'YES', 'DATA_TYPE': 'json'}],
        }

    def run_preflight(self, installed=False):
        (self.root / 'fixture.json').write_text(json.dumps(self.fixture))
        before = {str(p): p.read_bytes() for p in Path(self.temp.name).rglob('*') if p.is_file()}
        command = ['php', str((self.root if installed else self.candidate) / 'ops/release-preflight.php')]
        if not installed:
            command += ['--project-root=' + str(self.root), '--migration-root=' + str(self.candidate / 'database/migrations')]
        result = subprocess.run(command + ['--require-current'], cwd=self.root, text=True, capture_output=True, timeout=10)
        self.assertNotIn(SENTINEL, result.stdout + result.stderr)
        after = {str(p): p.read_bytes() for p in Path(self.temp.name).rglob('*') if p.is_file()}
        self.assertEqual(before, after, 'Preflight may not modify installed or candidate files')
        return result

    def test_candidate_policy_can_publish_over_blocked_installed_validator(self):
        self.assertEqual(self.run_preflight(installed=True).returncode, 91)
        result = self.run_preflight()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout)['status'], 'PASS')

    def test_frontend_can_use_corrected_installed_policy_after_backend_deploy(self):
        for file in ['release-preflight.php', 'ReleaseMigrationPlan.php', 'ReleaseWompiPolicy.php', 'ReleaseConfiguratorPolicy.php']:
            shutil.copyfile(self.candidate / 'ops' / file, self.root / 'ops' / file)
        shutil.copytree(self.candidate / 'database/migrations', self.root / 'database/migrations', dirs_exist_ok=True)
        self.assertEqual(self.run_preflight(installed=True).returncode, 0)

    def test_configurator_only_accepts_explicit_reviewed_catalog_and_profiles(self):
        db = self.fixture['config']['database']['connections']['mysql']
        db.update({'host': 'fixture.local', 'port': '3306'})
        config = {
            'preview_enabled': True, 'purchase_enabled': True, 'production_approved': True,
            'catalog': {'environment': 'production', 'driver': 'mysql', 'host': 'fixture.local',
                        'port': '3306', 'database': 'fixture_prod'},
            'profiles': {'vehicle-oem-reference-v1': {'version': 1, 'enabled': True}},
            'products': {'123': {'profile_id': 'vehicle-oem-reference-v1', 'profile_version': 1, 'reviewed': True, 'approved_variant_ids': [456]}},
        }
        self.fixture['config']['product_configurator'] = config
        self.assertEqual(self.run_preflight().returncode, 0)
        for key, value in [('production_approved', False), ('products', {}), ('catalog', None)]:
            old = config[key]
            config[key] = value
            self.assertEqual(self.run_preflight().returncode, 1)
            config[key] = old
        config['products']['123']['reviewed'] = False
        self.assertEqual(self.run_preflight().returncode, 1)

    def test_sandbox_mixed_missing_or_unsafe_credentials_fail_with_safe_identifiers(self):
        wompi = self.fixture['config']['services']['wompi']
        variables = {'public_key': 'WOMPI_PUBLIC_KEY', 'private_key': 'WOMPI_PRIVATE_KEY',
                     'integrity_secret': 'WOMPI_INTEGRITY_SECRET', 'events_secret': 'WOMPI_EVENTS_SECRET'}
        for key, variable in variables.items():
            old = wompi[key]
            for value in ['', None, old.replace('prod_', 'test_'), old + '\n', old + ' invalid']:
                with self.subTest(key=key, value_type=type(value).__name__):
                    wompi[key] = value
                    result = self.run_preflight()
                    self.assertEqual(result.returncode, 1)
                    self.assertIn(variable + '_production_format', result.stderr)
            wompi[key] = old

    def test_wrong_urls_are_still_rejected(self):
        wompi = self.fixture['config']['services']['wompi']
        for key, wrong in [('base_url', 'https://sandbox.wompi.co/v1'),
                           ('base_url', 'https://untrusted.example/v1'),
                           ('checkout_url', 'https://untrusted.example/p'),
                           ('redirect_url', 'http://upgradecolombia.com/checkout/payment/return'),
                           ('redirect_url', 'https://upgradecolombia.com/checkout/payment/return?unexpected=1')]:
            with self.subTest(key=key):
                old = wompi[key]
                wompi[key] = wrong
                self.assertEqual(self.run_preflight().returncode, 1)
                wompi[key] = old

    def test_entire_sandbox_set_is_rejected_on_production(self):
        wompi = self.fixture['config']['services']['wompi']
        for key in ['public_key', 'private_key', 'integrity_secret', 'events_secret']:
            wompi[key] = wompi[key].replace('prod_', 'test_')
        result = self.run_preflight()
        self.assertEqual(result.returncode, 1)
        for variable in ['WOMPI_PUBLIC_KEY', 'WOMPI_PRIVATE_KEY', 'WOMPI_INTEGRITY_SECRET', 'WOMPI_EVENTS_SECRET']:
            self.assertIn(variable + '_production_format', result.stderr)

    def test_environment_cors_smtp_and_configurator_restrictions_remain(self):
        for group, key, wrong in [('app', 'env', 'local'), ('app', 'debug', True),
                                  ('mail', 'default', 'smtp'), ('commercial_documents', 'real_mail_enabled', True),
                                  ('product_configurator', 'purchase_enabled', True),
                                  ('cors', 'allowed_origins', ['http://localhost:5173', 'https://upgradecolombia.com'])]:
            with self.subTest(group=group, key=key):
                old = self.fixture['config'][group][key]
                self.fixture['config'][group][key] = wrong
                self.assertEqual(self.run_preflight().returncode, 1)
                self.fixture['config'][group][key] = old

    def test_database_mismatch_pending_history_and_invalid_column_still_block(self):
        self.fixture['effective_database'] = 'other_database'
        self.assertEqual(self.run_preflight().returncode, 1)
        self.fixture['effective_database'] = 'fixture_prod'
        self.fixture['applied'] = []
        self.fixture['columns'] = []
        self.assertEqual(self.run_preflight().returncode, 1)
        self.fixture['applied'] = [MIGRATION]
        self.fixture['columns'] = [{'IS_NULLABLE': 'NO', 'DATA_TYPE': 'json'}]
        self.assertEqual(self.run_preflight().returncode, 1)

    def test_database_configuration_constraints_remain(self):
        db = self.fixture['config']['database']['connections']['mysql']
        for key, wrong in [('database', 'upgrade'), ('database', 'upgrade_test'), ('database', ''),
                           ('url', 'mysql://'+SENTINEL), ('prefix', 'prefix_'), ('read', {'host': SENTINEL}), ('write', {'host': SENTINEL})]:
            with self.subTest(key=key):
                old = dict(db)
                db[key] = wrong
                self.assertEqual(self.run_preflight().returncode, 1)
                db.clear()
                db.update(old)


if __name__ == '__main__':
    unittest.main(verbosity=2)
