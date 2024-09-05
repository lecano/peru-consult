<?php
use Peru\Sunat\RucFactory;

require 'vendor/autoload.php';

header('Content-Type: application/json'); // Asegúrate de que la respuesta sea JSON

$response = [
    'success' => false,
    'message' => 'Sin datos',
    'data' => null
];

try {
    $ruc = isset($_GET['ruc']) ? $_GET['ruc'] : null;

    if (empty($ruc)) {
        throw new InvalidArgumentException('El RUC es requerido');
    }

    $factory = new RucFactory();
    $cs = $factory->create();

    $company = $cs->get($ruc);

    if ($company) {
        $response['success'] = true;
        $response['message'] = 'Info encontrada';
        $response['data'] = $company;
    } else {
        $response['message'] = 'Info no encontrada';
    }
} catch (InvalidArgumentException $e) {
    $response['message'] = $e->getMessage();
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
}

echo json_encode($response);
