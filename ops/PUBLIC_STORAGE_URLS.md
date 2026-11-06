# Public storage origin — production release

This patch changes URL presentation only. It does not move images, rewrite
stored paths, create symlinks, modify inventory or require a migration.

## Required environment

Hostinger backend private configuration:

```dotenv
PUBLIC_STORAGE_URL=https://crmupgradecol.com/storage
```

Do not copy the local `.env` to production. Keep APP_URL, FRONTEND_URL,
SESSION_DOMAIN, credentials, CORS and the existing public bridge unchanged.
Laravel uses `filesystems.disks.public.url`; the deployment already clears
and rebuilds configuration. If an operator changes the variable afterwards,
rebuild configuration in the installed backend explicitly.

Frontend public build settings (not credentials):

```dotenv
VITE_API_BASE_URL=https://upgradecolombia.com
VITE_PUBLIC_STORAGE_URL=https://crmupgradecol.com/storage
PUBLIC_SITE_URL=https://upgradecolombia.com
```

Deploy and verify the pinned backend first. Build the frontend afterwards so
prerendered HTML receives the corrected API URLs. Publish HTML and assets from
the same build; the hydration fix also needs its serialized `bookingDate`.

## URL rules

- Product, variant and gallery use the public disk URL, not the HTTP request host.
- Relative `products/...`, `storage/products/...` and `/storage/products/...`
  resolve with exactly one storage prefix.
- Valid absolute external URLs are preserved, not rewritten indiscriminately.
- Local development can leave PUBLIC_STORAGE_URL unset: APP_URL/storage is used.
- Cart and checkout reuse the frontend resolver and prefer an available stored
  path over an obsolete absolute URL snapshot. They do not rewrite cart identity.
- An obsolete absolute URL without a recoverable path is not silently rebased.

## Read-only checks after an authorized deploy

```bash
cd "$HOME/domains/crmupgradecol.com/laravel"
php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if (rtrim((string) config("filesystems.disks.public.url"), "/") !== "https://crmupgradecol.com/storage") { fwrite(STDERR,"Unexpected public media origin\n"); exit(1); } echo "Public storage origin OK\n";'

curl --fail --silent --show-error \
  https://upgradecolombia.com/api/products/camara-360-cinammon \
  | php -r '$p=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR)["data"]; $urls=array_merge([$p["image_url"]],array_column($p["images"],"image_url")); foreach ($urls as $url) { if (!str_starts_with($url,"https://crmupgradecol.com/storage/")) { fwrite(STDERR,"Unexpected image origin\n"); exit(1); } } echo "Product and gallery origins OK\n";'
```

Check principal, gallery, variant and persisted cart images in the browser.
The old frontend-domain /storage path may still return 404; new API responses
and HTML must no longer point at it. Do not fix that by duplicating images.

## Database safety and rollback

The focused unit tests do not connect to commercial MySQL. The optional feature
test writes transactional fixtures and must not be run against local commercial
or production databases. It was not executed as part of release preparation.

No schema or dependency files change in this functional release. For rollback,
use a forward revert of the release commit, deploy backend first, then update
the frontend backend pin to that rollback commit. Do not restore databases or
delete images to revert a presentation-only change. Keep private .env files out
of Git, and do not disable security audits or preflight checks to force a deploy.
