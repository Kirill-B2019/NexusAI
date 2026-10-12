# Project instructions

## Target frontend architecture

- The planned user interface is Laravel 12 with Blade, Livewire, and Alpine.js. This is a target architecture; do not imply that a Laravel application already exists.
- Do not introduce React, Vue, or a separate SPA unless the user explicitly decides to change this direction.
- TypeScript is allowed only for isolated browser-side logic for the SSE chat, using `fetch` and `ReadableStream`.
- Laravel owns user accounts, sessions, roles, authorization, and the server-side proxy to the FastAPI NEXUS AI service. Keep NEXUS AI a separate API-first FastAPI service; make Laravel backend changes only for the integrations the UI needs.
- Never expose NEXUS AI API keys or other secrets to the browser.
- Proxy SSE streams without buffering and configure suitable upstream, server, and proxy timeouts for long-running responses.
- Verify API contracts against the actual FastAPI routers before implementing integrations; written documentation may be out of date.
- Follow the shared UI Kit, Laravel code-organization, accessibility, and testing standards in `docs/UI.md`; reuse kit components and extend them instead of creating one-off UI patterns.
- Preserve local `.env` files and their secrets; change them only when required for the requested behavior, never commit them.

## Implementation and learning

- Build the frontend in small vertical increments, following Laravel conventions and patterns.
- Explain important implementation decisions and, when useful, briefly explain the concepts being learned.
- Preserve existing project rules and conventions that do not conflict with these decisions.
