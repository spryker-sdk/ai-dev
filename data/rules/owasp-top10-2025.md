---
name: owasp-top10-2025
description: Use during any code review and when writing code that handles user input, authentication, data storage, or external calls. Enforces OWASP Top 10:2025 categories as blocking issues — flag injection, access control, cryptographic, auth, and logging failures.
globs: "**/*.{php,js,ts,jsx,tsx}"
---

**Security rule**
All code MUST adhere to OWASP Top 10:2025 security standards to prevent common vulnerabilities and security risks.

Critical instructions:
- Strictly enforce all OWASP Top 10:2025 categories during code review
- Flag any security violation as a blocking issue
- Provide specific OWASP category reference in feedback (e.g., "A05:2025 - Injection")
- Prioritize security issues over style and code quality issues

## A01:2025 - Broken Access Control

Critical instructions:
- ALL endpoints and methods accessing sensitive data MUST have authorization checks
- User permissions MUST be verified before allowing access to resources
- Direct object references MUST be validated against user permissions
- Horizontal and vertical privilege escalation MUST be prevented

## A02:2025 - Security Misconfiguration

Critical instructions:
- Debug mode and stack traces MUST NOT be exposed in production
- Default credentials and unnecessary features MUST be disabled
- Security headers MUST be properly configured
- Error messages MUST NOT reveal system details

## A03:2025 - Software Supply Chain Failures

Critical instructions:
- All new dependencies MUST be reviewed for known vulnerabilities
- New Dependency versions MUST be pinned and tracked
- Untrusted sources for packages MUST NOT be used
- New Dependencies MUST be regularly updated for security patches

## A04:2025 - Cryptographic Failures

Critical instructions:
- Secrets, API keys, and credentials MUST NEVER be hardcoded
- Strong encryption algorithms MUST be used (AES-256, RSA-2048+)
- Sensitive data MUST be encrypted in transit and at rest
- Weak hashing algorithms MUST NOT be used (MD5, SHA1)

## A05:2025 - Injection

Critical instructions:
- ALL database queries MUST use parameterized statements or ORM
- User input MUST NEVER be concatenated into queries or commands
- Output MUST be properly encoded for context (HTML, JavaScript, SQL)
- Input validation MUST be performed but NOT relied upon as sole protection

## A06:2025 - Insecure Design

Critical instructions:
- Business logic MUST be validated on server-side
- Rate limiting MUST be implemented for sensitive operations
- Race conditions MUST be prevented with proper locking
- Security controls MUST be designed into architecture

## A07:2025 - Authentication Failures

Critical instructions:
- Authentication logic MUST be centralized and consistent
- Session tokens MUST be properly generated and validated
- Password policies MUST enforce strong passwords
- Account enumeration MUST be prevented

## A08:2025 - Software or Data Integrity Failures

Critical instructions:
- Deserialization of untrusted data MUST be avoided
- Updates and artifacts MUST be verified with signatures
- CI/CD pipelines MUST be secured and audited
- Data integrity checks MUST be implemented

## A09:2025 - Security Logging and Alerting Failures

Critical instructions:
- Security events MUST be logged (authentication, authorization, failures)
- Sensitive data MUST NOT be logged (passwords, tokens, credit cards)
- Logs MUST be protected from tampering
- Critical security failures MUST trigger alerts

## A10:2025 - Mishandling of Exceptional Conditions

Critical instructions:
- ALL exceptions MUST be properly caught and handled
- Error messages MUST NOT reveal sensitive system information
- Application MUST fail securely and gracefully
- Unhandled exceptions MUST be caught at top level

WHY (this part of the instruction MUST be skipped by Agent, this block is for humans only):
- OWASP Top 10 represents the most critical web application security risks
- Following these standards prevents the majority of security vulnerabilities
- Security breaches can result in data theft, financial loss, and reputation damage
- Compliance requirements (GDPR, PCI-DSS, SOC2) mandate these security controls
- Prevention is far less costly than remediation after a breach
- Reference: https://owasp.org/Top10/
