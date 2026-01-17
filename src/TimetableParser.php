<?php
declare(strict_types=1);

namespace TyfloPodroznik;

final class TimetableParser
{
    public function parseGeneralTimetableHtml(string $html): array
    {
        if (trim($html) === '') {
            throw new \RuntimeException('Pusta odpowiedź z e‑podroznik.pl. Spróbuj ponownie.');
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $wrapped = '<!doctype html><html lang="pl"><head><meta charset="utf-8"></head><body>' . $html . '</body></html>';
        $doc->loadHTML('<?xml encoding="UTF-8">' . $wrapped);
        libxml_clear_errors();
        $xp = new \DOMXPath($doc);

        $container = $xp->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' timeTableContainer ')]")->item(0);
        if (!$container instanceof \DOMNode) {
            throw new \RuntimeException('Nie znaleziono danych rozkładu w odpowiedzi e‑podroznik.pl.');
        }

        $stopName = $this->text($xp, ".//*[contains(concat(' ', normalize-space(@class), ' '), ' ttStopInfo ')]//h2[1]", $container);
        $stopCity = $this->text($xp, ".//*[contains(concat(' ', normalize-space(@class), ' '), ' ttStopInfo ')]//h2[2]", $container);

        $currentStopId = $this->attribute($xp, ".//select[@id='stopsCombo']//option[@selected][1]", 'value', $container);
        if ($currentStopId === null || $currentStopId === '') {
            $currentStopId = $this->attribute($xp, ".//select[@id='stopsCombo']//option[contains(concat(' ', normalize-space(@class), ' '), ' stopOptionSelected ')][1]", 'value', $container);
        }
        if ($currentStopId === null) {
            $currentStopId = '';
        }

        $stopOptions = [];
        /** @var \DOMElement $opt */
        foreach ($xp->query(".//select[@id='stopsCombo']//option", $container) as $opt) {
            $id = trim((string)$opt->getAttribute('value'));
            if ($id === '') {
                continue;
            }
            $label = $this->normalizeText($opt->textContent ?? '');
            $group = '';
            $p = $opt->parentNode;
            if ($p instanceof \DOMElement && strtolower($p->tagName) === 'optgroup') {
                $group = trim((string)$p->getAttribute('label'));
            }
            $stopOptions[] = [
                'id' => $id,
                'label' => $label,
                'group' => $group,
                'selected' => $id === $currentStopId,
            ];
        }

        $destinations = [];
        /** @var \DOMElement $mark */
        foreach ($xp->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' markEven ') or contains(concat(' ', normalize-space(@class), ' '), ' markOdd ')]", $container) as $mark) {
            $destination = $this->text($xp, ".//p[contains(concat(' ', normalize-space(@class), ' '), ' cityName ')][1]", $mark);
            if ($destination === '') {
                continue;
            }

            $throughTxt = $this->text($xp, ".//div[contains(concat(' ', normalize-space(@class), ' '), ' through ')][1]", $mark);
            $through = $this->parseThrough($throughTxt);

            $departures = [];
            /** @var \DOMElement $cw */
            foreach ($xp->query(".//span[contains(concat(' ', normalize-space(@class), ' '), ' connectionWrap ')]", $mark) as $cw) {
                $departures[] = $this->parseDeparture($xp, $cw);
            }

            $destinations[] = [
                'destination' => $destination,
                'through' => $through,
                'departures' => $departures,
            ];
        }

        return [
            'stop' => [
                'name' => $stopName,
                'city' => $stopCity,
                'stopId' => $currentStopId,
            ],
            'stopOptions' => $stopOptions,
            'destinations' => $destinations,
        ];
    }

    private function parseThrough(string $text): array
    {
        $text = $this->normalizeText($text);
        if ($text === '') {
            return [];
        }
        $text = preg_replace('/^przez\\s*:\\s*/iu', '', $text) ?? $text;
        $text = trim($text);
        $text = rtrim($text, ',');
        if ($text === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', $text));
        return array_values(array_filter($parts, static fn(string $v): bool => $v !== ''));
    }

    private function parseDeparture(\DOMXPath $xp, \DOMElement $cw): array
    {
        $id = trim((string)$cw->getAttribute('id'));
        $time = $this->text($xp, ".//span[contains(concat(' ', normalize-space(@class), ' '), ' timeHour ')][1]", $cw);

        $carrierInfo = $this->text($xp, ".//span[contains(concat(' ', normalize-space(@class), ' '), ' remarks ')]//div[contains(concat(' ', normalize-space(@class), ' '), ' stick-remarks ')][1]", $cw);
        $carrier = $carrierInfo;
        if (preg_match('/^(.*?)(?:\\s*Typ\\s+przewoźnika\\s*:\\s*.+)?$/iu', $carrierInfo, $m)) {
            $maybe = trim((string)$m[1]);
            if ($maybe !== '') {
                $carrier = $maybe;
            }
        }

        $infoItems = [];
        /** @var \DOMElement $li */
        foreach ($xp->query(".//span[contains(concat(' ', normalize-space(@class), ' '), ' ttIcon ')]//li", $cw) as $li) {
            $t = $this->normalizeText($li->textContent ?? '');
            if ($t !== '') {
                $infoItems[] = $t;
            }
        }

        $validity = $infoItems[0] ?? '';
        $notes = array_slice($infoItems, 1);

        return [
            'id' => $id,
            'time' => $time,
            'carrier' => $carrier,
            'validity' => $validity,
            'notes' => $notes,
            'infoItems' => $infoItems,
        ];
    }

    private function attribute(\DOMXPath $xp, string $query, string $attr, \DOMNode $ctx): ?string
    {
        $n = $xp->query($query, $ctx)->item(0);
        if (!$n instanceof \DOMElement) {
            return null;
        }
        $v = trim((string)$n->getAttribute($attr));
        return $v !== '' ? $v : null;
    }

    private function text(\DOMXPath $xp, string $query, \DOMNode $ctx): string
    {
        $n = $xp->query($query, $ctx)->item(0);
        if (!$n instanceof \DOMNode) {
            return '';
        }
        return $this->normalizeText($n->textContent ?? '');
    }

    private function normalizeText(string $t): string
    {
        $t = preg_replace('/\\s+/u', ' ', $t) ?? $t;
        return trim($t);
    }
}
