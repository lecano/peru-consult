<?php

namespace Peru\Sunat;

use DateTime;
use Generator;

class RucParser
{
    /**
     * Override Departments.
     *
     * @var array<string, string>
     */
    private $overridDeps = [
        'DIOS' => 'MADRE DE DIOS',
        'MARTIN' => 'SAN MARTIN',
        'LIBERTAD' => 'LA LIBERTAD',
        'CALLAO' => 'PROV. CONST. DEL CALLAO',
    ];

    /**
     * @var HtmlParserInterface
     */
    private $parser;

    /**
     * RucHtmlParser constructor.
     * @param HtmlParserInterface $parser
     */
    public function __construct(HtmlParserInterface $parser)
    {
        $this->parser = $parser;
    }

    public function parse(string $html): ?Company
    {
        if (empty($html)) {
            return null;
        }

        $dic = $this->parser->parse($html);
        if (false === $dic) {

            return null;
        }
        //var_dump($dic);

        return $this->getCompany($dic);
    }

    /**
     * @param array<string, mixed> $items
     * @return Company
     */
    private function getCompany(array $items): Company
    {
        $cp = $this->getHeadCompany($items);
        $cp->sistEmsion = $items['Sistema Emisión de Comprobante:'] ?? $items['Sistema de Emisión de Comprobante:'] ?? '';
        $cp->sistContabilidad = $items['Sistema Contabilidiad:'] ?? $items['Sistema Contabilidad:'] ?? $items['Sistema de Contabilidad:'] ?? '';
        $cp->actExterior = $items['Actividad Comercio Exterior:'] ?? $items['Actividad de Comercio Exterior:'] ?? '';
        $cp->fechaIniActividades = $this->parseDate($items['Fecha de Inicio de Actividades:'] ?? '');
        $cp->actEconomicas = $items['Actividad(es) Económica(s):'] ?? [];
        $cp->cpPago = $items['Comprobantes de Pago c/aut. de impresión (F. 806 u 816):'] ?? [];
        $cp->sistElectronica = $items['Sistema de Emisión Electrónica:'] ?? $items['Sistema de Emision Electronica:'] ?? [];
        $cp->fechaEmisorFe = $this->parseDate($items['Emisor electrónico desde:'] ?? '');
        $cp->cpeElectronico = $this->getCpes($items['Comprobantes Electrónicos:'] ?? '');
        $cp->fechaPle = $this->parseDate($items['Afiliado al PLE desde:'] ?? '');
        $cp->padrones = $items['Padrones:'] ?? [];
        //$cp->trabajadores = $items['trabajadores'] ?? [];
        //$cp->representantes = $items['representantes'] ?? [];

        $cp->representantes = isset($items['Documento']) ? array_map(function ($row) {
            return [
                'tipo_doc' => $row[0] ?? null,
                'nro_doc' => $row[1] ?? null,
                'nombre' => $row[2] ?? null,
                'cargo' => $row[3] ?? null,
                'fecha_desde' => isset($row[4]) ? $this->parseDate($row[4]) : null,
            ];
        }, $items['Documento']) : [];
        
        /*
        $cp->trabajadores = isset($items['Período']) ? array_map(function ($row) {
            $trabajadores = (int) str_replace([' ', '-'], '', trim($row[1] ?? '0'));
            $pensionistas = (int) str_replace([' ', '-'], '', trim($row[2] ?? '0'));
            $prestadores_servicio = (int) str_replace([' ', '-'], '', trim($row[3] ?? '0'));
            
            return [
                'periodo' => trim($row[0] ?? null),
                'trabajadores' => $trabajadores,
                'pensionistas' => $pensionistas,
                'prestadores_servicio' => $prestadores_servicio,
                'total' => $trabajadores + $pensionistas + $prestadores_servicio,
            ];
        }, $items['Período']) : [];
        */

        $cp->trabajadores = isset($items['Período']) ? array_reduce($items['Período'], function ($reciente, $row) {
            return (!$reciente || strtotime($row[0]) > strtotime($reciente[0])) ? $row : $reciente;
        }) : null;
        
        if ($cp->trabajadores) {
            $cp->trabajadores = [
                'periodo' => trim($cp->trabajadores[0] ?? null),
                'trabajadores' => str_replace([' ', '-'], 0, trim($cp->trabajadores[1] ?? null)),
                'pensionistas' => str_replace([' ', '-'], 0, trim($cp->trabajadores[2] ?? null)),
                'prestadores_servicio' => str_replace([' ', '-'], 0, trim($cp->trabajadores[3] ?? null)),
            ];
            $cp->trabajadores['total'] = $cp->trabajadores['trabajadores'] + $cp->trabajadores['pensionistas'] + $cp->trabajadores['prestadores_servicio'];
        }else{
            $cp->trabajadores = [];
        }
        

        $this->fixDirection($cp);

        return $cp;
    }

    /**
     * @param array<string, mixed> $items
     * @return Company
     */
    private function getHeadCompany(array $items): Company
    {
        $cp = new Company();

        [$cp->ruc, $cp->razonSocial] = $this->getRucRzSocial($items['Número de RUC:'] ?? $items['RUC:']);
        $cp->nombreComercial = $items['Nombre Comercial:'] ?? '';
        $cp->telefonos = [];
        $cp->tipo = $items['Tipo Contribuyente:'] ?? '';
        $cp->estado = $items['Estado del Contribuyente:'] ?? $items['Estado:'];
        $cp->condicion = $this->getFirstLine($items['Condición del Contribuyente:'] ?? $items['Condición:']);
        $cp->direccion = $items['Domicilio Fiscal:'] ?? $items['Dirección del Domicilio Fiscal:'];
        $cp->fechaInscripcion = $this->parseDate($items['Fecha de Inscripción:'] ?? '');
        $cp->fechaBaja = $this->parseDate($items['Fecha de Baja:'] ?? '');
        $cp->profesion = $items['Profesión u Oficio:'] ?? '';
        $this->fixEstado($cp);

        return $cp;
    }

    /**
     * @param string $text
     *
     * @return null|string
     */
    private function parseDate(string $text): ?string
    {
        if (empty($text) || '-' == $text) {
            return null;
        }

        $date = DateTime::createFromFormat('d/m/Y', $text);

        //return false === $date ? null : $date->format('Y-m-d').'T00:00:00.000Z';
        return false === $date ? null : $date->format('Y-m-d');
    }

    private function getFirstLine(string $text): string
    {
        $lines = explode("\r\n", $text);

        return trim($lines[0]);
    }

    private function fixEstado(Company $company): void
    {
        $lines = explode("\r\n", $company->estado);
        $count = count($lines);
        if ($count === 1) {
            return;
        }

        $validLines = iterator_to_array($this->filterValidLines($lines));
        $updateFechaBaja = count($validLines) === 3 && $company->fechaBaja === null;

        $company->estado = $validLines[0];
        $company->fechaBaja = $updateFechaBaja ? $this->parseDate($validLines[2]) : $company->fechaBaja;
    }

    private function filterValidLines(array $lines): Generator
    {
        foreach ($lines as $line) {
            $value = trim($line);
            if ($value === '') {
                continue;
            }
            yield $value;
        }
    }

    private function fixDirection(Company $company): void
    {
        $items = explode('                                               -', $company->direccion);
        if (3 !== count($items)) {
            $company->direccion = preg_replace("[\s+]", ' ', $company->direccion);

            return;
        }

        $pieces = explode(' ', trim($items[0]));
        $department = $this->getDepartment(end($pieces));
        $company->departamento = $department;
        $company->provincia = trim($items[1]);
        $company->distrito = trim($items[2]);
        $removeLength = count(explode(' ', $department));
        array_splice($pieces, -1 * $removeLength);
        $company->direccion = rtrim(join(' ', $pieces));
    }

    private function getDepartment(?string $department): string
    {
        $department = strtoupper($department);
        if (isset($this->overridDeps[$department])) {
            $department = $this->overridDeps[$department];
        }

        return $department;
    }

    /**
     * @param string|null $text
     * @return string[]
     */
    private function getCpes(?string $text): array
    {
        $cpes = [];
        if (!empty($text) && '-' != $text) {
            $cpes = explode(',', $text);
        }

        return $cpes;
    }

    /**
     * @param string|null $text
     * @return string[]
     */
    private function getRucRzSocial(?string $text): array
    {
        $pos = strpos($text, '-');

        $ruc = trim(substr($text, 0, $pos));
        $rzSocial = trim(substr($text, $pos + 1));

        return [$ruc, $rzSocial];
    }
}
