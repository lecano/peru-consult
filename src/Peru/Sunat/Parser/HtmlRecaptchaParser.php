<?php

declare(strict_types=1);

namespace Peru\Sunat\Parser;

use DOMNode;
use DOMNodeList;
use DOMXPath;
use Generator;
use Peru\Sunat\HtmlParserInterface;

class HtmlRecaptchaParser implements HtmlParserInterface
{
    /**
     * Parse html to dictionary.
     *
     * @param string $html
     *
     * @return array|false
     */
    public function parse(string $html)
    {
        $xp = XpathLoader::getXpathFromHtml($html);

        // Procesar las tablas con claves en el encabezado
        $dic = [];

        // Procesar tablas con claves en el encabezado
        $tables = $xp->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' table-responsive ')]");
        foreach ($tables as $table) {
            $dic = array_merge($dic, $this->parseTable($xp, $table));
        }

        // Procesar tablas con claves en el lado
        $tableNodes = $xp->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' list-group ')]");
        if (0 == $tableNodes->length) {
            return $dic;
        }

        foreach ($tableNodes as $tableNode) {
            $nodes = $tableNode->childNodes;
            $dic = array_merge($dic, $this->getKeyValues($nodes, $xp));
        }

        return $dic;
    }

    private function parseTable(DOMXPath $xp, DOMNode $tableNode): array
    {
        $dic = [];

        $headers = $xp->query(".//thead/tr/th", $tableNode);
        $rows = $xp->query(".//tbody/tr", $tableNode);

        foreach ($rows as $row) {
            $cells = $xp->query(".//td", $row);

            if ($headers->length > 0 && $cells->length > 0) {
                $key = trim($headers->item(0)->textContent);
                $values = [];

                foreach ($cells as $cell) {
                    $values[] = trim($cell->textContent);
                }

                // Aquí asumimos que cada fila corresponde a un período o categoría
                // Ajusta la lógica según la estructura específica de tus datos
                $dic[$key][] = $values;
            }
        }

        return $dic;
    }

    private function getKeyValues(DOMNodeList $nodes, DOMXPath $xp): array
    {
        $dic = [];
        foreach ($nodes as $item) {
            /** @var $item DOMNode */
            if ($this->isNotElement($item)) {
                continue;
            }

            $this->setKeyValuesFromNode($xp, $item, $dic);
        }

        return $dic;
    }

    private function setKeyValuesFromNode(DOMXPath $xp, DOMNode $item, &$dic)
    {
        $keys = $xp->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' list-group-item-heading ')]", $item);
        $values = $xp->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' list-group-item-text ')]", $item);

        $isHeadRow = $values->length === 0 && $keys->length === 2;
        if ($isHeadRow) {
            $title = trim($keys->item(0)->textContent);
            $dic[$title] = trim($keys->item(1)->textContent);

            return;
        }

        for ($i = 0; $i < $keys->length; $i++) {
            $title = trim($keys->item($i)->textContent);

            if ($values->length > $i) {
                $dic[$title] = trim($values->item($i)->textContent);
            } else {
                $dic[$title] = iterator_to_array($this->getValuesFromTable($xp, $item));
            }
        }
    }

    private function getValuesFromTable(DOMXPath $xp, DOMNode $item): Generator
    {
        $rows = $xp->query('.//table/tbody/tr/td', $item);

        foreach ($rows as $item) {
            /** @var $item DOMNode */
            yield trim($item->textContent);
        }
    }

    private function isNotElement(DOMNode $node)
    {
        return XML_ELEMENT_NODE !== $node->nodeType;
    }
}
