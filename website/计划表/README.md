# LiteTrack PHP edition

This directory is a PHP 8.4 reimplementation of LiteTrack for the existing site.

- Projects, descriptions and lifecycle status
- Multiple `.litematic` files per project
- Server-side gzip/NBT parsing without Node.js, aligned with LiteTrack's bit-packed palette parser
- Material totals in blocks, stacks and shulker boxes
- Project members and owner/admin/member roles
- Claims, cancellation and collected state
- Per-user claim dashboard
- Existing site member identities, sessions, CSRF protection and audit logs
- Bounded streaming gzip decompression and strict NBT structure limits for untrusted uploads
- MySQL runtime schema initialization

Uploaded projection files are validated, parsed from PHP's temporary upload file and
not retained. Compressed uploads are limited to 100 MB, decompressed NBT data to
128 MB, and decoded projections to 50 million blocks. Runtime database access uses
the site's shared `../统一认证/main_database.php` entry and private
`../config/database.php` configuration.

Original project: LiteTrack, licensed under GPL-3.0. See `LICENSE`.
