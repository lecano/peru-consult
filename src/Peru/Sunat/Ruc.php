<?php

/**
 * Created by PhpStorm.
 * User: Administrador
 * Date: 15/11/2017
 * Time: 04:15 PM.
 */

namespace Peru\Sunat;

use Peru\Http\ClientInterface;
use Peru\Services\RucInterface;

/**
 * Class Ruc.
 */
class Ruc implements RucInterface
{
    use RandomTrait;

    /**
     * @var ClientInterface
     */
    public $client;
    /**
     * @var RucParser
     */
    private $parser;

    /**
     * Ruc constructor.
     *
     * @param ClientInterface $client
     * @param RucParser       $parser
     */
    public function __construct(ClientInterface $client, RucParser $parser)
    {
        $this->client = $client;
        $this->parser = $parser;
    }

    /**
     * Get Company Information by RUC.
     *
     * @param string $ruc
     *
     * @return null|Company
     */
    public function get(string $ruc): ?Company
    {
        $this->client->get(Endpoints::CONSULT);
        $htmlRandom = $this->client->post(Endpoints::CONSULT, [
            'accion' => 'consPorRazonSoc',
            'razSoc' => 'BVA FOODS',
        ]);

        $random = $this->getRandom($htmlRandom);

        $html = $this->client->post(Endpoints::CONSULT, [
            'accion' => 'consPorRuc',
            'nroRuc' => $ruc,
            'numRnd' => $random,
            'actReturn' => '1',
            'modo' => '1',
        ]);

        if ($html === false) {
            return null;
        }

        if (strpos($html, 'Tipo Contribuyente') === false) {
            return null;
            //throw new \Exception('RUC no encontrado o no válido');
        }

        // Obtener datos adicionales
        $data = $this->getAdditionalData($ruc);
        if ($data === false) {
            return null;
        }

        $dom = new \DOMDocument();
        @$dom->loadHTML(utf8_encode($html));
        //@$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $body = $dom->getElementsByTagName('body')->item(0);

        /*
        var_dump($dom->saveHTML());
        exit();
        */

        if ($body) {
            $extraDom = new \DOMDocument();
            @$extraDom->loadHTML($data);
            $extraBody = $extraDom->getElementsByTagName('body')->item(0);

            if ($extraBody) {
                foreach ($extraBody->childNodes as $node) {
                    $body->appendChild($dom->importNode($node, true));
                }
            }
        }

        $combinedHtml = $dom->saveHTML();
        return $this->parser->parse($combinedHtml);

        //return $html === false ? null : $this->parser->parse($html);
    }


    /**
     * Obtener datos adicionales.
     *
     * @param string $ruc
     * @return string|false
     */
    private function getAdditionalData(string $ruc)
    {
        $data = [
            'accion' => 'getCantTrab',
            'nroRuc' => $ruc,
            'contexto' => 'ti-it',
            'modo' => '1',
        ];
        $htmlCantTrab = $this->client->post(Endpoints::CONSULT, http_build_query($data), [
            'Content-Type' => 'application/x-www-form-urlencoded'
        ]);

        if ($htmlCantTrab === false) {
            return false;
        }

        $dom = new \DOMDocument();
        @$dom->loadHTML($htmlCantTrab);
        $xpath = new \DOMXPath($dom);
        $elements = $xpath->query("//div[contains(@class, 'table-responsive')]");

        $extraHtml = '';
        foreach ($elements as $element) {
            $element->setAttribute('class', $element->getAttribute('class') . ' trabajadores');
            $extraHtml .= $dom->saveHTML($element);
        }

        $data = [
            'accion' => 'getRepLeg',
            'contexto' => 'ti-it',
            'modo' => '1',
            'nroRuc' => $ruc,
            'desRuc' => 'BVA FOODS',
        ];
        $htmlRepLeg = $this->client->post(Endpoints::CONSULT, http_build_query($data), [
            'Content-Type' => 'application/x-www-form-urlencoded'
        ]);

        if ($htmlRepLeg === false) {
            return false;
        }

        $dom = new \DOMDocument();
        @$dom->loadHTML($htmlRepLeg);
        $xpath = new \DOMXPath($dom);
        $elements = $xpath->query("//div[contains(@class, 'table-responsive')]");

        foreach ($elements as $element) {
            $element->setAttribute('class', $element->getAttribute('class') . ' representantes');
            $extraHtml .= $dom->saveHTML($element);
        }

        return utf8_decode($extraHtml);
    }
}
