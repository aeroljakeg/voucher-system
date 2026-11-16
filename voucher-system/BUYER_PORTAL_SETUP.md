# Buyer portal setup

The buyer shop is available at `/shop/` and sells single-use gift voucher codes for **$10, $25, $50, or $100 USD**. Buyers can check out as guests or create a separate buyer account to save purchases and view their purchase history. After paying through Stripe Checkout, buyers can view the voucher code and printable receipt. Buyer accounts do not provide staff access. Staff can review purchases and mark codes redeemed under **Administration → Buyer purchases**.

## Database

For an existing installation, import `database/buyer_portal.sql` and then `database/buyer_account_migration.sql` into the `voucher_system_app` database. The second migration creates buyer accounts and links accounts to future orders; older guest orders remain available as guest purchases. New installations include both tables in `database/voucher_system.sql`.

## Stripe test mode with XAMPP

1. Create a Stripe account and use **test-mode** API keys. Never put live keys in source files or share them in chat.
2. In `C:\xampp\apache\conf\httpd.conf`, add these server environment settings, replacing the placeholders:

   ```apache
   SetEnv APP_BASE_URL "http://localhost/voucher-system/voucher-system"
   SetEnv STRIPE_SECRET_KEY "sk_test_replace_with_your_key"
   SetEnv STRIPE_WEBHOOK_SECRET "whsec_replace_with_your_signing_secret"
   ```

3. Restart Apache in XAMPP.
4. For local webhook testing, install Stripe CLI and run:

   ```powershell
   stripe listen --forward-to http://localhost/voucher-system/voucher-system/shop/webhook.php
   ```

   Use the `whsec_...` signing secret printed by Stripe CLI for `STRIPE_WEBHOOK_SECRET`.

Open `http://localhost/voucher-system/voucher-system/shop/` to test the buyer flow. Card details are handled by Stripe Checkout and are not stored by this app.

For a public deployment, set `APP_BASE_URL` to the site's HTTPS URL, configure a Stripe webhook for `checkout.session.completed` and `checkout.session.expired`, and use the production keys only after testing.

## Vercel container test deployment

Vercel does not provide a built-in PHP runtime for this project. The root `Dockerfile` packages PHP with Apache and the required cURL, PDO MySQL, and mbstring extensions for Vercel's beta Container Runtime. In the Vercel project settings, select the **Container** framework preset. Local Docker testing is recommended before deploying.

The application still requires an external MySQL database. Create the database with your database provider, then import the schema into that selected database. The fresh-install SQL begins by creating and selecting `voucher_system_app`; remove those first two statements in a deployment copy if the provider assigns a different database name.

Set these variables in the Vercel project's environment settings:

- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` (and `DB_PORT` if the provider uses a non-default port)
- `DB_SSL_CA` if the MySQL provider requires a CA certificate file; include the provider's public CA certificate in the container image and set this to its container path
- `APP_BASE_URL` (the deployed HTTPS origin, with no trailing slash)
- `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`

Configure the Stripe webhook URL as `https://your-domain/shop/webhook.php` and subscribe to `checkout.session.completed` and `checkout.session.expired`. Start with Stripe test keys. Do not commit credentials or upload a `.env` file.

The container's local filesystem is not durable across restarts or deployments. PHP file sessions may be lost when an instance scales down or requests reach another instance, so this setup is for a test deployment rather than production use until sessions are moved to shared storage. Keep the Git repository private, change any seeded/default admin password, and do not rely on container-local files for persistent uploads.
