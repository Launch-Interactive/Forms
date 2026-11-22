# Launch Forms

A lightweight WordPress form builder that outputs minimal, semantic HTML and takes its styling from the active theme.

## Features

- **Build anywhere**: in the block editor (Form block → "Create new form" or pick an existing one) or under **Forms → Add New**. Forms are a separate, reusable record: one form can be placed on any number of pages, and editing it anywhere updates it everywhere.
- **Shortcode** for Elementor, classic content, widgets and other builders: `[launch_form id="123"]` (optional `class="..."`).
- **Minimal output**: labels, fieldsets/legends, ARIA error wiring, no inline styles. The submit button uses `wp-element-button`, so block themes style it through theme.json. The structural stylesheet is under 2 KB and can be turned off in settings.
- **Side-by-side fields**: give each field a width (full, 1/2, 1/3, 2/3, 1/4, 3/4); fields next to each other share a row and stack when the form is narrower than 480px. Classes are `lf-field--half` and so on; change the spacing with the `--lf-gap` CSS variable on `.lf-fields`.
- **Field types**: text, email, URL, phone, number, paragraph, dropdown (single/multi), radio, checkboxes, consent checkbox, date, country, hidden, file upload, HTML content.
- **Country field**: a dropdown of all countries (ISO 3166-1, sorted by name) with an optional default country. It stores the country's name, uses `autocomplete="country-name"` so browsers can fill it in, and rejects anything not in the list. Limit or rename the list with the `launch_forms_countries` filter.
- **Consent checkbox links**: the consent checkbox's label may contain links and bold or italic text, e.g. `I agree to the <a href="/terms">terms and conditions</a>`. Anything else is stripped; links with `target="_blank"` get `rel="noopener noreferrer"`. Emails, the submissions screen and exports show the label as plain text. Other field labels are always plain text.
- **Reordering fields**: drag a field by its handle, or tab to the handle and press the up and down arrow keys.
- **Conditional logic**: show or hide a field when all or any rules match (`is`, `is not`, `contains`, `empty`, `>`, `<`, and so on). It is evaluated live in the browser and again on the server, so hidden fields are never validated or stored. Chained conditions are supported.
- **Submissions** are stored under **Forms → Submissions** (admins only), with a per-form filter, an action log, and secure file downloads. Storage can be turned off per form.
- **Success message merge tags**: the success message can use the same merge tags as emails, e.g. `A confirmation email was sent to {field:email}.` Visitor values are HTML-escaped. Without JavaScript, the filled-in message is carried across the redirect for 10 minutes under a random token (the values never appear in the URL).
- **Email notifications**: multiple per form, with merge tags (`{field:key}`, `{all_fields}`, `{form_title}`, `{site_name}`, `{admin_email}`, `{page_url}`, `{date}`, `{submission_id}`). Supports Reply-To and From, autoresponders (`{field:email}` as recipient), and optional attachment of uploaded files.
- **Required marker**: required fields show `*` after the label by default. Change it under **Forms → Settings → Required field marker** (for example `(required)`), leave it empty to show nothing, or use the `launch_forms_required_marker` filter. Inputs always carry the `required` attribute.
- **SMTP**: tick **Use Custom SMTP** under **Forms → Settings → Email (SMTP)** to send all WordPress email through your email provider's SMTP server (host, port, TLS/SSL, username and password, From address and name, optional forced From). The password is stored encrypted with a key derived from the site's salts and is never shown again.
- **Test email**: **Forms → Settings → Send a test email** shows the exact `wp_mail()` failure reason when sending fails.
- **Mailchimp**: per-form audience, email field, merge fields, tags, double opt-in, and an optional "only if this box is ticked" consent field.
- **Spam protection**: a built-in honeypot on every form, plus Google reCAPTCHA (v2 checkbox or v3 score) or Cloudflare Turnstile as a **field**: add it from the field list and drag it where you want it (with a width, like any field). One per form; it is always shown and checked, so it can't use conditional logic, and a failed check is reported at the widget. Keys go in **Forms → Settings**; until they are set the field isn't shown. Forms that chose a captcha in the old per-form setting get the field added at the end automatically.
- **Works without JavaScript**: forms submit with a plain POST when JavaScript is unavailable; otherwise they submit with AJAX through the REST API.

## Upload security

- Each field has its own extension allow-list, which is intersected with WordPress' allowed MIME types. The file content must match its extension (`wp_check_filetype_and_ext`).
- Executable and script types (php, phtml, phar, html, svg, js, exe, and so on) are always blocked, in **any** part of the name, so `shell.php.jpg` is rejected.
- Each field has its own size limit, capped by the server limit.
- Files are stored in `wp-content/uploads/launch-forms-private/` under random 128-bit names with no extension. They never enter the media library. The folder is locked with `.htaccess` (Apache), `web.config` (IIS) and `index.php`.
- Files can only be downloaded through a nonce-checked admin handler that requires the manage capability. They are served as `application/octet-stream` attachments with `nosniff` and a sandbox CSP.
- **nginx ignores `.htaccess`.** On nginx, either add `location ^~ /wp-content/uploads/launch-forms-private/ { deny all; }` or, better, store files outside the web root:
  ```php
  define( 'LAUNCH_FORMS_PRIVATE_DIR', '/var/private/launch-forms' ); // wp-config.php
  ```
- Files are deleted when a submission is permanently deleted, when a submission fails validation, and right after emails are sent if storage is turned off.
- **Tools → Site Health** includes a "Launch Forms uploads are private" check. It writes a harmless test file to the private folder and requests it over HTTP, so it tells you whether your server actually blocks the folder.

## Submission security

- **Rate limit:** each IP address can make 10 successful submissions per 10 minutes (failed validation doesn't count). Further attempts get "Too many submissions" (HTTP 429). IPs are stored only as salted hashes. Change it with the `launch_forms_rate_limit` filter, or return `false` to turn it off. Behind a proxy or CDN that hides visitor IPs, use `launch_forms_client_ip` to pass the real IP, or every visitor will share one allowance.
- Spam protection: honeypot on every form, plus reCAPTCHA or Turnstile per form (recommended on every public form, especially ones with file uploads or autoresponders).
- A submission is only linked to a logged-in user when the form carried a nonce issued to them, so other sites can't post forms in a user's name. Logged-out pages contain no nonces and stay cacheable.
- The page URL recorded with each submission is only kept if it points at this site.
- All input is sanitised by field type and limited in length; dropdown, radio and checkbox values must be one of the configured options.
- Emails are plain text, and every header value has line breaks removed.
- `composer security` runs PHPCS with WordPress' security rules (escaping, nonces, input sanitising, SQL, redirects).

## Settings via wp-config.php

Any setting can be defined as a constant, which keeps secrets out of the database:

```php
define( 'LAUNCH_FORMS_MAILCHIMP_API_KEY', 'xxxx-us21' );
define( 'LAUNCH_FORMS_TURNSTILE_SECRET_KEY', '...' );
define( 'LAUNCH_FORMS_RECAPTCHA_SECRET_KEY', '...' );
define( 'LAUNCH_FORMS_SMTP_PASSWORD', '...' ); // Also SMTP_HOST, SMTP_PORT, SMTP_USERNAME, ...
```

If the security keys (salts) in wp-config.php change, a saved SMTP password can no longer be decrypted; the settings page says so and asks for it again.

## Hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `launch_forms_validation_errors` | filter | Add custom validation errors |
| `launch_forms_before_actions` | action | Runs after storage, before emails and Mailchimp |
| `launch_forms_submission` | action | Runs after a successful submission and all actions |
| `launch_forms_notification_email` | filter | Modify an email before sending |
| `launch_forms_mailchimp_member` | filter | Modify the Mailchimp member payload |
| `launch_forms_form_html` | filter | Modify rendered form HTML |
| `launch_forms_private_dir` | filter | Change the private upload folder |
| `launch_forms_manage_capability` | filter | Capability for submissions, downloads and settings (default `manage_options`) |
| `launch_forms_rate_limit` | filter | `array( 'max' => 10, 'window' => 600 )`, or `false` to disable |
| `launch_forms_required_marker` | filter | Text after required field labels (default from settings, `*`) |
| `launch_forms_smtp_configured` | action | Adjust PHPMailer after SMTP is configured |
| `launch_forms_countries` | filter | Countries offered by Country fields (ISO code => name) |
| `launch_forms_client_ip` | filter | Visitor IP used for rate limiting and captcha (for trusted proxies) |

The front end also dispatches `launch-forms:success` and `launch-forms:error` DOM events on the form.

## Development and tests

No build step is needed: the editor UI is written with `wp.element.createElement`.

```bash
composer install
composer install-wp-tests   # downloads WordPress + the SQLite drop-in into .wp-tests/ (no MySQL needed)
vendor/bin/phpunit          # PHP integration tests on the real WordPress test suite
npm install && npm test     # JS tests (conditional logic, builder helpers, front end under jsdom)
```
