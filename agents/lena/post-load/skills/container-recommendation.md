# Container type and quantity recommendation

Use during sea or rail load posting when the user asks for container planning, or the container questionnaire step is reached. Use cargo facts already extracted from invoices, packing lists and chat. Ask for missing total gross weight in kg and total volume in CBM; never ask again for recorded facts. Do not change road, air or storage transport into sea just to offer containers.

The system supplies a deterministic container recommendation JSON calculated from the same engine as the right canvas. Treat it as the source of quantities, scores and alternatives; do not invent a 97% score, capacity, carrier quote or availability. When available, name the first candidate as recommended (quantity × label), its estimated match and data coverage, then the alternatives and their reasons. A container planning question belongs to the current load; answer it without the unrelated-capability follow-up. Continue the existing next questionnaire step afterward.

Quantity = max(ceil(total CBM / usable CBM), ceil(total kg / planning payload kg), ceil(unit count / upright floor slots) when individual dimensions are known). The resources/lena/container-types.json catalogue owns container codes, categories, icons, carrier specifications and separate operational planning assumptions. The main Lena schema composes its container choices from that file. Each type has sourced specifications or an aliasOf reference; planning defines scoring weights and enabled dry-container limits. Carrier maximum payload and nominal volume are reference facts, not the planning limits. Special equipment specifications require operator review and do not automatically enable dry-container scoring. Use the supplied calculation values rather than maintaining another capacity list in these instructions. Only registered standard types with specifications participate; missing specifications are not guessed. Pieces are not automatically pallets. Never divide overall shipment dimensions into identical pieces; only dimensions explicitly marked per_unit can support the floor layout. Never assume stacking, tipping, homogeneous mixed-document items, or that aggregate capacity guarantees loadability.

Score = sum(known factor score × configured weight) / sum(known weights). Missing factors are excluded, and coverage is reported separately. Cost efficiency is explicitly a container-count proxy, not a carrier price comparison. Route and carrier availability are unknown until connected verified data exists. Equal fit may have equal scores: the current tie-break prefers fewer containers, then more volume reserve. Explain a same-count alternative's smaller reserve rather than pretending it is unavailable or more expensive. Do not describe the match score as a probability or loading guarantee.

HS codes provide commodity context only, never proof of DG, temperature or carrier compatibility. For reefer, dangerous, oversized, bulk or liquid cargo, or failed door/dimension checks, explain the specialist-review status and ask for the needed handling or equipment detail. The current dry-container engine must not recommend ordinary dry equipment for those goods.

Tell the user the right canvas shows the formula and a Copy to container field action. A recommendation is advice until explicitly copied. Do not claim to have saved a choice merely by recommending it; the Copy to container field action records type and quantity in the load draft and conversation. Recalculate after cargo changes and ask the user to review any earlier selection.

## Name

- bs: Preporuka kontejnera
- en: Container recommendation
- de: Containerempfehlung
