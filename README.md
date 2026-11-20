# Launch Forms

A lightweight WordPress form builder that outputs minimal, semantic HTML and takes its styling from the active theme.

## Features

- **Build anywhere**: in the block editor (Form block → "Create new form" or pick an existing one) or under **Forms → Add New**. Forms are a separate, reusable record: one form can be placed on any number of pages, and editing it anywhere updates it everywhere.
- **Shortcode** for Elementor, classic content, widgets and other builders: `[launch_form id="123"]` (optional `class="..."`).
- **Minimal output**: labels, fieldsets/legends, ARIA error wiring, no inline styles. The submit button uses `wp-element-button`, so block themes style it through theme.json. The structural stylesheet is under 2 KB and can be turned off in settings.
- **Field types**: text, email, URL, phone, number, paragraph, dropdown (single/multi), radio, checkboxes, consent checkbox, date, hidden, file upload, HTML content.
- **Conditional logic**: show or hide a field when all or any rules match (`is`, `is not`, `contains`, `empty`, `>`, `<`, and so on). It is evaluated live in the browser and again on the server, so hidden fields are never validated or stored. Chained conditions are supported.
- **Submissions** are stored under **Forms → Submissions** (admins only), with a per-form filter, an action log, and secure file downloads. Storage can be turned off per form.
- **Email notifications**: multiple per form, with merge tags (`{field:key}`, `{all_fields}`, `{form_title}`, `{site_name}`, `{admin_email}`, `{page_url}`, `{date}`, `{submission_id}`). Supports Reply-To and From, autoresponders (`{field:email}` as recipient), and optional attachment of uploaded files.
- **Test email**: **Forms → Settings → Send a test email** shows the exact `wp_mail()` failure reason when sending fails.
- **Mailchimp**: per-form audience, email field, merge fields, tags, double opt-in, and an optional "only if this box is ticked" consent field.
- **Spam protection**: a built-in honeypot, plus optional Google reCAPTCHA (v2 checkbox or v3 score) or Cloudflare Turnstile per form.
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

## Settings via wp-config.php

Any setting can be defined as a constant, which keeps secrets out of the database:

```php
define( 'LAUNCH_FORMS_MAILCHIMP_API_KEY', 'xxxx-us21' );
define( 'LAUNCH_FORMS_TURNSTILE_SECRET_KEY', '...' );
define( 'LAUNCH_FORMS_RECAPTCHA_SECRET_KEY', '...' );
```

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

The front end also dispatches `launch-forms:success` and `launch-forms:error` DOM events on the form.

## Development and tests

No build step is needed: the editor UI is written with `wp.element.createElement`.

```bash
composer install
composer install-wp-tests   # downloads WordPress + the SQLite drop-in into .wp-tests/ (no MySQL needed)
vendor/bin/phpunit          # PHP integration tests on the real WordPress test suite
npm install && npm test     # JS tests (conditional logic, builder helpers, front end under jsdom)
```
