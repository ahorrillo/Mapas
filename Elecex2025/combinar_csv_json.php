<?php
/**
 * Script para combinar datos de CSV con un archivo JSON
 * Busca coincidencias entre cusec (CSV) y CUSEC (JSON) y agrega propiedades
 */

// Configuración
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Verificar argumentos de línea de comandos
if ($argc < 4) {
    echo "Uso: php script.php <archivo_csv> <archivo_json> <archivo_salida>\n";
    echo "Ejemplo: php script.php datos.csv datos.json resultado.json\n";
    exit(1);
}

$csvFile = $argv[1];
$jsonFile = $argv[2];
$outputFile = $argv[3];

// Verificar existencia de archivos
if (!file_exists($csvFile)) {
    echo "Error: El archivo CSV '$csvFile' no existe.\n";
    exit(1);
}

if (!file_exists($jsonFile)) {
    echo "Error: El archivo JSON '$jsonFile' no existe.\n";
    exit(1);
}

// Función para normalizar CUSEC - maneja ceros a la izquierda
function normalizarCusec($cusec) {
    // Eliminar espacios y convertir a string
    $cusec = trim(strval($cusec));

    // Si es numérico, eliminar ceros a la izquierda para comparación
    if (is_numeric($cusec)) {
        // Convertir a entero y luego a string para eliminar ceros a la izquierda
        $cusec = strval(intval($cusec));
    }

    return $cusec;
}

// 1. Leer y procesar el CSV
echo "Leyendo archivo CSV: $csvFile\n";
$csvData = [];
$headers = [];

if (($handle = fopen($csvFile, "r")) !== FALSE) {
    // Leer encabezados (primera línea)
    $headers = fgetcsv($handle, 1000, ",");

    // Verificar que exista la columna cusec
    $cusecIndex = array_search('cusec', array_map('strtolower', $headers));
    if ($cusecIndex === FALSE) {
        // Buscar variaciones del nombre
        foreach ($headers as $index => $header) {
            if (strtolower($header) === 'cusec') {
                $cusecIndex = $index;
                break;
            }
        }
    }

    if ($cusecIndex === FALSE) {
        echo "Error: El CSV debe tener una columna llamada 'cusec'.\n";
        echo "Encabezados encontrados: " . implode(', ', $headers) . "\n";
        fclose($handle);
        exit(1);
    }

    // Obtener los nombres reales de las columnas
    $cusecColName = $headers[$cusecIndex];

    // Columnas a agregar (todas excepto cusec)
    $propertyColumns = [];
    foreach ($headers as $index => $header) {
        if ($index !== $cusecIndex) {
            $propertyColumns[$index] = $header;
        }
    }

    echo "Columna cusec encontrada: '$cusecColName' en índice: $cusecIndex\n";
    echo "Columnas a agregar como propiedades (" . count($propertyColumns) . "): " . implode(', ', $propertyColumns) . "\n";

    // Leer resto de filas
    $rowCount = 0;
    while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
        if (count($row) == count($headers)) {
            $data = array_combine($headers, $row);
            $cusecValue = $data[$cusecColName] ?? '';
            $cusec = normalizarCusec($cusecValue);

            // Solo procesar si cusec no está vacío
            if (!empty($cusec)) {
                $csvData[$cusec] = $data;
                $rowCount++;

                // Mostrar algunos ejemplos para depuración
                if ($rowCount <= 3) {
                    echo "  Ejemplo CSV - CUSEC: '$cusecValue' -> normalizado: '$cusec'\n";
                }
            } else {
                echo "  Advertencia: Fila $rowCount tiene cusec vacío\n";
            }
        } else {
            echo "  Advertencia: Fila con número incorrecto de columnas: " . count($row) . " vs " . count($headers) . "\n";
        }
    }
    fclose($handle);

    echo "Filas CSV procesadas: $rowCount\n";
    echo "CUSECs únicos en CSV: " . count($csvData) . "\n";
} else {
    echo "Error: No se pudo abrir el archivo CSV.\n";
    exit(1);
}

// 2. Leer y procesar el JSON
echo "\nLeyendo archivo JSON: $jsonFile\n";
$jsonContent = file_get_contents($jsonFile);
if ($jsonContent === FALSE) {
    echo "Error: No se pudo leer el archivo JSON.\n";
    exit(1);
}

$data = json_decode($jsonContent, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "Error al decodificar JSON: " . json_last_error_msg() . "\n";
    exit(1);
}

// Verificar estructura del JSON
if (!isset($data['features']) || !is_array($data['features'])) {
    echo "Error: El JSON debe tener un array 'features'.\n";
    exit(1);
}

echo "Features encontrados en JSON: " . count($data['features']) . "\n";

// 3. Buscar coincidencias y agregar propiedades
echo "\nBuscando coincidencias y agregando propiedades...\n";
$matchesFound = 0;
$featuresProcessed = 0;
$debugExamples = [];

foreach ($data['features'] as &$feature) {
    $featuresProcessed++;

    if (!isset($feature['properties']['CUSEC'])) {
        continue;
    }

    $jsonCusecOriginal = $feature['properties']['CUSEC'];
    $jsonCusec = normalizarCusec($jsonCusecOriginal);

    // Guardar algunos ejemplos para depuración
    if ($featuresProcessed <= 3) {
        $debugExamples[] = "JSON - CUSEC original: '$jsonCusecOriginal' -> normalizado: '$jsonCusec'";
    }

    // Buscar coincidencia en datos CSV
    if (isset($csvData[$jsonCusec])) {
        $matchesFound++;

        // Agregar cada columna (excepto cusec) como propiedad
        foreach ($propertyColumns as $colIndex => $colName) {
            $value = $csvData[$jsonCusec][$colName] ?? '';

            // Convertir a número si es posible
            if (is_numeric($value)) {
                // Para porcentajes, mantener como float
                if (strpos($colName, '_porc') !== false || strpos($colName, '_dif') !== false) {
                    $value = floatval($value);
                } else {
                    // Para otros números, mantener como string si comienza con 0
                    $value = (strlen($value) > 1 && $value[0] === '0') ? $value : floatval($value);
                }
            }

            $feature['properties'][$colName] = $value;
        }

        // Mostrar un ejemplo de la primera coincidencia
        if ($matchesFound === 1) {
            echo "  Primera coincidencia encontrada:\n";
            echo "    CUSEC JSON: '$jsonCusecOriginal' -> normalizado: '$jsonCusec'\n";
            echo "    Datos agregados: participacion=" . ($csvData[$jsonCusec]['participacion'] ?? 'N/A') . "\n";
        }

        // Opcional: Mostrar progreso cada 1000 features
        if ($matchesFound % 1000 == 0) {
            echo "  Coincidencias encontradas: $matchesFound\n";
        }
    }
}

// Mostrar ejemplos de depuración
if (!empty($debugExamples)) {
    echo "\nEjemplos de normalización de CUSEC:\n";
    foreach ($debugExamples as $example) {
        echo "  $example\n";
    }
}

echo "\nFeatures procesados: $featuresProcessed\n";
echo "Coincidencias totales encontradas: $matchesFound\n";

if ($matchesFound === 0) {
    echo "\n¡ADVERTENCIA! No se encontraron coincidencias.\n";
    echo "Posibles causas:\n";
    echo "1. Los CUSEC en JSON y CSV no coinciden\n";
    echo "2. Diferencia en ceros a la izquierda\n";
    echo "3. Diferentes formatos de CUSEC\n";

    // Mostrar algunos CUSEC de ejemplo de cada archivo
    echo "\nPrimeros 5 CUSEC del CSV (normalizados):\n";
    $csvKeys = array_slice(array_keys($csvData), 0, 5);
    foreach ($csvKeys as $key) {
        echo "  - $key\n";
    }

    echo "\nPrimeros 5 CUSEC del JSON (normalizados):\n";
    $jsonExamples = [];
    foreach ($data['features'] as $feature) {
        if (isset($feature['properties']['CUSEC'])) {
            $jsonExamples[] = normalizarCusec($feature['properties']['CUSEC']);
            if (count($jsonExamples) >= 5) break;
        }
    }
    foreach ($jsonExamples as $example) {
        echo "  - $example\n";
    }
}

// 4. Guardar el JSON modificado
echo "\nGuardando resultado en: $outputFile\n";
$jsonResult = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

if (file_put_contents($outputFile, $jsonResult) === FALSE) {
    echo "Error: No se pudo guardar el archivo de salida.\n";
    exit(1);
}

echo "¡Proceso completado exitosamente!\n";
echo "Archivo guardado: $outputFile\n";

// Resumen final
echo "\n=== RESUMEN ===\n";
echo "Archivo CSV procesado: $csvFile\n";
echo "Filas CSV: " . count($csvData) . "\n";
echo "Features JSON procesados: $featuresProcessed\n";
echo "Coincidencias encontradas: $matchesFound\n";
echo "Porcentaje de coincidencia: " . ($featuresProcessed > 0 ? round(($matchesFound / $featuresProcessed) * 100, 2) : 0) . "%\n";
echo "Columnas agregadas: " . implode(', ', $propertyColumns) . "\n";

// Verificar si se agregaron propiedades correctamente
if ($matchesFound > 0) {
    // Encontrar el primer feature con propiedades agregadas
    foreach ($data['features'] as $feature) {
        if (isset($feature['properties']['CUSEC']) && isset($feature['properties']['participacion'])) {
            $sampleCusec = $feature['properties']['CUSEC'];
            echo "\nEjemplo de propiedades agregadas para CUSEC '$sampleCusec':\n";
            echo "  participacion: " . ($feature['properties']['participacion'] ?? 'N/A') . "\n";
            echo "  primero: " . ($feature['properties']['primero'] ?? 'N/A') . "\n";
            echo "  primero_porc: " . ($feature['properties']['primero_porc'] ?? 'N/A') . "\n";
            break;
        }
    }
}
?>
