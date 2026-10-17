# UniPAY skills for AI coding assistants

Teach your AI coding assistant to integrate [UniPAY](https://unipay.com) payments correctly:
authentication, creating orders and redirecting to the payment page, verifying callbacks,
order status, refunds, preauthorization, saved cards and subscriptions.

The skill follows the open [Agent Skills](https://agentskills.io) format, so it works with
Claude Code, Cursor, OpenAI Codex, GitHub Copilot, Gemini CLI and other assistants that
support skills.

## Install

**Any assistant** (needs Node.js):

```bash
npx skills add unipay/AI_Skills
```

**Claude Code plugin:**

```
/plugin marketplace add unipay/AI_Skills
/plugin install unipay@unipay
```

**Manually:** copy the `skills/unipay-integration` folder into your assistant's skills folder,
for example `~/.claude/skills/` (all projects) or `.claude/skills/` (one project) for
Claude Code.

## Use

Ask your assistant as you normally would. The skill loads when the task involves UniPAY:

- "Add UniPAY checkout to our Laravel shop"
- "Handle UniPAY payment callbacks in our Express API"
- "Let customers save a card with UniPAY and charge it monthly"
- "Why does our UniPAY callback hash never match?"

## What's inside

```
skills/unipay-integration/
├── SKILL.md                    # the integration flow and the rules that are easy to get wrong
├── references/                 # loaded only when needed
│   ├── authentication.md
│   ├── create-order.md
│   ├── callbacks.md
│   ├── statuses-and-errors.md
│   ├── refunds.md
│   ├── preauth.md
│   ├── saved-cards-and-subscriptions.md
│   └── v1-legacy.md
└── examples/                   # minimal clients: token cache, create order, callback check, refund
    ├── php/unipay.php
    ├── node/unipay.mjs
    └── python/unipay.py
```

## Before you start

You need a verified business account and a project at https://business.unipay.com, and the
project's API credentials (merchant ID and secret key). API reference: https://docs.unipay.com.
Support: https://support.unipay.com.

## Contributing

The skill must match how the UniPAY API actually behaves. If you find a difference, open an
issue with the request, the response and what you expected.

## License

[MIT](LICENSE)
