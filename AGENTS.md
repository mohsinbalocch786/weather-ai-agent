# Agent instructions

The project guide for contributors and AI coding assistants is in [CLAUDE.md](CLAUDE.md): commands, architecture, rules and known gotchas.

Short version:

- Run `composer test` and `composer test:js` before finishing; both must pass without Ollama or internet.
- Do not change the Ollama block in `config/ai.php`, and do not add dependencies without a strong reason.
- Check `vendor/laravel/ai` instead of assuming SDK methods exist.
- Mock external HTTP in tests; keep controllers thin and logic in `app/Services`.
