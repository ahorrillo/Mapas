<?php
class JsonUpdater {
    private $csvFile;
    private $jsonFile;
    private $logFile;

    public function __construct($csvFile, $jsonFile, $logFile = 'actualizacion_json.log') {
        $this->csvFile = $csvFile;
        $this->jsonFile = $jsonFile;
        $this->logFile = $logFile;

        // Inicializar archivo de log
        $this->log("Iniciando proceso de actualización JSON");
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
     * Lee el archivo CSV y crea un array asociativo RefCat => Año
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

        // Verificar que existen las columnas necesarias
        if (!in_array('RefCat', $headers) || !in_array('AnnoConstruccion', $headers)) {
            $this->log("Error: El CSV debe contener las columnas 'RefCat' y 'AnnoConstruccion'");
            fclose($handle);
            return false;
        }

        // Obtener índices de las columnas
        $refIndex = array_search('RefCat', $headers);
        $annoIndex = array_search('AnnoConstruccion', $headers);

        // Leer datos
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) > max($refIndex, $annoIndex)) {
                $refCat = trim($row[$refIndex]);
                $anno = trim($row[$annoIndex]);

                // Solo procesar si tenemos un año válido
                if (is_numeric($anno) && $anno > 0) {
                    $datos[$refCat] = $anno; // Formato XXXX
                }
            }
        }

        fclose($handle);
        $this->log("Leídos " . count($datos) . " registros válidos desde CSV");
        return $datos;
    }

    /**
     * Actualiza el archivo JSON con los datos del CSV
     */
    public function actualizarJSON() {
        // Leer datos del CSV
        $datosCSV = $this->leerCSV();
        if ($datosCSV === false) {
            return false;
        }

        if (count($datosCSV) === 0) {
            $this->log("No hay datos válidos en el CSV para procesar");
            return false;
        }

        // Verificar si el archivo JSON existe
        if (!file_exists($this->jsonFile)) {
            $this->log("Error: El archivo JSON no existe: " . $this->jsonFile);
            return false;
        }

        // Leer el archivo JSON
        $jsonContent = file_get_contents($this->jsonFile);
        if ($jsonContent === false) {
            $this->log("Error: No se pudo leer el archivo JSON");
            return false;
        }

        // Decodificar JSON
        $data = json_decode($jsonContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("Error decodificando JSON: " . json_last_error_msg());
            return false;
        }

        // Verificar que es un GeoJSON con features
        if (!isset($data['type']) || $data['type'] !== 'FeatureCollection' || !isset($data['features'])) {
            $this->log("Error: El JSON no es un FeatureCollection de GeoJSON válido");
            return false;
        }

        $actualizaciones = 0;
        $noEncontrados = 0;

        // Procesar cada feature
        foreach ($data['features'] as &$feature) {
            if (isset($feature['type']) && $feature['type'] === 'Feature' &&
                isset($feature['properties']['RefCat'])) {

                $refCat = $feature['properties']['REFCAT'];

                // Buscar si esta RefCat está en nuestro CSV
                if (isset($datosCSV[$refCat])) {
                    // Actualizar la propiedad FECHAALTA
                    $feature['properties']['FECHAALTA'] = $datosCSV[$refCat];
                    $actualizaciones++;
                    $this->log("Actualizada RefCat: $refCat con fecha: " . $datosCSV[$refCat]);
                } else {
                    $noEncontrados++;
                }
            }
        }

        // Guardar el JSON actualizado
        $jsonActualizado = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Crear nombre de archivo de salida
        $info = pathinfo($this->jsonFile);
        $outputFile = $info['dirname'] . '/' . $info['filename'] . '_actualizado.' . $info['extension'];

        if (file_put_contents($outputFile, $jsonActualizado)) {
            $this->log("Proceso completado. Archivo guardado como: $outputFile");
            $this->log("Resumen: $actualizaciones actualizaciones, $noEncontrados RefCat no encontradas en CSV");
            return $outputFile;
        } else {
            $this->log("Error: No se pudo guardar el archivo JSON actualizado");
            return false;
        }
    }
}

// Uso del script
if (php_sapi_name() === 'cli') {
    // Modo línea de comandos
    if ($argc < 3) {
        echo "Uso: php json_updater.php <archivo_csv> <archivo_json>" . PHP_EOL;
        echo "Ejemplo: php json_updater.php resultado.csv datos.json" . PHP_EOL;
        exit(1);
    }

    $csvFile = $argv[1];
    $jsonFile = $argv[2];

    $updater = new JsonUpdater($csvFile, $jsonFile);
    $updater->actualizarJSON();
} else {
    // Modo web - formulario simple
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $uploadDir = __DIR__ . '/uploads/';

        // Crear directorio de uploads si no existe
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // Procesar archivo CSV
        if (isset($_FILES['csvfile'])) {
            $csvFile = $uploadDir . basename($_FILES['csvfile']['name']);
            move_uploaded_file($_FILES['csvfile']['tmp_name'], $csvFile);
        }

        // Procesar archivo JSON
        if (isset($_FILES['jsonfile'])) {
            $jsonFile = $uploadDir . basename($_FILES['jsonfile']['name']);
            move_uploaded_file($_FILES['jsonfile']['tmp_name'], $jsonFile);
        }

        // Ejecutar la actualización
        $updater = new JsonUpdater($csvFile, $jsonFile);
        $resultFile = $updater->actualizarJSON();

        if ($resultFile) {
            // Ofrecer descarga del archivo resultante
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . basename($resultFile) . '"');
            readfile($resultFile);

            // Limpiar archivos temporales
            unlink($csvFile);
            unlink($jsonFile);
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
            <title>Actualizador de JSON con datos CSV</title>
            <meta charset="utf-8">
        </head>
        <body>
            <h1>Actualizador de JSON con datos CSV</h1>
            <form method="post" enctype="multipart/form-data">
                <label>Seleccione el archivo CSV con RefCat y AnnoConstruccion:</label><br>
                <input type="file" name="csvfile" accept=".csv" required><br><br>

                <label>Seleccione el archivo JSON a actualizar:</label><br>
                <input type="file" name="jsonfile" accept=".json" required><br><br>

                <input type="submit" value="Actualizar JSON">
            </form>
            <p>El archivo CSV debe contener columnas "RefCat" y "AnnoConstruccion".</p>
            <p>El archivo JSON debe ser un GeoJSON con features que tengan propiedad "RefCat".</p>
        </body>
        </html>';
    }
}
?>
