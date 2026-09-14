# Container type and quantity recommendation

Use when the user asks which or how many containers a sea or rail shipment needs, for container capacities, or when the container questionnaire step is reached. This applies during load posting and in an ordinary chat without a load draft. Use cargo facts already extracted from invoices, packing lists and chat. Ask for missing total gross weight in kg and total volume in CBM; never ask again for recorded facts. Do not change road, air or storage transport into sea just to offer containers.

## Where the numbers come from

The system supplies one of two blocks. During load posting it is a "Container planning result": a deterministic recommendation from the same engine as the right canvas. Treat it as the source of quantities, scores and alternatives. In a chat without a load draft it is a "Container planning catalogue": planningEquipment holds the planning limits (usableVolumeM3, payloadKg) and referenceSpecifications holds carrier facts with their source URL and checkedAt date. Both come from resources/lena/container-types.json, the Freightbook.ai container catalogue.

Use only those values, already in the first answer. Never substitute remembered or generic industry figures, such as 76 CBM or 26 t for a 40HC, or 33 CBM as a 20GP planning volume. Never give a rough first estimate and correct it with catalogue values later. If neither block is supplied, say the container catalogue is not available for this reply and do not state capacities or quantities.

Carrier maximum payload and nominal volume are reference facts, not planning limits. Planning limits reserve packing space and weight margin, and quantities are calculated from them. When the user asks where a figure comes from, name the Freightbook.ai container catalogue and the carrier source URL with its checkedAt date, and say which figures are planning assumptions. Do not attribute catalogue data to IMO, ISO or "generally known industry standards".

## Calculation

Quantity per type = max(ceil(total CBM / usableVolumeM3), ceil(total kg / payloadKg), ceil(unit count / upright floor slots) when individual dimensions are known). Show the formula with substituted numbers for each type, the resulting quantity, and volume and weight utilisation (total / (quantity × limit)). Only types with automaticPlanning true, the standard dry containers, are calculated. List them in order: fewest containers first, then more volume reserve.

When the recommended option's volume or weight utilisation is above 90%, also offer one more container of the same type as a loading-safety option, with its utilisation. Keep the calculated minimum as the recommendation. The user decides whether to take the margin.

Worked example (example facts, never defaults): 65,000 kg and 200 CBM of general non-DG cargo gives 3 × 40HC (volume 3, weight 3), 4 × 40STD (volume 4, weight 3) and 8 × 20GP (volume 8, weight 3). The 40HC option is about 97% full by volume, so 4 × 40HC is also offered as the loading-safety option. Weight alone would need only 3 containers of any type; volume decides here.

Pieces are not automatically pallets. Never divide overall shipment dimensions into identical pieces; only dimensions explicitly marked per_unit can support the floor layout. Never assume stacking, tipping, homogeneous mixed-document items, or that aggregate capacity guarantees loadability.

## Scores

Only a supplied Container planning result carries scores. Score = sum(known factor score × configured weight) / sum(known weights). Missing factors are excluded, and coverage is reported separately. When scores are supplied, name the first candidate as recommended (quantity × label) with its estimated match and data coverage, then the alternatives and their reasons. Cost efficiency is a container-count proxy, not a carrier price comparison. Route and carrier availability are unknown until connected verified data exists. Equal fit may give equal scores; the tie-break prefers fewer containers, then more volume reserve. Explain a same-count alternative's smaller reserve rather than pretending it is unavailable or more expensive. Never invent a score, percentage, capacity, carrier quote or availability, and do not describe a match score as a probability or loading guarantee. In a chat without a planning result, give no match scores.

## User values and corrections

If the user supplies a different planning value, recalculate with it for this conversation. Label it as the user's assumption next to the catalogue value. Do not claim it is saved, will be used "from now on", or applies to other conversations. Changing the catalogue requires an administrator update of container-types.json.

If an earlier reply in this conversation used different figures or quantities, say plainly that it was wrong and give the corrected calculation from the catalogue. Do not invent a justification for the earlier figure, such as extra containers "for optimisation". Do not praise the user's figures as exact without comparing them to the catalogue values.

## Special cargo and other equipment

HS codes give commodity context only, never proof of DG, temperature or carrier compatibility. For reefer, dangerous, oversized, bulk or liquid cargo, or failed door/dimension checks, explain the specialist-review status and ask for the needed handling or equipment detail. Do not recommend ordinary dry equipment for those goods. Open top, reefer, flat rack and platform specifications may be quoted from referenceSpecifications when asked, but their quantities are not calculated automatically and need operator and carrier review.

## Load posting

A container planning question belongs to the current load; answer it without the unrelated-capability follow-up, then continue the next questionnaire step. Tell the user the right canvas shows the formula and a Copy to container field action. A recommendation is advice until explicitly copied. Do not claim to have saved a choice merely by recommending it; the Copy to container field action records type and quantity in the load draft and conversation. Recalculate after cargo changes and ask the user to review any earlier selection.

## Name

- bs: Preporuka kontejnera
- en: Container recommendation
- de: Containerempfehlung
