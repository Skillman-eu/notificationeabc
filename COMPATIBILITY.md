# Moodle 4.1 to 4.5 compatibility review

## Upgrade failure and fix

Moodle deprecated `MESSAGE_DEFAULT_LOGGEDIN` and `MESSAGE_DEFAULT_LOGGEDOFF`
in 4.0 and removed them in 4.5 (MDL-73284). Both processor defaults in
`db/messages.php` now use `MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED`.
This keeps notifications enabled by default and lets users disable them.
The replacement exists in Moodle 4.1; no shim or core modification is needed.

The plugin version is increased so Moodle processes the changed component.
The declared minimum is now Moodle 4.1, matching the compatibility scope of
this change. The obsolete `cron` metadata is removed: the plugin has no cron
callback or scheduled task, and notifications use event observers.
No database schema migration or preference reset is necessary. Existing
site and user notification preferences are intentionally preserved.

Sources:

- [Moodle 4.5 release notes](https://moodledev.io/general/releases/4.5)
- [Moodle 4.1 messaging constants](https://github.com/moodle/moodle/blob/MOODLE_401_STABLE/message/lib.php)
- [Message API](https://moodledev.io/docs/4.5/apis/core/message)

## Additional compatibility fix

Moodle 4.5 requires PHP 8.1 or later. `enrol_plugin::get_config()` returns
null for an unset setting unless a default is supplied. The three message
templates previously passed this null to `strip_tags()`, which emits a
deprecation notice on PHP 8.1+. They now default to an empty string, keeping
the existing fallback messages.

- [PHP 8.1 deprecations](https://www.php.net/manual/en/migration81.deprecated.php)

## Other findings (pre-existing, not confirmed 4.5 API breaks)

- Notification classification is valid: core `message_send()` sets
  `notification = 1` when it is absent. No change is required.
- The event observer names, core user helpers, enrolment method overrides,
  form controls and message object properties used here remain available
  in the reviewed Moodle 4.5 core sources. No other removed API was identified.
- Observers fetch user/course records without checking for a missing record.
  Course or user deletion can leave no live record, causing a TypeError when
  `enviarmail(stdClass, stdClass, ...)` receives false. A future fix should
  skip notifications when either record is missing, with deletion tests.
- Course alert checkboxes currently select custom versus fallback content;
  observers still send all three event types for an enabled course instance.
  Disabled instances can also fall through to enabled global alerts. Decide
  the intended local/global precedence before changing this behavior.
- New-instance submission previously read `customint4` and `customint5`,
  although the form only defines checkboxes `customint1` through `customint3`.
  This is fixed by the PHP 8.3 review below.
- Global sender settings are copied into new course instances but ignored
  in the global-only send path, which uses the support user's name/email.
- Custom recipient lookup assumes email uniqueness, but Moodle can allow
  accounts sharing an email address. `get_record()` can then warn and select
  one account. Define whether to notify every account or only email once.
- Delivery success is overwritten per recipient; a later successful send
  can hide an earlier failure. `message_send()` also cannot guarantee final
  SMTP delivery when a transaction defers processing.
- The plugin has no Privacy API provider. Its templates and recipient
  settings can contain personal data, and notifications are stored by core.
  Assess metadata/export requirements separately; a null provider should
  not be added without assessing the actual data flow.
- Notifications are sent synchronously by observers. Large enrolment imports
  can be slow; moving delivery to an adhoc task is a separate design change.

## Deploy and validate

1. Copy this plugin into `enrol/notificationeabc` on a staging Moodle 4.1
   installation, then test the upgrade to Moodle 4.5 with PHP 8.1+.
2. Resume the interrupted upgrade with `php admin/cli/upgrade.php
   --non-interactive` from the Moodle root, or use Site administration >
   Notifications. Do not define the removed constants in Moodle core.
3. Run `php admin/cli/purge_caches.php` after a successful upgrade.
4. Verify the provider under Site administration > Messaging > Notification
   settings; check popup/email and recipient preferences if delivery is off.
5. Test enrolment creation, update and deletion with global-only settings and
   with an enabled course instance, both custom and empty templates. Test a
   registered staff recipient and an external email recipient. Verify real
   SMTP delivery as well as the notification list.

The included regression test exercises all three fallback notifications
with unset templates and a registered additional recipient. From a Moodle
checkout with its PHPUnit test database initialized, run:

```sh
vendor/bin/phpunit enrol/notificationeabc/tests/notification_test.php
```

## PHP 8.3 review (24 November 2026)

No PHP 8.3-specific syntax or removed-function incompatibility was found.
Moodle 4.5 supports both PHP 8.1 and PHP 8.3; the plugin's minimum Moodle
version does not imply that Moodle 4.1 itself supports PHP 8.3.

The following runtime warnings were addressed for both PHP versions:

- Both instance creation and updates now use `get_instance_fields()` to
  default absent optional controls, including the legacy `customint4` and
  `customint5` fields, to database-safe values. Editor contents and submitted
  checkbox values are preserved.
- New-instance editor, sender and checkbox defaults no longer contain null
  when their global settings are missing.
- Unknown notification types now throw `invalid_parameter_exception`
  instead of continuing with an undefined `$mensaje` and passing null to
  message formatting functions.

Dynamic properties require no attribute workaround: the plugin adds data
to `stdClass` records, which remain exempt from PHP 8.2/8.3 deprecations.
The core message object exposes its properties through magic accessors.
No PHP 8.2-only or PHP 8.3-only syntax was introduced.

PHPUnit coverage includes unset/configured defaults, absent optional form
controls, saving real enrolment fields, rejecting unknown types, and the
existing three notification types with an additional registered recipient.
The GitHub Actions workflow `.github/workflows/phpunit.yml` installs Moodle
4.5 and a dedicated PostgreSQL test database and runs lint and PHPUnit on
PHP 8.1 and 8.3. These CI jobs have not been run from this workspace.

Local validation: all 13 plugin PHP files pass PHP 8.1.5 and PHP 8.3.37 lint, and
`git diff --check` passes. The local Moodle checkouts lack their vendor
dependencies, so the Moodle PHPUnit suite has not been executed locally.
SMTP delivery and the end-to-end Moodle upgrade still require staging
validation as described above.

Sources: [PHP 8.3 incompatible changes](https://www.php.net/manual/en/migration83.incompatible.php),
[PHP 8.3 deprecations](https://www.php.net/manual/en/migration83.deprecated.php),
[PHP 8.2 dynamic property rules](https://www.php.net/manual/en/migration82.deprecated.php).
