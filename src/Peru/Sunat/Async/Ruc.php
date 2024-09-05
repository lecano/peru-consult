<?php

namespace Peru\Sunat\Async;

use Peru\Http\Async\ClientInterface;
use Peru\Sunat\Endpoints;
use Peru\Sunat\RandomTrait;
use Peru\Sunat\RucParser;
use React\Promise\PromiseInterface;

class Ruc
{
    use RandomTrait;

    /**
     * @var ClientInterface
     */
    private $client;

    /**
     * @var RucParser
     */
    private $parser;

    /**
     * Ruc constructor.
     *
     * @param ClientInterface $client
     * @param RucParser   $parser
     */
    public function __construct(ClientInterface $client, RucParser $parser)
    {
        $this->client = $client;
        $this->parser = $parser;
    }

    public function get(string $ruc): PromiseInterface
    {
        return $this->client
            ->getAsync(Endpoints::CONSULT)
            ->then(function () {
                $data = [
                    'accion' => 'consPorRazonSoc',
                    'razSoc' => 'BVA FOODS',
                ];

                return $this->client->postAsync(
                    Endpoints::CONSULT,
                    http_build_query($data),
                    [
                        'Content-Type' => 'application/x-www-form-urlencoded'
                    ]
                );
            })
            ->then(function ($htmlRandom) use ($ruc) {
                $random = $this->getRandom($htmlRandom);
                $data = [
                    'accion' => 'consPorRuc',
                    'nroRuc' => $ruc,
                    'numRnd' => $random,
                    'actReturn' => '1',
                    'modo' => '1',
                ];
                return $this->client->postAsync(
                    Endpoints::CONSULT,
                    http_build_query($data),
                    [
                        'Content-Type' => 'application/x-www-form-urlencoded'
                    ]
                );
            })
            ->then(function ($html) use ($ruc) {
                $data = [
                    'accion' => 'getCantTrab',
                    'nroRuc' => $ruc,
                    'contexto' => 'ti-it',
                    'modo' => '1',
                ];
                return $this->client->postAsync(
                    Endpoints::CONSULT,
                    http_build_query($data),
                    [
                        'Content-Type' => 'application/x-www-form-urlencoded'
                    ]
                )->then(function ($getCantTrab) use ($html, $ruc) {
                    $dom = new \DOMDocument();
                    @$dom->loadHTML($getCantTrab);
                    $xpath = new \DOMXPath($dom);
                    $elements = $xpath->query("//div[contains(@class, 'table-responsive')]");

                    $extra_html = '';
                    foreach ($elements as $element) {
                        // Agregar la clase 'trabajadores' al primer elemento
                        $element->setAttribute('class', $element->getAttribute('class') . ' trabajadores');
                        $extra_html .= $dom->saveHTML($element);
                    }

                    $data = [
                        'accion' => 'getRepLeg',
                        'contexto' => 'ti-it',
                        'modo' => '1',
                        'nroRuc' => $ruc,
                        'desRuc' => 'BVA FOODS',
                    ];
                    return $this->client->postAsync(
                        Endpoints::CONSULT,
                        http_build_query($data),
                        [
                            'Content-Type' => 'application/x-www-form-urlencoded'
                        ]
                    )->then(function ($getRepLeg) use ($html, $extra_html) {
                        $dom = new \DOMDocument();
                        @$dom->loadHTML($getRepLeg);
                        $xpath = new \DOMXPath($dom);
                        $elements = $xpath->query("//div[contains(@class, 'table-responsive')]");

                        // Agregar la clase 'representantes' a los elementos
                        foreach ($elements as $element) {
                            $element->setAttribute('class', $element->getAttribute('class') . ' representantes');
                            $extra_html .= $dom->saveHTML($element);
                        }

                        $domHtml = new \DOMDocument();
                        @$domHtml->loadHTML($html);
                        $body = $domHtml->getElementsByTagName('body')->item(0);

                        $extraDom = new \DOMDocument();
                        $extra_html = utf8_decode($extra_html);
                        @$extraDom->loadHTML($extra_html);
                        foreach ($extraDom->getElementsByTagName('body')->item(0)->childNodes as $node) {
                            $body->appendChild($domHtml->importNode($node, true));
                        }

                        $mix_html = $domHtml->saveHTML();

                        //echo $extra_html;

                        return $this->parser->parse($mix_html);
                    });
                });
            });
    }
}
