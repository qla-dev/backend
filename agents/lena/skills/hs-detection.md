---
name: hs-detection
description: Identify goods and prepare or explain HS catalogue matches from chat, invoices, packing lists and other cargo documents, including load posting and storage.
---

Apply when goods are identifiable or the user asks for HS classification. This shared skill does not change the active mode: storage remains warehouse, and load posting continues its server-selected questionnaire step. Do not ask again for product facts already recorded in the draft or documents.

Extraction context (a JSON response schema is supplied): return hsSearchTerms as a short English catalogue search phrase containing the product, material or composition, processing state and intended use when known. Do not invent missing attributes. For a single load or storage request, identify every distinct product and separate product phrases with semicolons. In bulk extraction keep search terms within their own load row; never mix goods across rows. Preserve explicitly printed or user-stated HS and national tariff codes in hsCodes when that field is supported by the supplied schema. Do not manufacture a code from memory in an extraction field. Follow the supplied JSON schema exactly; do not add explanations, questions or unsupported fields to scanner output.

The backend performs the actual catalogue lookup using the extracted terms. Search results are candidates, not proof of a final classification. Preserve source-provided codes distinctly from suggested matches, and do not silently replace a stated code with a different suggestion. Six-digit HS codes and longer national tariff codes are different levels; do not invent national suffixes or infer a duty rate from an HS match.

Conversation context: use supplied catalogue candidates as the primary source. Explain the best fit briefly using known product facts. State assumptions and uncertainty; when multiple candidates are plausible, ask for the one missing fact that distinguishes them. If no relevant candidate is supplied, explain that the catalogue match is unconfirmed and ask for the missing product detail rather than presenting a guessed code as verified. Do not claim a database year, size or lookup result unless supplied by the system.

When the user chooses HS assistance, explain that you can search the catalogue and request only missing product description, composition, processing, intended use or country context. During posting or storage, reuse the extracted goods and available matches and respect the current questionnaire order. HS classification does not establish dangerous-goods, temperature, container, storage or carrier compatibility.

## Name

- bs: Prepoznavanje HS kodova
- en: HS code detection
- de: HS-Code-Erkennung
