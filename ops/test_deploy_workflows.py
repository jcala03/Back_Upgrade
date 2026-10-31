#!/usr/bin/env python3
"""Local workflow regression: PyYAML + isolated fake commands, never SSH/SQL."""
import hashlib
import io
import os
from pathlib import Path
import re
import shlex
import subprocess
import tarfile
import tempfile
import unittest

import yaml

BACKEND = Path(__file__).resolve().parents[1]
FRONTEND = BACKEND.parent / "upgrade79-web"


def workflow(root):
    return yaml.safe_load((root / ".github/workflows/deploy.yml").read_text())


def triggers(document):
    return document.get("on", document.get(True))


def remote_script(job):
    return next(step["with"]["script"] for step in job["steps"]
                if "script" in step.get("with", {}))


def execute(script, env):
    return subprocess.run(["bash", "-c", script], env={**os.environ, **env},
                          text=True, capture_output=True, timeout=20)


def fixture_file(path, content):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content)


class AutomaticDeployPolicy(unittest.TestCase):
    def setUp(self):
        self.backend = workflow(BACKEND)
        self.frontend = workflow(FRONTEND)

    def test_push_and_dispatch_remain_and_normal_jobs_need_no_approval(self):
        for document in [self.backend, self.frontend]:
            self.assertEqual(triggers(document)["push"]["branches"], ["main"])
            self.assertIn("workflow_dispatch", triggers(document))
            self.assertFalse(document["concurrency"]["cancel-in-progress"])
            self.assertEqual(document["concurrency"]["queue"], "max")
            self.assertNotIn("environment", document["jobs"]["deploy"])
            for name, field in triggers(document)["workflow_dispatch"]["inputs"].items():
                if name != "run_configuration_migration":
                    self.assertFalse(field.get("required", False))

    def test_bash_syntax_of_every_runner_and_remote_step(self):
        for document in [self.backend, self.frontend]:
            for job in document["jobs"].values():
                for step in job["steps"]:
                    for script in [step.get("run"), step.get("with", {}).get("script")]:
                        if script:
                            result = subprocess.run(["bash", "-n"], input=script,
                                                    text=True, capture_output=True)
                            self.assertEqual(result.returncode, 0, result.stderr)
                            if script.startswith("bash -se <<'UPGRADE79_REMOTE'\n"):
                                inner = script.split("\n", 1)[1].rsplit("\nUPGRADE79_REMOTE", 1)[0]
                                result = subprocess.run(["bash", "-n"], input=inner,
                                                        text=True, capture_output=True)
                                self.assertEqual(result.returncode, 0, result.stderr)

    def test_ordinary_backend_push_needs_no_inputs_and_cannot_authorize_migration(self):
        script = self.backend["jobs"]["validate"]["steps"][0]["run"]
        base = dict(RELEASE_SHA="a"*40, REQUESTED_SHA="", BACKUP_ID="",
                    RUN_CONFIGURATION_MIGRATION="false", GITHUB_EVENT_NAME="push")
        self.assertEqual(execute(script, base).returncode, 0)
        self.assertNotEqual(execute(script, {**base, "RUN_CONFIGURATION_MIGRATION": "true"}).returncode, 0)
        manual = {**base, "GITHUB_EVENT_NAME": "workflow_dispatch", "RUN_CONFIGURATION_MIGRATION": "true"}
        self.assertNotEqual(execute(script, manual).returncode, 0)
        self.assertEqual(execute(script, {**manual, "BACKUP_ID": "release-20261030T000000Z-abc123"}).returncode, 0)
        for bad in [{"REQUESTED_SHA": "b"*40}, {"RELEASE_SHA": "invalid"},
                    {"BACKUP_ID": "../../public_html"}, {"RUN_CONFIGURATION_MIGRATION": "unexpected"}]:
            self.assertNotEqual(execute(script, {**base, **bad}).returncode, 0)

    def test_ordinary_frontend_push_needs_no_inputs(self):
        script = self.frontend["jobs"]["deploy"]["steps"][0]["run"]
        base = dict(RELEASE_SHA="a"*40, REQUESTED_SHA="", BACKEND_SHA="",
                    BACKUP_ID="", RELEASE_RUN_NUMBER="23")
        self.assertEqual(execute(script, base).returncode, 0)
        for bad in [{"REQUESTED_SHA": "b"*40}, {"BACKEND_SHA": "invalid"},
                    {"BACKUP_ID": "../other"}, {"RELEASE_RUN_NUMBER": "not-a-number"}]:
            self.assertNotEqual(execute(script, {**base, **bad}).returncode, 0)

    def test_no_migration_command_in_ordinary_deploy(self):
        normal = remote_script(self.backend["jobs"]["deploy"])
        self.assertNotRegex(normal, r"php\s+artisan\s+migrate\b")
        self.assertIn("--require-current", normal)
        self.assertLess(normal.index("--require-current"), normal.index("checkout --detach"))
        self.assertLess(normal.index("--require-current"), normal.index("php artisan down"))
        self.assertLess(normal.index("--require-current"), normal.index("composer2 "))
        self.assertIn("git diff --name-status", normal)
        self.assertEqual(self.backend["jobs"]["deploy"]["needs"], "validate")

    def test_only_explicit_manual_job_can_migrate_with_verified_backup(self):
        job = self.backend["jobs"]["migrate_configuration"]
        self.assertIn("workflow_dispatch", job["if"])
        self.assertIn("inputs.run_configuration_migration", job["if"])
        self.assertEqual(job["environment"], "production-migrations")
        script = remote_script(job)
        self.assertIn("RESTORE_VERIFIED", script)
        self.assertIn("sha256sum --check --quiet SHA256SUMS", script)
        self.assertLess(script.index("RESTORE_VERIFIED"), script.index("php artisan migrate"))
        self.assertIn("--realpath", script)
        self.assertIn("hash_equals(ReleaseMigrationPlan::MIGRATION_SHA256", script)
        self.assertLess(script.index("hash_equals"), script.index("php artisan migrate"))
        self.assertIn("2026_10_28_000001_add_configuration_context_to_order_items.php", script)
        self.assertNotIn("checkout --detach", script)
        self.assertNotIn("composer2 install", script)

    def test_both_repositories_and_migration_share_server_lock(self):
        for job in [self.backend["jobs"]["deploy"], self.backend["jobs"]["migrate_configuration"],
                    self.frontend["jobs"]["deploy"]]:
            script = remote_script(job)
            self.assertIn('exec 9>"$HOME/.upgrade79-deploy.lock"', script)
            self.assertIn("flock -w 900 9", script)
        steps = self.frontend["jobs"]["deploy"]["steps"]
        upload = next(s for s in steps if "scp-action" in s.get("uses", ""))
        self.assertNotIn("public_html", upload["with"]["target"])
        self.assertIn("upgrade79-deploy-incoming", upload["with"]["target"])
        self.assertLess(next(i for i,s in enumerate(steps) if s.get("run") == "npm test"),
                        steps.index(upload))
        publish = remote_script(self.frontend["jobs"]["deploy"])
        self.assertLess(publish.index("--require-current"), publish.index("cp -a"))
        self.assertIn("DEPLOY_SHA256SUMS", publish)
        self.assertIn('test "$RELEASE_RUN_NUMBER" -ge "$PUBLISHED_RUN"', publish)

    def fake_commands(self, root):
        binary = root / "bin"
        fixture_file(binary / "git", '#!/usr/bin/env bash\nprintf "git %s\\n" "$*" >> "$TEST_COMMAND_LOG"\n'
                     'case "$1" in\nrev-parse) printf "%s\\n" "$RELEASE_SHA" ;;\n'
                     'archive) cat "$TEST_ARCHIVE" ;;\nesac\n')
        fixture_file(binary / "php", '#!/usr/bin/env bash\nprintf "php %s\\n" "$*" >> "$TEST_COMMAND_LOG"\n'
                     'if [ "$TEST_SCHEMA_FAIL" = "0" ]; then exit 0; fi\nexit 42\n')
        fixture_file(binary / "composer2", '#!/usr/bin/env bash\nprintf "composer %s\\n" "$*" >> "$TEST_COMMAND_LOG"\nexit 0\n')
        for executable in binary.iterdir():
            executable.chmod(0o755)
        log = root / "commands.log"
        log.touch()
        return dict(PATH=str(binary)+os.pathsep+os.environ["PATH"], TEST_SCHEMA_FAIL="1",
                    DEPLOY_TEST_ROOT=str(root), TEST_COMMAND_LOG=str(log),
                    RELEASE_SHA="a"*40, RELEASE_RUN_NUMBER="23", BACKEND_SHA="", BACKUP_ID="")

    def test_failed_backend_schema_gate_never_changes_installed_code_or_starts_maintenance(self):
        with tempfile.TemporaryDirectory(prefix="upgrade79-deploy-policy-") as directory:
            root = Path(directory)
            project, public = root / "project", root / "api-public"
            for name in [".env", "artisan", "installed-marker"]:
                fixture_file(project/name, "ISOLATED FIXTURE ONLY\n")
            fixture_file(public/"index.php", "OLD API\n")
            archive = root/"candidate.tar"
            with tarfile.open(archive, "w") as tar:
                for name in ["ops/release-preflight.php", "database/migrations/reviewed.php",
                             "composer.json", "composer.lock"]:
                    data = b"isolated fixture\n"
                    info = tarfile.TarInfo(name); info.size = len(data)
                    tar.addfile(info, io.BytesIO(data))
            script = remote_script(self.backend["jobs"]["deploy"]).replace("$HOME", "$DEPLOY_TEST_ROOT")
            script, replacements = re.subn(r"(?m)^PROJECT=.*$", "PROJECT="+shlex.quote(str(project)), script)
            self.assertEqual(replacements, 1)
            script, replacements = re.subn(r"(?m)^PUBLIC=.*$", "PUBLIC="+shlex.quote(str(public)), script)
            self.assertEqual(replacements, 1)
            env = {**self.fake_commands(root), "TEST_ARCHIVE": str(archive)}
            result = execute(script, env)
            self.assertEqual(result.returncode, 42, result.stderr)
            log = (root/"commands.log").read_text()
            self.assertIn("ops/release-preflight.php", log)
            self.assertNotIn("checkout", log)
            self.assertNotIn("composer ", log)
            self.assertNotIn("artisan down", log)
            self.assertEqual((project/"installed-marker").read_text(), "ISOLATED FIXTURE ONLY\n")
            self.assertEqual((public/"index.php").read_text(), "OLD API\n")

    def test_successful_ordinary_backend_deploy_never_runs_migrations(self):
        with tempfile.TemporaryDirectory(prefix="upgrade79-deploy-policy-") as directory:
            root = Path(directory)
            project, public = root/"project", root/"api-public"
            for name in [".env", "artisan", "ops/release-preflight.php"]:
                fixture_file(project/name, "ISOLATED FIXTURE ONLY\n")
            fixture_file(project/"public/index.php", "NEW API\n")
            (project/"storage/app/public").mkdir(parents=True)
            fixture_file(public/"index.php", "OLD API\n")
            archive = root/"candidate.tar"
            with tarfile.open(archive, "w") as tar:
                for name in ["ops/release-preflight.php", "database/migrations/reviewed.php",
                             "composer.json", "composer.lock"]:
                    data = b"isolated fixture\n"
                    info = tarfile.TarInfo(name); info.size = len(data)
                    tar.addfile(info, io.BytesIO(data))
            script = remote_script(self.backend["jobs"]["deploy"]).replace("$HOME", "$DEPLOY_TEST_ROOT")
            script, count = re.subn(r"(?m)^PROJECT=.*$", "PROJECT="+shlex.quote(str(project)), script)
            self.assertEqual(count, 1)
            script, count = re.subn(r"(?m)^PUBLIC=.*$", "PUBLIC="+shlex.quote(str(public)), script)
            self.assertEqual(count, 1)
            env = {**self.fake_commands(root), "TEST_ARCHIVE": str(archive), "TEST_SCHEMA_FAIL": "0"}
            result = execute(script, env)
            self.assertEqual(result.returncode, 0, result.stderr)
            log = (root/"commands.log").read_text()
            self.assertLess(log.index("ops/release-preflight.php"), log.index("checkout --detach"))
            self.assertIn("composer install", log)
            self.assertIn("artisan config:cache", log)
            self.assertIn("artisan up", log)
            self.assertNotIn("artisan migrate", log)
            self.assertEqual((public/"index.php").read_text(), "NEW API\n")
            self.assertEqual(len(list((root/"upgrade79-deploy-rollbacks").glob("*/backend-code.tar.gz"))), 1)

    def test_failed_frontend_schema_gate_never_replaces_published_html(self):
        with tempfile.TemporaryDirectory(prefix="upgrade79-deploy-policy-") as directory:
            root = Path(directory)
            project = root/"domains/crmupgradecol.com/laravel"
            front = root/"domains/upgradecolombia.com/public_html"
            candidate = root/("upgrade79-deploy-incoming/frontend/"+"a"*40)
            fixture_file(project/"ops/release-preflight.php", "isolated fixture\n")
            for name in ["laravel-api.php", ".htaccess", "index.html"]:
                fixture_file(front/name, "OLD PUBLIC FILE\n")
            files = {"index.html": "NEW HTML\n", "RELEASE_COMMIT": "a"*40+"\n",
                     "assets/new.js": "isolated artifact\n"}
            for name, data in files.items():
                fixture_file(candidate/name, data)
            manifest = "".join(hashlib.sha256(data.encode()).hexdigest()+"  ./"+name+"\n"
                               for name,data in files.items())
            fixture_file(candidate/"DEPLOY_SHA256SUMS", manifest)
            script = remote_script(self.frontend["jobs"]["deploy"]).replace("$HOME", "$DEPLOY_TEST_ROOT")
            result = execute(script, self.fake_commands(root))
            self.assertEqual(result.returncode, 42, result.stderr)
            self.assertEqual((front/"index.html").read_text(), "OLD PUBLIC FILE\n")
            self.assertEqual((front/"laravel-api.php").read_text(), "OLD PUBLIC FILE\n")
            self.assertFalse((root/".upgrade79-frontend-deploy-state").exists())

    def test_successful_frontend_publish_preserves_bridge_storage_and_runs_smoke_checks(self):
        with tempfile.TemporaryDirectory(prefix="upgrade79-deploy-policy-") as directory:
            root = Path(directory)
            project = root/"domains/crmupgradecol.com/laravel"
            front = root/"domains/upgradecolombia.com/public_html"
            candidate = root/("upgrade79-deploy-incoming/frontend/"+"a"*40)
            fixture_file(project/"ops/release-preflight.php", "isolated fixture\n")
            for name in ["laravel-api.php", ".htaccess", "index.html", "storage/keep.jpg"]:
                fixture_file(front/name, "KEEP ORIGINAL\n")
            files = {
                "index.html": '<title>Fixture</title><h1>Fixture</h1><script src="/assets/main.js"></script>',
                "RELEASE_COMMIT": "a"*40+"\n", "assets/main.js": "https://upgradecolombia.com",
                "sw.js": "self.addEventListener('push',()=>{});", "manifest.webmanifest": "{}",
                "offline.html": "offline", "seo/.htaccess": "fixture routing",
                "seo/install-seo-routing.php": "fixture installer",
                "icons/pwa-192.png": "fixture", "icons/pwa-512.png": "fixture",
                "laravel-api.php": "DO NOT OVERWRITE", "storage/keep.jpg": "DO NOT OVERWRITE",
            }
            for name, data in files.items():
                fixture_file(candidate/name, data)
            fixture_file(candidate/"DEPLOY_SHA256SUMS", "".join(
                hashlib.sha256(data.encode()).hexdigest()+"  ./"+name+"\n" for name,data in files.items()))
            env = {**self.fake_commands(root), "TEST_SCHEMA_FAIL": "0"}
            fixture_file(root/"bin/curl", '''#!/usr/bin/env python3
import os, sys
from pathlib import Path
args=sys.argv[1:]; url=""; output=None; header=None; write=False; i=0
while i<len(args):
    a=args[i]
    if a in ["-o", "--output"]: i+=1; output=args[i]
    elif a in ["-D", "--dump-header"]: i+=1; header=args[i]
    elif a in ["-w", "--write-out"]: i+=1; write=True
    elif a in ["-H", "--header"]: i+=1
    elif a.startswith("https://"): url=a
    i+=1
status="200"; mime="text/html"; body='<title>Fixture</title><h1>Fixture</h1><link rel="canonical" href="https://upgradecolombia.com/">'
if url.endswith("/sw.js"): mime="application/javascript"; body="self.addEventListener('push',()=>{});"
elif url.endswith("/robots.txt"): mime="text/plain"; body="Sitemap: https://upgradecolombia.com/sitemap.xml\\n"
elif url.endswith("/sitemap.xml"): mime="application/xml"; body="<urlset></urlset>"
elif url.endswith("/api/auth/me"): status="401"
elif url.endswith("/__seo-deploy-missing__"): status="404"
elif url.startswith("https://www."): status="301"
for destination,data in [(output,body),(header,"HTTP/2 "+status+"\\ncontent-type: "+mime+"\\ncache-control: no-cache\\n")]:
    if destination and destination!="/dev/null":
        p=Path(destination)
        if not p.resolve().is_relative_to(Path(os.environ["DEPLOY_TEST_ROOT"]).resolve()): raise RuntimeError("Unsafe fixture path")
        p.write_text(data)
if write: print(status,end="")
''')
            (root/"bin/curl").chmod(0o755)
            script = remote_script(self.frontend["jobs"]["deploy"]).replace("$HOME", "$DEPLOY_TEST_ROOT")
            script = script.replace("/tmp/upgrade79-", str(root/"http-"))
            result = execute(script, env)
            self.assertEqual(result.returncode, 0, result.stderr+result.stdout)
            self.assertEqual((front/"index.html").read_text(), files["index.html"])
            for name in ["laravel-api.php", ".htaccess", "storage/keep.jpg"]:
                self.assertEqual((front/name).read_text(), "KEEP ORIGINAL\n")
            self.assertEqual((root/".upgrade79-frontend-deploy-state").read_text(), "23 "+"a"*40+"\n")
            self.assertEqual(len(list((root/"upgrade79-deploy-rollbacks").glob("*/frontend-code.tar.gz"))), 1)


if __name__ == "__main__":
    unittest.main(verbosity=2)
