<?php
declare(strict_types=1);

namespace TyfloPodroznik;

final class ResultsParser
{
    public function parseResultsPageHtml(string $html): array
    {
        if (trim($html) === '') {
            throw new \RuntimeException('Pusta odpowiedź z e‑podroznik.pl. Spróbuj ponownie.');
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $xp = new \DOMXPath($doc);

        $results = [];
        /** @var \DOMElement $node */
        foreach ($xp->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' searching-result ')]") as $node) {
            $results[] = $this->parseResultNode($xp, $node);
        }

        $extendBackUrl = $this->firstHref($xp, "//a[contains(concat(' ', normalize-space(@class), ' '), ' btnSearchForEarlier ')]");
        $extendForwardUrl = $this->firstHref($xp, "//a[contains(concat(' ', normalize-space(@class), ' '), ' btnSearchForLater ')]");

        $searchTitle = $this->text($xp, "//title");
        $h1 = $this->text($xp, "//*[self::h1 or self::h2][1]");

        return [
            'title' => $h1 !== '' ? $h1 : ($searchTitle !== '' ? $searchTitle : 'Wyniki wyszukiwania'),
            'count' => count($results),
            'results' => $results,
            'extendBackUrl' => $extendBackUrl,
            'extendForwardUrl' => $extendForwardUrl,
        ];
    }

    private function parseResultNode(\DOMXPath $xp, \DOMElement $node): array
    {
        $resId = $node->getAttribute('data-resId') ?: $node->getAttribute('data-resid');
        $sellable = strtolower($node->getAttribute('data-sellable')) === 'true';
        $connectionsCount = (int)($node->getAttribute('data-connectionscount') ?: '0');
        $durationSort = (int)($node->getAttribute('data-sortbytime') ?: '0');
        $changesSort = (int)($node->getAttribute('data-sortbychanges') ?: '0');
        $depSort = (int)($node->getAttribute('data-sortbydep') ?: '0');
        $arrSort = (int)($node->getAttribute('data-sortbyarr') ?: '0');

        $depStop = $this->text($xp, ".//span[contains(@class,'edge-stops-names')]/span[contains(@class,'departure')]", $node);
        $arrStop = $this->text($xp, ".//span[contains(@class,'edge-stops-names')]/span[contains(@class,'arrival')]", $node);

        $depTime = $this->text($xp, ".//span[contains(@class,'edge-date-time')]//span[contains(@class,'departure')]//span[contains(@class,'time')]", $node);
        $arrTime = $this->text($xp, ".//span[contains(@class,'edge-date-time')]//span[contains(@class,'arrival')]//span[contains(@class,'time')]", $node);

        $depDate = $this->text($xp, ".//span[contains(@class,'edge-date-time')]//span[contains(@class,'departure')]//span[contains(@class,'date')]", $node);
        $arrDate = $this->text($xp, ".//span[contains(@class,'edge-date-time')]//span[contains(@class,'arrival')]//span[contains(@class,'date')]", $node);

        $duration = $this->text($xp, ".//div[contains(@class,'journey-time')]//span[contains(@class,'time-duration')]", $node);
        $buyHref = $this->firstHref($xp, ".//a[contains(concat(' ', normalize-space(@class), ' '), ' btnBuyTicket ')]", $node);

        return [
            'resId' => $resId,
            'sellable' => $sellable,
            'connectionsCount' => $connectionsCount,
            'sort' => [
                'dep' => $depSort,
                'arr' => $arrSort,
                'time' => $durationSort,
                'changes' => $changesSort,
            ],
            'from' => [
                'stop' => $depStop,
                'time' => $depTime,
                'date' => $depDate,
            ],
            'to' => [
                'stop' => $arrStop,
                'time' => $arrTime,
                'date' => $arrDate,
            ],
            'duration' => $duration,
            'buyHref' => $buyHref,
        ];
    }

    private function firstHref(\DOMXPath $xp, string $query, ?\DOMNode $ctx = null): ?string
    {
        $n = $xp->query($query, $ctx)->item(0);
        if (!$n instanceof \DOMElement) {
            return null;
        }
        $href = trim((string)$n->getAttribute('href'));
        return $href !== '' ? $href : null;
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
