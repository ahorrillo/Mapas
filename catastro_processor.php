<?php
class CatastroProcessor {
    private $inputFile;
    private $outputFile;
    private $logFile;

    public function __construct($inputFile, $outputFile = 'resultado.csv', $logFile = 'procesamiento.log') {
        $this->inputFile = $inputFile;
        $this->outputFile = $outputFile;
        $this->logFile = $logFile;

        // Inicializar archivo de log
        $this->log("Iniciando procesamiento de referencias catastrales");
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
     * Obtiene el año de construcción de una referencia catastral
     */
    private function obtenerAnnoConstruccion($refCatastral) {
        $url = "https://ovc.catastro.meh.es/OVCServWeb/OVCWcfCallejero/COVCCallejero.svc/json/Consulta_DNPRC?RefCat=" . urlencode($refCatastral);

        $this->log("Consultando: $refCatastral");

        // Configurar y ejecutar la solicitud cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FAILONERROR, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Verificar errores en la solicitud
        if ($response === false) {
            $this->log("Error en la consulta: $error");
            return "Error: $error";
        }

        if ($httpCode !== 200) {
            $this->log("Error HTTP: $httpCode");
            return "Error HTTP: $httpCode";
        }

        // Decodificar la respuesta JSON
        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("Error decodificando JSON: " . json_last_error_msg());
            return "Error en formato JSON";
        }

        // Extraer el año de construcción (ruta anidada en el JSON)
        if (isset($data['consulta_dnprcResult']['bico']['bi']['debi']['ant'])) {
            $anno = $data['consulta_dnprcResult']['bico']['bi']['debi']['ant'];
            $this->log("Fecha encontrada para $refCatastral: $anno");
            return $anno;
        } else {
            $this->log("No existe el año de construcción para: $refCatastral");
            $noref = "no";
            return $noref;
        }
    }

    /**
     * Procesa el archivo CSV y genera el resultado
     */
    public function procesar() {
        // Verificar si el archivo existe
        if (!file_exists($this->inputFile)) {
            $this->log("Error: El archivo de entrada no existe: " . $this->inputFile);
            return false;
        }

        // Abrir archivos de entrada y salida
        $inputHandle = fopen($this->inputFile, 'r');
        $outputHandle = fopen($this->outputFile, 'w');

        if (!$inputHandle || !$outputHandle) {
            $this->log("Error: No se pudo abrir los archivos necesarios");
            return false;
        }

        // Leer y procesar el archivo CSV
        $rowCount = 0;
        $successCount = 0;

        // Leer la primera línea (encabezados)
        $headers = fgetcsv($inputHandle);

        // Verificar que existe la columna RefCat
        if (!in_array('RefCat', $headers)) {
            $this->log("Error: El archivo CSV debe contener una columna 'RefCat'");
            fclose($inputHandle);
            fclose($outputHandle);
            return false;
        }

        // Añadir columna para el año de construcción y escribir encabezados
        $headers[] = 'AnnoConstruccion';
        fputcsv($outputHandle, $headers);

        // Procesar cada fila
        while (($row = fgetcsv($inputHandle)) !== false) {
            $rowCount++;

            // Obtener la referencia catastral
            $refIndex = array_search('RefCat', $headers);
            $refCatastral = $row[$refIndex];

            // Consultar el año de construcción
            $anno = $this->obtenerAnnoConstruccion($refCatastral);

            // Añadir el resultado a la fila
            $row[] = $anno;

            // Escribir la fila en el archivo de salida
            fputcsv($outputHandle, $row);

            // Contar éxito si se encontró el año
            if ($anno !== "No encontrado" && strpos($anno, "Error") === false) {
                $successCount++;
            }

            // Esperar entre solicitudes para no saturar la API (0.5 segundos)
            if ($rowCount > 0) {
                usleep(500000); // 500,000 microsegundos = 0.5 segundos
            }
        }

        // Cerrar archivos
        fclose($inputHandle);
        fclose($outputHandle);

        $this->log("Procesamiento completado. Éxitos: $successCount/$rowCount");
        return true;
    }
}

// Uso del script
if (php_sapi_name() === 'cli') {
    // Modo línea de comandos
    $inputFile = isset($argv[1]) ? $argv[1] : 'referencias.csv';
    $outputFile = isset($argv[2]) ? $argv[2] : 'resultado.csv';

    $processor = new CatastroProcessor($inputFile, $outputFile);
    $processor->procesar();
} else {
    // Modo web - formulario simple
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csvfile'])) {
        $uploadDir = __DIR__ . '/uploads/';

        // Crear directorio de uploads si no existe
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $inputFile = $uploadDir . basename($_FILES['csvfile']['name']);
        $outputFile = $uploadDir . 'resultado_' . time() . '.csv';

        // Mover archivo subido
        if (move_uploaded_file($_FILES['csvfile']['tmp_name'], $inputFile)) {
            $processor = new CatastroProcessor($inputFile, $outputFile);
            $success = $processor->procesar();

            if ($success) {
                // Ofrecer descarga del archivo resultante
                header('Content-Type: application/csv');
                header('Content-Disposition: attachment; filename="' . basename($outputFile) . '"');
                readfile($outputFile);

                // Limpiar archivos temporales
                unlink($inputFile);
                unlink($outputFile);
                exit;
            } else {
                echo "Error en el procesamiento. Verifique el archivo de log.";
            }
        } else {
            echo "Error al subir el archivo.";
        }
    } else {
        // Mostrar formulario
        echo '
        <!DOCTYPE html>
        <html>
        <head>
            <title>Procesador de Referencias Catastrales</title>
            <meta charset="utf-8">
        </head>
        <body>
            <h1>Procesador de Referencias Catastrales</h1>
            <form method="post" enctype="multipart/form-data">
                <label>Seleccione el archivo CSV con las referencias catastrales:</label><br>
                <input type="file" name="csvfile" accept=".csv" required><br><br>
                <input type="submit" value="Procesar">
            </form>
            <p>El archivo CSV debe contener una columna llamada "RefCat" con las referencias catastrales.</p>
        </body>
        </html>';
    }
}
?>
