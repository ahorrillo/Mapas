<?php
$inputFile = 'parcelas_actualizado_min.json';
$outputFile = 'parcelas_actualizado_min_fixed.json';

$geojson = json_decode(file_get_contents($inputFile), true);

function fixPolygon($coords) {
    $newCoords = [];
    foreach ($coords as $ring) {
        $newRing = [];
        foreach ($ring as $point) {
            // Cambia el signo de la longitud (primer valor)
            $newRing[] = [ -1 * abs($point[0]), $point[1] ];
        }
        $newCoords[] = $newRing;
    }
    return $newCoords;
}

foreach ($geojson['features'] as &$feature) {
    if ($feature['geometry']['type'] === 'Polygon') {
        $feature['geometry']['coordinates'] = fixPolygon($feature['geometry']['coordinates']);
    }
    // Si tienes MultiPolygon, añade una función similar
}

file_put_contents($outputFile, json_encode($geojson, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "Archivo corregido guardado en $outputFile\n";
?>
