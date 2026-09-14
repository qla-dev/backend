# Container recommendation training history

The runtime prompt remains [container-recommendation.md](../../container-recommendation.md).

Each future training iteration creates a new file here:

```text
post-load/skills/
  container-recommendation.md
  container-recommendation/
    training/
      README.md
      TEMPLATE.md
      YYYY-MM-DDTHH-mm-ssZ-short-topic.md
```

Use actual UTC time and a short topic, for example `2026-09-14T15-52-59Z-training-structure.md`. Copy [TEMPLATE.md](TEMPLATE.md), fill in the source instruction and intended behavior, then document any minimal main-prompt edits and validation. Create a new record for each subsequent correction rather than replacing previous records.

These files preserve training history for maintainers. The application does not load them into Lena's prompt or show them as selectable skills. Behavior changes still require a focused update to the main prompt; a structure-only or pending record does not activate new behavior. Follow [the training workflow](../../../../AGENTS.md).
