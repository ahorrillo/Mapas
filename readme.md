# Visualizador de Parcelas Catastrales - Badajoz 📋

## Descripción del Proyecto

Este proyecto consiste en un visualizador interactivo de parcelas catastrales del municipio de Badajoz, mostrando propiedades coloreadas según su año de construcción. La aplicación convierte coordenadas UTM a WGS84 y presenta los datos de forma intuitiva mediante un mapa Leaflet.

---

## 🚀 Características Principales

- **Conversión de coordenadas**: Transformación automática de UTM 29N (EPSG:25829) a WGS84 (EPSG:4326)
- **Coloreado por antigüedad**: Parcelas coloreadas según escala temporal de años de construcción
- **Interactividad completa**: Popups informativos, efectos hover y zoom interactivo
- **Visualización optimizada**: Renderizado eficiente para grandes volúmenes de datos
- **Diseño responsive**: Compatible con dispositivos móviles y desktop

---

## 🛠️ Tecnologías Utilizadas

- Leaflet 1.9.4: Biblioteca principal para visualización de mapas
- Proj4js 2.8.0: Conversión de sistemas de coordenadas
- OpenStreetMap: Capas base del mapa
- GeoJSON: Formato de datos geoespaciales
- PHP: Procesamiento backend de datos catastrales

---

## 📁 Estructura del Proyecto

~~~
Mapas/
├── mapa/
│   ├── PARCELA-FINAL_actualizado.geojson     # Datos geoespaciales procesados
│   ├── PARCELA-FINAL_actualizado_min.json    # Versión optimizada para web
│   └── visualizador_parcelas.html            # Aplicación principal
├── scripts/
│   ├── catastro_processor.php                # Consulta API Catastro
│   └── geojson_updater.php                   # Actualización de GeoJSON
└── datos/
    ├── referencias.csv                       # Referencias catastrales originales
    └── resultado.csv                         # Datos enriquecidos con años y direcciones
~~~

## 🔄 Flujo de Procesamiento de Datos

### Fase 1: Obtención de Datos Catastrales

~~~
Consulta masiva a API de Catastro
php scripts/catastro_processor.php datos/referencias.csv datos/resultado.csv
~~~

**Proceso**

- Lectura de referencias catastrales desde CSV
- Consulta a API de Catastro para cada referencia
- Extracción de año de construcción y dirección
- Manejo de múltiples estructuras JSON de respuesta
- Generación de CSV enriquecido

---

### Fase 2: Actualización de GeoJSON

~~~
Integración de datos en GeoJSON
php scripts/geojson_updater.php datos/resultado.csv mapa/PARCELA-FINAL.geojson
~~~

**Proceso**

- Carga de datos del CSV procesado
- Lectura del GeoJSON original con parcelas
- Búsqueda y coincidencia por REFCAT
- Actualización de campo FECHAALTA
- Adición de campo CALLE
- Generación de GeoJSON actualizado

---

### Fase 3: Optimización para Web

~~~
// Conversión de coordenadas en cliente
proj4.defs("EPSG:25829", "+proj=utm +zone=29 +ellps=GRS80 +units=m +no_defs");
const [longitude, latitude] = proj4("EPSG:25829", "WGS84", [coord, coord]);
~~~

---

## 🎨 Esquema de Colores por Año

| Rango de Años   | Color HEX   | Descripción         |
|-----------------|------------|---------------------|
| 0 (Desconocido) | #9b9b9b    | Gris                |
| 1-1800          | #ffffb2    | Amarillo muy claro  |
| 1801-1899       | #fed976    | Amarillo claro      |
| 1900-1920       | #feb24c    | Naranja claro       |
| 1921-1940       | #fd8d3c    | Naranja             |
| 1941-1969       | #fc4e2a    | Naranja-rojo        |
| 1970-1999       | #f03b20    | Rojo claro          |
| 2000-2008       | #bd0026    | Rojo                |
| 2009-2020       | #800026    | Rojo oscuro         |
| 2021-2025       | #55001a    | Rojo muy oscuro     |
| Post-2025       | #000000    | Negro               |

---

## 🔧 Configuración e Instalación

### Requisitos Previos

- Servidor web con PHP 7.0+
- Acceso a internet para cargas CDN (Leaflet, Proj4js)

### Instalación Rápida

1. Clonar o descargar el repositorio.
2. Subir los archivos a un servidor web.
3. Acceder a `visualizador_parcelas.html` desde el navegador.

### Procesamiento de Datos (Opcional)

~~~
Procesar nuevas referencias catastrales
php scripts/catastro_processor.php nuevas_referencias.csv nuevo_resultado.csv

Actualizar GeoJSON con nuevos datos
php scripts/geojson_updater.php nuevo_resultado.csv parcelas_nuevas.geojson
~~~

## 📊 Estructura de Datos

### Archivo CSV de Entrada

~~~
RefCat,AnnoConstruccion,Direccion
6720402PD7062B,1961,LEBRATOS LOS
5637604PD7053F,2023,ANTONIO CHAVES
~~~

### GeoJSON de Salida

~~~
{
"type": "FeatureCollection",
"name": "PARCELA-FINAL",
"crs": {
"type": "name",
"properties": {
"name": "urn:ogc:def:crs:EPSG::25829"
}
},
"features": [
{
"type": "Feature",
"properties": {
"REFCAT": "6720402PD7062B",
"FECHAALTA": 1961,
"CALLE": "LEBRATOS LOS",
"AREA": 2976,
"PARCELA": "02"
},
"geometry": {
"type": "MultiPolygon",
"coordinates": [[[...]]]
}
}
]
}
~~~

---

## 🌐 API de Catastro Utilizada

- Endpoint: [Consulta_DNPRC](https://ovc.catastro.meh.es/OVCServWeb/OVCWcfCallejero/COVCCallejero.svc/json/Consulta_DNPRC?RefCat=)
- Estructuras JSON manejadas:
  - Respuesta simple con bico.bi.debi.ant
  - Respuesta múltiple con lrcdnp.rcdnp[]
  - Múltiples variantes de estructura de direcciones

---

## 🚀 Uso de la Aplicación

### Interacciones Disponibles

- Hover sobre parcela: Resaltado visual
- Clic en parcela: Popup con información detallada
- Clic + arrastrar: Navegación por el mapa
- Rueda del ratón: Zoom in/out
- Doble clic: Zoom rápido

### Navegación por Coordenadas

- Vista inicial: Badajoz centro (38.8816, -6.9703)
- Zoom inicial: 13
- Zoom máximo: 19

---

## 🔍 Solución de Problemas

- **Error: "Bounds are not valid"**
  - Causa: Límites geográficos inválidos al filtrar
  - Solución: Validación con `bounds.isValid()`
- **Error: "Malformed UTF-8 characters"**
  - Causa: Codificación incorrecta en archivos JSON
  - Solución: Limpieza UTF-8 implementada

### Rendimiento con Grandes Volúmenes

- Técnica: preferCanvas: true en Leaflet
- Optimización: Simplificación de geometrías para web

---

## 📈 Características Técnicas Avanzadas

- **Conversión de Coordenadas**

~~~
// Transformación UTM 29N → WGS84
const conversion = proj4("EPSG:25829", "WGS84");
const [lng, lat] = conversion.forward([este, norte]);
~~~

- **Optimización de Rendimiento**
- Renderizado por canvas para mejor performance
- Lazy loading de popups
- Agrupación de eventos de ratón

- **Manejo de Errores**
- Validación de respuestas de API
- Fallbacks para datos faltantes
- Logging detallado de procesos

---

## 🤝 Contribuciones

Las contribuciones son bienvenidas. Áreas de mejora potencial:

- Implementación de más capas base
- Funcionalidades de exportación de datos
- Análisis estadísticos integrados
- Soporte para más formatos de entrada

---

## 📄 Licencia

Este proyecto es de uso libre para fines educativos y de investigación.

---

## 👨‍💻 Autor

Desarrollado por Antonio Horrillo.
