# Lena catalog

`GET /api/lena/catalog` supplies the web and mobile clients with the same versioned catalog. It is public and contains no user or load data. It is the single definition of what a load is made of: the Post a load form and the LenaAI guided questionnaire both read it, neither keeps its own copy.

- `schema.json` owns step order, stable option values, transport-specific option groups, per-field pickers, form-field mappings, selection type, input masks/units, the glyph each option is drawn with (`option_icons`) and the container equipment categories.
- `lang/en/lena.php`, `lang/de/lena.php`, and `lang/bs/lena.php` own greetings, question templates, confirmations, completion text, field labels, option translations, per-option descriptions (`option_descriptions`) and one worked example per step (`examples`).
- `LenaLoadQuestionnaire` selects the next applicable step. `LenaGuidedAnswerResponder` supplies its localized question, which names the step's example. `LenaCatalog` exposes those same questions, labels, examples, pickers and glyphs to both clients.
- The web form retains its specialized controls and layout. Its text, option lists, placeholders and icons are read from this catalog. Clients keep presentation code (how a card is drawn, numeric/date formatting, navigation) and the name-to-component icon registry (`lenaIcons.ts`), not assistant wording or option data.
- Draft conversations accept `greeting: draft | warehouse_transport | last_mile` and `lang`. The controller authors the assistant greeting. Legacy `initial_message` input is accepted for compatibility but is not used to author assistant messages.

## Keeping the two in step

Four rules hold the form and the questionnaire together. Each is covered by a test in `tests/Unit/LenaCatalogTest.php` or `tests/Unit/LenaLoadQuestionnaireTest.php`.

1. **Coverage** - every field the Post a load draft collects belongs to exactly one step, for every transport type it applies to. `steps.<key>.transports` says which types ask it; `form_fields` maps each draft field to its step.
2. **Order** - `steps` is ordered the way the form asks: its header (the title), then the Cargo step top to bottom, then Route, then Contact. Moving a field in the form means moving its step here.
3. **Answer type** - a field the form offers as a card grid, radio row or dropdown has `options: true` and a group of values; a field that is typed into stays free text with a `mask` where one applies. Multi-select in the form means `multiple: true` here. `dynamic` marks the two lists only a client can fetch (the account's warehouses, its customers); `searchable` marks a list long enough to need filtering.
4. **Glyphs** - `option_icons` names the icon for every option value, so a transport type is the same truck, plane, ship, train or warehouse on a form card, a chat pill and on mobile.

Change canonical option values only with a corresponding review of draft parsing, validation and existing saved records. Localize labels without changing values. Maintain only en, de and bs.

Deploy the backend catalog endpoint before the updated clients. Web and mobile fetch the catalog at startup and retain the last successfully fetched catalog for offline startup. With no valid catalog, startup shows retry instead of inventing local assistant text. Changes are picked up on the next application startup. `revision` identifies content changes; incompatible response shapes require a new `version` and client support.
