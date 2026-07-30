# LiteTrack PHP edition

This directory is a PHP 8.4 reimplementation of LiteTrack for the existing site.

- Projects, descriptions and lifecycle status
- Multiple `.litematic` files per project
- Server-side gzip/NBT parsing without Node.js
- Material totals in blocks, stacks and shulker boxes
- Project members and owner/admin/member roles
- Claims, cancellation and collected state
- Per-user claim dashboard
- Existing site member identities, sessions, CSRF protection and audit logs
- MySQL runtime schema initialization

Uploaded projection files are validated, parsed from PHP's temporary upload file and
not retained. The database connection is configured in `config.php`; account data
continues to use the 经纬度 module's private production configuration.

Original project: LiteTrack, licensed under GPL-3.0. See `LICENSE`.
