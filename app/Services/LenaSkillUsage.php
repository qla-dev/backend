<?php

namespace App\Services;

/**
 * The skill files a LenaAI turn uses, in the order the thinking indicator names them: the mode's own AGENT.md, then the
 * skills the turn clearly calls for. General mode is the plain fallback and is not named.
 */
class LenaSkillUsage
{
    /**
     * @param  array{instructionMode: string, hsMode: bool, canvasEnabled: bool, storageMode: bool, legalMode: bool, explicitPaymentRequest: bool}  $turn
     * @param  list<array>  $latestScans  load scans attached to the latest user message
     * @return list<string> paths relative to agents/lena
     */
    public function files(array $turn, array $latestScans, ?string $transport): array
    {
        $files = $turn['instructionMode'] === 'general' ? [] : [$turn['instructionMode'].'/AGENT.md'];
        if ($turn['instructionMode'] === 'post-load') {
            $files[] = 'post-load/skills/guided-voice.md';
        }
        $scanFoundGoods = collect($latestScans)->contains(fn (array $scan) => ! empty($scan['hsCodes']) || filled($scan['hsSearchTerms'] ?? null));
        if ($turn['hsMode'] || ($turn['canvasEnabled'] && $scanFoundGoods)) {
            $files[] = 'skills/hs-detection.md';
        }
        if ($turn['canvasEnabled'] && ! $turn['storageMode'] && in_array($transport, ['sea', 'rail'], true)) {
            $files[] = 'post-load/skills/container-recommendation.md';
        }
        if ($turn['legalMode'] && $turn['explicitPaymentRequest']) {
            $files[] = 'legal/skills/reconcile-declaration-ocp-payments.md';
        }
        if ($turn['legalMode'] && ! empty($turn['legalSkill'])) {
            $files[] = $turn['legalSkill'];
        }

        return $files;
    }
}
