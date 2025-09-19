<?php
class GeoJsonUpdater {
    private $csvFile;
    private $geojsonFile;
    private $logFile;

    public function __construct($csvFile, $geojsonFile, $logFile = 'actualizacion_geojson.log') {
        $this->csvFile = $csvFile;
        $this->geojsonFile = $geojsonFile;
        $this->logFile = $logFile;

        // Inicializar archivo de log
        $this->log("Iniciando proceso de actualización GeoJSON");
        $this->log("Archivo CSV: " . $csvFile);
        $this->log("Archivo GeoJSON: " . $geojsonFile);
    }

    /**
     * Escribe un mensaje en el archivo de log
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message" . PHP_EOL;
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        echo $logMessage;
    }

    /**
     * Lee el archivo CSV y crea un array asociativo RefCat => [anno, direccion]
     */
    private function leerCSV() {
        $datos = [];

        if (!file_exists($this->csvFile)) {
            $this->log("Error: El archivo CSV no existe: " . $this->csvFile);
            return false;
        }

        $handle = fopen($this->csvFile, 'r');
        if (!$handle) {
            $this->log("Error: No se pudo abrir el archivo CSV: " . $this->csvFile);
            return false;
        }

        // Leer encabezados
        $headers = fgetcsv($handle);
        $this->log("Encabezados CSV: " . implode(', ', $headers));

        // Verificar que existen las columnas necesarias
        if (!in_array('RefCat', $headers) || !in_array('AnnoConstruccion', $headers) || !in_array('Direccion', $headers)) {
            $this->log("Error: El CSV debe contener las columnas 'RefCat', 'AnnoConstruccion' y 'Direccion'");
            fclose($handle);
            return false;
        }

        // Obtener índices de las columnas
        $refIndex = array_search('RefCat', $headers);
        $annoIndex = array_search('AnnoConstruccion', $headers);
        $dirIndex = array_search('Direccion', $headers);

        // Leer datos
        $contador = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) > max($refIndex, $annoIndex, $dirIndex)) {
                $refCat = trim($row[$refIndex]);
                $anno = trim($row[$annoIndex]);
                $direccion = trim($row[$dirIndex]);

                // Solo procesar si tenemos una referencia válida
                if (!empty($refCat)) {
                    $datos[$refCat] = [
                        'anno' => $anno,
                        'direccion' => $direccion
                    ];
                    $contador++;
                }
            }
        }

        fclose($handle);
        $this->log("Leídos $contador registros desde CSV");
        return $datos;
    }

    /**
     * Lee y decodifica el archivo GeoJSON
     */
    private function leerGeoJSON() {
        if (!file_exists($this->geojsonFile)) {
            $this->log("Error: El archivo GeoJSON no existe: " . $this->geojsonFile);
            return false;
        }

        $content = file_get_contents($this->geojsonFile);

        if ($content === false) {
            $this->log("Error: No se pudo leer el archivo GeoJSON");
            return false;
        }

        // Decodificar el JSON
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("Error decodificando GeoJSON: " . json_last_error_msg());
            return false;
        }

        // Verificar que es un GeoJSON válido
        if (!isset($data['type']) || $data['type'] !== 'FeatureCollection' || !isset($data['features'])) {
            $this->log("Error: El archivo no es un FeatureCollection de GeoJSON válido");
            return false;
        }

        return $data;
    }

    /**
     * Actualiza el GeoJSON con los datos del CSV
     */
    public function actualizarGeoJSON() {
        // Leer datos del CSV
        $datosCSV = $this->leerCSV();
        if ($datosCSV === false) {
            return false;
        }

        if (count($datosCSV) === 0) {
            $this->log("No hay datos válidos en el CSV para procesar");
            return false;
        }

        // Leer el GeoJSON
        $geojsonData = $this->leerGeoJSON();
        if ($geojsonData === false) {
            return false;
        }

        $this->log("GeoJSON contiene " . count($geojsonData['features']) . " features");

        $actualizaciones = 0;
        $noEncontrados = 0;
        $sinRefCat = 0;

        // Procesar cada feature del GeoJSON
        foreach ($geojsonData['features'] as &$feature) {
            if (isset($feature['properties']['REFCAT'])) {
                $refCat = $feature['properties']['REFCAT'];

                // Buscar si esta RefCat está en nuestro CSV
                if (isset($datosCSV[$refCat])) {
                    // Actualizar FECHAALTA y añadir campo CALLE
                    $feature['properties']['FECHAALTA'] = $datosCSV[$refCat]['anno'];
                    $feature['properties']['CALLE'] = $datosCSV[$refCat]['direccion'];
                    $actualizaciones++;

                    $this->log("Actualizada RefCat: $refCat - Año: " . $datosCSV[$refCat]['anno'] . " - Calle: " . $datosCSV[$refCat]['direccion']);
                } else {
                    // No encontrado en CSV, establecer FECHAALTA a 0
                    $feature['properties']['FECHAALTA'] = '0';
                    $noEncontrados++;

                    $this->log("RefCat no encontrada en CSV: $refCat - Estableciendo FECHAALTA a 0");
                }
            } else {
                // No tiene propiedad REFCAT
                $sinRefCat++;
                $this->log("Feature sin propiedad REFCAT");
            }
        }

        // Guardar el GeoJSON actualizado
        $jsonActualizado = json_encode($geojsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($jsonActualizado === false) {
            $this->log("Error: No se pudo codificar el GeoJSON actualizado: " . json_last_error_msg());
            return false;
        }

        // Crear nombre de archivo de salida
        $info = pathinfo($this->geojsonFile);
        $outputFile = $info['dirname'] . '/' . $info['filename'] . '_actualizado.' . $info['extension'];

        if (file_put_contents($outputFile, $jsonActualizado)) {
            $this->log("Proceso completado. Archivo guardado como: $outputFile");
            $this->log("Resumen: $actualizaciones actualizaciones, $noEncontrados RefCat no encontradas, $sinRefCat features sin REFCAT");
            return $outputFile;
        } else {
            $this->log("Error: No se pudo guardar el archivo GeoJSON actualizado");
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
        exit(1);
    }

    $csvFile = $argv[1];
    $geojsonFile = $argv[2];

    $updater = new GeoJsonUpdater($csvFile, $geojsonFile);
    $result = $updater->actualizarGeoJSON();

    if ($result) {
        echo "Proceso completado con éxito. Archivo generado: $result" . PHP_EOL;
    } else {
        echo "Error en el proceso. Verifique el archivo de log." . PHP_EOL;
        exit(1);
    }
} else {
    // Modo web - formulario simple
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $uploadDir = __DIR__ . '/uploads/';

        // Crear directorio de uploads si no existe
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // Procesar archivo CSV
        $csvFile = $uploadDir . 'temp_csv_' . time() . '.csv';
        if (isset($_FILES['csvfile']) && $_FILES['csvfile']['error'] === UPLOAD_ERR_OK) {
            move_uploaded_file($_FILES['csvfile']['tmp_name'], $csvFile);
        } else {
            echo "Error al subir el archivo CSV.";
            exit;
        }

        // Procesar archivo GeoJSON
        $geojsonFile = $uploadDir . 'temp_geojson_' . time() . '.json';
        if (isset($_FILES['geojsonfile']) && $_FILES['geojsonfile']['error'] === UPLOAD_ERR_OK) {
            move_uploaded_file($_FILES['geojsonfile']['tmp_name'], $geojsonFile);
        } else {
            echo "Error al subir el archivo GeoJSON.";
            exit;
        }

        // Ejecutar la actualización
        $updater = new GeoJsonUpdater($csvFile, $geojsonFile);
        $resultFile = $updater->actualizarGeoJSON();

        if ($resultFile) {
            // Ofrecer descarga del archivo resultante
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . basename($resultFile) . '"');
            readfile($resultFile);

            // Limpiar archivos temporales
            unlink($csvFile);
            unlink($geojsonFile);
            unlink($resultFile);
            exit;
        } else {
            echo "Error en el procesamiento. Verifique el archivo de log.";
        }
    } else {
        // Mostrar formulario
        echo '
        <!DOCTYPE html>
        <html>
        <head>
            <title>Actualizador de GeoJSON con datos CSV</title>
            <meta charset="utf-8">
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                form { margin: 20px 0; }
                input[type="file"], input[type="submit"] { margin: 10px 0; }
            </style>
        </head>
        <body>
            <h1>Actualizador de GeoJSON con datos CSV</h1>
            <form method="post" enctype="multipart/form-data">
                <div>
                    <label>Seleccione el archivo CSV con RefCat, AnnoConstruccion y Direccion:</label><br>
                    <input type="file" name="csvfile" accept=".csv" required>
                </div>
                <div>
                    <label>Seleccione el archivo GeoJSON a actualizar:</label><br>
                    <input type="file" name="geojsonfile" accept=".geojson,.json" required>
                </div>
                <div>
                    <input type="submit" value="Actualizar GeoJSON">
                </div>
            </form>
            <p>El archivo CSV debe contener columnas "RefCat", "AnnoConstruccion" y "Direccion".</p>
            <p>El archivo GeoJSON debe tener features con propiedad "REFCAT".</p>
            <p>El script actualizará "FECHAALTA" y añadirá "CALLE" según los datos del CSV.</p>
        </body>
        </html>';
    }
}
?>
