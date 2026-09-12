# Lena catalog

`GET /api/lena/catalog` supplies the web and mobile clients with the same versioned catalog. It is public and contains no user or load data.

- `schema.json` owns step order, stable option values, transport-specific option groups, form-field mappings, selection type, and input masks/units.
- `lang/en/lena.php`, `lang/de/lena.php`, and `lang/bs/lena.php` own greetings, question templates, confirmations, completion text, field labels, and option translations.
- `LenaLoadQuestionnaire` selects the next applicable step. `LenaGuidedAnswerResponder` supplies its localized question. `LenaCatalog` exposes those same questions and form labels to both clients.
- The web form retains its specialized controls and layout. Its text and option lists are read from this catalog. Clients keep presentation code (icons, numeric/date formatting, navigation), not assistant wording.
- Draft conversations accept `greeting: draft | warehouse_transport | last_mile` and `lang`. The controller authors the assistant greeting. Legacy `initial_message` input is accepted for compatibility but is not used to author assistant messages.

Change canonical option values only with a corresponding review of draft parsing, validation and existing saved records. Localize labels without changing values. Maintain only en, de and bs.

Deploy the backend catalog endpoint before the updated clients. Web and mobile fetch the catalog at startup and retain the last successfully fetched catalog for offline startup. With no valid catalog, startup shows retry instead of inventing local assistant text. Changes are picked up on the next application startup. `revision` identifies content changes; incompatible response shapes require a new `version` and client support.
