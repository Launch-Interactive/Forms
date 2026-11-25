=== Launch Interactive Forms ===
Contributors: launchinteractive
Tags: form, contact form, form builder, mailchimp, turnstile
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight form builder that outputs clean HTML and takes its look from your theme. Build forms in the block editor and reuse them anywhere.

== Description ==

Launch Interactive Forms is a form builder for people who want forms that look like the rest of their site. It outputs minimal, accessible HTML with no inline styles, so your theme's styles for inputs and buttons apply as they are. Block themes style the submit button through theme.json.

**Build anywhere, reuse everywhere**

* Build a form right in the block editor with the Form block, or under Forms → Add New.
* Each form is a reusable record. Place it on as many pages as you like; edit it once and it changes everywhere.
* Use the `[liforms id="123"]` shortcode in Elementor, classic content, widgets and other page builders.
* Drag fields into order, or use the keyboard: tab to a field's handle and press the up and down arrow keys.

**Fields**

* Text, email, website, phone, number, paragraph, dropdown (single or multiple), radio buttons, checkboxes, date, country, hidden, file upload and text/HTML content.
* A consent checkbox whose label can link to your terms, e.g. "I agree to the terms and conditions".
* Side-by-side layouts: give fields a width (1/2, 1/3, 2/3, 1/4, 3/4). They stack automatically on small screens.
* Conditional logic: show or hide fields based on other answers. It runs live in the browser and again on the server, so hidden fields are never validated or stored.

**After a submission**

* Submissions are stored in WordPress under Forms → Submissions (can be turned off per form).
* Email notifications, as many per form as you need, with merge tags such as `{field:email}` and `{all_fields}`. Send autoresponders to the visitor.
* Success messages can use merge tags too: "A confirmation email was sent to {field:email}."
* Add subscribers to a Mailchimp audience, with merge fields, tags, double opt-in and an optional consent checkbox.
* Track submissions in Google Analytics: give a form an event name (e.g. `contact_form_submit`) and every successful submission is sent to Google Analytics or Google Tag Manager, with the form's ID and name. No code needed.
* Optional SMTP settings send all of your site's email through your email provider. The password is stored encrypted.
* A test email tool shows exactly why sending failed.

**Spam protection and security**

* A honeypot on every form.
* Google reCAPTCHA (v2 or v3) or Cloudflare Turnstile, added as a field you can position anywhere in the form.
* Each visitor can make 10 successful submissions per 10 minutes.
* Uploaded files are stored privately under random names, outside the media library, and can only be downloaded by administrators. A Site Health check confirms your server really blocks the folder.
* Forms still work without JavaScript.

Developer documentation, hooks and filters are on [GitHub](https://github.com/Launch-Interactive/Forms).

== External services ==

Launch Interactive Forms works without any external service. The services below are used only if you turn them on.

**Google reCAPTCHA** checks that a submission comes from a person. It is used only when you add a reCAPTCHA field to a form and enter reCAPTCHA keys under Forms → Settings. On pages with that form, the visitor's browser loads Google's script from www.google.com, which may collect information about the visitor and their device. When the form is submitted, your site sends the reCAPTCHA response token, your secret key and the visitor's IP address to Google to verify it.
Google [Terms of Service](https://policies.google.com/terms) and [Privacy Policy](https://policies.google.com/privacy).

**Cloudflare Turnstile** checks that a submission comes from a person. It is used only when you add a Turnstile field to a form and enter Turnstile keys under Forms → Settings. On pages with that form, the visitor's browser loads Cloudflare's script from challenges.cloudflare.com. When the form is submitted, your site sends the Turnstile response token, your secret key and the visitor's IP address to Cloudflare to verify it.
Cloudflare [Website and Online Services Terms of Use](https://www.cloudflare.com/website-terms/) and [Privacy Policy](https://www.cloudflare.com/privacypolicy/).

**Mailchimp** adds people who submit a form to your mailing list. It is used only when you enter a Mailchimp API key under Forms → Settings and turn on Mailchimp for a form. When that form is submitted (and, if you chose one, its consent checkbox is ticked), your site sends the email address, the fields you mapped to merge fields, your chosen tags and the subscription status to the Mailchimp Marketing API (your-datacenter.api.mailchimp.com). The form editor also fetches the names of your audiences from Mailchimp so you can pick one.
Intuit Mailchimp [Terms of Use](https://mailchimp.com/legal/terms/) and [Privacy Policy](https://mailchimp.com/legal/privacy/).

**Your SMTP server.** If you turn on Use Custom SMTP, all email your site sends, including form notifications, goes through the SMTP server you enter. Its terms and privacy policy are those of your email provider.

== Installation ==

1. Install the plugin from Plugins → Add New, or upload the zip under Plugins → Add New → Upload Plugin.
2. Activate it.
3. Add a Form block to any page and choose "Create new form", or go to Forms → Add New.
4. Optional: enter reCAPTCHA or Turnstile keys, a Mailchimp API key and SMTP details under Forms → Settings, then use Send a test email to check delivery.

If your site runs on nginx, block web access to `wp-content/uploads/launch-interactive-forms/` or move uploads outside the web root (see the FAQ). Tools → Site Health tells you whether the folder is protected.

== Frequently Asked Questions ==

= How do I track submissions in Google Analytics? =

Open the form, go to the Settings tab and enter a **Google Analytics event** name, such as `contact_form_submit` or GA4's recommended `generate_lead`. Each successful submission then calls `gtag( 'event', 'contact_form_submit', { form_id, form_name } )`, or pushes the same event to the Google Tag Manager `dataLayer` if your site uses GTM. The plugin doesn't add Google Analytics itself, so your site needs to load it already (for example through Site Kit or your theme). To report on it, mark the event as a key event in Google Analytics, or add a Custom Event trigger in Tag Manager.


= Why doesn't the form have its own styling? =

On purpose. The plugin uses your theme's styles for inputs, labels and buttons, so forms match your site without extra CSS. A tiny structural stylesheet handles layout (side-by-side fields, error messages) and can be turned off under Forms → Settings.

= How do I use a form in Elementor or another page builder? =

Use the shortcode shown on the form's edit screen, e.g. `[liforms id="123"]`.

= Are uploaded files public? =

No. They are stored in a private folder under random names with no file extension, and only administrators can download them, through a logged-in, nonce-checked link. Apache and IIS are protected automatically. On nginx, add `location ^~ /wp-content/uploads/launch-interactive-forms/ { deny all; }` to your server config, or add `define( 'LIFORMS_PRIVATE_DIR', '/path/outside/webroot' );` to wp-config.php.

= Can I keep API keys out of the database? =

Yes. Any setting can be defined in wp-config.php, e.g. `define( 'LIFORMS_MAILCHIMP_API_KEY', '...' );` or `define( 'LIFORMS_SMTP_PASSWORD', '...' );`.

= Can I change the required field marker? =

Yes. Under Forms → Settings, change the `*` to anything you like, for example "(required)", or leave it empty.

= My site blocks the REST API for visitors. Do forms still work? =

Yes. Forms normally send without reloading the page, through the WordPress REST API. If your site or a security plugin refuses that request, the form is sent the ordinary way instead and the page reloads with the result. To keep forms sending without a reload, allow the `liforms/v1` routes in your REST API restriction.

= Does it work without JavaScript? =

Yes. Forms submit normally when JavaScript isn't available, and conditional logic is still applied on the server.

== Changelog ==

= 2.1.0 =
* Changed: one prefix throughout. PHP names, options, hooks, post types, the REST namespace, the block and the shortcode use `liforms` (`liforms_settings`, `liforms_form_html`, `liforms/v1`, `liforms/form`, `[liforms]`); constants use `LIFORMS_`; the namespace is `LaunchInteractiveForms`. CSS classes, HTML ids, data attributes, CSS variables and form field names use `lif-` / `lif_`. Spacing is one variable, `--lif-gap`, for rows and side-by-side fields.
* Existing data, blocks and shortcodes (in post content, Elementor data and widgets) are moved to the new names automatically. Theme CSS, custom code and wp-config.php constants need updating by hand.

= 2.0.0 =
* Renamed from Launch Forms to Launch Interactive Forms (slug `launch-interactive-forms`).
* Changed: the post types are now `launch_forms_form` and `launch_forms_entry`, submission meta keys start with `_launch_forms_`, and private uploads live in `wp-content/uploads/launch-interactive-forms/`. Existing forms, submissions and files are moved automatically.
* Fixed: removing the field a Mailchimp action uses for consent no longer switches it to "always subscribe". Nobody is subscribed until another field is chosen.
* Security: the success message is filtered after merge tags are filled in, so a visitor's value can't turn a link into a `javascript:` URL.
* Changed: email attachment copies are made inside the protected private upload folder instead of the system temp folder.
* Changed: the settings page script is an enqueued file instead of an inline script.

= 1.8.0 =
* Changed: simpler form markup. Fields sit directly in the form, without the `lf-fields` wrapper, and the submit button follows them as the last item. The form is laid out with CSS grid (12 columns).

= 1.7.3 =
* Changed: fields are spaced with CSS gap instead of margins. Adjust the spacing with the `--lf-row-gap` and `--lf-gap` CSS variables.

= 1.7.2 =
* Fix: forms work on sites that block the WordPress REST API for visitors (for example with a security plugin), instead of showing "You are not currently logged in." The form is sent the ordinary way.
* Fix: Google Analytics events are also sent for forms submitted without JavaScript.

= 1.7.1 =
* Improved: the conditional logic script loads only on forms that use conditional logic, and no longer delays the page.

= 1.7.0 =
* New: send a Google Analytics (gtag.js) or Google Tag Manager event when a form is submitted, set per form with no code.

= 1.6.3 =
* Changed: uploaded files are saved with WordPress's own upload handler (still private, under random names).
* Changed: translations load the standard WordPress.org way.

= 1.6.2 =
* Fix: the Form block's preview now shows changes you haven't saved yet, such as a newly added field.

= 1.6.1 =
* New: the consent checkbox label can contain links, e.g. to your terms and conditions.

= 1.6.0 =
* New: Country field with an optional default country.
* Changed: the up and down buttons on each field are replaced by keyboard reordering on the drag handle.

= 1.5.1 =
* Fix: fields can be dragged to the bottom of the list.

= 1.5.0 =
* New: reCAPTCHA and Turnstile are fields you can position anywhere in the form.

= 1.4.0 =
* New: merge tags in the success message.

= 1.3.0 =
* New: SMTP settings for all WordPress email, with an encrypted password.
* New: setting for the required field marker.

= 1.2.0 =
* Security: submission rate limit, Site Health check for private uploads, stricter handling of logged-in submissions and page URLs.

= 1.1.0 =
* New: field widths for side-by-side layouts.
* New: drag and drop field ordering.
* Improved: friendly message when a file download link is refused or has expired.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 2.1.0 =
New prefix: update theme CSS (`.lf-*` → `.lif-*`, `--lf-row-gap` / `--lf-gap` → `--lif-gap`), hooks (`launch_forms_*` → `liforms_*`) and wp-config.php constants (`LAUNCH_FORMS_*` → `LIFORMS_*`). Forms, submissions, blocks and shortcodes are updated automatically.

= 2.0.0 =
New name and folder (launch-interactive-forms). Forms, submissions and uploads move to the new names automatically the first time it loads.

= 1.8.0 =
The `lf-fields` wrapper is gone and forms use CSS grid. If your theme styles `.lf-fields`, move those styles to `.lf-form`.
