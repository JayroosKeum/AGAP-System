# PHP security remediation record — 31 October 2026

## Completed

- Installed PHP 8.3.36 for the Laragon Apache integration at
  `C:\laragon\bin\php\php-8.3.36-Win32-vs16-x64`.
- Replaced the CLI installation with PHP 8.5.12 at `C:\php\php.exe`.
- Backed up the original PHP, Apache, and configuration files in
  `C:\laragon\backups\php-security-20261031-133906`.
- Removed the literal `GEMINI_API_KEY` from Apache configuration. Apache now
  uses `PassEnv GEMINI_API_KEY`; the application already reads that server-side
  environment variable.
- Confirmed Apache configuration syntax is valid and confirmed PHP 8.5.12
  loads `C:\php\php.ini` and its enabled extensions.
- Updated Apache's incompatible cURL dependency DLLs (`libssh2.dll` and
  `nghttp2.dll`) to the matching PHP 8.3.36 releases. The original DLLs are
  backed up in `apache-curl-dependencies` under the timestamped backup folder.

## Current safety state

Apache is running with PHP 8.3.36 through `mod_php`; the final local health
check confirmed the expected `php.ini`, Apache SAPI, and all required
extensions: cURL, intl, mbstring, mysqli, OpenSSL, PDO MySQL, PDO SQLite, and
Sodium.

## Credential rotation required

The old API key must be revoked and replaced in its provider console. After
rotation, set the replacement as a Windows service/process environment variable
named `GEMINI_API_KEY` for the Apache/Laragon launch context. Do not add it to
this repository, Apache configuration, browser JavaScript, or log output.

## Validation status

- CLI PHP: `8.5.12` — meets the security-fix requirement.
- Apache config: syntax valid and points to PHP `8.3.36`.
- Apache runtime: `8.3.36`, verified through an HTTP request using the Apache
  SAPI, with no missing required extensions.
