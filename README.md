# Launch Interactive Forms

A lightweight WordPress form builder that outputs minimal, semantic HTML and takes its styling from the active theme.

## Features

- **Build anywhere**: in the block editor (Form block → "Create new form" or pick an existing one) or under **Forms → Add New**. Forms are a separate, reusable record: one form can be placed on any number of pages, and editing it anywhere updates it everywhere.
- **Shortcode** for Elementor, classic content, widgets and other builders: `[liforms id="123"]` (optional `class="..."`).
- **Minimal output**: labels, fieldsets/legends, ARIA error wiring, no inline styles. The submit button uses `wp-element-button`, so block themes style it through theme.json. The structural stylesheet is under 2 KB and can be turned off in settings.
- **Side-by-side fields**: give each field a width (full, 1/2, 1/3, 2/3, 1/4, 3/4); fields next to each other share a row and stack when the form is narrower than 480px. Classes are `lif-field--half` and so on; the form is a 12-column CSS grid (1/2 spans 6 columns, 1/3 spans 4, and so on), with fields, messages and the submit button (`.lif-submit`) as its direct children. Spacing is CSS `gap` (no margins): set `--lif-gap` on `.lif-form` to change the space between rows and between side-by-side fields, e.g. `.lif-form { --lif-gap: 1.5em; }`. It defaults to `1em`. Error messages use `--lif-error-color` (default `#b32d2e`).
- **Field types**: text, email, URL, phone, number, paragraph, dropdown (single/multi), radio, checkboxes, consent checkbox, date, country, hidden, file upload, HTML content.
- **Country field**: a dropdown of all countries (ISO 3166-1, sorted by name) with an optional default country. It stores the country's name, uses `autocomplete="country-name"` so browsers can fill it in, and rejects anything not in the list. Limit or rename the list with the `liforms_countries` filter.
- **Consent checkbox links**: the consent checkbox's label may contain links and bold or italic text, e.g. `I agree to the <a href="/terms">terms and conditions</a>`. Anything else is stripped; links with `target="_blank"` get `rel="noopener noreferrer"`. Emails, the submissions screen and exports show the label as plain text. Other field labels are always plain text.
- **Reordering fields**: drag a field by its handle, or tab to the handle and press the up and down arrow keys.
- **Conditional logic**: show or hide a field when all or any rules match (`is`, `is not`, `contains`, `empty`, `>`, `<`, and so on). It is evaluated live in the browser and again on the server, so hidden fields are never validated or stored. Chained conditions are supported.
- **Submissions** are stored under **Forms → Submissions** (admins only), with a per-form filter, an action log, and secure file downloads. Storage can be turned off per form.
- **Success message merge tags**: the success message can use the same merge tags as emails, e.g. `A confirmation email was sent to {field:email}.` Visitor values are HTML-escaped. Without JavaScript, the filled-in message is carried across the redirect for 10 minutes under a random token (the values never appear in the URL).
- **Email notifications**: multiple per form, with merge tags (`{field:key}`, `{all_fields}`, `{form_title}`, `{site_name}`, `{admin_email}`, `{page_url}`, `{date}`, `{submission_id}`). Supports Reply-To and From, autoresponders (`{field:email}` as recipient), and optional attachment of uploaded files.
- **Required marker**: required fields show `*` after the label by default. Change it under **Forms → Settings → Required field marker** (for example `(required)`), leave it empty to show nothing, or use the `liforms_required_marker` filter. Inputs always carry the `required` attribute.
- **Google Analytics / Tag Manager**: set an event name on a form's Settings tab (e.g. `contact_form_submit`) and each successful submission runs `gtag( 'event', name, { form_id, form_name } )`, or `dataLayer.push( { event: name, form_id, form_name } )` when only Google Tag Manager is present. Failed submissions aren't counted. Plain-POST submissions are counted too, on the page shown after the submission (the `lif_sent` and `lif_msg` query arguments are then removed from the address, so a reload doesn't count twice), except when the form redirects to another URL. With a redirect URL, the redirect waits for the event to be sent (at most about a second, so blocked analytics never hold visitors up). The site must already load Google Analytics or GTM. For custom tracking, listen for the `liforms:success` DOM event instead.
- **SMTP**: tick **Use Custom SMTP** under **Forms → Settings → Email (SMTP)** to send all WordPress email through your email provider's SMTP server (host, port, TLS/SSL, username and password, From address and name, optional forced From). The password is stored encrypted with a key derived from the site's salts and is never shown again.
- **Test email**: **Forms → Settings → Send a test email** shows the exact `wp_mail()` failure reason when sending fails.
- **Mailchimp**: per-form audience, email field, merge fields, tags, double opt-in, and an optional "only if this box is ticked" consent field.
- **Zoho CRM**: create a lead per submission. Connect once under **Forms → Settings → Zoho CRM**: create a *Server-based Application* in the Zoho API Console for your region, register the Redirect URI shown on the settings page (`/wp-admin/admin-post.php?action=liforms_zoho_callback`), paste the client ID and secret and click **Connect to Zoho**. The refresh token and secret are stored encrypted; `LIFORMS_ZOHO_CLIENT_ID` / `LIFORMS_ZOHO_CLIENT_SECRET` in wp-config.php override the saved values. Requested scopes: `ZohoCRM.modules.leads.ALL,ZohoCRM.settings.fields.READ` (filter `liforms_zoho_scopes`). Per form, map form fields to lead fields (the builder lists your org's lead fields; `Full name` splits one field into First/Last Name), set a Lead Source, optionally upsert by Email, skip workflow rules, add `{all_fields}` to the Description, and gate on a consent checkbox. A lead with no Last Name gets the email address. The result is logged on the submission. Uses Zoho CRM API v8.
- **Spam protection**: a built-in honeypot on every form, plus Google reCAPTCHA (v2 checkbox or v3 score) or Cloudflare Turnstile as a **field**: add it from the field list and drag it where you want it (with a width, like any field). One per form; it is always shown and checked, so it can't use conditional logic, and a failed check is reported at the widget. Keys go in **Forms → Settings**; until they are set the field isn't shown. Forms that chose a captcha in the old per-form setting get the field added at the end automatically.
- **Works without JavaScript**: forms submit with a plain POST when JavaScript is unavailable; otherwise they submit with AJAX through the REST API. If the REST request is refused before it reaches the plugin (a 401, 403, 404 or 405 reply that isn't the plugin's own, e.g. from a security plugin that limits the REST API to logged-in users or from a firewall), the script falls back to the plain POST. Allow the `liforms/v1` routes to keep AJAX submission.

## Upload security

- Each field has its own extension allow-list, which is intersected with WordPress' allowed MIME types. The file content must match its extension (`wp_check_filetype_and_ext`).
- Executable and script types (php, phtml, phar, html, svg, js, exe, and so on) are always blocked, in **any** part of the name, so `shell.php.jpg` is rejected.
- Each field has its own size limit, capped by the server limit.
- Files are stored in `wp-content/uploads/launch-interactive-forms/` under random 128-bit names with no extension. They never enter the media library. The folder is locked with `.htaccess` (Apache), `web.config` (IIS) and `index.php`.
- Files can only be downloaded through a nonce-checked admin handler that requires the manage capability. They are served as `application/octet-stream` attachments with `nosniff` and a sandbox CSP.
- **nginx ignores `.htaccess`.** On nginx, either add `location ^~ /wp-content/uploads/launch-interactive-forms/ { deny all; }` or, better, store files outside the web root:
  ```php
  define( 'LIFORMS_PRIVATE_DIR', '/var/private/liforms' ); // wp-config.php
  ```
- Files are deleted when a submission is permanently deleted, when a submission fails validation, and right after emails are sent if storage is turned off.
- **Tools → Site Health** includes a "Launch Interactive Forms uploads are private" check. It writes a harmless test file to the private folder and requests it over HTTP, so it tells you whether your server actually blocks the folder.

## Submission security

- **Rate limit:** each IP address can make 10 successful submissions per 10 minutes (failed validation doesn't count). Further attempts get "Too many submissions" (HTTP 429). IPs are stored only as salted hashes. Change it with the `liforms_rate_limit` filter, or return `false` to turn it off. Behind a proxy or CDN that hides visitor IPs, use `liforms_client_ip` to pass the real IP, or every visitor will share one allowance.
- Spam protection: honeypot on every form, plus reCAPTCHA or Turnstile per form (recommended on every public form, especially ones with file uploads or autoresponders).
- A submission is only linked to a logged-in user when the form carried a nonce issued to them, so other sites can't post forms in a user's name. Logged-out pages contain no nonces and stay cacheable.
- The page URL recorded with each submission is only kept if it points at this site.
- All input is sanitised by field type and limited in length; dropdown, radio and checkbox values must be one of the configured options.
- Emails are plain text, and every header value has line breaks removed.
- `composer security` runs PHPCS with WordPress' security rules (escaping, nonces, input sanitising, SQL, redirects).

## Settings via wp-config.php

Any setting can be defined as a constant, which keeps secrets out of the database:

```php
define( 'LIFORMS_MAILCHIMP_API_KEY', 'xxxx-us21' );
define( 'LIFORMS_TURNSTILE_SECRET_KEY', '...' );
define( 'LIFORMS_RECAPTCHA_SECRET_KEY', '...' );
define( 'LIFORMS_SMTP_PASSWORD', '...' ); // Also SMTP_HOST, SMTP_PORT, SMTP_USERNAME, ...
```

If the security keys (salts) in wp-config.php change, a saved SMTP password can no longer be decrypted; the settings page says so and asks for it again.

## Automated delivery tests

Forms → Settings → **Automated testing** creates a monitoring key (48 hex characters, stored encrypted; `LIFORMS_MONITOR_KEY` in wp-config.php overrides it). A request to the form's submit endpoint (or a plain POST of the form) that carries

```
X-Liforms-Monitor: <key>
X-Liforms-Monitor-Run: <code>   (optional, up to 64 letters, digits and dashes)
```

skips the captcha, honeypot and rate limit, but is validated and its notifications are sent as usual, with `[Automated test <code>] ` prepended to each subject and a short note at the top of the body. It is not stored, Mailchimp/Zoho CRM/`liforms_before_actions`/`liforms_submission` don't run, and the front end doesn't send the analytics event. Notifications whose only recipients are email addresses entered in the form (autoresponders) aren't sent, since they would go back to the monitoring script; they appear in the report with `"skipped": true`. The REST response gains:

```json
"monitor": { "run": "<code>", "notifications": [ { "name": "Admin", "success": true, "message": "Sent", "to": [ "info@example.com" ] } ] }
```

so a script can see which addresses each notification went to and whether the mail server accepted it, then look for the subject in those inboxes. Only send the key to the site itself: with Playwright, add the headers in a route handler for the site's own origin rather than with `setExtraHTTPHeaders`, which would also send them to Google and other third parties.

## Hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `liforms_validation_errors` | filter | Add custom validation errors |
| `liforms_before_actions` | action | Runs after storage, before emails and Mailchimp |
| `liforms_submission` | action | Runs after a successful submission and all actions |
| `liforms_notification_email` | filter | Modify an email before sending |
| `liforms_mailchimp_member` | filter | Modify the Mailchimp member payload |
| `liforms_zoho_lead` | filter | Modify the Zoho CRM lead before it's sent (`$record, $form, $values`) |
| `liforms_zoho_scopes` | filter | OAuth scopes requested when connecting Zoho |
| `liforms_form_html` | filter | Modify rendered form HTML |
| `liforms_private_dir` | filter | Change the private upload folder |
| `liforms_manage_capability` | filter | Capability for submissions, downloads and settings (default `manage_options`) |
| `liforms_rate_limit` | filter | `array( 'max' => 10, 'window' => 600 )`, or `false` to disable |
| `liforms_required_marker` | filter | Text after required field labels (default from settings, `*`) |
| `liforms_smtp_configured` | action | Adjust PHPMailer after SMTP is configured |
| `liforms_countries` | filter | Countries offered by Country fields (ISO code => name) |
| `liforms_client_ip` | filter | Visitor IP used for rate limiting and captcha (for trusted proxies) |

The front end also dispatches `liforms:success` and `liforms:error` DOM events on the form.

## Upgrading from Launch Forms (1.x and 2.0)

Version 2.1.0 uses the `liforms` prefix throughout (`lif-` for CSS classes, HTML ids, data attributes, CSS variables and form field names). Earlier versions used `lf_` / `lf-` (1.x) and `launch_forms` / `launch-forms` (2.0).

1. On a 1.x site, deactivate **Launch Forms** first (the folder name changed in 2.0). Don't delete it yet.
2. Upload and activate **Launch Interactive Forms**. On first load it moves:
   - the settings option (`launch_forms_settings` → `liforms_settings`),
   - forms (`lf_form` / `launch_forms_form` → `liforms_form`, config meta → `liforms_config`),
   - submissions (`lf_submission` / `launch_forms_entry` → `liforms_entry`, meta `_lf_*` / `_launch_forms_*` → `_liforms_*`),
   - blocks and shortcodes in post content, Elementor page data and widgets (`launch-forms/form` → `liforms/form`, `[launch_form]` → `[liforms]`),
   - uploaded files (`uploads/launch-forms-private/` → `uploads/launch-interactive-forms/`).
3. On a 1.x site, remove the old `wp-content/plugins/launch-forms` folder over SFTP or your host's file manager. **Don't use Delete on the Plugins screen**: the old uninstall routine deletes the settings.
4. Update anything outside the plugin by hand:
   - constants in wp-config.php: `LAUNCH_FORMS_*` → `LIFORMS_*` (e.g. `LIFORMS_PRIVATE_DIR`, `LIFORMS_SMTP_PASSWORD`, `LIFORMS_MAILCHIMP_API_KEY`);
   - hooks: `launch_forms_*` → `liforms_*`;
   - REST exceptions in themes or security plugins: `launch-forms/v1` → `liforms/v1`;
   - theme CSS: `.lf-*` → `.lif-*`, `--lf-row-gap` / `--lf-gap` → `--lif-gap` (one variable now sets both directions);
   - JavaScript: `window.launchForms` → `window.liforms`, events `launch-forms:success` / `launch-forms:error` → `liforms:success` / `liforms:error`;
   - shortcodes stored anywhere the upgrade doesn't look (other page builders' own data).

## Development and tests

No build step is needed: the editor UI is written with `wp.element.createElement`.

```bash
composer install
composer install-wp-tests   # downloads WordPress + the SQLite drop-in into .wp-tests/ (no MySQL needed)
vendor/bin/phpunit          # PHP integration tests on the real WordPress test suite
npm install && npm test     # JS tests (conditional logic, builder helpers, front end under jsdom)
```
