# Security policy

## Supported versions

The latest release receives security fixes. Before 1.0.0 that is the latest minor version.

## Reporting a vulnerability

Email **hello@indianopinion.org** with "spend-meter security" in the subject. Please include the version, what you found, how to reproduce it, and the impact you expect. Do not open a public issue for a security problem.

We acknowledge reports within 3 working days and aim to send a first assessment within 10 working days. We will tell you when a fix is released and credit you in the release notes if you wish.

## Scope

spend-meter holds no API keys and makes no outbound requests. The filter contract is a guard against mistakes, not a security boundary against hostile code already running in the same WordPress install. Reports about the admin screens, the widget, WP-CLI input handling, SQL handling, and anything that lets a user without the configured capability read the ledger or change caps, kill switches or prices are in scope.
