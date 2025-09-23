<?php
class GeoJsonUpdater {
    private $csvFile;
    private $geojsonFile;
    private $logFile;

    public function __construct($csvFile, $geojsonFile, $logFile = 'actualizacion_geojson.log') {
        $this->csvFile = $csvFile;
        $this->geojsonFile = $geojsonFile;
        $this->logFile = $logFile;

        $this->log("=== INICIANDO ACTUALIZACIÓN GEOJSON ===");
        $this->log("Archivo CSV: " . $csvFile);
        $this->log("Archivo GeoJSON: " . $geojsonFile);
    }

    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message" . PHP_EOL;
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        echo $logMessage;
    }

    /**
     * Función para limpiar y validar cadenas UTF-8
     */
    private function limpiarUTF8($string) {
        if (!mb_check_encoding($string, 'UTF-8')) {
            $encoding = mb_detect_encoding($string, 'UTF-8, ISO-8859-1, WINDOWS-1252', true);
            if ($encoding !== 'UTF-8') {
                $string = mb_convert_encoding($string, 'UTF-8', $encoding);
            }
        }
        return preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', ' ', $string);
    }

    /**
     * Lee el archivo CSV y crea un array asociativo RefCat => [anno, direccion]
     */
    private function leerCSV() {
        if (!file_exists($this->csvFile)) {
            $this->log("ERROR: El archivo CSV no existe: " . $this->csvFile);
            return false;
        }

        $handle = fopen($this->csvFile, 'r');
        if (!$handle) {
            $this->log("ERROR: No se pudo abrir el archivo CSV");
            return false;
        }

        // Leer encabezados
        $headers = fgetcsv($handle);
        $this->log("Encabezados CSV detectados: " . implode(', ', $headers));

        // Verificar columnas necesarias
        $columnasRequeridas = ['RefCat', 'AnnoConstruccion', 'Direccion'];
        foreach ($columnasRequeridas as $columna) {
            if (!in_array($columna, $headers)) {
                $this->log("ERROR: Falta la columna requerida: " . $columna);
                fclose($handle);
                return false;
            }
        }

        // Obtener índices
        $refIndex = array_search('RefCat', $headers);
        $annoIndex = array_search('AnnoConstruccion', $headers);
        $dirIndex = array_search('Direccion', $headers);

        $datos = [];
        $contador = 0;
        $errores = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) > max($refIndex, $annoIndex, $dirIndex)) {
                $refCat = $this->limpiarUTF8(trim($row[$refIndex]));
                $anno = $this->limpiarUTF8(trim($row[$annoIndex]));
                $direccion = $this->limpiarUTF8(trim($row[$dirIndex]));

                if (!empty($refCat)) {
                    // Convertir año a formato numérico (0 si no es válido)
                    $annoNumerico = is_numeric($anno) ? (int)$anno : 0;

                    $datos[$refCat] = [
                        'anno' => $annoNumerico,
                        'direccion' => $direccion
                    ];
                    $contador++;
                } else {
                    $errores++;
                }
            }
        }

        fclose($handle);
        $this->log("CSV procesado: $contador registros válidos, $errores registros con errores");
        return $datos;
    }

    /**
     * Lee y decodifica el archivo GeoJSON con manejo de errores de codificación
     */
    private function leerGeoJSON() {
        if (!file_exists($this->geojsonFile)) {
            $this->log("ERROR: El archivo GeoJSON no existe: " . $this->geojsonFile);
            return false;
        }

        $content = file_get_contents($this->geojsonFile);
        if ($content === false) {
            $this->log("ERROR: No se pudo leer el archivo GeoJSON");
            return false;
        }

        // Limpiar contenido
        $cleanedContent = $this->limpiarUTF8($content);

        // Intentar decodificar
        $data = json_decode($cleanedContent, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            $this->log("GeoJSON decodificado correctamente");
            $this->log("Sistema de coordenadas: " . ($data['crs']['properties']['name'] ?? 'No especificado'));
            return $data;
        }

        $this->log("ERROR decodificando GeoJSON: " . json_last_error_msg());

        // Intentar con diferentes codificaciones
        $encodings = ['UTF-8', 'ISO-8859-1', 'WINDOWS-1252'];
        foreach ($encodings as $encoding) {
            $convertedContent = mb_convert_encoding($content, 'UTF-8', $encoding);
            $data = json_decode($convertedContent, true, 512, JSON_INVALID_UTF8_IGNORE);

            if (json_last_error() === JSON_ERROR_NONE) {
                $this->log("GeoJSON decodificado con codificación: $encoding");
                $this->log("Sistema de coordenadas: " . ($data['crs']['properties']['name'] ?? 'No especificado'));
                return $data;
            }
        }

        $this->log("ERROR: No se pudo decodificar el GeoJSON después de múltiples intentos");
        return false;
    }

    /**
     * Actualiza el GeoJSON con los datos del CSV
     */
    public function actualizarGeoJSON() {
        // Leer CSV
        $this->log("Leyendo archivo CSV...");
        $datosCSV = $this->leerCSV();
        if ($datosCSV === false) {
            return false;
        }

        if (count($datosCSV) === 0) {
            $this->log("ADVERTENCIA: No hay datos válidos en el CSV");
        }

        // Leer GeoJSON
        $this->log("Leyendo archivo GeoJSON...");
        $geojsonData = $this->leerGeoJSON();
        if ($geojsonData === false) {
            return false;
        }

        // Validar estructura GeoJSON (nuevo formato con CRS)
        if (!isset($geojsonData['type']) || $geojsonData['type'] !== 'FeatureCollection' || !isset($geojsonData['features'])) {
            $this->log("ERROR: El archivo no es un FeatureCollection de GeoJSON válido");
            return false;
        }

        // Verificar si tiene CRS definido
        if (isset($geojsonData['crs'])) {
            $this->log("Sistema de coordenadas detectado: " . $geojsonData['crs']['properties']['name']);
        } else {
            $this->log("ADVERTENCIA: No se detectó sistema de coordenadas en el GeoJSON");
        }

        $totalFeatures = count($geojsonData['features']);
        $this->log("GeoJSON contiene $totalFeatures features");

        // Procesar actualizaciones
        $actualizaciones = 0;
        $noEncontrados = 0;
        $sinRefCat = 0;
        $errores = 0;

        foreach ($geojsonData['features'] as &$feature) {
            try {
                if (isset($feature['properties']['REFCAT'])) {
                    $refCat = $this->limpiarUTF8($feature['properties']['REFCAT']);

                    if (isset($datosCSV[$refCat])) {
                        // Actualizar con datos del CSV
                        $feature['properties']['FECHAALTA'] = $datosCSV[$refCat]['anno'];

                        // Añadir campo CALLE solo si no existe o queremos actualizarlo
                        $feature['properties']['CALLE'] = $datosCSV[$refCat]['direccion'];
                        $actualizaciones++;

                        $this->log("ACTUALIZADA: $refCat - Año: " . $datosCSV[$refCat]['anno'] . " - Calle: " . $datosCSV[$refCat]['direccion']);
                    } else {
                        // No encontrado, establecer a 0
                        $feature['properties']['FECHAALTA'] = 0;
                        $noEncontrados++;
                        $this->log("NO ENCONTRADA: $refCat - Estableciendo FECHAALTA a 0");
                    }
                } else {
                    $sinRefCat++;
                    $this->log("SIN REFCAT: Feature sin propiedad REFCAT");
                }
            } catch (Exception $e) {
                $errores++;
                $this->log("ERROR procesando feature: " . $e->getMessage());
            }
        }

        // Guardar resultado - mantener la estructura original con CRS
        $this->log("Guardando GeoJSON actualizado...");

        // Mantener todas las propiedades originales incluyendo CRS y name
        $jsonActualizado = json_encode($geojsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($jsonActualizado === false) {
            $this->log("ERROR codificando GeoJSON: " . json_last_error_msg());
            return false;
        }

        // Crear archivo de salida
        $info = pathinfo($this->geojsonFile);
        $outputFile = $info['dirname'] . '/' . $info['filename'] . '_actualizado.' . $info['extension'];

        if (file_put_contents($outputFile, $jsonActualizado)) {
            // Resumen final
            $this->log("=== RESUMEN FINAL ===");
            $this->log("Total features procesadas: $totalFeatures");
            $this->log("Actualizaciones exitosas: $actualizaciones");
            $this->log("REFCAT no encontradas: $noEncontrados");
            $this->log("Features sin REFCAT: $sinRefCat");
            $this->log("Errores de procesamiento: $errores");
            $this->log("Archivo guardado como: $outputFile");
            $this->log("=== ACTUALIZACIÓN COMPLETADA ===");

            return $outputFile;
        } else {
            $this->log("ERROR: No se pudo guardar el archivo GeoJSON actualizado");
            return false;
        }
    }
}

// Uso del script
if (php_sapi_name() === 'cli') {
    // Modo línea de comandos
    if ($argc < 3) {
        echo "Uso: php geojson_updater.php <archivo_csv> <archivo_geojson>" . PHP_EOL;
        echo "Ejemplo: php geojson_updater.php resultado.csv parcelas.geojson" . PHP_EOL;
        echo "Funcionalidad:" . PHP_EOL;
        echo "  - Busca REFCAT en GeoJSON y las compara con RefCat en CSV" . PHP_EOL;
        echo "  - Actualiza FECHAALTA con AnnoConstruccion del CSV" . PHP_EOL;
        echo "  - Añade campo CALLE con Direccion del CSV" . PHP_EOL;
        echo "  - Establece FECHAALTA=0 para REFCAT no encontradas" . PHP_EOL;
        echo "  - Mantiene el sistema de coordenadas EPSG:25829 (UTM 29N)" . PHP_EOL;
        exit(1);
    }

    $csvFile = $argv[1];
    $geojsonFile = $argv[2];

    $updater = new GeoJsonUpdater($csvFile, $geojsonFile);
    $result = $updater->actualizarGeoJSON();

    if ($result) {
        echo "✅ Proceso completado con éxito." . PHP_EOL;
        echo "📁 Archivo generado: $result" . PHP_EOL;
        echo "📋 Ver detalles en: actualizacion_geojson.log" . PHP_EOL;
    } else {
        echo "❌ Error en el proceso." . PHP_EOL;
        echo "📋 Ver detalles en: actualizacion_geojson.log" . PHP_EOL;
        exit(1);
    }
} else {
    // Modo web
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $uploadDir = __DIR__ . '/uploads/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

        // Procesar archivos subidos
        $csvFile = $uploadDir . 'temp_csv_' . time() . '.csv';
        $geojsonFile = $uploadDir . 'temp_geojson_' . time() . '.json';

        $uploadSuccess = true;

        if (isset($_FILES['csvfile']) && $_FILES['csvfile']['error'] === UPLOAD_ERR_OK) {
            move_uploaded_file($_FILES['csvfile']['tmp_name'], $csvFile);
        } else {
            echo "Error al subir el archivo CSV.";
            $uploadSuccess = false;
        }

        if (isset($_FILES['geojsonfile']) && $_FILES['geojsonfile']['error'] === UPLOAD_ERR_OK) {
            move_uploaded_file($_FILES['geojsonfile']['tmp_name'], $geojsonFile);
        } else {
            echo "Error al subir el archivo GeoJSON.";
            $uploadSuccess = false;
        }

        if ($uploadSuccess) {
            $updater = new GeoJsonUpdater($csvFile, $geojsonFile);
            $resultFile = $updater->actualizarGeoJSON();

            if ($resultFile) {
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="' . basename($resultFile) . '"');
                readfile($resultFile);

                // Limpiar
                unlink($csvFile);
                unlink($geojsonFile);
                unlink($resultFile);
                exit;
            } else {
                echo "Error en el procesamiento. Verifique el archivo de log.";
            }
        }
    } else {
        // Formulario HTML
        echo '<!DOCTYPE html>
        <html>
        <head>
            <title>Actualizador GeoJSON - Nuevo Formato</title>
            <meta charset="utf-8">
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; line-height: 1.6; }
                form { background: #f5f5f5; padding: 20px; border-radius: 8px; }
                div { margin-bottom: 15px; }
                label { display: block; margin-bottom: 5px; font-weight: bold; }
                input[type="file"] { padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
                input[type="submit"] { background: #3498db; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; }
                input[type="submit"]:hover { background: #2980b9; }
                .info { background: #e7f3ff; padding: 15px; border-radius: 4px; margin-top: 20px; }
            </style>
        </head>
        <body>
            <h1>Actualizador de GeoJSON (Nuevo Formato)</h1>
            <form method="post" enctype="multipart/form-data">
                <div>
                    <label>Archivo CSV con datos catastrales:</label>
                    <input type="file" name="csvfile" accept=".csv" required>
                </div>
                <div>
                    <label>Archivo GeoJSON (formato PARCELA-FINAL):</label>
                    <input type="file" name="geojsonfile" accept=".geojson,.json" required>
                </div>
                <div>
                    <input type="submit" value="Actualizar GeoJSON">
                </div>
            </form>

            <div class="info">
                <h3>Funcionalidades del script:</h3>
                <ul>
                    <li>✅ Compatible con el nuevo formato GeoJSON (EPSG:25829)</li>
                    <li>✅ Busca REFCAT en GeoJSON y las compara con RefCat en CSV</li>
                    <li>✅ Actualiza FECHAALTA con AnnoConstruccion del CSV</li>
                    <li>✅ Añade campo CALLE con Direccion del CSV</li>
                    <li>✅ Establece FECHAALTA=0 para REFCAT no encontradas</li>
                    <li>✅ Mantiene el sistema de coordenadas original (UTM 29N)</li>
                    <li>✅ Preserva todas las propiedades y estructura del GeoJSON</li>
                </ul>
                <p><strong>Formato esperado del CSV:</strong> Columnas RefCat, AnnoConstruccion, Direccion</p>
                <p><strong>Formato esperado del GeoJSON:</strong> FeatureCollection con CRS EPSG:25829</p>
            </div>
        </body>
        </html>';
    }
}
?>
