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
        $this->log("Archivo CSV: " . $inputFile);
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
     * Obtiene el año de construcción y dirección de una referencia catastral
     */
    private function obtenerDatosCatastro($refCatastral) {
        $url = "https://ovc.catastro.meh.es/OVCServWeb/OVCWcfCallejero/COVCCallejero.svc/json/Consulta_DNPRC?RefCat=" . urlencode($refCatastral);

        $this->log("Consultando: $refCatastral");

        // Configurar y ejecutar la solicitud cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Verificar errores en la solicitud
        if ($response === false) {
            $this->log("Error en la consulta: $error");
            return ["anno" => "0", "direccion" => "Error: $error"];
        }

        if ($httpCode !== 200) {
            $this->log("Error HTTP: $httpCode");
            return ["anno" => "0", "direccion" => "Error HTTP: $httpCode"];
        }

        // Decodificar la respuesta JSON
        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("Error decodificando JSON: " . json_last_error_msg());
            return ["anno" => "0", "direccion" => "Error en formato JSON"];
        }

        // Verificar si es una respuesta con múltiples referencias
        if (isset($data['consulta_dnprcResult']['lrcdnp']['rcdnp'])) {
            $this->log("Referencia con múltiples entradas: $refCatastral");
            return $this->procesarMultiplesReferencias($data, $refCatastral);
        }

        // Procesar respuesta estándar (una sola referencia)
        return $this->procesarRespuestaEstandar($data, $refCatastral);
    }

    /**
     * Procesa respuestas con múltiples referencias catastrales
     * Devuelve solo el registro con el año mayor
     */
    private function procesarMultiplesReferencias($data, $refCatastralBase) {
        $registros = [];
        $maxAnno = 0;
        $registroSeleccionado = null;

        foreach ($data['consulta_dnprcResult']['lrcdnp']['rcdnp'] as $referencia) {
            // Extraer año (convertir a int, 0 si no existe)
            $anno = isset($referencia['debi']['ant']) && is_numeric($referencia['debi']['ant']) ?
                    (int)$referencia['debi']['ant'] : 0;

            // Extraer dirección
            $direccion = $this->extraerDireccion($referencia);

            $registro = [
                'anno' => $anno,
                'direccion' => $direccion
            ];

            $registros[] = $registro;

            // Encontrar el registro con el año mayor
            if ($anno > $maxAnno) {
                $maxAnno = $anno;
                $registroSeleccionado = $registro;
            }

            $this->log("Subreferencia - Año: $anno - Dirección: $direccion");
        }

        // Si no encontramos ningún registro con año, usar el primero
        if ($registroSeleccionado === null && count($registros) > 0) {
            $registroSeleccionado = $registros[0];
        }

        $this->log("Seleccionado para múltiples referencias - Año: " . $registroSeleccionado['anno'] . " - Dirección: " . $registroSeleccionado['direccion']);

        return $registroSeleccionado;
    }

    /**
     * Procesa respuestas con una sola referencia catastral
     */
    private function procesarRespuestaEstandar($data, $refCatastral) {
        // Extraer el año de construcción (0 si no existe)
        $anno = isset($data['consulta_dnprcResult']['bico']['bi']['debi']['ant']) &&
                is_numeric($data['consulta_dnprcResult']['bico']['bi']['debi']['ant']) ?
                (int)$data['consulta_dnprcResult']['bico']['bi']['debi']['ant'] : 0;

        // Extraer la dirección
        $direccion = $this->extraerDireccion($data['consulta_dnprcResult']['bico']['bi']);

        $this->log("Referencia única: $refCatastral - Año: $anno - Dirección: $direccion");

        return [
            'anno' => $anno,
            'direccion' => $direccion
        ];
    }

    /**
     * Función mejorada para extraer dirección de diferentes estructuras JSON
     * Sin calle y número, solo el nombre de la vía
     */
    private function extraerDireccion($data) {
        $direccion = ''; // Cadena vacía en lugar de "No encontrada"

        // Intentar diferentes rutas para encontrar la dirección
        if (isset($data['dt']['locs']['lors']['lourb']['dir']['nv'])) {
            // Estructura alternativa con 'lors' en lugar de 'lous'
            $direccion = $data['dt']['locs']['lors']['lourb']['dir']['nv'];
        }
        elseif (isset($data['dt']['locs']['lous']['lourb']['dir']['nv'])) {
            // Estructura estándar con 'lous'
            $direccion = $data['dt']['locs']['lous']['lourb']['dir']['nv'];
        }
        elseif (isset($data['ldt'])) {
            // Usar el campo ldt (linea de texto) si está disponible
            $partes = explode(' ', $data['ldt']);
            if (count($partes) >= 3) {
                // Tomar solo el nombre de la vía (omitir tipo de vía y número)
                $direccion = $partes[1] . (isset($partes[2]) ? ' ' . $partes[2] : '');
            }
        }

        return $direccion;
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

        // Leer la primera línea (encabezados)
        $headers = fgetcsv($inputHandle);

        // Verificar que existe la columna RefCat
        if (!in_array('RefCat', $headers)) {
            $this->log("Error: El archivo CSV debe contener una columna 'RefCat'");
            fclose($inputHandle);
            fclose($outputHandle);
            return false;
        }

        // Añadir columnas para el año de construcción y dirección
        $headers[] = 'AnnoConstruccion';
        $headers[] = 'Direccion';

        // Escribir encabezados en el archivo de salida
        fputcsv($outputHandle, $headers);

        // Obtener índice de la columna RefCat
        $refIndex = array_search('RefCat', $headers);

        // Procesar cada fila
        $rowCount = 0;
        $successCount = 0;
        $multiRefCount = 0;

        while (($row = fgetcsv($inputHandle)) !== false) {
            $rowCount++;

            if (count($row) <= $refIndex) {
                $this->log("Fila $rowCount: No tiene columna RefCat");
                continue;
            }

            // Obtener la referencia catastral
            $refCatastral = trim($row[$refIndex]);

            if (empty($refCatastral)) {
                $this->log("Fila $rowCount: RefCat vacía");
                // Mantener la fila original con valores por defecto
                $row[] = '0';
                $row[] = '';
                fputcsv($outputHandle, $row);
                continue;
            }

            // Consultar los datos catastrales
            $datos = $this->obtenerDatosCatastro($refCatastral);

            // Añadir los datos a la fila
            $row[] = $datos['anno'];
            $row[] = $datos['direccion'];

            // Escribir la fila en el archivo de salida
            fputcsv($outputHandle, $row);

            if ($datos['anno'] !== 0) {
                $successCount++;
            }

            // Esperar entre solicitudes para no saturar la API (0.5 segundos)
            usleep(500000); // 500,000 microsegundos = 0.5 segundos
        }

        // Cerrar archivos
        fclose($inputHandle);
        fclose($outputHandle);

        $this->log("Procesamiento completado. Filas procesadas: $rowCount, Éxitos: $successCount, Múltiples referencias: $multiRefCount");
        return true;
    }
}

// Uso del script
if (php_sapi_name() === 'cli') {
    // Modo línea de comandos
    $inputFile = isset($argv[1]) ? $argv[1] : 'referencias.csv';
    $outputFile = isset($argv[2]) ? $argv[2] : 'resultado.csv';

    $processor = new CatastroProcessor($inputFile, $outputFile);
    $success = $processor->procesar();

    if ($success) {
        echo "Proceso completado con éxito. Resultados en: $outputFile" . PHP_EOL;
    } else {
        echo "Error en el proceso. Verifique el archivo de log." . PHP_EOL;
        exit(1);
    }
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
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                form { margin: 20px 0; }
                input[type="file"], input[type="submit"] { margin: 10px 0; }
            </style>
        </head>
        <body>
            <h1>Procesador de Referencias Catastrales</h1>
            <form method="post" enctype="multipart/form-data">
                <label>Seleccione el archivo CSV con las referencias catastrales:</label><br>
                <input type="file" name="csvfile" accept=".csv" required><br><br>
                <input type="submit" value="Procesar">
            </form>
            <p>El archivo CSV debe contener una columna llamada "RefCat" con las referencias catastrales.</p>
            <p>El script obtendrá el año de construcción y la dirección de cada referencia.</p>
            <p><strong>NUEVO:</strong> Para referencias múltiples, se seleccionará el año mayor.</p>
        </body>
        </html>';
    }
}
?>
