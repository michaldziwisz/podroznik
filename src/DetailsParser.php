<?php
declare(strict_types=1);

namespace TyfloPodroznik;

final class DetailsParser
{
    public function parseExtendedHtml(string $html): array
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

        $title = $this->text($xp, "//div[contains(@class,'details-container-header')]//span[contains(@class,'title')]");

        $hints = [];
        foreach ($xp->query("//div[contains(@class,'hint-item')]//div[contains(@class,'hint-content')]") as $n) {
            $h = $this->normalizeText($n->textContent ?? '');
            if ($h !== '') {
                $hints[] = $h;
            }
        }

        $segments = [];
        /** @var \DOMElement $seg */
        foreach ($xp->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' stick-details ')]") as $seg) {
            $segments[] = $this->parseSegment($xp, $seg);
        }

        return [
            'title' => $title !== '' ? $title : 'Szczegóły trasy',
            'hints' => $hints,
            'segments' => $segments,
        ];
    }

    private function parseSegment(\DOMXPath $xp, \DOMElement $seg): array
    {
        $carrier = $this->text($xp, ".//a[contains(@class,'carrier-name')]", $seg);
        $line = $this->text($xp, ".//*[contains(@class,'transportation-type')][1]", $seg);
        $duration = $this->text($xp, ".//div[contains(@class,'summary')]//span[contains(@class,'time-duration')]", $seg);

        $remarks = [];
        foreach ($xp->query(".//div[contains(@class,'stick-remarks')]//li", $seg) as $li) {
            $t = $this->normalizeText($li->textContent ?? '');
            if ($t !== '') {
                $remarks[] = $t;
            }
        }

        $stops = [];
        foreach ($xp->query(".//div[contains(@class,'route-details-container') and contains(@class,'preceding-stops')]//div[contains(@class,'stop-item')]", $seg) as $n) {
            $stop = $this->parseStopLikeNode($xp, $n);
            if ($stop !== null) {
                $stops[] = $stop;
            }
        }
        foreach ($xp->query(".//div[contains(@class,'route-details-container') and contains(@class,'firstStop')]", $seg) as $n) {
            $stop = $this->parseStopLikeNode($xp, $n);
            if ($stop !== null) {
                $stops[] = $stop;
            }
        }
        foreach ($xp->query(".//div[contains(@class,'route-details-container') and contains(@class,'intermediateStops')]//div[contains(@class,'stop-item')]", $seg) as $n) {
            $stop = $this->parseStopLikeNode($xp, $n);
            if ($stop !== null) {
                $stops[] = $stop;
            }
        }
        foreach ($xp->query(".//div[contains(@class,'route-details-container') and contains(@class,'lastStop')]", $seg) as $n) {
            $stop = $this->parseStopLikeNode($xp, $n);
            if ($stop !== null) {
                $stops[] = $stop;
            }
        }

        return [
            'carrier' => $carrier,
            'line' => $line,
            'duration' => $duration,
            'remarks' => $remarks,
            'stops' => $stops,
        ];
    }

    private function parseStopLikeNode(\DOMXPath $xp, \DOMNode $node): ?array
    {
        $name = $this->text($xp, ".//span[contains(@class,'stop-name')][1]", $node);
        if ($name === '') {
            return null;
        }
        $routeTime = $this->text($xp, ".//span[contains(@class,'route-time')][1]", $node);
        $arrival = $this->text($xp, ".//span[contains(@class,'arrival')][1]", $node);
        $departure = $this->text($xp, ".//span[contains(@class,'departure')][1]", $node);

        $day = $this->text($xp, ".//span[contains(@class,'route-day')][1]", $node);
        return [
            'name' => $name,
            'routeTime' => $routeTime,
            'arrival' => $arrival,
            'departure' => $departure,
            'day' => $day,
        ];
    }

    private function text(\DOMXPath $xp, string $query, ?\DOMNode $ctx = null): string
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
