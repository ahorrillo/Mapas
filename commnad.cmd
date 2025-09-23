php catastro_processor.php referencias.csv resultado.csv

php json_updater.php resultado.csv datos.json

php geojson_updater.php resultado.csv parcelas.geojson

jq.exe -c . parcelas_actualizado_fixed.json > parcelas_actualizado_fixed_min.json

jq.exe -c . PARCELA-FINAL_actualizado.geojson > PARCELA-FINAL_actualizado_min.json
