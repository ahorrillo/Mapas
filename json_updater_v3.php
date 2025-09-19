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
        $this->log("Archivo CSV: " . $csvFile);
        $this->log("Archivo JSON: " . $jsonFile);
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
        $this->log("Encabezados CSV: " . implode(', ', $headers));

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
        $contador = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) > max($refIndex, $annoIndex)) {
                $refCat = trim($row[$refIndex]);
                $anno = trim($row[$annoIndex]);

                // Solo procesar si tenemos un año válido
                if (is_numeric($anno) && $anno > 0) {
                    $datos[$refCat] = $anno; // Formato XXXX
                    $contador++;
                } else {
                    $this->log("Año no válido para RefCat $refCat: $anno");
                }
            }
        }

        fclose($handle);
        $this->log("Leídos $contador registros válidos desde CSV");
        return $datos;
    }

    /**
     * Función para limpiar y validar cadenas UTF-8
     */
    private function limpiarUTF8($string) {
        // Primero, intentar detectar la codificación
        $encoding = mb_detect_encoding($string, 'UTF-8, ISO-8859-1, WINDOWS-1252', true);

        if ($encoding !== 'UTF-8') {
            // Convertir a UTF-8 si no está en esa codificación
            $string = mb_convert_encoding($string, 'UTF-8', $encoding);
        }

        // Limpiar caracteres inválidos
        $string = preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', ' ', $string);

        return $string;
    }

    /**
     * Lee y decodifica JSON con manejo de errores de codificación
     */
    private function leerJSON($filePath) {
        $content = file_get_contents($filePath);

        if ($content === false) {
            $this->log("Error: No se pudo leer el archivo JSON");
            return false;
        }

        // Intentar decodificar directamente
        $data = json_decode($content, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            $this->log("JSON decodificado correctamente en el primer intento");
            return $data;
        }

        $this->log("Primer intento fallido: " . json_last_error_msg());

        // Limpiar el contenido de posibles problemas de UTF-8
        $cleanedContent = $this->limpiarUTF8($content);

        // Intentar decodificar de nuevo con flags para manejar UTF-8 incorrecto
        $data = json_decode($cleanedContent, true, 512, JSON_INVALID_UTF8_IGNORE);

        if (json_last_error() === JSON_ERROR_NONE) {
            $this->log("JSON decodificado correctamente después de limpieza UTF-8");
            return $data;
        }

        $this->log("Error decodificando JSON después de limpieza: " . json_last_error_msg());

        // Último intento: forzar la conversión de codificación
        $this->log("Intentando conversión de codificación forzada...");
        $encodings = ['UTF-8', 'ISO-8859-1', 'WINDOWS-1252', 'ASCII'];

        foreach ($encodings as $encoding) {
            $convertedContent = mb_convert_encoding($content, 'UTF-8', $encoding);
            $data = json_decode($convertedContent, true, 512, JSON_INVALID_UTF8_IGNORE);

            if (json_last_error() === JSON_ERROR_NONE) {
                $this->log("JSON decodificado correctamente con codificación: $encoding");
                return $data;
            }
        }

        return false;
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

        $this->log("Datos CSV cargados: " . implode(', ', array_keys($datosCSV)));

        // Verificar si el archivo JSON existe
        if (!file_exists($this->jsonFile)) {
            $this->log("Error: El archivo JSON no existe: " . $this->jsonFile);
            return false;
        }

        // Leer y decodificar el archivo JSON con manejo de errores
        $data = $this->leerJSON($this->jsonFile);
        if ($data === false) {
            $this->log("Error: No se pudo decodificar el archivo JSON después de múltiples intentos");
            return false;
        }

        // Verificar que es un GeoJSON con features
        if (!isset($data['type']) || $data['type'] !== 'FeatureCollection' || !isset($data['features'])) {
            $this->log("Error: El JSON no es un FeatureCollection de GeoJSON válido");
            return false;
        }

        $this->log("JSON contiene " . count($data['features']) . " features");

        $actualizaciones = 0;
        $noEncontrados = 0;
        $coincidencias = [];

        // Procesar cada feature
        foreach ($data['features'] as &$feature) {
            if (isset($feature['type']) && $feature['type'] === 'Feature' &&
                isset($feature['properties']['REFCAT'])) {

                $refCat = $feature['properties']['REFCAT'];
                $coincidencias[] = $refCat;

                // Buscar si esta RefCat está en nuestro CSV
                if (isset($datosCSV[$refCat])) {
                    // Guardar el valor antiguo para logging
                    $valorAntiguo = isset($feature['properties']['FECHAALTA']) ? $feature['properties']['FECHAALTA'] : 'No existía';

                    // Actualizar la propiedad FECHAALTA
                    $feature['properties']['FECHAALTA'] = $datosCSV[$refCat];
                    $actualizaciones++;

                    $this->log("Actualizada RefCat: $refCat - Antiguo: $valorAntiguo, Nuevo: " . $datosCSV[$refCat]);
                } else {
                    $noEncontrados++;
                }
            }
        }

        $this->log("Coincidencias encontradas en JSON: " . implode(', ', $coincidencias));

        // Guardar el JSON actualizado con flags para manejar UTF-8
        $jsonActualizado = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($jsonActualizado === false) {
            $this->log("Error: No se pudo codificar el JSON actualizado: " . json_last_error_msg());
            return false;
        }

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
    $result = $updater->actualizarJSON();

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

        // Procesar archivo JSON
        $jsonFile = $uploadDir . 'temp_json_' . time() . '.json';
        if (isset($_FILES['jsonfile']) && $_FILES['jsonfile']['error'] === UPLOAD_ERR_OK) {
            move_uploaded_file($_FILES['jsonfile']['tmp_name'], $jsonFile);
        } else {
            echo "Error al subir el archivo JSON.";
            exit;
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
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                form { margin: 20px 0; }
                input[type="file"], input[type="submit"] { margin: 10px 0; }
            </style>
        </head>
        <body>
            <h1>Actualizador de JSON con datos CSV</h1>
            <form method="post" enctype="multipart/form-data">
                <div>
                    <label>Seleccione el archivo CSV con RefCat y AnnoConstruccion:</label><br>
                    <input type="file" name="csvfile" accept=".csv" required>
                </div>
                <div>
                    <label>Seleccione el archivo JSON a actualizar:</label><br>
                    <input type="file" name="jsonfile" accept=".json" required>
                </div>
                <div>
                    <input type="submit" value="Actualizar JSON">
                </div>
            </form>
            <p>El archivo CSV debe contener columnas "RefCat" y "AnnoConstruccion".</p>
            <p>El archivo JSON debe ser un GeoJSON con features que tengan propiedad "RefCat".</p>
        </body>
        </html>';
    }
}
?>
