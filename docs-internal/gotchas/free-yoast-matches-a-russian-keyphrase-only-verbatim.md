# Gotcha: [tooling/content] — free Yoast matches a Russian keyphrase only verbatim, and only its own transition-word list counts

> Tags: yoast, seo, woodev-ru, russian, readability | Session: s185

## What happens

woodev.ru runs the free Yoast SEO, which has no Russian morphology. Two traps hit the s185 shipping articles:

- The focus keyphrase «оформление заказа пункт выдачи» never occurred in that exact form, so Yoast found it 0 times and turned
  the whole keyphrase block red: intro, density, meta description, subheadings. The text was fine. The keyphrase was not a phrase
  the text could contain.
- A writer counted transition words with its own list and reported 36 %. Yoast's panel said 19.3 %. Only the list Yoast ships
  for Russian counts.

## ✅ Correct

- Pick a 2–4-word keyphrase that the text naturally uses in one form («пункт выдачи», «заказы доставки», «письма о доставке»).
  Put it verbatim in the first paragraph, at the start of the SEO title, in the meta description (≤ 155 characters), in an h2/h3,
  in one image alt, and about once per 100–150 words.
- Count transition words with `yoast/wordpress-seo` →
  `packages/yoastseo/src/languageProcessing/languages/ru/config/transitionWords.js`, target ≥ 30 % of sentences. Every connector
  must be logically true. Never drop a wording the operator mandated just to hit a number (a rewrite once dropped the licence clause).
- Expect «keyphrase in slug» to stay orange: English slugs are the operator's choice (#1342).
- The agent's numbers are estimates. The operator reads the real panel.

## Related

- [../wiki/orchestrating-agents-with-orca.md](../wiki/orchestrating-agents-with-orca.md) — «Workers in woodev-ru on Homelab»: slug/Yoast abilities, media import
- [../gotcha-index/tooling.md](../gotcha-index/tooling.md) — tooling index
