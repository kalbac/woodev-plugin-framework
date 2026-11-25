# Gotcha: [tooling/orca] — the first Orca bot on a computer is made in the UI; rooms and effort are UI-only
> Tags: orca, bots, cli | Session: s245

`orca bot create --offer` refuses outside a bot's chat («Run this from a bot's chat.», Orca 1.4.274, measured 25.11.2026). On a
computer with no bot yet the CLI therefore cannot make one. There is also no CLI for a group room or for a bot's effort level
(`agent-context` lists no `bot group*` command; `bot.json` is Orca-owned and rewritten).

❌ **Wrong:** planning a fully scripted setup — `orca bot create --offer --name Framework …` from a coordinator shell.

✅ **Correct:**

1. The owner creates the FIRST bot in Orca's UI (agent, model).
2. Write its `~/.orca/bots/<name>/SOUL.md` and `MEMORY.md` directly (the SOUL's first paragraph becomes the «For:» line).
3. Mail that bot to run `orca bot create --offer --name <next> --purpose "…"`; the owner presses Create bot on the card.
4. Group room: sidebar → «New bot or group» → «New group» → «Who answers your messages». Effort: the bot's row → «Bot settings…» →
   «Agent and model», or the effort pill in its Home Chat (a choice there is written back to the bot).
5. Give a bot its repos through lineage, as the Homelab bots have them:
   `orca worktree set --worktree <sel> --parent-worktree folder:<bot home id>` (another computer:
   `--environment <server> … --parent-environment local`).

## Related

- [bot mode](../wiki/bot-mode.md)
- [orchestrating agents with Orca](../wiki/orchestrating-agents-with-orca.md)
