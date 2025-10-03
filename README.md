# Seo-Audit

This scaffold provides a minimal proof-of-concept for an Automated Content Auditing module for Drupal. It:

- Scans nodes for meta tags (basic), missing image alt attributes, and broken links.
- Stores results in a custom table `seo_audit_report`.
- Provides an admin report at `/admin/content/seo-audit` and a route to run a full audit.

## Next steps

- Improve metatag detection using Metatag API.
- Add support for Lighthouse/PageSpeed integration.
- Implement nicer Views-based UI and permissions.
- Add Queue API support for very large sites.# Seo-Audit
