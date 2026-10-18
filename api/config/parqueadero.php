<?php

declare(strict_types=1);

/**
 * Parametros del parqueadero.
 *
 * QUE HAY EN ESTE ARCHIVO Y QUE NO
 * --------------------------------
 * Lo que se PUEDE cambiar segun el operador y lo que se ajusta al entorno.
 * Lo que es una regla del dominio NO esta aca: vive en el modelo de datos o
 * en el codigo de calculo, no en un valor configurable. Ponerlo aca seria
 * permitir apagarlo desde una variable de entorno.
 *
 * QUE SE LEE DEL ENTORNO
 * ----------------------
 * Las variables de entorno se leen con `(int) env(...)` y con valores por
 * omision explicitos. El valor por omision esta escrito en el codigo y en la
 * documentacion (docs/05-CONFIGURACION.md), para que no haya que adivinar
 * de donde sale un numero.
 *
 * `env()` solo se usa en archivos de configuracion: en el resto de la
 * aplicacion se lee `config('...')`. Es la regla de Laravel y tiene un
 * motivo concreto: con la configuracion cacheada, `env()` devuelve `null` en
 * produccion.
 */
return [

    /*
    |-----------------------------------------------------------------------
    | Corte y redondeo
    |-----------------------------------------------------------------------
    |
    | Valores por omision del operador. OJO: hoy el cobro NO los lee. El
    | calculo usa `tarifas.minutos_incluidos` y `tarifas.minutos_por_fraccion`,
    | que son por tarifa. Se dejan aqui porque son la politica declarada del
    | operador y porque `configuraciones` los persiste. Ver la nota de
    | docs/02-MODELO-DE-DATOS.md y el apartado de pendientes en docs/10.
    |
    */

    'minutos_cortesia' => (int) env('PARQUEADERO_MINUTOS_CORTESIA', 10),

    'minutos_redondeo' => (int) env('PARQUEADERO_MINUTOS_REDONDEO', 1),

    /*
    |-----------------------------------------------------------------------
    | Aviso de vencimiento de mensualidades
    |-----------------------------------------------------------------------
    |
    | Cuantos dias antes de que venza la credencial aparece el cliente en el
    | aviso. Solo mueve el aviso: no termina ninguna mensualidad. El cierre
    | de un contrato lo decide una persona.
    |
    */

    'dias_aviso_vencimiento' => (int) env('PARQUEADERO_DIAS_AVISO_VENCIMIENTO', 15),

    /*
    |-----------------------------------------------------------------------
    | Ocupacion
    |-----------------------------------------------------------------------
    |
    | Segundos que vive una lectura cacheada del conteo de lugares cuando la
    | version no cambio. El valor esta en `CalculadoraOcupacion`, que es donde
    | se usa; se declara aca para que exista un solo lugar donde se documenta
    | el valor.
    |
    | El cache NO decide si un lugar esta libre: eso lo dice siempre
    | PostgreSQL, por el indice unico parcial. El cache es aceleracion.
    |
    */

    'ocupacion_ttl_segundos' => (int) env('PARQUEADERO_OCUPACION_TTL', 30),

    /*
    |-----------------------------------------------------------------------
    | Zona horaria por omision
    |-----------------------------------------------------------------------
    |
    | Chile continental es America/Santiago. En horario de verano la hora
    | oficial se adelanta una, y el calculo de permanencia usa SIEMPRE las
    | marcas de tiempo de la base (`ingreso_en` y `salida_en` son
    | `timestamptz`), que PostgreSQL guarda en UTC. La zona horaria importa
    | para mostrar la hora local y para el cierre del dia, no para la
    | duracion cobrada.
    |
    */

    'zona_horaria' => env('PARQUEADERO_ZONA_HORARIA', 'America/Santiago'),

    /*
    |-----------------------------------------------------------------------
    | Firmas de placas
    |-----------------------------------------------------------------------
    |
    | Chile cambia la placa de los automoviles livianos y medianos cada cuatro
    | años, y las motos y los vehiculos pesados tienen su propio formato. El
    | sistema NO valida el formato de la placa contra la lista vigente, y no
    | se inventa una lista aqui: una lista de placas que se queda vieja es
    | peor que no tenerla. Lo que si hace es limpiar la placa de puntos,
    | espacios y guiones, y guardarla en mayusculas.
    |
    */

    'normalizar_patente' => env('PARQUEADERO_NORMALIZAR_PATENTE', true),

    /*
    |-----------------------------------------------------------------------
    | Fotos
    |-----------------------------------------------------------------------
    |
    | `foto_obligatoria` obliga a adjuntar la foto al marcar un ingreso.
    |
    | OJO: esto esta en `false` y NO se puede dejar en `true` a ciegas. La
    | foto se guarda como RUTA en la base, y la subida del archivo la hace la
    | interfaz. Si se obliga la foto y el archivo no esta en el volumen, la
    | fila queda con una ruta que no lleva a nada, y el registro de ese
    | vehiculo no tiene la evidencia que el caso del reclamo asume que tiene.
    | Ver el pendiente en docs/10-PRUEBAS-Y-ESTADO.md.
    |
    */

    'foto_obligatoria' => (bool) env('PARQUEADERO_FOTO_OBLIGATORIA', false),

    'foto_ruta_base' => env('PARQUEADERO_FOTO_RUTA_BASE', 'fotos'),

    /*
    |-----------------------------------------------------------------------
    | Cobranza
    |-----------------------------------------------------------------------
    |
    | Formas de pago que el mostrador acepta. La lista canonica esta en
    | `App\Models\Boleta::FORMAS_DE_PAGO`, que es la que valida el formulario.
    | Aca se deja el texto que la interfaz muestra y el valor por omision.
    |
    */

    'forma_pago_por_omision' => env('PARQUEADERO_FORMA_PAGO', 'efectivo'),

    /*
    |-----------------------------------------------------------------------
    | Descuento
    |-----------------------------------------------------------------------
    |
    | Descuento maximo en pesos que puede aplicar un operador sin que el
    | sistema lo trate como una excepcion. El limite DURO esta en
    | `GeneradorDeBoleta`, que rechaza el descuento que supera el subtotal:
    | un descuento mayor que el total es un cobro negativo, y eso no es un
    | descuento.
    |
    */

    'descuento_maximo' => (int) env('PARQUEADERO_DESCUENTO_MAXIMO', 5000),

    /*
    |-----------------------------------------------------------------------
    | Paginacion
    |-----------------------------------------------------------------------
    |
    | El historial se pide con ventana de fechas y con tope. Un historial sin
    | tope devuelve la historia entera del operador, que en un estacionamiento
    | con diez años son millones de filas.
    |
    */

    'historial_por_pagina' => (int) env('PARQUEADERO_HISTORIAL_POR_PAGINA', 100),

    'historial_maximo_pagina' => (int) env('PARQUEADERO_HISTORIAL_MAXIMO', 200),

    /*
    |-----------------------------------------------------------------------
    | Mantenimiento
    |-----------------------------------------------------------------------
    |
    | `verificar_aislamiento_al_arrancar` hace que el entrypoint ejecute
    | `parqueadero:verificar-aislamiento` antes de levantar php-fpm. Si la
    | RLS no esta puesta, la API arranca igual y queda despidiendo datos de
    | otros operadores: es preferible que no arranque.
    |
    */

    'verificar_aislamiento_al_arrancar' => (bool) env('PARQUEADERO_VERIFICAR_AISLAMIENTO', true),

];