# Security Policy

## Supported Versions

| Version | Supported |
| --- | --- |
| 1.1.x | Yes |
| 1.0.x | Critical security fixes only |
| Below 1.0 | No |

## Reporting a Vulnerability

**Do not report security vulnerabilities through public GitHub Issues.**

Public issue trackers are not monitored for confidential security reports. Posting vulnerability details publicly may put other users at risk.

### Preferred reporting channel

When enabled for this repository, use [GitHub Private Vulnerability Reporting](https://github.com/dbflow-labs/dbflow-core/security/advisories/new) to submit a confidential report.

### Fallback reporting channel

If private vulnerability reporting is unavailable, email:

**hello@dbflow.dev**

Suggested subject line:

```text
Security vulnerability in dbflowlabs/core
```

## Information to Include

Please include as much of the following as possible:

- Affected DBFlow Core version
- Laravel version
- PHP version
- Database platform and version
- Queue driver
- Cache driver
- Workflow definition sample when relevant
- Vulnerability description
- Reproduction steps
- Minimal reproduction repository when possible
- Potential impact
- Suggested mitigation when available

## Do Not Include Sensitive Data

Do not include the following in your report:

- Passwords
- API keys
- Webhook secrets
- Composer credentials
- Tokens
- License keys
- Personal data
- Customer data
- Production database exports
- Private application source unrelated to reproduction

Remove secrets and production data from reproduction steps, logs, screenshots, and sample payloads.

## Security-Sensitive Areas

Reports related to the following areas are especially important:

- Workflow task authorization
- Actor and assignee resolution
- Approval and rejection authorization
- Cancellation and reassignment authorization
- Delegation boundaries
- Cross-user and cross-tenant access
- Workflow state transitions
- Expression evaluation
- Workflow definition validation
- Action execution payloads
- Action retry and recovery
- Webhook SSRF and TLS enforcement
- Webhook secret resolution
- Idempotency behavior
- Audit-log integrity
- Credential and configuration exposure
- Migration and database integrity

## Responsible Disclosure

We ask reporters to allow reasonable time to investigate and address confirmed issues before public disclosure. Coordinated disclosure helps protect DBFlow users.

We do not guarantee:

- A response within a fixed number of hours
- A fix within a guaranteed number of days
- Indefinite maintenance
- A financial bounty
- Public recognition
- Support beyond current operational capacity

## Non-Security Requests

Ordinary bugs, feature requests, installation questions, and support requests should use the normal GitHub Issues or support channels for this repository.
